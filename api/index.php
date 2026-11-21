<?php
// Front controller for the JSON API. Routes on ?r=resource/action so it needs
// no URL rewriting (safe on shared hosting). Called from the SPA at ../api/.
declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/auth.php';

// ---- Bootstrap ------------------------------------------------------------
$configPath = getenv('TUTOR_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fail('no_config', 'Run setup.php first.', 500);
}
$cfg = require $configPath;
try {
    DB::init($cfg);
} catch (Throwable $e) {
    fail('db_error', 'Could not connect to the database.', 500);
}

// Auto-apply pending DB migrations once after a deploy (idempotent + guarded).
require_once __DIR__ . '/lib/migrate.php';
try {
    $sv = DB::one("SELECT svalue FROM settings WHERE skey = 'schema_version'");
    if ((int)($sv['svalue'] ?? 0) < SCHEMA_VERSION) {
        migrate();
        require_once __DIR__ . '/lib/seed.php';
        seed_curriculum();
    }
} catch (Throwable $e) { /* fresh installs are handled by setup.php */ }

start_app_session();

$r = $_GET['r'] ?? '';

// ---- Small shared helpers -------------------------------------------------
function star_rating(float $acc, float $threshold): int {
    if ($acc >= 0.99) return 3;
    if ($acc >= 0.95) return 2;
    if ($acc >= $threshold) return 1;
    return 0;
}

function stage_starts(): array {
    $rows = DB::all('SELECT stage, MIN(lesson_order) AS o FROM lessons GROUP BY stage');
    $out = [];
    foreach ($rows as $row) $out[$row['stage']] = (int) $row['o'];
    return $out;
}

function max_order(): int {
    $row = DB::one('SELECT MAX(lesson_order) AS m FROM lessons');
    return (int) ($row['m'] ?? 1);
}

// Physical keys unlocked up to and including a given lesson order.
function unlocked_keys(int $order): array {
    $rows = DB::all('SELECT new_keys FROM lessons WHERE lesson_order <= ?', [$order]);
    $keys = [];
    foreach ($rows as $row) {
        $arr = json_decode($row['new_keys'] ?? '[]', true) ?: [];
        foreach ($arr as $k) {
            if ($k === 'LShift' || $k === 'RShift') continue; // modifier, not drilled
            $keys[$k] = true;
        }
    }
    return array_keys($keys);
}

// Add hits/misses/time to key_stats (portable upsert).
function upsert_keystats(int $pid, array $keystats): void {
    foreach ($keystats as $ks) {
        $key = (string) ($ks['key'] ?? '');
        if ($key === '') continue;
        $hits = (int) ($ks['hits'] ?? 0);
        $miss = (int) ($ks['misses'] ?? 0);
        $time = (int) ($ks['time_ms'] ?? 0);
        $existing = DB::one('SELECT id FROM key_stats WHERE profile_id = ? AND key_code = ?', [$pid, $key]);
        if ($existing) {
            DB::run('UPDATE key_stats SET hits = hits + ?, misses = misses + ?, total_time_ms = total_time_ms + ? WHERE id = ?',
                [$hits, $miss, $time, $existing['id']]);
        } else {
            DB::run('INSERT INTO key_stats (profile_id, key_code, hits, misses, total_time_ms) VALUES (?,?,?,?,?)',
                [$pid, $key, $hits, $miss, $time]);
        }
    }
}

// ---- Cosmetics / dress-up helpers -----------------------------------------
function load_cosmetics(): array {
    static $c = null;
    if ($c !== null) return $c;
    $path = __DIR__ . '/../data/cosmetics.json';
    $c = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    return $c;
}
function cosmetic(string $key): ?array {
    foreach (load_cosmetics()['items'] ?? [] as $it) if ($it['key'] === $key) return $it;
    return null;
}
function profile_settings(array $p): array {
    $s = json_decode($p['settings'] ?? '', true);
    return is_array($s) ? $s : [];
}
function effective_equipped(array $p): array {
    $eq = load_cosmetics()['default_equip'] ?? [];
    $s = profile_settings($p);
    if (!empty($s['equipped']) && is_array($s['equipped'])) {
        foreach ($s['equipped'] as $slot => $key) $eq[$slot] = $key;
    }
    return $eq;
}
function owned_keys(int $pid): array {
    $owned = [];
    foreach (load_cosmetics()['items'] ?? [] as $it) {
        if ((int)($it['price'] ?? 0) === 0) $owned[$it['key']] = true; // free items owned by all
    }
    foreach (DB::all('SELECT item_key FROM owned_cosmetics WHERE profile_id = ?', [$pid]) as $row) {
        $owned[$row['item_key']] = true;
    }
    return array_keys($owned);
}
function app_name(): string {
    $row = DB::one('SELECT svalue FROM settings WHERE skey = ?', ['app_name']);
    return ($row && $row['svalue'] !== '') ? $row['svalue'] : 'Turtle Type';
}

// ---- AI content helpers ---------------------------------------------------
// Characters a learner can type by a given lesson (letters, unlocked punctuation
// and digits, plus capitals once a shift lesson is unlocked, plus space). This
// is the same constraint the curriculum generator enforces, reused for AI text.
function allowed_chars_for(int $order): array {
    $rows = DB::all('SELECT new_keys FROM lessons WHERE lesson_order <= ?', [$order]);
    $chars = [' ' => true];
    $allowUpper = false;
    foreach ($rows as $r) {
        foreach (json_decode($r['new_keys'] ?? '[]', true) ?: [] as $k) {
            if ($k === 'LShift' || $k === 'RShift') { $allowUpper = true; continue; }
            $chars[$k] = true;
        }
    }
    if ($allowUpper) {
        foreach (array_keys($chars) as $c) if ($c >= 'a' && $c <= 'z') $chars[strtoupper($c)] = true;
    }
    return array_keys($chars);
}
function validate_passage(string $text, array $allowed, int $maxLen = 220): array {
    $set = array_flip($allowed);
    $bad = [];
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (!isset($set[$ch])) $bad[$ch] = true;
    }
    return ['ok' => empty($bad) && trim($text) !== '' && mb_strlen($text) <= $maxLen,
            'bad' => array_keys($bad), 'len' => mb_strlen($text)];
}
// Server-side Anthropic call. Sends only topic, reading level and the allowed
// character set — never names, ages, school or any child identifier.
function anthropic_generate(array $cfg, string $topic, int $level, array $allowed, int $count): array {
    $key = $cfg['anthropic_api_key'] ?? '';
    if ($key === '') return ['error' => 'no_key'];
    $allowedStr = implode('', $allowed);
    $sys = "You write ultra-short typing-practice lines for a child learning touch typing. "
        . "Target reading level: grade $level. "
        . "HARD CONSTRAINT: use ONLY these characters, nothing else (spaces allowed): \"$allowedStr\". "
        . "If a word would need a letter or mark outside that set, choose a different word. "
        . "Each line is 1-2 short sentences, 60-180 characters, cheerful and age-appropriate. "
        . "Topic: $topic. Never include real people's names, ages, schools, addresses or places. "
        . "Reply with ONLY a JSON array of $count strings and nothing else.";
    $payload = json_encode([
        'model' => 'claude-haiku-5-5', 'max_tokens' => 900, 'system' => $sys,
        'messages' => [['role' => 'user', 'content' => "Write $count practice lines about: $topic"]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) return ['error' => 'api_error'];
    $text = json_decode($res, true)['content'][0]['text'] ?? '';
    if (preg_match('/\[.*\]/s', $text, $m)) {
        $arr = json_decode($m[0], true);
        if (is_array($arr)) return ['passages' => array_values(array_filter(array_map('strval', $arr)))];
    }
    return ['error' => 'parse_error'];
}

// ---- Routes ---------------------------------------------------------------
switch ($r) {

case 'session':
    $p = Auth::currentProfile();
    ok([
        'app_name' => app_name(),
        'family' => Auth::familyOk(),
        'profile' => $p ? Auth::publicProfile($p) : null,
    ]);
    break;

case 'site/login': // POST {password}
    $pw = (string) field('password', '');
    if (!Auth::loginFamily($pw, $cfg)) {
        fail('wrong_password', 'That is not the family password.', 401);
    }
    ok(['family' => true]);
    break;

case 'site/logout': // POST
    Auth::logoutAll();
    ok();
    break;

case 'profiles': // GET  (family required) -> picker list with lock state
    Auth::requireFamily();
    $rows = DB::all('SELECT * FROM profiles ORDER BY role DESC, name ASC');
    $now = time();
    $list = array_map(function ($p) use ($now) {
        $pub = Auth::publicProfile($p);
        $lu = !empty($p['locked_until']) ? strtotime($p['locked_until'] . ' UTC') : 0;
        $pub['locked'] = ($lu && $lu > $now);
        $pub['locked_seconds'] = $pub['locked'] ? $lu - $now : 0;
        return $pub;
    }, $rows);
    ok(['profiles' => $list]);
    break;

case 'profile/select': // POST {profile_id, pin}
    Auth::requireFamily();
    $res = Auth::selectProfile((int) field('profile_id', 0), (string) field('pin', ''), $cfg);
    if (!$res['ok']) {
        $status = $res['error'] === 'locked' ? 403 : 401;
        $payload = ['ok' => false, 'error' => $res['error'], 'message' => ''];
        if (isset($res['remaining']))      $payload['remaining'] = $res['remaining'];
        if (isset($res['locked_seconds'])) $payload['locked_seconds'] = $res['locked_seconds'];
        json_out($payload, $status);
    }
    $p = Auth::currentProfile();
    ok(['profile' => Auth::publicProfile($p)]);
    break;

case 'profile/logout': // POST
    Auth::logoutProfile();
    ok();
    break;

case 'profile/create': // POST {name, avatar, role, pin, reading_level?}  (parent)
    Auth::requireParent();
    $name = trim((string) field('name', ''));
    $avatar = trim((string) field('avatar', 'cat'));
    $role = field('role', 'child') === 'parent' ? 'parent' : 'child';
    $pin = (string) field('pin', '');
    $level = (int) field('reading_level', 4);
    if ($name === '') fail('bad_input', 'A name is needed.');
    if (!preg_match('/^\d{4,10}$/', $pin)) fail('bad_pin', 'PIN must be 4 to 10 digits.');
    if ($role === 'parent' && strlen($pin) < 6) fail('bad_pin', 'A grown-up PIN needs at least 6 digits.');
    $id = DB::insert('INSERT INTO profiles (name, avatar, role, pin_hash, current_lesson, reading_level, created_at)
                      VALUES (?,?,?,?,?,?,?)',
        [$name, $avatar, $role, password_hash($pin, PASSWORD_DEFAULT), 1, $level, DB::now()]);
    $p = DB::one('SELECT * FROM profiles WHERE id = ?', [$id]);
    ok(['profile' => Auth::publicProfile($p)]);
    break;

case 'profile/update': // POST {id, name?, avatar?, role?, pin?, reading_level?} (parent)
    Auth::requireParent();
    $id = (int) field('id', 0);
    $p = DB::one('SELECT * FROM profiles WHERE id = ?', [$id]);
    if (!$p) fail('not_found', 'No such profile.', 404);
    $sets = []; $vals = [];
    if (($v = field('name')) !== null && trim((string)$v) !== '') { $sets[] = 'name = ?'; $vals[] = trim((string)$v); }
    if (($v = field('avatar')) !== null) { $sets[] = 'avatar = ?'; $vals[] = trim((string)$v); }
    if (($v = field('role')) !== null) { $sets[] = 'role = ?'; $vals[] = ($v === 'parent' ? 'parent' : 'child'); }
    if (($v = field('reading_level')) !== null) { $sets[] = 'reading_level = ?'; $vals[] = (int)$v; }
    if (($v = field('pin')) !== null && $v !== '') {
        $role = field('role', $p['role']);
        if (!preg_match('/^\d{4,10}$/', (string)$v)) fail('bad_pin', 'PIN must be 4 to 10 digits.');
        if ($role === 'parent' && strlen((string)$v) < 6) fail('bad_pin', 'A grown-up PIN needs at least 6 digits.');
        $sets[] = 'pin_hash = ?'; $vals[] = password_hash((string)$v, PASSWORD_DEFAULT);
        $sets[] = 'failed_attempts = ?'; $vals[] = 0;
        $sets[] = 'locked_until = NULL';
    }
    if (!$sets) fail('bad_input', 'Nothing to change.');
    $vals[] = $id;
    DB::run('UPDATE profiles SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    $p = DB::one('SELECT * FROM profiles WHERE id = ?', [$id]);
    ok(['profile' => Auth::publicProfile($p)]);
    break;

case 'lessons': // GET  -> all lessons with per-profile status
    $p = Auth::requireProfile();
    $cur = (int) $p['current_lesson'];
    $lessons = DB::all('SELECT id, lesson_order, stage, title, new_keys, pass_threshold FROM lessons ORDER BY lesson_order');
    // best result per lesson for this profile
    $best = [];
    foreach (DB::all('SELECT lesson_id, MAX(accuracy) AS acc, MAX(wpm) AS wpm, MAX(stars) AS stars
                      FROM attempts WHERE profile_id = ? AND lesson_id IS NOT NULL GROUP BY lesson_id', [$p['id']]) as $b) {
        $best[(int)$b['lesson_id']] = $b;
    }
    $out = [];
    foreach ($lessons as $L) {
        $ord = (int) $L['lesson_order'];
        $b = $best[(int)$L['id']] ?? null;
        $passed = $b && (float)$b['acc'] >= (float)$L['pass_threshold'];
        $status = $ord > $cur ? 'locked' : ($ord === $cur ? 'current' : ($passed ? 'passed' : 'available'));
        $out[] = [
            'id' => (int)$L['id'],
            'order' => $ord,
            'stage' => $L['stage'],
            'title' => $L['title'],
            'new_keys' => json_decode($L['new_keys'] ?? '[]', true),
            'status' => $status,
            'passed' => (bool)$passed,
            'best' => $b ? ['accuracy' => (float)$b['acc'], 'wpm' => (int)$b['wpm'], 'stars' => (int)$b['stars']] : null,
        ];
    }
    ok(['current_lesson' => $cur, 'lessons' => $out]);
    break;

case 'lesson': // GET &id= -> steps + tricky-keys drill  (must be unlocked)
    $p = Auth::requireProfile();
    $id = (int) ($_GET['id'] ?? 0);
    $L = DB::one('SELECT * FROM lessons WHERE id = ?', [$id]);
    if (!$L) fail('not_found', 'No such lesson.', 404);
    if ((int)$L['lesson_order'] > (int)$p['current_lesson']) {
        fail('locked', 'Finish the earlier lessons first.', 403);
    }
    $drill = json_decode($L['drill_data'], true);
    // tricky keys: worst unlocked keys this learner has enough data on
    $unlocked = unlocked_keys((int)$L['lesson_order']);
    $tricky = [];
    if ($unlocked) {
        $place = implode(',', array_fill(0, count($unlocked), '?'));
        $rows = DB::all(
            "SELECT key_code, hits, misses FROM key_stats
             WHERE profile_id = ? AND (hits + misses) >= 5 AND key_code IN ($place)",
            array_merge([$p['id']], $unlocked));
        foreach ($rows as $row) {
            $tot = (int)$row['hits'] + (int)$row['misses'];
            $rate = $tot ? (int)$row['misses'] / $tot : 0;
            if ($rate > 0) $tricky[] = ['key' => $row['key_code'], 'miss_rate' => round($rate, 3)];
        }
        usort($tricky, fn($a, $b) => $b['miss_rate'] <=> $a['miss_rate']);
        $tricky = array_slice($tricky, 0, 5);
    }
    ok([
        'id' => (int)$L['id'],
        'order' => (int)$L['lesson_order'],
        'stage' => $L['stage'],
        'title' => $L['title'],
        'new_keys' => json_decode($L['new_keys'] ?? '[]', true),
        'pass_threshold' => (float)$L['pass_threshold'],
        'steps' => $drill['steps'] ?? [],
        'tricky_keys' => $tricky,
        'unlocked_keys' => $unlocked,
    ]);
    break;

case 'attempt': // POST {lesson_id?, source, duration_ms, accuracy, wpm, keystats[]}
    $p = Auth::requireProfile();
    $lessonId = field('lesson_id') !== null ? (int) field('lesson_id') : null;
    $contentId = field('content_item_id') !== null ? (int) field('content_item_id') : null;
    $source = (string) field('source', 'lesson');
    $acc = max(0.0, min(1.0, (float) field('accuracy', 0)));
    $wpm = max(0, min(250, (int) field('wpm', 0)));
    $dur = max(0, (int) field('duration_ms', 0));
    $keystats = field('keystats', []);

    $threshold = 0.9; $lessonOrder = null;
    if ($lessonId) {
        $L = DB::one('SELECT * FROM lessons WHERE id = ?', [$lessonId]);
        if (!$L) fail('not_found', 'No such lesson.', 404);
        $threshold = (float) $L['pass_threshold'];
        $lessonOrder = (int) $L['lesson_order'];
    }
    $stars = star_rating($acc, $threshold);

    // Was this lesson already passed before this attempt? (first pass pays more)
    $priorPass = 0;
    if ($lessonId) {
        $row = DB::one('SELECT COUNT(*) AS c FROM attempts WHERE profile_id = ? AND lesson_id = ? AND accuracy >= ?',
            [$p['id'], $lessonId, $threshold]);
        $priorPass = (int) ($row['c'] ?? 0);
    } elseif ($contentId) {
        $row = DB::one('SELECT COUNT(*) AS c FROM attempts WHERE profile_id = ? AND content_item_id = ? AND accuracy >= ?',
            [$p['id'], $contentId, $threshold]);
        $priorPass = (int) ($row['c'] ?? 0);
    }

    DB::run('INSERT INTO attempts (profile_id, lesson_id, content_item_id, source, duration_ms, wpm, accuracy, stars, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
        [$p['id'], $lessonId, $contentId, $source, $dur, $wpm, $acc, $stars, DB::now()]);
    if (is_array($keystats)) upsert_keystats((int)$p['id'], $keystats);

    // Advance the pointer only when the CURRENT lesson is passed.
    $passed = ($lessonId || $contentId) && $acc >= $threshold;
    $newCurrent = (int) $p['current_lesson'];
    if ($passed && $lessonOrder === (int) $p['current_lesson']) {
        $next = min($lessonOrder + 1, max_order());
        if ($next !== $newCurrent) {
            DB::run('UPDATE profiles SET current_lesson = ? WHERE id = ?', [$next, $p['id']]);
            $newCurrent = $next;
        }
    }
    // Award shells (kid-friendly: always a little, more for a first pass).
    // Lessons and "made for you" passages both earn; the mini-game uses its own route.
    $shellsEarned = 0;
    if ($lessonId || $contentId) {
        if ($passed && $priorPass === 0)      $shellsEarned = [1 => 6, 2 => 10, 3 => 15][$stars] ?? 6;
        elseif ($passed)                      $shellsEarned = 3;  // replay practice
        else                                  $shellsEarned = 1;  // effort, never zero
        if ($shellsEarned > 0) {
            DB::run('UPDATE profiles SET shells = shells + ? WHERE id = ?', [$shellsEarned, $p['id']]);
            DB::run('INSERT INTO rewards (profile_id, type, value, meta, created_at) VALUES (?,?,?,?,?)',
                [$p['id'], 'earn', $shellsEarned,
                 json_encode(['lesson_id' => $lessonId, 'content_item_id' => $contentId, 'stars' => $stars]), DB::now()]);
        }
    }
    $bal = DB::one('SELECT shells FROM profiles WHERE id = ?', [$p['id']]);
    ok(['stars' => $stars, 'passed' => (bool)$passed, 'current_lesson' => $newCurrent,
        'shells_earned' => $shellsEarned, 'shells' => (int)($bal['shells'] ?? 0)]);
    break;

case 'placement': // GET -> probe config
    Auth::requireProfile();
    ok([
        'target' => 0.9,
        'stage_starts' => stage_starts(),
        'probes' => [
            ['id' => 'home', 'stage' => 'home', 'label' => 'Home row',
             'text' => 'a sad lass had a glass; dad asks a lad'],
            ['id' => 'top', 'stage' => 'top', 'label' => 'Top row',
             'text' => 'the quiet writer typed her poetry'],
            ['id' => 'bottom', 'stage' => 'bottom', 'label' => 'Bottom row',
             'text' => 'a brave cub can move seven boxes'],
            ['id' => 'sample', 'stage' => 'sample', 'label' => 'Free sample',
             'text' => 'The quick brown fox jumps over a very lazy dog.'],
        ],
    ]);
    break;

case 'placement/result': // POST {current_lesson, accuracy?, keystats[]}
    $p = Auth::requireProfile();
    $cur = (int) field('current_lesson', 1);
    $cur = max(1, min($cur, max_order()));
    DB::run('UPDATE profiles SET current_lesson = ? WHERE id = ?', [$cur, $p['id']]);
    $ks = field('keystats', []);
    if (is_array($ks)) upsert_keystats((int)$p['id'], $ks);
    DB::run('INSERT INTO attempts (profile_id, lesson_id, source, duration_ms, wpm, accuracy, stars, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
        [$p['id'], null, 'placement', (int) field('duration_ms', 0), (int) field('wpm', 0),
         max(0.0, min(1.0, (float) field('accuracy', 0))), 0, DB::now()]);
    $p = DB::one('SELECT * FROM profiles WHERE id = ?', [$p['id']]);
    ok(['profile' => Auth::publicProfile($p)]);
    break;

case 'progress': // GET -> current profile's progress
    $p = Auth::requireProfile();
    ok(['progress' => build_progress((int)$p['id']), 'profile' => Auth::publicProfile($p)]);
    break;

case 'parent/progress': // GET (parent) -> every child's progress
    Auth::requireParent();
    $children = DB::all("SELECT * FROM profiles WHERE role = 'child' ORDER BY name");
    $out = [];
    foreach ($children as $c) {
        $pr = build_progress((int)$c['id']);
        $out[] = [
            'profile' => Auth::publicProfile($c),
            'days_practiced' => $pr['days_practiced'],
            'streak' => $pr['streak'],
            'total_minutes' => $pr['total_minutes'],
            'avg_accuracy' => $pr['avg_accuracy'],
            'best_wpm' => $pr['best_wpm'],
            'avg_wpm' => $pr['avg_wpm'],
            'lessons_passed' => $pr['lessons_passed'],
            'last_active' => $pr['last_active'],
        ];
    }
    ok(['children' => $out]);
    break;

case 'keyboard': // GET -> finger map + colors for the on-screen keyboard
    Auth::requireFamily();
    $row = DB::one('SELECT svalue FROM settings WHERE skey = ?', ['keyboard_meta']);
    ok(['keyboard' => $row ? json_decode($row['svalue'], true) : null]);
    break;

case 'avatar': // GET -> what the buddy is wearing + render data + balance
    $p = Auth::requireProfile();
    $eq = effective_equipped($p);
    $render = [];
    foreach ($eq as $slot => $key) {
        $it = cosmetic((string)$key);
        if ($it) $render[$slot] = $it['render'] ?? [];
    }
    ok(['equipped' => $eq, 'render' => $render, 'shells' => (int)$p['shells']]);
    break;

case 'shop': // GET -> catalog + owned + equipped + balance
    $p = Auth::requireProfile();
    $cos = load_cosmetics();
    ok([
        'shells' => (int)$p['shells'],
        'currency' => $cos['currency'] ?? ['name' => 'shells'],
        'slots' => $cos['slots'] ?? [],
        'items' => $cos['items'] ?? [],
        'owned' => owned_keys((int)$p['id']),
        'equipped' => effective_equipped($p),
    ]);
    break;

case 'shop/buy': // POST {item_key}
    $p = Auth::requireProfile();
    $key = (string) field('item_key', '');
    $it = cosmetic($key);
    if (!$it) fail('not_found', 'No such item.', 404);
    if (in_array($key, owned_keys((int)$p['id']), true)) fail('owned', 'You already have that one.');
    $price = (int)($it['price'] ?? 0);
    if ($price > (int)$p['shells']) fail('too_few_shells', 'Not enough shells yet — keep practicing!');
    DB::run('UPDATE profiles SET shells = shells - ? WHERE id = ?', [$price, $p['id']]);
    DB::run('INSERT INTO owned_cosmetics (profile_id, item_key, created_at) VALUES (?,?,?)',
        [$p['id'], $key, DB::now()]);
    DB::run('INSERT INTO rewards (profile_id, type, value, meta, created_at) VALUES (?,?,?,?,?)',
        [$p['id'], 'spend', $price, json_encode(['item' => $key]), DB::now()]);
    $p = DB::one('SELECT * FROM profiles WHERE id = ?', [$p['id']]);
    ok(['shells' => (int)$p['shells'], 'owned' => owned_keys((int)$p['id'])]);
    break;

case 'avatar/equip': // POST {slot, item_key}
    $p = Auth::requireProfile();
    $slot = (string) field('slot', '');
    $key = (string) field('item_key', '');
    $it = cosmetic($key);
    if (!$it || ($it['slot'] ?? '') !== $slot) fail('bad_input', 'That does not go there.');
    if (!in_array($key, owned_keys((int)$p['id']), true)) fail('not_owned', 'Buy it first.', 403);
    $s = profile_settings($p);
    $s['equipped'] = $s['equipped'] ?? [];
    $s['equipped'][$slot] = $key;
    DB::run('UPDATE profiles SET settings = ? WHERE id = ?', [json_encode($s), $p['id']]);
    $p = DB::one('SELECT * FROM profiles WHERE id = ?', [$p['id']]);
    ok(['equipped' => effective_equipped($p)]);
    break;

case 'game/reward': // POST {collected} -> capped bonus shells from the mini-game
    $p = Auth::requireProfile();
    $collected = max(0, min(60, (int) field('collected', 0)));
    $bonus = min($collected, 12); // capped so the game never out-earns real lessons
    if ($bonus > 0) {
        DB::run('UPDATE profiles SET shells = shells + ? WHERE id = ?', [$bonus, $p['id']]);
        DB::run('INSERT INTO rewards (profile_id, type, value, meta, created_at) VALUES (?,?,?,?,?)',
            [$p['id'], 'game', $bonus, json_encode(['collected' => $collected]), DB::now()]);
    }
    $bal = DB::one('SELECT shells FROM profiles WHERE id = ?', [$p['id']]);
    ok(['bonus' => $bonus, 'collected' => $collected, 'shells' => (int)($bal['shells'] ?? 0)]);
    break;

case 'settings/app_name': // POST {name}  (parent) -> rename the app
    Auth::requireParent();
    $name = trim((string) field('name', ''));
    if ($name === '' || mb_strlen($name) > 40) fail('bad_input', 'Pick a name up to 40 letters.');
    DB::run('DELETE FROM settings WHERE skey = ?', ['app_name']);
    DB::run('INSERT INTO settings (skey, svalue) VALUES (?, ?)', ['app_name', $name]);
    ok(['app_name' => $name]);
    break;

case 'topics': // GET &profile_id= (parent) -> a child's interest topics
    Auth::requireParent();
    $pid = (int)($_GET['profile_id'] ?? 0);
    ok(['topics' => DB::all('SELECT id, topic FROM topics WHERE profile_id = ? ORDER BY created_at', [$pid])]);
    break;

case 'topics/add': // POST {profile_id, topic} (parent)
    Auth::requireParent();
    $pid = (int) field('profile_id', 0);
    $t = trim((string) field('topic', ''));
    if ($pid <= 0 || $t === '') fail('bad_input', 'Pick a child and a topic.');
    $id = DB::insert('INSERT INTO topics (profile_id, topic, created_at) VALUES (?,?,?)', [$pid, $t, DB::now()]);
    ok(['id' => $id]);
    break;

case 'topics/delete': // POST {id} (parent)
    Auth::requireParent();
    DB::run('DELETE FROM topics WHERE id = ?', [(int) field('id', 0)]);
    ok();
    break;

case 'content/generate': // POST {profile_id, topic, count?} (parent)
    Auth::requireParent();
    $pid = (int) field('profile_id', 0);
    $topic = trim((string) field('topic', ''));
    $count = max(1, min(5, (int) field('count', 3)));
    $child = DB::one('SELECT * FROM profiles WHERE id = ?', [$pid]);
    if (!$child || $topic === '') fail('bad_input', 'Pick a child and a topic.');
    $mk = 'ai_gen_' . gmdate('Ym');
    $capRow = DB::one('SELECT svalue FROM settings WHERE skey = ?', [$mk]);
    $used = (int)($capRow['svalue'] ?? 0);
    $cap = (int)($cfg['ai_month_cap'] ?? 200);
    if ($used >= $cap) fail('cap_reached', "This month's passage limit is reached.");
    $allowed = allowed_chars_for((int)$child['current_lesson']);
    $gen = anthropic_generate($cfg, $topic, (int)$child['reading_level'], $allowed, $count);
    if (isset($gen['error'])) {
        $msg = $gen['error'] === 'no_key'
            ? 'No AI key is set up yet. Add anthropic_api_key to the server config to turn this on.'
            : 'The AI is unavailable right now — try again later.';
        fail($gen['error'], $msg, 503);
    }
    $inserted = 0; $rejected = 0;
    foreach ($gen['passages'] as $txt) {
        if (validate_passage($txt, $allowed)['ok']) {
            DB::run('INSERT INTO content_items (profile_id, topic, reading_level, text, allowed_keys, status, created_at)
                     VALUES (?,?,?,?,?,?,?)',
                [$pid, $topic, (int)$child['reading_level'], $txt, json_encode($allowed), 'pending', DB::now()]);
            $inserted++;
        } else { $rejected++; }
    }
    DB::run('DELETE FROM settings WHERE skey = ?', [$mk]);
    DB::run('INSERT INTO settings (skey, svalue) VALUES (?, ?)', [$mk, (string)($used + $inserted)]);
    ok(['generated' => $inserted, 'rejected' => $rejected]);
    break;

case 'content/add': // POST {profile_id, text} (parent) -> own passage into the queue
    Auth::requireParent();
    $pid = (int) field('profile_id', 0);
    $text = trim((string) field('text', ''));
    $child = DB::one('SELECT * FROM profiles WHERE id = ?', [$pid]);
    if (!$child || $text === '') fail('bad_input', 'Pick a child and write a passage.');
    $allowed = allowed_chars_for((int)$child['current_lesson']);
    $v = validate_passage($text, $allowed, 400);
    $id = DB::insert('INSERT INTO content_items (profile_id, topic, reading_level, text, allowed_keys, status, created_at)
                      VALUES (?,?,?,?,?,?,?)',
        [$pid, '(your own)', (int)$child['reading_level'], $text, json_encode($allowed), 'pending', DB::now()]);
    ok(['id' => $id, 'warnings' => $v['ok'] ? [] : $v['bad']]);
    break;

case 'content/queue': // GET &profile_id? (parent) -> pending passages
    Auth::requireParent();
    $pid = (int)($_GET['profile_id'] ?? 0);
    $rows = $pid
        ? DB::all('SELECT * FROM content_items WHERE status = ? AND profile_id = ? ORDER BY created_at DESC', ['pending', $pid])
        : DB::all('SELECT * FROM content_items WHERE status = ? ORDER BY created_at DESC', ['pending']);
    ok(['items' => $rows]);
    break;

case 'content/review': // POST {id, action: approve|reject, text?} (parent)
    Auth::requireParent();
    $id = (int) field('id', 0);
    $action = (string) field('action', '');
    $it = DB::one('SELECT * FROM content_items WHERE id = ?', [$id]);
    if (!$it) fail('not_found', 'No such passage.', 404);
    if ($action === 'approve') {
        $text = field('text') !== null ? trim((string) field('text')) : $it['text'];
        if ($text === '') fail('bad_input', 'Passage is empty.');
        DB::run('UPDATE content_items SET status = ?, text = ?, reviewed_at = ? WHERE id = ?',
            ['approved', $text, DB::now(), $id]);
    } elseif ($action === 'reject') {
        DB::run('UPDATE content_items SET status = ?, reviewed_at = ? WHERE id = ?', ['rejected', DB::now(), $id]);
    } else {
        fail('bad_input', 'Unknown action.');
    }
    ok();
    break;

case 'content/mine': // GET -> approved passages for the active profile ("made for you")
    $p = Auth::requireProfile();
    ok(['items' => DB::all('SELECT id, topic, text FROM content_items WHERE profile_id = ? AND status = ? ORDER BY created_at DESC',
        [$p['id'], 'approved'])]);
    break;

default:
    fail('not_found', 'Unknown route: ' . $r, 404);
}

// ---- Progress aggregation -------------------------------------------------
function build_progress(int $pid): array {
    $attempts = DB::all('SELECT lesson_id, source, duration_ms, wpm, accuracy, stars, created_at
                         FROM attempts WHERE profile_id = ? ORDER BY created_at DESC', [$pid]);
    $totalMs = 0; $wpms = []; $accs = []; $dates = []; $last = null;
    foreach ($attempts as $a) {
        $totalMs += (int)$a['duration_ms'];
        if ((int)$a['wpm'] > 0) $wpms[] = (int)$a['wpm'];
        if ((float)$a['accuracy'] > 0) $accs[] = (float)$a['accuracy'];
        $d = substr($a['created_at'], 0, 10);
        $dates[$d] = true;
        if ($last === null) $last = $a['created_at'];
    }
    // best accuracy / wpm / stars per lesson
    $perLesson = [];
    foreach (DB::all('SELECT lesson_id, MAX(accuracy) acc, MAX(wpm) wpm, MAX(stars) stars, COUNT(*) tries
                      FROM attempts WHERE profile_id = ? AND lesson_id IS NOT NULL GROUP BY lesson_id', [$pid]) as $b) {
        $perLesson[(int)$b['lesson_id']] = [
            'accuracy' => (float)$b['acc'], 'wpm' => (int)$b['wpm'],
            'stars' => (int)$b['stars'], 'tries' => (int)$b['tries'],
        ];
    }
    // lessons passed count (acc >= that lesson's threshold)
    $passed = 0;
    foreach (DB::all('SELECT a.lesson_id, MAX(a.accuracy) acc, l.pass_threshold thr
                      FROM attempts a JOIN lessons l ON l.id = a.lesson_id
                      WHERE a.profile_id = ? GROUP BY a.lesson_id, l.pass_threshold', [$pid]) as $row) {
        if ((float)$row['acc'] >= (float)$row['thr']) $passed++;
    }

    $streak = streak_days(array_keys($dates));
    return [
        'total_attempts' => count($attempts),
        'total_minutes' => (int) round($totalMs / 60000),
        'days_practiced' => count($dates),
        'streak' => $streak,
        'avg_accuracy' => $accs ? round(array_sum($accs) / count($accs), 3) : 0,
        'best_wpm' => $wpms ? max($wpms) : 0,
        'avg_wpm' => $wpms ? (int) round(array_sum($wpms) / count($wpms)) : 0,
        'lessons_passed' => $passed,
        'last_active' => $last,
        'per_lesson' => $perLesson,
        'recent' => array_slice($attempts, 0, 10),
    ];
}

// Consecutive days practiced ending today or yesterday (UTC).
function streak_days(array $isoDates): int {
    if (!$isoDates) return 0;
    $set = array_flip($isoDates);
    $today = gmdate('Y-m-d');
    $yest = gmdate('Y-m-d', strtotime($today . ' -1 day'));
    if (!isset($set[$today]) && !isset($set[$yest])) return 0;
    $cursor = isset($set[$today]) ? $today : $yest;
    $streak = 0;
    while (isset($set[$cursor])) {
        $streak++;
        $cursor = gmdate('Y-m-d', strtotime($cursor . ' -1 day'));
    }
    return $streak;
}

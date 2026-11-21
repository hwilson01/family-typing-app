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

// ---- Routes ---------------------------------------------------------------
switch ($r) {

case 'session':
    $p = Auth::currentProfile();
    ok([
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

    DB::run('INSERT INTO attempts (profile_id, lesson_id, source, duration_ms, wpm, accuracy, stars, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
        [$p['id'], $lessonId, $source, $dur, $wpm, $acc, $stars, DB::now()]);
    if (is_array($keystats)) upsert_keystats((int)$p['id'], $keystats);

    // Advance the pointer only when the CURRENT lesson is passed.
    $passed = $lessonId && $acc >= $threshold;
    $newCurrent = (int) $p['current_lesson'];
    if ($passed && $lessonOrder === (int) $p['current_lesson']) {
        $next = min($lessonOrder + 1, max_order());
        if ($next !== $newCurrent) {
            DB::run('UPDATE profiles SET current_lesson = ? WHERE id = ?', [$next, $p['id']]);
            $newCurrent = $next;
        }
    }
    ok(['stars' => $stars, 'passed' => (bool)$passed, 'current_lesson' => $newCurrent]);
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

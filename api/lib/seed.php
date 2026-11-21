<?php
// Seed / re-seed the lessons table from data/curriculum.json.
// Idempotent: upserts by lesson_order so re-running after a curriculum edit
// updates rows in place rather than duplicating them.
require_once __DIR__ . '/db.php';

function curriculum_path(): string {
    return __DIR__ . '/../../data/curriculum.json';
}

function seed_curriculum(): array {
    $path = curriculum_path();
    if (!is_file($path)) {
        throw new RuntimeException('curriculum.json not found at ' . $path);
    }
    $doc = json_decode(file_get_contents($path), true);
    if (!$doc || empty($doc['lessons'])) {
        throw new RuntimeException('curriculum.json is empty or invalid');
    }

    $inserted = 0; $updated = 0;
    foreach ($doc['lessons'] as $L) {
        $order = (int) $L['order'];
        $drill = json_encode([
            'intro_keys' => $L['new_keys'],
            'steps'      => $L['steps'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $newKeys = json_encode($L['new_keys']);
        $thr = $L['pass_threshold'] ?? 0.9;

        $existing = DB::one('SELECT id FROM lessons WHERE lesson_order = ?', [$order]);
        if ($existing) {
            DB::run('UPDATE lessons SET stage=?, title=?, new_keys=?, pass_threshold=?, drill_data=? WHERE lesson_order=?',
                [$L['stage'], $L['title'], $newKeys, $thr, $drill, $order]);
            $updated++;
        } else {
            DB::run('INSERT INTO lessons (lesson_order, stage, title, new_keys, pass_threshold, drill_data, created_at)
                     VALUES (?,?,?,?,?,?,?)',
                [$order, $L['stage'], $L['title'], $newKeys, $thr, $drill, DB::now()]);
            $inserted++;
        }
    }
    // Also store the finger map + colors so the keyboard UI can fetch them.
    DB::run('DELETE FROM settings WHERE skey = ?', ['keyboard_meta']);
    DB::run('INSERT INTO settings (skey, svalue) VALUES (?, ?)', [
        'keyboard_meta',
        json_encode([
            'layout' => $doc['layout'] ?? 'us_qwerty',
            'fingers' => $doc['fingers'] ?? [],
            'finger_colors' => $doc['finger_colors'] ?? [],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    return ['inserted' => $inserted, 'updated' => $updated,
            'total' => $inserted + $updated];
}

<?php
// Creates all tables (Phase 1 + the Phase 2 tables, which stay empty for now).
// Driver-aware so setup.php (MySQL) and the test harness (SQLite) share it.
require_once __DIR__ . '/db.php';

function migrate(): void {
    $mysql = DB::driver() !== 'sqlite';
    // Column-type shims
    $pk   = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int  = 'INTEGER';
    $txt  = 'TEXT';
    $dt   = $mysql ? 'DATETIME' : 'TEXT';
    $dec  = $mysql ? 'DECIMAL(5,4)' : 'REAL';
    $eng  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    // Short, indexable string
    $vc   = fn(int $n) => $mysql ? "VARCHAR($n)" : 'TEXT';

    $stmts = [];

    $stmts[] = "CREATE TABLE IF NOT EXISTS settings (
        skey {$vc(64)} PRIMARY KEY,
        svalue $txt
    )$eng";

    $stmts[] = "CREATE TABLE IF NOT EXISTS profiles (
        id $pk,
        name {$vc(60)} NOT NULL,
        avatar {$vc(40)} NOT NULL DEFAULT 'cat',
        role {$vc(10)} NOT NULL DEFAULT 'child',
        pin_hash $txt NOT NULL,
        current_lesson $int NOT NULL DEFAULT 1,
        reading_level $int NOT NULL DEFAULT 4,
        settings $txt,
        failed_attempts $int NOT NULL DEFAULT 0,
        locked_until $dt NULL,
        created_at $dt NOT NULL
    )$eng";

    $stmts[] = "CREATE TABLE IF NOT EXISTS lessons (
        id $pk,
        lesson_order $int NOT NULL,
        stage {$vc(16)} NOT NULL,
        title {$vc(120)} NOT NULL,
        new_keys $txt,
        pass_threshold $dec NOT NULL DEFAULT 0.9,
        drill_data $txt NOT NULL,
        created_at $dt NOT NULL
    )$eng";
    $stmts[] = "CREATE UNIQUE INDEX IF NOT EXISTS idx_lessons_order ON lessons (lesson_order)";

    $stmts[] = "CREATE TABLE IF NOT EXISTS attempts (
        id $pk,
        profile_id $int NOT NULL,
        lesson_id $int NULL,
        content_item_id $int NULL,
        source {$vc(16)} NOT NULL DEFAULT 'lesson',
        duration_ms $int NOT NULL DEFAULT 0,
        wpm $int NOT NULL DEFAULT 0,
        accuracy $dec NOT NULL DEFAULT 0,
        stars $int NOT NULL DEFAULT 0,
        created_at $dt NOT NULL
    )$eng";
    $stmts[] = "CREATE INDEX IF NOT EXISTS idx_attempts_profile ON attempts (profile_id)";

    $stmts[] = "CREATE TABLE IF NOT EXISTS key_stats (
        id $pk,
        profile_id $int NOT NULL,
        key_code {$vc(16)} NOT NULL,
        hits $int NOT NULL DEFAULT 0,
        misses $int NOT NULL DEFAULT 0,
        total_time_ms $int NOT NULL DEFAULT 0
    )$eng";
    $stmts[] = "CREATE UNIQUE INDEX IF NOT EXISTS idx_keystats ON key_stats (profile_id, key_code)";

    // ---- Phase 2 tables: created now, unused in Phase 1 ----
    $stmts[] = "CREATE TABLE IF NOT EXISTS content_items (
        id $pk,
        profile_id $int NULL,
        topic {$vc(200)},
        reading_level $int NOT NULL DEFAULT 4,
        text $txt NOT NULL,
        allowed_keys $txt,
        status {$vc(12)} NOT NULL DEFAULT 'pending',
        created_at $dt NOT NULL,
        reviewed_at $dt NULL
    )$eng";

    $stmts[] = "CREATE TABLE IF NOT EXISTS topics (
        id $pk,
        profile_id $int NOT NULL,
        topic {$vc(200)} NOT NULL,
        created_at $dt NOT NULL
    )$eng";

    $stmts[] = "CREATE TABLE IF NOT EXISTS rewards (
        id $pk,
        profile_id $int NOT NULL,
        type {$vc(32)} NOT NULL,
        value $int NOT NULL DEFAULT 0,
        meta $txt,
        created_at $dt NOT NULL
    )$eng";

    $pdo = DB::pdo();
    foreach ($stmts as $s) {
        $pdo->exec($s);
    }
}

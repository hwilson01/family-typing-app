<?php
// PDO connection + small query helpers. Driver-aware so the same code runs
// on MySQL (production) and SQLite (local tests).

class DB {
    private static ?PDO $pdo = null;
    private static array $cfg = [];

    public static function init(array $cfg): void {
        self::$cfg = $cfg;
        $driver = $cfg['db_driver'] ?? 'mysql';
        if ($driver === 'sqlite') {
            $path = $cfg['db_path'];
            @mkdir(dirname($path), 0775, true);
            $dsn = 'sqlite:' . $path;
            self::$pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['db_host'], $cfg['db_port'] ?? 3306, $cfg['db_name']
            );
            self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
    }

    public static function driver(): string {
        return self::$cfg['db_driver'] ?? 'mysql';
    }

    public static function pdo(): PDO {
        if (self::$pdo === null) {
            throw new RuntimeException('DB not initialized');
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array {
        return self::run($sql, $params)->fetchAll();
    }

    public static function insert(string $sql, array $params = []): int {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function now(): string {
        return gmdate('Y-m-d H:i:s'); // store UTC as ISO text; sortable on both drivers
    }
}

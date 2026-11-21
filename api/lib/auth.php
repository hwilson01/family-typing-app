<?php
// Authentication: family password gate, profile PIN with lockout, role checks.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class Auth {
    // --- Family gate -------------------------------------------------------
    static function familyOk(): bool {
        return !empty($_SESSION['family']);
    }

    static function requireFamily(): void {
        if (!self::familyOk()) {
            fail('no_family', 'The family password is required.', 401);
        }
    }

    static function loginFamily(string $password, array $cfg): bool {
        $hash = $cfg['family_password_hash'] ?? '';
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }
        $_SESSION['family'] = true;
        return true;
    }

    // --- Active profile ----------------------------------------------------
    static function currentProfile(): ?array {
        if (empty($_SESSION['profile_id'])) return null;
        return DB::one('SELECT * FROM profiles WHERE id = ?', [$_SESSION['profile_id']]);
    }

    static function requireProfile(): array {
        self::requireFamily();
        $p = self::currentProfile();
        if (!$p) fail('no_profile', 'Pick a profile first.', 401);
        return $p;
    }

    static function requireParent(): array {
        $p = self::requireProfile();
        if (($p['role'] ?? 'child') !== 'parent') {
            fail('not_parent', 'That area is for grown-ups.', 403);
        }
        return $p;
    }

    // --- PIN with lockout --------------------------------------------------
    // Returns ['ok'=>bool, 'error'=>?, 'locked_seconds'=>?]
    static function selectProfile(int $profileId, string $pin, array $cfg): array {
        self::requireFamily();
        $p = DB::one('SELECT * FROM profiles WHERE id = ?', [$profileId]);
        if (!$p) return ['ok' => false, 'error' => 'not_found'];

        $now = time();
        $lockedUntil = !empty($p['locked_until']) ? strtotime($p['locked_until'] . ' UTC') : 0;
        if ($lockedUntil && $lockedUntil > $now) {
            return ['ok' => false, 'error' => 'locked', 'locked_seconds' => $lockedUntil - $now];
        }

        if (!password_verify($pin, $p['pin_hash'])) {
            $attempts = (int) $p['failed_attempts'] + 1;
            $max = (int) ($cfg['pin_max_attempts'] ?? 5);
            if ($attempts >= $max) {
                $mins = (int) ($cfg['pin_lockout_minutes'] ?? 15);
                $until = gmdate('Y-m-d H:i:s', $now + $mins * 60);
                DB::run('UPDATE profiles SET failed_attempts = 0, locked_until = ? WHERE id = ?',
                        [$until, $profileId]);
                return ['ok' => false, 'error' => 'locked', 'locked_seconds' => $mins * 60];
            }
            DB::run('UPDATE profiles SET failed_attempts = ? WHERE id = ?', [$attempts, $profileId]);
            return ['ok' => false, 'error' => 'wrong_pin', 'remaining' => $max - $attempts];
        }

        // Success: clear counters, set active profile.
        DB::run('UPDATE profiles SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$profileId]);
        $_SESSION['profile_id'] = (int) $p['id'];
        $_SESSION['role'] = $p['role'];
        return ['ok' => true];
    }

    static function logoutProfile(): void {
        unset($_SESSION['profile_id'], $_SESSION['role']);
    }

    static function logoutAll(): void {
        $_SESSION = [];
    }

    // Public view of a profile (never leaks pin_hash).
    static function publicProfile(array $p): array {
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'avatar' => $p['avatar'],
            'role' => $p['role'],
            'current_lesson' => (int) $p['current_lesson'],
            'reading_level' => (int) $p['reading_level'],
        ];
    }
}

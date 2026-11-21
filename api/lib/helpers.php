<?php
// Request/response helpers for the JSON API.

function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ok($data = []): void {
    json_out(array_merge(['ok' => true], $data));
}

function fail(string $error, string $message = '', int $status = 400): void {
    json_out(['ok' => false, 'error' => $error, 'message' => $message], $status);
}

// Parse JSON body once.
function body(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input');
    $data = $raw === '' ? [] : json_decode($raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

function field(string $key, $default = null) {
    $b = body();
    return $b[$key] ?? $default;
}

// Start a session scoped to this app's subfolder so cookies don't leak to
// sibling apps (planner, speed reader) on the same domain.
function start_app_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // The app lives at <base>/ and the API at <base>/api/. Scope to <base>/.
    $dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 60, // 60 days ("long-lived")
        'path'     => $dir === '' ? '/' : $dir,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('tutor_sess');
    session_start();
}

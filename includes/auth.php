<?php
// Session-based auth helpers.

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 90,
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
        ]);
        session_name('healthsid');
        session_start();
    }
}

function current_user_id(): ?int {
    start_session();
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

function require_user(): int {
    $uid = current_user_id();
    if (!$uid) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'auth']);
        exit;
    }
    return $uid;
}

function login_user(int $uid): void {
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
}

function logout_user(): void {
    start_session();
    $_SESSION = [];
    session_destroy();
}

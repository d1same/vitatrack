<?php
// Session auth + remember-me tokens.
// Shared hosts garbage-collect PHP session files aggressively, so the session
// alone can't be trusted to persist. A long-lived vt_remember cookie (random
// token, stored hashed in auth_tokens) silently restores the login.

const REMEMBER_DAYS = 90;

// True when the CLIENT connection is HTTPS — including behind Cloudflare,
// where the origin may be plain HTTP but X-Forwarded-Proto says https.
// (Plain $_SERVER['HTTPS'] alone would drop the cookies' Secure flag there.)
function is_https(): bool {
    return !empty($_SERVER['HTTPS'])
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || strpos($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https') !== false;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        @ini_set('session.gc_maxlifetime', (string)(REMEMBER_DAYS * 86400));
        session_set_cookie_params([
            'lifetime' => REMEMBER_DAYS * 86400,
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => is_https(),
        ]);
        session_name('healthsid');
        session_start();
    }
}

function remember_cookie_opts(int $expires): array {
    return [
        'expires' => $expires, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => is_https(),
    ];
}

function issue_remember_token(int $uid): void {
    $token = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO auth_tokens (user_id, token_hash, expires) VALUES (?,?,?)")
      ->execute([$uid, hash('sha256', $token), time() + REMEMBER_DAYS * 86400]);
    setcookie('vt_remember', $token, remember_cookie_opts(time() + REMEMBER_DAYS * 86400));
    db()->prepare("DELETE FROM auth_tokens WHERE expires < ?")->execute([time()]); // prune expired
}

function revoke_remember_tokens(int $uid, bool $keepCurrent = false): void {
    $current = $_COOKIE['vt_remember'] ?? '';
    if ($keepCurrent && strlen($current) === 64) {
        db()->prepare("DELETE FROM auth_tokens WHERE user_id=? AND token_hash != ?")
          ->execute([$uid, hash('sha256', $current)]);
    } else {
        db()->prepare("DELETE FROM auth_tokens WHERE user_id=?")->execute([$uid]);
    }
}

function current_user_id(): ?int {
    start_session();
    if (isset($_SESSION['uid'])) return (int)$_SESSION['uid'];
    // Session lost (host GC, new device profile…) — restore from remember token
    $token = $_COOKIE['vt_remember'] ?? '';
    if (strlen($token) === 64 && ctype_xdigit($token)) {
        $st = db()->prepare("SELECT user_id FROM auth_tokens WHERE token_hash=? AND expires > ?");
        $st->execute([hash('sha256', $token), time()]);
        $row = $st->fetch();
        if ($row) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['user_id'];
            return (int)$row['user_id'];
        }
    }
    return null;
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
    issue_remember_token($uid);
}

function logout_user(): void {
    start_session();
    $token = $_COOKIE['vt_remember'] ?? '';
    if (strlen($token) === 64) {
        db()->prepare("DELETE FROM auth_tokens WHERE token_hash=?")->execute([hash('sha256', $token)]);
        setcookie('vt_remember', '', remember_cookie_opts(time() - 3600));
    }
    $_SESSION = [];
    session_destroy();
}

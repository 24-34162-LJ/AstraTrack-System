<?php
session_start();

if (!empty($_SESSION['user'])) {
    try {
        require_once __DIR__ . '/config/database.php';
        require_once __DIR__ . '/function/log_activity.php';
        $userName = (string)($_SESSION['user']['full_name'] ?? $_SESSION['user']['name'] ?? 'User');
        logActivity($conn, 'User Account', $userName . ' logged out', [
            'action' => 'logout',
            'role' => $_SESSION['user']['role'] ?? null,
        ]);
    } catch (Throwable $ex) {
        // A logging or database issue must not prevent the user from signing out.
        error_log('Logout activity logging failed: ' . $ex->getMessage());
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;
?>

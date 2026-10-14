<?php
require_once __DIR__ . '/../common.php';

// Only a POST carrying a valid CSRF token logs out (a cross-site link can't)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf()) {
    header('Location: index.php');
    exit;
}

// Clear all session variables
$_SESSION = [];

// Delete the session cookie (same options as startAppSession())
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Strict'
    ]);
}

// Destroy the session
session_destroy();

// Redirect to the admin login page
header('Location: login.php');
exit;

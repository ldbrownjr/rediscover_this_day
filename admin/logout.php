<?php
require_once '../common.php';

// Clear all session variables
$_SESSION = [];

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to the admin login page
try {
    $basePath = getWebBasePath();
    header('Location: ' . $basePath . '/admin/login.php');
} catch (Exception $e) {
    // Fallback to relative redirect if getWebBasePath fails
    header('Location: login.php');
}
exit;

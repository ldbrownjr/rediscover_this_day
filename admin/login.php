<?php
require_once __DIR__ . '/../common.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $username = is_string($username) ? $username : '';
    $password = is_string($password) ? $password : '';
    // Safe form for the log: no control characters (no log injection), max 64 chars
    $logUsername = substr((string)preg_replace('/[\x00-\x1F\x7F]/', '', $username), 0, 64);
    
    if (!verifyCsrf()) {
        $error = 'Session expired, please try again';
        logMessage('Login rejected: missing or invalid CSRF token', 'WARNING');
    } elseif (verifyAdminPassword($username, $password)) {
        // New session ID on privilege change (prevents session fixation)
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        rotateCsrfToken();
        logMessage('Admin login successful for user: ' . $logUsername, 'INFO');
        header('Location: index.php');
        exit;
    } else {
        sleep(1); // Slow down password guessing
        $error = 'Invalid username or password';
        logMessage('Failed login attempt for user: ' . $logUsername, 'WARNING');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Rediscover This Day</title>
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>Rediscover This Day</h1>
            <h2>Admin Login</h2>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo h($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            
            <div class="info-box">
                <p><strong>Default credentials:</strong></p>
                <p>Username: <code>admin</code></p>
                <p>Password: <code>password</code></p>
                <p><em>Please change these after first login!</em></p>
            </div>
        </div>
    </div>
</body>
</html>

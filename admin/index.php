<?php
require_once '../common.php';
require_once '../api/synology.php';

requireAdmin();

$config = loadConfig();
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_config') {
        // Update Synology settings
        $config['synology']['url'] = trim($_POST['synology_url']);
        $config['synology']['username'] = trim($_POST['synology_username']);
        
        // Only update password if provided
        if (!empty($_POST['synology_password'])) {
            $config['synology']['password'] = $_POST['synology_password'];
        }
        
        // Update photo settings
        $config['photos']['first_year'] = (int)$_POST['first_year'];
        
        // Update slideshow settings
        $config['slideshow']['duration'] = (int)$_POST['duration'];
        $config['slideshow']['transition'] = $_POST['transition'];
        $config['slideshow']['refresh_time'] = $_POST['refresh_time'];
        
        if (saveConfig($config)) {
            $message = 'Configuration saved successfully!';
            $messageType = 'success';
            logMessage('Configuration updated', 'INFO');
        } else {
            $message = 'Failed to save configuration';
            $messageType = 'error';
            logMessage('Failed to save configuration', 'ERROR');
        }
    }
    elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($newPassword)) {
            $message = 'New password cannot be empty';
            $messageType = 'error';
        }
        elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match';
            $messageType = 'error';
        }
        elseif (!verifyAdminPassword($config['admin']['username'], $currentPassword)) {
            $message = 'Current password is incorrect';
            $messageType = 'error';
        }
        else {
            $config['admin']['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            
            if (saveConfig($config)) {
                $message = 'Password changed successfully!';
                $messageType = 'success';
                logMessage('Admin password changed', 'INFO');
            } else {
                $message = 'Failed to change password';
                $messageType = 'error';
            }
        }
    }
    elseif ($action === 'test_connection') {
        if (empty($config['synology']['url']) || empty($config['synology']['username']) || empty($config['synology']['password'])) {
            $message = 'Please configure Synology connection settings first';
            $messageType = 'error';
        } else {
            $api = new SynologyAPI(
                $config['synology']['url'],
                $config['synology']['username'],
                $config['synology']['password']
            );
            
            if ($api->testConnection()) {
                $message = 'Connection successful!';
                $messageType = 'success';
                logMessage('Synology connection test successful', 'INFO');
                $api->logout();
            } else {
                $message = 'Connection failed. Please check your settings.';
                $messageType = 'error';
                logMessage('Synology connection test failed', 'ERROR');
            }
        }
    }
    elseif ($action === 'refresh_now') {
        // Trigger manual refresh
        require_once '../cron/daily_refresh.php';
        
        $result = performDailyRefresh();
        
        if ($result['success']) {
            $message = "Refresh completed! Found {$result['count']} photos.";
            $messageType = 'success';
        } else {
            $message = "Refresh failed: {$result['error']}";
            $messageType = 'error';
        }
    }
    
    // Reload config in case it was changed
    $config = loadConfig();
}

$lastRefresh = getLastRefreshInfo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Rediscover This Day</title>
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>Rediscover This Day - Admin</h1>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-secondary" target="_blank">View Slideshow</a>
                <a href="logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </header>
        
        <?php if ($message): ?>
            <div class="message message-<?php echo h($messageType); ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="admin-content">
            <!-- Synology Connection Settings -->
            <section class="admin-section">
                <h2>Synology Photos Connection</h2>
                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="save_config">
                    
                    <div class="form-group">
                        <label for="synology_url">NAS URL:</label>
                        <input type="text" id="synology_url" name="synology_url" 
                               value="<?php echo h($config['synology']['url']); ?>"
                               placeholder="https://192.168.1.100:5001 or https://yournas.synology.me">
                        <small>Include protocol (http:// or https://) and port if needed</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="synology_username">Username:</label>
                        <input type="text" id="synology_username" name="synology_username" 
                               value="<?php echo h($config['synology']['username']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="synology_password">Password:</label>
                        <input type="password" id="synology_password" name="synology_password" 
                               placeholder="Leave blank to keep current password">
                        <small>Only enter if you want to change it</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Configuration</button>
                </form>
                
                <form method="POST" action="index.php" style="margin-top: 10px;">
                    <input type="hidden" name="action" value="test_connection">
                    <button type="submit" class="btn btn-secondary">Test Connection</button>
                </form>
            </section>
            
            <!-- Photo Settings -->
            <section class="admin-section">
                <h2>Photo Settings</h2>
                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="save_config">
                    
                    <!-- Copy other fields as hidden -->
                    <input type="hidden" name="synology_url" value="<?php echo h($config['synology']['url']); ?>">
                    <input type="hidden" name="synology_username" value="<?php echo h($config['synology']['username']); ?>">
                    <input type="hidden" name="duration" value="<?php echo h($config['slideshow']['duration']); ?>">
                    <input type="hidden" name="transition" value="<?php echo h($config['slideshow']['transition']); ?>">
                    <input type="hidden" name="refresh_time" value="<?php echo h($config['slideshow']['refresh_time']); ?>">
                    
                    <div class="form-group">
                        <label for="first_year">First Year to Include:</label>
                        <input type="number" id="first_year" name="first_year" 
                               value="<?php echo h($config['photos']['first_year']); ?>"
                               min="1900" max="<?php echo date('Y'); ?>">
                        <small>Don't show photos older than this year</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </section>
            
            <!-- Slideshow Settings -->
            <section class="admin-section">
                <h2>Slideshow Settings</h2>
                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="save_config">
                    
                    <!-- Copy other fields as hidden -->
                    <input type="hidden" name="synology_url" value="<?php echo h($config['synology']['url']); ?>">
                    <input type="hidden" name="synology_username" value="<?php echo h($config['synology']['username']); ?>">
                    <input type="hidden" name="first_year" value="<?php echo h($config['photos']['first_year']); ?>">
                    
                    <div class="form-group">
                        <label for="duration">Photo Duration (seconds):</label>
                        <input type="number" id="duration" name="duration" 
                               value="<?php echo h($config['slideshow']['duration']); ?>"
                               min="1" max="60">
                    </div>
                    
                    <div class="form-group">
                        <label for="transition">Transition Effect:</label>
                        <select id="transition" name="transition">
                            <option value="fade" <?php echo $config['slideshow']['transition'] === 'fade' ? 'selected' : ''; ?>>Fade</option>
                            <option value="none" <?php echo $config['slideshow']['transition'] === 'none' ? 'selected' : ''; ?>>None (Instant)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="refresh_time">Daily Refresh Time:</label>
                        <input type="time" id="refresh_time" name="refresh_time" 
                               value="<?php echo h($config['slideshow']['refresh_time']); ?>">
                        <small>Time to automatically fetch new photos (configure cron separately)</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </section>
            
            <!-- Admin Security -->
            <section class="admin-section">
                <h2>Admin Security</h2>
                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="current_password">Current Password:</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </section>
            
            <!-- Status -->
            <section class="admin-section">
                <h2>Status</h2>
                <div class="status-info">
                    <?php if ($lastRefresh): ?>
                        <p><strong>Last Refresh:</strong> <?php echo h($lastRefresh['formatted']); ?></p>
                        <p><strong>Photos Today:</strong> <?php echo h($lastRefresh['count']); ?></p>
                    <?php else: ?>
                        <p><em>No photos cached yet</em></p>
                    <?php endif; ?>
                </div>
                
                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="refresh_now">
                    <button type="submit" class="btn btn-secondary">Refresh Now</button>
                </form>
            </section>
        </div>
    </div>
</body>
</html>

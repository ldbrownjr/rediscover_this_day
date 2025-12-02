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
    
    // Determine action based on which button was clicked
    if (isset($_POST['save_config'])) {
        $action = 'save_config';
    } elseif (isset($_POST['change_password'])) {
        $action = 'change_password';
    } elseif (isset($_POST['test_connection'])) {
        $action = 'test_connection';
    } elseif (isset($_POST['refresh_now'])) {
        $action = 'refresh_now';
    }
    
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
        
        // Update debug settings
        $config['debug']['log_level'] = $_POST['log_level'];
        
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
                <form method="POST" action="/admin/logout.php" style="display: inline;">
                    <button type="submit" class="btn btn-secondary">Logout</button>
                </form>
            </div>
        </header>
        
        <?php if ($message): ?>
            <div class="message message-<?php echo h($messageType); ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="index.php">
        <div class="admin-content">
            <!-- Top Row: Connection and Photo Settings -->
            <div class="admin-grid">
                <!-- Synology Connection Settings -->
                <section class="admin-section compact">
                    <h3>Synology Connection</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="synology_url">NAS URL:</label>
                            <input type="text" id="synology_url" name="synology_url" 
                                   value="<?php echo h($config['synology']['url']); ?>"
                                   placeholder="https://192.168.1.100:5001">
                        </div>
                        
                        <div class="form-group">
                            <label for="synology_username">Username:</label>
                            <input type="text" id="synology_username" name="synology_username" 
                                   value="<?php echo h($config['synology']['username']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="synology_password">Password:</label>
                        <input type="password" id="synology_password" name="synology_password" 
                               placeholder="Leave blank to keep current">
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="test_connection" class="btn btn-secondary btn-sm">Test Connection</button>
                    </div>
                </section>
                
                <!-- Photo Settings -->
                <section class="admin-section compact">
                    <h3>Photo Settings</h3>
                    
                    <div class="form-group">
                        <label for="first_year">First Year:</label>
                        <input type="number" id="first_year" name="first_year" 
                               value="<?php echo h($config['photos']['first_year']); ?>"
                               min="1900" max="<?php echo date('Y'); ?>">
                    </div>
                </section>
            </div>
            
            <!-- Middle Row: Slideshow and Security -->
            <div class="admin-grid">
                <!-- Slideshow Settings -->
                <section class="admin-section compact">
                    <h3>Slideshow</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="duration">Duration (sec):</label>
                            <input type="number" id="duration" name="duration" 
                                   value="<?php echo h($config['slideshow']['duration']); ?>"
                                   min="1" max="60">
                        </div>
                        
                        <div class="form-group">
                            <label for="transition">Transition:</label>
                            <select id="transition" name="transition">
                                <option value="fade" <?php echo $config['slideshow']['transition'] === 'fade' ? 'selected' : ''; ?>>Fade</option>
                                <option value="none" <?php echo $config['slideshow']['transition'] === 'none' ? 'selected' : ''; ?>>Instant</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="refresh_time">Refresh Time:</label>
                        <input type="time" id="refresh_time" name="refresh_time" 
                               value="<?php echo h($config['slideshow']['refresh_time']); ?>">
                    </div>
                </section>
                        
                        <div class="form-group">
                            <label for="refresh_time">Refresh Time:</label>
                            <input type="time" id="refresh_time" name="refresh_time" 
                                   value="<?php echo h($config['slideshow']['refresh_time']); ?>">
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </form>
                </section>
                
                <!-- Admin Security -->
                <section class="admin-section compact">
                    <h3>Admin Password</h3>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password">Current:</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_password">New:</label>
                                <input type="password" id="new_password" name="new_password" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm:</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-sm">Change</button>
                    </form>
                </section>
            </div>
            
            <!-- Bottom Row: Status and Debug -->
            <div class="admin-grid">
                <!-- Status -->
                <section class="admin-section compact">
                    <h3>Status</h3>
                    <div class="status-info">
                        <?php if ($lastRefresh): ?>
                            <p><strong>Last:</strong> <?php echo h($lastRefresh['formatted']); ?></p>
                            <p><strong>Photos:</strong> <?php echo h($lastRefresh['count']); ?></p>
                        <?php else: ?>
                            <p><em>No photos cached</em></p>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="refresh_now">
                        <button type="submit" class="btn btn-secondary btn-sm">Refresh Now</button>
                    </form>
                </section>
                
                <!-- Debug Settings -->
                <section class="admin-section compact">
                    <h3>Debug</h3>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="save_config">
                        
                        <!-- Copy other fields as hidden -->
                        <input type="hidden" name="synology_url" value="<?php echo h($config['synology']['url']); ?>">
                        <input type="hidden" name="synology_username" value="<?php echo h($config['synology']['username']); ?>">
                        <input type="hidden" name="first_year" value="<?php echo h($config['photos']['first_year']); ?>">
                        <input type="hidden" name="duration" value="<?php echo h($config['slideshow']['duration']); ?>">
                        <input type="hidden" name="transition" value="<?php echo h($config['slideshow']['transition']); ?>">
                        <input type="hidden" name="refresh_time" value="<?php echo h($config['slideshow']['refresh_time']); ?>">
                        
                        <div class="form-group">
                            <label for="log_level">Log Level:</label>
                            <select id="log_level" name="log_level">
                                <option value="ERROR" <?php echo (isset($config['debug']['log_level']) && $config['debug']['log_level'] === 'ERROR') ? 'selected' : ''; ?>>ERROR</option>
                                <option value="WARNING" <?php echo (isset($config['debug']['log_level']) && $config['debug']['log_level'] === 'WARNING') ? 'selected' : ''; ?>>WARNING</option>
                                <option value="INFO" <?php echo (!isset($config['debug']['log_level']) || $config['debug']['log_level'] === 'INFO') ? 'selected' : ''; ?>>INFO</option>
                                <option value="DEBUG" <?php echo (isset($config['debug']['log_level']) && $config['debug']['log_level'] === 'DEBUG') ? 'selected' : ''; ?>>DEBUG</option>
                            </select>
                        </div>
                </section>
            </div>
            
            <!-- Main Action Buttons -->
            <div class="form-actions main-actions">
                <button type="submit" name="save_config" class="btn btn-primary">Save All Settings</button>
            </div>
        </div>
        </form>
    </div>
</body>
</html>

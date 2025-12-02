<?php
/**
 * Common functions and utilities used across the application
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define constants
define('BASE_PATH', dirname(__FILE__));
define('CONFIG_PATH', BASE_PATH . '/config/config.json');
define('CACHE_PATH', BASE_PATH . '/cache');
define('LOG_PATH', BASE_PATH . '/logs');

/**
 * Get the web base path (URL path from document root to application)
 */
function getWebBasePath() {
    // Simple approach: extract from current request
    if (isset($_SERVER['REQUEST_URI']) && isset($_SERVER['SCRIPT_NAME'])) {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $requestDir = dirname($_SERVER['REQUEST_URI']);
        
        // Find the common base path
        $scriptParts = explode('/', trim($scriptDir, '/'));
        $requestParts = explode('/', trim($requestDir, '/'));
        
        $baseParts = [];
        $maxLen = min(count($scriptParts), count($requestParts));
        
        for ($i = 0; $i < $maxLen; $i++) {
            if ($scriptParts[$i] === $requestParts[$i]) {
                $baseParts[] = $scriptParts[$i];
            } else {
                break;
            }
        }
        
        $basePath = '/' . implode('/', $baseParts);
        return $basePath === '/' ? '' : $basePath;
    }
    
    // Fallback: return empty (assume root installation)
    return '';
}
function loadConfig() {
    if (!file_exists(CONFIG_PATH)) {
        // Copy default config if config doesn't exist
        $defaultConfig = BASE_PATH . '/config/config.default.json';
        if (file_exists($defaultConfig)) {
            copy($defaultConfig, CONFIG_PATH);
        } else {
            return null;
        }
    }
    
    $json = file_get_contents(CONFIG_PATH);
    return json_decode($json, true);
}

/**
 * Save configuration to JSON file
 */
function saveConfig($config) {
    $json = json_encode($config, JSON_PRETTY_PRINT);
    return file_put_contents(CONFIG_PATH, $json) !== false;
}

/**
 * Log message to file
 */
function logMessage($message, $level = 'INFO') {
    // Define log levels in order of verbosity
    $logLevels = [
        'ERROR' => 1,
        'WARNING' => 2,
        'INFO' => 3,
        'DEBUG' => 4
    ];
    
    // Get configured log level
    $config = loadConfig();
    $configuredLevel = isset($config['debug']['log_level']) ? $config['debug']['log_level'] : 'INFO';
    
    // Check if this message should be logged
    if (!isset($logLevels[$level]) || !isset($logLevels[$configuredLevel])) {
        return; // Invalid level
    }
    
    if ($logLevels[$level] > $logLevels[$configuredLevel]) {
        return; // Message level is higher than configured level
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logFile = LOG_PATH . '/app.log';
    $logEntry = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Check if user is logged in as admin
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require admin login
 */
function requireAdmin() {
    if (!isAdminLoggedIn()) {
        try {
            $basePath = getWebBasePath();
            header('Location: ' . $basePath . '/admin/login.php');
        } catch (Exception $e) {
            // Fallback to relative redirect if getWebBasePath fails
            header('Location: login.php');
        }
        exit;
    }
}

/**
 * Verify admin password
 */
function verifyAdminPassword($username, $password) {
    $config = loadConfig();
    if (!$config) {
        return false;
    }
    
    $adminUsername = $config['admin']['username'];
    $adminPasswordHash = $config['admin']['password_hash'];
    
    return $username === $adminUsername && password_verify($password, $adminPasswordHash);
}

/**
 * Get cached photos for today
 */
function getCachedPhotos() {
    $cacheFile = CACHE_PATH . '/photos.json';
    if (!file_exists($cacheFile)) {
        return [];
    }
    
    $json = file_get_contents($cacheFile);
    $data = json_decode($json, true);
    
    return $data ? $data : [];
}

/**
 * Save photos to cache
 */
function saveCachedPhotos($photos) {
    $cacheFile = CACHE_PATH . '/photos.json';
    $json = json_encode($photos, JSON_PRETTY_PRINT);
    return file_put_contents($cacheFile, $json) !== false;
}

/**
 * Get last refresh info
 */
function getLastRefreshInfo() {
    $cacheFile = CACHE_PATH . '/photos.json';
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    return [
        'timestamp' => filemtime($cacheFile),
        'formatted' => date('Y-m-d H:i:s', filemtime($cacheFile)),
        'count' => count(getCachedPhotos())
    ];
}

/**
 * Sanitize output for HTML
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Get current date month and day
 */
function getCurrentMonthDay() {
    return [
        'month' => (int)date('n'),
        'day' => (int)date('j')
    ];
}

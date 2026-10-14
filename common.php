<?php
/**
 * Common functions and utilities used across the application
 */

// Define constants
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__FILE__));
}
// First line of every guarded data file: if the web server ever executes the file
// directly, it returns an empty 404 instead of the data that follows.
define('GUARD_LINE', "<?php http_response_code(404); exit; ?>\n");
define('CONFIG_PATH', BASE_PATH . '/config/config.php');
define('LEGACY_CONFIG_PATH', BASE_PATH . '/config/config.json');
define('DEFAULT_CONFIG_PATH', BASE_PATH . '/config/config.default.json');
define('CACHE_PATH', BASE_PATH . '/cache');
define('LOG_PATH', BASE_PATH . '/logs');
define('LOG_FILE', LOG_PATH . '/app.log.php');

/**
 * Whether the current request arrived over HTTPS (directly or via a proxy).
 */
function isHttpsRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return false;
}

/**
 * Start the PHP session with hardened cookie settings (set in code because
 * .htaccess php_value lines don't apply under Nginx / PHP-FPM).
 */
function startAppSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => isHttpsRequest()
    ]);
    session_start();
}

startAppSession();

/**
 * Read a guarded data file, stripping a leading UTF-8 BOM and the guard block
 * (everything up to and including the first "?>" plus one line ending).
 * Returns null if the file can't be read.
 */
function readGuardedFile(string $path): ?string {
    $data = @file_get_contents($path);
    if ($data === false) {
        return null;
    }
    $data = stripUtf8Bom($data);
    if (strncmp($data, '<?', 2) === 0) {
        $end = strpos($data, '?>');
        if ($end === false) {
            return '';
        }
        $data = substr($data, $end + 2);
        // One optional line ending after the guard: CRLF, LF or CR
        if (strncmp($data, "\r\n", 2) === 0) {
            $data = substr($data, 2);
        } elseif ($data !== '' && ($data[0] === "\n" || $data[0] === "\r")) {
            $data = substr($data, 1);
        }
    }
    return stripUtf8Bom($data);
}

/**
 * Remove a leading UTF-8 byte order mark, if present.
 */
function stripUtf8Bom(string $data): string {
    return strncmp($data, "\xEF\xBB\xBF", 3) === 0 ? substr($data, 3) : $data;
}

/**
 * Write a guarded data file atomically (guard line + data).
 */
function writeGuardedFile(string $path, string $data, ?int $mode = null): bool {
    return writeFileAtomic($path, GUARD_LINE . $data, $mode);
}

/**
 * Create a guarded data file only if it doesn't exist yet (never overwrites).
 * Writes a temp file and link()s it into place, so readers never see a partial
 * file; falls back to an exclusive create if hard links aren't supported.
 */
function createGuardedFile(string $path, string $data, int $mode = 0644): bool {
    $data = GUARD_LINE . $data;
    $tmpPath = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (@file_put_contents($tmpPath, $data) !== false && @chmod($tmpPath, $mode)) {
        $linked = @link($tmpPath, $path);
        @unlink($tmpPath);
        if ($linked || file_exists($path)) {
            return $linked;
        }
    } else {
        @unlink($tmpPath);
    }

    // Fallback: exclusive create ('x' fails if the file already exists)
    $fh = @fopen($path, 'x');
    if ($fh === false) {
        return false;
    }
    $ok = fwrite($fh, $data) === strlen($data);
    fclose($fh);
    if (!$ok) {
        @unlink($path);
        return false;
    }
    @chmod($path, $mode);
    return true;
}

/**
 * Read and decode config/config.php. Returns null (and logs an ERROR once per
 * process) if it can't be read or isn't valid JSON.
 */
function readConfigFile(): ?array {
    static $logging = false;   // logMessage() calls loadConfig(), which calls this again
    static $reported = false;  // one ERROR per process, not one per log line

    $json = readGuardedFile(CONFIG_PATH);
    $config = $json === null ? null : json_decode($json, true);
    if (is_array($config)) {
        return $config;
    }

    if (!$logging && !$reported) {
        $logging = true;
        $reported = true;
        $reason = $json === null ? 'unreadable (check owner/permissions)' : 'invalid JSON';
        logMessage('Config file ' . CONFIG_PATH . ' is ' . $reason, 'ERROR');
        $logging = false;
    }
    return null;
}

/**
 * Load configuration.
 *
 * Order: config/config.php (guarded) -> legacy config/config.json (migrated to
 * config.php, then deleted) -> seeded from config/config.default.json.
 *
 * The static flags stop recursion: writeFileAtomic() and logMessage() both end up
 * calling loadConfig(), so while a migration/seed is in progress (or after a failed
 * migration in this process) the source data is returned directly.
 */
function loadConfig() {
    static $busy = false;
    static $migrationAttempted = false; // at most one attempt per process (a failure log must not retry it)

    if (file_exists(CONFIG_PATH)) {
        return readConfigFile();
    }

    if (file_exists(LEGACY_CONFIG_PATH)) {
        $legacyJson = @file_get_contents(LEGACY_CONFIG_PATH);
        if ($legacyJson === false && file_exists(CONFIG_PATH)) {
            // Another process migrated it between our checks
            return readConfigFile();
        }
        $legacy = $legacyJson === false ? null : json_decode($legacyJson, true);
        if ($busy || $migrationAttempted || !is_array($legacy)) {
            return $legacy;
        }

        $busy = true;
        $migrationAttempted = true;
        $result = migrateLegacyConfig($legacy);
        $busy = false;

        // Log only once the migration is finished (logMessage() calls loadConfig())
        if ($result === 'ok') {
            logMessage('Migrated config/config.json to guarded config/config.php', 'INFO');
        } elseif ($result === 'unlink_failed') {
            logMessage('Migrated config to config/config.php but could not delete config/config.json - delete it manually', 'ERROR');
        } else {
            logMessage('Config migration to config/config.php failed (' . $result . ') - still using config/config.json', 'ERROR');
        }
        return $legacy;
    }

    // No config yet: seed from the default template
    if (!file_exists(DEFAULT_CONFIG_PATH)) {
        return null;
    }
    $defaultJson = @file_get_contents(DEFAULT_CONFIG_PATH);
    $default = $defaultJson === false ? null : json_decode($defaultJson, true);
    if (!is_array($default)) {
        return null;
    }
    if ($busy) {
        return $default;
    }

    $busy = true;
    $seeded = false;
    $json = json_encode($default, JSON_PRETTY_PRINT | JSON_HEX_TAG);
    // Re-check right before seeding; createGuardedFile() never overwrites anyway
    if ($json !== false && !file_exists(CONFIG_PATH)) {
        $seeded = createGuardedFile(CONFIG_PATH, $json);
    }
    $busy = false;

    if (file_exists(CONFIG_PATH)) {
        // Ours, or one another process created first
        return readConfigFile();
    }
    if (!$seeded) {
        logMessage('Could not create config/config.php from config/config.default.json', 'ERROR');
    }
    return $default;
}

/**
 * Move legacy config/config.json into guarded config/config.php.
 * Returns 'ok', 'unlink_failed', 'encode_failed', 'write_failed' or 'verify_failed'.
 */
function migrateLegacyConfig(array $legacy): string {
    // Same flags as saveConfig(): JSON_HEX_TAG keeps "<?" out of the guarded file
    $json = json_encode($legacy, JSON_PRETTY_PRINT | JSON_HEX_TAG);
    if ($json === false) {
        return 'encode_failed';
    }

    // Keep the legacy permissions, but always readable by both web and cron users
    $mode = @fileperms(LEGACY_CONFIG_PATH);
    $mode = $mode === false ? null : (($mode & 0777) | 0644);

    if (!writeGuardedFile(CONFIG_PATH, $json, $mode)) {
        return 'write_failed';
    }

    // Running as root (e.g. a root cron task): hand the file to the legacy owner,
    // so the web user isn't locked out of its own config
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $owner = @fileowner(LEGACY_CONFIG_PATH);
        $group = @filegroup(LEGACY_CONFIG_PATH);
        if ($owner !== false) {
            @chown(CONFIG_PATH, $owner);
        }
        if ($group !== false) {
            @chgrp(CONFIG_PATH, $group);
        }
    }

    $written = readGuardedFile(CONFIG_PATH);
    if ($written === null || json_decode($written, true) !== $legacy) {
        @unlink(CONFIG_PATH);
        return 'verify_failed';
    }

    if (!@unlink(LEGACY_CONFIG_PATH) && file_exists(LEGACY_CONFIG_PATH)) {
        return 'unlink_failed';
    }
    return 'ok';
}

/**
 * Save configuration (guarded JSON in config/config.php)
 */
function saveConfig($config) {
    // JSON_HEX_TAG keeps "<?" out of the guarded file so it always parses as PHP
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_HEX_TAG);
    if ($json === false) {
        return false;
    }
    return writeGuardedFile(CONFIG_PATH, $json);
}

/**
 * Write a file atomically: write to a temp file in the same directory, then
 * rename() it over the target so readers never see a partially written file.
 *
 * Permissions: $mode if given, else the existing target's permissions, else 0644.
 */
function writeFileAtomic(string $path, string $data, ?int $mode = null): bool {
    $dir = dirname($path);
    $tmpPath = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (@file_put_contents($tmpPath, $data) === false) {
        @unlink($tmpPath);
        logMessage('Atomic write failed: could not write temp file for ' . basename($path), 'ERROR');
        return false;
    }

    if ($mode === null) {
        $mode = file_exists($path) ? (fileperms($path) & 0777) : 0644;
    }

    if (!@chmod($tmpPath, $mode)) {
        @unlink($tmpPath);
        logMessage('Atomic write failed: could not set permissions for ' . basename($path), 'ERROR');
        return false;
    }

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        logMessage('Atomic write failed: could not replace ' . basename($path), 'ERROR');
        return false;
    }

    return true;
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
    
    // Redact secrets, and neutralise "<?" so the guarded .php log always parses
    $message = str_replace('<?', '<\\?', redactSecrets((string)$message));

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";

    if (!file_exists(LOG_FILE)) {
        // Create with the guard line; 0666 so both cron (possibly root) and the web user can append
        $fh = @fopen(LOG_FILE, 'x');
        if ($fh !== false) {
            flock($fh, LOCK_EX);
            fwrite($fh, GUARD_LINE);
            flock($fh, LOCK_UN);
            fclose($fh);
            @chmod(LOG_FILE, 0666);
        }
    }
    @file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Replace Synology session IDs and passwords in a string with [redacted].
 * Covers query-string form (_sid=..., passwd=...) and JSON form ("_sid":"...").
 */
function redactSecrets(string $s): string {
    $out = preg_replace('/(_sid|sid|passwd|password)=[^&\s"\']+/i', '$1=[redacted]', $s);
    if ($out === null) {
        return '[redaction failed]';
    }
    $out = preg_replace('/"(_sid|sid|passwd|password)"\s*:\s*"[^"]*"/i', '"$1":"[redacted]"', $out);
    return $out === null ? '[redaction failed]' : $out;
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
        // Use relative redirect within admin directory
        header('Location: login.php');
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
    if ($json === false) {
        return false;
    }
    return writeFileAtomic($cacheFile, $json);
}

/**
 * Get a version string for the cached photos (changes whenever photos.json changes).
 * Returns '' if the cache file doesn't exist.
 */
function getCachedPhotosVersion(): string {
    $cacheFile = CACHE_PATH . '/photos.json';
    if (!file_exists($cacheFile)) {
        return '';
    }
    $hash = @md5_file($cacheFile);
    return $hash === false ? '' : $hash;
}

/**
 * Get the cached image filenames referenced by photos.json.
 */
function getCachedPhotoFilenames(): array {
    $filenames = [];
    foreach (getCachedPhotos() as $photo) {
        if (is_array($photo) && isset($photo['cached_filename']) && is_string($photo['cached_filename'])) {
            $filenames[] = $photo['cached_filename'];
        }
    }
    return $filenames;
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
 * Get (lazily creating) the session CSRF token
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden form field carrying the CSRF token
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

/**
 * Check the POSTed CSRF token against the session token
 */
function verifyCsrf(): bool {
    $sent = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($expected) || $expected === '') {
        return false;
    }
    return hash_equals($expected, $sent);
}

/**
 * Replace the CSRF token (e.g. after login)
 */
function rotateCsrfToken(): void {
    unset($_SESSION['csrf_token']);
    csrfToken();
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

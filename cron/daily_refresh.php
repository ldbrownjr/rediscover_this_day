<?php
/**
 * Daily Photo Refresh Script
 * This script fetches photos from Synology Photos for the current calendar date
 * and caches them locally for the slideshow.
 * 
 * Can be run manually or via cron job.
 */

// Allow running from command line or web
if (php_sapi_name() === 'cli') {
    // Running from command line
    define('BASE_PATH', dirname(__DIR__));
} else {
    // Running from web (for manual refresh)
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/common.php';
require_once BASE_PATH . '/api/synology.php';

/**
 * Perform the daily refresh
 */
function performDailyRefresh() {
    $startTime = microtime(true);
    
    logMessage('Starting daily photo refresh', 'INFO');
    
    // Load configuration
    $config = loadConfig();
    
    if (!$config) {
        logMessage('Failed to load configuration', 'ERROR');
        return ['success' => false, 'error' => 'Failed to load configuration'];
    }
    
    // Validate configuration
    if (empty($config['synology']['url']) || 
        empty($config['synology']['username']) || 
        empty($config['synology']['password'])) {
        logMessage('Synology configuration incomplete', 'ERROR');
        return ['success' => false, 'error' => 'Synology configuration incomplete'];
    }
    
    // Initialize API
    $api = new SynologyAPI(
        $config['synology']['url'],
        $config['synology']['username'],
        $config['synology']['password']
    );
    
    // Get current month and day
    $date = getCurrentMonthDay();
    $month = $date['month'];
    $day = $date['day'];
    
    logMessage("Searching for photos from {$month}/{$day}", 'INFO');
    
    // Search for photos
    $firstYear = $config['photos']['first_year'];
    $photos = $api->searchPhotosByDate($month, $day, $firstYear);
    
    if ($photos === false) {
        logMessage('Failed to fetch photos from Synology', 'ERROR');
        $api->logout();
        return ['success' => false, 'error' => 'Failed to connect to Synology'];
    }
    
    $photoCount = count($photos);
    logMessage("Found {$photoCount} photos", 'INFO');
    
    // Process and cache photos
    $cachedPhotos = [];
    $downloadCount = 0;
    
    foreach ($photos as $photo) {
        // Extract cache key from thumbnail data
        $cacheKey = isset($photo['additional']['thumbnail']['cache_key']) 
            ? $photo['additional']['thumbnail']['cache_key'] 
            : null;
        
        if (!$cacheKey) {
            logMessage("Photo {$photo['id']} has no cache key, skipping", 'WARNING');
            continue;
        }
        
        // Download thumbnail (xl size for better quality)
        $cachedFilename = $api->downloadThumbnail($photo['id'], $cacheKey, 'xl');
        
        if ($cachedFilename) {
            $downloadCount++;
            
            // Extract location if available (from EXIF or metadata)
            $location = null;
            // Note: Location extraction would require additional API calls or EXIF data
            // For now, we'll leave it as null
            
            // Store photo metadata
            $cachedPhotos[] = [
                'id' => $photo['id'],
                'filename' => $photo['filename'],
                'time' => $photo['time'],
                'year' => date('Y', $photo['time']),
                'cached_filename' => $cachedFilename,
                'location' => $location
            ];
        } else {
            logMessage("Failed to download photo {$photo['id']}", 'WARNING');
        }
    }
    
    // Logout from API
    $api->logout();
    
    // Save cached photos metadata
    if (saveCachedPhotos($cachedPhotos)) {
        $duration = round(microtime(true) - $startTime, 2);
        logMessage("Successfully cached {$downloadCount} of {$photoCount} photos in {$duration}s", 'INFO');
        
        return [
            'success' => true,
            'count' => $downloadCount,
            'total' => $photoCount,
            'duration' => $duration
        ];
    } else {
        logMessage('Failed to save cached photos metadata', 'ERROR');
        return ['success' => false, 'error' => 'Failed to save cache'];
    }
}

/**
 * Clean old cached images (optional cleanup)
 */
function cleanOldCache() {
    $cacheDir = CACHE_PATH;
    $files = glob($cacheDir . '/*.jpg');
    
    $deleted = 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            $deleted++;
        }
    }
    
    logMessage("Cleaned {$deleted} old cached images", 'INFO');
}

// Main execution
if (php_sapi_name() === 'cli' || (isset($_SERVER['REQUEST_METHOD']) && isset($_GET['run']))) {
    // Clean old cache before refreshing
    cleanOldCache();
    
    // Perform refresh
    $result = performDailyRefresh();
    
    // Output result
    if (php_sapi_name() === 'cli') {
        echo "Daily refresh completed\n";
        echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
        
        if ($result['success']) {
            echo "Photos cached: {$result['count']} of {$result['total']}\n";
            echo "Duration: {$result['duration']}s\n";
        } else {
            echo "Error: {$result['error']}\n";
        }
        
        exit($result['success'] ? 0 : 1);
    }
}

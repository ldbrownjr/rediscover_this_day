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
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', dirname(__DIR__));
    }
} else {
    // Running from web (for manual refresh)
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', dirname(__DIR__));
    }
}

require_once BASE_PATH . '/common.php';
require_once BASE_PATH . '/api/synology.php';

/**
 * Perform the daily refresh
 */
function performDailyRefresh() {
    $startTime = microtime(true);
    
    logMessage('========================================', 'INFO');
    logMessage('Starting daily photo refresh', 'INFO');
    logMessage('Script running in: ' . (php_sapi_name() === 'cli' ? 'CLI' : 'WEB') . ' mode', 'INFO');
    
    // Load configuration
    logMessage('Loading configuration from: ' . CONFIG_PATH, 'INFO');
    $config = loadConfig();
    
    if (!$config) {
        logMessage('Failed to load configuration', 'ERROR');
        return ['success' => false, 'error' => 'Failed to load configuration'];
    }
    logMessage('Configuration loaded successfully', 'INFO');
    
    // Validate configuration
    logMessage('Validating Synology configuration...', 'INFO');
    if (empty($config['synology']['url']) || 
        empty($config['synology']['username']) || 
        empty($config['synology']['password'])) {
        logMessage('Synology configuration incomplete - URL: ' . (!empty($config['synology']['url']) ? 'SET' : 'MISSING') . 
                   ', Username: ' . (!empty($config['synology']['username']) ? 'SET' : 'MISSING') . 
                   ', Password: ' . (!empty($config['synology']['password']) ? 'SET' : 'MISSING'), 'ERROR');
        return ['success' => false, 'error' => 'Synology configuration incomplete'];
    }
    logMessage('Synology configuration validated', 'INFO');
    
    // Initialize API
    logMessage('Initializing Synology API connection to: ' . $config['synology']['url'], 'INFO');
    $api = new SynologyAPI(
        $config['synology']['url'],
        $config['synology']['username'],
        $config['synology']['password']
    );
    logMessage('SynologyAPI object created', 'INFO');
    
    // Get current month and day
    $date = getCurrentMonthDay();
    $month = $date['month'];
    $day = $date['day'];
    
    logMessage("Target date: {$month}/{$day} (searching across all years from {$config['photos']['first_year']} to " . date('Y') . ")", 'INFO');
    
    // Search for photos
    $firstYear = $config['photos']['first_year'];
    logMessage("Calling searchPhotosByDate(month={$month}, day={$day}, firstYear={$firstYear})", 'INFO');
    $photos = $api->searchPhotosByDate($month, $day, $firstYear);
    
    if ($photos === false) {
        logMessage('searchPhotosByDate returned FALSE - connection or API error', 'ERROR');
        $api->logout();
        return ['success' => false, 'error' => 'Failed to connect to Synology'];
    }
    
    $photoCount = count($photos);
    logMessage("searchPhotosByDate completed - found {$photoCount} photos", 'INFO');
    
    if ($photoCount > 0) {
        logMessage('Sample photo data: ' . json_encode(array_slice($photos, 0, 1)), 'INFO');
    }
    
    // Process and cache photos
    $cachedPhotos = [];
    $downloadCount = 0;
    
    logMessage("Processing {$photoCount} photos for caching...", 'INFO');
    
    foreach ($photos as $index => $photo) {
        logMessage("Processing photo " . ($index + 1) . "/{$photoCount}: ID={$photo['id']}, filename={$photo['filename']}", 'DEBUG');
        
        // Extract cache key from thumbnail data
        $cacheKey = isset($photo['additional']['thumbnail']['cache_key']) 
            ? $photo['additional']['thumbnail']['cache_key'] 
            : null;
        
        if (!$cacheKey) {
            logMessage("Photo {$photo['id']} has no cache key in additional data. Photo structure: " . json_encode($photo), 'WARNING');
            continue;
        }
        
        logMessage("Photo {$photo['id']} cache_key: {$cacheKey} - downloading thumbnail...", 'DEBUG');
        
        // Download thumbnail (xl size for better quality)
        $cachedFilename = $api->downloadThumbnail($photo['id'], $cacheKey, 'xl');
        
        if ($cachedFilename) {
            $downloadCount++;
            logMessage("Successfully downloaded photo {$photo['id']} as {$cachedFilename}", 'DEBUG');
            
            // Extract location data from address data if available
            $landmark = null;
            $route = null;
            $villageDistrictTownCity = null;
            $countyState = null;
            $country = null;
            if (isset($photo['additional']['address'])) {
                $address = $photo['additional']['address'];
                $villageDistrictTownCityParts = [];
                $countyStateParts = [];
                
                // Build location string from available address components
                if (!empty($address['landmark'])) {
                    $landmark[] = $address['landmark'];
                }
                if (!empty($address['route'])) {
                    $route = $address['route'];
                }     
                if (!empty($address['village'])) {
                    $villageDistrictTownCityParts[] = $address['village'];
                }
                if (!empty($address['district'])) {
                    $villageDistrictTownCityParts[] = $address['district'];
                }
                if (!empty($address['town'])) {
                    $villageDistrictTownCityParts[] = $address['town'];
                }
                if (!empty($address['city'])) {
                    $villageDistrictTownCityParts[] = $address['city'];
                }
                if (!empty($address['county'])) {
                    $countyStateParts[] = $address['county'];
                }
                if (!empty($address['state'])) {
                    $countyStateParts[] = $address['state'];
                }
                if (!empty($address['country']) && $address['country'] !== 'United States') {
                    $country = $address['country'];
                }
                if (!empty($villageDistrictTownCityParts)) {
                    $villageDistrictTownCity = implode(', ', $villageDistrictTownCityParts);
                }
                if (!empty($countyStateParts)) {
                    $countyState = implode(', ', $countyStateParts);
                }
            }
            
            // Store photo metadata
            $cachedPhotos[] = [
                'id' => $photo['id'],
                'filename' => $photo['filename'],
                'time' => $photo['time'],
                'year' => date('Y', $photo['time']),
                'cached_filename' => $cachedFilename,
                'landmark' => $landmark,
                'route' => $route,
                'village_district_town_city' => $villageDistrictTownCity,
                'county_state' => $countyState,
                'country' => $country
            ];
        } else {
            logMessage("Failed to download photo {$photo['id']} - downloadThumbnail returned null", 'WARNING');
        }
    }
    
    logMessage("Photo processing complete - successfully downloaded {$downloadCount} of {$photoCount} photos", 'INFO');
    
    // Logout from API
    logMessage('Logging out from Synology API', 'INFO');
    $api->logout();
    
    // Save cached photos metadata
    $cacheFile = CACHE_PATH . '/photos.json';
    logMessage("Saving photo metadata to: {$cacheFile}", 'INFO');
    logMessage("Metadata contains " . count($cachedPhotos) . " photo entries", 'INFO');
    
    if (saveCachedPhotos($cachedPhotos)) {
        $duration = round(microtime(true) - $startTime, 2);
        logMessage("Metadata saved successfully", 'INFO');
        logMessage("Successfully cached {$downloadCount} of {$photoCount} photos in {$duration}s", 'INFO');
        logMessage('========================================', 'INFO');
        
        return [
            'success' => true,
            'count' => $downloadCount,
            'total' => $photoCount,
            'duration' => $duration
        ];
    } else {
        logMessage('Failed to save cached photos metadata to: ' . $cacheFile, 'ERROR');
        logMessage('Check file permissions on cache directory: ' . CACHE_PATH, 'ERROR');
        return ['success' => false, 'error' => 'Failed to save cache'];
    }
}

/**
 * Clean old cached images (optional cleanup)
 */
function cleanOldCache() {
    $cacheDir = CACHE_PATH;
    logMessage('Cleaning old cache from: ' . $cacheDir, 'INFO');
    
    $files = glob($cacheDir . '/*.jpg');
    logMessage('Found ' . count($files) . ' cached image files to clean', 'INFO');
    
    $deleted = 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            if (unlink($file)) {
                $deleted++;
            } else {
                logMessage("Failed to delete file: {$file}", 'WARNING');
            }
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

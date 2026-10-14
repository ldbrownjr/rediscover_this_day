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
function performDailyRefresh(): array {
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
    
    $api = null;
    try {
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
        
        // Search for photos (throws on login failure; per-year failures are reported in failed_years)
        $firstYear = $config['photos']['first_year'];
        logMessage("Calling searchPhotosByDate(month={$month}, day={$day}, firstYear={$firstYear})", 'INFO');
        $searchResult = $api->searchPhotosByDate($month, $day, $firstYear);
        $photos = $searchResult['photos'];
        $failedYears = $searchResult['failed_years'];
        
        $photoCount = count($photos);
        logMessage("searchPhotosByDate completed - found {$photoCount} photos", 'INFO');
        
        if (!empty($failedYears)) {
            logMessage(count($failedYears) . ' of ' . $searchResult['years_queried'] . ' year(s) could not be queried: ' . implode(', ', $failedYears), 'WARNING');
            if ($photoCount === 0) {
                // Can't tell "no photos today" from "queries failed" - keep the previous cache
                logMessage('No photos found and some years failed - keeping previous cache', 'ERROR');
                return ['success' => false, 'error' => 'Some years could not be queried and no photos were found'];
            }
        }
        
        if ($photoCount > 0) {
            logMessage('Sample photo data: ' . json_encode(array_slice($photos, 0, 1)), 'INFO');
        }
        
        // Remember what the current cache references so it isn't deleted out from under
        // clients that haven't reloaded yet
        $previousFiles = getCachedPhotoFilenames();
        
        // Process and cache photos
        $cachedPhotos = [];
        $newFiles = [];
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

            // Extract cache key from thumbnail data
            $unit_id = isset($photo['additional']['thumbnail']['unit_id'])
                ? $photo['additional']['thumbnail']['unit_id']
                : null;

            logMessage("Photo {$photo['id']} Unit Id {$unit_id} cache_key: {$cacheKey} - downloading thumbnail...", 'DEBUG');
            
            // Download thumbnail (xl size for better quality)
            $cachedFilename = $api->downloadThumbnail($photo['id'], $cacheKey, 'xl', $unit_id);
            
            if ($cachedFilename) {
                $downloadCount++;
                $newFiles[] = $cachedFilename;
                logMessage("Successfully downloaded photo {$photo['id']} as {$cachedFilename}", 'DEBUG');
                
                // Extract location data from address data if available
                $landmark = null;
                $route = null;
                $villageDistrictTownCity = null;
                $county = null;
                $state = null;
                $country = null;
                if (isset($photo['additional']['address'])) {
                    $address = $photo['additional']['address'];
                    $villageDistrictTownCityParts = [];

                    // Build location string from available address components
                    if (!empty($address['landmark'])) {
                        $landmark = $address['landmark'];
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
                        $county = $address['county'];
                    }
                    if (!empty($address['state'])) {
                        $state = $address['state'];
                    }
                    if (!empty($address['country']) && $address['country'] !== 'United States') {
                        $country = $address['country'];
                    }
                    if (!empty($villageDistrictTownCityParts)) {
                        $villageDistrictTownCity = implode(', ', $villageDistrictTownCityParts);
                    }
                }

                // Extract tag data if available
                $tags = [];
                if (isset($photo['additional']['tag']) && is_array($photo['additional']['tag'])) {
                    foreach ($photo['additional']['tag'] as $tag) {
                        if (isset($tag['name']) && !empty($tag['name'])) {
                            $tags[] = $tag['name'];
                        }
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
                    'county' => $county,
                    'state' => $state,
                    'country' => $country,
                    'tags' => $tags
                ];
            } else {
                logMessage("Failed to download photo {$photo['id']} - downloadThumbnail returned null", 'WARNING');
            }
        }
        
        logMessage("Photo processing complete - successfully downloaded {$downloadCount} of {$photoCount} photos", 'INFO');
        
        if ($photoCount > 0 && $downloadCount === 0) {
            logMessage('Photos were found but none could be downloaded - keeping previous cache', 'ERROR');
            return ['success' => false, 'error' => 'No photos could be downloaded'];
        }
        
        $partial = !empty($failedYears) || $downloadCount < $photoCount;
        if ($partial) {
            logMessage("Partial refresh: {$downloadCount} of {$photoCount} photos cached, " . count($failedYears) . ' year(s) failed', 'WARNING');
        }
        
        // Save cached photos metadata
        $cacheFile = CACHE_PATH . '/photos.json';
        logMessage("Saving photo metadata to: {$cacheFile}", 'INFO');
        logMessage("Metadata contains " . count($cachedPhotos) . " photo entries", 'INFO');
        
        if (!saveCachedPhotos($cachedPhotos)) {
            logMessage('Failed to save cached photos metadata to: ' . $cacheFile, 'ERROR');
            logMessage('Check file permissions on cache directory: ' . CACHE_PATH, 'ERROR');
            return ['success' => false, 'error' => 'Failed to save cache'];
        }
        logMessage("Metadata saved successfully", 'INFO');
        
        // Only now that the new metadata is in place, remove images neither set references
        cleanOrphanedCache(array_merge($newFiles, $previousFiles));
        
        $duration = round(microtime(true) - $startTime, 2);
        logMessage("Successfully cached {$downloadCount} of {$photoCount} photos in {$duration}s", 'INFO');
        logMessage('========================================', 'INFO');
        
        return [
            'success' => true,
            'count' => $downloadCount,
            'total' => $photoCount,
            'duration' => $duration,
            'partial' => $partial,
            'failed_years' => $failedYears
        ];
    } catch (Throwable $e) {
        logMessage('Daily refresh failed: ' . $e->getMessage(), 'ERROR');
        return ['success' => false, 'error' => 'Refresh failed'];
    } finally {
        if ($api) {
            logMessage('Logging out from Synology API', 'INFO');
            $api->logout();
        }
    }
}

/**
 * Delete cached images in CACHE_PATH that aren't in $keepFilenames.
 * Returns the number of files deleted.
 */
function cleanOrphanedCache(array $keepFilenames): int {
    $keep = array_flip($keepFilenames);
    $files = glob(CACHE_PATH . '/*.jpg');
    if ($files === false) {
        $files = [];
    }
    
    $deleted = 0;
    foreach ($files as $file) {
        if (!is_file($file) || isset($keep[basename($file)])) {
            continue;
        }
        if (@unlink($file)) {
            $deleted++;
        } else {
            logMessage('Failed to delete orphaned cache file: ' . basename($file), 'WARNING');
        }
    }
    
    logMessage("Cleaned {$deleted} orphaned cached images (kept " . count($keep) . ')', 'INFO');
    return $deleted;
}

// Main execution
if (php_sapi_name() === 'cli' || (isset($_SERVER['REQUEST_METHOD']) && isset($_GET['run']))) {
    // Perform refresh (cleans orphaned images itself, after a successful save)
    $result = performDailyRefresh();
    
    // Output result
    if (php_sapi_name() === 'cli') {
        echo "Daily refresh completed\n";
        echo "Success: " . (!empty($result['success']) ? 'Yes' : 'No') . "\n";
        
        if (!empty($result['success'])) {
            echo 'Photos cached: ' . ($result['count'] ?? 0) . ' of ' . ($result['total'] ?? 0) . "\n";
            echo 'Duration: ' . ($result['duration'] ?? 0) . "s\n";
            if (!empty($result['partial'])) {
                $failedYears = $result['failed_years'] ?? [];
                echo 'Partial: yes (' . count($failedYears) . " year(s) could not be queried)\n";
            }
        } else {
            echo 'Error: ' . ($result['error'] ?? 'Unknown error') . "\n";
        }
        
        exit(!empty($result['success']) ? 0 : 1);
    }
}

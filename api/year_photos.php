<?php
/**
 * Year Photos API Endpoint
 * Returns photo metadata for a specific year with smart selection and statistics
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/synology.php';

header('Content-Type: application/json');

// Get year parameter
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;

if (!$year) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Year parameter required']);
    exit;
}

$config = loadConfig();

if (!$config) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Configuration error']);
    exit;
}

// Validate year range
$firstYear = $config['photos']['first_year'] ?? 2010;
$currentYear = (int)date('Y');

if ($year < $firstYear || $year > $currentYear) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid year']);
    exit;
}

try {
    // Initialize API
    $api = new SynologyAPI(
        $config['synology']['url'],
        $config['synology']['username'],
        $config['synology']['password']
    );

    // Fetch all photos for the year
    $allPhotos = $api->getPhotosForYear($year);

    if ($allPhotos === false) {
        throw new Exception('Failed to fetch photos from Synology');
    }

    // Calculate statistics from all photos
    $stats = calculateYearStats($allPhotos, $year);

    // Apply smart selection algorithm
    $targetCount = $config['year_review']['photos_per_year'] ?? 52;
    $selectedPhotos = selectYearHighlights($allPhotos, $targetCount);

    // Format photos for client consumption
    $formattedPhotos = [];
    foreach ($selectedPhotos as $photo) {
        // Extract metadata
        $cacheKey = isset($photo['additional']['thumbnail']['cache_key'])
            ? $photo['additional']['thumbnail']['cache_key']
            : null;

        if (!$cacheKey) {
            logMessage('Skipping photo ' . $photo['id'] . ' - no thumbnail cache key', 'WARNING');
            continue; // Skip photos without thumbnails
        }

        // Download and cache thumbnail to year_review subdirectory
        $unitId = isset($photo['additional']['thumbnail']['unit_id']) ? $photo['additional']['thumbnail']['unit_id'] : null;
        $cachedFilename = downloadYearReviewThumbnail($api, $photo['id'], $cacheKey, 'xl', $year, $unitId);

        if (!$cachedFilename) {
            $name = isset($photo['filename']) ? $photo['filename'] : 'unknown';
            logMessage('Failed to download thumbnail for photo ' . $photo['id'] . ' (' . $name . ')', 'WARNING');
            continue; // Skip photos that fail to download
        }

        logMessage("Cached filename for photo {$photo['id']}: {$cachedFilename}", 'DEBUG');

        // Extract location data
        $location = extractLocationData($photo);

        // Extract tags
        $tags = extractTags($photo);

        // Use same structure as regular slideshow (cache/photos.json)
        $formattedPhoto = [
            'id' => $photo['id'],
            'filename' => $photo['filename'],
            'time' => $photo['time'],  // Unix timestamp - JavaScript will convert this
            'year' => date('Y', $photo['time']),  // Keep for backwards compatibility
            'cached_filename' => $cachedFilename,
            'landmark' => $location['landmark'],
            'route' => $location['route'],
            'village_district_town_city' => $location['village_district_town_city'],
            'county' => $location['county'],
            'state' => $location['state'],
            'country' => $location['country'],
            'tags' => $tags
        ];

        // Debug log to verify data matching
        logMessage("Photo {$photo['id']}: {$photo['filename']} -> {$location['village_district_town_city']}, {$location['state']}", 'DEBUG');

        $formattedPhotos[] = $formattedPhoto;
    }

    $api->logout();

    echo json_encode([
        'success' => true,
        'year' => $year,
        'stats' => $stats,
        'photos' => $formattedPhotos
    ]);

} catch (Exception $e) {
    logMessage('Year photos API error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load photos for year ' . $year
    ]);
}

/**
 * Download thumbnail to year_review cache subdirectory
 *
 * @param SynologyAPI $api API instance
 * @param int $photoId Photo ID
 * @param string $cacheKey Cache key from API
 * @param string $size Thumbnail size (xl, m, sm)
 * @param int $year Year for cache organization
 * @return string|null Cached filename relative to cache/year_review/ or null on failure
 */
function downloadYearReviewThumbnail($api, $photoId, $cacheKey, $size = 'xl', $year = null, $unitId = null)
{
    $url = $api->getThumbnailUrl($photoId, $cacheKey, $size, $unitId);

    $idNote = $unitId ? " (unit {$unitId})" : '';
    logMessage("Downloading thumbnail for photo {$photoId}{$idNote} with cache_key {$cacheKey}", 'DEBUG');
    logMessage("Thumbnail URL: {$url}", 'DEBUG');

    try {
        // Create Guzzle client (same settings as SynologyAPI)
        $client = new \GuzzleHttp\Client([
            'timeout' => 60,
            'verify' => false,
            'allow_redirects' => true,
        ]);

        $response = $client->get($url);
        $imageData = $response->getBody()->getContents();

        if ($imageData) {
            // Include year in filename to prevent conflicts between different year queries
            $filename = ($year ? $year . '_' : '') . $photoId . '_' . $size . '.jpg';
            $filepath = CACHE_PATH . '/year_review/' . $filename;

            if (file_put_contents($filepath, $imageData)) {
                return 'year_review/' . $filename; // Return path relative to cache/
            }
        }
    } catch (\Exception $e) {
        logMessage("Year review thumbnail download error: " . $e->getMessage(), 'ERROR');
    }

    return null;
}

/**
 * Calculate statistics from year photos
 *
 * @param array $photos All photos from the year
 * @param int $year The year being analyzed
 * @return array Statistics array
 */
function calculateYearStats($photos, $year) {
    $stats = [
        'year' => $year,
        'total_photos' => count($photos),
        'unique_cities' => 0,
        'unique_states' => 0,
        'unique_countries' => 0,
        'most_photographed_location' => 'Unknown',
        'top_tags' => [],
        'monthly_breakdown' => array_fill(0, 12, 0),
        'most_active_month' => 'Unknown'
    ];

    if (empty($photos)) {
        return $stats;
    }

    // Track unique locations
    $cities = [];
    $states = [];
    $countries = [];
    $locationCounts = [];

    // Track tags
    $tagCounts = [];

    foreach ($photos as $photo) {
        // Extract location data
        $address = isset($photo['additional']['address']) ? $photo['additional']['address'] : null;

        if ($address) {
            // Cities
            $cityParts = [];
            foreach (['village', 'district', 'town', 'city'] as $key) {
                if (!empty($address[$key])) {
                    $cityParts[] = $address[$key];
                }
            }
            if (!empty($cityParts)) {
                $city = implode(', ', $cityParts);
                if (!in_array($city, $cities)) {
                    $cities[] = $city;
                }

                // Count for most photographed location
                $state = !empty($address['state']) ? $address['state'] : '';
                $locationKey = $city . ($state ? ', ' . $state : '');
                $locationCounts[$locationKey] = ($locationCounts[$locationKey] ?? 0) + 1;
            }

            // States
            if (!empty($address['state']) && !in_array($address['state'], $states)) {
                $states[] = $address['state'];
            }

            // Countries
            if (!empty($address['country']) && !in_array($address['country'], $countries)) {
                $countries[] = $address['country'];
            }
        }

        // Extract tags
        if (isset($photo['additional']['tag']) && is_array($photo['additional']['tag'])) {
            foreach ($photo['additional']['tag'] as $tag) {
                if (isset($tag['name']) && !empty($tag['name'])) {
                    $tagName = $tag['name'];
                    $tagCounts[$tagName] = ($tagCounts[$tagName] ?? 0) + 1;
                }
            }
        }

        // Monthly breakdown
        $month = (int)date('n', $photo['time']) - 1; // 0-indexed
        $stats['monthly_breakdown'][$month]++;
    }

    // Set location stats
    $stats['unique_cities'] = count($cities);
    $stats['unique_states'] = count($states);
    $stats['unique_countries'] = count($countries);
    $stats['states_list'] = $states;
    $stats['countries_list'] = $countries;

    // Find most photographed location
    if (!empty($locationCounts)) {
        arsort($locationCounts);
        $topLocation = key($locationCounts);
        $topCount = current($locationCounts);
        $stats['most_photographed_location'] = $topLocation . ' (' . $topCount . ' photos)';
    }

    // Find top tags
    if (!empty($tagCounts)) {
        arsort($tagCounts);
        $stats['top_tags'] = array_slice(array_keys($tagCounts), 0, 5);
    }

    // Find most active month
    $maxPhotos = max($stats['monthly_breakdown']);
    $mostActiveMonthIndex = array_search($maxPhotos, $stats['monthly_breakdown']);
    $monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                   'July', 'August', 'September', 'October', 'November', 'December'];
    $stats['most_active_month'] = $monthNames[$mostActiveMonthIndex] . ' (' . $maxPhotos . ' photos)';

    return $stats;
}

/**
 * Select highlight photos using even distribution algorithm
 *
 * @param array $photos All photos from the year (sorted by time)
 * @param int $targetCount Target number of photos to select (default: 52)
 * @return array Selected highlight photos
 */
function selectYearHighlights($photos, $targetCount = 52) {
    $totalPhotos = count($photos);

    // If fewer photos than target, return all
    if ($totalPhotos <= $targetCount) {
        return $photos;
    }

    $selected = [];
    $interval = $totalPhotos / $targetCount;

    for ($i = 0; $i < $targetCount; $i++) {
        $index = (int)floor($i * $interval);
        // Ensure we don't exceed array bounds
        if ($index < $totalPhotos) {
            $selected[] = $photos[$index];
        }
    }

    return $selected;
}

/**
 * Extract location data from photo
 *
 * @param array $photo Photo object from API
 * @return array Location data
 */
function extractLocationData($photo) {
    $location = [
        'landmark' => null,
        'route' => null,
        'village_district_town_city' => null,
        'county' => null,
        'state' => null,
        'country' => null
    ];

    if (!isset($photo['additional']['address'])) {
        return $location;
    }

    $address = $photo['additional']['address'];

    if (!empty($address['landmark'])) {
        $location['landmark'] = $address['landmark'];
    }
    if (!empty($address['route'])) {
        $location['route'] = $address['route'];
    }

    // Build village/district/town/city
    $parts = [];
    foreach (['village', 'district', 'town', 'city'] as $key) {
        if (!empty($address[$key])) {
            $parts[] = $address[$key];
        }
    }
    if (!empty($parts)) {
        $location['village_district_town_city'] = implode(', ', $parts);
    }

    if (!empty($address['county'])) {
        $location['county'] = $address['county'];
    }
    if (!empty($address['state'])) {
        $location['state'] = $address['state'];
    }
    if (!empty($address['country']) && $address['country'] !== 'United States') {
        $location['country'] = $address['country'];
    }

    return $location;
}

/**
 * Extract tags from photo
 *
 * @param array $photo Photo object from API
 * @return array Tags array
 */
function extractTags($photo) {
    $tags = [];

    if (isset($photo['additional']['tag']) && is_array($photo['additional']['tag'])) {
        foreach ($photo['additional']['tag'] as $tag) {
            if (isset($tag['name']) && !empty($tag['name'])) {
                $tags[] = $tag['name'];
            }
        }
    }

    return $tags;
}

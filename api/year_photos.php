<?php
/**
 * Year Photos API Endpoint
 * Returns photo metadata for a specific year with smart selection and statistics
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/synology.php';

header('Content-Type: application/json');

// Increase execution time for processing many locations
set_time_limit(300);

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

    logMessage('Starting year photos API request for year ' . $year, 'DEBUG');

    // Fetch all photos for the year
    $allPhotos = $api->getPhotosForYear($year);

    logMessage('Fetched ' . count($allPhotos) . ' total photos for year ' . $year, 'DEBUG');

    if ($allPhotos === false || empty($allPhotos)) {
        throw new Exception('No photos found for year ' . $year);
    }

    // Group photos by month first for better geographic distribution
    $photosByMonth = [];
    foreach ($allPhotos as $photo) {
        $month = (int) date('n', $photo['time']);
        if (!isset($photosByMonth[$month])) {
            $photosByMonth[$month] = [];
        }
        $photosByMonth[$month][] = $photo;
    }

    logMessage('Processing ' . count($photosByMonth) . ' months', 'DEBUG');

    // Group by month with summaries and location slides
    $monthsData = groupPhotosByMonth($allPhotos, $photosByMonth, $api, $year);

    logMessage('Completed grouping. Generated ' . count($monthsData['months']) . ' months with location slides', 'DEBUG');

    $api->logout();

    echo json_encode([
        'success' => true,
        'year' => $year,
        'year_summary' => $monthsData['year_summary'],
        'months' => $monthsData['months']
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
 * Group photos by month with summaries and location slides
 *
 * @param array $allPhotos All photos from the year
 * @param array $photosByMonth Photos grouped by month number
 * @param SynologyAPI $api API instance for downloading thumbnails
 * @param int $year Year for cache organization
 * @return array Months data with year summary and month slides
 */
function groupPhotosByMonth($allPhotos, $photosByMonth, $api, $year)
{
    $monthNames = [
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December'
    ];

    // Calculate year-level summary from ALL photos
    $yearCountries = [];
    $yearStates = [];
    $yearCities = [];

    foreach ($allPhotos as $photo) {
        $address = isset($photo['additional']['address']) ? $photo['additional']['address'] : null;
        if ($address) {
            if (!empty($address['country'])) {
                $yearCountries[$address['country']] = true;
            }
            if (!empty($address['state'])) {
                $yearStates[$address['state']] = true;
            }
            $cityParts = [];
            foreach (['village', 'district', 'town', 'city'] as $key) {
                if (!empty($address[$key])) {
                    $cityParts[] = $address[$key];
                }
            }
            if (!empty($cityParts)) {
                $yearCities[implode(', ', $cityParts)] = true;
            }
        }
    }

    $yearSummary = [
        'total_photos' => count($allPhotos),
        'countries' => count($yearCountries),
        'states' => count($yearStates),
        'cities' => count($yearCities)
    ];

    // Process each month
    $months = [];

    foreach ($photosByMonth as $monthNum => $monthPhotos) {
        logMessage('Processing month ' . $monthNames[$monthNum - 1] . ' with ' . count($monthPhotos) . ' photos', 'DEBUG');

        // Calculate month summary from ALL photos in this month
        $monthCountries = [];
        $monthStates = [];
        $monthCities = [];
        $monthLocations = []; // Track unique location combinations

        foreach ($monthPhotos as $photo) {
            $address = isset($photo['additional']['address']) ? $photo['additional']['address'] : null;
            if ($address) {
                $country = !empty($address['country']) ? $address['country'] : 'Unknown Country';
                $state = !empty($address['state']) ? $address['state'] : 'Unknown State';

                if (!empty($address['country'])) {
                    $monthCountries[$address['country']] = true;
                }
                if (!empty($address['state'])) {
                    $monthStates[$address['state']] = true;
                }

                $cityParts = [];
                foreach (['village', 'district', 'town', 'city'] as $key) {
                    if (!empty($address[$key])) {
                        $cityParts[] = $address[$key];
                    }
                }
                if (!empty($cityParts)) {
                    $city = implode(', ', $cityParts);
                    $monthCities[$city] = true;

                    // Track full location combination
                    $locationKey = $country . '|' . $state . '|' . $city;
                    if (!isset($monthLocations[$locationKey])) {
                        $monthLocations[$locationKey] = [
                            'country' => $country,
                            'state' => $state,
                            'city' => $city
                        ];
                    }
                }
            }
        }

        // Build locations list for display
        $locationsList = array_values($monthLocations);
        usort($locationsList, function ($a, $b) {
            if ($a['country'] !== $b['country']) {
                return strcmp($a['country'], $b['country']);
            }
            if ($a['state'] !== $b['state']) {
                return strcmp($a['state'], $b['state']);
            }
            return strcmp($a['city'], $b['city']);
        });

        $monthSummary = [
            'month_number' => $monthNum,
            'month_name' => $monthNames[$monthNum - 1],
            'total_photos' => count($monthPhotos),
            'countries' => count($monthCountries),
            'states' => count($monthStates),
            'cities' => count($monthCities),
            'locations' => $locationsList
        ];

        // Group ALL photos in this month by location
        $locationGroups = [];

        foreach ($monthPhotos as $photo) {
            $address = isset($photo['additional']['address']) ? $photo['additional']['address'] : null;
            $country = !empty($address['country']) ? $address['country'] : 'Unknown Country';
            $state = !empty($address['state']) ? $address['state'] : 'Unknown State';

            $cityParts = [];
            if ($address) {
                foreach (['village', 'district', 'town', 'city'] as $key) {
                    if (!empty($address[$key])) {
                        $cityParts[] = $address[$key];
                    }
                }
            }
            $city = !empty($cityParts) ? implode(', ', $cityParts) : 'Unknown City';

            $key = $country . '|' . $state . '|' . $city;

            if (!isset($locationGroups[$key])) {
                $locationGroups[$key] = [
                    'country' => $country,
                    'state' => $state,
                    'city' => $city,
                    'photos' => []
                ];
            }

            $locationGroups[$key]['photos'][] = $photo;
        }

        // Sort locations alphabetically
        ksort($locationGroups);

        logMessage('Month ' . $monthNames[$monthNum - 1] . ' has ' . count($locationGroups) . ' unique locations', 'DEBUG');

        // Build location slides - select up to 9 photos per location
        $locationSlides = [];

        foreach ($locationGroups as $key => $group) {
            logMessage('Processing location: ' . $group['city'] . ', ' . $group['state'] . ', ' . $group['country'] . ' with ' . count($group['photos']) . ' photos', 'DEBUG');

            // Select up to 9 photos from this location
            $photosToShow = selectYearHighlights($group['photos'], 9);
            $slidePhotos = [];

            foreach ($photosToShow as $photo) {
                $cacheKey = isset($photo['additional']['thumbnail']['cache_key'])
                    ? $photo['additional']['thumbnail']['cache_key']
                    : null;

                if (!$cacheKey) {
                    logMessage('Skipping photo ' . $photo['id'] . ' - no thumbnail cache key', 'WARNING');
                    continue;
                }

                $unitId = isset($photo['additional']['thumbnail']['unit_id'])
                    ? $photo['additional']['thumbnail']['unit_id']
                    : null;
                $cachedFilename = downloadYearReviewThumbnail($api, $photo['id'], $cacheKey, 'xl', $year, $unitId);

                if (!$cachedFilename) {
                    $name = isset($photo['filename']) ? $photo['filename'] : 'unknown';
                    logMessage('Failed to download thumbnail for photo ' . $photo['id'] . ' (' . $name . ')', 'WARNING');
                    continue;
                }

                $location = extractLocationData($photo);
                $tags = extractTags($photo);

                // Determine orientation from resolution
                $orientation = 'square';
                if (isset($photo['additional']['resolution'])) {
                    $width = $photo['additional']['resolution']['width'];
                    $height = $photo['additional']['resolution']['height'];
                    if ($width > $height * 1.1) {
                        $orientation = 'landscape';
                    } else if ($height > $width * 1.1) {
                        $orientation = 'portrait';
                    }
                }

                $slidePhotos[] = [
                    'id' => $photo['id'],
                    'filename' => $photo['filename'],
                    'time' => $photo['time'],
                    'cached_filename' => $cachedFilename,
                    'landmark' => $location['landmark'],
                    'route' => $location['route'],
                    'tags' => $tags,
                    'orientation' => $orientation
                ];
            }

            if (!empty($slidePhotos)) {
                // Create one slide per location with up to 9 photos
                $locationSlides[] = [
                    'country' => $group['country'],
                    'state' => $group['state'],
                    'city' => $group['city'],
                    'photo_count' => count($slidePhotos),
                    'photos' => $slidePhotos,
                    'page' => 1,
                    'total_pages' => 1
                ];
                logMessage('Added location slide: ' . $group['city'] . ' with ' . count($slidePhotos) . ' photos', 'DEBUG');
            }
        }

        // Only add month if it has location slides
        if (!empty($locationSlides)) {
            $months[] = [
                'summary' => $monthSummary,
                'location_slides' => $locationSlides
            ];
        }
    }

    return [
        'year_summary' => $yearSummary,
        'months' => $months
    ];
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
    // Always download fresh (cache busting enabled)
    $filename = ($year ? $year . '_' : '') . $photoId . '_' . $size . '.jpg';
    $filepath = CACHE_PATH . '/year_review/' . $filename;

    // Commented out cache check to always download fresh
    // if (file_exists($filepath)) {
    //     logMessage("Using cached thumbnail for photo {$photoId}", 'DEBUG');
    //     return 'year_review/' . $filename;
    // }

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

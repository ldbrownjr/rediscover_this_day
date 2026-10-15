<?php
/**
 * Year in Review slide list (JSON): GET api/year_photos.php?year=YYYY
 *
 * Queries the year's photos from Synology and groups them into a year summary,
 * month summaries and location slides (up to 9 photos each). The year summary
 * has a "cover" photo ({id, w, h, src} at size xl, or null) and each month
 * summary has "photos" (up to 3 {id, w, h, src} at size m). No thumbnails are
 * downloaded here: each photo's "src" is either the cached file or
 * api/year_thumb.php, which fetches it on demand.
 *
 * The list is cached in the guarded cache/year_review/{year}.cache.php:
 * permanently for past years (once built after the year ended), for the day
 * for the current year.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/synology.php';
require_once __DIR__ . '/year_review_lib.php';

$config = loadConfig();
session_write_close();

header('Content-Type: application/json');
header('Cache-Control: no-cache');
set_time_limit(120);

/**
 * Send a JSON response and stop.
 */
function sendYearJson(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Validate the year
$rawYear = $_GET['year'] ?? '';
$firstYear = (int)($config['photos']['first_year'] ?? 2010);
$currentYear = (int)date('Y');
if (!is_string($rawYear) || !ctype_digit($rawYear)
    || (int)$rawYear < $firstYear || (int)$rawYear > $currentYear) {
    sendYearJson(400, ['success' => false, 'error' => 'Invalid year']);
}
$year = (int)$rawYear;

if (!isYearReviewEnabled($config)) {
    sendYearJson(403, ['success' => false, 'disabled' => true, 'error' => 'Year in Review is turned off']);
}

if (!$config) {
    logMessage('Year photos API error: configuration could not be loaded', 'ERROR');
    sendYearJson(500, ['success' => false, 'error' => 'Failed to load photos']);
}

// Cached list
$cache = readYearCache($year, true);
if ($cache !== null) {
    $data = withYearPhotoSrc($cache['data'], $year);
    sendYearJson(200, [
        'success' => true,
        'year' => $year,
        'cached' => true,
        'year_summary' => $data['year_summary'] ?? null,
        'months' => $data['months'] ?? []
    ]);
}

// Build it from Synology
$api = null;
$built = null;
try {
    $api = new SynologyAPI(
        $config['synology']['url'] ?? '',
        $config['synology']['username'] ?? '',
        $config['synology']['password'] ?? ''
    );
    if (!$api->login()) {
        throw new Exception('Synology login failed');
    }
    $built = buildYearData($api, $year);

    if (!writeYearCache($year, $built)) {
        logMessage('Year in Review: could not save the list for ' . $year, 'WARNING');
    }
} catch (Exception $e) {
    logMessage('Year photos API error for ' . $year . ': ' . redactSecrets($e->getMessage()), 'ERROR');
    $built = null;
} finally {
    if ($api) {
        $api->logout();
    }
}

if ($built === null) {
    sendYearJson(500, ['success' => false, 'error' => 'Failed to load photos']);
}

$data = withYearPhotoSrc($built['data'], $year);
sendYearJson(200, [
    'success' => true,
    'year' => $year,
    'cached' => false,
    'year_summary' => $data['year_summary'],
    'months' => $data['months']
]);

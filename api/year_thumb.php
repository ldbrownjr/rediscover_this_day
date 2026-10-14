<?php
/**
 * Year in Review thumbnail (IMAGE endpoint, not JSON):
 *   GET api/year_thumb.php?year=YYYY&id=N
 *
 * Serves cache/year_review/{year}_{id}_xl.jpg. If it isn't cached yet, looks up
 * the photo's cache_key/unit_id in the year's list file (the allow-list: only
 * photos selected into slides), fetches it from Synology with the shared sid,
 * saves it atomically and streams it.
 *
 * 200 image/jpeg | 404 empty (bad input, unknown id, no list, feature off)
 * | 502 empty (Synology fetch failed).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/synology.php';
require_once __DIR__ . '/year_review_lib.php';

$config = loadConfig();
session_write_close();
set_time_limit(30);

/**
 * Empty response with a status code, then stop.
 */
function sendThumbStatus(int $status): void {
    http_response_code($status);
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache');
    exit;
}

/**
 * Stream JPEG bytes with cache headers, then stop.
 */
function sendThumbJpeg(string $bytes, int $mtime): void {
    // Drop the session's no-cache headers so the browser can cache the image
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: public, max-age=604800');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    echo $bytes;
    exit;
}

// Validate input (raw strings only: arrays, signs, exponents etc. are rejected)
$rawYear = $_GET['year'] ?? null;
$rawId = $_GET['id'] ?? null;
$firstYear = (int)($config['photos']['first_year'] ?? 2010);
$currentYear = (int)date('Y');
if (!is_string($rawYear) || !ctype_digit($rawYear)
    || !is_string($rawId) || !ctype_digit($rawId) || strlen($rawId) > 10
    || (int)$rawYear < $firstYear || (int)$rawYear > $currentYear) {
    sendThumbStatus(404);
}
if (!$config || !isYearReviewEnabled($config)) {
    sendThumbStatus(404);
}
$year = (int)$rawYear;
$id = (int)$rawId;

// Already cached
$path = yearThumbPath($year, $id);
if (is_file($path) && filesize($path) > 0) {
    $bytes = @file_get_contents($path);
    if ($bytes !== false && $bytes !== '') {
        sendThumbJpeg($bytes, (int)@filemtime($path));
    }
}

// Only photos selected into this year's slides
$cache = readYearCache($year, false);
if ($cache === null || !isset($cache['thumbs'][(string)$id])) {
    sendThumbStatus(404);
}
$thumb = $cache['thumbs'][(string)$id];
$cacheKey = is_array($thumb) && is_string($thumb['k'] ?? null) ? $thumb['k'] : '';
$unitId = is_array($thumb) && is_numeric($thumb['u'] ?? null) ? (int)$thumb['u'] : null;
if ($cacheKey === '') {
    sendThumbStatus(404);
}

// Fresh logins that couldn't be saved as the shared sid are logged out here
// (inner finally: it runs before the 502 exit below, which would skip it)
$unsavedApis = [];
try {
    try {
        $api = sharedSynologyApi($config, $persisted);
        if (!$persisted) {
            $unsavedApis[] = $api;
        }
        try {
            $bytes = $api->fetchThumbnail($id, $cacheKey, YEAR_THUMB_SIZE, $unitId);
        } catch (Exception $e) {
            // Possibly an expired sid (Synology answers that with an HTML 404, like a
            // deleted photo): re-login and retry once, unless the sid is fresh
            $failedSid = $api->getSid();
            if (!shouldRetrySharedSid($failedSid, (int)$e->getCode(), $persisted)) {
                throw $e;
            }
            logMessage("Year in Review: thumbnail fetch failed ({$e->getCode()}), refreshing the shared session", 'DEBUG');
            $api = refreshSharedSid($config, $failedSid, $persisted);
            if (!$persisted) {
                $unsavedApis[] = $api;
            }
            $bytes = $api->fetchThumbnail($id, $cacheKey, YEAR_THUMB_SIZE, $unitId);
        }
    } finally {
        foreach ($unsavedApis as $unsaved) {
            $unsaved->logout();
        }
    }
} catch (Throwable $t) {
    logMessage("Year in Review: thumbnail {$year}/{$id} failed: " . redactSecrets($t->getMessage())
        . ($t->getCode() ? ' (code ' . (int)$t->getCode() . ')' : ''), 'WARNING');
    sendThumbStatus(502);
}

if (!writeFileAtomic($path, $bytes, 0644)) {
    logMessage("Year in Review: could not save thumbnail {$year}/{$id}", 'WARNING');
}
logMessage("Year in Review: fetched thumbnail {$year}/{$id}", 'DEBUG');
sendThumbJpeg($bytes, time());

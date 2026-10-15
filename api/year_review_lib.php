<?php
/**
 * Year in Review library: functions only, no top-level output.
 *
 * Shared by api/year_photos.php (slide list), api/year_thumb.php (on-demand
 * thumbnails), cron/daily_refresh.php (nightly cleanup) and admin/index.php
 * (Clear button). Requesting this file over HTTP just defines the functions
 * and returns an empty response.
 *
 * Per-year file cache/year_review/{year}.cache.php (guarded JSON):
 *   { "schema": 3, "year": 2019, "generated_at": <unix>,
 *     "data":   { "year_summary": { ..., "cover": {id, w, h} | null },
 *                 "months": [ { "summary": { ..., "photos": [ {id, w, h}, ... up to 3 ] },
 *                               "location_slides": [...] } ] },
 *     "thumbs": { "<id>": { "k": "<cache_key>", "u": <unit_id|null> } } }
 * "thumbs" lists ONLY the photos selected into slides; it is the allow-list
 * for api/year_thumb.php. The year cover and the month photos are always picked
 * from those photos. The cover is shown at size xl, month photos (and picker
 * tiles) at size m; location grids at xl. Older-schema files are rebuilt
 * (schema 3: w/h are additional.resolution as-is, which Synology already
 * reports display-oriented; schema 2 wrongly swapped them for EXIF 5-8).
 *
 * Shared Synology session config/synology_session.php (guarded JSON):
 *   { "sid": "...", "created": <unix> }
 * Used by api/year_thumb.php only. It is a live credential: never log it,
 * never move it out of config/. By design it is never logged out.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/synology.php';

define('YEAR_CACHE_SCHEMA', 3);
define('YEAR_THUMB_SIZE', 'xl'); // default size
define('YEAR_THUMB_SIZES', ['xl', 'm']); // every size that may be cached and served
define('YEAR_REVIEW_DIR', CACHE_PATH . '/year_review');
define('YEAR_PHOTOS_PER_SLIDE', 9);
define('SYNOLOGY_SESSION_PATH', BASE_PATH . '/config/synology_session.php');
define('SYNOLOGY_SESSION_LOCK', BASE_PATH . '/config/.synology_session.lock');
// Synology's thumbnail API answers an expired/invalid sid with a plain HTML 404,
// the same as for a deleted photo. So any failed fetch re-logs in once, but only
// if the stored sid is at least this old (seconds): a deleted photo then costs
// at most one login per 5 minutes.
define('SHARED_SID_RETRY_MIN_AGE', 300);

/**
 * Path of a year's guarded list file.
 */
function yearCachePath(int $year): string {
    return YEAR_REVIEW_DIR . "/{$year}.cache.php";
}

/**
 * Path of a cached thumbnail (built from ints and a size from YEAR_THUMB_SIZES).
 * Throws InvalidArgumentException for any other size.
 */
function yearThumbPath(int $year, int $id, string $size = YEAR_THUMB_SIZE): string {
    if (!in_array($size, YEAR_THUMB_SIZES, true)) {
        throw new InvalidArgumentException('Unknown thumbnail size');
    }
    return YEAR_REVIEW_DIR . "/{$year}_{$id}_{$size}.jpg";
}

/**
 * Relative URL of a thumbnail: the static cached file when it exists, else the
 * on-demand endpoint (with &size=m for the m size). Any change to these two
 * forms must also update isYearThumbSrc() and safeSrc() in js/year-slideshow.js.
 */
function yearThumbSrc(int $year, int $id, string $size): string {
    $path = yearThumbPath($year, $id, $size);
    if (is_file($path) && filesize($path) > 0) {
        return 'cache/year_review/' . basename($path);
    }
    return 'api/year_thumb.php?year=' . $year . '&id=' . $id . ($size === 'm' ? '&size=m' : '');
}

/**
 * Whether $src is one of the two forms yearThumbSrc() builds. Used where a src
 * lands inside CSS url(...), where h() alone can't stop a break-out.
 */
function isYearThumbSrc(string $src): bool {
    return preg_match('#^(cache/year_review/\d{4}_\d+_(xl|m)\.jpg|api/year_thumb\.php\?year=\d{4}&id=\d+(&size=(xl|m))?)$#D', $src) === 1;
}

/**
 * Read a year's list file. Returns null if it's missing, undecodable or the
 * wrong schema, or (when $requireFresh) no longer fresh:
 *  - past year: must have been generated after the year ended;
 *  - current year: must have been generated today.
 * $reason says why it returned null: 'missing', 'invalid' (unreadable, not
 * JSON, or bad fields at the current schema), 'schema' (an older/other schema,
 * expected after a schema bump) or 'stale'; null when the list is returned.
 */
function readYearCache(int $year, bool $requireFresh, ?string &$reason = null): ?array {
    $reason = null;
    $path = yearCachePath($year);
    if (!is_file($path)) {
        $reason = 'missing';
        return null;
    }
    $json = readGuardedFile($path);
    $cache = $json === null ? null : json_decode($json, true);
    if (!is_array($cache)) {
        $reason = 'invalid';
        return null;
    }
    if (($cache['schema'] ?? null) !== YEAR_CACHE_SCHEMA) {
        $reason = 'schema';
        return null;
    }
    if (($cache['year'] ?? null) !== $year
        || !is_int($cache['generated_at'] ?? null)
        || !is_array($cache['data'] ?? null)
        || !is_array($cache['thumbs'] ?? null)) {
        $reason = 'invalid';
        return null;
    }

    if ($requireFresh) {
        $generatedAt = $cache['generated_at'];
        $currentYear = (int)date('Y');
        if ($year < $currentYear) {
            if ($generatedAt < mktime(0, 0, 0, 1, 1, $year + 1)) {
                $reason = 'stale';
                return null;
            }
        } elseif (date('Y-m-d', $generatedAt) !== date('Y-m-d')) {
            $reason = 'stale';
            return null;
        }
    }

    return $cache;
}

/**
 * Write a year's list file (atomic, guarded). $built is buildYearData()'s
 * result: ['data' => [...], 'thumbs' => [...]].
 */
function writeYearCache(int $year, array $built): bool {
    $payload = [
        'schema' => YEAR_CACHE_SCHEMA,
        'year' => $year,
        'generated_at' => time(),
        'data' => $built['data'],
        // Always a JSON object, even if the ids happen to be 0..n-1
        'thumbs' => (object)$built['thumbs']
    ];
    // JSON_HEX_TAG keeps "<?" out of the guarded file (as saveConfig() does)
    $json = json_encode($payload, JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        logMessage('Year in Review: could not encode list for ' . $year, 'WARNING');
        return false;
    }
    return writeGuardedFile(yearCachePath($year), $json, 0644);
}

/**
 * Location parts from additional.address, in the order city, state, country.
 * Empty parts are omitted. city = non-empty village, district, town, city
 * joined with ", ".
 */
function buildLocationParts(?array $address): array {
    $parts = [];
    if (!$address) {
        return $parts;
    }

    $cityParts = [];
    foreach (['village', 'district', 'town', 'city'] as $key) {
        if (isset($address[$key]) && is_string($address[$key]) && trim($address[$key]) !== '') {
            $cityParts[] = trim($address[$key]);
        }
    }
    if (!empty($cityParts)) {
        $parts['city'] = implode(', ', $cityParts);
    }
    foreach (['state', 'country'] as $key) {
        if (isset($address[$key]) && is_string($address[$key]) && trim($address[$key]) !== '') {
            $parts[$key] = trim($address[$key]);
        }
    }
    return $parts;
}

/**
 * Slide title: city, state, country joined with ", " (country omitted when it's
 * "United States"). "Unknown location" when there are no parts.
 */
function buildLocationTitle(array $parts): string {
    $titleParts = [];
    if (isset($parts['city'])) {
        $titleParts[] = $parts['city'];
    }
    if (isset($parts['state'])) {
        $titleParts[] = $parts['state'];
    }
    if (isset($parts['country']) && $parts['country'] !== 'United States') {
        $titleParts[] = $parts['country'];
    }
    if (empty($titleParts)) {
        // Only "United States" (or nothing at all)
        return $parts['country'] ?? 'Unknown location';
    }
    return implode(', ', $titleParts);
}

/**
 * Compare two location part sets by country, then state, then city.
 */
function compareLocationParts(array $a, array $b): int {
    foreach (['country', 'state', 'city'] as $key) {
        $cmp = strcmp($a[$key] ?? '', $b[$key] ?? '');
        if ($cmp !== 0) {
            return $cmp;
        }
    }
    return 0;
}

/**
 * Select up to $targetCount photos spread evenly through the (time-sorted) list.
 */
function selectYearHighlights(array $photos, int $targetCount = 52): array {
    $totalPhotos = count($photos);
    if ($totalPhotos <= $targetCount) {
        return $photos;
    }

    $selected = [];
    $interval = $totalPhotos / $targetCount;
    for ($i = 0; $i < $targetCount; $i++) {
        $index = (int)floor($i * $interval);
        if ($index < $totalPhotos) {
            $selected[] = $photos[$index];
        }
    }
    return $selected;
}

/**
 * Display width/height from additional.resolution. Empty array when unknown.
 * Synology already reports resolution display-oriented (after EXIF rotation):
 * do NOT swap for additional.orientation 5-8. Checked live against the m
 * thumbnails for every orientation / orientation_original combination.
 */
function photoDisplaySize(array $photo): array {
    $res = $photo['additional']['resolution'] ?? null;
    if (!is_array($res) || !isset($res['width'], $res['height'])
        || !is_numeric($res['width']) || !is_numeric($res['height'])) {
        return [];
    }
    $w = (int)$res['width'];
    $h = (int)$res['height'];
    if ($w <= 0 || $h <= 0) {
        return [];
    }
    return ['w' => $w, 'h' => $h];
}

/**
 * Whether a selected photo (['id', 'time', 'w'?, 'h'?]) is a landscape picture
 * suitable for a cover or month strip: known size, 1.3 <= w/h <= 1.9.
 */
function isCoverShaped(array $photo): bool {
    if (!isset($photo['w'], $photo['h']) || $photo['h'] <= 0) {
        return false;
    }
    $ratio = $photo['w'] / $photo['h'];
    return $ratio >= 1.3 && $ratio <= 1.9;
}

/**
 * Median of the photos' times (mean of the two middle values for an even
 * count). Null for no photos.
 */
function medianPhotoTime(array $photos): ?float {
    if (empty($photos)) {
        return null;
    }
    $times = [];
    foreach ($photos as $photo) {
        $times[] = $photo['time'];
    }
    sort($times);
    $n = count($times);
    $mid = intdiv($n, 2);
    return $n % 2 ? (float)$times[$mid] : ($times[$mid - 1] + $times[$mid]) / 2;
}

/**
 * The photo whose time is closest to $medianTime (default: the photos' own
 * median time); ties go to the lower id. Null for no photos.
 */
function pickMedianTimePhoto(array $photos, ?float $medianTime = null): ?array {
    if ($medianTime === null) {
        $medianTime = medianPhotoTime($photos);
    }
    $best = null;
    $bestDist = null;
    foreach ($photos as $photo) {
        $dist = abs($photo['time'] - $medianTime);
        if ($best === null || $dist < $bestDist || ($dist == $bestDist && $photo['id'] < $best['id'])) {
            $best = $photo;
            $bestDist = $dist;
        }
    }
    return $best;
}

/**
 * Order group infos (['count', 'month', 'order', 'photos']) by source photo
 * count descending, then earlier month, then the slide order.
 */
function sortGroupsBySize(array $groups): array {
    usort($groups, function ($a, $b) {
        if ($a['count'] !== $b['count']) {
            return $b['count'] - $a['count'];
        }
        if ($a['month'] !== $b['month']) {
            return $a['month'] - $b['month'];
        }
        return $a['order'] - $b['order'];
    });
    return $groups;
}

/**
 * Year cover from the selected photos (deterministic):
 *  1. the median-time cover-shaped photo of the largest group that has one;
 *  2. else the landscape photo (w > h) closest to the median time of all
 *     selected photos;
 *  3. else the first selected photo; null if there are none.
 */
function pickYearCover(array $groups): ?array {
    foreach (sortGroupsBySize($groups) as $group) {
        $shaped = array_values(array_filter($group['photos'], 'isCoverShaped'));
        if (!empty($shaped)) {
            return pickMedianTimePhoto($shaped);
        }
    }

    $all = [];
    foreach ($groups as $group) {
        foreach ($group['photos'] as $photo) {
            $all[] = $photo;
        }
    }
    if (empty($all)) {
        return null;
    }
    $wide = array_values(array_filter($all, function ($p) {
        return isset($p['w'], $p['h']) && $p['w'] > $p['h'];
    }));
    if (!empty($wide)) {
        return pickMedianTimePhoto($wide, medianPhotoTime($all));
    }
    return $all[0];
}

/**
 * Up to 3 photos for a month summary strip (deterministic), groups taken
 * largest first:
 *  1. each group's median-time cover-shaped photo;
 *  2. the groups' other cover-shaped photos;
 *  3. any selected photo.
 * The year cover is skipped unless nothing else is left.
 */
function pickMonthPhotos(array $groups, ?int $coverId): array {
    $groups = sortGroupsBySize($groups);
    $picked = [];
    $add = function (array $photo) use (&$picked) {
        if (count($picked) < 3 && !isset($picked[$photo['id']])) {
            $picked[$photo['id']] = $photo;
        }
    };
    $notCover = function ($p) use ($coverId) {
        return $p['id'] !== $coverId;
    };

    foreach ($groups as $group) {
        $shaped = array_values(array_filter(array_filter($group['photos'], 'isCoverShaped'), $notCover));
        if (!empty($shaped)) {
            $add(pickMedianTimePhoto($shaped));
        }
    }
    foreach ($groups as $group) {
        foreach (array_filter(array_filter($group['photos'], 'isCoverShaped'), $notCover) as $photo) {
            $add($photo);
        }
    }
    foreach ($groups as $group) {
        foreach (array_filter($group['photos'], $notCover) as $photo) {
            $add($photo);
        }
    }
    foreach ($groups as $group) {
        foreach ($group['photos'] as $photo) {
            $add($photo);
        }
    }

    return array_values($picked);
}

/**
 * Public {id, w, h} of a selected photo (w/h omitted when unknown, as for
 * location photos).
 */
function publicPhotoRef(array $photo): array {
    $ref = ['id' => $photo['id']];
    if (isset($photo['w'], $photo['h'])) {
        $ref['w'] = $photo['w'];
        $ref['h'] = $photo['h'];
    }
    return $ref;
}

/**
 * Query a year's photos and group them into slides (no thumbnail downloads).
 * Returns ['data' => ['year_summary' => [...], 'months' => [...]],
 *          'thumbs' => [id => ['k' => cache_key, 'u' => unit_id|null]]].
 * year_summary.cover and months[].summary.photos are picked from the selected
 * photos (see pickYearCover() / pickMonthPhotos()); the photo times and group
 * sizes used for that are never stored.
 * Throws Exception if the Synology query fails.
 */
function buildYearData(SynologyAPI $api, int $year): array {
    $monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                   'July', 'August', 'September', 'October', 'November', 'December'];

    $allPhotos = $api->getPhotosForYear($year);
    logMessage('Year in Review: ' . count($allPhotos) . " photos for {$year}", 'INFO');

    // Year summary and per-month buckets
    $yearCountries = [];
    $yearStates = [];
    $yearCities = [];
    $photosByMonth = [];
    foreach ($allPhotos as $photo) {
        if (!isset($photo['id'], $photo['time'])) {
            continue;
        }
        $parts = buildLocationParts($photo['additional']['address'] ?? null);
        if (isset($parts['country'])) {
            $yearCountries[$parts['country']] = true;
        }
        if (isset($parts['state'])) {
            $yearStates[$parts['state']] = true;
        }
        if (isset($parts['city'])) {
            $yearCities[$parts['city']] = true;
        }
        $photosByMonth[(int)date('n', (int)$photo['time'])][] = $photo;
    }
    ksort($photosByMonth);

    $yearSummary = [
        'total_photos' => count($allPhotos),
        'countries' => count($yearCountries),
        'states' => count($yearStates),
        'cities' => count($yearCities)
    ];

    $months = [];
    $thumbs = [];
    // In memory only: per month, its groups' sizes and selected photos (with times)
    $monthGroupInfos = [];

    foreach ($photosByMonth as $monthNum => $monthPhotos) {
        $monthCountries = [];
        $monthStates = [];
        $monthCities = [];
        $groups = [];
        $unknown = [];

        foreach ($monthPhotos as $photo) {
            $parts = buildLocationParts($photo['additional']['address'] ?? null);
            if (empty($parts)) {
                $unknown[] = $photo;
                continue;
            }
            if (isset($parts['country'])) {
                $monthCountries[$parts['country']] = true;
            }
            if (isset($parts['state'])) {
                $monthStates[$parts['state']] = true;
            }
            if (isset($parts['city'])) {
                $monthCities[$parts['city']] = true;
            }
            // Keyed, so city "Georgia" and state "Georgia" stay separate groups
            $key = json_encode($parts);
            if (!isset($groups[$key])) {
                $groups[$key] = ['parts' => $parts, 'photos' => []];
            }
            $groups[$key]['photos'][] = $photo;
        }

        uasort($groups, function ($a, $b) {
            return compareLocationParts($a['parts'], $b['parts']);
        });

        $locations = [];
        foreach ($groups as $group) {
            // Order the keys country, state, city for the client
            $loc = [];
            foreach (['country', 'state', 'city'] as $k) {
                if (isset($group['parts'][$k])) {
                    $loc[$k] = $group['parts'][$k];
                }
            }
            $locations[] = $loc;
        }

        // "Unknown location" goes last
        if (!empty($unknown)) {
            $groups['__unknown__'] = ['parts' => [], 'photos' => $unknown];
        }

        $locationSlides = [];
        $groupInfos = [];
        foreach ($groups as $group) {
            $slidePhotos = [];
            $selected = [];
            foreach (selectYearHighlights($group['photos'], YEAR_PHOTOS_PER_SLIDE) as $photo) {
                $cacheKey = $photo['additional']['thumbnail']['cache_key'] ?? null;
                if (!is_string($cacheKey) || $cacheKey === '') {
                    logMessage('Year in Review: skipping photo ' . (int)$photo['id'] . ' (no thumbnail cache key)', 'WARNING');
                    continue;
                }
                $id = (int)$photo['id'];
                $unitId = $photo['additional']['thumbnail']['unit_id'] ?? null;
                $thumbs[(string)$id] = [
                    'k' => $cacheKey,
                    'u' => is_numeric($unitId) ? (int)$unitId : null
                ];

                $entry = [
                    'id' => $id,
                    'filename' => is_string($photo['filename'] ?? null) ? $photo['filename'] : ''
                ];
                $size = photoDisplaySize($photo);
                $slidePhotos[] = $entry + $size;
                $selected[] = ['id' => $id, 'time' => (int)$photo['time']] + $size;
            }

            if (!empty($slidePhotos)) {
                $locationSlides[] = [
                    'title' => buildLocationTitle($group['parts']),
                    'photo_count' => count($slidePhotos),
                    'photos' => $slidePhotos
                ];
                $groupInfos[] = [
                    'count' => count($group['photos']),
                    'month' => $monthNum,
                    'order' => count($groupInfos),
                    'photos' => $selected
                ];
            }
        }

        if (empty($locationSlides)) {
            continue;
        }

        $monthGroupInfos[count($months)] = $groupInfos;
        $months[] = [
            'summary' => [
                'month_number' => $monthNum,
                'month_name' => $monthNames[$monthNum - 1],
                'total_photos' => count($monthPhotos),
                'countries' => count($monthCountries),
                'states' => count($monthStates),
                'cities' => count($monthCities),
                'locations' => $locations
            ],
            'location_slides' => $locationSlides
        ];
    }

    // Year cover, then up to 3 photos per month (skipping the cover)
    $allGroupInfos = [];
    foreach ($monthGroupInfos as $groupInfos) {
        foreach ($groupInfos as $info) {
            $allGroupInfos[] = $info;
        }
    }
    $cover = pickYearCover($allGroupInfos);
    $yearSummary['cover'] = $cover === null ? null : publicPhotoRef($cover);
    foreach ($months as $m => $month) {
        $months[$m]['summary']['photos'] = array_map('publicPhotoRef',
            pickMonthPhotos($monthGroupInfos[$m], $cover === null ? null : $cover['id']));
    }

    return [
        'data' => [
            'year_summary' => $yearSummary,
            'months' => $months
        ],
        'thumbs' => $thumbs
    ];
}

/**
 * Add "src" to every photo: the static cached file when it exists, else the
 * on-demand endpoint. Decided per response, never stored. The year cover and
 * location photos use size xl, the month summary photos size m.
 */
function withYearPhotoSrc(array $data, int $year): array {
    if (is_array($data['year_summary']['cover'] ?? null)) {
        $id = (int)($data['year_summary']['cover']['id'] ?? 0);
        $data['year_summary']['cover']['src'] = yearThumbSrc($year, $id, 'xl');
    }
    if (empty($data['months']) || !is_array($data['months'])) {
        return $data;
    }
    foreach ($data['months'] as $m => $month) {
        foreach (($month['summary']['photos'] ?? []) as $p => $photo) {
            $id = (int)($photo['id'] ?? 0);
            $data['months'][$m]['summary']['photos'][$p]['src'] = yearThumbSrc($year, $id, 'm');
        }
        foreach (($month['location_slides'] ?? []) as $s => $slide) {
            foreach (($slide['photos'] ?? []) as $p => $photo) {
                $id = (int)($photo['id'] ?? 0);
                $data['months'][$m]['location_slides'][$s]['photos'][$p]['src'] = yearThumbSrc($year, $id, 'xl');
            }
        }
    }
    return $data;
}

/**
 * Picker tile data for a year from its list file (stale lists are fine; no
 * Synology calls): ['src' => m-size cover URL, 'cities' => n, 'total_photos' => n],
 * or null when there is no usable list (missing, older schema) or no cover.
 * An existing but unreadable list is logged (once per request; an older schema
 * is expected after a bump and is not logged).
 */
function readYearCover(int $year): ?array {
    static $warned = false;
    $cache = readYearCache($year, false, $reason);
    if ($cache === null) {
        if ($reason === 'invalid' && !$warned) {
            $warned = true;
            logMessage('Year in Review: list file for ' . $year . ' is unreadable or invalid (picker shows a plain tile)', 'WARNING');
        }
        return null;
    }
    $summary = $cache['data']['year_summary'] ?? null;
    $cover = is_array($summary) ? ($summary['cover'] ?? null) : null;
    if (!is_array($cover) || !isset($cover['id']) || !is_int($cover['id']) || $cover['id'] <= 0) {
        return null;
    }
    // The src lands inside CSS url(...) in a style attribute, where h() alone
    // can't stop a ')' or ';' breaking out. It is built from ints today; keep
    // this guard in case that ever changes.
    $src = yearThumbSrc($year, $cover['id'], 'm');
    if (!isYearThumbSrc($src)) {
        return null;
    }
    return [
        'src' => $src,
        'cities' => max(0, (int)($summary['cities'] ?? 0)),
        'total_photos' => max(0, (int)($summary['total_photos'] ?? 0))
    ];
}

/**
 * Read the shared sid (no lock: writes are atomic renames). Null if missing.
 */
function readSharedSid(): ?string {
    $session = readSharedSession();
    return $session === null ? null : $session['sid'];
}

/**
 * Read the shared session file: ['sid' => string, 'created' => int], or null.
 */
function readSharedSession(): ?array {
    if (!is_file(SYNOLOGY_SESSION_PATH)) {
        return null;
    }
    $json = readGuardedFile(SYNOLOGY_SESSION_PATH);
    $data = $json === null ? null : json_decode($json, true);
    $sid = is_array($data) ? ($data['sid'] ?? null) : null;
    if (!is_string($sid) || $sid === '') {
        return null;
    }
    return ['sid' => $sid, 'created' => (int)($data['created'] ?? 0)];
}

/**
 * After a failed thumbnail fetch with $failedSid: should we refresh the shared
 * sid and retry once?
 *  - No stored session: only if $failedSid had been saved (the file was since
 *    deleted, e.g. admin Clear). A fresh unsaved login is not retried.
 *  - Synology codes 106/107/119 (session expired / replaced / not found): yes.
 *  - The stored sid already differs (someone else refreshed it): yes, no login needed.
 *  - Otherwise only if the stored sid is at least SHARED_SID_RETRY_MIN_AGE old.
 * With sharedSynologyApi() this caps a request at 2 logins.
 */
function shouldRetrySharedSid(?string $failedSid, int $code, bool $failedSidPersisted = true): bool {
    $session = readSharedSession();
    if ($session === null) {
        return $failedSidPersisted;
    }
    if (in_array($code, [106, 107, 119], true)) {
        return true;
    }
    if ($session['sid'] !== $failedSid) {
        return true;
    }
    return $session['created'] <= time() - SHARED_SID_RETRY_MIN_AGE;
}

/**
 * A SynologyAPI using the shared sid (logging in to create one if needed).
 * Thumb endpoint only; the shared sid is never logged out.
 * $persisted is set to false if a fresh login could not be saved: the caller
 * must then logout() that API itself when done.
 */
function sharedSynologyApi(array $config, ?bool &$persisted = null): SynologyAPI {
    $persisted = true;
    $sid = readSharedSid();
    if ($sid === null) {
        return refreshSharedSid($config, null, $persisted);
    }
    $api = new SynologyAPI(
        $config['synology']['url'] ?? '',
        $config['synology']['username'] ?? '',
        $config['synology']['password'] ?? ''
    );
    $api->setSid($sid);
    return $api;
}

/**
 * Replace a missing/expired shared sid. Under an exclusive lock: if someone
 * else already stored a different sid, use it; otherwise log in and store the
 * new one. Throws Exception if the login fails.
 * $persisted is set to false when a fresh login could not be saved (e.g.
 * config/ not writable): the caller must logout() that API itself.
 */
function refreshSharedSid(array $config, ?string $failedSid, ?bool &$persisted = null): SynologyAPI {
    $persisted = true;
    $api = new SynologyAPI(
        $config['synology']['url'] ?? '',
        $config['synology']['username'] ?? '',
        $config['synology']['password'] ?? ''
    );

    $lock = @fopen(SYNOLOGY_SESSION_LOCK, 'c');
    if ($lock === false) {
        logMessage('Year in Review: could not open the Synology session lock file', 'WARNING');
    } else {
        flock($lock, LOCK_EX);
    }

    try {
        $current = readSharedSid();
        if ($current !== null && $current !== $failedSid) {
            // Someone else refreshed it while we waited
            $api->setSid($current);
            return $api;
        }

        if (!$api->login()) {
            throw new Exception('Synology login failed');
        }
        logMessage('Year in Review: new shared Synology session', 'DEBUG');

        $json = json_encode(['sid' => $api->getSid(), 'created' => time()], JSON_HEX_TAG);
        // Private from the start: the temp file is created 0600 too, so a
        // killed write can't leave a world-readable copy of the sid behind
        $oldUmask = umask(0077);
        try {
            $persisted = $json !== false && writeGuardedFile(SYNOLOGY_SESSION_PATH, $json, 0600);
        } finally {
            umask($oldUmask);
        }
        if (!$persisted) {
            logMessage('Year in Review: could not save the shared Synology session (it will be logged out after use)', 'WARNING');
        }
        return $api;
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Nightly cleanup of cache/year_review/ (cron only, no Synology calls).
 * Deletes {year}_{id}_{size}.jpg files that no list file references and that
 * are over an hour old (years with an unreadable list file are left alone),
 * plus stale .*.tmp files. Returns ['deleted' => n, 'kept' => n, 'failed' => n].
 */
function cleanYearReviewCache(): array {
    $result = ['deleted' => 0, 'kept' => 0, 'failed' => 0];
    $cutoff = time() - 3600;

    $keep = [];
    $protectedYears = [];
    foreach (glob(YEAR_REVIEW_DIR . '/*.cache.php') ?: [] as $listFile) {
        if (!preg_match('/^(\d{4})\.cache\.php$/', basename($listFile), $m)) {
            continue;
        }
        $year = (int)$m[1];
        $json = readGuardedFile($listFile);
        $cache = $json === null ? null : json_decode($json, true);
        if (!is_array($cache) || !is_array($cache['thumbs'] ?? null)) {
            $protectedYears[$year] = true;
            logMessage('Year in Review cleanup: ' . basename($listFile) . ' is unreadable or invalid, keeping its thumbnails', 'WARNING');
            continue;
        }
        // Every size: the m files (picker and month summaries) are referenced too
        foreach (array_keys($cache['thumbs']) as $id) {
            foreach (YEAR_THUMB_SIZES as $size) {
                $keep[$year . '_' . $id . '_' . $size . '.jpg'] = true;
            }
        }
    }

    $names = @scandir(YEAR_REVIEW_DIR);
    foreach ($names ?: [] as $name) {
        $path = YEAR_REVIEW_DIR . '/' . $name;
        if (preg_match('/^(\d{4})_(\d+)_[a-z]+\.jpg$/', $name, $m)) {
            if (isset($keep[$name]) || isset($protectedYears[(int)$m[1]])
                || !is_file($path) || @filemtime($path) >= $cutoff) {
                $result['kept']++;
                continue;
            }
        } elseif (preg_match('/^\..+\.tmp$/', $name)) {
            if (!is_file($path) || @filemtime($path) >= $cutoff) {
                continue;
            }
        } else {
            // .gitignore, .htaccess, *.cache.php and anything else
            continue;
        }

        if (@unlink($path)) {
            $result['deleted']++;
        } else {
            $result['failed']++;
        }
    }

    logMessage("Year in Review cleanup: deleted {$result['deleted']}, kept {$result['kept']}, failed {$result['failed']}", 'INFO');
    return $result;
}

/**
 * Admin "Clear Year in Review cache": deletes every list file, thumbnail and
 * temp file in cache/year_review/ (never .gitignore / .htaccess), plus the
 * shared Synology session file config/synology_session.php.
 * Returns ['deleted' => n, 'failed' => n, 'session_cleared' => bool].
 */
function clearYearReviewCache(): array {
    $result = ['deleted' => 0, 'failed' => 0, 'session_cleared' => false];
    $files = array_merge(
        glob(YEAR_REVIEW_DIR . '/*.cache.php') ?: [],
        glob(YEAR_REVIEW_DIR . '/*.jpg') ?: [],
        glob(YEAR_REVIEW_DIR . '/.*.tmp') ?: []
    );
    // The shared Synology session too (the next thumbnail miss logs in again)
    if (is_file(SYNOLOGY_SESSION_PATH)) {
        $files[] = SYNOLOGY_SESSION_PATH;
    }
    foreach ($files as $path) {
        $name = basename($path);
        if ($name === '.gitignore' || $name === '.htaccess' || !is_file($path)) {
            continue;
        }
        if (@unlink($path)) {
            $result['deleted']++;
            if ($path === SYNOLOGY_SESSION_PATH) {
                $result['session_cleared'] = true;
            }
        } else {
            $result['failed']++;
        }
    }
    logMessage("Year in Review cache cleared: deleted {$result['deleted']} files, failed {$result['failed']}", 'INFO');
    return $result;
}

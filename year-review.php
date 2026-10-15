<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/api/year_review_lib.php';

$config = loadConfig();
$isAdmin = isAdminLoggedIn();
session_write_close();

$yrEnabled = isYearReviewEnabled($config);
$firstYear = $config['photos']['first_year'] ?? 2010;
$currentYear = (int)date('Y');

// Generate year range (newest first)
$years = range($currentYear, $firstYear);

// Cover tiles for years whose list file exists (no Synology calls); the rest
// get plain tiles
$covers = [];
if ($yrEnabled) {
    foreach ($years as $year) {
        try {
            $covers[$year] = readYearCover((int)$year);
        } catch (Throwable $t) {
            logMessage('Year in Review picker: could not read the cover for ' . (int)$year . ': '
                . redactSecrets($t->getMessage()), 'WARNING');
            $covers[$year] = null;
        }
    }
}

/**
 * "60 Cities · 4,133 Photos" for a cover tile (zero values omitted)
 */
function yearTileMeta(array $cover): string {
    $parts = [];
    if ($cover['cities'] > 0) {
        $parts[] = number_format($cover['cities']) . ($cover['cities'] === 1 ? ' City' : ' Cities');
    }
    if ($cover['total_photos'] > 0) {
        $parts[] = number_format($cover['total_photos']) . ($cover['total_photos'] === 1 ? ' Photo' : ' Photos');
    }
    return implode(' · ', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Year in Review - Rediscover This Day</title>
    <link rel="stylesheet" href="css/year-review.css?v=<?php echo filemtime(__DIR__ . '/css/year-review.css'); ?>">
</head>
<body>
    <div class="year-review-container">
        <header class="year-header">
            <div class="header-title">
                <div class="eyebrow">Rediscover This Day</div>
                <h1>Year in Review</h1>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn js-nav">This Day</a>
                <?php if ($isAdmin): ?>
                    <a href="admin/index.php" class="btn">Admin</a>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!$yrEnabled): ?>
            <div class="year-off-panel">
                <h2>Year in Review is turned off</h2>
                <a href="index.php" class="btn js-nav">This Day</a>
            </div>
        <?php else: ?>
            <div class="year-grid">
                <?php foreach ($years as $year): ?>
                    <?php $cover = $covers[$year] ?? null; ?>
                    <?php if ($cover !== null): ?>
                        <a href="year-slideshow.php?year=<?php echo h($year); ?>" class="year-card js-nav">
                            <span class="year-cover" style="background-image:url(<?php echo h($cover['src']); ?>)"></span>
                            <span class="year-scrim"></span>
                            <span class="year-text">
                                <?php $meta = yearTileMeta($cover); ?>
                                <?php if ($meta !== ''): ?>
                                    <span class="year-meta"><?php echo h($meta); ?></span>
                                <?php endif; ?>
                                <span class="year-number"><?php echo h($year); ?></span>
                            </span>
                        </a>
                    <?php else: ?>
                        <a href="year-slideshow.php?year=<?php echo h($year); ?>" class="year-card is-plain js-nav">
                            <span class="year-text">
                                <span class="rule"></span>
                                <span class="year-number"><?php echo h($year); ?></span>
                            </span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Navigate in JS so the Home Screen app stays full screen (href is the fallback)
        (function() {
            'use strict';
            var links = document.getElementsByClassName('js-nav');
            function go(e) {
                e.preventDefault();
                window.location.href = this.getAttribute('href');
            }
            for (var i = 0; i < links.length; i++) {
                links[i].addEventListener('click', go);
            }
        })();
    </script>
</body>
</html>

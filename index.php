<?php
require_once __DIR__ . '/common.php';

header('Cache-Control: no-cache, must-revalidate');

// Read the version before the photos: if the cache changes in between, the
// client sees an old version and reloads once, rather than missing the update.
$photosVersion = getCachedPhotosVersion();
$photos = getCachedPhotos();
$config = loadConfig();
session_write_close();
$yrEnabled = isYearReviewEnabled($config);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Rediscover This Day">
    <title>Rediscover This Day</title>
    <link rel="stylesheet" href="css/slideshow.css?v=<?php echo filemtime(__DIR__ . '/css/slideshow.css'); ?>">
</head>
<body>
    <div id="slideshow-container">
        <?php if (empty($photos)): ?>
            <div class="no-photos">
                <h2>No photos for today</h2>
                <p>Check back tomorrow!</p>
                <?php if ($yrEnabled): ?>
                    <button id="year-review-btn-empty" type="button" class="btn">Year in Review</button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div id="photo-display">
                <img id="current-photo" src="" alt="Photo">
                <div id="metadata">
                    <span id="year"></span>
                    <span id="location"></span>
                    <span id="landmark"></span>
                    <span id="route"></span>
                    <span id="village_district_town_city"></span>
                    <span id="county"></span>
                    <span id="state"></span>
                    <span id="country"></span>
                    <span id="image-count"></span>
                </div>
                <div id="tags-metadata">
                    <div id="tags-label"></div>
                    <div id="tags-list"></div>
                </div>
            </div>
            
            <div id="controls">
                <button id="prev-btn" title="Previous">&lt;</button>
                <button id="play-pause-btn" title="Pause">&#10074;&#10074;</button>
                <button id="next-btn" title="Next">&gt;</button>
                <span id="counter"></span>
            </div>
            <?php if ($yrEnabled): ?>
                <button id="year-review-btn" type="button" title="Year in Review">Year in Review</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        // Pass PHP data to JavaScript
        var photos = <?php echo json_encode($photos); ?>;
        var photosVersion = <?php echo json_encode($photosVersion); ?>;
        var config = <?php echo json_encode($config['slideshow']); ?>;
    </script>
    <script src="js/slideshow.js?v=<?php echo filemtime(__DIR__ . '/js/slideshow.js'); ?>"></script>
</body>
</html>

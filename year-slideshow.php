<?php
require_once __DIR__ . '/common.php';

$config = loadConfig();
session_write_close();

// Turned off: back to the year picker (a server redirect stays full screen)
if (!isYearReviewEnabled($config)) {
    header('Location: year-review.php');
    exit;
}

// Validate year parameter
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;

if (!$year) {
    header('Location: year-review.php');
    exit;
}

$firstYear = $config['photos']['first_year'] ?? 2010;
$currentYear = (int)date('Y');

// Validate year is in acceptable range
if ($year < $firstYear || $year > $currentYear) {
    header('Location: year-review.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo h($year); ?> - Year in Review</title>
    <link rel="stylesheet" href="css/lib/justifiedGallery-3.8.1.min.css?v=<?php echo filemtime(__DIR__ . '/css/lib/justifiedGallery-3.8.1.min.css'); ?>">
    <link rel="stylesheet" href="css/slideshow.css?v=<?php echo filemtime(__DIR__ . '/css/slideshow.css'); ?>">
    <link rel="stylesheet" href="css/year-slideshow.css?v=<?php echo filemtime(__DIR__ . '/css/year-slideshow.css'); ?>">
</head>
<body>
    <div id="slideshow-container">
        <div id="loading-screen">
            <div class="loading-message">
                <div class="loading-spinner"></div>
                <p id="loading-text">Loading <?php echo h($year); ?> highlights...</p>
                <button id="loading-back-btn" class="btn">Back to Years</button>
            </div>
        </div>

        <div id="photo-display" style="display: none;"></div>

        <div id="stat-display" style="display: none;">
            <div class="stat-content">
                <h2 class="stat-title"></h2>
                <p class="stat-value"></p>
                <p class="stat-detail"></p>
            </div>
        </div>

        <div id="controls">
            <button id="back-btn" title="Back to Year Selection">&larr;</button>
            <button id="prev-btn" title="Previous">&lt;</button>
            <button id="play-pause-btn" title="Pause">&#10074;&#10074;</button>
            <button id="next-btn" title="Next">&gt;</button>
            <span id="counter"></span>
        </div>

        <div id="error-screen" style="display: none;">
            <div class="error-message">
                <h2 id="error-title">Unable to load photos</h2>
                <p id="error-text">Please check your connection and try again.</p>
                <button id="error-back-btn" class="btn">Back to Years</button>
                <button id="error-retry-btn" class="btn">Try Again</button>
            </div>
        </div>
    </div>

    <script src="js/lib/jquery-3.7.1.min.js?v=<?php echo filemtime(__DIR__ . '/js/lib/jquery-3.7.1.min.js'); ?>"></script>
    <script src="js/lib/jquery.justifiedGallery-3.8.1.min.js?v=<?php echo filemtime(__DIR__ . '/js/lib/jquery.justifiedGallery-3.8.1.min.js'); ?>"></script>
    <script>
        var selectedYear = <?php echo json_encode($year); ?>;
        var config = <?php echo json_encode($config['slideshow'] ?? []); ?>;
    </script>
    <script src="js/year-slideshow.js?v=<?php echo filemtime(__DIR__ . '/js/year-slideshow.js'); ?>"></script>
</body>
</html>

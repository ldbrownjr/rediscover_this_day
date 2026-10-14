<?php
require_once 'common.php';

// Validate year parameter
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$config = loadConfig();

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/justifiedGallery/3.8.1/css/justifiedGallery.min.css">
    <link rel="stylesheet" href="css/slideshow.css?v=<?php echo filemtime('css/slideshow.css'); ?>">
    <link rel="stylesheet" href="css/year-slideshow.css?v=<?php echo filemtime(__DIR__ . '/css/year-slideshow.css'); ?>">
</head>
<body>
    <div id="slideshow-container">
        <div id="loading-screen">
            <div class="loading-message">
                <div class="loading-spinner"></div>
                <p>Loading <?php echo h($year); ?> highlights...</p>
            </div>
        </div>

        <div id="photo-display" style="display: none;">
            <img id="current-photo" src="" alt="Photo">
            <div id="metadata">
                <span id="year"></span>
                <span id="date"></span>
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
                <h2>Unable to load photos</h2>
                <p id="error-text">Please check your connection and try again.</p>
                <a href="year-review.php" class="btn">Back to Year Selection</a>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/justifiedGallery/3.8.1/js/jquery.justifiedGallery.min.js"></script>
    <script>
        var selectedYear = <?php echo $year; ?>;
        var config = <?php echo json_encode($config['slideshow'] ?? []); ?>;
        var yearReviewConfig = <?php echo json_encode($config['year_review'] ?? []); ?>;
    </script>
    <script src="js/year-slideshow.js?v=<?php echo filemtime('js/year-slideshow.js'); ?>"></script>
</body>
</html>

<?php
require_once 'common.php';

$photos = getCachedPhotos();
$config = loadConfig();
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
    <link rel="stylesheet" href="css/slideshow.css">
</head>
<body>
    <div id="slideshow-container">
        <?php if (empty($photos)): ?>
            <div class="no-photos">
                <h2>No photos for today</h2>
                <p>Check back tomorrow!</p>
            </div>
        <?php else: ?>
            <div id="photo-display">
                <img id="current-photo" src="" alt="Photo">
                <div id="metadata">
                    <span id="year"></span>
                    <span id="location"></span>
                    <span id="image-count"></span>
                </div>
            </div>
            
            <div id="controls">
                <button id="prev-btn" title="Previous">&lt;</button>
                <button id="play-pause-btn" title="Pause">&#10074;&#10074;</button>
                <button id="next-btn" title="Next">&gt;</button>
                <span id="counter"></span>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Pass PHP data to JavaScript
        var photos = <?php echo json_encode($photos); ?>;
        var config = <?php echo json_encode($config['slideshow']); ?>;
    </script>
    <script src="js/slideshow.js"></script>
</body>
</html>

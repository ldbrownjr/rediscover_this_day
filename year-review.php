<?php
require_once __DIR__ . '/common.php';

$config = loadConfig();
$isAdmin = isAdminLoggedIn();
session_write_close();

$yrEnabled = isYearReviewEnabled($config);
$firstYear = $config['photos']['first_year'] ?? 2010;
$currentYear = (int)date('Y');

// Generate year range (newest first)
$years = range($currentYear, $firstYear);
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
            <h1>Year in Review</h1>
            <div class="header-actions">
                <a href="index.php" class="btn btn-secondary js-nav">This Day Slideshow</a>
                <?php if ($isAdmin): ?>
                    <a href="admin/index.php" class="btn btn-secondary">Admin</a>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!$yrEnabled): ?>
            <div class="year-header year-off-panel">
                <p>Year in Review is turned off</p>
                <a href="index.php" class="btn js-nav">This Day Slideshow</a>
            </div>
        <?php else: ?>
            <div class="year-grid">
                <?php foreach ($years as $year): ?>
                    <a href="year-slideshow.php?year=<?php echo h($year); ?>" class="year-card js-nav">
                        <div class="year-number"><?php echo h($year); ?></div>
                        <div class="year-label">View Highlights</div>
                    </a>
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

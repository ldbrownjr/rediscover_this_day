<?php
require_once 'common.php';

$config = loadConfig();
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
    <link rel="stylesheet" href="css/year-review.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="year-review-container">
        <header class="year-header">
            <h1>Year in Review</h1>
            <div class="header-actions">
                <a href="index.php" class="btn btn-secondary">This Day Slideshow</a>
                <?php if (isAdminLoggedIn()): ?>
                    <a href="admin/index.php" class="btn btn-secondary">Admin</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="year-grid">
            <?php foreach ($years as $year): ?>
                <a href="year-slideshow.php?year=<?php echo $year; ?>" class="year-card">
                    <div class="year-number"><?php echo $year; ?></div>
                    <div class="year-label">View Highlights</div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>

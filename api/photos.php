<?php
require_once __DIR__ . '/../common.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $photos = getCachedPhotos();
    echo json_encode([
        'success' => true,
        'photos' => $photos,
        'count' => count($photos),
        'version' => getCachedPhotosVersion(),
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    logMessage('photos.php error: ' . $e->getMessage(), 'ERROR');
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load photos'
    ]);
}

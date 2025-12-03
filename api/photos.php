<?php
require_once '../common.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $photos = getCachedPhotos();
    echo json_encode([
        'success' => true,
        'photos' => $photos,
        'count' => count($photos),
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
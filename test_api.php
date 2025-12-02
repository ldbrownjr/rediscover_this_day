<?php
/**
 * Test script to debug Synology API calls
 */

require_once 'common.php';
require_once 'api/synology.php';

$config = loadConfig();

echo "=== Testing Synology Photos API ===\n\n";

// Initialize API
$api = new SynologyAPI(
    $config['synology']['url'],
    $config['synology']['username'],
    $config['synology']['password']
);

// Login
echo "1. Testing login...\n";
if ($api->login()) {
    echo "   ✓ Login successful\n\n";
} else {
    echo "   ✗ Login failed\n";
    exit(1);
}

// Test 1: Simple list without any filters
echo "2. Testing simple list (no filters, no additional)...\n";
$testUrl = $config['synology']['url'] . '/photo/webapi/entry.cgi';
$testParams = [
    'api' => 'SYNO.FotoTeam.Browse.Item',
    'version' => '1',
    'method' => 'list',
    'offset' => '0',
    'limit' => '5',
    '_sid' => 'TEST_SID_PLACEHOLDER'
];
echo "   URL: " . $testUrl . "?" . http_build_query($testParams) . "\n\n";

// Test 2: Different ways to format the additional parameter
echo "3. Testing different 'additional' parameter formats...\n\n";

$formats = [
    'Format 1 (current)' => '["thumbnail","resolution","orientation"]',
    'Format 2 (json_encode)' => json_encode(['thumbnail','resolution','orientation']),
    'Format 3 (Python style)' => str_replace("'", '"', str_replace(' ', '', json_encode(['thumbnail','resolution','orientation']))),
    'Format 4 (no quotes)' => '[thumbnail,resolution,orientation]',
    'Format 5 (single item)' => '["thumbnail"]',
];

foreach ($formats as $name => $format) {
    echo "   {$name}: {$format}\n";
    echo "   URL encoded: " . urlencode($format) . "\n\n";
}

// Test 3: Try SYNO.Foto instead of SYNO.FotoTeam
echo "4. Testing both Personal Space (Foto) and Shared Space (FotoTeam)...\n\n";

$apis = [
    'SYNO.Foto.Browse.Item' => 'Personal Space',
    'SYNO.FotoTeam.Browse.Item' => 'Shared Space'
];

foreach ($apis as $apiName => $spaceName) {
    echo "   Testing {$apiName} ({$spaceName})...\n";
    // We'll just show what the URL would look like
    $params = [
        'api' => $apiName,
        'version' => '1',
        'method' => 'list',
        'offset' => '0',
        'limit' => '5',
    ];
    echo "   Base URL: " . $testUrl . "?" . http_build_query($params) . "\n\n";
}

// Test 4: Check what API version supports
echo "5. Querying available API info...\n";
$infoUrl = $config['synology']['url'] . '/photo/webapi/entry.cgi?api=SYNO.API.Info&version=1&method=query&query=all';
echo "   Info URL: {$infoUrl}\n";
echo "   Try opening this in your browser to see what APIs are available\n\n";

echo "=== Manual Testing Recommendations ===\n\n";
echo "Try these URLs in your browser (replace SESSION_ID with actual _sid from logs):\n\n";

echo "1. List without additional (Personal Space):\n";
echo "   " . $config['synology']['url'] . "/photo/webapi/entry.cgi?api=SYNO.Foto.Browse.Item&version=1&method=list&offset=0&limit=5&_sid=SESSION_ID\n\n";

echo "2. List with additional (Personal Space):\n";
echo "   " . $config['synology']['url'] . "/photo/webapi/entry.cgi?api=SYNO.Foto.Browse.Item&version=1&method=list&offset=0&limit=5&additional=[\"thumbnail\"]&_sid=SESSION_ID\n\n";

echo "3. List without additional (Shared Space):\n";
echo "   " . $config['synology']['url'] . "/photo/webapi/entry.cgi?api=SYNO.FotoTeam.Browse.Item&version=1&method=list&offset=0&limit=5&_sid=SESSION_ID\n\n";

echo "4. List with additional (Shared Space):\n";
echo "   " . $config['synology']['url'] . "/photo/webapi/entry.cgi?api=SYNO.FotoTeam.Browse.Item&version=1&method=list&offset=0&limit=5&additional=[\"thumbnail\"]&_sid=SESSION_ID\n\n";

echo "=== Next Steps ===\n\n";
echo "1. Run this script: php test_api.php\n";
echo "2. Look at the latest _sid from your logs\n";
echo "3. Try the URLs above in your browser with the real _sid\n";
echo "4. Check if the response includes 'additional' field\n";
echo "5. Try different API versions (version=1, version=2, version=3)\n";
echo "6. Check if your photos are in Personal Space or Shared Space\n\n";

$api->logout();
echo "Test complete!\n";

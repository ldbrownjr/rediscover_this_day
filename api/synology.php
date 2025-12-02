<?php
/**
 * Synology Photos API Wrapper
 * Based on: https://github.com/zeichensatz/SynologyPhotosAPI
 */

class SynologyAPI {
    private $baseUrl;
    private $username;
    private $password;
    private $sid = null;
    private $did = null;
    
    public function __construct($url, $username, $password) {
        $this->baseUrl = rtrim($url, '/');
        $this->username = $username;
        $this->password = $password;
    }
    
    /**
     * Login to Synology Photos API
     */
    public function login() {
        $url = $this->baseUrl . '/photo/webapi/auth.cgi';
        
        $params = [
            'api' => 'SYNO.API.Auth',
            'version' => '3',
            'method' => 'login',
            'account' => $this->username,
            'passwd' => $this->password
        ];
        
        $response = $this->makeRequest($url, $params, 'POST');
        
        if ($response && isset($response['success']) && $response['success']) {
            $this->sid = $response['data']['sid'];
            $this->did = $response['data']['did'];
            return true;
        }
        
        return false;
    }
    
    /**
     * Logout from Synology Photos API
     */
    public function logout() {
        if (!$this->sid) {
            return true;
        }
        
        $url = $this->baseUrl . '/photo/webapi/auth.cgi';
        
        $params = [
            'api' => 'SYNO.API.Auth',
            'version' => '3',
            'method' => 'logout',
            '_sid' => $this->sid
        ];
        
        $this->makeRequest($url, $params);
        $this->sid = null;
        $this->did = null;
        
        return true;
    }
    
    /**
     * Test connection to Synology
     */
    public function testConnection() {
        return $this->login();
    }
    
    /**
     * Search for photos by date
     * Returns all photos taken on the specified month and day across all years
     */
    public function searchPhotosByDate($month, $day, $firstYear = null) {
        logMessage("searchPhotosByDate called with month={$month}, day={$day}, firstYear={$firstYear}", 'INFO');
        
        if (!$this->sid && !$this->login()) {
            logMessage('Failed to login to Synology API in searchPhotosByDate', 'ERROR');
            return [];
        }
        
        logMessage("Logged in successfully, SID: " . substr($this->sid, 0, 10) . "...", 'INFO');
        
        $allPhotos = [];
        
        // We need to search year by year since the API doesn't support "any year"
        $currentYear = (int)date('Y');
        $startYear = $firstYear ? $firstYear : 2000;
        
        logMessage("Searching year range: {$startYear} to {$currentYear} (total: " . ($currentYear - $startYear + 1) . " years)", 'INFO');
        
        for ($year = $startYear; $year <= $currentYear; $year++) {
            logMessage("Querying year {$year} for date {$month}/{$day}...", 'INFO');
            $photos = $this->getPhotosForSpecificDate($year, $month, $day);
            
            if ($photos) {
                $photoCount = count($photos);
                logMessage("Found {$photoCount} photos for {$month}/{$day}/{$year}", 'INFO');
                $allPhotos = array_merge($allPhotos, $photos);
            } else {
                logMessage("No photos found for {$month}/{$day}/{$year}", 'INFO');
            }
        }
        
        $totalPhotos = count($allPhotos);
        logMessage("Year-by-year search complete. Total photos found: {$totalPhotos}", 'INFO');
        
        // Sort by capture date (oldest first)
        usort($allPhotos, function($a, $b) {
            return $a['time'] - $b['time'];
        });
        
        logMessage("Photos sorted by capture date (oldest to newest)", 'INFO');
        
        return $allPhotos;
    }
    
    /**
     * Get photos for a specific date
     */
    private function getPhotosForSpecificDate($year, $month, $day) {
        // Create start and end timestamps for the specific day
        $startTimestamp = mktime(0, 0, 0, $month, $day, $year);
        $endTimestamp = mktime(23, 59, 59, $month, $day, $year);
        
        logMessage("getPhotosForSpecificDate: {$year}-{$month}-{$day}, timestamps: {$startTimestamp} to {$endTimestamp}", 'INFO');
        logMessage("Date range: " . date('Y-m-d H:i:s', $startTimestamp) . " to " . date('Y-m-d H:i:s', $endTimestamp), 'INFO');
        
        $url = $this->baseUrl . '/photo/webapi/entry.cgi';

        $additionals = ['thumbnail', 'address', 'resolution', 'orientation', 'folder', 'video_convert', 'video_meta', 'tag'];
        $additional = str_replace("'", '"', str_replace(' ', '', json_encode($additionals)));
        
        $params = [
            'api' => 'SYNO.FotoTeam.Browse.Item',
            'version' => '3',
            'method' => 'list',
            'offset' => '0',
            'limit' => '5000',
            '_sid' => $this->sid,
            'additional' => $additional,
            'start_time' => $startTimestamp,
            'end_time' => $endTimestamp
        ];
        
        logMessage("API Request URL: {$url}", 'INFO');
        logMessage("API Request params: " . json_encode($params), 'INFO');
        
        $response = $this->makeRequest($url, $params);
        
        logMessage("API Response received: " . json_encode($response), 'INFO');
        
        if ($response && isset($response['success']) && $response['success']) {
            $photos = isset($response['data']['list']) ? $response['data']['list'] : [];
            logMessage("API call successful, returned " . count($photos) . " photos", 'INFO');
            return $photos;
        }
        
        if ($response) {
            logMessage("API call failed or returned unsuccessful response: " . json_encode($response), 'WARNING');
        } else {
            logMessage("API call returned null/empty response", 'WARNING');
        }
        
        return [];
    }
    
    /**
     * Get photo thumbnail URL
     */
    public function getThumbnailUrl($photoId, $cacheKey, $size = 'xl') {
        return $this->baseUrl . '/photo/webapi/entry.cgi?' . http_build_query([
            'api' => 'SYNO.FotoTeam.Thumbnail',
            'version' => '1',
            'method' => 'get',
            'id' => $photoId,
            'cache_key' => $cacheKey,
            'type' => 'unit',
            'size' => $size,
            '_sid' => $this->sid
        ]);
    }
    
    /**
     * Download photo thumbnail to local cache
     */
    public function downloadThumbnail($photoId, $cacheKey, $size = 'xl') {
        $url = $this->getThumbnailUrl($photoId, $cacheKey, $size);
        
        $imageData = $this->downloadFile($url);
        
        if ($imageData) {
            $filename = $photoId . '_' . $size . '.jpg';
            $filepath = CACHE_PATH . '/' . $filename;
            
            if (file_put_contents($filepath, $imageData)) {
                return $filename;
            }
        }
        
        return null;
    }
    
    /**
     * Download full resolution photo to local cache
     */
    public function downloadFullPhoto($photoId, $cacheKey) {
        $url = $this->baseUrl . '/photo/webapi/entry.cgi?' . http_build_query([
            'api' => 'SYNO.FotoTeam.Download',
            'version' => '1',
            'method' => 'download',
            'unit_id' => '[' . $photoId . ']',
            'cache_key' => $cacheKey,
            '_sid' => $this->sid
        ]);
        
        $imageData = $this->downloadFile($url);
        
        if ($imageData) {
            $filename = $photoId . '_full.jpg';
            $filepath = CACHE_PATH . '/' . $filename;
            
            if (file_put_contents($filepath, $imageData)) {
                return $filename;
            }
        }
        
        return null;
    }
    
    /**
     * Make HTTP request
     */
    private function makeRequest($url, $params = [], $method = 'GET') {
        // Try cURL first, fallback to file_get_contents if not available
        if (function_exists('curl_init')) {
            return $this->makeRequestCurl($url, $params, $method);
        } else {
            logMessage('cURL not available, using file_get_contents fallback', 'INFO');
            return $this->makeRequestStreams($url, $params, $method);
        }
    }

    private function makeRequestCurl($url, $params = [], $method = 'GET') {
        $ch = curl_init();

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            $url .= '?' . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        logMessage("Making $method request to: $url", 'DEBUG');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            logMessage("CURL Error: $error", 'ERROR');
            throw new Exception("CURL Error: $error");
        }

        if ($httpCode !== 200) {
            logMessage("HTTP Error: $httpCode, Response: " . substr($response, 0, 200), 'ERROR');
            throw new Exception("HTTP Error: $httpCode");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("JSON decode error: " . json_last_error_msg(), 'ERROR');
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function makeRequestStreams($url, $params = [], $method = 'GET') {
        // Fallback implementation using file_get_contents with stream contexts
        logMessage("Making $method request to: $url using streams", 'DEBUG');

        // Build the full URL
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Create stream context for HTTPS requests
        $contextOptions = [
            'http' => [
                'method' => $method,
                'timeout' => 30,
                'ignore_errors' => true, // Don't fail on HTTP error codes
                'header' => [
                    'User-Agent: PHP SynologyAPI',
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded'
                ]
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];

        if ($method === 'POST') {
            $contextOptions['http']['content'] = http_build_query($params);
            $contextOptions['http']['header'][] = 'Content-Length: ' . strlen($contextOptions['http']['content']);
        }

        $context = stream_context_create($contextOptions);

        // Make the request
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Unknown stream error';
            logMessage("Stream Error: $errorMsg", 'ERROR');
            throw new Exception("Stream Error: $errorMsg");
        }

        // Get HTTP status code from headers
        $httpCode = 200; // Default
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        if ($httpCode !== 200) {
            logMessage("HTTP Error: $httpCode, Response: " . substr($response, 0, 200), 'ERROR');
            throw new Exception("HTTP Error: $httpCode");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("JSON decode error: " . json_last_error_msg(), 'ERROR');
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }
    
    /**
     * Download file
     */
    private function downloadFile($url) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $data = curl_exec($ch);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            logMessage("Download Error: $error", 'ERROR');
            return null;
        }
        
        return $data;
    }
}

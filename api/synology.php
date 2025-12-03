<?php
/**
 * Synology Photos API Wrapper
 * Based on: https://github.com/zeichensatz/SynologyPhotosAPI
 */

// Load Composer autoload
require_once __DIR__ . '/../vendor/autoload.php';

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
        
        // If running on the same machine as the Synology NAS, use localhost
        $this->adjustUrlForLocalAccess();
    }
    
    /**
     * Adjust URL to use localhost if running on the same machine
     */
    private function adjustUrlForLocalAccess() {
        $parsedUrl = parse_url($this->baseUrl);
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return;
        }
        
        $targetHost = $parsedUrl['host'];
        $currentServer = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? gethostbyname(gethostname());
        
        // If we're running on the same machine as the target, use localhost
        if ($targetHost === $currentServer) {
            $this->baseUrl = str_replace('https://' . $targetHost, 'https://localhost', $this->baseUrl);
            logMessage('Adjusted Synology API URL for local access: ' . $this->baseUrl, 'INFO');
        }
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
        } else {
            // Handle error codes (Method 1 or Method 2)
            logMessage("Full error response: " . json_encode($response), 'DEBUG');
            $errorCode = $response['error']['code'] ?? null;
            $subErrors = $response['error']['errors'] ?? [];
            
            if ($errorCode === 801) {
                logMessage('Login failed: Invalid credentials or permissions (error 801)', 'ERROR');
                throw new Exception('Authentication failed: Invalid credentials.');
            } elseif ($errorCode === 120) {
                logMessage('Login failed: API access denied (error 120)', 'ERROR');
                throw new Exception('API access denied.');
            } elseif ($errorCode === 1100 && !empty($subErrors)) {
                // Method 2: Log sub-errors for debugging
                foreach ($subErrors as $subError) {
                    $subCode = $subError['code'] ?? 'unknown';
                    $path = $subError['path'] ?? '';
                    logMessage("Login sub-error: code {$subCode}, path {$path}", 'ERROR');
                }
                logMessage('Login failed: Multiple errors (code 1100)', 'ERROR');
                throw new Exception('Login failed due to multiple errors.');
            } else {
                logMessage('Login failed: Unknown error (code: ' . ($errorCode ?? 'N/A') . ')', 'ERROR');
            }
            return false;
        }
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
            try {
                $photos = $this->getPhotosForSpecificDate($year, $month, $day);
                
                if ($photos) {
                    $photoCount = count($photos);
                    logMessage("Found {$photoCount} photos for {$month}/{$day}/{$year}", 'INFO');
                    $allPhotos = array_merge($allPhotos, $photos);
                } else {
                    logMessage("No photos found for {$month}/{$day}/{$year}", 'INFO');
                }
            } catch (Exception $e) {
                logMessage("Error querying photos for {$month}/{$day}/{$year}: " . $e->getMessage(), 'ERROR');
                // Continue to next year
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
        
        logMessage("API Response received: " . json_encode($response), 'DEBUG');
        
        if ($response && isset($response['success']) && $response['success']) {
            $photos = isset($response['data']['list']) ? $response['data']['list'] : [];
            logMessage("API call successful, returned " . count($photos) . " photos", 'INFO');
            return $photos;
        } else {
            // Handle error codes (Method 1 or Method 2)
            $errorCode = $response['error']['code'] ?? null;
            $subErrors = $response['error']['errors'] ?? [];
            
            if ($errorCode === 801) {
                logMessage('Photo query failed: Permission denied (error 801)', 'ERROR');
                throw new Exception('Permission denied for photo access.');
            } elseif ($errorCode === 120) {
                logMessage('Photo query failed: Invalid request parameters (error 120)', 'ERROR');
                throw new Exception('Invalid photo query parameters.');
            } elseif ($errorCode === 1100 && !empty($subErrors)) {
                // Method 2: Log sub-errors for debugging
                foreach ($subErrors as $subError) {
                    $subCode = $subError['code'] ?? 'unknown';
                    $path = $subError['path'] ?? '';
                    logMessage("Photo query sub-error: code {$subCode}, path {$path}", 'ERROR');
                }
                logMessage('Photo query failed: Multiple errors (code 1100)', 'ERROR');
                throw new Exception('Photo query failed due to multiple errors.');
            } else {
                logMessage('Photo query failed: Unknown error (code: ' . ($errorCode ?? 'N/A') . ')', 'ERROR');
            }
            return [];
        }
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
     * Make HTTP request using Guzzle
     */
    private function makeRequest($url, $params = [], $method = 'GET') {
        try {
            // Create Guzzle client with custom configuration
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'verify' => false, // Disable SSL verification like the original cURL code
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => 'PHP SynologyAPI',
                    'Accept' => 'application/json',
                ]
            ]);

            logMessage("Making $method request to: $url", 'DEBUG');

            // Make the request
            $options = [];
            if ($method === 'POST') {
                $options['form_params'] = $params;
            } elseif ($method === 'GET' && !empty($params)) {
                $options['query'] = $params;
            }

            $response = $client->request($method, $url, $options);

            // Get response body
            $body = $response->getBody()->getContents();

            // Check for successful response
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                logMessage("HTTP Error: $statusCode, Response: " . substr($body, 0, 200), 'ERROR');
                throw new Exception("HTTP Error: $statusCode");
            }

            // Parse JSON response
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                logMessage("JSON decode error: " . json_last_error_msg(), 'ERROR');
                throw new Exception("JSON decode error: " . json_last_error_msg());
            }

            return $decoded;

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg = $e->getMessage();
            logMessage("Guzzle Request Error: $errorMsg", 'ERROR');
            throw new Exception("Request Error: $errorMsg");
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $errorMsg = $e->getMessage();
            logMessage("Guzzle Connection Error: $errorMsg", 'ERROR');
            throw new Exception("Connection Error: $errorMsg");
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            logMessage("Guzzle Error: $errorMsg", 'ERROR');
            throw new Exception("HTTP Error: $errorMsg");
        }
    }

    /**
     * Download file
     */
    private function downloadFile($url) {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 60,
                'verify' => false,
                'allow_redirects' => true,
            ]);

            $response = $client->get($url);
            return $response->getBody()->getContents();

        } catch (\Exception $e) {
            logMessage("Download Error: " . $e->getMessage(), 'ERROR');
            return null;
        }
    }
}

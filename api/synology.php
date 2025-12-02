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
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            logMessage("CURL Error: $error", 'ERROR');
            return null;
        }
        
        if ($httpCode !== 200) {
            logMessage("HTTP Error: $httpCode", 'ERROR');
            return null;
        }
        
        return json_decode($response, true);
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

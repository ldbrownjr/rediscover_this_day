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
        if (!$this->sid && !$this->login()) {
            logMessage('Failed to login to Synology API', 'ERROR');
            return [];
        }
        
        $allPhotos = [];
        
        // We need to search year by year since the API doesn't support "any year"
        $currentYear = (int)date('Y');
        $startYear = $firstYear ? $firstYear : 2000;
        
        for ($year = $startYear; $year <= $currentYear; $year++) {
            $photos = $this->getPhotosForSpecificDate($year, $month, $day);
            if ($photos) {
                $allPhotos = array_merge($allPhotos, $photos);
            }
        }
        
        // Sort by capture date (oldest first)
        usort($allPhotos, function($a, $b) {
            return $a['time'] - $b['time'];
        });
        
        return $allPhotos;
    }
    
    /**
     * Get photos for a specific date
     */
    private function getPhotosForSpecificDate($year, $month, $day) {
        // Create start and end timestamps for the specific day
        $startTimestamp = mktime(0, 0, 0, $month, $day, $year);
        $endTimestamp = mktime(23, 59, 59, $month, $day, $year);
        
        $url = $this->baseUrl . '/photo/webapi/entry.cgi';
        
        $params = [
            'api' => 'SYNO.Foto.Browse.Item',
            'version' => '1',
            'method' => 'list',
            'offset' => '0',
            'limit' => '5000',
            '_sid' => $this->sid,
            'additional' => '["thumbnail","resolution","orientation"]',
            'start_time' => $startTimestamp,
            'end_time' => $endTimestamp
        ];
        
        $response = $this->makeRequest($url, $params);
        
        if ($response && isset($response['success']) && $response['success']) {
            return isset($response['data']['list']) ? $response['data']['list'] : [];
        }
        
        return [];
    }
    
    /**
     * Get photo thumbnail URL
     */
    public function getThumbnailUrl($photoId, $cacheKey, $size = 'xl') {
        return $this->baseUrl . '/photo/webapi/entry.cgi?' . http_build_query([
            'api' => 'SYNO.Foto.Thumbnail',
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
            'api' => 'SYNO.Foto.Download',
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

<?php
/**
 * Request Class
 * 
 * Helper class for handling HTTP requests
 */

class Request {
    /**
     * Get request method
     * 
     * @return string
     */
    public static function method() {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    /**
     * Check if request is POST
     * 
     * @return bool
     */
    public static function isPost() {
        return self::method() === 'POST';
    }
    
    /**
     * Check if request is GET
     * 
     * @return bool
     */
    public static function isGet() {
        return self::method() === 'GET';
    }
    
    /**
     * Get POST data
     * 
     * @param string $key Key to retrieve
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public static function post($key = null, $default = null) {
        if ($key === null) {
            return $_POST;
        }
        return $_POST[$key] ?? $default;
    }
    
    /**
     * Get GET data
     * 
     * @param string $key Key to retrieve
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public static function query($key = null, $default = null) {
        if ($key === null) {
            return $_GET;
        }
        return $_GET[$key] ?? $default;
    }
    
    /**
     * Get request data (POST or JSON body)
     * 
     * @param string $key Key to retrieve
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public static function input($key = null, $default = null) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
            if ($key === null) {
                return $data ?? [];
            }
            return $data[$key] ?? $default;
        }
        
        return self::post($key, $default);
    }
    
    /**
     * Get all request data
     * 
     * @return array
     */
    public static function all() {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
            return $data ?? [];
        }
        
        return array_merge($_GET, $_POST);
    }
    
    /**
     * Check if request has a specific key
     * 
     * @param string $key Key to check
     * @return bool
     */
    public static function has($key) {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }
    
    /**
     * Get file upload
     * 
     * @param string $key File input name
     * @return array|null
     */
    public static function file($key) {
        return $_FILES[$key] ?? null;
    }
    
    /**
     * Check if request is AJAX
     * 
     * @return bool
     */
    public static function isAjax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * Get request URI
     * 
     * @return string
     */
    public static function uri() {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }
    
    /**
     * Get client IP address
     * 
     * @return string
     */
    public static function ip() {
        $ip = $_SERVER['REMOTE_ADDR'];
        
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        
        return $ip;
    }
    
    /**
     * Validate required fields
     * 
     * @param array $fields Required fields
     * @return array Array of missing fields
     */
    public static function validateRequired($fields) {
        $data = self::all();
        $missing = [];
        
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $missing[] = $field;
            }
        }
        
        return $missing;
    }
}

<?php
/**
 * Security Configuration and Functions for Merrylay Enterprises
 * 
 * This file contains security-related configurations and utility functions
 * to prevent common vulnerabilities like XSS, CSRF, and SQL injection.
 */

// Prevent direct access
if (!defined('MERRYLAY_SYSTEM')) {
    exit('Direct access denied.');
}

/**
 * Security Configuration
 */
class SecurityConfig {
    // Session security
    const SESSION_LIFETIME = 3600; // 1 hour
    const SESSION_REGENERATE = true;
    
    // Password requirements
    const MIN_PASSWORD_LENGTH = 8;
    const REQUIRE_UPPERCASE = true;
    const REQUIRE_LOWERCASE = true;
    const REQUIRE_NUMBERS = true;
    const REQUIRE_SPECIAL_CHARS = true;
    
    // CSRF protection
    const CSRF_TOKEN_LIFETIME = 3600; // 1 hour
    
    // Rate limiting
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOGIN_LOCKOUT_TIME = 900; // 15 minutes
}

/**
 * Security Utility Functions
 */
class SecurityUtils {
    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token']) || 
            !isset($_SESSION['csrf_token_time']) || 
            time() - $_SESSION['csrf_token_time'] > SecurityConfig::CSRF_TOKEN_LIFETIME) {
            
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCSRFToken($token) {
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return false;
        }

        if (!isset($_SESSION['csrf_token']) || 
            !isset($_SESSION['csrf_token_time'])) {
            return false;
        }

        if (!is_string($_SESSION['csrf_token']) || !preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['csrf_token'])) {
            return false;
        }
        
        if (time() - $_SESSION['csrf_token_time'] > SecurityConfig::CSRF_TOKEN_LIFETIME) {
            unset($_SESSION['csrf_token']);
            unset($_SESSION['csrf_token_time']);
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Validate and consume a CSRF token in one operation
     */
    public static function consumeCSRFToken($token) {
        if (!self::validateCSRFToken($token)) {
            return false;
        }

        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return true;
    }
    
    /**
     * Sanitize input data
     */
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate email format
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate phone number (basic validation)
     */
    public static function validatePhone($phone) {
        return preg_match('/^[\d\s\-\+\(\)]+$/', $phone) && strlen(preg_replace('/\D/', '', $phone)) >= 10;
    }
    
    /**
     * Validate password strength
     */
    public static function validatePassword($password) {
        if (strlen($password) < SecurityConfig::MIN_PASSWORD_LENGTH) {
            return false;
        }
        
        if (SecurityConfig::REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            return false;
        }
        
        if (SecurityConfig::REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            return false;
        }
        
        if (SecurityConfig::REQUIRE_NUMBERS && !preg_match('/\d/', $password)) {
            return false;
        }
        
        if (SecurityConfig::REQUIRE_SPECIAL_CHARS && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Hash password securely
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3]);
    }
    
    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Generate secure random string
     */
    public static function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Check for rate limiting
     */
    public static function checkRateLimit($identifier, $max_attempts = SecurityConfig::MAX_LOGIN_ATTEMPTS, $lockout_time = SecurityConfig::LOGIN_LOCKOUT_TIME) {
        $cache_key = "rate_limit_" . md5($identifier);
        
        if (!isset($_SESSION[$cache_key])) {
            $_SESSION[$cache_key] = ['attempts' => 0, 'first_attempt' => time()];
        }
        
        $rate_data = $_SESSION[$cache_key];
        
        // Reset if lockout period has passed
        if (time() - $rate_data['first_attempt'] > $lockout_time) {
            $_SESSION[$cache_key] = ['attempts' => 0, 'first_attempt' => time()];
            return true;
        }
        
        // Check if limit exceeded
        if ($rate_data['attempts'] >= $max_attempts) {
            return false;
        }
        
        // Increment attempts
        $_SESSION[$cache_key]['attempts']++;
        return true;
    }
    
    /**
     * Get remaining lockout time
     */
    public static function getLockoutTime($identifier) {
        $cache_key = "rate_limit_" . md5($identifier);
        
        if (!isset($_SESSION[$cache_key])) {
            return 0;
        }
        
        $rate_data = $_SESSION[$cache_key];
        $remaining = SecurityConfig::LOGIN_LOCKOUT_TIME - (time() - $rate_data['first_attempt']);
        
        return max(0, $remaining);
    }
    
    /**
     * Validate numeric input
     */
    public static function validateNumeric($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return false;
        }
        
        $num = floatval($value);
        
        if ($min !== null && $num < $min) {
            return false;
        }
        
        if ($max !== null && $num > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate integer input
     */
    public static function validateInteger($value, $min = null, $max = null) {
        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            return false;
        }
        
        $int = intval($value);
        
        if ($min !== null && $int < $min) {
            return false;
        }
        
        if ($max !== null && $int > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Clean filename to prevent directory traversal
     */
    public static function cleanFilename($filename) {
        // Remove directory traversal attempts
        $filename = str_replace(['../', '..\\', '/', '\\'], '', $filename);
        
        // Remove null bytes
        $filename = str_replace("\0", '', $filename);
        
        // Only allow alphanumeric, dash, underscore, and dot
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    }
    
    /**
     * Validate input based on type
     */
    public static function validateInput($input, $type = 'general') {
        switch ($type) {
            case 'username':
                return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $input);
            case 'email':
                return self::validateEmail($input);
            case 'phone':
                return self::validatePhone($input);
            case 'numeric':
                return self::validateNumeric($input);
            case 'integer':
                return self::validateInteger($input);
            default:
                return !empty($input) && strlen($input) > 0;
        }
    }
    
    /**
     * Log security events
     */
    public static function logSecurityEvent($event_type, $details = []) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'event_type' => $event_type,
            'details' => $details
        ];
        
        error_log('SECURITY: ' . json_encode($log_entry));
    }
}

// Initialize security
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}

// Configure session parameters only before the application starts a session.
if (session_status() === PHP_SESSION_NONE && !defined('MERRYLAY_SESSION_CONFIGURED')) {
    ini_set('session.cookie_domain', '');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.cookie_lifetime', '86400');
    define('MERRYLAY_SESSION_CONFIGURED', true);
}


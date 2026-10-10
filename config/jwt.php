<?php
/**
 * JWT (JSON Web Token) Authentication Library
 * 
 * Provides JWT token generation, validation, and refresh functionality
 * for offline-first authentication in the Merrylay System
 */

if (!defined('JWT_SECRET_KEY')) {
    // Generate a secure secret key for JWT signing
    // In production, this should be stored in environment variables
    define('JWT_SECRET_KEY', 'merrylay_secure_jwt_secret_key_change_in_production_2024');
}

if (!defined('JWT_ALGORITHM')) {
    define('JWT_ALGORITHM', 'HS256');
}

if (!defined('JWT_ACCESS_TOKEN_EXPIRY')) {
    define('JWT_ACCESS_TOKEN_EXPIRY', 3600); // 1 hour
}

if (!defined('JWT_REFRESH_TOKEN_EXPIRY')) {
    define('JWT_REFRESH_TOKEN_EXPIRY', 604800); // 7 days
}

/**
 * JWT Manager Class
 */
class JWTManager {
    private $secretKey;
    private $algorithm;
    
    public function __construct() {
        $this->secretKey = JWT_SECRET_KEY;
        $this->algorithm = JWT_ALGORITHM;
    }
    
    /**
     * Generate JWT token
     * 
     * @param array $payload Token payload data
     * @param int $expiry Expiry time in seconds
     * @return string JWT token
     */
    public function generateToken($payload, $expiry = JWT_ACCESS_TOKEN_EXPIRY) {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ];
        
        $issuedAt = time();
        $expire = $issuedAt + $expiry;
        
        $tokenPayload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $expire
        ]);
        
        // Encode header
        $base64UrlHeader = $this->base64UrlEncode(json_encode($header));
        
        // Encode payload
        $base64UrlPayload = $this->base64UrlEncode(json_encode($tokenPayload));
        
        // Create signature
        $signature = $this->sign($base64UrlHeader . "." . $base64UrlPayload);
        
        // Create JWT
        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $signature;
        
        return $jwt;
    }
    
    /**
     * Generate access token for user
     * 
     * @param int $userId User ID
     * @param string $username Username
     * @param string $role User role
     * @param string $fullName User's full name
     * @return array Access and refresh tokens
     */
    public function generateUserTokens($userId, $username, $role, $fullName) {
        $payload = [
            'user_id' => $userId,
            'username' => $username,
            'role' => $role,
            'full_name' => $fullName,
            'type' => 'access'
        ];
        
        $accessToken = $this->generateToken($payload, JWT_ACCESS_TOKEN_EXPIRY);
        
        $refreshPayload = [
            'user_id' => $userId,
            'type' => 'refresh'
        ];
        
        $refreshToken = $this->generateToken($refreshPayload, JWT_REFRESH_TOKEN_EXPIRY);
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => JWT_ACCESS_TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    }
    
    /**
     * Validate JWT token
     * 
     * @param string $token JWT token
     * @return array|false Decoded payload or false if invalid
     */
    public function validateToken($token) {
        if (empty($token)) {
            return false;
        }
        
        // Extract token parts
        $tokenParts = explode('.', $token);
        if (count($tokenParts) !== 3) {
            return false;
        }
        
        list($base64UrlHeader, $base64UrlPayload, $signature) = $tokenParts;
        
        // Verify signature
        $expectedSignature = $this->sign($base64UrlHeader . "." . $base64UrlPayload);
        if ($signature !== $expectedSignature) {
            return false;
        }
        
        // Decode payload
        $payload = json_decode($this->base64UrlDecode($base64UrlPayload), true);
        
        if ($payload === null) {
            return false;
        }
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }
    
    /**
     * Refresh access token using refresh token
     * 
     * @param string $refreshToken Refresh token
     * @param array $userData Current user data
     * @return array|false New tokens or false if invalid
     */
    public function refreshToken($refreshToken, $userData) {
        $payload = $this->validateToken($refreshToken);
        
        if (!$payload || $payload['type'] !== 'refresh') {
            return false;
        }
        
        // Verify user ID matches
        if ($payload['user_id'] != $userData['id']) {
            return false;
        }
        
        // Generate new tokens
        return $this->generateUserTokens(
            $userData['id'],
            $userData['username'],
            $userData['role'],
            $userData['full_name']
        );
    }
    
    /**
     * Get user ID from token
     * 
     * @param string $token JWT token
     * @return int|false User ID or false if invalid
     */
    public function getUserIdFromToken($token) {
        $payload = $this->validateToken($token);
        
        if ($payload && isset($payload['user_id'])) {
            return (int)$payload['user_id'];
        }
        
        return false;
    }
    
    /**
     * Get user data from token
     * 
     * @param string $token JWT token
     * @return array|false User data or false if invalid
     */
    public function getUserDataFromToken($token) {
        $payload = $this->validateToken($token);
        
        if ($payload && $payload['type'] === 'access') {
            return [
                'user_id' => $payload['user_id'],
                'username' => $payload['username'],
                'role' => $payload['role'],
                'full_name' => $payload['full_name']
            ];
        }
        
        return false;
    }
    
    /**
     * Sign data with secret key
     * 
     * @param string $data Data to sign
     * @return string Signature
     */
    private function sign($data) {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->secretKey, true)
        );
    }
    
    /**
     * Base64 URL encode
     * 
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     * 
     * @param string $data Data to decode
     * @return string Decoded data
     */
    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

/**
 * Helper function to get JWT manager instance
 */
function getJWTManager() {
    static $instance = null;
    
    if ($instance === null) {
        $instance = new JWTManager();
    }
    
    return $instance;
}

/**
 * Get Bearer token from request headers
 */
function getBearerToken() {
    $headers = getallheaders();
    
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Validate JWT token from request
 */
function validateRequestToken() {
    $token = getBearerToken();
    
    if (!$token) {
        return false;
    }
    
    $jwt = getJWTManager();
    return $jwt->validateToken($token);
}

/**
 * Get current user from JWT token
 */
function getCurrentUserFromToken() {
    $token = getBearerToken();
    
    if (!$token) {
        return false;
    }
    
    $jwt = getJWTManager();
    return $jwt->getUserDataFromToken($token);
}

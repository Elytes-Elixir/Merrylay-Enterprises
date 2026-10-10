<?php
/**
 * API Endpoint for Token Refresh
 * Handles JWT token refresh for offline authentication
 */

header('Content-Type: application/json');

// Include required files
require_once '../../config/db.php';
require_once '../../config/jwt.php';

// Handle POST request for token refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['refresh_token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Refresh token required']);
        exit();
    }
    
    try {
        $jwt = getJWTManager();
        
        // Validate refresh token
        $payload = $jwt->validateToken($input['refresh_token']);
        
        if (!$payload || $payload['type'] !== 'refresh') {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired refresh token']);
            exit();
        }
        
        // Get user data from database
        $stmt = executeQuery("SELECT id, username, name, role FROM users WHERE id = ?", [$payload['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit();
        }
        
        // Generate new tokens
        $newTokens = $jwt->generateUserTokens(
            $user['id'],
            $user['username'],
            $user['role'],
            $user['name']
        );
        
        echo json_encode([
            'success' => true,
            'tokens' => $newTokens
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Token refresh failed: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

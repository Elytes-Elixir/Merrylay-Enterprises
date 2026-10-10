<?php
/**
 * API Endpoint for Users Data
 * Provides user data for offline preloading
 */

session_start();
require_once '../../config/db.php';
require_once '../../config/jwt.php';

header('Content-Type: application/json');

// Check if user is authenticated (support both session and JWT)
$user_id = null;

// Try JWT authentication first
$token = getBearerToken();
if ($token) {
    $jwt = getJWTManager();
    $userData = $jwt->getUserDataFromToken($token);
    if ($userData) {
        $user_id = $userData['user_id'];
    }
}

// Fallback to session authentication
if (!$user_id && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
}

// If still not authenticated, return unauthorized
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = getDBConnection();
        
        // Get all active users
        $stmt = $pdo->prepare("
            SELECT 
                id,
                username,
                name as full_name,
                email,
                phone,
                role,
                is_active
            FROM users
            WHERE is_active = 1
            ORDER BY name
        ");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $users,
            'count' => count($users),
            'timestamp' => time()
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch users: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

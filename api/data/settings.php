<?php
/**
 * API Endpoint for Settings Data
 * Provides system settings for offline preloading
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
        
        // Get all system settings
        $stmt = $pdo->prepare("
            SELECT 
                setting_key,
                setting_value,
                setting_type,
                description
            FROM system_settings
            ORDER BY setting_key
        ");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert to key-value object
        $settingsObj = [];
        foreach ($settings as $setting) {
            $settingsObj[$setting['setting_key']] = [
                'value' => $setting['setting_value'],
                'type' => $setting['setting_type'],
                'description' => $setting['description']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $settingsObj,
            'count' => count($settings),
            'timestamp' => time()
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch settings: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

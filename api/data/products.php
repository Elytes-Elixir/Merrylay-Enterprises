<?php
/**
 * API Endpoint for Products Data
 * Provides product data for offline preloading
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
        
        // Get all active products with stock information
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.name,
                p.sku,
                p.category_id,
                c.name as category_name,
                p.unit_price,
                p.cost_price,
                p.unit_size,
                p.unit_type,
                p.is_active,
                s.current_stock,
                s.minimum_stock,
                s.maximum_stock
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN stock s ON p.id = s.product_id
            WHERE p.is_active = 1
            ORDER BY p.name
        ");
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $products,
            'count' => count($products),
            'timestamp' => time()
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch products: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

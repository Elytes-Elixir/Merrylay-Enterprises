<?php
/**
 * API Endpoint for Syncing Sales Data
 * Handles offline sales data synchronization
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

// Handle POST request for syncing data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['data']) || !is_array($input['data'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data format']);
        exit();
    }

    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        $synced = 0;
        $errors = [];

        foreach ($input['data'] as $item) {
            $saleData = $item['data'];
            $timestamp = $item['timestamp'];

            // Validate required fields
            if (!isset($saleData['receipt_number']) || !isset($saleData['total_amount'])) {
                $errors[] = 'Missing required fields in sale data';
                continue;
            }

            // Check if sale already exists
            $stmt = $pdo->prepare("SELECT id FROM sales WHERE receipt_number = ?");
            $stmt->execute([$saleData['receipt_number']]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Sale already exists, skip
                continue;
            }

            // Insert sale
            $stmt = $pdo->prepare("
                INSERT INTO sales (receipt_number, customer_id, user_id, subtotal, discount_amount, tax_amount, total_amount, amount_paid, change_amount, payment_method, payment_status, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'completed', ?)
            ");
            $stmt->execute([
                $saleData['receipt_number'],
                $saleData['customer_id'] ?? null,
                $user_id,
                $saleData['subtotal'] ?? 0,
                $saleData['discount_amount'] ?? 0,
                $saleData['tax_amount'] ?? 0,
                $saleData['total_amount'],
                $saleData['amount_paid'] ?? $saleData['total_amount'],
                $saleData['change_amount'] ?? 0,
                $saleData['payment_method'] ?? 'cash',
                $timestamp
            ]);

            $sale_id = $pdo->lastInsertId();

            // Insert sale items if present
            if (isset($saleData['items']) && is_array($saleData['items'])) {
                foreach ($saleData['items'] as $itemData) {
                    $stmt = $pdo->prepare("
                        INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount_percent, discount_amount, price, cost_price, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $sale_id,
                        $itemData['product_id'],
                        $itemData['quantity'],
                        $itemData['unit_price'],
                        $itemData['discount_percent'] ?? 0,
                        $itemData['discount_amount'] ?? 0,
                        $itemData['price'],
                        $itemData['cost_price'] ?? 0,
                        $timestamp
                    ]);
                }
            }

            $synced++;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'synced' => $synced,
            'errors' => $errors
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Sync failed: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

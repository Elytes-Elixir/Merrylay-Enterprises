<?php
/**
 * API Endpoint for Syncing Dispatch Data
 * Handles offline dispatch data synchronization
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
            $dispatchData = $item['data'];
            $timestamp = $item['timestamp'];

            if (!isset($dispatchData['dispatch_number'])) {
                $errors[] = 'Missing dispatch number';
                continue;
            }

            $stmt = $pdo->prepare("SELECT id FROM dispatches WHERE dispatch_number = ?");
            $stmt->execute([$dispatchData['dispatch_number']]);
            $existing = $stmt->fetch();

            if ($existing) {
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO dispatches (dispatch_number, user_id, dispatcher_name, dispatch_type, status, shipping_address, tracking_number, carrier, estimated_delivery, expected_amount, actual_sales_amount, dispatch_date, notes, created_at)
                VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $dispatchData['dispatch_number'],
                $user_id,
                $dispatchData['dispatcher_name'],
                $dispatchData['dispatch_type'] ?? 'sale',
                $dispatchData['shipping_address'] ?? '',
                $dispatchData['tracking_number'] ?? '',
                $dispatchData['carrier'] ?? '',
                $dispatchData['estimated_delivery'] ?? null,
                $dispatchData['expected_amount'] ?? 0,
                $dispatchData['expected_amount'] ?? 0,
                $dispatchData['dispatch_date'] ?? date('Y-m-d'),
                $dispatchData['notes'] ?? '',
                $timestamp
            ]);

            $dispatch_id = $pdo->lastInsertId();

            if (isset($dispatchData['items']) && is_array($dispatchData['items'])) {
                foreach ($dispatchData['items'] as $itemData) {
                    $stmt = $pdo->prepare("
                        INSERT INTO dispatch_items (dispatch_id, product_id, quantity, unit_price, discount_amount, batch_number, expiry_date, notes, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, '', ?)
                    ");
                    $stmt->execute([
                        $dispatch_id,
                        $itemData['product_id'],
                        $itemData['quantity'],
                        $itemData['unit_price'],
                        $itemData['discount_amount'] ?? 0,
                        $itemData['batch_number'] ?? '',
                        $itemData['expiry_date'] ?? null,
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

<?php
/**
 * API Endpoint for Syncing Credit Settlements Data
 * Handles offline credit settlements data synchronization
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
            $settlementData = $item['data'];
            $timestamp = $item['timestamp'];

            if (!isset($settlementData['dispatch_id']) || !isset($settlementData['amount_collected'])) {
                $errors[] = 'Missing required fields in credit settlement data';
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO credit_settlements (dispatch_id, customer_id, customer_name, amount_collected, payment_method, reference_number, notes, collected_by, settlement_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $settlementData['dispatch_id'],
                $settlementData['customer_id'] ?? null,
                $settlementData['customer_name'] ?? '',
                $settlementData['amount_collected'],
                $settlementData['payment_method'] ?? 'cash',
                $settlementData['reference_number'] ?? '',
                $settlementData['notes'] ?? '',
                $user_id,
                $settlementData['settlement_date'] ?? date('Y-m-d'),
                $timestamp
            ]);

            // Update dispatch actual_sales_amount
            $stmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount + ? WHERE id = ?");
            $stmt->execute([$settlementData['amount_collected'], $settlementData['dispatch_id']]);

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

<?php
/**
 * API Endpoint for Syncing Returns Data
 * Handles offline returns data synchronization
 */

session_start();
require_once '../../config/db.php';
require_once '../../config/jwt.php';
require_once '../../config/stock_manager.php';

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
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Unable to start return sync transaction.');
        }

        $synced = 0;
        $errors = [];

        foreach ($input['data'] as $item) {
            if (!is_array($item) || !isset($item['data']) || !is_array($item['data'])) {
                $errors[] = 'Missing required fields in return data';
                continue;
            }
            $returnData = $item['data'];
            $dispatchId = filter_var($returnData['dispatch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $productId = filter_var($returnData['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantityValue = $returnData['quantity_returned'] ?? null;
            $condition = $returnData['condition'] ?? 'good';
            $returnDate = $returnData['return_date'] ?? date('Y-m-d');
            $timestamp = $item['timestamp'] ?? null;

            if ($dispatchId === false || $dispatchId === null || $productId === false || $productId === null
                || !is_scalar($quantityValue) || trim((string) $quantityValue) === '' || !is_numeric($quantityValue)
                || !is_string($condition) || !in_array($condition, ['good', 'damaged', 'expired', 'unsaleable'], true)
                || !is_string($returnDate)) {
                $errors[] = 'Invalid return data';
                continue;
            }
            $quantity = (float) $quantityValue;
            $returnDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $returnDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!is_finite($quantity) || $quantity <= 0 || $quantity > 999999.99
                || $returnDateObject === false || $returnDateObject->format('Y-m-d') !== $returnDate
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || !is_scalar($timestamp) || trim((string) $timestamp) === '') {
                $errors[] = 'Invalid return quantity, date, or sync timestamp';
                continue;
            }
            try {
                $createdAt = (new DateTimeImmutable((string) $timestamp))->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $errors[] = 'Invalid return sync timestamp';
                continue;
            }

            $dispatchStmt = $pdo->prepare('SELECT id, status, dispatch_date FROM dispatches WHERE id = ? FOR UPDATE');
            if (!$dispatchStmt->execute([$dispatchId])) {
                throw new RuntimeException('Dispatch validation failed during return sync.');
            }
            $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$dispatch || $dispatch['status'] === 'cancelled') {
                $errors[] = 'Return rejected: dispatch is missing or cancelled';
                continue;
            }
            $dispatchDate = (new DateTimeImmutable($dispatch['dispatch_date']))->format('Y-m-d');
            if ($returnDate < $dispatchDate || $returnDate > date('Y-m-d')) {
                $errors[] = 'Return rejected: return date is outside the dispatch period';
                continue;
            }

            $itemStmt = $pdo->prepare(
                'SELECT di.quantity, di.quantity_sold, p.name, p.is_active
                 FROM dispatch_items di JOIN products p ON p.id = di.product_id
                 WHERE di.dispatch_id = ? AND di.product_id = ? FOR UPDATE'
            );
            if (!$itemStmt->execute([$dispatchId, $productId])) {
                throw new RuntimeException('Dispatch product validation failed during return sync.');
            }
            $dispatchItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$dispatchItem || !(bool) $dispatchItem['is_active']) {
                $errors[] = 'Return rejected: product is not on the dispatch or is inactive';
                continue;
            }

            $duplicateStmt = $pdo->prepare(
                'SELECT id FROM dispatch_returns
                 WHERE dispatch_id = ? AND product_id = ? AND quantity_returned = ?
                   AND `condition` = ? AND recorded_by = ? AND created_at = ?
                 LIMIT 1 FOR UPDATE'
            );
            if (!$duplicateStmt->execute([$dispatchId, $productId, $quantity, $condition, $user_id, $createdAt])) {
                throw new RuntimeException('Return retry validation failed during sync.');
            }
            if ($duplicateStmt->fetchColumn() !== false) {
                $errors[] = 'Duplicate return sync item ignored';
                continue;
            }

            $dispatchReturnsStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity_returned), 0) FROM dispatch_returns
                 WHERE dispatch_id = ? AND product_id = ? AND quantity_returned > 0'
            );
            $dailyReturnsStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity), 0) FROM daily_returns
                 WHERE dispatch_id = ? AND product_id = ? AND quantity > 0'
            );
            $damageStmt = $pdo->prepare(
                'SELECT damaged_products_note FROM daily_activities
                 WHERE dispatch_id = ? AND damaged_products > 0'
            );
            if (!$dispatchReturnsStmt->execute([$dispatchId, $productId])
                || !$dailyReturnsStmt->execute([$dispatchId, $productId])
                || !$damageStmt->execute([$dispatchId])) {
                throw new RuntimeException('Previous return validation failed during sync.');
            }
            $recordedDamage = 0.0;
            $productName = strtolower(trim((string) $dispatchItem['name']));
            foreach ($damageStmt->fetchAll(PDO::FETCH_ASSOC) as $damageRow) {
                $note = trim($damageRow['damaged_products_note'] ?? '');
                if (preg_match('/Damaged\s*([\d.,]+)\s*units?\s*of\s*(.+)$/i', $note, $matches)) {
                    $noteProductName = strtolower(trim(rtrim($matches[2], '. ')));
                    if ($noteProductName === $productName
                        || strpos($productName, $noteProductName) !== false
                        || strpos($noteProductName, $productName) !== false) {
                        $recordedDamage += (float) str_replace(',', '', $matches[1]);
                    }
                }
            }
            $remaining = (float) $dispatchItem['quantity']
                - (float) $dispatchItem['quantity_sold']
                - (float) $dispatchReturnsStmt->fetchColumn()
                - (float) $dailyReturnsStmt->fetchColumn()
                - $recordedDamage;
            if (!is_finite($remaining) || $remaining < 0 || $quantity > $remaining) {
                $errors[] = 'Return rejected: quantity exceeds the unaccounted dispatch quantity';
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO dispatch_returns (dispatch_id, product_id, quantity_returned, return_reason, `condition`, notes, recorded_by, return_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt->execute([
                $dispatchId,
                $productId,
                $quantity,
                is_scalar($returnData['return_reason'] ?? '') ? (string) ($returnData['return_reason'] ?? '') : '',
                $condition,
                is_scalar($returnData['notes'] ?? '') ? (string) ($returnData['notes'] ?? '') : '',
                $user_id,
                $returnDate,
                $createdAt
            ]) || $stmt->rowCount() !== 1) {
                throw new RuntimeException('Return record could not be saved during sync.');
            }
            $return_record_id = $pdo->lastInsertId();
            if (!$return_record_id) {
                throw new RuntimeException('Return record ID was not generated during sync.');
            }

            // Restore stock using centralized stock management if condition is good
            if ($condition === 'good' && !recordReturnMovement(
                    $return_record_id,
                    $productId,
                    $quantity,
                    'manual',
                    $user_id,
                    $returnDate,
                    'Dispatch return'
                )) {
                throw new RuntimeException('Return stock movement failed during sync.');
            }

            $synced++;
        }

        if (!$pdo->commit()) {
            throw new RuntimeException('Return sync transaction could not be committed.');
        }

        echo json_encode([
            'success' => true,
            'synced' => $synced,
            'errors' => $errors
        ]);

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Return sync failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Sync failed. No records were saved.']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

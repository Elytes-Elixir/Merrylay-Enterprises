<?php
/**
 * Delete Daily Activity
 *
 * Allows admin to delete daily activity records with proper cleanup of related data.
 * This is for error correction only - requires admin permissions.
 */

if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';
require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';
require_once '../../config/cache.php';
require_once '../../config/stock_manager.php';

// Only admin can delete records
if (!hasRole(['admin'])) {
    header('Location: index.php?error=unauthorized');
    exit();
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($requestMethod !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('This request method is not allowed.');
}

$rawActivityId = $_POST['id'] ?? '';
$activity_id = is_scalar($rawActivityId) && preg_match('/^[1-9][0-9]*$/D', (string) $rawActivityId)
    ? filter_var((string) $rawActivityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
    : false;
$from = isset($_POST['from']) && $_POST['from'] === 'today-sales' ? 'today-sales' : '';

if ($activity_id === false) {
    http_response_code(400);
    exit('A valid activity identifier is required.');
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
    http_response_code(403);
    exit('This form has expired. Refresh the page and try again.');
}

try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();

    // Get the activity details before deletion
    $stmt = $pdo->prepare("
        SELECT da.*
        FROM daily_activities da
        WHERE da.id = ?
    ");
    $stmt->execute([$activity_id]);
    $activity = $stmt->fetch();

    if (!$activity) {
        throw new Exception('Activity record not found');
    }

    // Store values for cleanup
    $driver_id = $activity['driver_id'];
    $driver_name = $activity['driver_name'];
    $dispatch_id = $activity['dispatch_id'];
    $activity_date = $activity['activity_date'];
    $cash_collected = floatval($activity['cash_collected'] ?? 0);
    $transfer_amount = floatval($activity['transfer_amount'] ?? 0);
    $credit_amount = floatval($activity['credit_amount'] ?? 0);
    $credit_settlement = floatval($activity['credit_settlement'] ?? 0);
    $credit_customer_name = $activity['credit_customer_name'];

    // 1. Delete associated daily returns and restore stock
    $stmt = $pdo->prepare("
        SELECT id, product_id, quantity, return_date
        FROM daily_returns
        WHERE driver_id = ? AND activity_date = ?
        FOR UPDATE
    ");
    if ($dispatch_id) {
        $stmt = $pdo->prepare("
            SELECT id, product_id, quantity, return_date
            FROM daily_returns
            WHERE driver_id = ? AND dispatch_id = ? AND return_date = ?
            FOR UPDATE
        ");
        if (!$stmt->execute([$driver_id, $dispatch_id, $activity_date])) {
            throw new RuntimeException('Activity return lookup failed.');
        }
    } else {
        if (!$stmt->execute([$driver_id, $activity_date])) {
            throw new RuntimeException('Activity return lookup failed.');
        }
    }
    $returns = $stmt->fetchAll();

    foreach ($returns as $return) {
        $movementStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity_change), 0)
             FROM stock_movements
             WHERE product_id = ? AND movement_type = 'return'
               AND reference_type = 'manual' AND reference_id = ?
               AND reference_number = ?
             FOR UPDATE"
        );
        if (!$movementStmt->execute([
            $return['product_id'],
            $return['id'],
            'Return #' . (int) $return['id']
        ])) {
            throw new RuntimeException('Return movement validation failed.');
        }
        $recordedMovement = (float) $movementStmt->fetchColumn();
        if (abs($recordedMovement - (float) $return['quantity']) > 0.0001) {
            throw new RuntimeException('Return movement history does not reconcile; no changes were made.');
        }

        $deleteReturnStmt = $pdo->prepare("DELETE FROM daily_returns WHERE id = ?");
        if (!$deleteReturnStmt->execute([$return['id']]) || $deleteReturnStmt->rowCount() !== 1) {
            throw new RuntimeException('Return record could not be deleted.');
        }
        if (!recordReturnMovement(
            $return['id'],
            $return['product_id'],
            -(float) $return['quantity'],
            'manual',
            $_SESSION['user_id'],
            $return['return_date'],
            'Return reversal'
        )) {
            throw new RuntimeException('Return stock movement could not be reversed.');
        }
    }

    // 2. Remove dispatch actual_sales_amount contribution
    if ($dispatch_id) {
        $tripTotal = $cash_collected + $transfer_amount + $credit_amount;
        if ($tripTotal > 0) {
            $dispatchUpdateStmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount - ? WHERE id = ?");
            $dispatchUpdateStmt->execute([$tripTotal, $dispatch_id]);
        }
    }

    // 3. Handle creditor record cleanup
    if ($credit_amount > 0 && !empty($credit_customer_name)) {
        // Reduce or remove creditor record
        $checkStmt = $pdo->prepare("
            SELECT id, credit_amount, amount_paid, balance
            FROM creditors
            WHERE creditor_name = ? AND driver_id = ? AND status = 'active'
            LIMIT 1
        ");
        $checkStmt->execute([$credit_customer_name, $driver_id]);
        $existingCreditor = $checkStmt->fetch();

        if ($existingCreditor) {
            $new_credit_amount = $existingCreditor['credit_amount'] - $credit_amount;
            $new_balance = $existingCreditor['balance'] - $credit_amount;

            if ($new_credit_amount <= 0) {
                // Remove creditor record if no more credit and no remittances exist.
                $remittanceCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM creditor_remittances WHERE creditor_id = ?");
                $remittanceCheckStmt->execute([$existingCreditor['id']]);
                $hasRemittances = $remittanceCheckStmt->fetchColumn() > 0;

                if ($hasRemittances) {
                    // Keep the creditor record for referential integrity, but clear its balance.
                    $updateStmt = $pdo->prepare(
                        "UPDATE creditors SET credit_amount = 0, balance = 0, status = 'cleared', updated_at = NOW() WHERE id = ?"
                    );
                    $updateStmt->execute([$existingCreditor['id']]);
                } else {
                    $deleteCreditorStmt = $pdo->prepare("DELETE FROM creditors WHERE id = ?");
                    $deleteCreditorStmt->execute([$existingCreditor['id']]);
                }
            } else {
                // Update creditor record
                $updateStmt = $pdo->prepare("
                    UPDATE creditors
                    SET credit_amount = ?, balance = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$new_credit_amount, max(0, $new_balance), $existingCreditor['id']]);
            }
        }
    }

    // 4. Delete any related daily activity credit breakdown rows
    try {
        $pdo->prepare("DELETE FROM daily_activity_credits WHERE daily_activity_id = ?")->execute([$activity_id]);
    } catch (PDOException $e) {
        error_log("daily_activity_credits delete skipped: " . $e->getMessage());
        if (!in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
            throw $e;
        }
    }

    // 5. Delete any customer payments associated with this trip record
    if (!empty($dispatch_id)) {
        $dispatchNumberStmt = $pdo->prepare("SELECT dispatch_number FROM dispatches WHERE id = ? LIMIT 1");
        $dispatchNumberStmt->execute([$dispatch_id]);
        $dispatchRow = $dispatchNumberStmt->fetch();

        if ($dispatchRow) {
            $dispatchNumber = $dispatchRow['dispatch_number'];
            $paymentDeleteStmt = $pdo->prepare("DELETE FROM customer_payments WHERE dispatcher_name = ? AND notes LIKE CONCAT('%trip #', ?, '%')");
            $paymentDeleteStmt->execute([$driver_name, $dispatchNumber]);
        }
    }

    // 6. Delete the daily activity record
    $deleteStmt = $pdo->prepare("DELETE FROM daily_activities WHERE id = ?");
    $deleteStmt->execute([$activity_id]);

    $pdo->commit();
    cache_clear();

    $_SESSION['success_message'] = 'Activity record deleted successfully. Related data has been cleaned up.';
    $redirectUrl = $from === 'today-sales' ? 'today-sales.php' : 'index.php';
    header('Location: ' . $redirectUrl, true, 303);
    exit();

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Delete activity error: " . $e->getMessage());
    http_response_code(500);
    exit('Unable to delete this activity record. Please try again.');
}

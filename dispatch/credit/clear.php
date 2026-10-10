<?php
/**
 * Clear Credit
 * 
 * Mark a credit as fully cleared when balance is zero.
 */

require_once __DIR__ . '/../../config/session.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('This action must be submitted using the confirmation form.');
}

if (!hasRole(['admin', 'manager', 'cashier'])) {
    error_log('Unauthorized creditor clear attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to clear creditors.');
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
    error_log('Rejected creditor clear request: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('Your form has expired. Return to the creditor and try again.');
}

$creditor_id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$creditor_id) {
    header('Location: list.php');
    exit();
}

try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    // Verify creditor exists and balance is zero or fully paid
    $stmt = $pdo->prepare("SELECT * FROM creditors WHERE id = ? AND status = 'active' FOR UPDATE");
    $stmt->execute([$creditor_id]);
    $creditor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$creditor) {
        $pdo->rollBack();
        header('Location: list.php?error=not_found');
        exit();
    }
    
    if ($creditor['balance'] > 0) {
        $pdo->rollBack();
        header('Location: list.php?error=balance_remaining');
        exit();
    }
    
    // Mark as cleared
    $update = $pdo->prepare("UPDATE creditors SET status = 'cleared', cleared_at = NOW() WHERE id = ?");
    $update->execute([$creditor_id]);
    $pdo->commit();
    
    header('Location: list.php?success=cleared');
    exit();
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Creditor clear failed: ' . $e->getMessage());
    header('Location: list.php?error=clear_failed');
    exit();
}

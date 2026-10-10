<?php
/**
 * Delete Product Handler for Merrylay Enterprises
 * 
 * Handles product deletion with proper validation
 */

// Start session
require_once __DIR__ . '/../config/session.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once __DIR__ . '/../config/security.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration
require_once '../config/db.php';
require_once '../config/auth.php';

if (!hasRole(['admin', 'manager'])) {
    error_log('Unauthorized product deletion attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to delete products.');
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: view.php');
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
    error_log('Rejected product deletion: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
    $_SESSION['error'] = 'Your form has expired. Please try again.';
    header('Location: view.php');
    exit();
}

// Get product ID from POST
$product_id = $_POST['product_id'] ?? '';

if (empty($product_id)) {
    $_SESSION['error'] = 'Product ID is required.';
    header('Location: view.php');
    exit();
}

try {
    // Check if product exists
    $stmt = executeQuery("SELECT id, name FROM products WHERE id = ?", [$product_id]);
    $product = $stmt->fetch();
    
    if (!$product) {
        $_SESSION['error'] = 'Product not found.';
        header('Location: view.php');
        exit();
    }
    
    // Check if product is referenced in daily returns or dispatches
    $stmt = executeQuery("SELECT COUNT(*) as count FROM daily_returns WHERE product_id = ?", [$product_id]);
    $returns_count = $stmt->fetch()['count'];
    
    $stmt = executeQuery("SELECT COUNT(*) as count FROM dispatch_items WHERE product_id = ?", [$product_id]);
    $dispatch_count = $stmt->fetch()['count'];
    
    if ($returns_count > 0 || $dispatch_count > 0) {
        $_SESSION['error'] = 'Cannot delete product. It is referenced in daily returns (' . $returns_count . ' returns, ' . $dispatch_count . ' dispatches). Consider archiving instead.';
        header('Location: view.php');
        exit();
    }
    
    // Delete the product
    $stmt = executeQuery("DELETE FROM products WHERE id = ?", [$product_id]);
    
    $_SESSION['success'] = 'Product "' . htmlspecialchars($product['name']) . '" has been deleted successfully.';
    header('Location: view.php');
    exit();
    
} catch (PDOException $e) {
    error_log("Delete product error: " . $e->getMessage());
    $_SESSION['error'] = 'Failed to delete product. Please try again.';
    header('Location: view.php');
    exit();
}
?>


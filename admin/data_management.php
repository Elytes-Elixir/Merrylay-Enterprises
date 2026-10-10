<?php
/**
 * Data Management - Clear All Data
 * Admin only feature for testing/reset purposes
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard/index.php');
    exit();
}

require_once '../config/db.php';

$error = '';
// Browser-based resets are disabled. Any future reset must use a separately audited
// break-glass maintenance process outside this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    $error = 'Browser-based data resets are disabled. No data was changed.';
    error_log('Rejected browser data-management POST for user ' . (int) ($_SESSION['user_id'] ?? 0));
}

// Get current data counts for display
$data_counts = [];
try {
    $pdo = getDBConnection();
    $tables = [
        'dispatches' => 'Dispatches',
        'dispatch_items' => 'Dispatch Items',
        'daily_activities' => 'Daily Activities',
        'daily_returns' => 'Daily Returns',
        'bank_deposits' => 'Bank Deposits',
        'creditors' => 'Creditors',
        'creditor_remittances' => 'Credit Remittances',
        'credit_settlements' => 'Credit Settlements',
        'customer_payments' => 'Customer Payments',
        'products' => 'Products',
        'stock_movements' => 'Stock Movements',
        'price_history' => 'Price History',
        'vehicles' => 'Vehicles',
        'drivers' => 'Drivers',
        'daily_stock' => 'Daily Stock',
        'dispatch_returns' => 'Trip Returns',
        'supplies' => 'Supplies',
        'supply_items' => 'Supply Items',
        'shop_sales' => 'Shop Sales',
        'shop_sales_items' => 'Shop Sales Items',
        'shop_collections' => 'Shop Collections'
    ];

    foreach ($tables as $table => $label) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
            $result = $stmt->fetch();
            $data_counts[$label] = $result['count'];
        } catch (PDOException $e) {
            // Table doesn't exist, skip it
            $data_counts[$label] = 'Table not found';
        }
    }
} catch (Exception $e) {
    $data_counts = ['Error' => 'Could not retrieve counts: ' . $e->getMessage()];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Management - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <style>
        .danger-zone { border: 2px solid #dc3545; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .danger-zone h4 { color: #dc3545; }
        .data-count { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 10px; }
        .count-number { font-size: 1.5rem; font-weight: bold; color: #495057; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-database me-2"></i>Data Management</h2>
                        <p class="mb-0">Clear all operational data or reset records for a specific day.</p>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <h4>Current Data Overview</h4>
                            <div class="row">
                                <?php foreach ($data_counts as $label => $count): ?>
                                    <div class="col-md-6">
                                        <div class="data-count">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span><?php echo htmlspecialchars($label); ?>:</span>
                                                <span class="count-number"><?php echo htmlspecialchars($count); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="alert alert-warning" role="status">
                            Browser-based resets are disabled. Any future reset must be a separately audited break-glass maintenance process.
                        </div>

                        <div class="mt-4">
                            <a href="../dashboard/index.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


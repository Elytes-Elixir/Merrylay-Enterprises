<?php
/**
 * Void Dispatch Page for Merrylay Enterprises
 */

if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';
require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/cache.php';
require_once '../config/stock_manager.php';

// Only admin can void dispatches
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hasRole(['admin'])) {
    error_log('Unauthorized dispatch void attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to void dispatches.');
}
requireRole(['admin'], '../dispatch/trips/list.php');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($requestMethod !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('This request method is not allowed.');
}

$requestData = $_POST;
$rawDispatchId = $requestData['id'] ?? '';
$dispatch_id = is_scalar($rawDispatchId) && preg_match('/^[1-9][0-9]*$/D', (string) $rawDispatchId)
    ? filter_var((string) $rawDispatchId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
    : false;
$from = isset($requestData['from']) && in_array($requestData['from'], ['trips', 'detail'], true) ? $requestData['from'] : '';
$error = '';
$success = '';

if ($dispatch_id === false) {
    http_response_code(400);
    exit('A valid dispatch identifier is required.');
}

if ($requestMethod === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (($_POST['confirm_void'] ?? '') !== '1'
        || !is_string($csrfToken)
        || !SecurityUtils::consumeCSRFToken($csrfToken)) {
        http_response_code(403);
        exit('This form has expired or is invalid. Refresh the page and try again.');
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Get dispatch details for confirmation with specific columns
try {
    $stmt = executeQuery("SELECT id, dispatch_number, dispatcher_name, dispatch_date, expected_amount, actual_sales_amount, status FROM dispatches WHERE id = ?", [$dispatch_id]);
    $dispatch = $stmt->fetch();
    
    if (!$dispatch) {
        $redirectUrl = $from === 'trips' ? '../dispatch/trips/list.php' : 'daily.php';
        header("Location: {$redirectUrl}");
        exit();
    }
} catch (PDOException $e) {
    error_log("Get dispatch error: " . $e->getMessage());
    $error = 'Unable to load dispatch details.';
    $dispatch = null;
}

// Handle void confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_void'])) {
    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();
        $dispatchStmt = $pdo->prepare("SELECT id, dispatch_number, dispatch_date, status FROM dispatches WHERE id = ? FOR UPDATE");
        $dispatchStmt->execute([$dispatch_id]);
        $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dispatch || $dispatch['status'] === 'cancelled') {
            throw new RuntimeException('This dispatch cannot be voided. Refresh the page and review its current status.');
        }

        $itemsStmt = $pdo->prepare("SELECT product_id, quantity, quantity_sold FROM dispatch_items WHERE dispatch_id = ? FOR UPDATE");
        $itemsStmt->execute([$dispatch_id]);
        $quantities = [];
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (float) $item['quantity'];
            $sold = (float) $item['quantity_sold'];
            if ($quantity <= 0 || $sold < 0 || $sold > $quantity) {
                throw new RuntimeException('Dispatch quantities are inconsistent; no changes were made.');
            }
            $quantities[$productId] = $quantities[$productId] ?? [
                'dispatched' => 0.0,
                'sold' => 0.0,
                'good_returns' => 0.0,
                'daily_returns' => 0.0,
                'damaged' => 0.0,
                'dispatch_movement' => 0.0,
                'return_movement' => 0.0,
                'damage_movement' => 0.0,
            ];
            $quantities[$productId]['dispatched'] += $quantity;
            $quantities[$productId]['sold'] += $sold;
        }
        if (!$quantities) {
            throw new RuntimeException('Dispatch items are missing; no changes were made.');
        }

        $dispatchReturnsStmt = $pdo->prepare("SELECT id, product_id, quantity_returned, `condition` FROM dispatch_returns WHERE dispatch_id = ? FOR UPDATE");
        if (!$dispatchReturnsStmt->execute([$dispatch_id])) {
            throw new RuntimeException('Dispatch return lookup failed; no changes were made.');
        }
        $dispatchReturnRows = $dispatchReturnsStmt->fetchAll(PDO::FETCH_ASSOC);
        $dailyReturnsStmt = $pdo->prepare("SELECT id, product_id, quantity FROM daily_returns WHERE dispatch_id = ? FOR UPDATE");
        if (!$dailyReturnsStmt->execute([$dispatch_id])) {
            throw new RuntimeException('Daily return lookup failed; no changes were made.');
        }
        $dailyReturnRows = $dailyReturnsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dispatchReturnRows as $return) {
            $productId = (int) $return['product_id'];
            $quantity = (float) $return['quantity_returned'];
            if (!isset($quantities[$productId]) || $quantity < 0) {
                throw new RuntimeException('Return quantities are inconsistent; no changes were made.');
            }
            if ($return['condition'] === 'good') {
                $quantities[$productId]['good_returns'] += $quantity;
            } else {
                $quantities[$productId]['damaged'] += $quantity;
            }
        }
        foreach ($dailyReturnRows as $return) {
            $productId = (int) $return['product_id'];
            $quantity = (float) $return['quantity'];
            if (!isset($quantities[$productId]) || $quantity < 0) {
                throw new RuntimeException('Daily return quantities are inconsistent; no changes were made.');
            }
            $quantities[$productId]['daily_returns'] += $quantity;
        }

        $activityStmt = $pdo->prepare("SELECT COUNT(*) FROM daily_activities WHERE dispatch_id = ?");
        if (!$activityStmt->execute([$dispatch_id])) {
            throw new RuntimeException('Dispatch activity lookup failed; no changes were made.');
        }
        if ((int) $activityStmt->fetchColumn() > 0) {
            throw new RuntimeException('This dispatch has recorded sales activity and requires manual inventory review. No changes were made.');
        }

        $movementStmt = $pdo->prepare("SELECT product_id, movement_type, quantity_change FROM stock_movements WHERE reference_type = 'dispatch' AND reference_id = ? FOR UPDATE");
        if (!$movementStmt->execute([$dispatch_id])) {
            throw new RuntimeException('Dispatch movement lookup failed; no changes were made.');
        }
        foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) as $movement) {
            $productId = (int) $movement['product_id'];
            if (!isset($quantities[$productId])) {
                throw new RuntimeException('Dispatch movements do not match its items; no changes were made.');
            }
            $change = (float) $movement['quantity_change'];
            if ($movement['movement_type'] === 'dispatch') {
                $quantities[$productId]['dispatch_movement'] += $change;
            } elseif ($movement['movement_type'] === 'return' && $change >= 0) {
                $quantities[$productId]['return_movement'] += $change;
            } elseif ($movement['movement_type'] === 'damage' && $change <= 0) {
                $quantities[$productId]['damage_movement'] += abs($change);
            } else {
                throw new RuntimeException('Dispatch movement history is ambiguous; no changes were made.');
            }
        }

        $returnMovementStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity_change), 0) FROM stock_movements WHERE movement_type = 'return' AND ((reference_type = 'dispatch_return' AND reference_id = ?) OR (reference_type = 'manual' AND reference_number = ?))");
        $damageReturnMovementStmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(quantity_change)), 0) FROM stock_movements WHERE movement_type = 'damage' AND reference_type = 'dispatch_return' AND reference_id = ?");
        foreach ($dispatchReturnRows as $return) {
            if (!$returnMovementStmt->execute([(int) $return['id'], 'Dispatch return #' . (int) $return['id']])) {
                throw new RuntimeException('Dispatch return movement lookup failed; no changes were made.');
            }
            $movementQuantity = (float) $returnMovementStmt->fetchColumn();
            $expectedMovement = $return['condition'] === 'good' ? (float) $return['quantity_returned'] : 0.0;
            if (abs($movementQuantity - $expectedMovement) > 0.0001) {
                throw new RuntimeException('Dispatch return movement history is incomplete; no changes were made.');
            }
            if ($expectedMovement > 0) {
                $productId = (int) $return['product_id'];
                $quantities[$productId]['return_movement'] += $movementQuantity;
            } else {
                if (!$damageReturnMovementStmt->execute([(int) $return['id']])) {
                    throw new RuntimeException('Damaged return movement lookup failed; no changes were made.');
                }
                if ((float) $damageReturnMovementStmt->fetchColumn() > 0.0001) {
                    throw new RuntimeException('Damaged return movement history is ambiguous; no changes were made.');
                }
            }
        }
        $dailyMovementStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity_change), 0) FROM stock_movements WHERE movement_type = 'return' AND reference_type = 'manual' AND reference_id = ? AND reference_number = ?");
        foreach ($dailyReturnRows as $return) {
            if (!$dailyMovementStmt->execute([(int) $return['id'], 'Return #' . (int) $return['id']])) {
                throw new RuntimeException('Daily return movement lookup failed; no changes were made.');
            }
            $movementQuantity = (float) $dailyMovementStmt->fetchColumn();
            if (abs($movementQuantity - (float) $return['quantity']) > 0.0001) {
                throw new RuntimeException('Daily return movement history is incomplete; no changes were made.');
            }
            $productId = (int) $return['product_id'];
            $quantities[$productId]['return_movement'] += $movementQuantity;
        }

        $voidStmt = $pdo->prepare("UPDATE dispatches SET status = 'cancelled' WHERE id = ? AND status <> 'cancelled'");
        if (!$voidStmt->execute([$dispatch_id])) {
            throw new RuntimeException('Dispatch status could not be changed; no changes were made.');
        }
        if ($voidStmt->rowCount() !== 1) {
            throw new RuntimeException('Dispatch status changed during voiding; no changes were made.');
        }

        $stockManager = new StockManager($pdo);
        foreach ($quantities as $productId => $productQuantities) {
            $dispatched = $productQuantities['dispatched'];
            $dispatchMovement = $productQuantities['dispatch_movement'];
            if (abs($dispatchMovement + $dispatched) > 0.0001) {
                throw new RuntimeException('Original dispatch movement history is incomplete; no changes were made.');
            }
            if ($productQuantities['good_returns'] > 0 && $productQuantities['daily_returns'] > 0) {
                throw new RuntimeException('Return records overlap across workflows and require manual stock review. No changes were made.');
            }
            $recordedReturns = $productQuantities['good_returns'] + $productQuantities['daily_returns'];
            if (abs($productQuantities['return_movement'] - $recordedReturns) > 0.0001) {
                throw new RuntimeException('Return movement history does not reconcile; no changes were made.');
            }
            if ($productQuantities['damage_movement'] > 0.0001) {
                throw new RuntimeException('Damage movement history is ambiguous; no changes were made.');
            }
            $remaining = $dispatched
                - $productQuantities['sold']
                - $productQuantities['good_returns']
                - $productQuantities['daily_returns']
                - $productQuantities['damaged'];
            if ($remaining < -0.0001) {
                throw new RuntimeException('Dispatch sales, returns, and damages exceed dispatched stock. No changes were made.');
            }
            if ($remaining > 0.0001 && !$stockManager->recordMovement(
                $productId,
                'return',
                $remaining,
                'system',
                $dispatch_id,
                'Dispatch ' . $dispatch['dispatch_number'],
                'Void compensation for dispatch ' . $dispatch['dispatch_number'],
                (int) $_SESSION['user_id'],
                date('Y-m-d', strtotime($dispatch['dispatch_date']))
            )) {
                throw new RuntimeException('Stock compensation failed; no changes were made.');
            }
        }

        $pdo->commit();
        cache_clear();

        $redirectUrl = $from === 'trips'
            ? 'trips/list.php?voided=1&payment_review=1'
            : 'dispatch_detail.php?id=' . (int) $dispatch_id . '&voided=1&payment_review=1';
        header('Location: ' . $redirectUrl, true, 303);
        exit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Void dispatch error: " . $e->getMessage());
        $error = 'Unable to void this dispatch safely. Inventory records could not be reconciled; no changes were made.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Void Dispatch - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }
        
        .header-section {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15);
        }
        
        .header-section h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }
        
        .header-section p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }
        
        .delete-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border-left: 4px solid #ef4444;
        }
        
        .delete-card h5 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #6b7280;
            font-weight: 600;
        }
        
        .info-value {
            color: #1f2937;
            font-weight: 700;
        }
        
        .warning-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .warning-box h6 {
            color: #dc2626;
            margin-bottom: 0.5rem;
        }
        
        .warning-box ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }
        
        .warning-box li {
            color: #991b1b;
            margin-bottom: 0.25rem;
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }
        
        .btn-delete:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../dashboard/index.php" class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <span class="logo-text">Merrylay Enterprises</span>
                </a>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
                        <?php
            $dispatcherSidebarPath = __DIR__ . '/components/dispatcher-sidebar.php';
            $searchDir = __DIR__;
            while (!file_exists($dispatcherSidebarPath) && dirname($searchDir) !== $searchDir) {
                $searchDir = dirname($searchDir);
                $dispatcherSidebarPath = $searchDir . '/components/dispatcher-sidebar.php';
            }
            include $dispatcherSidebarPath;
            ?>
        </aside>

        <!-- Main content -->
        <main class="main-content" id="mainContent">
            <div class="container-fluid py-4">
                <!-- Header -->
                <div class="header-section">
                    <h1><i class="fas fa-ban me-2"></i>Void Dispatch</h1>
                    <p>Mark this dispatch as cancelled while preserving its audit history</p>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($dispatch && ($dispatch['status'] ?? '') === 'cancelled'): ?>
                    <div class="alert alert-info">This dispatch is already cancelled and cannot be voided again.</div>
                <?php elseif ($dispatch): ?>
                    <!-- Void Confirmation Card -->
                    <div class="delete-card">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>Confirm Void</h5>
                        
                        <div class="warning-box">
                            <h6><i class="fas fa-exclamation-circle me-2"></i>Inventory will be restored only if its history reconciles safely.</h6>
                            <ul>
                                <li>Dispatch items, returns, activities, credits, expenses, settlements, and stock movements will be retained.</li>
                                <li>Payments will not be changed. Legacy payments without a reliable dispatch link require manual review.</li>
                                <li>If stock movements cannot be reconciled, the void will be rejected without changes.</li>
                            </ul>
                        </div>

                        <h6 class="mb-3">Dispatch Details</h6>
                        <div class="info-row">
                            <span class="info-label">Dispatch Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($dispatch['dispatch_number']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Dispatcher</span>
                            <span class="info-value"><?php echo htmlspecialchars($dispatch['dispatcher_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Date</span>
                            <span class="info-value"><?php echo date('d/m/Y', strtotime($dispatch['dispatch_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Expected Amount</span>
                            <span class="info-value">₦<?php echo number_format($dispatch['expected_amount'], 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="info-value"><?php echo ucfirst($dispatch['status'] ?? 'N/A'); ?></span>
                        </div>

                        <form method="POST" action="" class="mt-4">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $dispatch_id); ?>">
                            <input type="hidden" name="from" value="<?php echo htmlspecialchars($from); ?>">
                            <input type="hidden" name="confirm_void" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-delete">
                                    <i class="fas fa-ban me-2"></i>Void Dispatch
                                </button>
                                <?php
                                $cancelUrl = $from === 'trips' ? 'trips/list.php' : ($from === 'detail' ? 'dispatch_detail.php?id=' . (int) $dispatch_id : 'daily.php');
                                ?>
                                <a href="<?php echo $cancelUrl; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                <?php elseif (empty($dispatch)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Dispatch not found.
                        <?php
                        $returnUrl = $from === 'trips' ? 'trips/list.php' : 'daily.php';
                        ?>
                        <a href="<?php echo $returnUrl; ?>" class="btn btn-primary mt-3">Return to List</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


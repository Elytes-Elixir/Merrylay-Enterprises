<?php
/**
 * Record Dispatch Return Page
 * 
 * Handles recording of returned products from dispatches
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../init.php';
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/cache.php';
require_once '../config/stock_manager.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

if (!canManageDispatches()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized dispatch return mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to record dispatch returns.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

const MAX_DISPATCH_RETURN_QUANTITY = 999999.99;

$dispatch_id = $_GET['dispatch_id'] ?? null;
$error = '';
$success = $_SESSION['dispatch_return_success'] ?? '';
unset($_SESSION['dispatch_return_success']);

if (!$dispatch_id) {
    header('Location: daily.php');
    exit();
}

// Get dispatch details with specific columns
try {
    $stmt = executeQuery("SELECT id, dispatch_number, dispatcher_name, dispatch_date, expected_amount, actual_sales_amount, status FROM dispatches WHERE id = ?", [$dispatch_id]);
    $dispatch = $stmt->fetch();
    
    if (!$dispatch) {
        $error = 'Dispatch not found.';
    } elseif (($dispatch['status'] ?? '') === 'cancelled') {
        $error = 'This dispatch is cancelled and cannot receive new returns.';
    } else {
        // Get dispatch items
        $stmt = executeQuery("
            SELECT di.*, p.name as product_name, p.unit
            FROM dispatch_items di
            LEFT JOIN products p ON di.product_id = p.id
            WHERE di.dispatch_id = ?
        ", [$dispatch_id]);
        $dispatch_items = $stmt->fetchAll();
        
        // Get existing returns
        $stmt = executeQuery("
            SELECT dr.*, p.name as product_name
            FROM dispatch_returns dr
            LEFT JOIN products p ON dr.product_id = p.id
            WHERE dr.dispatch_id = ?
            ORDER BY dr.created_at DESC
        ", [$dispatch_id]);
        $existing_returns = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log("Get dispatch error: " . $e->getMessage());
    $error = 'Unable to load dispatch details.';
}

// This workflow intentionally writes dispatch_returns only; daily_returns remains separate.
// Process return submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $pdo = getDBConnection();

    try {
        $csrfToken = $_POST['csrf_token'] ?? null;
        if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
            throw new InvalidArgumentException('Invalid security token. Please refresh the page and try again.');
        }

        $postedProductId = $_POST['product_id'] ?? null;
        $product_id = filter_var($postedProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantityValue = $_POST['quantity_returned'] ?? null;
        $return_reason = trim((string) ($_POST['return_reason'] ?? ''));
        $condition = $_POST['condition'] ?? 'good';
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($product_id === false || $product_id === null) {
            throw new InvalidArgumentException('Please select a valid product.');
        }
        if (!is_scalar($quantityValue) || trim((string) $quantityValue) === '' || !is_numeric($quantityValue)) {
            throw new InvalidArgumentException('Return quantity must be a valid number.');
        }
        $quantity_returned = (float) $quantityValue;
        if (!is_finite($quantity_returned) || $quantity_returned <= 0) {
            throw new InvalidArgumentException('Return quantity must be greater than 0.');
        }
        if ($quantity_returned > MAX_DISPATCH_RETURN_QUANTITY) {
            throw new InvalidArgumentException('Return quantity exceeds the permitted maximum.');
        }
        if (!in_array($condition, ['good', 'damaged', 'expired', 'unsaleable'], true)) {
            throw new InvalidArgumentException('Return condition is invalid.');
        }

        $pdo->beginTransaction();

        $dispatchStmt = $pdo->prepare("SELECT id, status FROM dispatches WHERE id = ? FOR UPDATE");
        if (!$dispatchStmt->execute([$dispatch_id])) {
            throw new RuntimeException('Dispatch validation failed.');
        }
        $lockedDispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedDispatch) {
            throw new InvalidArgumentException('Dispatch not found.');
        }
        if ($lockedDispatch['status'] === 'cancelled') {
            throw new InvalidArgumentException('This dispatch is cancelled and cannot receive new returns.');
        }

        $itemStmt = $pdo->prepare(
            "SELECT di.quantity, di.quantity_sold, p.is_active, p.name AS product_name
             FROM dispatch_items di
             JOIN products p ON p.id = di.product_id
             WHERE di.dispatch_id = ? AND di.product_id = ?
             FOR UPDATE"
        );
        if (!$itemStmt->execute([$dispatch_id, $product_id])) {
            throw new RuntimeException('Dispatch item validation failed.');
        }
        $dispatchItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dispatchItem) {
            throw new InvalidArgumentException('The selected product is not part of this dispatch.');
        }
        if (!(bool) $dispatchItem['is_active']) {
            throw new InvalidArgumentException('The selected product is inactive.');
        }

        $returnsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity_returned), 0)
             FROM dispatch_returns
             WHERE dispatch_id = ? AND product_id = ?
             AND quantity_returned > 0"
        );
        if (!$returnsStmt->execute([$dispatch_id, $product_id])) {
            throw new RuntimeException('Previous return validation failed.');
        }
        $previousReturns = (float) $returnsStmt->fetchColumn();
        $dailyReturnsStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(quantity), 0)
             FROM daily_returns
             WHERE dispatch_id = ? AND product_id = ? AND quantity > 0'
        );
        if (!$dailyReturnsStmt->execute([$dispatch_id, $product_id])) {
            throw new RuntimeException('Existing trip-return validation failed.');
        }
        $dailyReturns = (float) $dailyReturnsStmt->fetchColumn();

        $damageStmt = $pdo->prepare(
            'SELECT damaged_products_note
             FROM daily_activities
             WHERE dispatch_id = ? AND damaged_products > 0'
        );
        if (!$damageStmt->execute([$dispatch_id])) {
            throw new RuntimeException('Existing trip-damage validation failed.');
        }
        $recordedDamage = 0.0;
        $productName = strtolower(trim((string) $dispatchItem['product_name']));
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
        $remainingUnsold = (float) $dispatchItem['quantity']
            - (float) $dispatchItem['quantity_sold']
            - $previousReturns
            - $dailyReturns
            - $recordedDamage;
        if ($remainingUnsold < 0 || $quantity_returned > $remainingUnsold) {
            throw new InvalidArgumentException('Return quantity exceeds the remaining unsold quantity.');
        }

        $returnStmt = $pdo->prepare(
            "INSERT INTO dispatch_returns
             (dispatch_id, product_id, quantity_returned, return_reason, `condition`, notes, recorded_by, return_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$returnStmt->execute([
            $dispatch_id,
            $product_id,
            $quantity_returned,
            $return_reason,
            $condition,
            $notes,
            $_SESSION['user_id']
        ]) || $returnStmt->rowCount() !== 1) {
            throw new RuntimeException('Return record was not saved.');
        }
        $return_record_id = $pdo->lastInsertId();
        if (!$return_record_id) {
            throw new RuntimeException('Return record ID was not generated.');
        }

        // Damaged or unsaleable goods are accounted for, but are not restored to stock.
        if ($condition === 'good' && !recordReturnMovement(
            $return_record_id,
            $product_id,
            $quantity_returned,
            'manual',
            $_SESSION['user_id'],
            null,
            'Dispatch return'
        )) {
            throw new RuntimeException('Stock restoration failed.');
        }

        $pdo->commit();
        cache_clear();
        $_SESSION['dispatch_return_success'] = 'Return recorded successfully!';
        header('Location: record_return.php?dispatch_id=' . urlencode((string) $dispatch_id));
        exit();
        header("Refresh:2; url=record_return.php?dispatch_id=" . rawurlencode((string) $dispatch_id));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Record return error: " . $e->getMessage());
        $error = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'Unable to record return. Please try again.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Return - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }

        .container {
            margin-top: 2rem;
        }

        .card {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border: none;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
            border: none;
        }

        .form-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .returns-table {
            background: white;
            border-radius: 12px;
            overflow: visible;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .returns-table table {
            margin: 0;
        }

        .returns-table thead {
            background: #f3f4f6;
            border-bottom: 2px solid #e5e7eb;
        }

        .returns-table th {
            font-weight: 600;
            color: #374151;
            padding: 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
        }

        .returns-table td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #e5e7eb;
        }

        .returns-table tbody tr:hover {
            background: #f9fafb;
        }

        .condition-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .condition-good {
            background: #d1fae5;
            color: #065f46;
        }

        .condition-damaged {
            background: #fee2e2;
            color: #991b1b;
        }

        .condition-expired {
            background: #fed7aa;
            color: #92400e;
        }

        .condition-unsaleable {
            background: #e0e7ff;
            color: #3730a3;
        }

        .dispatch-info {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .dispatch-info h5 {
            color: #0c4a6e;
            margin-bottom: 1rem;
        }

        .dispatch-info p {
            margin: 0.5rem 0;
            color: #1e40af;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
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

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="container">
                <div class="row">
                    <div class="col-lg-8 offset-lg-2">
                <!-- Back Button -->
                <a href="daily.php" class="btn btn-outline-secondary mb-3">
                    <i class="fas fa-arrow-left me-2"></i>Back to Daily Records
                </a>

                <!-- Dispatch Info -->
                <?php if (!empty($dispatch)): ?>
                <div class="dispatch-info">
                    <h5><i class="fas fa-truck-moving me-2"></i>Dispatch Details</h5>
                    <p><strong>Dispatch #:</strong> <?php echo htmlspecialchars($dispatch['dispatch_number']); ?></p>
                    <p><strong>Dispatcher:</strong> <?php echo htmlspecialchars($dispatch['dispatcher_name']); ?></p>
                    <p><strong>Expected Amount:</strong> ₦<?php echo number_format($dispatch['expected_amount'], 2); ?></p>
                    <p><strong>Actual Sales:</strong> ₦<?php echo number_format($dispatch['actual_sales_amount'], 2); ?></p>
                </div>
                <?php endif; ?>

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

                <!-- Record Return Form -->
                <div class="form-section">
                    <h5 class="mb-4"><i class="fas fa-reply me-2"></i>Record Return</h5>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="returnProductId">Product <span class="text-danger">*</span></label>
                                <select class="form-select" name="product_id" id="returnProductId" required>
                                    <option value="">Select Product</option>
                                    <?php foreach ($dispatch_items as $item): ?>
                                    <option value="<?php echo $item['product_id']; ?>">
                                        <?php echo htmlspecialchars($item['product_name']); ?> 
                                        (Available: <?php echo $item['quantity']; ?> <?php echo $item['unit']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="returnQuantity">Quantity Returned <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="quantity_returned" id="returnQuantity" min="0.25" step="0.25" placeholder="0" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="returnCondition">Condition</label>
                                <select class="form-select" name="condition" id="returnCondition">
                                    <option value="good">Good</option>
                                    <option value="damaged">Damaged</option>
                                    <option value="expired">Expired</option>
                                    <option value="unsaleable">Unsaleable</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="returnReason">Return Reason</label>
                                <input type="text" class="form-control" name="return_reason" id="returnReason" placeholder="e.g., Customer returned, didn't sell">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="returnNotes">Notes</label>
                            <textarea class="form-control" name="notes" id="returnNotes" rows="3" placeholder="Additional notes about the return"></textarea>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary btn-action">
                                <i class="fas fa-check me-2"></i>Record Return
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Existing Returns -->
                <?php if (count($existing_returns) > 0): ?>
                <div class="returns-table">
                    <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Condition</th>
                                <th>Reason</th>
                                <th>Recorded On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existing_returns as $return): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($return['product_name']); ?></strong></td>
                                <td><?php echo $return['quantity_returned']; ?></td>
                                <td>
                                    <span class="condition-badge condition-<?php echo strtolower($return['condition']); ?>">
                                        <?php echo ucfirst($return['condition']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($return['return_reason']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($return['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


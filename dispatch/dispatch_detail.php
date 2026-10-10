<?php
/**
 * Dispatch Detail Page
 * 
 * Shows complete dispatch information including:
 * - Loaded items
 * - Expected vs actual sales
 * - Returned products
 * - Balance reconciliation
 */

require_once __DIR__ . '/../config/session.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
$error = '';
$success = '';
$voidDispatchCsrfToken = hasRole(['admin']) ? SecurityUtils::generateCSRFToken() : '';
$canUpdateDispatchSales = canManageFinancials();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canUpdateDispatchSales) {
    error_log('Unauthorized dispatch sales update attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to update dispatch sales.');
}
$salesCsrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $salesCsrfToken = $_POST['csrf_token'] ?? null;
    $salesCsrfValid = is_string($salesCsrfToken) && SecurityUtils::consumeCSRFToken($salesCsrfToken);
    if (!$salesCsrfValid) {
        error_log('Rejected dispatch sales update: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the sales amount and submit again.';
    }
}

$dispatch_id = $_GET['id'] ?? null;

if (!$dispatch_id) {
    header('Location: daily.php');
    exit();
}

// Get dispatch details
try {
    $stmt = executeQuery("
        SELECT d.*,
               u.name as supervisor_name,
               v.vehicle_number,
               v.vehicle_type,
               v.make,
               v.model,
               v.driver_name,
               v.driver_phone
        FROM dispatches d
        LEFT JOIN users u ON d.dispatcher_id = u.id
        LEFT JOIN vehicles v ON d.vehicle_id = v.id
        WHERE d.id = ?
    ", [$dispatch_id]);
    $dispatch = $stmt->fetch();
    
    if (!$dispatch) {
        header('Location: daily.php');
        exit();
    }
    
    // Get dispatch items
    $stmt = executeQuery("
        SELECT di.*,
               p.name as product_name,
               p.unit
        FROM dispatch_items di
        LEFT JOIN products p ON di.product_id = p.id
        WHERE di.dispatch_id = ?
        ORDER BY di.created_at
    ", [$dispatch_id]);
    $dispatch_items = $stmt->fetchAll();
    
    // Calculate total discount
    $total_discount = 0;
    foreach ($dispatch_items as $item) {
        $total_discount += ($item['discount_amount'] ?? 0);
    }
    
    // Get dispatch returns
    $stmt = executeQuery("
        SELECT dr.*,
               p.name as product_name,
               u.name as recorded_by_name
        FROM dispatch_returns dr
        LEFT JOIN products p ON dr.product_id = p.id
        LEFT JOIN users u ON dr.recorded_by = u.id
        WHERE dr.dispatch_id = ?
        ORDER BY dr.created_at DESC
    ", [$dispatch_id]);
    $dispatch_returns = $stmt->fetchAll();
    
    // Calculate totals
    $total_loaded = 0;
    $total_returned = 0;
    foreach ($dispatch_items as $item) {
        $total_loaded += $item['quantity'];
    }
    foreach ($dispatch_returns as $return) {
        $total_returned += $return['quantity_returned'];
    }
    $net_items = $total_loaded - $total_returned;
    
} catch (PDOException $e) {
    error_log("Get dispatch detail error: " . $e->getMessage());
    $error = 'Unable to load dispatch details.';
}

// Handle update of actual sales amount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['update_sales']) && $salesCsrfValid && $canUpdateDispatchSales) {
    $actual_sales = floatval($_POST['actual_sales_amount'] ?? 0);
    
    if ($actual_sales < 0) {
        $error = 'Actual sales amount cannot be negative.';
    } else {
        try {
            $query = "UPDATE dispatches SET actual_sales_amount = ? WHERE id = ?";
            executeQuery($query, [$actual_sales, $dispatch_id]);
            $success = 'Sales amount updated successfully!';
            
            // Reload dispatch data with specific columns
            $stmt = executeQuery("
                SELECT d.*,
                       u.name as supervisor_name,
                       v.vehicle_number,
                       v.vehicle_type,
                       v.make,
                       v.model,
                       v.driver_name,
                       v.driver_phone
                FROM dispatches d
                LEFT JOIN users u ON d.dispatcher_id = u.id
                LEFT JOIN vehicles v ON d.vehicle_id = v.id
                WHERE d.id = ?
            ", [$dispatch_id]);
            $dispatch = $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Update sales error: " . $e->getMessage());
            $error = 'Unable to update sales amount.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Details - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }

        .container {
            margin-top: 2rem;
        }

        .header-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }

        .header-card h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
        }

        .info-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .info-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            border-left: 4px solid #667eea;
            padding-left: 1rem;
        }

        .info-label {
            font-size: 0.875rem;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.25rem;
            color: #1e293b;
            font-weight: 600;
        }

        .items-table,
        .returns-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        table {
            margin: 0;
        }

        table thead {
            background: #f3f4f6;
            border-bottom: 2px solid #e5e7eb;
        }

        table th {
            font-weight: 600;
            color: #374151;
            padding: 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #e5e7eb;
        }

        table tbody tr:hover {
            background: #f9fafb;
        }

        .summary-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-item {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .summary-item.loaded {
            border-left-color: #667eea;
        }

        .summary-item.returned {
            border-left-color: #f59e0b;
        }

        .summary-item.net {
            border-left-color: #10b981;
        }

        .summary-item.balance {
            border-left-color: #3b82f6;
        }

        .summary-item-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
        }

        .summary-item-value {
            font-size: 1.75rem;
            color: #1e293b;
            font-weight: 700;
        }

        .balance-positive {
            color: #10b981;
        }

        .balance-negative {
            color: #ef4444;
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

        .form-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .btn-group-custom {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn-action {
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
                    <div class="col-lg-12">
                <!-- Back Button -->
                <a href="daily.php" class="btn btn-outline-secondary mb-3">
                    <i class="fas fa-arrow-left me-2"></i>Back to Daily Records
                </a>

                <!-- Header -->
                <div class="header-card">
                    <h1><i class="fas fa-box-open me-2"></i>Dispatch Details</h1>
                    <p class="mb-0 mt-2">Complete record of dispatch #<?php echo htmlspecialchars($dispatch['dispatch_number']); ?></p>
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

                <!-- Basic Information -->
                <div class="info-card">
                    <h5 class="mb-4"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                    <div class="info-row">
                        <div class="info-item">
                            <div class="info-label">Dispatch Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($dispatch['dispatch_number']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Dispatcher Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($dispatch['dispatcher_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Vehicle</div>
                            <div class="info-value">
                                <?php if (!empty($dispatch['vehicle_number'])): ?>
                                    <span class="badge bg-info">
                                        <i class="fas fa-truck me-1"></i><?php echo htmlspecialchars($dispatch['vehicle_number']); ?>
                                    </span>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($dispatch['make'] . ' ' . $dispatch['model']); ?></small>
                                    <small class="text-muted"><?php echo htmlspecialchars($dispatch['driver_name'] ?? 'No driver'); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Supervisor</div>
                            <div class="info-value"><?php echo htmlspecialchars($dispatch['supervisor_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Dispatch Date</div>
                            <div class="info-value"><?php echo date('M d, Y', strtotime($dispatch['dispatch_date'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="status-badge status-<?php echo strtolower($dispatch['status']); ?>">
                                    <?php echo ucfirst($dispatch['status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Type</div>
                            <div class="info-value"><?php echo ucfirst($dispatch['dispatch_type']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="summary-section">
                    <div class="summary-item loaded">
                        <div class="summary-item-label"><i class="fas fa-boxes me-2"></i>Total Quantity Loaded</div>
                        <div class="summary-item-value"><?php echo $total_loaded; ?></div>
                    </div>
                    <div class="summary-item returned">
                        <div class="summary-item-label"><i class="fas fa-reply me-2"></i>Total Returned</div>
                        <div class="summary-item-value"><?php echo $total_returned; ?></div>
                    
                <!-- Expected vs Actual Sales -->
                <div class="form-section">
                    <h5 class="mb-4"><i class="fas fa-cash-register me-2"></i>Sales Information</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Expected Amount</label>
                            <div class="form-control-plaintext" style="font-size: 1.5rem; font-weight: 700; color: #f59e0b;">
                                ₦<?php echo number_format($dispatch['expected_amount'], 2); ?>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Actual Sales</label>
                            <div class="form-control-plaintext" style="font-size: 1.5rem; font-weight: 700; color: #10b981;">
                                ₦<?php echo number_format($dispatch['actual_sales_amount'], 2); ?>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Difference</label>
                            <div class="form-control-plaintext" style="font-size: 1.5rem; font-weight: 700; color: <?php echo ($dispatch['actual_sales_amount'] - $dispatch['expected_amount']) >= 0 ? '#10b981' : '#ef4444'; ?>;">
                                ₦<?php echo number_format($dispatch['actual_sales_amount'] - $dispatch['expected_amount'], 2); ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($dispatch['status'] !== 'delivered' && $canUpdateDispatchSales): ?>
                    <hr class="my-3">
                    <h6 class="mb-3">Update Actual Sales Amount</h6>
                    <form method="POST" action="">
                        <input type="hidden" name="update_sales" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text">₦</span>
                                    <input type="number" class="form-control" name="actual_sales_amount" 
                                           value="<?php echo $dispatch['actual_sales_amount']; ?>" 
                                           step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary">Update</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Loaded Items -->
                <div class="items-table">
                    <div style="padding: 1.5rem;">
                        <h5 class="mb-3"><i class="fas fa-cubes me-2"></i>Items Loaded</h5>
                    </div>
                    <?php if (count($dispatch_items) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Unit</th>
                                <th>Unit Price</th>
                                <th>Discount</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dispatch_items as $item): 
                                // Use full unit price (without discount) - discounts are tracked separately for account balancing
                                $subtotal = $item['quantity'] * $item['unit_price'];
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td>₦<?php echo number_format($item['unit_price'], 2); ?></td>
                                <td style="color: #ef4444;">-₦<?php echo number_format($item['discount_amount'] ?? 0, 2); ?></td>
                                <td><strong>₦<?php echo number_format($subtotal, 2); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background: #f3f4f6; font-weight: 700;">
                                <td colspan="4" class="text-end">Total Discount:</td>
                                <td style="color: #ef4444;">-₦<?php echo number_format($total_discount, 2); ?></td>
                                <td><strong>₦<?php echo number_format($dispatch['expected_amount'], 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-boxes"></i>
                        <p>No items loaded for this dispatch</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Returns -->
                <?php if (count($dispatch_returns) > 0): ?>
                <div class="returns-table">
                    <div style="padding: 1.5rem;">
                        <h5 class="mb-3"><i class="fas fa-reply me-2"></i>Returned Products</h5>
                    </div>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Condition</th>
                                <th>Reason</th>
                                <th>Recorded By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dispatch_returns as $return): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($return['product_name']); ?></strong></td>
                                <td><?php echo $return['quantity_returned']; ?></td>
                                <td>
                                    <span class="condition-badge condition-<?php echo strtolower($return['condition']); ?>">
                                        <?php echo ucfirst($return['condition']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($return['return_reason'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($return['recorded_by_name'] ?? 'N/A'); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($return['return_date'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="btn-group-custom">
                    <?php if (($_GET['voided'] ?? '') === '1'): ?>
                    <div class="alert alert-success w-100" role="alert">
                        Dispatch voided. Existing payment records were left untouched and require manual review because some legacy payments are not reliably linked to a dispatch.
                    </div>
                    <?php endif; ?>
                    <a href="daily.php" class="btn btn-secondary btn-action">
                        <i class="fas fa-arrow-left me-2"></i>Back to Records
                    </a>
                    <?php if (($dispatch['status'] ?? '') !== 'cancelled'): ?>
                    <a href="daily/record.php" class="btn btn-primary btn-action">
                        <i class="fas fa-calendar-day me-2"></i>Record Daily Sales
                    </a>
                    <?php endif; ?>
                    <a href="credit/index.php" class="btn btn-info btn-action">
                        <i class="fas fa-hand-holding-usd me-2"></i>Credit Management
                    </a>
                    <?php if (hasRole(['admin', 'manager']) && ($dispatch['status'] ?? '') !== 'cancelled'): ?>
                    <a href="edit_dispatch.php?id=<?php echo $dispatch_id; ?>" class="btn btn-warning btn-action">
                        <i class="fas fa-edit me-2"></i>Edit Dispatch
                    </a>
                    <?php endif; ?>
                    <a href="dispatch_balance_report.php?dispatch_id=<?php echo $dispatch_id; ?>" class="btn btn-outline-primary btn-action">
                        <i class="fas fa-balance-scale me-2"></i>View Balance
                    </a>
                    <?php if (hasRole(['admin']) && ($dispatch['status'] ?? '') !== 'cancelled'): ?>
                    <form method="POST" action="delete_dispatch.php" class="d-inline" onsubmit="return confirm('Void this dispatch? Its records will be retained. Legacy payment records will require manual review.')">
                        <input type="hidden" name="id" value="<?php echo (int) $dispatch_id; ?>">
                        <input type="hidden" name="from" value="detail">
                        <input type="hidden" name="confirm_void" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($voidDispatchCsrfToken); ?>">
                        <button type="submit" class="btn btn-danger btn-action">
                            <i class="fas fa-ban me-2"></i>Void Dispatch
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


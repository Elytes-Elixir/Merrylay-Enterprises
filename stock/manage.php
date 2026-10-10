<?php
/**
 * Clean Stock Management Page for Merrylay Enterprises
 *
 * Handles opening stock initialization, stock movements tracking, and closing stock
 * with modern, clean design consistent with the dashboard and product pages
 */

// Start session
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration and stock manager
require_once '../config/db.php';
require_once '../config/stock_manager.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

if (!hasRole(['admin', 'manager', 'dispatcher'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized stock management mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to manage stock.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

// Initialize stock manager
$stockManager = getStockManager();

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$error = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected stock management mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the stock details and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Check for success messages
if (isset($_GET['success'])) {
    $successDate = $_GET['date'] ?? date('Y-m-d');
    $formattedDate = date('M d, Y', strtotime($successDate));
    switch ($_GET['success']) {
        case 'stock_initialized':
            $message = "Daily stock initialized successfully for " . $formattedDate;
            break;
        case 'stock_closed':
            $message = "Daily stock closed successfully for " . $formattedDate;
            break;
    }
}

// Check for redirect error from dispatch creation
if (isset($_GET['error']) && $_GET['error'] === 'stock_not_initialized') {
    $error = 'Daily stock must be initialized before creating dispatches. Please initialize today\'s stock first.';
    $error_type = 'warning';
    $error_title = 'Stock Not Initialized';
    $error_action = 'initialize_stock';
} elseif (isset($_GET['error']) && $_GET['error'] === 'stock_already_closed') {
    $error = 'Today\'s stock has already been closed. No further changes can be made today.';
    $error_type = 'info';
    $error_title = 'Stock Already Closed';
    $error_action = null;
} elseif (isset($_GET['error']) && $_GET['error'] === 'insufficient_stock') {
    $error = 'Insufficient stock available for this operation. Please check stock levels and try again.';
    $error_type = 'danger';
    $error_title = 'Insufficient Stock';
    $error_action = null;
} else {
    $error_type = $error ? 'danger' : null;
    $error_title = $error ? 'Error' : null;
    $error_action = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    $action = $_POST['action'] ?? '';
    $success = false;

    try {
        switch ($action) {
            case 'initialize_stock':
                if (!hasRole(['admin', 'manager'])) {
                    http_response_code(403);
                    throw new RuntimeException('Only admins and managers can initialize daily stock.');
                }
                $date = $_POST['stock_date'] ?? date('Y-m-d');
                if ($stockManager->initializeDailyStock($date)) {
                    $success = true;
                    header('Location: manage.php?success=stock_initialized&date=' . urlencode($date));
                    exit();
                } else {
                    $error = "Failed to initialize daily stock.";
                }
                break;

            case 'close_stock':
                if (!hasRole(['admin', 'manager'])) {
                    error_log('Unauthorized stock close attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
                    http_response_code(403);
                    $error = 'You do not have permission to close stock.';
                    break;
                }
                $date = $_POST['stock_date'] ?? date('Y-m-d');
                if ($stockManager->closeDailyStock($date)) {
                    $success = true;
                    header('Location: manage.php?success=stock_closed&date=' . urlencode($date));
                    exit();
                } else {
                    $error = "Failed to close daily stock.";
                }
                break;

        }
    } catch (Exception $e) {
        error_log('Stock management action failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof DomainException
            ? $e->getMessage()
            : 'Unable to complete the stock operation. No stock changes were committed.';
    }

    // Only reach here if there's an error
    if ($error && !$success) {
        // Error will be displayed on the refreshed page
    }
}

// Get current date and check if stock is initialized
$today = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $today;
$isInitialized = $stockManager->isDailyStockInitialized($selectedDate);

// Get stock report for selected date
$dailyReport = $stockManager->getDailyStockReport($selectedDate);

// Get daily stock records directly (shows opening/closing even without movements)
try {
    $stmt = executeQuery("
        SELECT ds.*, p.name as product_name, p.sku, p.unit
        FROM daily_stock ds
        JOIN products p ON ds.product_id = p.id
        WHERE ds.stock_date = ?
        ORDER BY p.name
    ", [$selectedDate]);
    $dailyStockRecords = $stmt->fetchAll();
} catch (Exception $e) {
    $dailyStockRecords = [];
}

$totalClosingStockAfterDispatch = 0;
foreach ($dailyStockRecords as $record) {
    $totalClosingStockAfterDispatch += floatval($record['closing_stock']);
}

// Get recent stock movements (last 7 days from selected date)
$recentMovements = $stockManager->getStockMovements(date('Y-m-d', strtotime($selectedDate . ' -7 days')), $selectedDate);

// Get movement summary for selected date
try {
    $stmt = executeQuery("
        SELECT
            movement_type,
            SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) as positive_quantity,
            SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as negative_quantity,
            SUM(quantity_change) as net_quantity
        FROM stock_movements
        WHERE DATE(movement_date) = ?
        GROUP BY movement_type
    ", [$selectedDate]);
    $movementSummary = $stmt->fetchAll();
} catch (Exception $e) {
    $movementSummary = [];
}

// Get supplies for selected date
try {
    $stmt = executeQuery("
        SELECT s.*, COUNT(si.id) as item_count
        FROM supplies s
        LEFT JOIN supply_items si ON s.id = si.supply_id
        WHERE s.supply_date = ?
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ", [$selectedDate]);
    $todaySupplies = $stmt->fetchAll();
} catch (Exception $e) {
    $todaySupplies = [];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage daily stock and track movements">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Stock Management - Merrylay Enterprises</title>
    
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/fonts/inter/inter.css" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/unified-design-system.css" rel="stylesheet">
    <style>
        /* Stock-specific styles */
        .stock-status-card {
            background: linear-gradient(135deg, var(--bg-primary) 0%, rgba(248, 250, 252, 0.8) 100%);
            border: 1px solid var(--border-medium);
            border-radius: 12px;
            padding: var(--space-6);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }
        
        .stock-status-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--blue) 0%, var(--green) 100%);
            opacity: 1;
            transition: opacity 0.3s ease;
        }
        
        .stock-status-card:hover {
            border-color: var(--border-dark);
            box-shadow: 0 12px 20px -5px rgb(0 0 0 / 0.15), 0 8px 10px -6px rgb(0 0 0 / 0.15);
            transform: translateY(-2px);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-full);
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-badge.active {
            background-color: rgb(16 185 129 / 0.1);
            color: var(--green);
            border: 1px solid rgb(16 185 129 / 0.2);
        }
        
        .status-badge.inactive {
            background-color: rgb(239 68 68 / 0.1);
            color: var(--red);
            border: 1px solid rgb(239 68 68 / 0.2);
        }
        
        .movement-type-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--space-1);
            padding: var(--space-1) var(--space-3);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .movement-sale {
            background-color: rgb(245 158 11 / 0.1);
            color: var(--yellow);
            border: 1px solid rgb(245 158 11 / 0.2);
        }
        
        .movement-dispatch {
            background-color: rgb(139 92 246 / 0.1);
            color: #8b5cf6;
            border: 1px solid rgb(139 92 246 / 0.2);
        }
        
        .movement-return {
            background-color: rgb(16 185 129 / 0.1);
            color: var(--green);
            border: 1px solid rgb(16 185 129 / 0.2);
        }
        
        .movement-adjustment {
            background-color: rgb(59 130 246 / 0.1);
            color: var(--blue);
            border: 1px solid rgb(59 130 246 / 0.2);
        }
        
        .stock-movement-row:hover {
            background-color: var(--bg-secondary);
        }
        
        .quantity-change {
            font-weight: 600;
            font-family: var(--font-mono);
        }
        
        .quantity-change.positive {
            color: var(--green);
        }
        
        .quantity-change.negative {
            color: var(--red);
        }
        
        .quick-actions {
            display: flex;
            gap: var(--space-3);
            margin-top: var(--space-4);
        }
        
        .quick-actions .btn {
            flex: 1;
            justify-content: center;
        }
        
        .stock-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }
        
        .stock-summary-item {
            text-align: center;
            padding: var(--space-4);
            background: linear-gradient(135deg, rgba(248, 250, 252, 0.5) 0%, rgba(241, 245, 249, 0.3) 100%);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }
        
        .stock-summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-800);
            margin-bottom: var(--space-2);
        }
        
        .stock-summary-label {
            font-size: 0.75rem;
            color: var(--primary-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        
        /* Enhanced Alert Styles */
        .alert-enhanced {
            border: none;
            border-radius: 12px;
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }
        
        .alert-enhanced::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .alert-enhanced.alert-warning::before {
            background: linear-gradient(90deg, var(--yellow) 0%, var(--yellow-600) 100%);
        }
        
        .alert-enhanced.alert-danger::before {
            background: linear-gradient(90deg, var(--red) 0%, var(--red-600) 100%);
        }
        
        .alert-enhanced.alert-info::before {
            background: linear-gradient(90deg, var(--blue) 0%, var(--blue-600) 100%);
        }
        
        .alert-enhanced.alert-success::before {
            background: linear-gradient(90deg, var(--green) 0%, var(--green-600) 100%);
        }
        
        .alert-enhanced.alert-warning {
            background: linear-gradient(135deg, rgb(254 243 199 / 0.9) 0%, rgb(254 215 170 / 0.8) 100%);
            color: #92400e;
            border: 1px solid rgb(254 215 170 / 0.5);
        }
        
        .alert-enhanced.alert-danger {
            background: linear-gradient(135deg, rgb(254 226 226 / 0.9) 0%, rgb(252 165 165 / 0.8) 100%);
            color: #991b1b;
            border: 1px solid rgb(252 165 165 / 0.5);
        }
        
        .alert-enhanced.alert-info {
            background: linear-gradient(135deg, rgb(219 234 254 / 0.9) 0%, rgb(191 219 254 / 0.8) 100%);
            color: #1e40af;
            border: 1px solid rgb(191 219 254 / 0.5);
        }
        
        .alert-enhanced.alert-success {
            background: linear-gradient(135deg, rgb(220 252 231 / 0.9) 0%, rgb(187 247 208 / 0.8) 100%);
            color: #166534;
            border: 1px solid rgb(187 247 208 / 0.5);
        }
        
        .alert-content {
            display: flex;
            align-items: flex-start;
            gap: var(--space-4);
        }
        
        .alert-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        
        .alert-enhanced.alert-warning .alert-icon {
            background: rgb(254 240 138 / 0.3);
            color: var(--yellow);
        }
        
        .alert-enhanced.alert-danger .alert-icon {
            background: rgb(254 202 202 / 0.3);
            color: var(--red);
        }
        
        .alert-enhanced.alert-info .alert-icon {
            background: rgb(191 219 254 / 0.3);
            color: var(--blue);
        }
        
        .alert-enhanced.alert-success .alert-icon {
            background: rgb(187 247 208 / 0.3);
            color: var(--green);
        }
        
        .alert-text {
            flex: 1;
        }
        
        .alert-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: var(--space-2);
            color: inherit;
        }
        
        .alert-message {
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: var(--space-4);
            color: inherit;
            opacity: 0.9;
        }
        
        .alert-actions {
            display: flex;
            gap: var(--space-3);
            margin-top: var(--space-4);
        }
        
        .alert-action-btn {
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all var(--transition-fast);
            border: 1px solid;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .alert-enhanced.alert-warning .alert-action-btn {
            background: var(--yellow);
            color: white;
            border-color: var(--yellow);
        }
        
        .alert-enhanced.alert-warning .alert-action-btn:hover {
            background: var(--yellow-600);
            border-color: var(--yellow-600);
        }
        
        .alert-enhanced.alert-danger .alert-action-btn {
            background: var(--red);
            color: white;
            border-color: var(--red);
        }
        
        .alert-enhanced.alert-danger .alert-action-btn:hover {
            background: var(--red-600);
            border-color: var(--red-600);
        }
        
        .alert-enhanced.alert-info .alert-action-btn {
            background: var(--blue);
            color: white;
            border-color: var(--blue);
        }
        
        .alert-enhanced.alert-info .alert-action-btn:hover {
            background: var(--blue-600);
            border-color: var(--blue-600);
        }
        
        .alert-enhanced.alert-success .alert-action-btn {
            background: var(--green);
            color: white;
            border-color: var(--green);
        }
        
        .alert-enhanced.alert-success .alert-action-btn:hover {
            background: var(--green-600);
            border-color: var(--green-600);
        }
        
        .alert-secondary-btn {
            background: transparent;
            color: inherit;
            border-color: inherit;
            opacity: 0.8;
        }
        
        .alert-secondary-btn:hover {
            opacity: 1;
        }
    </style>
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/stock-pages.css" rel="stylesheet">
</head>
<body class="stock-ui">
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
                <button type="button" class="toggle-btn" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section-title overview">Main Operations</div>
                <a href="../dashboard/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../products/view.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <div class="nav-section-title stock">Stock Management</div>
                <a href="manage.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock Manage</span>
                </a>
                <a href="summary.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Stock Summary</span>
                </a>
                <a href="analysis.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-search-dollar"></i></span>
                    <span class="nav-text">Stock Analysis</span>
                </a>
                <a href="initial_setup.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-play-circle"></i></span>
                    <span class="nav-text">Initial Stock Setup</span>
                </a>
                <a href="supplies.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-truck-loading"></i></span>
                    <span class="nav-text">Supplies</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordDispatches()): ?>
                <div class="nav-section-title dispatch">Dispatch Operations</div>
                <a href="../dispatch/trips/record.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="nav-text">New Dispatch</span>
                </a>
                <a href="../dispatch/trips/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-list"></i></span>
                    <span class="nav-text">Manage Dispatches</span>
                </a>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../dispatch/drivers.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span class="nav-text">Dispatchers</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title sales">Sales & Finance</div>
                <a href="../dispatch/daily/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Daily Sales</span>
                </a>
                <a href="../dispatch/daily/account-summary.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>
                <a href="../finance/daily_balance.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>
                <?php if (isCashier()): ?>
                <a href="../cashier/deposit.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="../admin/end-of-day.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../reports/daily.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <a href="../users/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="../admin/data_management.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <a href="../auth/logout.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search stock, products, movements..." id="globalSearch" aria-label="Search stock, products, and movements">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <button type="button" class="icon-btn position-relative" title="Notifications" aria-label="Notifications">
                            <i class="fas fa-bell"></i>
                        </button>

                        <div class="user-menu dropdown">
                            <button class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User', 0, 2)); ?>
                                </div>
                                <div class="user-info d-none d-lg-block">
                                    <div class="user-name"><?php echo htmlspecialchars(ucwords($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')); ?></div>
                                    <div class="user-role"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <li><a class="dropdown-item" href="../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumb stock-breadcrumb">
                            <li class="breadcrumb-item"><a href="../dashboard/index.php">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Daily stock</li>
                        </ol>
                    </nav>
                    <h1 class="page-title">
                        <i class="fas fa-warehouse" style="margin-right: 15px;"></i>
                        Stock Management
                    </h1>
                    <p class="page-subtitle">Initialize the selected business date, review stock movements, and close its balance.</p>
                </div>
                <!-- Enhanced Alerts -->
                <?php if ($message): ?>
                    <div class="alert-enhanced alert-success">
                        <div class="alert-content">
                            <div class="alert-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="alert-text">
                                <div class="alert-title">Success</div>
                                <div class="alert-message"><?php echo htmlspecialchars($message); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert-enhanced alert-<?php echo $error_type; ?>">
                        <div class="alert-content">
                            <div class="alert-icon">
                                <i class="fas fa-<?php echo $error_type === 'warning' ? 'exclamation-triangle' : ($error_type === 'info' ? 'info-circle' : 'times-circle'); ?>"></i>
                            </div>
                            <div class="alert-text">
                                <div class="alert-title"><?php echo htmlspecialchars($error_title); ?></div>
                                <div class="alert-message"><?php echo htmlspecialchars($error); ?></div>
                                <?php if ($error_action === 'initialize_stock' && !$isInitialized && hasRole(['admin', 'manager'])): ?>
                                    <div class="alert-actions">
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="initialize_stock">
                                            <input type="hidden" name="stock_date" value="<?php echo $today; ?>">
                                            <button type="submit" class="alert-action-btn">
                                                <i class="fas fa-play"></i>
                                                Initialize Stock Now
                                            </button>
                                        </form>
                                        <a href="../dashboard/index.php" class="alert-action-btn alert-secondary-btn">
                                            <i class="fas fa-arrow-left"></i>
                                            Back to Dashboard
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stock Status Overview -->
                <div class="row">
                    <div class="col-12">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title mb-2">
                                        <i class="fas fa-calendar-day me-2"></i>
                                        Stock Status for <?php echo date('M d, Y', strtotime($selectedDate)); ?>
                                    </h5>
                                    <p class="card-subtitle">Opening stock for selected date</p>
                                </div>
                                <div class="stat-icon blue">
                                    <i class="fas fa-warehouse"></i>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="viewDatePicker">View stock for date</label>
                                <div class="d-flex gap-2">
                                    <input type="date" id="viewDatePicker" class="form-control" value="<?php echo $selectedDate; ?>">
                                    <button type="button" onclick="viewDate()" class="btn btn-secondary">View</button>
                                </div>
                            </div>
                            <script>
                                function viewDate() {
                                    const datePicker = document.getElementById('viewDatePicker');
                                    if (datePicker) {
                                        window.location.href = 'manage.php?date=' + datePicker.value;
                                    }
                                }
                            </script>
                            
                            <div class="d-flex align-items-center mb-4">
                                <span class="status-badge <?php echo $isInitialized ? 'active' : 'inactive'; ?> me-3">
                                    <i class="fas fa-<?php echo $isInitialized ? 'check' : 'times'; ?> me-1"></i>
                                    <?php echo $isInitialized ? 'Initialized' : 'Not Initialized'; ?>
                                </span>
                            </div>

                            <div class="quick-actions">
                                <div class="d-flex gap-2">
                                    <?php if (hasRole(['admin', 'manager'])): ?>
                                    <form method="post" class="flex-grow-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="mb-3">
                                            <label class="form-label" for="initializeStockDate">Initialize stock for date</label>
                                            <input type="date" id="initializeStockDate" name="stock_date" class="form-control" value="<?php echo $selectedDate; ?>">
                                        </div>
                                        <input type="hidden" name="action" value="initialize_stock">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-play me-1"></i>Initialize Stock
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" class="flex-grow-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="mb-3">
                                            <label class="form-label" for="closeStockDate">Close stock for date</label>
                                            <input type="date" id="closeStockDate" name="stock_date" class="form-control" value="<?php echo $selectedDate; ?>">
                                        </div>
                                        <input type="hidden" name="action" value="close_stock">
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="fas fa-check me-1"></i>Close Stock
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Supplies for Selected Date -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-truck-loading me-2"></i>
                                Supplies for <?php echo date('M d, Y', strtotime($selectedDate)); ?>
                            </h5>
                            <p class="card-subtitle">Inbound stock received on selected date</p>
                        </div>
                        <a href="supplies.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>Add Supply
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($todaySupplies)): ?>
                            <p class="text-muted">No supplies recorded today.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-modern">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Items</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($todaySupplies as $supply): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($supply['supply_date'])); ?></td>
                                                <td><?php echo $supply['item_count']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Movement Summary for Selected Date -->
                <?php if (!empty($movementSummary)): ?>
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-chart-bar me-2"></i>
                                Movement Summary for <?php echo date('M d, Y', strtotime($selectedDate)); ?>
                            </h5>
                            <p class="card-subtitle">Total units dispatched, returned, and other movements</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php
                            $totalDispatched = 0;
                            $totalReturned = 0;
                            $totalSales = 0;
                            $totalSupply = 0;
                            foreach ($movementSummary as $summary) {
                                $type = strtolower($summary['movement_type']);

                                // Dispatches are recorded as negative quantity (stock out)
                                if (in_array($type, ['dispatch'])) {
                                    $totalDispatched += $summary['negative_quantity'];

                                // Returns add stock back (positive quantity)
                                } elseif (in_array($type, ['return'])) {
                                    $totalReturned += $summary['positive_quantity'];

                                // Sales may be stored as 'sale' or 'sales' and are negative (stock out)
                                } elseif (in_array($type, ['sale', 'sales'])) {
                                    $totalSales += $summary['negative_quantity'];

                                // Supplies/purchases may be stored as 'supply' or 'purchase' and are positive (stock in)
                                } elseif (in_array($type, ['supply', 'purchase'])) {
                                    $totalSupply += $summary['positive_quantity'];
                                }
                            }
                            ?>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon red">
                                        <i class="fas fa-truck"></i>
                                    </div>
                                    <div class="stat-content">
                                        <div class="stat-value"><?php echo number_format($totalDispatched, 2); ?></div>
                                        <div class="stat-label">Total Dispatched</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon orange">
                                        <i class="fas fa-undo"></i>
                                    </div>
                                    <div class="stat-content">
                                        <div class="stat-value"><?php echo number_format($totalReturned, 2); ?></div>
                                        <div class="stat-label">Total Returned</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon blue">
                                        <i class="fas fa-shopping-cart"></i>
                                    </div>
                                    <div class="stat-content">
                                        <div class="stat-value"><?php echo number_format($totalSales, 2); ?></div>
                                        <div class="stat-label">Total Sales</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon green">
                                        <i class="fas fa-boxes"></i>
                                    </div>
                                    <div class="stat-content">
                                        <div class="stat-value"><?php echo number_format($totalSupply, 2); ?></div>
                                        <div class="stat-label">Total Supply</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon purple">
                                        <i class="fas fa-balance-scale"></i>
                                    </div>
                                    <div class="stat-content">
                                        <div class="stat-value"><?php echo number_format($totalClosingStockAfterDispatch, 2); ?></div>
                                        <div class="stat-label">Stock Balance After Dispatch</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Daily Stock Report -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-chart-line me-2"></i>
                                Stock Report for <?php echo date('M d, Y', strtotime($selectedDate)); ?>
                            </h5>
                            <p class="card-subtitle">Detailed stock movements for selected date</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($dailyStockRecords)): ?>
                        <div class="table-responsive">
                            <table class="table table-modern">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>SKU</th>
                                        <th>Unit</th>
                                        <th>Opening Stock</th>
                                        <th>Stock Movement</th>
                                        <th>Closing Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dailyStockRecords as $record): ?>
                                    <?php $movement = $record['closing_stock'] - $record['opening_stock']; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($record['product_name']); ?></td>
                                        <td><?php echo htmlspecialchars($record['sku']); ?></td>
                                        <td><?php echo htmlspecialchars($record['unit']); ?></td>
                                        <td><strong><?php echo $record['opening_stock']; ?></strong></td>
                                        <td>
                                            <span class="quantity-change <?php echo $movement >= 0 ? 'positive' : 'negative'; ?>">
                                                <?php echo ($movement >= 0 ? '+' : '') . $movement; ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo $record['closing_stock']; ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <p class="text-muted">No stock records found for this date. Please initialize stock first.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Stock Movements -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-history me-2"></i>
                                Recent Stock Movements
                            </h5>
                            <p class="card-subtitle">Last 7 days of stock activity</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-modern">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th>Type</th>
                                        <th>Quantity Change</th>
                                        <th>Reference</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentMovements)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">
                                                    <i class="fas fa-inbox"></i>
                                                </div>
                                                <h6>No stock movements found</h6>
                                                <p>Stock movements will appear here once activities are recorded</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentMovements as $movement): ?>
                                        <tr class="stock-movement-row">
                                            <td><?php echo date('M d, Y', strtotime($movement['movement_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                            <td>
                                                <span class="movement-type-badge movement-<?php echo strtolower($movement['movement_type']); ?>">
                                                    <?php echo ucfirst($movement['movement_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="quantity-change <?php echo $movement['quantity_change'] >= 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo ($movement['quantity_change'] >= 0 ? '+' : '') . $movement['quantity_change']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($movement['reference_number'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($movement['user_name'] ?: 'System'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/offline-handler.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/app.js"></script>
    
    <script>
        // Sidebar toggle functionality
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });

        // Global search functionality
        document.getElementById('globalSearch')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.stock-movement-row');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Auto-refresh stock status every 30 seconds
        setInterval(function() {
            // You can add AJAX call here to refresh stock status
            console.log('Stock status refreshed');
        }, 30000);
    </script>
</body>
</html>


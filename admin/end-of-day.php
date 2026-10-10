<?php
/**
 * Admin End-of-Day Summary Dashboard for Merrylay Enterprises
 *
 * Provides company-wide daily financial summary aggregating all dispatchers' performance
 */

require_once '../init.php';
require_once '../config/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/stock_manager.php';

if (!hasRole(['admin', 'manager'])) {
    error_log('Unauthorized end-of-day access by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to view end-of-day reports.');
}

$error = '';
$success = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Handle stock closing and initialization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['close_stock'] ?? '') === '1') {
    $postedDate = $_POST['stock_date'] ?? '';
    $dateObject = DateTime::createFromFormat('!Y-m-d', $postedDate);
    $dateIsValid = $dateObject && $dateObject->format('Y-m-d') === $postedDate;

    $stockManager = getStockManager();

    try {
        if (!isset($_SESSION['user_id'])) {
            throw new RuntimeException('Authentication is required.');
        }
        if (!hasRole(['admin', 'manager'])) {
            error_log('Unauthorized end-of-day stock close attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
            throw new DomainException('You do not have permission to close stock.');
        }
        if (!$dateIsValid || $postedDate !== date('Y-m-d')) {
            throw new InvalidArgumentException('Only the current date can be closed.');
        }
        $csrfToken = $_POST['csrf_token'] ?? null;
        if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
            error_log('Rejected end-of-day stock close: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
            throw new RuntimeException('CSRF validation failed.');
        }
        
        $selected_date = $postedDate;
        $stockManager->closeDailyStock($selected_date);

        $success = "Stock closed for $selected_date and initialized for the next day";
    } catch (Exception $e) {
        error_log('End-of-day stock close failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof DomainException
            ? $e->getMessage()
            : 'Stock could not be closed. No changes were committed.';
    }
}

$stockCloseCsrfToken = SecurityUtils::generateCSRFToken();

// Get company-wide daily summary
$daily_summary = null;
$overall_variance = 0;
$cash_position = 0;
$shop_total_expenses = 0;
$shop_total_returns = 0;
$shop_net_cash = 0;
$dispatcher_summaries = [];

try {
    // Get all dispatches for the selected date (excluding shop channel to avoid duplication)
    $stmt = executeQuery("
        SELECT DISTINCT dispatcher_name
        FROM dispatches
        WHERE DATE(dispatch_date) = ? AND (sales_channel IS NULL OR sales_channel NOT IN ('shop', 'shop_sales'))
        ORDER BY dispatcher_name
    ", [$selected_date]);
    $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Calculate company-wide totals (including shop channel for trip count, excluding for expected)
    $stmt = executeQuery("
        SELECT
            COUNT(DISTINCT d.id) as total_trips,
            COUNT(DISTINCT d.dispatcher_name) as total_dispatchers,
            SUM(CASE WHEN (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales')) THEN d.expected_amount ELSE 0 END) as total_expected
        FROM dispatches d
        WHERE DATE(d.dispatch_date) = ?
    ", [$selected_date]);
    $dispatch_totals = $stmt->fetch();

    // Get shop sales payment totals with transaction counts (matching daily_balance.php)
    $stmt = executeQuery("
        SELECT
            payment_method,
            SUM(payment_amount) as total_amount,
            COUNT(DISTINCT shop_sale_id) as transaction_count
        FROM (
            SELECT ss.id as shop_sale_id, ss.payment_method, ss.total_amount as payment_amount
            FROM shop_sales ss
            WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 0

            UNION ALL

            SELECT ss.id as shop_sale_id, ssp.payment_method, ssp.amount as payment_amount
            FROM shop_sale_payments ssp
            JOIN shop_sales ss ON ss.id = ssp.shop_sale_id
            WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 1
        ) shop_payment_rows
        GROUP BY payment_method
    ", [$selected_date, $selected_date]);
    $shop_sales_details = $stmt->fetchAll();

    $stmt = executeQuery("
        SELECT COALESCE(SUM(total_amount), 0) as shop_total
        FROM shop_sales
        WHERE DATE(sale_date) = ?
    ", [$selected_date]);
    $shop_sales_total_data = $stmt->fetch();
    
    $shop_sales_totals = [
        'shop_cash' => 0,
        'shop_transfer' => 0,
        'shop_card' => 0,
        'shop_credit' => 0,
        'shop_total' => floatval($shop_sales_total_data['shop_total'] ?? 0)
    ];
    $shop_sales_transaction_counts = [];
    
    foreach ($shop_sales_details as $detail) {
        if ($detail['payment_method'] === 'cash') {
            $shop_sales_totals['shop_cash'] += $detail['total_amount'];
        } elseif ($detail['payment_method'] === 'transfer') {
            $shop_sales_totals['shop_transfer'] += $detail['total_amount'];
        } elseif ($detail['payment_method'] === 'card') {
            $shop_sales_totals['shop_card'] += $detail['total_amount'];
        } elseif ($detail['payment_method'] === 'credit') {
            $shop_sales_totals['shop_credit'] += $detail['total_amount'];
        }
        $shop_sales_transaction_counts[$detail['payment_method']] = $detail['transaction_count'];
    }

    // Get total quantity dispatched separately (excluding shop channel)
    $stmt = executeQuery("
        SELECT SUM(di.quantity) as total_quantity_dispatched
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) = ? AND (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales'))
    ", [$selected_date]);
    $quantity_totals = $stmt->fetch();

    // Get collections from daily_activities (matching daily_balance.php approach)
    $stmt = executeQuery("
        SELECT 
            'cash' as payment_method,
            COALESCE(SUM(cash_collected), 0) as total_amount,
            COUNT(*) as transaction_count
        FROM daily_activities
        WHERE activity_date = ? AND cash_collected > 0

        UNION ALL

        SELECT 
            'transfer' as payment_method,
            COALESCE(SUM(transfer_amount), 0) as total_amount,
            COUNT(*) as transaction_count
        FROM daily_activities
        WHERE activity_date = ? AND transfer_amount > 0

        UNION ALL

        SELECT 
            'credit_settlement' as payment_method,
            COALESCE(SUM(credit_settlement), 0) as total_amount,
            COUNT(*) as transaction_count
        FROM daily_activities
        WHERE activity_date = ? AND credit_settlement > 0
    ", [$selected_date, $selected_date, $selected_date]);
    $collections = $stmt->fetchAll();

    $total_cash_collected = 0;
    $total_transfers_collected = 0;
    $total_credit_settlement = 0;
    $dispatcher_transaction_counts = [];

    foreach ($collections as $collection) {
        if ($collection['payment_method'] === 'cash') {
            $total_cash_collected += $collection['total_amount'];
            $dispatcher_transaction_counts['cash'] = $collection['transaction_count'];
        } elseif ($collection['payment_method'] === 'transfer') {
            $total_transfers_collected += $collection['total_amount'];
            $dispatcher_transaction_counts['transfer'] = $collection['transaction_count'];
        } elseif ($collection['payment_method'] === 'credit_settlement') {
            $total_credit_settlement += $collection['total_amount'];
            $dispatcher_transaction_counts['credit_settlement'] = $collection['transaction_count'];
        }
    }

    // Get credit sales from daily_activities
    $stmt = executeQuery("SELECT COALESCE(SUM(credit_amount), 0) as total_credit_sales FROM daily_activities WHERE activity_date = ?", [$selected_date]);
    $dispatcher_credit_sales = $stmt->fetchColumn();

    // Get total expenses for the date
    $stmt = executeQuery("
        SELECT 
            SUM(expenses_amount) as total_expenses,
            COUNT(CASE WHEN expenses_amount > 0 THEN 1 END) as expense_count
        FROM daily_activities
        WHERE activity_date = ?
    ", [$selected_date]);
    $expense_totals = $stmt->fetch();

    // Get returns with product prices for monetary value (matching account-summary.php)
    $stmt = executeQuery("
        SELECT 
            SUM(dr.quantity * di.unit_price) as total_return_value,
            SUM(dr.quantity) as total_return_quantity
        FROM daily_returns dr
        JOIN dispatch_items di ON di.dispatch_id = dr.dispatch_id AND di.product_id = dr.product_id
        WHERE dr.return_date = ?
    ", [$selected_date]);
    $return_totals = $stmt->fetch();

    // Get actual trip discounts from dispatch_items (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.discount_amount), 0) as total_discounts
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) = ? AND (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales'))
    ", [$selected_date]);
    $discount_totals = $stmt->fetch();

    // Get actual damaged products value from daily_activities (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(damaged_products), 0) as total_damaged_value
        FROM daily_activities
        WHERE activity_date = ?
    ", [$selected_date]);
    $damaged_totals = $stmt->fetch();

    // Get shop collections from shop_collections table (matching daily_balance.php)
    try {
        $stmt = executeQuery("
            SELECT
                COALESCE(SUM(CASE WHEN status != 'cleared' THEN collection_amount ELSE 0 END), 0) as unsettled_collections,
                COALESCE(SUM(CASE WHEN status = 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as settled_acknowledged,
                COALESCE(SUM(collection_amount), 0) as total_shop_expenses,
                COALESCE(SUM(amount_acknowledged), 0) as total_shop_returns
            FROM shop_collections
            WHERE DATE(collection_date) = ?
        ", [$selected_date]);
        $shop_collection_totals = $stmt->fetch();
        $shop_sales_unsettled_collections = floatval($shop_collection_totals['unsettled_collections'] ?? 0);
        $shop_sales_settled_collections = floatval($shop_collection_totals['settled_acknowledged'] ?? 0);
        $shop_total_expenses = floatval($shop_collection_totals['total_shop_expenses'] ?? 0);
        $shop_total_returns = floatval($shop_collection_totals['total_shop_returns'] ?? 0);
    } catch (PDOException $e) {
        $shop_sales_unsettled_collections = 0;
        $shop_sales_settled_collections = 0;
        $shop_total_expenses = 0;
        $shop_total_returns = 0;
    }

    // Calculate totals matching daily_balance.php approach
    $shop_cash = floatval($shop_sales_totals['shop_cash'] ?? 0);
    $shop_transfer = floatval($shop_sales_totals['shop_transfer'] ?? 0);
    $shop_card = floatval($shop_sales_totals['shop_card'] ?? 0);
    $shop_credit = floatval($shop_sales_totals['shop_credit'] ?? 0);
    $shop_total = floatval($shop_sales_totals['shop_total'] ?? 0);

    // Preserve gross shop sale allocations for disposition reconciliation.
    $disposition_cash = $total_cash_collected + $shop_cash;
    $disposition_transfer = $total_transfers_collected + $shop_transfer;
    $disposition_card = $shop_card;
    $disposition_credit = $dispatcher_credit_sales + $shop_credit;

    // Keep sales collections gross; shop expenses affect cash position only.
    $total_cash_collected += $shop_cash;
    $total_transfers_collected += $shop_transfer;
    $total_card_collected = $shop_card;
    $total_credit_sales = $dispatcher_credit_sales + $shop_credit;

    // Total Collections = Cash + Transfer + Card (all payment methods received)
    $total_collections = $total_cash_collected + $total_transfers_collected + $total_card_collected;
    $total_expected = $dispatch_totals['total_expected'] ?? 0;
    $total_shop_sales = $shop_total;
    $total_expenses = $expense_totals['total_expenses'] ?? 0;
    $total_returns = $return_totals['total_return_value'] ?? 0;
    $total_discounts = $discount_totals['total_discounts'] ?? 0;
    $total_damaged = $damaged_totals['total_damaged_value'] ?? 0;

    // Total Dispatch Value = Dispatcher expected + Shop sales gross
    $total_dispatch_value = $total_expected + $total_shop_sales;

    // Outstanding Credit = Credit Sales - Credit Settlements (net amount still owed)
    $outstanding_credit = $total_credit_sales - $total_credit_settlement;

    // Disposition compares gross sales allocations and stock adjustments with dispatch/shop value.
    // Credit settlements and expenses are separate cash movements, not current-day sales disposition.
    $total_accounted_for = $disposition_cash + $disposition_transfer + $disposition_card + $disposition_credit + $total_returns + $total_damaged;
    $total_disposition_expected = $total_dispatch_value - $total_discounts;
    $overall_variance = $total_accounted_for - $total_disposition_expected;

    // Cash position includes shop expenses and documented cash returned to the shop.
    $shop_net_cash = $shop_cash - $shop_total_expenses + $shop_total_returns;
    $cash_position = $total_cash_collected - $total_expenses - $shop_total_expenses + $shop_total_returns;

    // Get individual dispatcher summaries with all metrics (simplified to match daily_balance.php)
    $dispatcher_summaries = [];
    foreach ($dispatchers as $dispatcher) {
        $stmt = executeQuery("
            SELECT
                d.dispatcher_name,
                COUNT(DISTINCT d.id) as trips,
                SUM(d.expected_amount) as expected,
                COALESCE(SUM(da.cash_collected), 0) as cash_collected,
                COALESCE(SUM(da.transfer_amount), 0) as transfer_collected,
                COALESCE(SUM(da.credit_settlement), 0) as credit_settlement,
                COALESCE(SUM(da.credit_amount), 0) as credit_sales,
                COALESCE(SUM(da.expenses_amount), 0) as expenses,
                COALESCE(SUM(da.damaged_products), 0) as damaged_products
            FROM dispatches d
            LEFT JOIN daily_activities da ON d.dispatcher_name = da.driver_name AND DATE(d.dispatch_date) = DATE(da.activity_date)
            WHERE d.dispatcher_name = ? AND DATE(d.dispatch_date) = ?
            GROUP BY d.dispatcher_name
        ", [$dispatcher, $selected_date]);
        $summary = $stmt->fetch();

        // Calculate returns for this dispatcher
        $stmt = executeQuery("
            SELECT COALESCE(SUM(dr.quantity * di.unit_price), 0) as returns_value
            FROM daily_returns dr
            JOIN dispatch_items di ON di.dispatch_id = dr.dispatch_id AND di.product_id = dr.product_id
            WHERE dr.driver_name = ? AND dr.return_date = ?
        ", [$dispatcher, $selected_date]);
        $returns = $stmt->fetch();

        // Calculate discounts for this dispatcher
        $stmt = executeQuery("
            SELECT COALESCE(SUM(di.discount_amount), 0) as discounts
            FROM dispatch_items di
            JOIN dispatches d ON di.dispatch_id = d.id
            WHERE d.dispatcher_name = ? AND DATE(d.dispatch_date) = ?
        ", [$dispatcher, $selected_date]);
        $discounts = $stmt->fetch();

        $summary['returns_value'] = $returns['returns_value'] ?? 0;
        $summary['discounts'] = $discounts['discounts'] ?? 0;
        $summary['collections'] = ($summary['cash_collected'] ?? 0) + ($summary['transfer_collected'] ?? 0);
        $summary['outstanding_credit'] = ($summary['credit_sales'] ?? 0) - ($summary['credit_settlement'] ?? 0);

        $dispatcher_summaries[] = $summary;
    }

    // Add shop sales as a separate entry in dispatcher summaries
    if ($total_shop_sales > 0) {
        $shop_summary = [
            'dispatcher_name' => 'SHOP',
            'trips' => 1,
            'expected' => $total_shop_sales,
            'cash_collected' => $shop_cash,
            'transfer_collected' => $shop_transfer,
            'credit_settlement' => 0,
            'credit_sales' => $shop_credit,
            'expenses' => $shop_total_expenses,
            'damaged_products' => 0,
            'returns_value' => 0,
            'discounts' => 0,
            'collections' => $shop_cash + $shop_transfer,
            'net_cash_available' => $shop_net_cash,
            'outstanding_credit' => $shop_credit
        ];
        $dispatcher_summaries[] = $shop_summary;
    }

} catch (PDOException $e) {
    error_log("End of day summary error: " . $e->getMessage());
    $error = 'Unable to load daily summary.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="End of day summary dashboard for Merrylay Enterprises - Company-wide daily financial overview">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>End of Day Summary - Merrylay Enterprises</title>
    
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/fonts/inter/inter.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/unified-design-system.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/clean-dashboard.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/stock-pages.css" rel="stylesheet">
</head>
<body class="stock-ui stock-ui-eod">
    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
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
                                    <tr>
                                        <td>Shop Cash Received</td>
                                        <td class="text-end">₦<?php echo number_format($shop_cash, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Shop Expenses</td>
                                        <td class="text-end">₦<?php echo number_format($shop_total_expenses, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Net Shop Cash Available</td>
                                        <td class="text-end">₦<?php echo number_format($shop_net_cash, 2); ?></td>
                                    </tr>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section-title overview">Main Operations</div>
                <a href="../dashboard/index.php" class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../products/view.php" class="nav-link <?php echo $currentPage === 'view.php' && strpos($_SERVER['PHP_SELF'], 'products') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="../stock/manage.php" class="nav-link <?php echo $currentPage === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
                </a>
                <a href="../stock/initial_setup.php" class="nav-link <?php echo $currentPage === 'initial_setup.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-play-circle"></i></span>
                    <span class="nav-text">Initial Stock Setup</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordDispatches()): ?>
                <a href="../dispatch/trips/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-truck"></i></span>
                    <span class="nav-text">Trips (Dispatch)</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title overview">Daily Workflow</div>

                <a href="../dispatch/daily/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'daily') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                    <span class="nav-text">Today's Records</span>
                </a>

                <a href="../dispatch/daily/account-summary.php" class="nav-link <?php echo $currentPage === 'account-summary.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>

                <a href="../finance/daily_balance.php" class="nav-link <?php echo $currentPage === 'daily_balance.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>

                <?php if (canRecordBankDeposits()): ?>
                <a href="../cashier/deposit.php" class="nav-link <?php echo $currentPage === 'deposit.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>

                <a href="../dispatch/credit/index.php" class="nav-link <?php echo $currentPage === 'record_credit_settlements.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-text">Credit Management</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="end-of-day.php" class="nav-link <?php echo $currentPage === 'end-of-day.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../reports/daily.php" class="nav-link <?php echo $currentPage === 'daily.php' && strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <a href="../users/manage.php" class="nav-link <?php echo $currentPage === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'users') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="data_management.php" class="nav-link <?php echo $currentPage === 'data_management.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <a href="index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-text">Stock Statistics</span>
                </a>
                <div class="nav-section-title admin">System</div>
                <a href="backup.php" class="nav-link <?php echo $currentPage === 'backup.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-server"></i></span>
                    <span class="nav-text">Database Backup</span>
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
                        <input type="text" placeholder="Search end-of-day data, dispatchers, payments..." id="endOfDaySearch" aria-label="Search end-of-day data, dispatchers, and payments">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <div class="date-display" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #f1f5f9; border-radius: 8px; font-weight: 500; color: #475569;">
                            <i class="fas fa-calendar-day text-primary"></i>
                            <span><?php echo date('F j, Y'); ?></span>
                        </div>
                        
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
                                <li><a class="dropdown-item" href="../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <nav aria-label="Breadcrumb">
                                <ol class="breadcrumb stock-breadcrumb">
                                    <li class="breadcrumb-item"><a href="../dashboard/index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active" aria-current="page">End of day</li>
                                </ol>
                            </nav>
                            <h1><i class="fas fa-chart-pie me-3"></i>End of Day Summary</h1>
                            <p>Review the selected day's performance and close today's stock to initialize the following day.</p>
                            <div class="welcome-meta">
                                <div>
                                    <i class="fas fa-calendar-day"></i>
                                    <span><?php echo date('l, F j, Y', strtotime($selected_date)); ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <span id="currentTime"><?php echo date('H:i'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($error)): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Date Filter -->
                <div class="content-card mb-4 screen-only">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-calendar-day me-2"></i>Select Date
                            </h5>
                            <p class="card-subtitle">Choose date to view end-of-day summary</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="close-stock-form">
                            <input type="hidden" name="close_stock" value="1">
                            <input type="hidden" name="stock_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($stockCloseCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        </form>
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label" for="date">Date</label>
                                <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sync me-2"></i>Load Summary
                                </button>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" form="close-stock-form" class="btn btn-success stock-close-submit" onclick="return confirm('Close stock for <?php echo htmlspecialchars(date('Y-m-d')); ?> and initialize next day?')">
                                    <i class="fas fa-warehouse me-2"></i>Close Stock & Init Next Day
                                </button>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                    <i class="fas fa-print me-2"></i>Print Report
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Balance Status -->
                <?php if (isset($dispatch_totals) && $dispatch_totals['total_trips'] > 0): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="balance-status <?php
                                echo abs($overall_variance) < 0.01 ? 'status-balanced' :
                                     ($overall_variance > 0 ? 'status-surplus' : 'status-unbalanced');
                            ?>">
                                <div class="d-flex align-items-center justify-content-center">
                                    <i class="fas fa-<?php
                                        echo abs($overall_variance) < 0.01 ? 'check-circle' :
                                             ($overall_variance > 0 ? 'plus-circle' : 'exclamation-triangle');
                                    ?> me-3"></i>
                                    <div>
                                        <strong>Sales Disposition Status: <?php
                                            echo abs($overall_variance) < 0.01 ? 'Balanced' :
                                                 ($overall_variance > 0 ? 'Surplus' : 'Shortage');
                                        ?></strong>
                                        <?php if (abs($overall_variance) >= 0.01): ?>
                                            <div class="small">Difference: ₦<?php echo number_format(abs($overall_variance), 2); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No dispatch data found for <?php echo date('l, F j, Y', strtotime($selected_date)); ?>. Select a date with dispatches to view the full summary.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Summary Stats - Accounting Flow -->
                    <div class="stats-grid">
                        <!-- Revenue Section -->
                        <div class="stat-card revenue-section">
                            <div class="stat-header">
                                <small class="text-muted text-uppercase fw-bold">Revenue</small>
                            </div>
                            <div class="stat-icon green">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_dispatch_value ?? 0, 0); ?></div>
                            <div class="stat-label">Gross Revenue</div>
                        </div>

                        <div class="stat-card revenue-section">
                            <div class="stat-icon orange">
                                <i class="fas fa-percent"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_discounts ?? 0, 0); ?></div>
                            <div class="stat-label">Discounts</div>
                        </div>

                        <div class="stat-card revenue-section">
                            <div class="stat-icon blue">
                                <i class="fas fa-undo"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_returns ?? 0, 0); ?></div>
                            <div class="stat-label">Returns</div>
                        </div>

                        <!-- Collections Section -->
                        <div class="stat-card collections-section">
                            <div class="stat-header">
                                <small class="text-muted text-uppercase fw-bold">Collections</small>
                            </div>
                            <div class="stat-icon yellow">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_cash_collected ?? 0, 0); ?></div>
                            <div class="stat-label">Cash</div>
                        </div>

                        <div class="stat-card collections-section">
                            <div class="stat-icon purple">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_transfers_collected ?? 0, 0); ?></div>
                            <div class="stat-label">Transfers</div>
                        </div>

                        <div class="stat-card collections-section">
                            <div class="stat-icon blue">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_card_collected ?? 0, 0); ?></div>
                            <div class="stat-label">Card</div>
                        </div>

                        <!-- Credit Section -->
                        <div class="stat-card credit-section">
                            <div class="stat-header">
                                <small class="text-muted text-uppercase fw-bold">Credit</small>
                            </div>
                            <div class="stat-icon purple">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_credit_sales ?? 0, 0); ?></div>
                            <div class="stat-label">Credit Sales</div>
                        </div>

                        <div class="stat-card credit-section">
                            <div class="stat-icon green">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_credit_settlement ?? 0, 0); ?></div>
                            <div class="stat-label">Settlements</div>
                        </div>

                        <!-- Adjustments Section -->
                        <div class="stat-card adjustments-section">
                            <div class="stat-header">
                                <small class="text-muted text-uppercase fw-bold">Adjustments</small>
                            </div>
                            <div class="stat-icon red">
                                <i class="fas fa-box-open"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_damaged ?? 0, 0); ?></div>
                            <div class="stat-label">Damaged</div>
                        </div>

                        <div class="stat-card adjustments-section">
                            <div class="stat-icon red">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($total_expenses ?? 0, 0); ?></div>
                            <div class="stat-label">Expenses</div>
                        </div>

                        <!-- Sales Disposition Section -->
                        <div class="stat-card net-section">
                            <div class="stat-header">
                                <small class="text-muted text-uppercase fw-bold">Sales Disposition</small>
                            </div>
                            <div class="stat-icon <?php echo abs($overall_variance) < 0.01 ? 'green' : 'red'; ?>">
                                <i class="fas fa-balance-scale"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($overall_variance, 0); ?></div>
                            <div class="stat-label">Disposition Variance</div>
                        </div>

                        <div class="stat-card net-section">
                            <div class="stat-icon green">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div class="stat-value">₦<?php echo number_format($cash_position, 0); ?></div>
                            <div class="stat-label">Cash Position</div>
                        </div>
                    </div>

                    <!-- Performance Analysis -->
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-chart-bar me-2"></i>Performance Analysis
                                    </h5>
                                    <p class="card-subtitle">Detailed breakdown by dispatcher and payment method</p>
                                </div>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#performanceTable">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse show" id="performanceTable">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th rowspan="2" class="align-middle">Dispatcher</th>
                                            <th rowspan="2" class="align-middle text-center">Trips</th>
                                            <th colspan="3" class="text-center">Revenue</th>
                                            <th colspan="4" class="text-center">Collections</th>
                                            <th colspan="4" class="text-center">Adjustments</th>
                                            <th rowspan="2" class="align-middle text-center">Disposition Variance</th>
                                        </tr>
                                        <tr>
                                            <th class="text-center">Expected</th>
                                            <th class="text-center">Discounts</th>
                                            <th class="text-center">Net Expected</th>
                                            <th class="text-center">Cash</th>
                                            <th class="text-center">Transfer</th>
                                            <th class="text-center">Card</th>
                                            <th class="text-center">Credit Settlement</th>
                                            <th class="text-center">Outstanding Credit</th>
                                            <th class="text-center">Returns</th>
                                            <th class="text-center">Damaged</th>
                                            <th class="text-center">Expenses</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Initialize running totals for footer
                                        $footer_trips = 0;
                                        $footer_expected = 0;
                                        $footer_discounts = 0;
                                        $footer_net_expected = 0;
                                        $footer_cash = 0;
                                        $footer_transfer = 0;
                                        $footer_card = 0;
                                        $footer_credit_settlement = 0;
                                        $footer_outstanding_credit = 0;
                                        $footer_returns = 0;
                                        $footer_damaged = 0;
                                        $footer_expenses = 0;
                                        $footer_variance = 0;

                                        foreach ($dispatcher_summaries as $summary):
                                            $net_expected = (floatval($summary['expected'] ?? 0) - floatval($summary['discounts'] ?? 0));
                                            $dsp_outstanding_credit = ($summary['credit_sales'] ?? 0) - ($summary['credit_settlement'] ?? 0);
                                            $is_shop = trim($summary['dispatcher_name']) === 'SHOP';
                                            $cash_card = $is_shop ? $shop_card : 0;
                                            $dsp_accounted_for = ($summary['collections'] + $cash_card + ($summary['credit_sales'] ?? 0) + $summary['returns_value'] + $summary['damaged_products']);
                                            $dsp_variance = $dsp_accounted_for - $net_expected;

                                            // Accumulate footer totals
                                            $footer_trips += (int)$summary['trips'];
                                            $footer_expected += floatval($summary['expected'] ?? 0);
                                            $footer_discounts += floatval($summary['discounts'] ?? 0);
                                            $footer_net_expected += $net_expected;
                                            $footer_cash += floatval($summary['cash_collected'] ?? 0);
                                            $footer_transfer += floatval($summary['transfer_collected'] ?? 0);
                                            $footer_card += $cash_card;
                                            $footer_credit_settlement += floatval($summary['credit_settlement'] ?? 0);
                                            $footer_outstanding_credit += $dsp_outstanding_credit;
                                            $footer_returns += floatval($summary['returns_value'] ?? 0);
                                            $footer_damaged += floatval($summary['damaged_products'] ?? 0);
                                            $footer_expenses += floatval($summary['expenses'] ?? 0);
                                            $footer_variance += $dsp_variance;
                                        ?>
                                            <tr <?php echo $is_shop ? 'class="table-info"' : ''; ?>>
                                                <td><strong><?php echo htmlspecialchars($summary['dispatcher_name']); ?></strong></td>
                                                <td class="text-center"><?php echo (int)$summary['trips']; ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['expected'] ?? 0, 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['discounts'], 2); ?></td>
                                                <td class="text-end fw-bold">₦<?php echo number_format($net_expected, 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['cash_collected'] ?? 0, 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['transfer_collected'] ?? 0, 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($cash_card, 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['credit_settlement'] ?? 0, 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($dsp_outstanding_credit, 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['returns_value'], 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['damaged_products'], 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($summary['expenses'], 2); ?></td>
                                                <td class="text-center">
                                                    <span class="badge <?php
                                                        echo abs($dsp_variance) < 0.01 ? 'bg-success' :
                                                             ($dsp_variance > 0 ? 'bg-primary' : 'bg-danger');
                                                    ?>">
                                                        <?php echo ($dsp_variance >= 0 ? '+' : '') . number_format($dsp_variance, 2); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-dark">
                                        <tr>
                                            <td><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong><?php echo $footer_trips; ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_expected, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_discounts, 2); ?></strong></td>
                                            <td class="text-end fw-bold"><strong>₦<?php echo number_format($footer_net_expected, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_cash, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_transfer, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_card, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_credit_settlement, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_outstanding_credit, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_returns, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_damaged, 2); ?></strong></td>
                                            <td class="text-end"><strong>₦<?php echo number_format($footer_expenses, 2); ?></strong></td>
                                            <td class="text-center">
                                                <span class="badge <?php
                                                    echo abs($footer_variance) < 0.01 ? 'bg-success' :
                                                         ($footer_variance > 0 ? 'bg-primary' : 'bg-danger');
                                                ?>">
                                                    <?php echo ($footer_variance >= 0 ? '+' : '') . number_format($footer_variance, 2); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="alert alert-light border mt-3 mb-0">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="fas fa-info-circle flex-shrink-0 mt-1 text-primary"></i>
                                    <div>
                                        <strong>Accounting Notes:</strong> 
                                        <ul class="mb-0 mt-2">
                                            <li><strong>Disposition Expected</strong> = Dispatch expected amount - Discounts + Shop Sales Gross</li>
                                            <li><strong>Disposition Total</strong> = Cash + Transfer + Card + Credit Sales + Returns + Damaged Value</li>
                                            <li><strong>Disposition Variance</strong> = Disposition Total - Disposition Expected</li>
                                            <li><strong>Expenses</strong> are displayed separately and do not enter disposition variance; they reduce Cash Position only</li>
                                            <li><strong>Credit Settlements</strong> are receipts against prior credit and do not enter current-day disposition variance</li>
                                            <li><strong>Outstanding Credit</strong> = Credit Sales - Credit Settlements (net amount still owed by customers)</li>
                                            <li><strong>Positive Variance</strong> = Surplus (more collected than expected)</li>
                                            <li><strong>Negative Variance</strong> = Shortage (less collected than expected)</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Variance Analysis & Insights -->
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-search-dollar me-2"></i>Variance Analysis & Insights
                                    </h5>
                                    <p class="card-subtitle">Key performance indicators and variance breakdown</p>
                                </div>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#varianceAnalysis">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse show" id="varianceAnalysis">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="card h-100 border-0 bg-light">
                                        <div class="card-body">
                                            <h6 class="card-title mb-3">
                                                <i class="fas fa-percentage me-2 text-primary"></i>Collection Efficiency
                                            </h6>
                                            <?php
                                            $collection_rate = $total_dispatch_value > 0 ? (($total_cash_collected + $total_transfers_collected + $total_card_collected) / $total_dispatch_value) * 100 : 0;
                                            ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Cash Collection Rate</span>
                                                    <strong><?php echo number_format($collection_rate, 1); ?>%</strong>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar <?php echo $collection_rate >= 90 ? 'bg-success' : ($collection_rate >= 70 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                         style="width: <?php echo min($collection_rate, 100); ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Outstanding Credit Ratio</span>
                                                    <strong><?php echo $total_dispatch_value > 0 ? number_format(($outstanding_credit / $total_dispatch_value) * 100, 1) : 0; ?>%</strong>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-info" 
                                                         style="width: <?php echo $total_dispatch_value > 0 ? min(($outstanding_credit / $total_dispatch_value) * 100, 100) : 0; ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="mb-0">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Discount Rate</span>
                                                    <strong><?php echo $total_dispatch_value > 0 ? number_format(($total_discounts / $total_dispatch_value) * 100, 1) : 0; ?>%</strong>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-warning" 
                                                         style="width: <?php echo $total_dispatch_value > 0 ? min(($total_discounts / $total_dispatch_value) * 100, 100) : 0; ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card h-100 border-0 bg-light">
                                        <div class="card-body">
                                            <h6 class="card-title mb-3">
                                                <i class="fas fa-balance-scale me-2 text-primary"></i>Variance Breakdown
                                            </h6>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between">
                                                    <span>Disposition Variance</span>
                                                    <strong class="<?php echo $overall_variance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        ₦<?php echo number_format(abs($overall_variance), 2); ?>
                                                        <?php echo $overall_variance >= 0 ? '(Surplus)' : '(Shortage)'; ?>
                                                    </strong>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between">
                                                    <span>Disposition Status</span>
                                                    <strong class="<?php echo abs($overall_variance) < 0.01 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo abs($overall_variance) < 0.01 ? 'Balanced' : 'Unbalanced'; ?>
                                                    </strong>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between">
                                                    <span>Cash Position</span>
                                                    <strong class="<?php echo $cash_position >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        ₦<?php echo number_format($cash_position, 2); ?>
                                                        <?php echo $cash_position >= 0 ? '(Positive)' : '(Negative)'; ?>
                                                    </strong>
                                                </div>
                                            </div>
                                            <div class="mb-0">
                                                <div class="d-flex justify-content-between">
                                                    <span>Outstanding Credit</span>
                                                    <strong class="text-info">
                                                        ₦<?php echo number_format($outstanding_credit, 2); ?>
                                                    </strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-<?php echo abs($overall_variance) < 0.01 ? 'success' : 'warning'; ?> mt-3 mb-0">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="fas fa-lightbulb flex-shrink-0 mt-1"></i>
                                    <div>
                                        <strong>Business Insight:</strong> 
                                        <?php if (abs($overall_variance) < 0.01): ?>
                                            Sales disposition balances: payment allocations, returns, and damage value match expected dispatch and shop sales after discounts.
                                        <?php elseif ($overall_variance > 0): ?>
                                            There is a surplus of ₦<?php echo number_format($overall_variance, 2); ?>. This may indicate additional collections not yet recorded, under-reported returns, or pricing adjustments. Review individual dispatcher performance for details.
                                        <?php else: ?>
                                            There is a disposition shortage of ₦<?php echo number_format(abs($overall_variance), 2); ?>. Review payment allocations, returns, damage values, discounts, and dispatch/shop expected values.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="content-card">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-bolt me-2"></i>Quick Actions
                                </h5>
                                <p class="card-subtitle">Common tasks and related pages</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <a href="../dispatch/trips/index.php" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-truck me-2"></i>Trips
                                    </a>
                                </div>
                                <div class="col-md-3">
                                    <a href="../dispatch/daily/index.php?date=<?php echo $selected_date; ?>" class="btn btn-outline-success w-100">
                                        <i class="fas fa-clipboard-list me-2"></i>Today's Records
                                    </a>
                                </div>
                                <div class="col-md-3">
                                    <a href="../finance/daily_balance.php?date=<?php echo $selected_date; ?>" class="btn btn-outline-warning w-100">
                                        <i class="fas fa-balance-scale me-2"></i>Daily Balance
                                    </a>
                                </div>
                                <div class="col-md-3">
                                    <a href="../accounts/balance_sheet.php?date=<?php echo $selected_date; ?>" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-file-invoice me-2"></i>Balance Sheet
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/clean-dashboard.js"></script>
    <style>
        /* Accounting Section Styling */
        .stat-header {
            margin-bottom: 0.25rem;
            font-size: 0.65rem;
            letter-spacing: 0.5px;
        }

        .revenue-section {
            border-left: 3px solid #28a745;
        }

        .collections-section {
            border-left: 3px solid #ffc107;
        }

        .credit-section {
            border-left: 3px solid #6f42c1;
        }

        .adjustments-section {
            border-left: 3px solid #dc3545;
        }

        .net-section {
            border-left: 3px solid #007bff;
        }

        /* Table Enhancements */
        .table th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.5rem;
        }

        .table td {
            font-size: 0.8rem;
            padding: 0.5rem;
        }

        .table-bordered th,
        .table-bordered td {
            border-color: #dee2e6;
        }

        /* Progress Bar Enhancements */
        .progress {
            background-color: #e9ecef;
            border-radius: 4px;
            height: 6px;
        }

        .progress-bar {
            transition: width 0.3s ease;
        }

        /* Card Enhancements */
        .card {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: box-shadow 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        /* Badge Enhancements */
        .badge {
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        /* Alert Enhancements */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
        }

        /* Stat Card Enhancements */
        .stat-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0.25rem 0;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #6c757d;
        }

        .stat-change {
            font-size: 0.7rem;
            margin-top: 0.25rem;
        }

        .stat-change.positive {
            color: #28a745;
        }

        .stat-change.negative {
            color: #dc3545;
        }

        /* Stats Grid Optimization */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
        }

        /* Responsive Table */
        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }

        /* Compact Card Headers */
        .card-header {
            padding: 0.75rem 1rem;
        }

        .card-body {
            padding: 1rem;
        }

        .card-title {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .card-subtitle {
            font-size: 0.8rem;
            margin-bottom: 0;
        }

        /* Compact Welcome Header */
        .welcome-header h1 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }

        .welcome-header p {
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        /* Compact Alert */
        .alert-light {
            font-size: 0.8rem;
        }

        /* Compact Progress Section */
        .card h6 {
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
        }

        .mb-3 {
            margin-bottom: 0.75rem !important;
        }

        .d-flex {
            font-size: 0.8rem;
        }

        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        @media print {
            html,
            body {
                background: #fff !important;
                color: #111 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .sidebar,
            .top-bar,
            .screen-only,
            .btn,
            .mobile-menu-toggle {
                display: none !important;
            }

            .dashboard-container {
                display: block !important;
            }

            .main-content,
            .dashboard-content {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .table-responsive {
                max-height: none !important;
                overflow: visible !important;
            }

            table {
                width: 100% !important;
                font-size: 8pt;
                table-layout: auto;
            }

            thead {
                display: table-header-group;
            }

            tr,
            .content-card,
            .stat-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .content-card {
                box-shadow: none !important;
                border: 1px solid #d1d5db !important;
                margin-bottom: 5mm;
            }

            h1,
            h2,
            h3,
            h4,
            h5,
            h6 {
                break-after: avoid;
                page-break-after: avoid;
            }
        }
    </style>
    <script>
        // Update current time (GMT+1)
        function updateTime() {
            const now = new Date();
            // Convert to GMT+1 (West Africa Time)
            const gmtPlus1 = new Date(now.toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
            const timeString = gmtPlus1.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Update time every minute
        setInterval(updateTime, 60000);

        // Initial time update
        updateTime();
        
        // Search functionality
        document.getElementById('endOfDaySearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('.content-card');
            
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                if (text.includes(searchTerm) || card.querySelector('.card-title')?.textContent.toLowerCase().includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>


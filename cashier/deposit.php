<?php
/**
 * Cashier Bank Deposit
 * 
 * Record bank deposits and track reconciliation.
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Require cashier or admin role for bank deposit access
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hasRole(['cashier', 'admin'])) {
    error_log('Unauthorized cashier deposit attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to record deposits.');
}
requireRole(['cashier', 'admin'], '../dashboard/index.php');

$error = '';
$success = $_SESSION['deposit_success'] ?? '';
unset($_SESSION['deposit_success']);
$recordDateInput = $_GET['date'] ?? $_POST['deposit_date'] ?? date('Y-m-d');
$record_date = is_scalar($recordDateInput) ? (string) $recordDateInput : date('Y-m-d');
$depositInputValue = static function ($key, $default = '') {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? (string) $value : (string) $default;
};

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_deposit'])) {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
        error_log('Rejected bank deposit submission: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the deposit details and submit again.';
    } else {
    $deposit_date = $_POST['deposit_date'] ?? date('Y-m-d');
    
    // Cash sources (matching new structure)
    $cash_from_dispatchers = floatval($_POST['cash_from_sales'] ?? 0);
    $cash_from_credit_settlements = floatval($_POST['cash_from_credit_collections'] ?? 0);
    $other_cash_income = floatval($_POST['other_cash_income'] ?? 0);
    
    // Shop sales net (auto-calculated)
    $shop_sales_net = floatval($_POST['shop_sales_net'] ?? 0);
    
    // Expenses
    $cashier_expenses = floatval($_POST['cashier_expenses'] ?? 0);
    $expenses_note = trim($_POST['expenses_note'] ?? '');
    
    // Bank deposit
    $bank_deposit_amount = floatval($_POST['bank_deposit_amount'] ?? 0);
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $deposit_slip_number = trim($_POST['deposit_slip_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Calculate totals (matching professional accounting approach)
    $total_cash_available = $cash_from_dispatchers + $shop_sales_net + $cash_from_credit_settlements + $other_cash_income;
    $expected_deposit = $total_cash_available - $cashier_expenses;
    $variance = $bank_deposit_amount - $expected_deposit;
    
    // Determine status
    if (abs($variance) < 0.01) {
        $status = 'balanced';
    } elseif ($variance > 0) {
        $status = 'surplus';
    } else {
        $status = 'short';
    }
    
    if ($bank_deposit_amount <= 0) {
        $error = 'Bank deposit amount must be greater than zero';
    } elseif (empty($bank_name)) {
        $error = 'Bank name is required';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("
                INSERT INTO bank_deposits
                (deposit_date, bank_deposit_amount,
                 cash_from_sales, cash_from_credit_collections, other_cash_income,
                 cashier_expenses, expenses_note, expected_deposit, variance,
                 bank_name, account_number, deposit_slip_number, recorded_by, depositor_name,
                 status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $deposit_date, $bank_deposit_amount,
                $cash_from_dispatchers, $cash_from_credit_settlements, $other_cash_income,
                $cashier_expenses, $expenses_note, $expected_deposit, $variance,
                $bank_name, $account_number, $deposit_slip_number, $_SESSION['user_id'], $_SESSION['name'] ?? 'Unknown',
                $status, $notes
            ]);
            
            $_SESSION['deposit_success'] = 'Bank deposit recorded successfully. Variance: ₦' . number_format(abs($variance), 2) . ' (' . ($variance >= 0 ? 'Surplus' : 'Short') . ')';
            header('Location: deposit.php?date=' . urlencode($deposit_date));
            exit();
            
        } catch (Exception $e) {
            $error = 'Failed to record deposit: ' . $e->getMessage();
        }
    }
    }
}
        $credit_collections_cash = 0;
        try {
            $stmt = executeQuery("\n        SELECT COALESCE(SUM(amount), 0) as total \n        FROM creditor_remittances \n        WHERE payment_date = ? AND payment_method = 'cash'\n    ", [$record_date]);
            $credit_collections_cash = $stmt->fetch()['total'];
        } catch (PDOException $e) {
            $credit_collections_cash = 0;
        }

// Get today's deposits
$today_deposits = [];
try {
    $stmt = executeQuery("
        SELECT * FROM bank_deposits 
        WHERE deposit_date = ? AND recorded_by = ?
        ORDER BY recorded_at DESC
    ", [$record_date, $_SESSION['user_id']]);
    $today_deposits = $stmt->fetchAll();
} catch (PDOException $e) {
    $today_deposits = [];
}

// Get dispatcher sales data for the date (matching end-of-day.php approach)
$dispatcher_sales = null;
try {
    $stmt = executeQuery("
        SELECT 
            COALESCE(SUM(cash_collected), 0) as total_cash,
            COALESCE(SUM(transfer_amount), 0) as total_transfer,
            COALESCE(SUM(credit_amount), 0) as total_credit_sales,
            COALESCE(SUM(credit_settlement), 0) as total_credit_settlement,
            COALESCE(SUM(expenses_amount), 0) as total_expenses
        FROM daily_activities
        WHERE activity_date = ?
    ", [$record_date]);
    $dispatcher_sales = $stmt->fetch();
} catch (PDOException $e) {
    $dispatcher_sales = null;
}

// Calculate shop sales totals
// Get shop sales data for the date (matching end-of-day.php approach)
$shop_sales_data = null;
try {
    $stmt = executeQuery("\n        SELECT\n            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_amount,\n            COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_amount,\n            COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_amount,\n            COALESCE(SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END), 0) as credit_amount,\n            COALESCE(SUM(total_amount), 0) as total_shop_sales\n        FROM shop_sales\n        WHERE DATE(sale_date) = ?\n    ", [$record_date]);
    $shop_sales_data = $stmt->fetch();
} catch (PDOException $e) {
    $shop_sales_data = null;
}

// Get shop collections for the date
$shop_collections = null;
try {
    $stmt = executeQuery("\n        SELECT\n            COALESCE(SUM(CASE WHEN status != 'cleared' THEN collection_amount ELSE 0 END), 0) as unsettled_collections,\n            COALESCE(SUM(CASE WHEN status = 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as settled_acknowledged\n        FROM shop_collections\n        WHERE DATE(collection_date) = ?\n    ", [$record_date]);
    $shop_collections = $stmt->fetch();
} catch (PDOException $e) {
    $shop_collections = null;
}

// Calculate shop sales totals
$shop_sales_total = $shop_sales_data['total_shop_sales'] ?? 0;
$shop_sales_unsettled_collections = $shop_collections['unsettled_collections'] ?? 0;
$shop_sales_settled_acknowledged = $shop_collections['settled_acknowledged'] ?? 0;
$shop_cash = $shop_sales_data['cash_amount'] ?? 0;
$shop_transfer = $shop_sales_data['transfer_amount'] ?? 0;
$shop_card = $shop_sales_data['card_amount'] ?? 0;
$shop_credit = $shop_sales_data['credit_amount'] ?? 0;

// Deposit page uses cash + transfer as the shop source total
$stmt = executeQuery("
    SELECT payment_method, SUM(payment_amount) as total_amount
    FROM (
        SELECT ss.payment_method, ss.total_amount as payment_amount
        FROM shop_sales ss
        WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 0

        UNION ALL

        SELECT ssp.payment_method, ssp.amount as payment_amount
        FROM shop_sale_payments ssp
        JOIN shop_sales ss ON ss.id = ssp.shop_sale_id
        WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 1
    ) shop_payment_rows
    GROUP BY payment_method
", [$record_date, $record_date]);
foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $method => $amount) {
    $shop_sales_data[$method . '_amount'] = $amount;
}
$shop_cash = $shop_sales_data['cash_amount'] ?? 0;
$shop_transfer = $shop_sales_data['transfer_amount'] ?? 0;
$shop_card = $shop_sales_data['card_amount'] ?? 0;
$shop_credit = $shop_sales_data['credit_amount'] ?? 0;

$stmt = executeQuery("
    SELECT
        COALESCE(SUM(collection_amount), 0) as total_shop_expenses,
        COALESCE(SUM(amount_acknowledged), 0) as total_shop_returns
    FROM shop_collections
    WHERE DATE(collection_date) = ?
", [$record_date]);
$shop_expense_totals = $stmt->fetch();
$shop_total_expenses = floatval($shop_expense_totals['total_shop_expenses'] ?? 0);
$shop_total_returns = floatval($shop_expense_totals['total_shop_returns'] ?? 0);
$shop_net_cash = $shop_cash - $shop_total_expenses + $shop_total_returns;
$shop_sales_net = $shop_net_cash + $shop_transfer;

// Calculate dispatcher totals (matching end-of-day.php approach)
$dispatcher_cash = $dispatcher_sales['total_cash'] ?? 0;
$dispatcher_transfer = $dispatcher_sales['total_transfer'] ?? 0;
$dispatcher_card = 0; // Card column doesn't exist in daily_activities table
$dispatcher_credit_sales = $dispatcher_sales['total_credit_sales'] ?? 0;
$dispatcher_credit_settlement = $dispatcher_sales['total_credit_settlement'] ?? 0;
$dispatcher_expenses = $dispatcher_sales['total_expenses'] ?? 0;

// Combined values used by the deposit form
$dispatcher_collections_total = $dispatcher_cash + $dispatcher_transfer;
$shop_collections_total = $shop_sales_net;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Cashier bank deposit dashboard for Merrylay Enterprises - Record bank deposits and track cash reconciliation">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Cashier Bank Deposit - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .header { background: #f59e0b; color: white; padding: 1.5rem; margin-bottom: 2rem; }
        .section-card { background: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .section-title { font-size: 1.1rem; font-weight: 600; color: #374151; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e5e7eb; }
        .btn-submit { background: #f59e0b; color: white; }
        .btn-submit:hover { background: #d97706; color: white; }
        .summary-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 1rem; }
        .calculation-box { background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 1rem; margin-top: 1rem; }
        .variance-positive { color: #059669; font-weight: 700; }
        .variance-negative { color: #dc2626; font-weight: 700; }
        .auto-fill { background: #eff6ff; border-left: 3px solid #3b82f6; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.25rem;
            border: 1px solid #e5e7eb;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            margin-bottom: 0.25rem;
            font-size: 0.65rem;
            letter-spacing: 0.5px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-icon.blue {
            background: #eff6ff;
            color: #3b82f6;
        }
        
        .stat-icon.green {
            background: #f0fdf4;
            color: #10b981;
        }
        
        .stat-icon.yellow {
            background: #fefce8;
            color: #eab308;
        }
        
        .stat-icon.orange {
            background: #fef3c7;
            color: #d97706;
        }
        
        .stat-icon.red {
            background: #fef2f2;
            color: #ef4444;
        }
        
        .stat-icon.purple {
            background: #faf5ff;
            color: #a855f7;
        }

        .stat-label {
            color: #6b7280;
            font-weight: 500;
            font-size: 0.75rem;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin: 0.25rem 0;
        }

        /* Accounting Section Styling */
        .dispatcher-section {
            border-left: 3px solid #3b82f6;
        }

        .shop-section {
            border-left: 3px solid #10b981;
        }

        .credit-section {
            border-left: 3px solid #8b5cf6;
        }

        .cash-section {
            border-left: 3px solid #f59e0b;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
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
                <div class="nav-section-title dispatch">Dispatch Operations</div>
                <a href="../dispatch/trips/record.php" class="nav-link <?php echo $currentPage === 'record.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="nav-text">New Dispatch</span>
                </a>
                <a href="../dispatch/trips/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-list"></i></span>
                    <span class="nav-text">Manage Dispatches</span>
                </a>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../dispatch/drivers.php" class="nav-link <?php echo $currentPage === 'drivers.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span class="nav-text">Dispatchers</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title sales">Sales & Finance</div>
                <a href="../dispatch/daily/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'daily') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Daily Sales</span>
                </a>
                <a href="../dispatch/daily/account-summary.php" class="nav-link <?php echo $currentPage === 'account-summary.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>
                <a href="../finance/daily_balance.php" class="nav-link <?php echo $currentPage === 'daily_balance.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>
                <?php if (isCashier()): ?>
                <a href="../cashier/deposit.php" class="nav-link <?php echo $currentPage === 'deposit.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="../admin/end-of-day.php" class="nav-link <?php echo $currentPage === 'end-of-day.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
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
                <?php if (hasRole('admin')): ?>
                <a href="../admin/data_management.php" class="nav-link <?php echo $currentPage === 'data_management.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <a href="../admin/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-text">Stock Statistics</span>
                </a>
                <div class="nav-section-title admin">System</div>
                <a href="../admin/backup.php" class="nav-link <?php echo $currentPage === 'backup.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
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
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search deposits, banks..." id="depositsSearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <button class="icon-btn position-relative" title="Notifications">
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
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <?php if (hasRole(['admin'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
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
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <h1><i class="fas fa-university me-3"></i>Bank Deposit</h1>
                            <p>Record bank deposits and track cash reconciliation</p>
                            <div class="welcome-meta">
                                <div>
                                    <i class="fas fa-calendar-day"></i>
                                    <span><?php echo date('l, F j, Y', strtotime($record_date)); ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <span id="currentTime"><?php echo date('H:i'); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="reconciliation.php" class="btn btn-outline-secondary">
                                <i class="fas fa-chart-bar me-2"></i>View Reconciliation
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($error): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Auto-filled Data from System -->
                <?php if ($dispatcher_sales || $shop_sales_data): ?>
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-robot me-2"></i>Auto-Generated from System
                            </h5>
                            <p class="card-subtitle">Data for <?php echo date('F j, Y', strtotime($record_date)); ?></p>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Dispatcher Sales Section -->
                        <?php if ($dispatcher_sales): ?>
                        <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Dispatcher Sales</h6>
                        <div class="stats-grid mb-4">
                            <div class="stat-card dispatcher-section">
                                <div class="stat-icon green">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($dispatcher_cash, 0); ?></div>
                                <div class="stat-label">Cash</div>
                            </div>
                            <div class="stat-card dispatcher-section">
                                <div class="stat-icon blue">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($dispatcher_transfer, 0); ?></div>
                                <div class="stat-label">Transfer</div>
                            </div>
                            <div class="stat-card dispatcher-section">
                                <div class="stat-icon orange">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($dispatcher_credit_settlement, 0); ?></div>
                                <div class="stat-label">Credit Settlements</div>
                            </div>
                            <div class="stat-card dispatcher-section">
                                <div class="stat-icon red">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($dispatcher_expenses, 0); ?></div>
                                <div class="stat-label">Expenses</div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Shop Sales Section -->
                        <?php if ($shop_sales_data): ?>
                        <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Shop Sales</h6>
                        <div class="stats-grid mb-4">
                            <div class="stat-card shop-section">
                                <div class="stat-icon green">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($shop_cash, 0); ?></div>
                                <div class="stat-label">Cash</div>
                            </div>
                            <div class="stat-card shop-section">
                                <div class="stat-icon blue">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($shop_transfer, 0); ?></div>
                                <div class="stat-label">Transfer</div>
                            </div>
                            <div class="stat-card shop-section">
                                <div class="stat-icon purple">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($shop_card, 0); ?></div>
                                <div class="stat-label">Card</div>
                            </div>
                            <div class="stat-card shop-section">
                                <div class="stat-icon orange">
                                    <i class="fas fa-shopping-cart"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($shop_sales_total, 0); ?></div>
                                <div class="stat-label">Total Sales</div>
                            </div>
                            <div class="stat-card shop-section">
                                <div class="stat-icon yellow">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($shop_sales_net, 0); ?></div>
                                <div class="stat-label">Cash + Transfer</div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Credit Collections Section -->
                        <?php if ($credit_collections_cash > 0): ?>
                        <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Credit Collections</h6>
                        <div class="stats-grid mb-4">
                            <div class="stat-card credit-section">
                                <div class="stat-icon green">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($credit_collections_cash, 0); ?></div>
                                <div class="stat-label">Cash Collections</div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="mt-3 text-center">
                            <button type="button" class="btn btn-primary" onclick="autoFillData()">
                                <i class="fas fa-magic me-2"></i>Auto-Fill Credit Collections
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                    <!-- Date Selection -->
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-calendar me-2"></i>Deposit Date
                                </h5>
                                <p class="card-subtitle">Select date to see auto-generated data</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="deposit.php">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label" for="depositDate">Select Date</label>
                                    <input type="date" id="depositDate" name="date" class="form-control" value="<?php echo htmlspecialchars($record_date); ?>" required onchange="this.form.submit()">
                                </div>
                            </div>
                            </form>
                        </div>
                    </div>

                    <form method="POST" action="deposit.php" id="depositForm">
                        <input type="hidden" name="record_deposit" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="deposit_date" value="<?php echo htmlspecialchars($record_date, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="shop_sales_net" id="shop_sales_net" value="<?php echo number_format($shop_sales_net, 2, '.', ''); ?>">

                    <!-- Cash Sources -->
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-money-bill-wave me-2"></i>Cash Sources
                                </h5>
                                <p class="card-subtitle">Total cash available for deposit</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Dispatcher Collections</h6>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="cash_from_sales">Dispatcher Cash + Transfer</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="cash_from_sales" id="cash_from_sales" class="form-control cash-source" step="0.01" min="0" value="<?php echo htmlspecialchars($depositInputValue('cash_from_sales', number_format($dispatcher_collections_total, 2, '.', '')), ENT_QUOTES, 'UTF-8'); ?>" required onchange="calculateTotals()">
                                    </div>
                                    <small class="text-muted">Cash and transfer received from dispatchers</small>
                                </div>
                                <div class="col-md-8">
                                    <div class="form-label">Dispatcher Collections Breakdown</div>
                                    <div class="alert alert-light border mb-0">
                                        <small class="text-muted">
                                            <strong>Cash:</strong> ₦<?php echo number_format($dispatcher_cash, 2); ?><br>
                                            <strong>Transfer:</strong> ₦<?php echo number_format($dispatcher_transfer, 2); ?><br>
                                            <strong>Cash + Transfer:</strong> ₦<?php echo number_format($dispatcher_collections_total, 2); ?><br>
                                            <strong>Credit Settlements:</strong> ₦<?php echo number_format($dispatcher_credit_settlement, 2); ?><br>
                                            <strong>Expenses:</strong> ₦<?php echo number_format($dispatcher_expenses, 2); ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="cash_from_credit_collections">Credit Settlements</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="cash_from_credit_collections" id="cash_from_credit_collections" class="form-control cash-source" step="0.01" min="0" value="<?php echo htmlspecialchars($depositInputValue('cash_from_credit_collections', '0'), ENT_QUOTES, 'UTF-8'); ?>" onchange="calculateTotals()">
                                    </div>
                                    <small class="text-muted">Cash received from credit settlements</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="other_cash_income">Other Cash Income</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="other_cash_income" id="other_cash_income" class="form-control cash-source" step="0.01" min="0" value="<?php echo htmlspecialchars($depositInputValue('other_cash_income', '0'), ENT_QUOTES, 'UTF-8'); ?>" onchange="calculateTotals()">
                                    </div>
                                    <small class="text-muted">Any other cash sources</small>
                                </div>
                            </div>
                            <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Shop Sales</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label" for="shop_sales_net_display">Shop Net Cash + Transfer</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="text" id="shop_sales_net_display" class="form-control" value="<?php echo number_format($shop_sales_net, 2); ?>" readonly>
                                    </div>
                                    <small class="text-muted">Cash after shop expenses, plus transfer sales (auto-calculated)</small>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-label">Shop Sales Breakdown</div>
                                    <div class="alert alert-light border mb-0">
                                        <small class="text-muted">
                                            <strong>Cash:</strong> ₦<?php echo number_format($shop_cash, 2); ?><br>
                                            <strong>Shop Expenses:</strong> ₦<?php echo number_format($shop_total_expenses, 2); ?><br>
                                            <strong>Net Shop Cash Available:</strong> ₦<?php echo number_format($shop_net_cash, 2); ?><br>
                                            <strong>Transfer:</strong> ₦<?php echo number_format($shop_transfer, 2); ?><br>
                                            <strong>Cash + Transfer:</strong> ₦<?php echo number_format($shop_sales_net, 2); ?><br>
                                            <strong>Total Sales:</strong> ₦<?php echo number_format($shop_sales_total, 2); ?><br>
                                            <strong>Cash Returned:</strong> ₦<?php echo number_format($shop_total_returns, 2); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cashier Expenses -->
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-receipt me-2"></i>Cashier Expenses
                                </h5>
                                <p class="card-subtitle">Expenses incurred before deposit</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-5">
                                    <label class="form-label" for="cashier_expenses">Expenses Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="cashier_expenses" id="cashier_expenses" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($depositInputValue('cashier_expenses', '0'), ENT_QUOTES, 'UTF-8'); ?>" onchange="calculateTotals()">
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label" for="cashierExpensesNote">Expenses Note</label>
                                    <input type="text" id="cashierExpensesNote" name="expenses_note" class="form-control" value="<?php echo htmlspecialchars($depositInputValue('expenses_note'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., Stationery, transportation, bank charges">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Live Calculation -->
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-calculator me-2"></i>Live Calculation
                                </h5>
                                <p class="card-subtitle">Real-time deposit calculation</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <small class="text-muted">Total Cash Available</small>
                                    <div class="h4 mb-0" id="display_total_cash">₦0.00</div>
                                </div>
                                <div class="col-md-1 text-center pt-2">
                                    <i class="fas fa-minus text-muted"></i>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted">Expenses</small>
                                    <div class="h4 mb-0 text-danger" id="display_expenses">₦0.00</div>
                                </div>
                                <div class="col-md-1 text-center pt-2">
                                    <i class="fas fa-equals text-muted"></i>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Expected Bank Deposit</small>
                                    <div class="h4 mb-0 text-success" id="display_expected">₦0.00</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Deposit Details -->
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-university me-2"></i>Bank Deposit Details
                                </h5>
                                <p class="card-subtitle">Bank information for the deposit</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label" for="bank_deposit_amount">Amount Deposited *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="bank_deposit_amount" id="bank_deposit_amount" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($depositInputValue('bank_deposit_amount', '0'), ENT_QUOTES, 'UTF-8'); ?>" required onchange="calculateTotals()">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="bankName">Bank Name *</label>
                                    <input type="text" id="bankName" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($depositInputValue('bank_name'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., First Bank, GTBank" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="bankAccountNumber">Account Number</label>
                                    <input type="text" id="bankAccountNumber" name="account_number" class="form-control" value="<?php echo htmlspecialchars($depositInputValue('account_number'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Optional">
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="depositSlipNumber">Deposit Slip Number</label>
                                    <input type="text" id="depositSlipNumber" name="deposit_slip_number" class="form-control" value="<?php echo htmlspecialchars($depositInputValue('deposit_slip_number'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Reference number">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label" for="depositNotes">Additional Notes</label>
                                    <input type="text" id="depositNotes" name="notes" class="form-control" value="<?php echo htmlspecialchars($depositInputValue('notes'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Any other relevant information">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Variance Display -->
                    <div class="content-card mb-4" id="varianceBox" style="display: none;">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-balance-scale me-2"></i>Reconciliation
                                </h5>
                                <p class="card-subtitle">Compare expected vs actual deposit</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <small class="text-muted">Expected Deposit</small>
                                    <div class="h5 mb-0" id="variance_expected">₦0.00</div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Actual Deposit</small>
                                    <div class="h5 mb-0" id="variance_actual">₦0.00</div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Variance</small>
                                    <div class="h4 mb-0" id="variance_amount">₦0.00</div>
                                    <small id="variance_status" class="badge bg-secondary">-</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-2"></i>Record Deposit
                        </button>
                    </div>
                </form>

                <!-- Today's Deposits -->
                <?php if (!empty($today_deposits)): ?>
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-history me-2"></i>Today's Deposits
                            </h5>
                            <p class="card-subtitle">Deposits recorded for <?php echo date('F j, Y', strtotime($record_date)); ?></p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Time</th>
                                        <th>Bank</th>
                                        <th class="text-end">Expected</th>
                                        <th class="text-end">Deposited</th>
                                        <th class="text-end">Variance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($today_deposits as $d): ?>
                                        <tr>
                                            <td><?php echo date('g:i A', strtotime($d['recorded_at'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($d['bank_name']); ?></strong></td>
                                            <td class="text-end">₦<?php echo number_format($d['expected_deposit'], 2); ?></td>
                                            <td class="text-end">₦<?php echo number_format($d['bank_deposit_amount'], 2); ?></td>
                                            <td class="text-end <?php echo $d['variance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $d['variance'] >= 0 ? '+' : ''; ?>₦<?php echo number_format($d['variance'], 2); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $d['status'] === 'balanced' ? 'success' : 
                                                         ($d['status'] === 'surplus' ? 'info' : 'warning'); 
                                                ?>">
                                                    <?php echo ucfirst($d['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
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
        document.getElementById('depositsSearch')?.addEventListener('input', function(e) {
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
        
        function calculateTotals() {
            const cashSales = parseFloat(document.getElementById('cash_from_sales').value) || 0;
            const shopSalesNet = parseFloat(document.getElementById('shop_sales_net').value) || 0;
            const creditCollections = parseFloat(document.getElementById('cash_from_credit_collections').value) || 0;
            const otherIncome = parseFloat(document.getElementById('other_cash_income').value) || 0;
            const expenses = parseFloat(document.getElementById('cashier_expenses').value) || 0;
            const bankDeposit = parseFloat(document.getElementById('bank_deposit_amount').value) || 0;
            
            const totalCash = cashSales + shopSalesNet + creditCollections + otherIncome;
            const expected = totalCash - expenses;
            const variance = bankDeposit - expected;
            
            // Update display
            document.getElementById('display_total_cash').textContent = '₦' + totalCash.toFixed(2);
            document.getElementById('display_expenses').textContent = '₦' + expenses.toFixed(2);
            document.getElementById('display_expected').textContent = '₦' + expected.toFixed(2);
            
            // Show variance box if deposit entered
            if (bankDeposit > 0) {
                document.getElementById('varianceBox').style.display = 'block';
                document.getElementById('variance_expected').textContent = '₦' + expected.toFixed(2);
                document.getElementById('variance_actual').textContent = '₦' + bankDeposit.toFixed(2);
                document.getElementById('variance_amount').textContent = (variance >= 0 ? '+' : '') + '₦' + variance.toFixed(2);
                
                const varianceEl = document.getElementById('variance_amount');
                const statusEl = document.getElementById('variance_status');
                
                if (Math.abs(variance) < 0.01) {
                    varianceEl.className = 'h4 mb-0 text-success';
                    statusEl.className = 'badge bg-success';
                    statusEl.textContent = 'BALANCED';
                } else if (variance > 0) {
                    varianceEl.className = 'h4 mb-0 text-info';
                    statusEl.className = 'badge bg-info';
                    statusEl.textContent = 'SURPLUS';
                } else {
                    varianceEl.className = 'h4 mb-0 text-danger';
                    statusEl.className = 'badge bg-warning';
                    statusEl.textContent = 'SHORT';
                }
            }
        }
        
        <?php if ($dispatcher_sales || $shop_sales_data): ?>
        function autoFillData() {
            // Dispatcher cash + transfer is now auto-populated, but user can override
            // Fill credit settlements
            document.getElementById('cash_from_credit_collections').value = '<?php echo ($dispatcher_credit_settlement ?? 0) + ($credit_collections_cash ?? 0); ?>';
            calculateTotals();
        }
        <?php endif; ?>
        
        // Initial calculation
        calculateTotals();
    </script>
</body>
</html>


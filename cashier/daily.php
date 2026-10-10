<?php
/**
 * Cashier Daily Financial Recording Page
 * 
 * Allows cashiers to record all financial transactions on a daily basis
 * - Cash received
 * - Bank transfers
 * - Expenses
 * - Bank deposits
 * - Outstanding payments
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

// Check if user has permission to record financials
if (!canRecordFinancials()) {
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';
$success = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get daily financial summary
$financial_summary = null;
try {
    $stmt = executeQuery("
        SELECT 
            DATE(s.created_at) as transaction_date,
            COUNT(DISTINCT s.id) as total_transactions,
            COALESCE(SUM(s.total_amount), 0) as total_sales,
            COALESCE(SUM(s.amount_paid), 0) as total_paid,
            COALESCE(SUM(s.change_amount), 0) as total_change,
            COUNT(DISTINCT s.customer_id) as unique_customers
        FROM sales s
        WHERE DATE(s.created_at) = ?
            AND s.status = 'completed'
        GROUP BY DATE(s.created_at)
    ", [$selected_date]);
    
    $financial_summary = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Financial summary error: " . $e->getMessage());
    $financial_summary = null;
}

// Get cashier sales recorded through customer_payments
$cashier_sales_summary = [
    'total_transactions' => 0,
    'total_amount' => 0
];
try {
    $stmt = executeQuery("
        SELECT
            COUNT(*) as total_transactions,
            COALESCE(SUM(amount), 0) as total_amount
        FROM customer_payments
        WHERE DATE(payment_date) = ?
    ", [$selected_date]);
    $cashier_sales_summary = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Cashier sales summary error: " . $e->getMessage());
}

$total_transactions = ($financial_summary['total_transactions'] ?? 0) + ($cashier_sales_summary['total_transactions'] ?? 0);
$total_sales = ($financial_summary['total_sales'] ?? 0) + ($cashier_sales_summary['total_amount'] ?? 0);
$total_paid = ($financial_summary['total_paid'] ?? 0) + ($cashier_sales_summary['total_amount'] ?? 0);

// Get payment method breakdown
$payment_breakdown = [];
try {
    $stmt = executeQuery("
        SELECT payment_method, COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total_amount
        FROM (
            SELECT s.payment_method, s.total_amount as amount
            FROM sales s
            WHERE DATE(s.created_at) = ?
                AND s.status = 'completed'
            UNION ALL
            SELECT cp.payment_method, cp.amount
            FROM customer_payments cp
            WHERE DATE(cp.payment_date) = ?
        ) combined
        GROUP BY payment_method
    ", [$selected_date, $selected_date]);
    
    $payment_breakdown = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Payment breakdown error: " . $e->getMessage());
    $payment_breakdown = [];
}

// Get dispatcher collections including debt collected on the same day
$dispatcher_collections_summary = [
    'cash_sales' => 0,
    'transfer_sales' => 0,
    'cash_debt_collected' => 0,
    'transfer_debt_collected' => 0,
    'total_collected' => 0
];
$dispatcher_collections_by_dispatcher = [];
try {
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(dr.cash_sales), 0) as cash_sales,
            COALESCE(SUM(dr.transfer_sales), 0) as transfer_sales,
            COALESCE(SUM(dr.cash_debt_collected), 0) as cash_debt_collected,
            COALESCE(SUM(dr.transfer_debt_collected), 0) as transfer_debt_collected,
            COALESCE(SUM(dr.cash_sales + dr.transfer_sales + dr.cash_debt_collected + dr.transfer_debt_collected), 0) as total_collected
        FROM dispatch_returns dr
        WHERE DATE(dr.recorded_at) = ?
    ", [$selected_date]);
    $dispatcher_collections_summary = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Dispatcher collections summary error: " . $e->getMessage());
}

try {
    $stmt = executeQuery("
        SELECT 
            d.dispatcher_name,
            COALESCE(SUM(dr.cash_sales + dr.transfer_sales + dr.cash_debt_collected + dr.transfer_debt_collected), 0) as total_collected,
            COALESCE(SUM(dr.cash_sales + dr.cash_debt_collected), 0) as total_cash,
            COALESCE(SUM(dr.transfer_sales + dr.transfer_debt_collected), 0) as total_transfer
        FROM dispatch_returns dr
        JOIN dispatches d ON dr.dispatch_id = d.id
        WHERE DATE(dr.recorded_at) = ?
        GROUP BY d.dispatcher_name
        ORDER BY d.dispatcher_name
    ", [$selected_date]);
    $dispatcher_collections_by_dispatcher = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dispatcher collections by dispatcher error: " . $e->getMessage());
}

// Get recent transactions, including cashier sales recorded through customer_payments
$recent_transactions = [];
$transaction_debug = ['sales_count' => 0, 'payments_count' => 0, 'selected_date' => $selected_date];
try {
    // Check sales table separately for debugging
    $sales_check = executeQuery("
        SELECT COUNT(*) as count FROM sales 
        WHERE DATE(created_at) = ? AND status = 'completed'
    ", [$selected_date]);
    $transaction_debug['sales_count'] = $sales_check->fetch()['count'] ?? 0;

    // Check customer_payments separately for debugging
    $payments_check = executeQuery("
        SELECT COUNT(*) as count FROM customer_payments 
        WHERE DATE(payment_date) = ?
    ", [$selected_date]);
    $transaction_debug['payments_count'] = $payments_check->fetch()['count'] ?? 0;

    $stmt = executeQuery("
        SELECT * FROM (
            SELECT
                s.id as transaction_id,
                s.receipt_number as reference,
                COALESCE(c.name, 'Walk-in Customer') as customer_name,
                u.name as cashier_name,
                s.total_amount as total_amount,
                s.amount_paid as amount_paid,
                s.change_amount as change_amount,
                s.payment_method as payment_method,
                s.created_at as created_at,
                'sale' as source
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN users u ON s.user_id = u.id
            WHERE DATE(s.created_at) = ?
                AND s.status = 'completed'
            UNION ALL
            SELECT
                cp.id as transaction_id,
                CONCAT('PAY#', cp.id) as reference,
                'Walk-in Customer' as customer_name,
                u.name as cashier_name,
                cp.amount as total_amount,
                cp.amount as amount_paid,
                0.00 as change_amount,
                cp.payment_method as payment_method,
                cp.created_at as created_at,
                'customer_payment' as source
            FROM customer_payments cp
            LEFT JOIN users u ON cp.recorded_by = u.id
            WHERE DATE(cp.payment_date) = ?
        ) combined
        ORDER BY created_at DESC
        LIMIT 50
    ", [$selected_date, $selected_date]);

    $recent_transactions = $stmt->fetchAll();
    $transaction_debug['combined_count'] = count($recent_transactions);
} catch (PDOException $e) {
    error_log("Recent transactions error: " . $e->getMessage());
    $recent_transactions = [];
    $transaction_debug['error'] = $e->getMessage();
}

// Get pending trip returns
$pending_returns = [];
try {
    $stmt = executeQuery("
        SELECT 
            d.id,
            d.dispatch_number,
            d.dispatcher_name,
            d.dispatch_date,
            d.expected_amount,
            COUNT(DISTINCT di.id) as item_count,
            SUM(di.quantity) as total_quantity
        FROM dispatches d
        LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
        LEFT JOIN dispatch_returns dr ON d.id = dr.dispatch_id
        WHERE d.status = 'shipped'
        AND dr.id IS NULL
        AND DATE(d.dispatch_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY d.id
        ORDER BY d.dispatch_date DESC
        LIMIT 10
    ");

    $pending_returns = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Pending returns error: " . $e->getMessage());
    $pending_returns = [];
}

// Get dispatcher activities for the day
$dispatcher_activities = [];
try {
    $stmt = executeQuery("
        SELECT
            d.dispatcher_name,
            COUNT(DISTINCT d.id) as total_trips,
            SUM(d.expected_amount) as total_expected,
            COALESCE(SUM(di.quantity), 0) as total_quantity_loaded,
            COALESCE(MAX(da.total_actual_sales), 0) as total_actual_sales,
            COALESCE(SUM(dr.total_returned_value), 0) as total_returns,
            COALESCE(SUM(de.total_expenses), 0) as total_expenses,
            COALESCE(SUM(cp.total_cash_payments), 0) as total_cash_received,
            COALESCE(SUM(cp.total_transfer_payments), 0) as total_transfer_received,
            SUM(d.expected_amount) - COALESCE(SUM(dr.total_returned_value), 0) - COALESCE(SUM(cp.total_cash_payments), 0) - COALESCE(SUM(cp.total_transfer_payments), 0) as balance_due
        FROM dispatches d
        LEFT JOIN (
            SELECT driver_name, activity_date,
                SUM(cash_collected + transfer_amount + credit_amount) as total_actual_sales
            FROM daily_activities
            GROUP BY driver_name, activity_date
        ) da ON d.dispatcher_name = da.driver_name AND d.dispatch_date = da.activity_date
        LEFT JOIN (
            SELECT dispatch_id, SUM(quantity) as quantity
            FROM dispatch_items
            GROUP BY dispatch_id
        ) di ON d.id = di.dispatch_id
        LEFT JOIN (
            SELECT dispatch_id, SUM(quantity_returned * unit_price) as total_returned_value
            FROM dispatch_returns
            WHERE DATE(recorded_at) = ?
            GROUP BY dispatch_id
        ) dr ON d.id = dr.dispatch_id
        LEFT JOIN (
            SELECT dispatcher_name, SUM(amount) as total_expenses
            FROM dispatcher_expenses
            WHERE DATE(expense_date) = ?
            GROUP BY dispatcher_name
        ) de ON d.dispatcher_name = de.dispatcher_name
        LEFT JOIN (
            SELECT
                dispatcher_name,
                SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as total_cash_payments,
                SUM(CASE WHEN payment_method = 'transfer' THEN amount ELSE 0 END) as total_transfer_payments
            FROM customer_payments
            WHERE DATE(payment_date) = ?
            GROUP BY dispatcher_name
        ) cp ON d.dispatcher_name = cp.dispatcher_name
        WHERE DATE(d.dispatch_date) = ?
        GROUP BY d.dispatcher_name
        ORDER BY d.dispatcher_name
    ", [$selected_date, $selected_date, $selected_date, $selected_date]);

    $dispatcher_activities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dispatcher activities error: " . $e->getMessage());
    $dispatcher_activities = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Sales Records - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }

        .header-section {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
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

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .summary-card.transactions {
            border-left-color: #667eea;
        }

        .summary-card.sales {
            border-left-color: #10b981;
        }

        .summary-card.paid {
            border-left-color: #3b82f6;
        }

        .summary-card.customers {
            border-left-color: #f59e0b;
        }

        .summary-card h6 {
            color: #6b7280;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .summary-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .filter-row .form-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-row .form-group label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .transactions-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .transactions-table thead {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
        }

        .transactions-table th {
            padding: 1rem;
            font-weight: 700;
            color: #6b7280;
            border-bottom: 1px solid #d1d5db;
        }

        .transactions-table td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .payment-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .payment-cash { background: #d1fae5; color: #059669; }
        .payment-card { background: #dbeafe; color: #1e40af; }
        .payment-transfer { background: #fef3c7; color: #92400e; }
        .payment-pos { background: #fce7f3; color: #db2777; }
        .payment-credit { background: #fee2e2; color: #dc2626; }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .btn-record {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
            font-weight: 600;
        }

        .btn-record:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
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

        <!-- Main content -->
        <main class="main-content" id="mainContent">
            <div class="container-fluid py-4">
                <!-- Header -->
                <div class="header-section">
                    <h1><i class="fas fa-chart-line me-2"></i>Daily Sales Records</h1>
                    <p>Sales records for <?php echo date('l, F j, Y', strtotime($selected_date)); ?></p>
                </div>

                <!-- Filters -->
                <div class="filter-row">
                    <div class="form-group">
                        <label for="date" class="form-label">Select Date</label>
                        <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="updateFilters()">
                    </div>

                    <!-- Daily Sales Records - View Only -->
                </div>

                <!-- Summary Cards -->
                <?php if ($financial_summary): ?>
                <div class="summary-cards">
                    <div class="summary-card transactions">
                        <h6><i class="fas fa-receipt me-2"></i>Total Transactions</h6>
                        <div class="value"><?php echo $total_transactions; ?></div>
                    </div>

                    <div class="summary-card sales">
                        <h6><i class="fas fa-money-bill-wave me-2"></i>Total Sales</h6>
                        <div class="value">₦<?php echo number_format($total_sales, 2); ?></div>
                    </div>

                    <div class="summary-card sales">
                        <h6><i class="fas fa-dollar-sign me-2"></i>Cashier Records</h6>
                        <div class="value">₦<?php echo number_format($cashier_sales_summary['total_amount'] ?? 0, 2); ?></div>
                    </div>

                    <div class="summary-card paid">
                        <h6><i class="fas fa-hand-holding-usd me-2"></i>Total Paid</h6>
                        <div class="value">₦<?php echo number_format($total_paid, 2); ?></div>
                    </div>

                    <div class="summary-card sales">
                        <h6><i class="fas fa-user-check me-2"></i>Collections by Dispatchers</h6>
                        <div class="value">₦<?php echo number_format($dispatcher_collections_summary['total_collected'] ?? 0, 2); ?></div>
                    </div>

                    <div class="summary-card customers">
                        <h6><i class="fas fa-users me-2"></i>Unique Customers</h6>
                        <div class="value"><?php echo $financial_summary['unique_customers'] ?? 0; ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($dispatcher_collections_by_dispatcher)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-friends me-2"></i>Daily Collections by Dispatchers</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>Dispatcher (Driver)</th>
                                        <th>Cash</th>
                                        <th>Transfer</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dispatcher_collections_by_dispatcher as $collection): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($collection['dispatcher_name']); ?></td>
                                        <td>₦<?php echo number_format($collection['total_cash'], 2); ?></td>
                                        <td>₦<?php echo number_format($collection['total_transfer'], 2); ?></td>
                                        <td>₦<?php echo number_format($collection['total_collected'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Dispatcher Activities Table -->
                <?php if (!empty($dispatcher_activities)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-truck me-2"></i>Dispatcher Activities for <?php echo date('F j, Y', strtotime($selected_date)); ?></h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Dispatcher</th>
                                        <th>Trips</th>
                                        <th>Qty Loaded</th>
                                        <th>Expected (₦)</th>
                                        <th>Actual Sales (₦)</th>
                                        <th>Returns (₦)</th>
                                        <th>Cash Received (₦)</th>
                                        <th>Transfer Received (₦)</th>
                                        <th>Expenses (₦)</th>
                                        <th>Balance Due (₦)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dispatcher_activities as $activity): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($activity['dispatcher_name']); ?></strong></td>
                                        <td><?php echo $activity['total_trips']; ?></td>
                                        <td><?php echo number_format($activity['total_quantity_loaded']); ?></td>
                                        <td><?php echo number_format($activity['total_expected'], 2); ?></td>
                                        <td><?php echo number_format($activity['total_actual_sales'], 2); ?></td>
                                        <td><?php echo number_format($activity['total_returns'], 2); ?></td>
                                        <td class="text-success"><?php echo number_format($activity['total_cash_received'], 2); ?></td>
                                        <td class="text-info"><?php echo number_format($activity['total_transfer_received'], 2); ?></td>
                                        <td class="text-danger"><?php echo number_format($activity['total_expenses'], 2); ?></td>
                                        <td class="<?php echo $activity['balance_due'] > 0 ? 'text-warning fw-bold' : 'text-success fw-bold'; ?>">
                                            <?php echo number_format($activity['balance_due'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Method Breakdown -->
                <?php if (!empty($payment_breakdown)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Payment Method Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($payment_breakdown as $payment): ?>
                            <div class="col-md-3 mb-3">
                                <div class="p-3 border rounded">
                                    <h6 class="text-muted text-uppercase small"><?php echo $payment['payment_method']; ?></h6>
                                    <h4 class="mb-0">₦<?php echo number_format($payment['total_amount'], 2); ?></h4>
                                    <small class="text-muted"><?php echo $payment['transaction_count']; ?> transactions</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pending Trip Returns -->
                <?php if (!empty($pending_returns)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Trip Returns</h5>
                        <small class="text-muted">Record returns in Step 2: Activities &rarr; Record Returns</small>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($pending_returns as $pending): ?>
                            <div class="col-md-6 mb-3">
                                <div class="p-3 border rounded">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($pending['dispatch_number']); ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($pending['dispatcher_name']); ?>
                                        <span class="mx-2">|</span>
                                        <i class="fas fa-calendar me-1"></i><?php echo date('M d', strtotime($pending['dispatch_date'])); ?>
                                    </small>
                                    <div class="mt-2">
                                        <small class="text-muted">Expected: ₦<?php echo number_format($pending['expected_amount'], 2); ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Debug Info -->
                <?php if (isset($transaction_debug)): ?>
                <div class="alert alert-info mb-3">
                    <small>
                        <strong>Debug:</strong> Date: <?php echo $transaction_debug['selected_date']; ?> | 
                        Sales: <?php echo $transaction_debug['sales_count']; ?> | 
                        Payments: <?php echo $transaction_debug['payments_count']; ?> | 
                        Combined: <?php echo $transaction_debug['combined_count'] ?? 0; ?>
                        <?php if (isset($transaction_debug['error'])): ?>
                            <br><strong>Error:</strong> <?php echo $transaction_debug['error']; ?>
                        <?php endif; ?>
                    </small>
                </div>
                <?php endif; ?>

                <!-- Recent Transactions Table -->
                <div class="transactions-table">
                    <?php if (count($recent_transactions) > 0): ?>
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Customer</th>
                                <th>Cashier</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Change</th>
                                <th>Payment Method</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_transactions as $transaction): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($transaction['reference']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($transaction['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['cashier_name']); ?></td>
                                <td>₦<?php echo number_format($transaction['total_amount'], 2); ?></td>
                                <td>₦<?php echo number_format($transaction['amount_paid'], 2); ?></td>
                                <td>₦<?php echo number_format($transaction['change_amount'], 2); ?></td>
                                <td>
                                    <span class="payment-badge payment-<?php echo $transaction['payment_method']; ?>">
                                        <?php echo ucfirst($transaction['payment_method']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('H:i', strtotime($transaction['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h5>No Transactions Found</h5>
                        <p>No financial transactions recorded for the selected date.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
    <script>
        function updateFilters() {
            const date = document.getElementById('date').value;
            const url = new URL(window.location);
            url.searchParams.set('date', date);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>


<?php
/**
 * Shop Daily Accounting
 * 
 * Daily accounting view for shop attendants showing sales, expenses,
 * and overall balance to ensure the shop account is balanced
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/middleware.php';

// Restrict access to shop attendants and managers (for viewing)
requireShopAttendantOrManager();

$error = '';
$success = '';

// Get date filter
$date_filter = $_GET['date'] ?? date('Y-m-d');
$is_admin = hasRole('admin');

// Get today's sales for current shop attendant with payment method breakdown
$sales_data = [];
$total_sales = 0; // Gross sales (before discount)
$total_discount = 0;
$total_surplus = 0; // Total surplus amounts
$sales_by_payment = ['cash' => 0, 'transfer' => 0, 'card' => 0, 'credit' => 0];
$cash_sales_after_discount = 0; // Cash sales after discount removal
$gross_cash_sales = 0; // Gross cash sales before discount
$credit_sales_total = 0; // Total credit sales
$units_sold = 0; // Total units sold
try {
    $query = "
        SELECT ss.*,
               (SELECT COUNT(*) FROM shop_sales_items WHERE shop_sale_id = ss.id) as item_count,
               (SELECT SUM(ssi.quantity) FROM shop_sales_items ssi WHERE ssi.shop_sale_id = ss.id) as total_units
        FROM shop_sales ss
        WHERE 1=1
    ";

    $params = [];

    // Filter by user if not admin
    if (!$is_admin) {
        $query .= " AND ss.recorded_by = ?";
        $params[] = $_SESSION['user_id'];
    }

    $query .= " AND DATE(ss.sale_date) = ?";
    $params[] = $date_filter;
    $query .= " ORDER BY ss.recorded_at DESC";

    $stmt = executeQuery($query, $params);
    $sales_data = $stmt->fetchAll();

    foreach ($sales_data as $sale) {
        $total_sales += $sale['total_amount'];
        $total_discount += $sale['discount_amount'];
        $total_surplus += ($sale['surplus_amount'] ?? 0);
        $units_sold += ($sale['total_units'] ?? 0);

        // Calculate net amount after discount
        $net_amount = $sale['total_amount'] - $sale['discount_amount'];

        // Get payment breakdown from shop_sale_payments
        $use_shop_sale_payments = false;
        $payment_methods_breakdown = []; // Aggregated by payment method

        // The payment_method field in shop_sales is the source of truth
        $primary_method = $sale['payment_method'];
        
        if ($sale['payment_split'] == 1) {
            // This sale has split payments, try to get from shop_sale_payments
            try {
                $pay_stmt = executeQuery("
                    SELECT payment_method, SUM(amount) as total_amount
                    FROM shop_sale_payments
                    WHERE shop_sale_id = ?
                    GROUP BY payment_method
                ", [$sale['id']]);
                $payment_methods_breakdown = $pay_stmt->fetchAll();

                // Use shop_sale_payments whenever any payment records exist for this sale
                if (!empty($payment_methods_breakdown)) {
                    $use_shop_sale_payments = true;
                }
            } catch (PDOException $e) {
                // Table might not exist, use fallback
                $payment_methods_breakdown = [];
            }
        }

        // Process payment breakdown - use exact user-entered amounts
        if ($use_shop_sale_payments && !empty($payment_methods_breakdown)) {
            // Use the exact amounts recorded in shop_sale_payments (what user entered)
            foreach ($payment_methods_breakdown as $payment) {
                $method = $payment['payment_method'];
                $recorded_amount = floatval($payment['total_amount']);

                if (isset($sales_by_payment[$method])) {
                    $sales_by_payment[$method] += $recorded_amount;
                }
                if ($method === 'cash') {
                    $cash_sales_after_discount += $recorded_amount;
                    $gross_cash_sales += $recorded_amount;
                }
                if ($method === 'credit') {
                    $credit_sales_total += $recorded_amount;
                }
            }
        } else {
            // Use single payment method from shop_sales (source of truth)
            $method = $primary_method;

            if (isset($sales_by_payment[$method])) {
                $sales_by_payment[$method] += $net_amount;
            }

            if ($method === 'cash') {
                $cash_sales_after_discount += $net_amount;
                $gross_cash_sales += $sale['total_amount'];
            }

            if ($method === 'credit') {
                $credit_sales_total += $net_amount;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Failed to load sales: " . $e->getMessage());
}

// Calculate additional accounting metrics
$net_sales = $total_sales - $total_discount; // Net sales after discounts
$discount_percentage = $total_sales > 0 ? ($total_discount / $total_sales) * 100 : 0;
$average_transaction = count($sales_data) > 0 ? ($net_sales / count($sales_data)) : 0;
$total_non_cash_sales = $sales_by_payment['card'] + $sales_by_payment['transfer'] + $sales_by_payment['credit'];

// Get today's expenses for current shop attendant
$expenses_data = [];
$total_expenses = 0;
$total_acknowledged = 0;
$total_balance_due = 0;
$unsettled_expenses = 0;
$settled_expenses = 0;
try {
    // Include all expenses so cleared records remain part of the day's cash reconciliation.
    $query = "
        SELECT sc.*
        FROM shop_collections sc
        WHERE DATE(sc.collection_date) = ?
        ORDER BY sc.created_at DESC
    ";
    $stmt = executeQuery($query, [$date_filter]);
    $expenses_data = $stmt->fetchAll();

    foreach ($expenses_data as $expense) {
        $total_expenses += $expense['collection_amount'];
        $total_acknowledged += $expense['amount_acknowledged'];
        $total_balance_due += $expense['balance_due'];
        if ($expense['status'] !== 'cleared') {
            $unsettled_expenses += $expense['collection_amount'];
        }
    }
    
    // Get settled expenses (status = 'cleared')
    $query = "
        SELECT COALESCE(SUM(amount_acknowledged), 0) as total_settled
        FROM shop_collections
        WHERE DATE(collection_date) = ? AND status = 'cleared'
    ";
    $stmt = executeQuery($query, [$date_filter]);
    $settled_data = $stmt->fetch();
    $settled_expenses = floatval($settled_data['total_settled'] ?? 0);
    
    // Net expenses after returns = total_expenses - total_acknowledged (returned amounts)
    $net_expenses = $total_expenses - $total_acknowledged;
} catch (PDOException $e) {
    error_log("Failed to load expenses: " . $e->getMessage());
}

// Get today's damaged products for current shop attendant
$damaged_data = [];
$total_damaged_quantity = 0;
try {
    $query = "
        SELECT sdp.*
        FROM shop_damaged_products sdp
        WHERE DATE(sdp.damage_date) = ? AND sdp.shop_attendant_id = ?
        ORDER BY sdp.created_at DESC
    ";
    $params = [$date_filter];
    if (!$is_admin) {
        $params[] = $_SESSION['user_id'];
    }
    $stmt = executeQuery($query, $params);
    $damaged_data = $stmt->fetchAll();

    foreach ($damaged_data as $damage) {
        $total_damaged_quantity += $damage['quantity'];
    }
} catch (PDOException $e) {
    error_log("Failed to load damaged products: " . $e->getMessage());
}

// Calculate net balance and surplus/shortage
$amount_collected = $sales_by_payment['cash'] + $sales_by_payment['card'] + $sales_by_payment['transfer'];
$net_balance = $sales_by_payment['cash'] - $net_expenses;

// Calculate surplus/shortage: Total payments collected vs Product total sold
$total_payments_collected = $sales_by_payment['cash'] + $sales_by_payment['card'] + $sales_by_payment['transfer'] + $sales_by_payment['credit'];
$payment_vs_product_diff = $total_payments_collected - $net_sales;

// Determine balance status
$balance_status = 'balanced';
if (abs($net_balance) > 0.01) {
    $balance_status = $net_balance > 0 ? 'excess' : 'shortage';
}

// Debug logging
error_log("Balance Calculation Debug:");
error_log("Total Sales (Gross): " . $total_sales);
error_log("Total Discounts: " . $total_discount);
error_log("Net Sales: " . $net_sales);
error_log("Sales by Payment - Cash: " . $sales_by_payment['cash']);
error_log("Sales by Payment - Card: " . $sales_by_payment['card']);
error_log("Sales by Payment - Transfer: " . $sales_by_payment['transfer']);
error_log("Sales by Payment - Credit: " . $sales_by_payment['credit']);
error_log("Amount Collected (Cash + Card + Transfer): " . $amount_collected);
error_log("Total Expenses: " . $total_expenses);
error_log("Unsettled Expenses (reduce cash): " . $unsettled_expenses);
error_log("Settled Expenses (return to cash): " . $settled_expenses);
error_log("Net Balance (Amount Collected - Unsettled + Settled): " . $net_balance);
error_log("Balance Status: " . $balance_status);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Accounting - Merrylay Enterprises</title>
    
    <!-- CSS Framework -->
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        .summary-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            text-align: center;
            transition: all var(--transition-normal);
        }
        
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .summary-card.sales {
            border-left: 4px solid var(--green);
        }
        
        .summary-card.expenses {
            border-left: 4px solid var(--blue);
        }
        
        .summary-card.balance {
            border-left: 4px solid var(--primary-500);
        }
        
        .transaction-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .transaction-item:hover {
            border-color: var(--border-dark);
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
            
            <nav class="sidebar-nav">
                <!-- Main Operations Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Main Operations</div>
                    <a href="index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span>
                        <span class="nav-text">Shop Sales</span>
                    </a>
                    <a href="history.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-history"></i></span>
                        <span class="nav-text">Sales History</span>
                    </a>
                </div>

                <!-- Shop Operations Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Shop Operations</div>
                    <a href="collections.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-text">Expenses</span>
                    </a>
                    <a href="collection-history.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-list-alt"></i></span>
                        <span class="nav-text">Expense History</span>
                    </a>
                    <a href="damaged.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
                        <span class="nav-text">Damaged Products</span>
                    </a>
                    <a href="daily-accounting.php" class="nav-link active">
                        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                        <span class="nav-text">Daily Accounting</span>
                    </a>
                </div>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="../stock/supplies.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-truck-loading"></i></span>
                        <span class="nav-text">Supplies</span>
                    </a>
                    <a href="../finance/daily_balance.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                        <span class="nav-text">Daily Balance</span>
                    </a>
                    <a href="../reports/daily.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                        <span class="nav-text">Reports</span>
                    </a>
                </div>
                <?php endif; ?>

                <!-- System Section -->
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <a href="../auth/profile.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-user"></i></span>
                        <span class="nav-text">Profile</span>
                    </a>
                    <a href="../auth/logout.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="nav-text">Logout</span>
                    </a>
                </div>
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
                        <input type="text" placeholder="Search..." id="globalSearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-menu dropdown">
                            <button class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User', 0, 2)); ?>
                                </div>
                                <div class="user-info d-none d-lg-block">
                                    <div class="user-name"><?php echo htmlspecialchars(ucwords($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')); ?></div>
                                    <div class="user-role">Shop Attendant</div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
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
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-balance-scale" style="margin-right: 15px;"></i>
                            Daily Accounting
                        </h1>
                        <p class="page-subtitle">
                            Track sales, expenses, and balance for the shop
                        </p>
                    </div>
                    <div>
                        <form method="GET" class="d-flex gap-2">
                            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-calendar"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Summary Cards - Essential Accounting Metrics -->
                <div class="row row-cols-1 row-cols-md-6 g-3 mb-4">
                    <div class="col">
                        <div class="summary-card sales">
                            <div style="color: var(--primary-600); margin-bottom: var(--space-2);">
                                <i class="fas fa-chart-line fa-2x"></i>
                            </div>
                            <small class="text-muted">Gross Sales</small>
                            <h2 class="mb-0" style="color: var(--green);">₦<?php echo number_format($total_sales, 2); ?></h2>
                            <small class="text-muted"><?php echo count($sales_data); ?> transactions</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="summary-card" style="border-left: 4px solid var(--primary-500);">
                            <div style="color: var(--primary-600); margin-bottom: var(--space-2);">
                                <i class="fas fa-receipt fa-2x"></i>
                            </div>
                            <small class="text-muted">Net Sales</small>
                            <h2 class="mb-0" style="color: var(--primary-500);">₦<?php echo number_format($net_sales, 2); ?></h2>
                            <small class="text-muted">After ₦<?php echo number_format($total_discount, 2); ?> discounts</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="summary-card" style="border-left: 4px solid <?php echo $payment_vs_product_diff >= 0 ? '#10b981' : '#ef4444'; ?>;">
                            <div style="color: var(--primary-600); margin-bottom: var(--space-2);">
                                <i class="fas fa-<?php echo $payment_vs_product_diff >= 0 ? 'plus-circle' : 'minus-circle'; ?> fa-2x"></i>
                            </div>
                            <small class="text-muted"><?php echo $payment_vs_product_diff >= 0 ? 'Surplus' : 'Shortage'; ?></small>
                            <h2 class="mb-0" style="color: <?php echo $payment_vs_product_diff >= 0 ? '#10b981' : '#ef4444'; ?>;">₦<?php echo number_format(abs($payment_vs_product_diff), 2); ?></h2>
                            <small class="text-muted"><?php echo $payment_vs_product_diff >= 0 ? 'Extra amount collected' : 'Amount short of product total'; ?></small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="summary-card expenses">
                            <div style="color: var(--primary-600); margin-bottom: var(--space-2);">
                                <i class="fas fa-file-invoice-dollar fa-2x"></i>
                            </div>
                            <small class="text-muted">Total Expenses</small>
                            <h2 class="mb-0" style="color: var(--blue);">₦<?php echo number_format($total_expenses, 2); ?></h2>
                            <small class="text-muted"><?php echo count($expenses_data); ?> expense(s)</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="summary-card" style="border-left: 4px solid var(--red);">
                            <div style="color: var(--primary-600); margin-bottom: var(--space-2);">
                                <i class="fas fa-exclamation-triangle fa-2x"></i>
                            </div>
                            <small class="text-muted">Damaged Products</small>
                            <h2 class="mb-0" style="color: var(--red);"><?php echo number_format($total_damaged_quantity, 2); ?></h2>
                            <small class="text-muted"><?php echo count($damaged_data); ?> record(s)</small>
                        </div>
                    </div>
                    <div class="col">
                        <div class="summary-card balance">
                            <div style="color: var(--primary-600); margin-bottom: var(--space-2);">
                                <i class="fas fa-money-bill-wave fa-2x"></i>
                            </div>
                            <small class="text-muted">Cash Received</small>
                            <h2 class="mb-0" style="color: var(--green);">₦<?php echo number_format($sales_by_payment['cash'], 2); ?></h2>
                            <small class="text-muted">Cash collected from sales</small>
                        </div>
                    </div>
                </div>

                <!-- Debug Info (Temporary) -->
                <div class="alert alert-info">
                    <strong>Balance Calculation Debug:</strong><br>
                    Amount Collected (Cash + Card + Transfer): ₦<?php echo number_format($amount_collected, 2); ?><br>
                    Net Expenses: ₦<?php echo number_format($net_expenses, 2); ?><br>
                    Net Shop Cash Available: ₦<?php echo number_format($net_balance, 2); ?><br>
                    <small>Breakdown: Cash: ₦<?php echo number_format($sales_by_payment['cash'], 2); ?> | Card: ₦<?php echo number_format($sales_by_payment['card'], 2); ?> | Transfer: ₦<?php echo number_format($sales_by_payment['transfer'], 2); ?> | Credit: ₦<?php echo number_format($sales_by_payment['credit'], 2); ?></small>
                </div>

                <!-- Sales & Expenses Breakdown -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-chart-pie" style="margin-right: 10px;"></i>
                                        Sales & Expenses Breakdown
                                    </h5>
                                    <p class="card-subtitle">Detailed breakdown of sales and expenses</p>
                                </div>
                                <ul class="nav nav-tabs" id="breakdownTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales-panel" type="button" role="tab">
                                            <i class="fas fa-shopping-cart me-2"></i>Sales
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="expenses-tab" data-bs-toggle="tab" data-bs-target="#expenses-panel" type="button" role="tab">
                                            <i class="fas fa-hand-holding-usd me-2"></i>Expenses
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="damaged-tab" data-bs-toggle="tab" data-bs-target="#damaged-panel" type="button" role="tab">
                                            <i class="fas fa-exclamation-triangle me-2"></i>Damaged Products
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="breakdownTabsContent">
                                    <!-- Sales Tab -->
                                    <div class="tab-pane fade show active" id="sales-panel" role="tabpanel">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <div class="summary-card" style="border-left: 4px solid var(--green);">
                                                    <small class="text-muted">Cash Received</small>
                                                    <h3 class="mb-0" style="color: var(--green);">₦<?php echo number_format($sales_by_payment['cash'], 2); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="summary-card" style="border-left: 4px solid var(--blue);">
                                                    <small class="text-muted">Transfer Sales</small>
                                                    <h3 class="mb-0" style="color: var(--blue);">₦<?php echo number_format($sales_by_payment['transfer'], 2); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="summary-card" style="border-left: 4px solid var(--primary-500);">
                                                    <small class="text-muted">Card Sales</small>
                                                    <h3 class="mb-0" style="color: var(--primary-500);">₦<?php echo number_format($sales_by_payment['card'], 2); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="summary-card" style="border-left: 4px solid var(--red);">
                                                    <small class="text-muted">Credit Sales</small>
                                                    <h3 class="mb-0" style="color: var(--red);">₦<?php echo number_format($sales_by_payment['credit'], 2); ?></h3>
                                                    <small class="text-muted">Recorded to credit dashboard</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Expenses Tab -->
                                    <div class="tab-pane fade" id="expenses-panel" role="tabpanel">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="summary-card" style="border-left: 4px solid var(--blue);">
                                                    <small class="text-muted">Total Expenses</small>
                                                    <h3 class="mb-0" style="color: var(--blue);">₦<?php echo number_format($total_expenses, 2); ?></h3>
                                                    <small class="text-muted"><?php echo count($expenses_data); ?> expense(s)</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="summary-card" style="border-left: 4px solid var(--yellow);">
                                                    <small class="text-muted">Amount Acknowledged (Returned)</small>
                                                    <h3 class="mb-0" style="color: var(--yellow);">₦<?php echo number_format($total_acknowledged, 2); ?></h3>
                                                    <small class="text-muted">Returned to shop</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="summary-card" style="border-left: 4px solid var(--green);">
                                                    <small class="text-muted">Net Expenses</small>
                                                    <h3 class="mb-0" style="color: var(--green);">₦<?php echo number_format($net_expenses, 2); ?></h3>
                                                    <small class="text-muted">After returns</small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (count($expenses_data) > 0): ?>
                                            <div class="mt-4">
                                                <h6 class="mb-3">Expense Details</h6>
                                                <div class="table-responsive">
                                                    <table class="table table-hover">
                                                        <thead>
                                                            <tr>
                                                                <th>Expense #</th>
                                                                <th>Collector</th>
                                                                <th>Purpose</th>
                                                                <th>Amount</th>
                                                                <th>Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($expenses_data as $expense): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($expense['collection_number']); ?></td>
                                                                    <td>
                                                                        <?php echo htmlspecialchars($expense['collector_name']); ?>
                                                                        <br><small class="text-muted"><?php echo htmlspecialchars($expense['collector_role']); ?></small>
                                                                    </td>
                                                                    <td><?php echo htmlspecialchars($expense['collection_purpose']); ?></td>
                                                                    <td style="font-weight: 600; color: var(--blue);">₦<?php echo number_format($expense['collection_amount'], 2); ?></td>
                                                                    <td>
                                                                        <?php if ($expense['status'] === 'pending'): ?>
                                                                            <span class="badge bg-warning">Pending</span>
                                                                        <?php elseif ($expense['status'] === 'acknowledged'): ?>
                                                                            <span class="badge bg-info">Acknowledged</span>
                                                                        <?php elseif ($expense['status'] === 'cleared'): ?>
                                                                            <span class="badge bg-success">Cleared</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted text-center mt-4">No expenses recorded for this date</p>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Damaged Products Tab -->
                                    <div class="tab-pane fade" id="damaged-panel" role="tabpanel">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="summary-card" style="border-left: 4px solid var(--red);">
                                                    <small class="text-muted">Total Damaged Quantity</small>
                                                    <h3 class="mb-0" style="color: var(--red);"><?php echo number_format($total_damaged_quantity, 2); ?></h3>
                                                    <small class="text-muted"><?php echo count($damaged_data); ?> record(s)</small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (count($damaged_data) > 0): ?>
                                            <div class="mt-4">
                                                <h6 class="mb-3">Damaged Products Details</h6>
                                                <div class="table-responsive">
                                                    <table class="table table-hover">
                                                        <thead>
                                                            <tr>
                                                                <th>Damage #</th>
                                                                <th>Product</th>
                                                                <th>Quantity</th>
                                                                <th>Reason</th>
                                                                <th>Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($damaged_data as $damage): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($damage['damage_number']); ?></td>
                                                                    <td>
                                                                        <?php echo htmlspecialchars($damage['product_name']); ?>
                                                                        <br><small class="text-muted"><?php echo htmlspecialchars($damage['unit']); ?></small>
                                                                    </td>
                                                                    <td style="font-weight: 600; color: var(--red);"><?php echo number_format($damage['quantity'], 2); ?></td>
                                                                    <td><?php echo htmlspecialchars($damage['reason']); ?></td>
                                                                    <td>
                                                                        <?php if ($damage['status'] === 'pending'): ?>
                                                                            <span class="badge bg-warning">Pending</span>
                                                                        <?php elseif ($damage['status'] === 'acknowledged'): ?>
                                                                            <span class="badge bg-info">Acknowledged</span>
                                                                        <?php elseif ($damage['status'] === 'cleared'): ?>
                                                                            <span class="badge bg-success">Cleared</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted text-center mt-4">No damaged products recorded for this date</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transactions Section -->
                <div class="row g-3">
                    <!-- Sales -->
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-shopping-cart" style="margin-right: 10px;"></i>
                                        Sales Transactions
                                    </h5>
                                    <p class="card-subtitle">Sales recorded on <?php echo date('M d, Y', strtotime($date_filter)); ?></p>
                                </div>
                            </div>
                            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                <?php if (count($sales_data) > 0): ?>
                                    <?php foreach ($sales_data as $sale): ?>
                                        <?php
                                        // Get payment breakdown
                                        $payment_breakdown = [];
                                        try {
                                            $pay_stmt = executeQuery("
                                                SELECT payment_method, amount 
                                                FROM shop_sale_payments 
                                                WHERE shop_sale_id = ?
                                            ", [$sale['id']]);
                                            $payment_breakdown = $pay_stmt->fetchAll();
                                        } catch (PDOException $e) {
                                            // Fallback to old payment_method
                                            $payment_breakdown = [['payment_method' => $sale['payment_method'], 'amount' => $sale['total_amount'] - $sale['discount_amount']]];
                                        }
                                        
                                        $is_split = count($payment_breakdown) > 1;
                                        $has_credit = false;
                                        foreach ($payment_breakdown as $p) {
                                            if ($p['payment_method'] === 'credit') {
                                                $has_credit = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <div class="transaction-item">
                                            <div>
                                                <div style="font-weight: 600; color: var(--primary-800);">
                                                    <?php echo htmlspecialchars($sale['sale_number']); ?>
                                                    <?php if ($is_split): ?>
                                                        <span class="badge bg-info" style="font-size: 10px; margin-left: 8px;">SPLIT</span>
                                                    <?php endif; ?>
                                                    <?php if ($has_credit): ?>
                                                        <span class="badge bg-danger" style="font-size: 10px; margin-left: 8px;">CREDIT</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($sale['recorded_at'])); ?>
                                                    <?php if ($sale['customer_name'] && $sale['customer_name'] !== 'Walk-in customer'): ?>
                                                        - <?php echo htmlspecialchars($sale['customer_name']); ?>
                                                    <?php endif; ?>
                                                </small>
                                                <?php if ($is_split): ?>
                                                    <div class="mt-1" style="font-size: 11px;">
                                                        <?php foreach ($payment_breakdown as $p): ?>
                                                            <span class="badge" style="background: var(--gray-200); color: var(--gray-700); margin-right: 4px;">
                                                                <?php echo ucfirst($p['payment_method']); ?>: ₦<?php echo number_format($p['amount'], 2); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-weight: 700; color: <?php echo $has_credit ? 'var(--red)' : 'var(--green)'; ?>;">
                                                ₦<?php echo number_format($sale['total_amount'], 2); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center">No sales recorded</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Collections -->
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-hand-holding-usd" style="margin-right: 10px;"></i>
                                        Expenses
                                    </h5>
                                    <p class="card-subtitle">Money spent from shop</p>
                                </div>
                                <a href="collections.php" class="btn btn-sm btn-primary">
                                    <i class="fas fa-plus"></i> Add Expense
                                </a>
                            </div>
                            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                <?php if (count($expenses_data) > 0): ?>
                                    <?php foreach ($expenses_data as $expense): ?>
                                        <div class="transaction-item">
                                            <div>
                                                <div style="font-weight: 600; color: var(--primary-800);">
                                                    <?php echo htmlspecialchars($expense['collector_name']); ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($expense['created_at'])); ?>
                                                    - <?php echo htmlspecialchars($expense['collection_purpose']); ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <div style="font-weight: 700; color: var(--blue);">
                                                    ₦<?php echo number_format($expense['collection_amount'], 2); ?>
                                                </div>
                                                <?php if ($expense['status'] === 'cleared'): ?>
                                                    <span class="badge bg-success" style="font-size: 10px;">
                                                        <i class="fas fa-check-circle"></i> Cleared
                                                    </span>
                                                <?php elseif ($expense['status'] === 'acknowledged'): ?>
                                                    <span class="badge bg-info" style="font-size: 10px;">
                                                        <i class="fas fa-clock"></i> Acknowledged
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning" style="font-size: 10px;">
                                                        <i class="fas fa-hourglass-half"></i> Pending
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center">No expenses recorded</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
    <script>
        // Force sidebar to be expanded for shop pages
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            // Remove collapsed state
            if (sidebar) {
                sidebar.classList.remove('collapsed');
            }
            if (mainContent) {
                mainContent.classList.remove('expanded');
            }
            
            // Clear localStorage preference
            localStorage.setItem('sidebarCollapsed', 'false');
            
        });
    </script>
</body>
</html>

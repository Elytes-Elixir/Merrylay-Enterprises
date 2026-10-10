<?php
/**
 * Daily Account Balance Page
 * 
 * Calculates and displays the daily account balance to ensure all financial records
 * are correct and balanced. Shows:
 * - Expected amounts from dispatches
 * - Actual collections (cash, transfers, credit)
 * - Returns
 * - Expenses
 * - Balance verification
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

// Manager can view but not edit finance data
if (!canViewFinancials()) {
    header('Location: ../dashboard/index.php');
    exit();
}

$can_edit = canManageFinancials();

$selected_date = $_GET['date'] ?? date('Y-m-d');
// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) || $selected_date === 'YYYY-MM-DD') {
    $selected_date = date('Y-m-d');
}

// Get all dispatches for the selected date
$dispatches = [];
$debug_info = '';
$debug_info = '';
try {
    // Step 1: Get all dispatches for the selected date (excluding shop channel to avoid duplication with shop_sales table)
    $stmt = executeQuery("
        SELECT 
            d.id,
            d.dispatch_number,
            d.dispatcher_name,
            d.dispatch_date,
            COALESCE(d.expected_amount, 0) as expected_amount,
            COALESCE(d.actual_sales_amount, 0) as actual_sales_amount_db
        FROM dispatches d
        WHERE DATE(d.dispatch_date) = ? AND (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales'))
        ORDER BY d.dispatch_number
    ", [$selected_date]);
    $dispatches = $stmt->fetchAll();
    
    $debug_info .= "Query date: $selected_date, Found: " . count($dispatches) . " dispatches (excluding shop channel)\n";
    
    // Step 2: For each dispatch, calculate expected_amount and returns from dispatch_items and daily_returns
    foreach ($dispatches as &$dispatch) {
        // Calculate expected amount
        $stmt = executeQuery("
            SELECT 
                COUNT(di.id) as item_count,
                GROUP_CONCAT(CONCAT(p.name, ' (', di.quantity, ')') SEPARATOR ', ') as items_summary
            FROM dispatch_items di
            LEFT JOIN products p ON di.product_id = p.id
            WHERE di.dispatch_id = ?
        ", [$dispatch['id']]);
        $items = $stmt->fetch();
        $dispatch['expected_amount'] = floatval($dispatch['expected_amount'] ?? 0);
        $dispatch['item_count'] = intval($items['item_count'] ?? 0);
        $dispatch['items_summary'] = $items['items_summary'] ?? '';
        
        // Calculate returns for this dispatch
        $stmt = executeQuery("
            SELECT dr.*, di.unit_price, di.discount_amount, di.quantity as dispatch_quantity
            FROM daily_returns dr
            JOIN dispatch_items di ON di.dispatch_id = dr.dispatch_id AND di.product_id = dr.product_id
            WHERE dr.dispatch_id = ? AND dr.return_date = ?
        ", [$dispatch['id'], $selected_date]);
        $dispatch_returns = $stmt->fetchAll();
        
        $dispatch['return_value'] = 0;
        foreach ($dispatch_returns as $return) {
            // Use full unit price (without discount) - discounts are tracked separately for account balancing
            $unitPrice = floatval($return['unit_price']);
            $return_value = floatval($return['quantity']) * $unitPrice;
            $dispatch['return_value'] += $return_value;
        }
        
        // Calculate actual dispatch value from expected minus returns so the summary matches physical trip sales.
        $dispatch['calculated_actual_sales_amount'] = $dispatch['expected_amount'] - $dispatch['return_value'];
        $dispatch['stored_actual_sales_amount'] = floatval($dispatch['actual_sales_amount_db'] ?? 0);
        $dispatch['actual_sales_amount'] = $dispatch['calculated_actual_sales_amount'];
        
        $debug_info .= "  Dispatch {$dispatch['dispatch_number']}: items={$dispatch['item_count']}, expected={$dispatch['expected_amount']}, returns={$dispatch['return_value']}, actual={$dispatch['actual_sales_amount']}, stored_actual={$dispatch['stored_actual_sales_amount']}\n";
    }
    unset($dispatch);
    // Detect duplicate dispatch numbers (helpful for debugging duplicate rows)
    $dispatch_number_map = [];
        foreach ($dispatches as $d) {
            $num = $d['dispatch_number'] ?? '(unknown)';
            $dispatch_number_map[$num][] = $d['id'] ?? '(no-id)';
        }
        foreach ($dispatch_number_map as $num => $ids) {
            if (count($ids) > 1) {
                $debug_info .= "DUPLICATE: Dispatch number {$num} appears " . count($ids) . " times (IDs: " . implode(',', $ids) . ")\n";
            }
        }
} catch (PDOException $e) {
    $debug_info .= "ERROR: " . $e->getMessage() . "\n";
    error_log("Error fetching dispatches: " . $e->getMessage());
    $dispatches = [];
}

// Calculate totals
$total_expected = 0;
$total_actual_sales = 0;
$total_returns = 0;
$total_cash_collected = 0;
$total_transfers_collected = 0;
$total_card_collected = 0;
$total_credit_sales = 0;
$total_credit_settlement = 0;
$total_expenses = 0;
$total_damaged_products = 0;
$total_shop_sales = 0;
$total_shop_sales_units = 0;
$shop_total_expenses = 0;
$shop_total_returns = 0;

foreach ($dispatches as $dispatch) {
    $total_expected += $dispatch['expected_amount'];
    $total_actual_sales += $dispatch['actual_sales_amount'];
    $total_returns += $dispatch['return_value'];
}

$debug_info .= "After calculation: total_expected=$total_expected, total_actual_sales=$total_actual_sales\n";

// Get collections (cash, transfers, credit, credit_settlement) for the date - using daily_activities
$collections = [];
try {
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

    foreach ($collections as $collection) {
        if ($collection['payment_method'] === 'cash') {
            $total_cash_collected += $collection['total_amount'];
            $debug_info .= "Dispatcher Cash: amount={$collection['total_amount']}, transactions={$collection['transaction_count']}\n";
        } elseif ($collection['payment_method'] === 'transfer') {
            $total_transfers_collected += $collection['total_amount'];
            $debug_info .= "Dispatcher Transfer: amount={$collection['total_amount']}, transactions={$collection['transaction_count']}\n";
        } elseif ($collection['payment_method'] === 'credit_settlement') {
            $total_credit_settlement += $collection['total_amount'];
            $debug_info .= "Dispatcher Credit Settlement: amount={$collection['total_amount']}, transactions={$collection['transaction_count']}\n";
        }
    }

    $stmt = executeQuery("SELECT COALESCE(SUM(credit_amount), 0) as total_credit_sales FROM daily_activities WHERE activity_date = ?", [$selected_date]);
    $total_credit_sales = $stmt->fetchColumn();
} catch (PDOException $e) {
    $collections = [];
}

// Get shop sales data with collections logic - matching account-summary.php approach
try {
    // From shop_sales table with payment method breakdown
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
        SELECT
            COALESCE(SUM(total_amount), 0) as total_amount,
            COALESCE(SUM(discount_amount), 0) as discount_amount,
            COUNT(*) as record_count
        FROM shop_sales
        WHERE DATE(sale_date) = ?
    ", [$selected_date]);
    $shop_sales_totals = $stmt->fetch();
    
    $shop_sales_new = [
        'cash_amount' => 0,
        'card_amount' => 0,
        'transfer_amount' => 0,
        'credit_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'record_count' => 0
    ];
    
    foreach ($shop_sales_details as $detail) {
        if ($detail['payment_method'] === 'cash') {
            $shop_sales_new['cash_amount'] += $detail['total_amount'];
        } elseif ($detail['payment_method'] === 'card') {
            $shop_sales_new['card_amount'] += $detail['total_amount'];
        } elseif ($detail['payment_method'] === 'transfer') {
            $shop_sales_new['transfer_amount'] += $detail['total_amount'];
        } elseif ($detail['payment_method'] === 'credit') {
            $shop_sales_new['credit_amount'] += $detail['total_amount'];
        }
        $shop_sales_new['record_count'] += $detail['transaction_count'];
    }

    $shop_sales_new['total_amount'] = $shop_sales_totals['total_amount'] ?? 0;
    $shop_sales_new['discount_amount'] = $shop_sales_totals['discount_amount'] ?? 0;
    $shop_sales_new['record_count'] = $shop_sales_totals['record_count'] ?? 0;
    
    $debug_info .= "Shop Sales Details: " . json_encode($shop_sales_details) . "\n";
    $debug_info .= "Shop Sales Aggregated: " . json_encode($shop_sales_new) . "\n";

    // From dispatches table (shop channel) - no payment_method column, treat as cash
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(actual_sales_amount), 0) as total_amount,
            COUNT(*) as record_count
        FROM dispatches
        WHERE DATE(dispatch_date) = ? AND sales_channel IN ('shop', 'shop_sales')
    ", [$selected_date]);
    $shop_sales_old = $stmt->fetch();
    
    $debug_info .= "Shop Sales (Old Dispatch): date={$selected_date}, result=" . json_encode($shop_sales_old) . "\n";

    // Get shop sales units from shop_sales_items
    $stmt = executeQuery("
        SELECT COALESCE(SUM(ssi.quantity), 0) as total_units
        FROM shop_sales_items ssi
        JOIN shop_sales ss ON ssi.shop_sale_id = ss.id
        WHERE DATE(ss.sale_date) = ?
    ", [$selected_date]);
    $shop_sales_units_new = $stmt->fetch();

    // Get shop sales transaction counts by payment method
    $stmt = executeQuery("
        SELECT
            payment_method,
            COUNT(DISTINCT shop_sale_id) as transaction_count
        FROM (
            SELECT ss.id as shop_sale_id, ss.payment_method
            FROM shop_sales ss
            WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 0

            UNION ALL

            SELECT ss.id as shop_sale_id, ssp.payment_method
            FROM shop_sale_payments ssp
            JOIN shop_sales ss ON ss.id = ssp.shop_sale_id
            WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 1
        ) shop_payment_rows
        GROUP BY payment_method
    ", [$selected_date, $selected_date]);
    $shop_sales_transactions = $stmt->fetchAll();
    $shop_sales_transaction_counts = [];
    foreach ($shop_sales_transactions as $t) {
        $shop_sales_transaction_counts[$t['payment_method']] = $t['transaction_count'];
    }

    // Get shop sales units from dispatch_items (shop channel)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.quantity), 0) as total_units
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) = ? AND d.sales_channel IN ('shop', 'shop_sales')
    ", [$selected_date]);
    $shop_sales_units_old = $stmt->fetch();

    // Get shop collections/expenses from shop_collections table - matching account-summary.php
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'pending' THEN collection_amount ELSE 0 END), 0) as unsettled_collections,
            COALESCE(SUM(CASE WHEN status = 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as settled_collections,
            COALESCE(SUM(collection_amount), 0) as total_shop_expenses,
            COALESCE(SUM(amount_acknowledged), 0) as total_shop_returns
        FROM shop_collections
        WHERE DATE(collection_date) = ?
    ", [$selected_date]);
    $shop_collections_data = $stmt->fetch();

    // Calculate totals with collections logic
    $shop_sales_cash = ($shop_sales_new['cash_amount'] ?? 0);
    $shop_sales_card = ($shop_sales_new['card_amount'] ?? 0);
    $shop_sales_transfer = ($shop_sales_new['transfer_amount'] ?? 0);
    $shop_sales_credit = ($shop_sales_new['credit_amount'] ?? 0);
    $shop_sales_discount = ($shop_sales_new['discount_amount'] ?? 0);
    $shop_sales_settled_collections = ($shop_collections_data['settled_collections'] ?? 0);
    $shop_sales_unsettled_collections = ($shop_collections_data['unsettled_collections'] ?? 0);
    $shop_total_expenses = floatval($shop_collections_data['total_shop_expenses'] ?? 0);
    $shop_total_returns = floatval($shop_collections_data['total_shop_returns'] ?? 0);

    // Fallback to legacy dispatch shop sales only when new shop_sales are absent for the date.
    if (($shop_sales_new['total_amount'] ?? 0) == 0) {
        // Fetch old shop sales separately since they're excluded from dispatch query
        $stmt = executeQuery("
            SELECT
                COALESCE(SUM(actual_sales_amount), 0) as total_amount
            FROM dispatches
            WHERE DATE(dispatch_date) = ? AND sales_channel IN ('shop', 'shop_sales')
        ", [$selected_date]);
        $shop_sales_old_fallback = $stmt->fetch();
        
        $shop_sales_cash += ($shop_sales_old_fallback['total_amount'] ?? 0);
        $total_shop_sales = ($shop_sales_old_fallback['total_amount'] ?? 0);
        $total_shop_sales_units = ($shop_sales_units_old['total_units'] ?? 0);
        
        $old_shop_total = $shop_sales_old_fallback['total_amount'] ?? 0;
        $debug_info .= "Shop Sales (Old System Fallback): total={$old_shop_total}\n";
    } else {
        $total_shop_sales = ($shop_sales_new['total_amount'] ?? 0);
        $total_shop_sales_units = ($shop_sales_units_new['total_units'] ?? 0);
        
        $new_shop_total = $shop_sales_new['total_amount'] ?? 0;
        $new_shop_cash = $shop_sales_new['cash_amount'] ?? 0;
        $new_shop_card = $shop_sales_new['card_amount'] ?? 0;
        $new_shop_transfer = $shop_sales_new['transfer_amount'] ?? 0;
        $debug_info .= "Shop Sales (New System Used): total={$new_shop_total}, cash={$new_shop_cash}, card={$new_shop_card}, transfer={$new_shop_transfer}\n";
    }
    
    $debug_info .= "Final Shop Sales Values: total_shop_sales={$total_shop_sales}, total_shop_sales_units={$total_shop_sales_units}\n";

    // Cash should include shop sales cash plus dispatcher cash (no expense adjustments)
    $total_cash_collected += $shop_sales_cash;
    $total_card_collected += $shop_sales_card;
    $total_transfers_collected += $shop_sales_transfer;
    $total_credit_sales += $shop_sales_credit; // Add shop credit sales to total credit sales

    $net_shop_cash = $shop_sales_cash - $shop_total_expenses + $shop_total_returns;
    
    $debug_info .= "Shop Sales Cash: {$shop_sales_cash}\n";
    $debug_info .= "Shop Unsettled Collections: {$shop_sales_unsettled_collections}\n";
    $debug_info .= "Shop Settled Collections: {$shop_sales_settled_collections}\n";
    $debug_info .= "Shop Card: {$shop_sales_card}\n";
    $debug_info .= "Shop Transfer: {$shop_sales_transfer}\n";
    $debug_info .= "Shop Credit: {$shop_sales_credit}\n";
    $debug_info .= "Total Cash (after adding shop): {$total_cash_collected}\n";
} catch (PDOException $e) {
    // Use defaults
    $debug_info .= "ERROR fetching shop sales: " . $e->getMessage() . "\n";
    error_log("Error fetching shop sales: " . $e->getMessage());
}

// Get returns for the date - using daily_returns with dispatch-item pricing (same as what was subtracted from actual_sales_amount)
$returns = [];
try {
    $stmt = executeQuery("
        SELECT 
            dr.*,
            p.name as product_name,
            d.dispatch_number,
            di.unit_price,
            di.discount_amount,
            di.quantity as dispatch_quantity
        FROM daily_returns dr
        JOIN products p ON dr.product_id = p.id
        JOIN dispatches d ON d.dispatcher_name = dr.driver_name AND DATE(d.dispatch_date) = dr.return_date AND d.id = dr.dispatch_id
        JOIN dispatch_items di ON di.dispatch_id = d.id AND di.product_id = dr.product_id
        WHERE dr.return_date = ?
        ORDER BY dr.recorded_at DESC
    ", [$selected_date]);
    $returns = $stmt->fetchAll();
    
    // Note: total_returns is already calculated per dispatch above
    // This loop is just for display purposes
} catch (PDOException $e) {
    $returns = [];
}

// Get expenses for the date - using daily_activities
$expenses = [];
try {
    $stmt = executeQuery("
        SELECT 
            driver_name as dispatcher_name,
            'General' as expense_type,
            expenses_note as description,
            expenses_amount as amount,
            'cash' as payment_method,
            recorded_at as created_at
        FROM daily_activities
        WHERE activity_date = ? AND expenses_amount > 0
        ORDER BY recorded_at DESC
    ", [$selected_date]);
    $expenses = $stmt->fetchAll();

    foreach ($expenses as $expense) {
        $total_expenses += $expense['amount'];
    }

    // Get damaged products total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(damaged_products), 0) as total FROM daily_activities WHERE activity_date = ?");
    $stmt->execute([$selected_date]);
    $total_damaged_products = $stmt->fetch()['total'];

} catch (PDOException $e) {
    $expenses = [];
}

$total_dispatch_value = $total_expected + $total_shop_sales;

$total_accounted_for = $total_cash_collected + $total_transfers_collected + $total_card_collected + $total_credit_sales + $total_returns + $total_damaged_products;
$net_cash_after_expenses = $total_cash_collected - $total_expenses - $shop_total_expenses + $shop_total_returns;

// Variance = Accounted For - Dispatch Value
// Positive variance = Accounted For > Dispatch Value = Surplus
// Negative variance = Accounted For < Dispatch Value = Shortage
$balance_variance = $total_accounted_for - $total_dispatch_value;

$is_balanced = abs($balance_variance) < 0.01;
$balance_label = $is_balanced ? 'Balanced' : ($balance_variance > 0 ? 'Surplus' : 'Shortage');
$difference = $balance_variance; // For backward compatibility with existing UI

// Debug output for final totals
$debug_info .= "\n=== FINAL TOTALS ===\n";
$debug_info .= "total_expected (dispatchers): {$total_expected}\n";
$debug_info .= "total_shop_sales: {$total_shop_sales}\n";
$debug_info .= "total_dispatch_value: {$total_dispatch_value}\n";
$debug_info .= "total_cash_collected: {$total_cash_collected}\n";
$debug_info .= "total_transfers_collected: {$total_transfers_collected}\n";
$debug_info .= "total_card_collected: {$total_card_collected}\n";
$debug_info .= "total_credit_sales: {$total_credit_sales}\n";
$debug_info .= "total_returns: {$total_returns}\n";
$debug_info .= "total_damaged_products: {$total_damaged_products}\n";
$debug_info .= "total_expenses: {$total_expenses}\n";
$debug_info .= "total_credit_settlement: {$total_credit_settlement}\n";
$debug_info .= "total_accounted_for: {$total_accounted_for}\n";
$debug_info .= "balance_variance: {$balance_variance}\n";
$debug_info .= "is_balanced: " . ($is_balanced ? 'true' : 'false') . "\n";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Daily account balance verification and reconciliation for the Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Daily Account Balance - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    
    <style>
        /* A4 Print Styles */
        @page {
            size: A4 portrait;
            margin: 10mm 15mm;
        }
        
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .dashboard-container {
                display: block !important;
            }
            
            .sidebar,
            .top-bar,
            .card-header button,
            button[type="button"],
            .no-print {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
            
            .content-card {
                break-inside: avoid;
                page-break-inside: avoid;
                margin-bottom: 8mm;
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            /* Keep rows horizontal */
            .row {
                display: flex !important;
                flex-wrap: nowrap !important;
                flex-direction: row !important;
            }
            
            /* Stats grid - 4 columns */
            .stats-grid {
                display: grid !important;
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 10px !important;
            }
            
            .stat-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            
            /* Column widths for print */
            [class*="col-md-"],
            [class*="col-lg-"],
            [class*="col-xl-"] {
                flex: 0 0 auto !important;
                max-width: none !important;
            }
            
            .col-md-3,
            .col-md-4 {
                width: 33.333% !important;
                flex-basis: 33.333% !important;
            }
            
            .col-md-6 {
                width: 50% !important;
                flex-basis: 50% !important;
            }
            
            .col-md-8,
            .col-md-9 {
                width: 66.666% !important;
                flex-basis: 66.666% !important;
            }
            
            .col-12 {
                width: 100% !important;
                flex-basis: 100% !important;
            }
            
            .card-body {
                padding: 12px !important;
            }
            
            .table {
                font-size: 9pt;
            }
            
            .table th,
            .table td {
                padding: 4px 8px !important;
            }
            
            h1, h2, h3, h4, h5, h6 {
                page-break-after: avoid;
                font-size: 14pt !important;
            }
            
            .card-title {
                font-size: 12pt !important;
            }
            
            .stat-value {
                font-size: 14pt !important;
            }
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
                <a href="../products/add.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-plus"></i></span>
                    <span class="nav-text">Add Product</span>
                </a>
                <a href="../stock/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordDispatches()): ?>
                <a href="../dispatch/trips/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-truck"></i></span>
                    <span class="nav-text">Trips (Dispatch)</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title overview">Daily Workflow</div>

                <a href="../dispatch/daily/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                    <span class="nav-text">Today's Records</span>
                </a>

                <a href="../dispatch/daily/account-summary.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>

                <a href="daily_balance.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="../admin/end-of-day.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../accounts/balance_sheet.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Accounts</span>
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
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search balance data, dispatches, payments..." id="balanceSearch">
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
                            <h1><i class="fas fa-balance-scale me-3"></i>Daily Account Balance</h1>
                            <p>Account balance verification and reconciliation for <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
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

        <!-- Date Filter -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-calendar-day me-2"></i>Select Date
                            </h5>
                            <p class="card-subtitle">Choose date to view account balance</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="balanceDate">Date</label>
                                <input type="date" id="balanceDate" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sync me-2"></i>Load Balance
                                </button>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                    <i class="fas fa-print me-2"></i>Print Report
                                </button>
                            </div>
                        </form>                        <?php if (!empty($debug_info) && isset($_GET['debug'])): ?>
                        <div class="alert alert-info mt-3">
                            <strong>Debug Info:</strong>
                            <pre style="font-size: 12px; margin: 0;"><?php echo htmlspecialchars($debug_info); ?></pre>
                        </div>
                        <?php endif; ?>                    </div>
                </div>

                <!-- Balance Status -->
                <div class="content-card mb-4">
                    <div class="card-body">
                        <div class="balance-status <?php echo $is_balanced ? 'status-balanced' : 'status-unbalanced'; ?>">
                            <?php if ($is_balanced): ?>
                                <div class="d-flex align-items-center justify-content-center">
                                    <i class="fas fa-check-circle me-3"></i>
                                    <div>
                                        <strong>ACCOUNT BALANCED</strong>
                                        <div class="small">Expected sales and actual amount are equal</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center">
                                    <i class="fas fa-exclamation-triangle me-3"></i>
                                    <div>
                                        <strong><?php echo strtoupper($balance_label); ?></strong>
                                        <div class="small">Difference: ₦<?php echo number_format(abs($difference), 2); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

        <!-- Summary Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($total_expected, 0); ?><?php if (isset($_GET['debug'])) echo " [DEBUG: " . var_export($total_expected, true) . "]"; ?></div>
                        <div class="stat-label">Expected Amount</div>
                        <div class="stat-change">
                            <i class="fas fa-info-circle"></i> Total expected from dispatches
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon teal">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($total_shop_sales, 0); ?><?php if (isset($_GET['debug'])) echo " [DEBUG: " . var_export($total_shop_sales, true) . "]"; ?></div>
                        <div class="stat-label">Shop Sales Gross</div>
                        <div class="stat-change positive">
                            <i class="fas fa-shopping-bag"></i> Shop sales total
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($total_dispatch_value, 0); ?></div>
                        <div class="stat-label">Total Dispatch Value</div>
                        <div class="stat-change positive">
                            <i class="fas fa-boxes"></i> Dispatches + Shop Sales
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($total_cash_collected + $total_transfers_collected + $total_card_collected + $total_credit_settlement, 0); ?></div>
                        <div class="stat-label">Total Collections</div>
                        <div class="stat-change positive">
                            <i class="fas fa-receipt"></i> Cash + Transfers + Card + Credit Settlement
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i class="fas fa-balance-scale-right"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($total_accounted_for, 0); ?></div>
                        <div class="stat-label">Sales Disposition Total</div>
                        <div class="stat-change <?php echo $is_balanced ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-check-circle"></i> Payments + returns + damage value
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon red">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($total_expenses, 0); ?></div>
                        <div class="stat-label">Total Expenses</div>
                        <div class="stat-change negative">
                            <i class="fas fa-minus-circle"></i> Cash outflows
                        </div>
                    </div>
                </div>

        <!-- Detailed Breakdown -->
                <div class="row">
                    <!-- Dispatch Summary -->
                    <div class="col-md-6">
                        <div class="content-card mb-4">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-truck me-2"></i>Dispatch Summary
                                    </h5>
                                    <p class="card-subtitle">Overview of dispatches for the selected date</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Dispatch #</th>
                                                <th>Dispatcher</th>
                                                <th>Expected</th>
                                                <th>Actual</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($dispatches) && $total_shop_sales == 0): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No dispatches or shop sales for this date</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($dispatches as $dispatch): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($dispatch['dispatch_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($dispatch['dispatcher_name']); ?></td>
                                                    <td>₦<?php echo number_format($dispatch['expected_amount'], 2); ?></td>
                                                    <td>₦<?php echo number_format($dispatch['actual_sales_amount'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if ($total_shop_sales > 0): ?>
                                                <tr class="table-info">
                                                    <td><strong>SHOP</strong></td>
                                                    <td><strong>Shop Sales</strong></td>
                                                    <td><strong>₦<?php echo number_format($total_shop_sales, 2); ?></strong></td>
                                                    <td><strong>₦<?php echo number_format($total_shop_sales, 2); ?></strong></td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr class="table-active">
                                                    <td colspan="2"><strong>Total</strong></td>
                                                    <td><strong>₦<?php echo number_format($total_expected + $total_shop_sales, 2); ?></strong></td>
                                                    <td><strong>₦<?php echo number_format($total_actual_sales + $total_shop_sales, 2); ?></strong></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Collections Breakdown -->
                    <div class="col-md-6">
                        <div class="content-card mb-4">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-money-bill-wave me-2"></i>Collections Breakdown
                                    </h5>
                                    <p class="card-subtitle">Payment methods and transaction details</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Payment Method</th>
                                                <th>Transactions</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><i class="fas fa-money-bill text-success me-2"></i>Cash</td>
                                                <td><?php 
                                                    $dispatcher_cash_tx = array_sum(array_column(array_filter($collections, function($c) { return $c['payment_method'] === 'cash'; }), 'transaction_count'));
                                                    $shop_cash_tx = $shop_sales_transaction_counts['cash'] ?? 0;
                                                    echo $dispatcher_cash_tx + $shop_cash_tx;
                                                ?></td>
                                                <td>₦<?php echo number_format($total_cash_collected, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-exchange-alt text-primary me-2"></i>Transfers</td>
                                                <td><?php 
                                                    $dispatcher_transfer_tx = array_sum(array_column(array_filter($collections, function($c) { return $c['payment_method'] === 'transfer'; }), 'transaction_count'));
                                                    $shop_transfer_tx = $shop_sales_transaction_counts['transfer'] ?? 0;
                                                    echo $dispatcher_transfer_tx + $shop_transfer_tx;
                                                ?></td>
                                                <td>₦<?php echo number_format($total_transfers_collected, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-credit-card text-secondary me-2"></i>Card Payments</td>
                                                <td><?php echo $shop_sales_transaction_counts['card'] ?? 0; ?></td>
                                                <td>₦<?php echo number_format($total_card_collected, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-hand-holding-usd text-warning me-2"></i>Credit Sales</td>
                                                <td><?php echo $shop_sales_transaction_counts['credit'] ?? 0; ?></td>
                                                <td>₦<?php echo number_format($total_credit_sales, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-sync-alt text-info me-2"></i>Credit Settlement</td>
                                                <td><?php echo array_sum(array_column(array_filter($collections, function($c) { return $c['payment_method'] === 'credit_settlement'; }), 'transaction_count')); ?></td>
                                                <td>₦<?php echo number_format($total_credit_settlement, 2); ?></td>
                                            </tr>
                                            <tr class="table-active">
                                                <td colspan="2"><strong>Total Collections</strong></td>
                                                <td><strong>₦<?php echo number_format($total_cash_collected + $total_transfers_collected + $total_card_collected + $total_credit_sales + $total_credit_settlement, 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

        <!-- Returns and Expenses -->
                <div class="row">
                    <!-- Returns Summary -->
                    <div class="col-md-6">
                        <div class="content-card mb-4">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-undo me-2"></i>Returns Summary
                                    </h5>
                                    <p class="card-subtitle">Products returned by dispatchers</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Dispatch #</th>
                                                <th>Qty</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($returns)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No returns for this date</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($returns as $return): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($return['product_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($return['dispatch_number']); ?></td>
                                                    <td><?php echo $return['quantity']; ?></td>
                                                    <td>₦<?php 
                                                        $unitPrice = floatval($return['unit_price']);
                                                        $discountPerUnit = floatval($return['discount_amount']) / max(1, floatval($return['dispatch_quantity']));
                                                        $returnAmount = floatval($return['quantity']) * ($unitPrice - $discountPerUnit);
                                                        echo number_format($returnAmount, 2); 
                                                    ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <tr class="table-active">
                                                    <td colspan="3"><strong>Total Returns</strong></td>
                                                    <td><strong>₦<?php echo number_format($total_returns, 2); ?></strong></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expenses Summary -->
                    <div class="col-md-6">
                        <div class="content-card mb-4">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-receipt me-2"></i>Expenses Summary
                                    </h5>
                                    <p class="card-subtitle">Expenses incurred by dispatchers</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th>Description</th>
                                                <th>Dispatcher</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($expenses)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No expenses for this date</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($expenses as $expense): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars(ucfirst($expense['expense_type'])); ?></td>
                                                    <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                                    <td><?php echo htmlspecialchars($expense['dispatcher_name']); ?></td>
                                                    <td>₦<?php echo number_format($expense['amount'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <tr class="table-active">
                                                    <td colspan="3"><strong>Total Expenses</strong></td>
                                                    <td><strong>₦<?php echo number_format($total_expenses, 2); ?></strong></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

        <!-- Balance Calculation -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-calculator me-2"></i>Balance Calculation
                            </h5>
                            <p class="card-subtitle">Professional accounting reconciliation</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3">Total Dispatch Value (Stock Issued)</h6>
                                <table class="table">
                                    <tr>
                                        <td>Dispatcher Trips Expected</td>
                                        <td class="text-end">₦<?php echo number_format($total_expected, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Shop Sales Gross</td>
                                        <td class="text-end">₦<?php echo number_format($total_shop_sales, 2); ?></td>
                                    </tr>
                                    <tr class="table-active">
                                        <td><strong>Total Dispatch Value</strong></td>
                                        <td class="text-end"><strong>₦<?php echo number_format($total_dispatch_value, 2); ?></strong></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3">Sales Disposition</h6>
                                <table class="table">
                                    <tr>
                                        <td>Cash Sales</td>
                                        <td class="text-end">₦<?php echo number_format($total_cash_collected, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Transfer Sales</td>
                                        <td class="text-end">₦<?php echo number_format($total_transfers_collected, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Card Sales</td>
                                        <td class="text-end">₦<?php echo number_format($total_card_collected, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Credit Sales</td>
                                        <td class="text-end">₦<?php echo number_format($total_credit_sales, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Shop Cash Received</td>
                                        <td class="text-end">₦<?php echo number_format($shop_sales_cash, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Shop Expenses</td>
                                        <td class="text-end">₦<?php echo number_format($shop_total_expenses, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Net Shop Cash Available</td>
                                        <td class="text-end">₦<?php echo number_format($net_shop_cash, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Returns</td>
                                        <td class="text-end">₦<?php echo number_format($total_returns, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Valid Damage Value</td>
                                        <td class="text-end">₦<?php echo number_format($total_damaged_products, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Expenses (separate)</td>
                                        <td class="text-end">₦<?php echo number_format($total_expenses, 2); ?></td>
                                    </tr>
                                    <tr class="table-active">
                                        <td><strong>Sales Disposition Total</strong></td>
                                        <td class="text-end"><strong>₦<?php echo number_format($total_accounted_for, 2); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Net Cash After Expenses</td>
                                        <td class="text-end">₦<?php echo number_format($net_cash_after_expenses, 2); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-12">
                                <table class="table">
                                    <tr class="table-active">
                                        <td><strong>Sales Disposition Variance (Disposition Total - Dispatch Value)</strong></td>
                                        <td class="text-end"><strong class="<?php echo $is_balanced ? 'text-success' : ($balance_variance > 0 ? 'text-success' : 'text-danger'); ?>">
                                            <?php if ($balance_variance > 0): ?>+<?php endif; ?>₦<?php echo number_format(abs($balance_variance), 2); ?> <?php echo $balance_variance > 0 ? '(Surplus)' : ($balance_variance < 0 ? '(Shortage)' : ''); ?>
                                        </strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="content-card">
                    <div class="card-body">
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if (!hasRole('dispatcher')): ?>
                            <a href="../dispatch/daily/index.php?date=<?php echo htmlspecialchars($selected_date); ?>" class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i>Edit Records
                            </a>
                            <?php endif; ?>
                            <a href="../dashboard/index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
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
        document.getElementById('balanceSearch')?.addEventListener('input', function(e) {
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


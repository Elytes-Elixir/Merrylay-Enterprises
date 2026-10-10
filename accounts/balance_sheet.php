<?php
/**
 * Balance Sheet Page for Merrylay Enterprises
 * 
 * Displays financial position with assets, liabilities, and equity
 */

// Start session
require_once __DIR__ . '/../config/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration and auth
require_once '../config/db.php';
require_once '../config/auth.php';

if (!hasRole(['admin', 'manager']) && !hasPermission(PERMISSION_REPORTS_VIEW)) {
    error_log('Unauthorized balance sheet access by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to view this report.');
}

// Get date range
$report_date = $_GET['date'] ?? date('Y-m-d');

// Initialize variables with default values
$inventory = ['total_inventory_value' => 0, 'total_products' => 0, 'low_stock_items' => 0];
$sales = ['total_collections' => 0, 'total_credit_sales' => 0];
$today_dispatch = ['today_trips' => 0, 'today_expected' => 0, 'today_collections' => 0, 'today_credit_sales' => 0, 'today_expenses' => 0, 'today_balance' => 0];
$credit_balance = 0;
$shop_sales_unsettled_collections = 0;
$shop_sales_settled_collections = 0;
$current_assets = 0;
$total_assets = 0;
$current_liabilities = 0;
$total_liabilities = 0;
$total_equity = 0;
$working_capital = 0;
$debt_to_equity_ratio = 0;
$current_ratio = 0;
$error_message = '';

// Additional variables for account summary methodology
$total_returns = 0;
$total_discounts = 0;
$total_damaged = 0;
$total_expenses = 0;
$total_shop_sales = 0;

// Calculate inventory value (assets)
try {
    $stmt = executeQuery("
        SELECT 
            COALESCE(SUM(cost_price * quantity), 0) as total_inventory_value,
            COUNT(*) as total_products,
            SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) as low_stock_items
        FROM products 
        WHERE is_active = 1
    ");
    $inventory = $stmt->fetch();
    
    // Calculate total cash collections from daily_activities (actual cash on hand - exclude credit sales)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(cash_collected + transfer_amount), 0) as total_collections,
            COALESCE(SUM(credit_amount), 0) as total_credit_sales
        FROM daily_activities
        WHERE activity_date <= ?
    ", [$report_date]);
    $sales = $stmt->fetch();

    // Get actual credit sales from creditors table (matching account-summary.php methodology)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(balance), 0) as total_credit_sales_proper
        FROM creditors
        WHERE credit_date <= ? AND status = 'active'
    ", [$report_date]);
    $credit_sales_proper = $stmt->fetch();
    $sales['total_credit_sales'] = $credit_sales_proper['total_credit_sales_proper'] ?? 0;

    // Get returns with product prices for monetary value (matching account-summary.php)
    $stmt = executeQuery("
        SELECT 
            SUM(dr.quantity * p.cost_price) as total_return_value,
            SUM(dr.quantity) as total_return_quantity
        FROM daily_returns dr
        JOIN products p ON dr.product_id = p.id
        WHERE dr.return_date <= ?
    ", [$report_date]);
    $return_totals = $stmt->fetch();
    $total_returns = $return_totals['total_return_value'] ?? 0;

    // Get actual trip discounts from dispatch_items (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.discount_amount), 0) as total_discounts
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) <= ?
    ", [$report_date]);
    $discount_totals = $stmt->fetch();
    $total_discounts = $discount_totals['total_discounts'] ?? 0;

    // Get actual damaged products value from daily_activities (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(damaged_products), 0) as total_damaged_value
        FROM daily_activities
        WHERE activity_date <= ?
    ", [$report_date]);
    $damaged_totals = $stmt->fetch();
    $total_damaged = $damaged_totals['total_damaged_value'] ?? 0;

    // Get total expenses (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(expenses_amount), 0) as total_expenses
        FROM daily_activities
        WHERE activity_date <= ?
    ", [$report_date]);
    $expense_totals = $stmt->fetch();
    $total_expenses = $expense_totals['total_expenses'] ?? 0;

    // Get shop sales with payment method breakdown and collections (matching account-summary.php)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN payment_split = 0 AND payment_method = 'cash' THEN total_amount ELSE 0 END), 0)
                + COALESCE((SELECT SUM(ssp.amount) FROM shop_sale_payments ssp JOIN shop_sales split_sales ON split_sales.id = ssp.shop_sale_id WHERE split_sales.payment_split = 1 AND ssp.payment_method = 'cash' AND DATE(split_sales.sale_date) <= ?), 0) as cash_amount,
            COALESCE(SUM(CASE WHEN payment_split = 0 AND payment_method = 'card' THEN total_amount ELSE 0 END), 0)
                + COALESCE((SELECT SUM(ssp.amount) FROM shop_sale_payments ssp JOIN shop_sales split_sales ON split_sales.id = ssp.shop_sale_id WHERE split_sales.payment_split = 1 AND ssp.payment_method = 'card' AND DATE(split_sales.sale_date) <= ?), 0) as card_amount,
            COALESCE(SUM(CASE WHEN payment_split = 0 AND payment_method = 'transfer' THEN total_amount ELSE 0 END), 0)
                + COALESCE((SELECT SUM(ssp.amount) FROM shop_sale_payments ssp JOIN shop_sales split_sales ON split_sales.id = ssp.shop_sale_id WHERE split_sales.payment_split = 1 AND ssp.payment_method = 'transfer' AND DATE(split_sales.sale_date) <= ?), 0) as transfer_amount,
            COALESCE(SUM(CASE WHEN payment_split = 0 AND payment_method = 'credit' THEN total_amount ELSE 0 END), 0)
                + COALESCE((SELECT SUM(ssp.amount) FROM shop_sale_payments ssp JOIN shop_sales split_sales ON split_sales.id = ssp.shop_sale_id WHERE split_sales.payment_split = 1 AND ssp.payment_method = 'credit' AND DATE(split_sales.sale_date) <= ?), 0) as credit_amount,
            COALESCE(SUM(discount_amount), 0) as discount_amount,
            COALESCE(SUM(total_amount), 0) as total_shop_sales
        FROM shop_sales
        WHERE DATE(sale_date) <= ?
    ", [$report_date, $report_date, $report_date, $report_date, $report_date]);
    $shop_sales_totals = $stmt->fetch();

    // Get shop collections from shop_collections table (separate from sales)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN collection_amount ELSE 0 END), 0) as unsettled_collections,
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN balance_due ELSE 0 END), 0) as unsettled_balance,
            COALESCE(SUM(CASE WHEN status = 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as settled_acknowledged
        FROM shop_collections
        WHERE DATE(collection_date) <= ?
    ", [$report_date]);
    $shop_collections_data = $stmt->fetch();
    $shop_sales_unsettled_collections = floatval($shop_collections_data['unsettled_collections'] ?? 0);
    $shop_sales_settled_collections = floatval($shop_collections_data['settled_acknowledged'] ?? 0);

    // Calculate shop sales totals
    $shop_sales_cash = ($shop_sales_totals['cash_amount'] ?? 0);
    $shop_sales_card = ($shop_sales_totals['card_amount'] ?? 0);
    $shop_sales_transfer = ($shop_sales_totals['transfer_amount'] ?? 0);
    $total_shop_sales = ($shop_sales_totals['total_shop_sales'] ?? 0);

    // Use gross cash (no expense adjustments) - expenses handled separately.
    // The unsettled and settled values are mutually exclusive statuses for the same collection record,
    // so the net effect on assets is the difference between them, not the sum of both amounts.
    $net_shop_cash = $shop_sales_cash;
    $shop_sale_collection_adjustment = -$shop_sales_unsettled_collections + $shop_sales_settled_collections;

    // Calculate dispatch financial data for today's summary only (matching end-of-day.php approach)
    $stmt = executeQuery("
        SELECT
            COUNT(DISTINCT d.id) as today_trips,
            COALESCE(SUM(CASE WHEN (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales')) THEN d.expected_amount ELSE 0 END), 0) as today_expected,
            (SELECT COALESCE(SUM(cash_collected + transfer_amount), 0) FROM daily_activities WHERE activity_date = ?) as today_collections,
            (SELECT COALESCE(SUM(credit_amount), 0) FROM daily_activities WHERE activity_date = ?) as today_credit_sales,
            (SELECT COALESCE(SUM(expenses_amount), 0) FROM daily_activities WHERE activity_date = ?) as today_expenses
        FROM dispatches d
        WHERE DATE(d.dispatch_date) = ?
    ", [$report_date, $report_date, $report_date, $report_date]);
    $today_dispatch = $stmt->fetch();

    // Get today's shop sales (matching end-of-day.php approach)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END), 0) as credit_amount,
            COALESCE(SUM(total_amount), 0) as total_shop_sales
        FROM shop_sales
        WHERE DATE(sale_date) = ?
    ", [$report_date]);
    $today_shop_sales_data = $stmt->fetch();

    // Add shop sales to dispatch totals
    $today_dispatch['today_trips'] += ($today_shop_sales_data['total_shop_sales'] > 0 ? 1 : 0);
    $today_dispatch['today_expected'] += $today_shop_sales_data['total_shop_sales'];
    $today_dispatch['today_shop_sales'] = $today_shop_sales_data['total_shop_sales'];

    // Add shop sales collections to total collections
    $today_dispatch['today_collections'] += $today_shop_sales_data['cash_amount'] + $today_shop_sales_data['transfer_amount'];
    $today_dispatch['today_card_collected'] = $today_shop_sales_data['card_amount'];
    $today_dispatch['today_shop_cash'] = $today_shop_sales_data['cash_amount'];
    $today_dispatch['today_shop_transfer'] = $today_shop_sales_data['transfer_amount'];
    $today_dispatch['today_shop_credit'] = $today_shop_sales_data['credit_amount'];

    // Get today's credit sales from creditors table (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(balance), 0) as today_credit_sales_proper
        FROM creditors
        WHERE credit_date = ? AND status = 'active'
    ", [$report_date]);
    $today_credit_proper = $stmt->fetch();
    $today_dispatch['today_credit_sales'] = $today_credit_proper['today_credit_sales_proper'] ?? 0;

    // Get today's credit settlements from daily_activities (matching end-of-day.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(credit_settlement), 0) as today_credit_settlement
        FROM daily_activities
        WHERE activity_date = ?
    ", [$report_date]);
    $today_credit_settlement = $stmt->fetch();
    $today_dispatch['today_credit_settlement'] = $today_credit_settlement['today_credit_settlement'] ?? 0;

    // Get today's returns (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(dr.quantity * p.cost_price), 0) as today_return_value
        FROM daily_returns dr
        JOIN products p ON dr.product_id = p.id
        WHERE dr.return_date = ?
    ", [$report_date]);
    $today_return = $stmt->fetch();
    $today_dispatch['today_returns'] = $today_return['today_return_value'] ?? 0;

    // Get today's discounts (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.discount_amount), 0) as today_discounts
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) = ?
    ", [$report_date]);
    $today_discount = $stmt->fetch();
    $today_dispatch['today_discounts'] = $today_discount['today_discounts'] ?? 0;

    // Get today's damaged products (matching account-summary.php)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(damaged_products), 0) as today_damaged
        FROM daily_activities
        WHERE activity_date = ?
    ", [$report_date]);
    $today_damaged = $stmt->fetch();
    $today_dispatch['today_damaged'] = $today_damaged['today_damaged'] ?? 0;

    // Calculate actual sales as collections (cash + transfers + card) - this represents actual money collected
    $today_dispatch['today_actual_sales'] = $today_dispatch['today_collections'] + ($today_dispatch['today_card_collected'] ?? 0);

    // Calculate outstanding credit (matching end-of-day.php)
    $today_outstanding_credit = $today_dispatch['today_credit_sales'] - $today_dispatch['today_credit_settlement'];

    // Calculate balance using proper credit treatment (matching end-of-day.php):
    // Accounted For = Cash + Transfer + Card + Credit Settlements + Returns + Discounts + Damaged + Expenses
    // Credit Sales are NOT included - they represent outstanding receivables
    // Balance = Accounted For - (Expected - Outstanding Credit)
    $today_accounted_for = ($today_dispatch['today_collections'] + ($today_dispatch['today_card_collected'] ?? 0) + $today_dispatch['today_credit_settlement'] + $today_dispatch['today_returns'] + $today_dispatch['today_discounts'] + $today_dispatch['today_damaged'] + $today_dispatch['today_expenses']);
    $today_dispatch['today_balance'] = $today_accounted_for - ($today_dispatch['today_expected'] - $today_outstanding_credit);
    
    // Calculate customer credit balances (liability - credit owed by customers) - using creditors table
    $stmt = executeQuery("
        SELECT COALESCE(SUM(balance), 0) as total_credit_balance
        FROM creditors
        WHERE status = 'active'
    ");
    $credit_balance = $stmt->fetch()['total_credit_balance'];

    // Assets: Inventory + Cash on Hand + Accounts Receivable (using account summary methodology).
    // The shop collection adjustment reflects pending versus settled collections without double-counting
    // the same records in the same balance-sheet period.
    $total_accounted_for = ($sales['total_collections'] + $total_returns + $sales['total_credit_sales'] + $total_discounts + $total_damaged + $total_expenses + $total_shop_sales);
    $current_assets = $inventory['total_inventory_value'] + $sales['total_collections'] + $shop_sale_collection_adjustment + $sales['total_credit_sales'] + $total_shop_sales;
    $total_assets = $current_assets;

    // Liabilities: Credit owed by customers
    $current_liabilities = $credit_balance;
    $total_liabilities = $current_liabilities;

    // Equity: Assets - Liabilities (accounting equation)
    $total_equity = $total_assets - $total_liabilities;
    
    // Calculate business metrics
    $working_capital = $current_assets - $current_liabilities;
    $debt_to_equity_ratio = ($total_equity > 0 && $current_liabilities > 0) ? ($current_liabilities / $total_equity) * 100 : 0;
    $current_ratio = $current_liabilities > 0 ? ($current_assets / $current_liabilities) : 0;
    
} catch (PDOException $e) {
    error_log("Balance sheet error: " . $e->getMessage());
    $error_message = "Unable to generate balance sheet.";
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Balance Sheet - Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <title>Balance Sheet - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .company-name {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #2563eb;
            margin-bottom: 0.5rem;
        }
        
        .page-header h4 {
            font-weight: 700;
            font-size: 1.75rem;
            color: #111827;
            margin-bottom: 0.25rem;
        }
        
        .page-header p {
            color: #6b7280;
            margin: 0;
        }

        .report-meta {
            min-width: 220px;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #374151;
            font-size: 0.8rem;
        }

        .report-meta strong {
            color: #111827;
        }
        
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-weight: 600;
            font-size: 1rem;
            color: #111827;
            margin: 0;
        }
        
        .card-subtitle {
            font-size: 0.875rem;
            color: #6b7280;
            margin: 0.25rem 0 0 0;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
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
        
        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin: 0.25rem 0;
        }
        
        .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #6b7280;
        }
        
        /* Accounting Section Styling */
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
        
        .balance-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .balance-item:last-child {
            border-bottom: none;
        }
        
        .balance-label {
            color: #374151;
            font-weight: 500;
            font-size: 0.875rem;
        }
        
        .balance-value {
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .total-row {
            background: #f9fafb;
            padding: 0.75rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
        
        .total-assets-row {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
        }
        
        .total-liabilities-row {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
        }
        
        .total-equity-row {
            background: #dcfce7;
            border-left: 4px solid #10b981;
        }
        
        .assets-header {
            background: #eff6ff;
            border-bottom: 2px solid #3b82f6;
        }
        
        .liabilities-header {
            background: #fef2f2;
            border-bottom: 2px solid #ef4444;
        }
        
        .equity-header {
            background: #f0fdf4;
            border-bottom: 2px solid #10b981;
        }
        
        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
        }
        
        .btn-primary {
            background: #3b82f6;
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
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
        
        .balance-sheet-summary {
            width: 100%;
            min-width: 36rem;
            border-collapse: collapse;
            margin-top: 1rem;
            background: #fff;
            border: 1px solid #e5e7eb;
        }

        .balance-sheet-summary thead th {
            background: #f1f5f9;
            color: #111827;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #dfe7f2;
        }

        .balance-sheet-summary tbody td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.9rem;
            vertical-align: top;
        }

        .balance-sheet-summary tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .balance-sheet-summary .section-label {
            font-weight: 600;
            color: #111827;
        }

        .balance-sheet-summary .amount {
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            text-align: right;
            white-space: nowrap;
        }

        .balance-sheet-summary .total-row td {
            background: #eef6ff;
            font-weight: 700;
            color: #0f172a;
        }

        .balance-check {
            padding: 0.85rem 1rem;
            background: #f8fafc;
            border: 1px solid #dbeafe;
            border-radius: 10px;
            margin-top: 1rem;
            color: #1f2937;
        }

        .balance-sheet-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* A4 Print Styles */
        @page {
            size: A4 portrait;
            margin: 10mm 15mm;
        }
        
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                background: #fff !important;
            }
            
            .dashboard-container {
                display: block !important;
            }
            
            .sidebar,
            .top-bar,
            .card-header button,
            .no-print,
            .btn,
            .form-control,
            .form-select {
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
                border: 1px solid #d6d9e0 !important;
            }
            
            .row {
                display: flex;
                flex-wrap: wrap;
            }
            
            .col-md-4,
            .col-md-6,
            .col-md-8,
            .col-lg-4 {
                width: 100% !important;
                max-width: 100% !important;
                flex: 0 0 100% !important;
            }
            
            .card-body {
                padding: 15px !important;
            }

            .page-header {
                box-shadow: none !important;
                border: 1px solid #d6d9e0 !important;
            }
            
            .table,
            .balance-sheet-summary {
                font-size: 11pt;
            }
            
            .table th,
            .table td,
            .balance-sheet-summary th,
            .balance-sheet-summary td {
                padding: 8px 12px !important;
            }

            thead {
                display: table-header-group;
            }

            .balance-sheet-table-wrap {
                overflow: visible !important;
            }

            .balance-sheet-summary {
                min-width: 0;
                table-layout: fixed;
                width: 100%;
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
                <a href="../stock/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
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
                <a href="../dispatch/credit/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-text">Credit Management</span>
                </a>
                <a href="../dispatch/bank-deposits.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposits</span>
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
                <a href="../accounts/balance_sheet.php" class="nav-link active">
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
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search...">
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
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="company-name">Merrylay Enterprises</div>
                            <h4><i class="fas fa-balance-scale me-2"></i>Balance Sheet</h4>
                            <p>As of <?php echo date('F j, Y', strtotime($report_date)); ?> · Financial position with assets, liabilities, and equity</p>
                        </div>
                        <div class="report-meta">
                            <div><strong>Report date:</strong> <?php echo date('F j, Y', strtotime($report_date)); ?></div>
                            <div><strong>Filters:</strong> All active accounts · Date <= <?php echo htmlspecialchars($report_date); ?></div>
                        </div>
                    </div>
                </div>
                <!-- Date Filter & Actions -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title"><i class="fas fa-calendar-alt me-2"></i>Report Filters</h5>
                            <p class="card-subtitle">As-of date and reporting criteria</p>
                        </div>
                        <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print()">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="d-flex gap-3 align-items-end">
                            <div>
                                <label class="form-label" for="balanceSheetDate">As of date</label>
                                <input type="date" id="balanceSheetDate" class="form-control" name="date" value="<?php echo htmlspecialchars($report_date); ?>">
                            </div>
                            <button type="submit" class="btn btn-primary no-print">
                                <i class="fas fa-search me-1"></i>Update
                            </button>
                        </form>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title"><i class="fas fa-table me-2"></i>Balance Sheet Summary</h5>
                            <p class="card-subtitle">Section totals from the active accounting data set</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="balance-sheet-table-wrap">
                        <table class="balance-sheet-summary" aria-label="Balance sheet summary table">
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th>Account category</th>
                                    <th class="amount">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="section-label">Assets</td>
                                    <td>Cash and collections</td>
                                    <td class="amount">₦<?php echo number_format($sales['total_collections'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td>Receivables / unsettled collections</td>
                                    <td class="amount">₦<?php echo number_format(max($shop_sales_unsettled_collections, 0), 2); ?></td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td>Inventory</td>
                                    <td class="amount">₦<?php echo number_format($inventory['total_inventory_value'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td>Supporting reconciliation — shop sales value</td>
                                    <td class="amount">₦<?php echo number_format($total_shop_sales, 2); ?></td>
                                </tr>
                                <tr class="total-row">
                                    <td class="section-label">Assets</td>
                                    <td>Total assets</td>
                                    <td class="amount">₦<?php echo number_format($total_assets, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="section-label">Liabilities</td>
                                    <td>Current liabilities — creditors / obligations</td>
                                    <td class="amount">₦<?php echo number_format($current_liabilities, 2); ?></td>
                                </tr>
                                <tr class="total-row">
                                    <td class="section-label">Liabilities</td>
                                    <td>Total liabilities</td>
                                    <td class="amount">₦<?php echo number_format($total_liabilities, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="section-label">Equity</td>
                                    <td>Calculated equity (Assets - Liabilities)</td>
                                    <td class="amount">₦<?php echo number_format($total_equity, 2); ?></td>
                                </tr>
                                <tr class="total-row">
                                    <td class="section-label">Equity</td>
                                    <td>Total equity</td>
                                    <td class="amount">₦<?php echo number_format($total_equity, 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                        <div class="balance-check">
                            <strong>Balance check:</strong>
                            Total Assets <?php echo number_format($total_assets, 2); ?> = Total Liabilities + Equity <?php echo number_format($total_liabilities + $total_equity, 2); ?>
                            <div class="mt-2"><strong>Balance difference:</strong> <?php echo number_format($total_assets - ($total_liabilities + $total_equity), 2); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Daily Financial Summary -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title"><i class="fas fa-chart-line me-2"></i>Daily Financial Summary</h5>
                            <p class="card-subtitle">Key performance indicators for <?php echo date('F j, Y', strtotime($report_date)); ?></p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <!-- Revenue Section -->
                            <div class="stat-card revenue-section">
                                <div class="stat-header">
                                    <small class="text-muted text-uppercase fw-bold">Revenue</small>
                                </div>
                                <div class="stat-icon green">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_expected'], 0); ?></div>
                                <div class="stat-label">Gross Revenue</div>
                            </div>

                            <div class="stat-card revenue-section">
                                <div class="stat-icon orange">
                                    <i class="fas fa-percent"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_discounts'], 0); ?></div>
                                <div class="stat-label">Discounts</div>
                            </div>

                            <div class="stat-card revenue-section">
                                <div class="stat-icon blue">
                                    <i class="fas fa-undo"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_returns'], 0); ?></div>
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
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_collections'], 0); ?></div>
                                <div class="stat-label">Cash + Transfer</div>
                            </div>

                            <div class="stat-card collections-section">
                                <div class="stat-icon blue">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_card_collected'] ?? 0, 0); ?></div>
                                <div class="stat-label">Card</div>
                            </div>

                            <div class="stat-card collections-section">
                                <div class="stat-icon purple">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_credit_settlement'] ?? 0, 0); ?></div>
                                <div class="stat-label">Credit Settlements</div>
                            </div>

                            <!-- Credit Section -->
                            <div class="stat-card credit-section">
                                <div class="stat-header">
                                    <small class="text-muted text-uppercase fw-bold">Credit</small>
                                </div>
                                <div class="stat-icon purple">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_credit_sales'], 0); ?></div>
                                <div class="stat-label">Credit Sales</div>
                            </div>

                            <div class="stat-card credit-section">
                                <div class="stat-icon orange">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_outstanding_credit, 0); ?></div>
                                <div class="stat-label">Outstanding</div>
                            </div>

                            <!-- Adjustments Section -->
                            <div class="stat-card adjustments-section">
                                <div class="stat-header">
                                    <small class="text-muted text-uppercase fw-bold">Adjustments</small>
                                </div>
                                <div class="stat-icon red">
                                    <i class="fas fa-box-open"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_damaged'], 0); ?></div>
                                <div class="stat-label">Damaged</div>
                            </div>

                            <div class="stat-card adjustments-section">
                                <div class="stat-icon red">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_expenses'], 0); ?></div>
                                <div class="stat-label">Expenses</div>
                            </div>

                            <!-- Net Position Section -->
                            <div class="stat-card net-section">
                                <div class="stat-header">
                                    <small class="text-muted text-uppercase fw-bold">Net Position</small>
                                </div>
                                <div class="stat-icon <?php echo abs($today_dispatch['today_balance']) < 0.01 ? 'green' : 'red'; ?>">
                                    <i class="fas fa-balance-scale"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($today_dispatch['today_balance'], 0); ?></div>
                                <div class="stat-label">Variance</div>
                            </div>

                            <div class="stat-card net-section">
                                <div class="stat-icon blue">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <div class="stat-value"><?php echo (int)$today_dispatch['today_trips']; ?></div>
                                <div class="stat-label">Total Trips</div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Assets -->
                    <div class="col-lg-4 mb-4">
                        <div class="content-card h-100">
                            <div class="card-header assets-header">
                                <div>
                                    <h5 class="card-title"><i class="fas fa-coins me-2"></i>Assets</h5>
                                    <p class="card-subtitle">Economic resources owned by the business</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Current Assets</h6>
                                
                                <div class="balance-item">
                                    <span class="balance-label">Cash & Cash Equivalents</span>
                                    <span class="balance-value">₦<?php echo number_format($sales['total_collections'], 2); ?></span>
                                </div>
                                
                                <div class="balance-item">
                                    <span class="balance-label">Accounts Receivable</span>
                                    <span class="balance-value">₦<?php echo number_format($sales['total_credit_sales'], 2); ?></span>
                                </div>
                                
                                <div class="balance-item">
                                    <span class="balance-label">Inventory</span>
                                    <span class="balance-value">₦<?php echo number_format($inventory['total_inventory_value'], 2); ?></span>
                                </div>
                                
                                <div class="balance-item">
                                    <span class="balance-label">Supporting reconciliation — shop sales collection balance</span>
                                    <span class="balance-value">₦<?php echo number_format($total_shop_sales, 2); ?></span>
                                </div>
                                
                                <div class="total-row">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold">Total Current Assets</span>
                                        <span class="fw-bold">₦<?php echo number_format($current_assets, 2); ?></span>
                                    </div>
                                </div>
                                
                                <div class="total-row mt-3 total-assets-row">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold fs-6">Total Assets</span>
                                        <span class="fw-bold fs-5">₦<?php echo number_format($total_assets, 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Liabilities -->
                    <div class="col-lg-4 mb-4">
                        <div class="content-card h-100">
                            <div class="card-header liabilities-header">
                                <div>
                                    <h5 class="card-title"><i class="fas fa-credit-card me-2"></i>Liabilities</h5>
                                    <p class="card-subtitle">Obligations owed by the business</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Current Liabilities</h6>
                                
                                <div class="balance-item">
                                    <span class="balance-label">Current liabilities — creditors / obligations</span>
                                    <span class="balance-value">₦<?php echo number_format($current_liabilities, 2); ?></span>
                                </div>
                                
                                <div class="total-row">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold">Total Current Liabilities</span>
                                        <span class="fw-bold">₦<?php echo number_format($current_liabilities, 2); ?></span>
                                    </div>
                                </div>
                                
                                <div class="total-row mt-3 total-liabilities-row">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold fs-6">Total Liabilities</span>
                                        <span class="fw-bold fs-5">₦<?php echo number_format($total_liabilities, 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Equity -->
                    <div class="col-lg-4 mb-4">
                        <div class="content-card h-100">
                            <div class="card-header equity-header">
                                <div>
                                    <h5 class="card-title"><i class="fas fa-landmark me-2"></i>Equity</h5>
                                    <p class="card-subtitle">Owner's interest in the business</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <h6 class="text-uppercase text-muted mb-3 fw-bold" style="font-size: 0.75rem;">Shareholders' Equity</h6>
                                
                                <div class="balance-item">
                                    <span class="balance-label">Calculated equity (Assets - Liabilities)</span>
                                    <span class="balance-value">₦<?php echo number_format($total_equity, 2); ?></span>
                                </div>
                                
                                <div class="total-row mt-3 total-equity-row">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold fs-6">Total Equity</span>
                                        <span class="fw-bold fs-5">₦<?php echo number_format($total_equity, 2); ?></span>
                                    </div>
                                </div>
                                
                                <div class="alert alert-light border mt-3 mb-0">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-check-circle text-success"></i>
                                        <small class="text-muted">
                                            <strong>Accounting Equation:</strong> Assets = Liabilities + Equity<br>
                                            ₦<?php echo number_format($total_assets, 2); ?> = ₦<?php echo number_format($total_liabilities, 2); ?> + ₦<?php echo number_format($total_equity, 2); ?><br>
                                            <strong>Balance difference:</strong> ₦<?php echo number_format($total_assets - ($total_liabilities + $total_equity), 2); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Ratios & Analysis -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title"><i class="fas fa-chart-line me-2"></i>Financial Ratios & Analysis</h5>
                            <p class="card-subtitle">Key performance indicators and financial health metrics</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="card h-100 border-0 bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">
                                            <i class="fas fa-coins me-2 text-primary"></i>Liquidity Ratios
                                        </h6>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Current Ratio</span>
                                                <strong class="<?php echo $current_ratio >= 2 ? 'text-success' : ($current_ratio >= 1 ? 'text-warning' : 'text-danger'); ?>">
                                                    <?php echo number_format($current_ratio, 2); ?>
                                                </strong>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar <?php echo $current_ratio >= 2 ? 'bg-success' : ($current_ratio >= 1 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                     style="width: <?php echo min($current_ratio * 20, 100); ?>%"></div>
                                            </div>
                                            <small class="text-muted">Ability to pay short-term obligations (≥2 is healthy)</small>
                                        </div>
                                        <div class="mb-0">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Working Capital</span>
                                                <strong class="<?php echo $working_capital >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    ₦<?php echo number_format($working_capital, 2); ?>
                                                </strong>
                                            </div>
                                            <small class="text-muted">Current assets minus current liabilities</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card h-100 border-0 bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3">
                                            <i class="fas fa-percentage me-2 text-primary"></i>Solvency Ratios
                                        </h6>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Debt-to-Equity Ratio</span>
                                                <strong class="<?php echo $debt_to_equity_ratio <= 50 ? 'text-success' : ($debt_to_equity_ratio <= 100 ? 'text-warning' : 'text-danger'); ?>">
                                                    <?php echo number_format($debt_to_equity_ratio, 1); ?>%
                                                </strong>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar <?php echo $debt_to_equity_ratio <= 50 ? 'bg-success' : ($debt_to_equity_ratio <= 100 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                     style="width: <?php echo min($debt_to_equity_ratio, 100); ?>%"></div>
                                            </div>
                                            <small class="text-muted">Financial leverage (≤50% is healthy)</small>
                                        </div>
                                        <div class="mb-0">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Equity Ratio</span>
                                                <strong class="text-info">
                                                    <?php echo $total_assets > 0 ? number_format(($total_equity / $total_assets) * 100, 1) : 0; ?>%
                                                </strong>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-info" 
                                                     style="width: <?php echo $total_assets > 0 ? min(($total_equity / $total_assets) * 100, 100) : 0; ?>%"></div>
                                            </div>
                                            <small class="text-muted">Owner's equity as percentage of total assets</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-light border mt-3 mb-0">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-info-circle flex-shrink-0 mt-1 text-primary"></i>
                                <div>
                                    <strong>Financial Health Summary:</strong> 
                                    <ul class="mb-0 mt-2">
                                        <li><strong>Current Ratio <?php echo number_format($current_ratio, 2); ?>:</strong> <?php echo $current_ratio >= 2 ? 'Excellent liquidity position' : ($current_ratio >= 1 ? 'Adequate liquidity' : 'Low liquidity - may need attention'); ?></li>
                                        <li><strong>Debt-to-Equity <?php echo number_format($debt_to_equity_ratio, 1); ?>%:</strong> <?php echo $debt_to_equity_ratio <= 50 ? 'Low financial risk' : ($debt_to_equity_ratio <= 100 ? 'Moderate financial risk' : 'High financial risk'); ?></li>
                                        <li><strong>Working Capital ₦<?php echo number_format($working_capital, 2); ?>:</strong> <?php echo $working_capital >= 0 ? 'Positive working capital - good for operations' : 'Negative working capital - may need financing'; ?></li>
                                    </ul>
                                </div>
                            </div>
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


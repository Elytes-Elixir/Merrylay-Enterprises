<?php
/**
 * Daily Reports Page for Merrylay Enterprises
 * 
 * Generates and displays daily business reports
 */

// Start session
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration and auth helpers
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/cache.php';

date_default_timezone_set('Africa/Lagos');
$hubTimezone = new DateTimeZone('Africa/Lagos');
$hubToday = new DateTimeImmutable('today', $hubTimezone);
$hubRange = is_string($_GET['hub_range'] ?? null) ? $_GET['hub_range'] : 'today';
$hubRangeError = '';
$parseHubDate = static function ($value) use ($hubTimezone) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $hubTimezone);
    return $parsedDate && $parsedDate->format('Y-m-d') === $value ? $parsedDate : null;
};

switch ($hubRange) {
    case 'yesterday':
        $hubStartDate = $hubToday->modify('-1 day');
        $hubEndDate = $hubStartDate;
        $hubRangeLabel = 'Yesterday';
        break;
    case 'this_week':
        $hubStartDate = $hubToday->modify('monday this week');
        $hubEndDate = $hubStartDate->modify('+6 days');
        $hubRangeLabel = 'This week';
        break;
    case 'this_month':
        $hubStartDate = $hubToday->modify('first day of this month');
        $hubEndDate = $hubToday->modify('last day of this month');
        $hubRangeLabel = 'This month';
        break;
    case 'previous_month':
        $hubStartDate = $hubToday->modify('first day of previous month');
        $hubEndDate = $hubToday->modify('last day of previous month');
        $hubRangeLabel = 'Previous month';
        break;
    case 'custom':
        $hubStartDate = $parseHubDate($_GET['hub_start_date'] ?? null);
        $hubEndDate = $parseHubDate($_GET['hub_end_date'] ?? null);
        if (!$hubStartDate || !$hubEndDate || $hubEndDate < $hubStartDate) {
            $hubRangeError = 'Enter a valid custom date range.';
            $hubRange = 'today';
            $hubStartDate = $hubToday;
            $hubEndDate = $hubToday;
            $hubRangeLabel = 'Today';
        } else {
            $hubRangeLabel = 'Custom range';
        }
        break;
    case 'today':
    default:
        $hubRange = 'today';
        $hubStartDate = $hubToday;
        $hubEndDate = $hubToday;
        $hubRangeLabel = 'Today';
        break;
}

$hubStart = $hubStartDate->format('Y-m-d');
$hubEnd = $hubEndDate->format('Y-m-d');
$hubRangeDisplay = $hubStartDate->format('M j, Y') . ($hubStart === $hubEnd ? '' : ' - ' . $hubEndDate->format('M j, Y'));
$hubDateParams = $hubStart === $hubEnd ? ['date' => $hubStart] : [];
$hubProfitLossParams = ['start_date' => $hubStart, 'end_date' => $hubEnd];
$hubDailyReportParams = ['type' => 'financial'];
if ($hubRange === 'today' || $hubRange === 'yesterday') {
    $hubDailyReportParams += ['date' => $hubStart, 'period' => 'daily'];
} elseif ($hubRange === 'this_week') {
    $hubDailyReportParams += ['date' => $hubStart, 'period' => 'weekly'];
} elseif ($hubRange === 'this_month' || $hubRange === 'previous_month') {
    $hubDailyReportParams += ['date' => $hubStart, 'period' => 'monthly'];
} elseif ($hubRange === 'custom' && $hubStart === $hubEnd) {
    $hubDailyReportParams += ['date' => $hubStart, 'period' => 'daily'];
}

$hubUrl = static function ($path, array $params = []) {
    $url = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    return $params ? $url . '?' . http_build_query($params) : $url;
};
$hubLegacyParams = [];
foreach (['date', 'type', 'period'] as $legacyParameter) {
    if (isset($_GET[$legacyParameter]) && is_scalar($_GET[$legacyParameter])) {
        $hubLegacyParams[$legacyParameter] = (string) $_GET[$legacyParameter];
    }
}

$canViewFinanceReports = canViewFinancials();
$canViewDispatchReports = hasRole(['admin', 'manager', 'cashier']);
$canViewStockReports = hasRole(['admin', 'manager', 'dispatcher']);
$canViewShopReports = hasRole(['admin', 'manager', 'shop_attendant']);
$canViewManagementReports = hasRole(['admin', 'manager']);
$reportHubSections = [
    [
        'title' => 'Sales and collections',
        'icon' => 'fas fa-cash-register',
        'reports' => [
            ['title' => 'Daily driver sales and collections', 'description' => 'Daily sales, cash, transfers, credit, returns and expenses by driver.', 'icon' => 'fas fa-chart-line', 'path' => 'dispatch/daily/account-summary.php', 'params' => $hubDateParams, 'visible' => $canViewFinanceReports],
            ['title' => 'Shop sales history', 'description' => 'Completed shop sales with the page’s date filter and attendant visibility rules.', 'icon' => 'fas fa-store', 'path' => 'shop/history.php', 'params' => $hubDateParams, 'visible' => $canViewShopReports],
            ['title' => 'Shop payment breakdown', 'description' => 'Shop sales totals by recorded payment method.', 'icon' => 'fas fa-money-bill-transfer', 'path' => 'shop/daily-accounting.php', 'params' => $hubDateParams, 'visible' => $canViewShopReports],
        ],
    ],
    [
        'title' => 'Dispatch and driver reports',
        'icon' => 'fas fa-truck',
        'reports' => [
            ['title' => 'Daily dispatch activity', 'description' => 'Dispatch list and trip details for a selected day.', 'icon' => 'fas fa-clipboard-list', 'path' => 'dispatch/daily.php', 'params' => $hubDateParams, 'visible' => $canViewDispatchReports],
            ['title' => 'Driver account summary', 'description' => 'Daily collections, returns and activity summarized by driver.', 'icon' => 'fas fa-users', 'path' => 'dispatch/daily/account-summary.php', 'params' => $hubDateParams, 'visible' => $canViewFinanceReports],
            ['title' => 'Returns and damage activity', 'description' => 'Daily activity summary including recorded returns and damaged quantities.', 'icon' => 'fas fa-rotate-left', 'path' => 'dispatch/daily/summary.php', 'params' => $hubDateParams, 'visible' => $canViewDispatchReports],
            ['title' => 'Expected versus actual dispatch values', 'description' => 'Daily dispatch balance and variance review.', 'icon' => 'fas fa-scale-balanced', 'path' => 'finance/daily_balance.php', 'params' => $hubDateParams, 'visible' => $canViewFinanceReports],
        ],
    ],
    [
        'title' => 'Stock and inventory',
        'icon' => 'fas fa-boxes-stacked',
        'reports' => [
            ['title' => 'Daily stock summary', 'description' => 'Stock movement and daily product balances for the selected day.', 'icon' => 'fas fa-box', 'path' => 'stock/summary.php', 'params' => $hubDateParams, 'visible' => $canViewStockReports],
            ['title' => 'Daily stock analysis', 'description' => 'Daily stock changes, dispatch quantities, returns and shop sales.', 'icon' => 'fas fa-chart-column', 'path' => 'stock/analysis.php', 'params' => $hubDateParams, 'visible' => $canViewStockReports],
            ['title' => 'Current stock and low-stock report', 'description' => 'Existing inventory status and low-stock view.', 'icon' => 'fas fa-warehouse', 'path' => 'reports/daily.php', 'params' => ['type' => 'inventory'], 'visible' => $canViewManagementReports],
        ],
    ],
    [
        'title' => 'Finance and accounting',
        'icon' => 'fas fa-file-invoice-dollar',
        'reports' => [
            ['title' => 'Daily accounting summary', 'description' => 'Daily financial activity and account summary.', 'icon' => 'fas fa-file-invoice', 'path' => 'dispatch/daily/account-summary.php', 'params' => $hubDateParams, 'visible' => $canViewFinanceReports],
            ['title' => 'Daily balance', 'description' => 'Daily account balance and reconciliation detail.', 'icon' => 'fas fa-scale-balanced', 'path' => 'finance/daily_balance.php', 'params' => $hubDateParams, 'visible' => $canViewFinanceReports],
            ['title' => 'Profit and loss', 'description' => 'Income statement for the selected date range.', 'icon' => 'fas fa-chart-pie', 'path' => 'accounts/profit_loss.php', 'params' => $hubProfitLossParams, 'visible' => hasRole('admin')],
            ['title' => 'Balance sheet', 'description' => 'Financial position and balances as of the selected end date.', 'icon' => 'fas fa-scale-balanced', 'path' => 'accounts/balance_sheet.php', 'params' => ['date' => $hubEnd], 'visible' => hasRole(['admin', 'manager'])],
            ['title' => 'Discounts and expenses detail', 'description' => 'Existing daily report breakdown for the selected supported period.', 'icon' => 'fas fa-receipt', 'path' => 'reports/daily.php', 'params' => $hubDailyReportParams, 'visible' => $canViewManagementReports],
        ],
    ],
];

// Initialize variables
$report_date = date('Y-m-d');
$report_type = 'financial';
$report_period = 'daily';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $report_date = $_GET['date'] ?? date('Y-m-d');
    $report_type = $_GET['type'] ?? 'financial';
    $report_period = $_GET['period'] ?? 'daily';
}

$report_date = date('Y-m-d', strtotime($report_date));
$valid_periods = ['daily', 'weekly', 'monthly'];
$valid_report_types = ['all', 'inventory', 'financial'];
if (!in_array($report_period, $valid_periods, true)) {
    $report_period = 'daily';
}
if (!in_array($report_type, $valid_report_types, true)) {
    $report_type = 'financial';
}

switch ($report_period) {
    case 'weekly':
        // Get Monday of the week for the selected date
        $period_start = date('Y-m-d', strtotime('monday this week', strtotime($report_date)));
        // End date is Monday of next week (excludes Sunday)
        $period_end = date('Y-m-d', strtotime($period_start . ' +7 days'));
        $report_caption = 'Week of ' . date('M j, Y', strtotime($period_start)) . ' to ' . date('M j, Y', strtotime($period_end . ' -1 day'));
        break;
    case 'monthly':
        // Start from 1st of the month
        $period_start = date('Y-m-01', strtotime($report_date));
        // End date is 1st of next month
        $period_end = date('Y-m-d', strtotime($period_start . ' +1 month'));
        $report_caption = date('F Y', strtotime($report_date));
        break;
    default:
        // Daily: single day
        $period_start = $report_date;
        $period_end = date('Y-m-d', strtotime($report_date . ' +1 day'));
        $report_caption = date('F j, Y', strtotime($report_date));
        break;
}

$period_start_datetime = $period_start . ' 00:00:00';
$period_end_datetime = $period_end . ' 00:00:00';

// NOTE: All queries below use >= start and < end to ensure all records for the entire period are included
// - Weekly: Monday 00:00:00 to next Monday 00:00:00 (includes full week)
// - Monthly: 1st 00:00:00 to 1st of next month 00:00:00 (includes full month)
// - Daily: selected date 00:00:00 to next day 00:00:00 (includes full day)

// OPTIMIZED: Add caching for report data (5 minute cache)
$cache_key = "daily_report_{$report_type}_{$report_period}_{$report_date}";
$cached_data = cache_get($cache_key);

try {
    if ($cached_data !== null) {
        $inventory_data = $cached_data['inventory_data'] ?? [];
        $low_stock_products = $cached_data['low_stock_products'] ?? [];
        $discount_breakdown = $cached_data['discount_breakdown'] ?? [];
        $expense_breakdown = $cached_data['expense_breakdown'] ?? [];
    } else {
        $inventory_data = [];
        $discount_breakdown = [];
        $expense_breakdown = [];

        // Get discount breakdown by trip for the period
        if ($report_type === 'financial' || $report_type === 'all') {
            // Get discounts from dispatch_items (trip discounts) with trip details
            $stmt = executeQuery("
                SELECT
                    d.id as dispatch_id,
                    d.dispatch_number,
                    d.dispatcher_name,
                    d.dispatch_date,
                    COALESCE(SUM(di.discount_amount), 0) as total_discount,
                    'trip' as discount_source
                FROM dispatch_items di
                JOIN dispatches d ON di.dispatch_id = d.id
                WHERE d.dispatch_date >= ? AND d.dispatch_date < ?
                  AND di.discount_amount > 0
                GROUP BY d.id, d.dispatch_number, d.dispatcher_name, d.dispatch_date
                ORDER BY d.dispatch_date ASC, d.dispatch_number ASC
            ", [$period_start_datetime, $period_end_datetime]);
            $dispatch_discounts = $stmt->fetchAll();

            // Get discounts from shop_sales (non-trip discounts)
            $stmt = executeQuery("
                SELECT
                    0 as dispatch_id,
                    'Shop Sale' as dispatch_number,
                    'Shop' as dispatcher_name,
                    DATE(sale_date) as dispatch_date,
                    COALESCE(SUM(discount_amount), 0) as total_discount,
                    'shop' as discount_source
                FROM shop_sales
                WHERE sale_date >= ? AND sale_date < ?
                  AND discount_amount > 0
                GROUP BY DATE(sale_date)
                ORDER BY dispatch_date ASC
            ", [$period_start_datetime, $period_end_datetime]);
            $shop_discounts = $stmt->fetchAll();

            // Get discounts from daily_activities (non-trip discounts)
            $stmt = executeQuery("
                SELECT
                    0 as dispatch_id,
                    'Daily Activity' as dispatch_number,
                    driver_name as dispatcher_name,
                    DATE(activity_date) as dispatch_date,
                    COALESCE(SUM(discounts), 0) as total_discount,
                    'daily' as discount_source
                FROM daily_activities
                WHERE activity_date >= ? AND activity_date < ?
                  AND discounts > 0
                GROUP BY DATE(activity_date), driver_name
                ORDER BY dispatch_date ASC
            ", [$period_start_datetime, $period_end_datetime]);
            $daily_activities_discounts = $stmt->fetchAll();

            // Merge all discount data
            $discount_breakdown = array_merge($dispatch_discounts, $shop_discounts, $daily_activities_discounts);

            // Sort by date and then by dispatch number
            usort($discount_breakdown, function($a, $b) {
                $dateCompare = strtotime($a['dispatch_date']) - strtotime($b['dispatch_date']);
                if ($dateCompare !== 0) return $dateCompare;
                // Sort trips first, then shop, then daily
                $sourceOrder = ['trip' => 0, 'shop' => 1, 'daily' => 2];
                $sourceCompare = ($sourceOrder[$a['discount_source']] ?? 99) - ($sourceOrder[$b['discount_source']] ?? 99);
                if ($sourceCompare !== 0) return $sourceCompare;
                return strcmp($a['dispatch_number'], $b['dispatch_number']);
            });

            // Get expenses breakdown by trip for the period
            // Get expenses from daily_activities with trip details
            $stmt = executeQuery("
                SELECT
                    d.id as dispatch_id,
                    d.dispatch_number,
                    d.dispatcher_name,
                    DATE(da.activity_date) as dispatch_date,
                    COALESCE(SUM(da.expenses_amount), 0) as total_expenses,
                    'trip' as expense_source
                FROM daily_activities da
                LEFT JOIN dispatches d ON da.driver_name = d.dispatcher_name AND DATE(da.activity_date) = DATE(d.dispatch_date)
                WHERE da.activity_date >= ? AND da.activity_date < ?
                  AND da.expenses_amount > 0
                GROUP BY d.id, d.dispatch_number, d.dispatcher_name, DATE(da.activity_date)
                ORDER BY dispatch_date ASC, d.dispatch_number ASC
            ", [$period_start_datetime, $period_end_datetime]);
            $daily_activities_expenses = $stmt->fetchAll();

            // Get shop collections as expenses (non-trip)
            $stmt = executeQuery("
                SELECT
                    0 as dispatch_id,
                    'Shop Collection' as dispatch_number,
                    'Shop' as dispatcher_name,
                    DATE(collection_date) as dispatch_date,
                    COALESCE(SUM(collection_amount), 0) as total_expenses,
                    'shop' as expense_source
                FROM shop_collections
                WHERE collection_date >= ? AND collection_date < ?
                GROUP BY DATE(collection_date)
                ORDER BY dispatch_date ASC
            ", [$period_start_datetime, $period_end_datetime]);
            $shop_collections_expenses = $stmt->fetchAll();

            // Merge all expense data
            $expense_breakdown = array_merge($daily_activities_expenses, $shop_collections_expenses);

            // Sort by date and then by dispatch number
            usort($expense_breakdown, function($a, $b) {
                $dateCompare = strtotime($a['dispatch_date']) - strtotime($b['dispatch_date']);
                if ($dateCompare !== 0) return $dateCompare;
                // Sort trips first, then shop
                $sourceOrder = ['trip' => 0, 'shop' => 1];
                $sourceCompare = ($sourceOrder[$a['expense_source']] ?? 99) - ($sourceOrder[$b['expense_source']] ?? 99);
                if ($sourceCompare !== 0) return $sourceCompare;
                return strcmp($a['dispatch_number'], $b['dispatch_number']);
            });
        }
    }
    
    if ($report_type === 'inventory' || $report_type === 'all') {
        // Current inventory status
        $stmt = executeQuery("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock,
                COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock,
                COUNT(CASE WHEN quantity > reorder_level THEN 1 END) as in_stock,
                COALESCE(SUM(quantity * selling_price), 0) as total_value
            FROM products
        ");
        $inventory_data = $stmt->fetch();
        
        // Ensure all inventory keys exist with default values
        $inventory_data = array_merge([
            'total_products' => 0,
            'out_of_stock' => 0,
            'low_stock' => 0,
            'in_stock' => 0,
            'total_value' => 0
        ], $inventory_data ?: []);
        
        // Low stock products
        $stmt = executeQuery("
            SELECT 
                name,
                quantity,
                unit,
                reorder_level
            FROM products 
            WHERE quantity <= reorder_level
            ORDER BY quantity ASC
            LIMIT 10
        ");
        $low_stock_products = $stmt->fetchAll();
        
        // OPTIMIZED: Cache the report data for 5 minutes
        cache_set($cache_key, [
            'inventory_data' => $inventory_data ?? [],
            'low_stock_products' => $low_stock_products ?? [],
            'discount_breakdown' => $discount_breakdown ?? [],
            'expense_breakdown' => $expense_breakdown ?? []
        ], 300); // 5 minute cache
    }
} catch (PDOException $e) {
    error_log("Daily reports error: " . $e->getMessage());
    $error_message = "Unable to generate report.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Daily reports dashboard for Merrylay Enterprises - Sales, dispatch, and inventory analytics">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Daily Reports - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <style>
        /* Fallback fonts for offline */
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    </style>
    
    <!-- CSS Framework -->
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <style>
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .stock-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .stock-card h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }

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
        
        .stat-change {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-top: 0.25rem;
        }
        
        .stat-change.positive {
            color: #10b981;
        }
        
        .stat-change.negative {
            color: #ef4444;
        }

        /* Accounting Section Styling */
        .discount-section {
            border-left: 3px solid #f59e0b;
        }

        .expense-section {
            border-left: 3px solid #ef4444;
        }

        .deduction-section {
            border-left: 3px solid #8b5cf6;
        }

        .inventory-section {
            border-left: 3px solid #3b82f6;
        }

        .table-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .table-section h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }
        
        .table-section table {
            margin-bottom: 0;
        }
        
        .table-section thead {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
        }
        
        .table-section th {
            padding: 1rem;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .table-section td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
        }

        .inventory-value-section {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border-left: 4px solid #10b981;
        }
        
        .value-display {
            font-size: 2rem;
            font-weight: 700;
            color: #166534;
            margin: 0.5rem 0;
        }
        
        .currency-symbol {
            font-size: 1.5rem;
            margin-right: 0.25rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd6 0%, #6a4190 100%);
            transform: translateY(-1px);
        }
        
        .btn-outline-secondary {
            border: 2px solid #6b7280;
            color: #6b7280;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-outline-secondary:hover {
            background: #6b7280;
            border-color: #6b7280;
            color: white;
            transform: translateY(-1px);
        }
        
        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }

        .reports-hub {
            margin: 0 0 2rem;
        }

        .reports-hub-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .reports-hub-header h2 {
            color: #142a36;
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
        }

        .reports-hub-header p {
            color: #52636d;
            margin: 0.35rem 0 0;
        }

        .reports-period {
            color: #173d3a;
            font-size: 0.9rem;
            font-weight: 700;
            text-align: right;
        }

        .reports-period span {
            color: #52636d;
            display: block;
            font-size: 0.78rem;
            font-weight: 500;
            margin-top: 0.2rem;
        }

        .reports-filter-panel {
            background: #fff;
            border: 1px solid #d7e1e4;
            border-left: 4px solid #1f766e;
            margin-bottom: 1.75rem;
            padding: 1.1rem 1.25rem;
        }

        .reports-filter-panel h3 {
            color: #19333e;
            font-size: 1rem;
            font-weight: 700;
            margin: 0 0 0.8rem;
        }

        .reports-range-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .reports-range-buttons .btn {
            border-color: #b7c7cc;
            color: #294550;
            font-weight: 600;
        }

        .reports-range-buttons .btn.active,
        .reports-range-buttons .btn:hover {
            background: #1f766e;
            border-color: #1f766e;
            color: #fff;
        }

        .reports-custom-range {
            align-items: flex-end;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .reports-custom-range .form-group {
            min-width: 170px;
        }

        .reports-custom-range label {
            color: #425963;
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .reports-section {
            margin: 0 0 1.75rem;
        }

        .reports-section-heading {
            align-items: center;
            border-bottom: 1px solid #d7e1e4;
            color: #19333e;
            display: flex;
            gap: 0.65rem;
            margin-bottom: 0.85rem;
            padding-bottom: 0.65rem;
        }

        .reports-section-heading i {
            color: #1f766e;
            width: 1.2rem;
        }

        .reports-section-heading h3 {
            font-size: 1.08rem;
            font-weight: 700;
            margin: 0;
        }

        .reports-card-grid {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .reports-card {
            align-items: center;
            background: #fff;
            border: 1px solid #d7e1e4;
            border-radius: 4px;
            color: #19333e;
            display: flex;
            gap: 0.85rem;
            min-height: 84px;
            padding: 0.9rem 1rem;
            text-decoration: none;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }

        .reports-card:hover,
        .reports-card:focus-visible {
            background: #f4faf9;
            border-color: #1f766e;
            color: #142a36;
        }

        .reports-card-icon {
            align-items: center;
            background: #e9f4f2;
            border-radius: 4px;
            color: #1f766e;
            display: flex;
            flex: 0 0 38px;
            height: 38px;
            justify-content: center;
        }

        .reports-card-copy {
            flex: 1;
            min-width: 0;
        }

        .reports-card-copy strong {
            display: block;
            font-size: 0.93rem;
            line-height: 1.3;
        }

        .reports-card-copy small {
            color: #52636d;
            display: block;
            line-height: 1.4;
            margin-top: 0.25rem;
        }

        .reports-card-arrow {
            color: #54717a;
            flex: 0 0 auto;
        }

        @media (max-width: 767.98px) {
            .reports-hub-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .reports-period {
                text-align: left;
            }

            .reports-card-grid {
                grid-template-columns: 1fr;
            }
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
            .reports-filter-panel,
            .reports-card-arrow,
            .reports-card-icon,
            .btn,
            .mobile-menu-toggle {
                display: none !important;
            }

            .main-content,
            .dashboard-content {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .dashboard-container {
                display: block !important;
            }

            .reports-card {
                break-inside: avoid;
                color: #111 !important;
                min-height: 0;
                padding: 0.5rem;
            }

            .reports-hub-header,
            .reports-section-heading,
            .content-card,
            .table-section {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .table-responsive {
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
            th,
            td {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            a {
                color: #111 !important;
                text-decoration: none !important;
            }
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
                <a href="../admin/end-of-day.php" class="nav-link <?php echo $currentPage === 'end-of-day.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../reports/daily.php" class="nav-link <?php echo $currentPage === 'daily.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <a href="../users/manage.php" class="nav-link <?php echo $currentPage === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'users') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="../admin/data_management.php" class="nav-link <?php echo $currentPage === 'data_management.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <a href="../admin/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-text">Stock Statistics</span>
                </a>
                <div class="nav-section-title admin">System</div>
                <a href="../admin/backup.php" class="nav-link <?php echo $currentPage === 'backup.php' ? 'active' : ''; ?>">
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
                        <input type="text" placeholder="Search reports, sales, dispatches..." id="reportsSearch">
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
                            <h1><i class="fas fa-chart-bar me-3"></i>Daily Reports</h1>
                            <p>Generate and view comprehensive daily business reports</p>
                            <div class="welcome-meta">
                                <div>
                                    <i class="fas fa-calendar-day"></i>
                                    <span><?php echo date('l, F j, Y', strtotime($report_date)); ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <span id="currentTime"><?php echo date('H:i'); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                            <button type="button" class="btn btn-outline-secondary" disabled aria-disabled="true" title="Export is unavailable">
                                <i class="fas fa-download me-2" aria-hidden="true"></i>Export unavailable
                            </button>
                        </div>
                    </div>
                </div>

                <section class="reports-hub" aria-labelledby="reportsHubTitle">
                    <div class="reports-hub-header">
                        <div>
                            <h2 id="reportsHubTitle">Merrylay Reports Hub</h2>
                            <p>Reports across sales, dispatch, stock and accounting.</p>
                        </div>
                        <div class="reports-period" aria-live="polite">
                            <?php echo htmlspecialchars($hubRangeDisplay); ?>
                            <span><?php echo htmlspecialchars($hubRangeLabel); ?> · Africa/Lagos</span>
                        </div>
                    </div>

                    <div class="reports-filter-panel" aria-label="Report date range">
                        <h3><i class="fas fa-calendar-days me-2" aria-hidden="true"></i>Date and period</h3>
                        <?php if ($hubRangeError !== ''): ?>
                            <div class="alert alert-warning py-2" role="alert"><?php echo htmlspecialchars($hubRangeError); ?></div>
                        <?php endif; ?>
                        <form method="GET" action="<?php echo htmlspecialchars($hubUrl('reports/daily.php')); ?>">
                            <?php foreach ($hubLegacyParams as $name => $value): ?>
                                <input type="hidden" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($value); ?>">
                            <?php endforeach; ?>
                            <div class="reports-range-buttons" role="group" aria-label="Quick date ranges">
                                <?php
                                $hubPresets = [
                                    'today' => 'Today',
                                    'yesterday' => 'Yesterday',
                                    'this_week' => 'This week',
                                    'this_month' => 'This month',
                                    'previous_month' => 'Previous month',
                                ];
                                foreach ($hubPresets as $preset => $label):
                                ?>
                                    <button class="btn btn-sm <?php echo $hubRange === $preset ? 'active' : 'btn-outline-secondary'; ?>" type="submit" name="hub_range" value="<?php echo htmlspecialchars($preset); ?>" aria-pressed="<?php echo $hubRange === $preset ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($label); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </form>
                        <form class="reports-custom-range" method="GET" action="<?php echo htmlspecialchars($hubUrl('reports/daily.php')); ?>">
                            <input type="hidden" name="hub_range" value="custom">
                            <?php foreach ($hubLegacyParams as $name => $value): ?>
                                <input type="hidden" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($value); ?>">
                            <?php endforeach; ?>
                            <div class="form-group">
                                <label for="hubStartDate">From</label>
                                <input class="form-control" id="hubStartDate" name="hub_start_date" type="date" value="<?php echo htmlspecialchars($hubStart); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="hubEndDate">To</label>
                                <input class="form-control" id="hubEndDate" name="hub_end_date" type="date" value="<?php echo htmlspecialchars($hubEnd); ?>" required>
                            </div>
                            <button class="btn btn-primary" type="submit"><i class="fas fa-filter me-2" aria-hidden="true"></i>Apply range</button>
                            <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($hubUrl('reports/daily.php')); ?>">Clear filters</a>
                        </form>
                    </div>

                    <?php foreach ($reportHubSections as $section): ?>
                        <?php
                        $visibleReports = array_filter($section['reports'], static function ($report) {
                            return $report['visible'];
                        });
                        if (!$visibleReports) {
                            continue;
                        }
                        ?>
                        <section class="reports-section" aria-labelledby="section-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $section['title']))); ?>">
                            <div class="reports-section-heading">
                                <i class="<?php echo htmlspecialchars($section['icon']); ?>" aria-hidden="true"></i>
                                <h3 id="section-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $section['title']))); ?>"><?php echo htmlspecialchars($section['title']); ?></h3>
                            </div>
                            <div class="reports-card-grid">
                                <?php foreach ($visibleReports as $report): ?>
                                    <a class="reports-card" href="<?php echo htmlspecialchars($hubUrl($report['path'], $report['params'])); ?>">
                                        <span class="reports-card-icon"><i class="<?php echo htmlspecialchars($report['icon']); ?>" aria-hidden="true"></i></span>
                                        <span class="reports-card-copy">
                                            <strong><?php echo htmlspecialchars($report['title']); ?></strong>
                                            <small><?php echo htmlspecialchars($report['description']); ?></small>
                                        </span>
                                        <i class="fas fa-arrow-right reports-card-arrow" aria-hidden="true"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </section>

                <!-- Alert Messages -->
                <?php if (isset($error_message)): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Report Filters -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-filter me-2"></i>Report Filters
                            </h5>
                            <p class="card-subtitle">Choose date, reporting period and report type to generate</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="date" class="form-label">Report Date</label>
                                    <input type="date" class="form-control" id="date" name="date" 
                                           value="<?php echo htmlspecialchars($report_date); ?>" required>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="type" class="form-label">Report Type</label>
                                    <select class="form-select" id="type" name="type">
                                                <option value="all" <?php echo $report_type === 'all' ? 'selected' : ''; ?>>All Reports</option>
                                        <option value="financial" <?php echo $report_type === 'financial' ? 'selected' : ''; ?>>Discounts & Expenses</option>
                                        <option value="inventory" <?php echo $report_type === 'inventory' ? 'selected' : ''; ?>>Inventory Only</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="period" class="form-label">Reporting Period</label>
                                    <select class="form-select" id="period" name="period">
                                        <option value="daily" <?php echo $report_period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo $report_period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="monthly" <?php echo $report_period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-sync me-2"></i>Generate Report
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Report Period Display -->
                <div class="content-card mb-4">
                    <div class="card-body text-center">
                        <h4 class="text-muted mb-0">Report for <?php echo htmlspecialchars($report_caption); ?></h4>
                    </div>
                </div>




                <!-- Discount & Expenses Breakdown -->
                <?php if ($report_type === 'financial' || $report_type === 'all'): ?>
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-chart-pie me-2"></i>Discount & Expenses Breakdown
                                </h5>
                                <p class="card-subtitle">Detailed breakdown of discounts and expenses for <?php echo htmlspecialchars($report_caption); ?></p>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Summary Stats -->
                            <div class="stats-grid mb-4">
                                <div class="stat-card discount-section">
                                    <div class="stat-header">
                                        <small class="text-muted text-uppercase fw-bold">Revenue Adjustments</small>
                                    </div>
                                    <div class="stat-icon orange">
                                        <i class="fas fa-percentage"></i>
                                    </div>
                                    <?php 
                                    $total_discount_period = array_sum(array_column($discount_breakdown, 'total_discount'));
                                    ?>
                                    <div class="stat-value">₦<?php echo number_format($total_discount_period, 0); ?></div>
                                    <div class="stat-label">Total Discounts</div>
                                    <div class="stat-change <?php echo $total_discount_period > 0 ? 'negative' : 'positive'; ?>">
                                        <i class="fas fa-<?php echo $total_discount_period > 0 ? 'arrow-down' : 'check'; ?>"></i> 
                                        <?php echo ucfirst($report_period); ?> total
                                    </div>
                                </div>
                                
                                <div class="stat-card expense-section">
                                    <div class="stat-header">
                                        <small class="text-muted text-uppercase fw-bold">Operating Expenses</small>
                                    </div>
                                    <div class="stat-icon red">
                                        <i class="fas fa-receipt"></i>
                                    </div>
                                    <?php 
                                    $total_expenses_period = array_sum(array_column($expense_breakdown, 'total_expenses'));
                                    ?>
                                    <div class="stat-value">₦<?php echo number_format($total_expenses_period, 0); ?></div>
                                    <div class="stat-label">Total Expenses</div>
                                    <div class="stat-change <?php echo $total_expenses_period > 0 ? 'negative' : 'positive'; ?>">
                                        <i class="fas fa-<?php echo $total_expenses_period > 0 ? 'arrow-down' : 'check'; ?>"></i> 
                                        <?php echo ucfirst($report_period); ?> total
                                    </div>
                                </div>
                                
                                <div class="stat-card deduction-section">
                                    <div class="stat-header">
                                        <small class="text-muted text-uppercase fw-bold">Total Deductions</small>
                                    </div>
                                    <div class="stat-icon purple">
                                        <i class="fas fa-calculator"></i>
                                    </div>
                                    <div class="stat-value">₦<?php echo number_format($total_discount_period + $total_expenses_period, 0); ?></div>
                                    <div class="stat-label">Net Deductions</div>
                                    <div class="stat-change <?php echo ($total_discount_period + $total_expenses_period) > 0 ? 'negative' : 'positive'; ?>">
                                        <i class="fas fa-<?php echo ($total_discount_period + $total_expenses_period) > 0 ? 'minus' : 'check'; ?>"></i> 
                                        Discounts + Expenses
                                    </div>
                                </div>
                            </div>

                            <!-- Breakdown Tables -->
                            <div class="row">
                                <!-- Discount Breakdown Table -->
                                <div class="col-md-6">
                                    <div class="table-section">
                                        <h5><i class="fas fa-percentage me-2"></i>Discount Breakdown by Trip</h5>
                                        <?php if (!empty($discount_breakdown)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Trip #</th>
                                                            <th>Dispatcher</th>
                                                            <th>Date</th>
                                                            <th>Discount Amount</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($discount_breakdown as $discount): ?>
                                                            <tr>
                                                                <td>
                                                                    <?php if ($discount['discount_source'] === 'trip'): ?>
                                                                        <a href="../dispatch/dispatch_detail.php?id=<?php echo $discount['dispatch_id']; ?>" class="text-decoration-none">
                                                                            <?php echo htmlspecialchars($discount['dispatch_number']); ?>
                                                                        </a>
                                                                    <?php else: ?>
                                                                        <?php echo htmlspecialchars($discount['dispatch_number']); ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($discount['dispatcher_name']); ?></td>
                                                                <td><?php echo date('M j, Y', strtotime($discount['dispatch_date'])); ?></td>
                                                                <td>₦<?php echo number_format($discount['total_discount'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        <tr class="table-primary fw-bold">
                                                            <td colspan="3">Total</td>
                                                            <td>₦<?php echo number_format($total_discount_period, 2); ?></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted">No discount records found for this period.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Expenses Breakdown Table -->
                                <div class="col-md-6">
                                    <div class="table-section">
                                        <h5><i class="fas fa-receipt me-2"></i>Expenses Breakdown by Trip</h5>
                                        <?php if (!empty($expense_breakdown)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Trip #</th>
                                                            <th>Dispatcher</th>
                                                            <th>Date</th>
                                                            <th>Expense Amount</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($expense_breakdown as $expense): ?>
                                                            <tr>
                                                                <td>
                                                                    <?php if ($expense['expense_source'] === 'trip' && $expense['dispatch_id'] > 0): ?>
                                                                        <a href="../dispatch/dispatch_detail.php?id=<?php echo $expense['dispatch_id']; ?>" class="text-decoration-none">
                                                                            <?php echo htmlspecialchars($expense['dispatch_number']); ?>
                                                                        </a>
                                                                    <?php else: ?>
                                                                        <?php echo htmlspecialchars($expense['dispatch_number']); ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($expense['dispatcher_name']); ?></td>
                                                                <td><?php echo date('M j, Y', strtotime($expense['dispatch_date'])); ?></td>
                                                                <td>₦<?php echo number_format($expense['total_expenses'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        <tr class="table-primary fw-bold">
                                                            <td colspan="3">Total</td>
                                                            <td>₦<?php echo number_format($total_expenses_period, 2); ?></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted">No expense records found for this period.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Inventory Report -->
                <?php if ($report_type === 'inventory' || $report_type === 'all'): ?>
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-boxes me-2"></i>Inventory Status
                                </h5>
                                <p class="card-subtitle">Current inventory levels and stock status</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Inventory Stats -->
                            <div class="stats-grid mb-4">
                                <div class="stat-card inventory-section">
                                    <div class="stat-header">
                                        <small class="text-muted text-uppercase fw-bold">Inventory Overview</small>
                                    </div>
                                    <div class="stat-icon blue">
                                        <i class="fas fa-box"></i>
                                    </div>
                                    <div class="stat-value"><?php echo number_format($inventory_data['total_products'] ?? 0); ?></div>
                                    <div class="stat-label">Total Products</div>
                                    <div class="stat-change">
                                        <i class="fas fa-list"></i> All products in catalog
                                    </div>
                                </div>
                                
                                <div class="stat-card inventory-section">
                                    <div class="stat-icon green">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="stat-value"><?php echo number_format($inventory_data['in_stock'] ?? 0); ?></div>
                                    <div class="stat-label">In Stock</div>
                                    <div class="stat-change positive">
                                        <i class="fas fa-check"></i> Available for sale
                                    </div>
                                </div>
                                
                                <div class="stat-card inventory-section">
                                    <div class="stat-icon yellow">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div class="stat-value"><?php echo number_format($inventory_data['low_stock'] ?? 0); ?></div>
                                    <div class="stat-label">Low Stock</div>
                                    <div class="stat-change <?php echo ($inventory_data['low_stock'] ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                                        <i class="fas fa-<?php echo ($inventory_data['low_stock'] ?? 0) > 0 ? 'exclamation-triangle' : 'check'; ?>"></i> 
                                        <?php echo ($inventory_data['low_stock'] ?? 0) > 0 ? 'Needs restocking' : 'Stock levels good'; ?>
                                    </div>
                                </div>
                                
                                <div class="stat-card inventory-section">
                                    <div class="stat-icon red">
                                        <i class="fas fa-times-circle"></i>
                                    </div>
                                    <div class="stat-value"><?php echo number_format($inventory_data['out_of_stock'] ?? 0); ?></div>
                                    <div class="stat-label">Out of Stock</div>
                                    <div class="stat-change <?php echo ($inventory_data['out_of_stock'] ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                                        <i class="fas fa-<?php echo ($inventory_data['out_of_stock'] ?? 0) > 0 ? 'times' : 'check'; ?>"></i> 
                                        <?php echo ($inventory_data['out_of_stock'] ?? 0) > 0 ? 'Out of stock' : 'No stockouts'; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Inventory Value -->
                            <div class="inventory-value-section">
                                <h6 class="text-muted mb-3">Total Inventory Value</h6>
                                <div class="value-display">
                                    <span class="currency-symbol">₦</span>
                                    <span class="value-amount"><?php echo number_format($inventory_data['total_value'] ?? 0, 2); ?></span>
                                </div>
                                <p class="text-muted small">Current market value of all inventory</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Low Stock Alert -->
                <?php if (($report_type === 'inventory' || $report_type === 'all') && !empty($low_stock_products)): ?>
                    <div class="content-card mb-4">
                        <div class="card-header">
                            <div>
                                <h5 class="card-title text-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Low Stock Products
                                </h5>
                                <p class="card-subtitle">Products that need immediate restocking</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($low_stock_products as $product): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                                <td><?php echo $product['quantity']; ?> <?php echo htmlspecialchars($product['unit']); ?></td>
                                                <td><?php echo $product['reorder_level']; ?> <?php echo htmlspecialchars($product['unit']); ?></td>
                                                <td>
                                                    <?php if ($product['quantity'] == 0): ?>
                                                        <span class="badge bg-danger">Out of Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Low Stock</span>
                                                    <?php endif; ?>
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
    <script src="../assets/js/offline-handler.js"></script>
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
        document.getElementById('reportsSearch')?.addEventListener('input', function(e) {
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


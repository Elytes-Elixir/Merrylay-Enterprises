<?php
/**
 * Daily Account Summary - Printable A4 Version
 * 
 * Print-optimized version for A4 paper
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';

$record_date = $_GET['date'] ?? date('Y-m-d');
$selected_driver = intval($_GET['driver_id'] ?? 0);

// Get all drivers
$drivers = [];
try {
    $stmt = executeQuery("SELECT id, name FROM drivers WHERE status = 'active' ORDER BY name");
    $drivers = $stmt->fetchAll();
} catch (PDOException $e) {
    $drivers = [];
}

// Get total outstanding credits (from creditors table)
$total_outstanding_credits = 0;
try {
    $stmt = executeQuery("SELECT COALESCE(SUM(balance), 0) as total FROM creditors WHERE status = 'active'");
    $total_outstanding_credits = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $total_outstanding_credits = 0;
}

// Get shop sales data for unified business view with payment method breakdown
$shop_sales_total = 0;
$shop_sales_units = 0;
$shop_sales_cash = 0;
$shop_sales_card = 0;
$shop_sales_transfer = 0;
$shop_sales_collections = 0;
try {
    // From shop_sales table with payment method breakdown (cash, card, transfer only)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_amount,
            COALESCE(SUM(total_amount), 0) as total_amount
        FROM shop_sales
        WHERE DATE(sale_date) = ?
    ", [$record_date]);
    $shop_sales_new = $stmt->fetch();

    // From dispatches table (shop channel) - no payment_method column, treat as cash
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(actual_sales_amount), 0) as total_amount
        FROM dispatches
        WHERE DATE(dispatch_date) = ? AND sales_channel IN ('shop', 'shop_sales')
    ", [$record_date]);
    $shop_sales_old = $stmt->fetch();

    // Get shop sales units from shop_sales_items
    $stmt = executeQuery("
        SELECT COALESCE(SUM(ssi.quantity), 0) as total_units
        FROM shop_sales_items ssi
        JOIN shop_sales ss ON ssi.shop_sale_id = ss.id
        WHERE DATE(ss.sale_date) = ?
    ", [$record_date]);
    $shop_sales_units_new = $stmt->fetch();

    // Get shop sales units from dispatch_items (shop channel)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.quantity), 0) as total_units
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) = ? AND d.sales_channel IN ('shop', 'shop_sales')
    ", [$record_date]);
    $shop_sales_units_old = $stmt->fetch();

    // Get shop collections from shop_collections table (separate from sales)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN balance_due ELSE 0 END), 0) as unsettled_collections,
            COALESCE(SUM(CASE WHEN status = 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as settled_collections
        FROM shop_collections
        WHERE DATE(collection_date) = ?
    ", [$record_date]);
    $shop_collections_data = $stmt->fetch();

    // Calculate totals with payment method breakdown
    $shop_sales_cash = ($shop_sales_new['cash_amount'] ?? 0) + ($shop_sales_old['total_amount'] ?? 0);
    $shop_sales_card = ($shop_sales_new['card_amount'] ?? 0);
    $shop_sales_transfer = ($shop_sales_new['transfer_amount'] ?? 0);
    $shop_sales_collections = ($shop_collections_data['unsettled_collections'] ?? 0);
    $shop_sales_settled_collections = ($shop_collections_data['settled_collections'] ?? 0);
    $shop_sales_total = ($shop_sales_new['total_amount'] ?? 0) + ($shop_sales_old['total_amount'] ?? 0);
    $shop_sales_units = ($shop_sales_units_new['total_units'] ?? 0) + ($shop_sales_units_old['total_units'] ?? 0);

    // Debug logging
    error_log("Account Summary Print Shop Sales - Total: $shop_sales_total, Cash: $shop_sales_cash, Card: $shop_sales_card, Transfer: $shop_sales_transfer, Collections: $shop_sales_collections, Units: $shop_sales_units");
} catch (PDOException $e) {
    error_log("Account summary print shop sales error: " . $e->getMessage());
    // Use defaults
}

// Get driver summaries with discounts and damaged products
$driver_summaries = [];
try {
    $driver_filter = $selected_driver ? "AND da.driver_id = ?" : "";
    $params = [$record_date];
    if ($selected_driver) {
        $params[] = $selected_driver;
    }

    $stmt = executeQuery("
        SELECT 
            da.driver_id,
            da.driver_name,
            (
                SELECT COUNT(DISTINCT dd.id)
                FROM dispatches dd
                WHERE dd.dispatcher_name = da.driver_name
                  AND DATE(dd.dispatch_date) = DATE(da.activity_date)
                  AND (dd.sales_channel IS NULL OR dd.sales_channel NOT IN ('shop', 'shop_sales'))
            ) as total_trips,
            COALESCE((
                SELECT SUM(dd.expected_amount)
                FROM dispatches dd
                WHERE dd.dispatcher_name = da.driver_name
                  AND DATE(dd.dispatch_date) = DATE(da.activity_date)
                  AND (dd.sales_channel IS NULL OR dd.sales_channel NOT IN ('shop', 'shop_sales'))
            ), 0) as total_dispatch_value,
            COALESCE((
                SELECT SUM(dac.cash_collected)
                FROM daily_activities dac
                WHERE dac.driver_name = da.driver_name
                  AND DATE(dac.activity_date) = DATE(da.activity_date)
            ), 0) as cash_collected,
            COALESCE((
                SELECT SUM(dat.transfer_amount)
                FROM daily_activities dat
                WHERE dat.driver_name = da.driver_name
                  AND DATE(dat.activity_date) = DATE(da.activity_date)
            ), 0) as transfer_amount,
            COALESCE((
                SELECT SUM(dac2.credit_amount)
                FROM daily_activities dac2
                WHERE dac2.driver_name = da.driver_name
                  AND DATE(dac2.activity_date) = DATE(da.activity_date)
            ), 0) as credit_amount,
            COALESCE((
                SELECT SUM(dae.expenses_amount)
                FROM daily_activities dae
                WHERE dae.driver_name = da.driver_name
                  AND DATE(dae.activity_date) = DATE(da.activity_date)
            ), 0) as expenses_amount,
            COALESCE(
                (SELECT COALESCE(SUM(di.discount_amount), 0) 
                 FROM dispatch_items di 
                 JOIN dispatches d2 ON di.dispatch_id = d2.id 
                 WHERE d2.dispatcher_name = da.driver_name 
                   AND DATE(d2.dispatch_date) = DATE(da.activity_date)
                   AND (d2.sales_channel IS NULL OR d2.sales_channel NOT IN ('shop', 'shop_sales'))
                ), 0
            ) as discounts,
            COALESCE((
                SELECT SUM(dad.damaged_products)
                FROM daily_activities dad
                WHERE dad.driver_name = da.driver_name
                  AND DATE(dad.activity_date) = DATE(da.activity_date)
                        ), 0) as damaged_products,
                        COALESCE((
                                SELECT SUM(das.credit_settlement)
                                FROM daily_activities das
                                WHERE das.driver_name = da.driver_name
                                    AND DATE(das.activity_date) = DATE(da.activity_date)
                        ), 0) as credit_settlement
        FROM daily_activities da
        WHERE DATE(da.activity_date) = ? $driver_filter
        GROUP BY da.driver_id, da.driver_name
        ORDER BY da.driver_name
    ", $params);
    $driver_summaries = $stmt->fetchAll();
} catch (PDOException $e) {
    $driver_summaries = [];
}

// Get all dispatches with details
$dispatches = [];
try {
    $dispatch_params = [$record_date];
    $dispatch_driver_filter = '';
    if ($selected_driver) {
        $dispatch_driver_filter = 'AND dsp.dispatcher_name = (SELECT name FROM drivers WHERE id = ?)';
        $dispatch_params[] = $selected_driver;
    }

    $stmt = executeQuery("
        SELECT 
            dsp.id,
            dsp.dispatch_number,
            dsp.dispatcher_name,
            dsp.dispatch_date,
            dsp.expected_amount,
            dsp.notes,
            GROUP_CONCAT(CONCAT(p.name, ' (', di.quantity, ' @ ₦', di.unit_price, ')') SEPARATOR ', ') as products
        FROM dispatches dsp
        LEFT JOIN dispatch_items di ON dsp.id = di.dispatch_id
        LEFT JOIN products p ON di.product_id = p.id
        WHERE DATE(dsp.dispatch_date) = ? $dispatch_driver_filter
          AND (dsp.sales_channel IS NULL OR dsp.sales_channel NOT IN ('shop', 'shop_sales'))
        GROUP BY dsp.id, dsp.dispatch_number, dsp.dispatcher_name, dsp.dispatch_date, dsp.expected_amount, dsp.notes
        ORDER BY dsp.dispatcher_name, dsp.created_at
    ", $dispatch_params);
    $dispatches = $stmt->fetchAll();
} catch (PDOException $e) {
    $dispatches = [];
}

// Get returns by driver with product prices for monetary value
$returns = [];
try {
    $returns_params = [$record_date];
    $returns_driver_filter = '';
    if ($selected_driver) {
        $returns_driver_filter = 'AND dr.driver_id = ?';
        $returns_params[] = $selected_driver;
    }
    
    $stmt = executeQuery("
        SELECT 
            dr.driver_id,
            dr.driver_name,
            dr.product_id,
            p.name as product_name,
            p.cost_price,
            SUM(dr.quantity) as total_quantity,
            SUM(dr.quantity * p.cost_price) as total_value
        FROM daily_returns dr
        JOIN products p ON dr.product_id = p.id
        WHERE dr.return_date = ? $returns_driver_filter
        GROUP BY dr.driver_id, dr.driver_name, dr.product_id, p.name, p.cost_price
        ORDER BY dr.driver_name
    ", $returns_params);
    $returns = $stmt->fetchAll();
} catch (PDOException $e) {
    $returns = [];
}

// Organize returns by driver with monetary value
$returns_by_driver = [];
foreach ($returns as $r) {
    if (!isset($returns_by_driver[$r['driver_id']])) {
        $returns_by_driver[$r['driver_id']] = [
            'driver_name' => $r['driver_name'],
            'total_returns' => 0,
            'total_return_value' => 0,
            'products' => []
        ];
    }
    $returns_by_driver[$r['driver_id']]['total_returns'] += $r['total_quantity'];
    $returns_by_driver[$r['driver_id']]['total_return_value'] += $r['total_value'] ?? 0;
    $returns_by_driver[$r['driver_id']]['products'][] = [
        'product_id' => $r['product_id'],
        'product_name' => $r['product_name'] ?? 'Unknown',
        'quantity' => $r['total_quantity'],
        'unit_price' => $r['cost_price'] ?? 0,
        'total_value' => $r['total_value'] ?? 0
    ];
}

// Calculate grand totals
$grand_totals = [
    'total_dispatch_value' => 0,
    'total_cash' => 0,
    'total_card' => 0,
    'total_transfer' => 0,
    'total_credit' => 0,
    'total_expenses' => 0,
    'total_discounts' => 0,
    'total_damaged' => 0,
    'total_settlement' => 0,
    'total_collection' => 0,
    'total_collected_with_returns' => 0,
    'total_returns' => array_sum(array_column($returns_by_driver, 'total_returns')),
    'total_return_value' => array_sum(array_column($returns_by_driver, 'total_return_value')) ?? 0,
    'shop_sales_total' => $shop_sales_total,
    'shop_sales_cash' => $shop_sales_cash,
    'shop_sales_card' => $shop_sales_card,
    'shop_sales_transfer' => $shop_sales_transfer,
    'shop_sales_units' => $shop_sales_units
];

foreach ($driver_summaries as $ds) {
    $grand_totals['total_dispatch_value'] += $ds['total_dispatch_value'];
    $grand_totals['total_cash'] += $ds['cash_collected'];
    $grand_totals['total_transfer'] += $ds['transfer_amount'];
    $grand_totals['total_credit'] += $ds['credit_amount'];
    $grand_totals['total_expenses'] += $ds['expenses_amount'];
    $grand_totals['total_discounts'] += $ds['discounts'];
    $grand_totals['total_damaged'] += $ds['damaged_products'];
    $grand_totals['total_settlement'] += $ds['credit_settlement'];
}

// Add shop sales to dispatch value (shop sales are part of total stock issued)
$grand_totals['total_dispatch_value'] += $grand_totals['shop_sales_total'];

// Add shop sales payment methods to respective totals
// Use gross cash (no expense adjustments) - expenses handled separately in expenses section
$grand_totals['total_cash'] += $grand_totals['shop_sales_cash'];
$grand_totals['total_card'] += $grand_totals['shop_sales_card'];
$grand_totals['total_transfer'] += $grand_totals['shop_sales_transfer'];

$grand_totals['total_collection'] = $grand_totals['total_cash'] + $grand_totals['total_card'] + $grand_totals['total_transfer'];
$grand_totals['total_collected_with_returns'] = $grand_totals['total_collection'] + $grand_totals['total_return_value'];
// Sales reconciliation includes credit settlements but excludes expenses.
$grand_totals['total_collected_complete'] = $grand_totals['total_collection'] + $grand_totals['total_return_value'] + $grand_totals['total_credit'] + $grand_totals['total_damaged'] + $grand_totals['total_settlement'];
$grand_totals['net_revenue'] = $grand_totals['total_collection'] - $grand_totals['total_expenses'];

// Balance compares accounted-for sales value with dispatch value; expenses are separate.
$net_dispatch_value = $grand_totals['total_dispatch_value'];
// Reconciliation excludes expenses and uses shop payments by their actual method.
$total_accounted = $grand_totals['total_collection'] + $grand_totals['total_credit'] + $grand_totals['total_damaged'] + $grand_totals['total_return_value'] + $grand_totals['total_settlement'];
$balance_variance = $total_accounted - $net_dispatch_value;
$is_balanced = abs($balance_variance) < 0.01;
$balance_label = $is_balanced ? 'Balanced' : ($balance_variance > 0 ? 'Surplus' : 'Shortage');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Account Summary - Print</title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Inter, Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.35;
            color: #111827;
            background: #f3f4f6;
        }

        .page {
            width: 100%;
            max-width: 190mm;
            min-height: auto;
            padding: 12mm;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08);
        }

        .header {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .header-left {
            flex: 1 1 55%;
            min-width: 220px;
        }

        .header h1 {
            font-size: 18pt;
            font-weight: 700;
            margin-bottom: 6px;
            color: #111827;
        }

        .header p {
            font-size: 9pt;
            color: #4b5563;
            margin-bottom: 3px;
        }

        .header-right {
            flex: 1 1 35%;
            min-width: 180px;
            text-align: right;
            font-size: 8.7pt;
            color: #475569;
        }

        .report-info {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 14px;
            font-size: 8.8pt;
            color: #4b5563;
        }

        .report-info div {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            padding: 9px 10px;
            border-radius: 10px;
        }

        .section {
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 10pt;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: #111827;
            margin-bottom: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 8.4pt;
        }

        th, td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: middle;
        }

        th {
            background: #f8fafc;
            color: #374151;
            font-weight: 700;
            text-align: left;
        }

        tbody tr:nth-child(even) {
            background: #fcfcfd;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .summary-item {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px 10px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.04);
        }

        .summary-item .label {
            display: block;
            font-size: 8pt;
            color: #6b7280;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .summary-item .value {
            font-size: 12pt;
            font-weight: 700;
            color: #111827;
        }

        .balance-status {
            text-align: center;
            padding: 10px 12px;
            margin: 18px 0;
            border-radius: 12px;
            font-weight: 700;
            font-size: 9.5pt;
            border: 1px solid transparent;
        }

        .driver-section {
            margin-bottom: 18px;
            border-radius: 14px;
            padding: 14px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 16px rgba(15, 23, 42, 0.05);
            page-break-inside: avoid;
        }

        .driver-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 10.5pt;
            color: #111827;
            margin-bottom: 12px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .driver-header span {
            font-size: 8.8pt;
            color: #6b7280;
        }

        .driver-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .driver-summary-card {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px 9px;
            text-align: center;
        }

        .driver-summary-card .label {
            font-size: 8pt;
            color: #6b7280;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .driver-summary-card .value {
            font-size: 11pt;
            font-weight: 700;
            color: #111827;
        }

        .grand-total {
            background: #111827;
            color: #f9fafb;
            padding: 16px;
            margin-top: 18px;
            border-radius: 16px;
            page-break-inside: avoid;
        }

        .grand-total-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .grand-total-header h2 {
            font-size: 12pt;
            font-weight: 700;
            margin: 0;
            color: #f8fafc;
        }

        .grand-total-subtitle {
            font-size: 8.8pt;
            color: #cbd5e1;
        }

        .grand-total-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .grand-total-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 14px;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .grand-total-card .label {
            display: block;
            font-size: 8pt;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
        }

        .grand-total-card .value {
            font-size: 12pt;
            font-weight: 700;
            color: #f8fafc;
            text-align: right;
            white-space: nowrap;
        }

        .grand-total-card.highlight {
            background: #0f172a;
            border-color: rgba(255,255,255,0.18);
        }

        .print-footer {
            text-align: center;
            font-size: 8.8pt;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
        }

        @media print {
            html, body {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 0;
                background: #ffffff;
                font-size: 8pt;
                line-height: 1.2;
            }
            .page {
                width: 100%;
                max-width: 100%;
                margin: 0;
                padding: 8mm;
                box-shadow: none;
                border-radius: 0;
                page-break-after: auto;
            }
            .header {
                gap: 8px;
                padding-bottom: 10px;
                margin-bottom: 10px;
            }
            .header h1 {
                font-size: 14pt;
            }
            .header p,
            .header-right,
            .report-info,
            .grand-total-subtitle {
                font-size: 7.5pt;
            }
            .report-info {
                grid-template-columns: 1fr;
                gap: 6px;
                margin-bottom: 10px;
            }
            .report-info div {
                padding: 6px 8px;
                border-radius: 8px;
            }
            .section {
                margin-bottom: 10px;
            }
            .section-title {
                font-size: 9pt;
                margin-bottom: 6px;
            }
            .summary-grid,
            .driver-summary-grid,
            .grand-total-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            .summary-item,
            .driver-summary-card,
            .grand-total-card {
                padding: 10px 10px;
            }
            .summary-item .label,
            .driver-summary-card .label,
            .grand-total-card .label {
                font-size: 7.2pt;
                margin-bottom: 4px;
            }
            .summary-item .value,
            .driver-summary-card .value,
            .grand-total-card .value {
                font-size: 10pt;
            }
            .driver-section {
                margin-bottom: 10px;
                padding: 10px;
            }
            .driver-header {
                font-size: 9.5pt;
                margin-bottom: 10px;
            }
            .driver-header span {
                font-size: 7.8pt;
            }
            table {
                font-size: 7.5pt;
                margin-bottom: 6px;
            }
            th, td {
                padding: 4px 6px;
            }
            .grand-total {
                padding: 14px;
                margin-top: 12px;
            }
            .grand-total-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
                margin-bottom: 10px;
            }
            .grand-total-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            .grand-total-card {
                padding: 10px 12px;
            }
            .print-footer {
                font-size: 7.5pt;
                margin-top: 14px;
                padding-top: 8px;
            }
            .summary-item,
            .driver-section,
            .report-info div,
            .grand-total-card {
                box-shadow: none;
            }
            tr, thead, tbody {
                page-break-inside: avoid;
            }
            thead {
                display: table-header-group;
            }
            tfoot {
                display: table-footer-group;
            }
        }

        :root {
            --ink: #183238;
            --muted: #587078;
            --line: #d7e2e3;
            --wash: #f2f7f6;
            --accent: #176b61;
            --accent-dark: #104c46;
            --warning: #9a5a12;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            color: var(--ink);
            background: #e8efee;
        }

        .page {
            max-width: 1120px;
            padding: 34px;
            margin: 24px auto;
            border: 1px solid var(--line);
            border-radius: 4px;
            box-shadow: 0 12px 36px rgba(24, 50, 56, 0.1);
        }

        .screen-only {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 18px;
        }

        .print-button {
            border: 0;
            border-radius: 3px;
            padding: 10px 16px;
            color: #fff;
            background: var(--accent-dark);
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .header {
            align-items: flex-end;
            border-bottom: 3px solid var(--accent);
            padding-bottom: 16px;
        }

        .header h1 {
            margin-bottom: 2px;
            color: var(--accent-dark);
            font-size: 19pt;
        }

        .header .report-title {
            margin-bottom: 6px;
            color: var(--ink);
            font-size: 11pt;
            font-weight: 700;
        }

        .header-right {
            color: var(--ink);
        }

        .report-info div {
            border-radius: 3px;
            background: var(--wash);
        }

        .section {
            margin-bottom: 22px;
        }

        .section-title {
            padding-bottom: 7px;
            border-bottom: 1px solid var(--line);
            color: var(--accent-dark);
            letter-spacing: 0;
            font-size: 11pt;
        }

        .summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }

        .summary-item {
            border-radius: 3px;
            border-top: 2px solid var(--accent);
            padding: 10px 12px;
            box-shadow: none;
            break-inside: avoid;
        }

        .summary-item .label {
            color: var(--muted);
            letter-spacing: 0;
        }

        .summary-item .value {
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        table {
            font-size: 8.8pt;
            font-variant-numeric: tabular-nums;
        }

        th {
            background: #dcebe8;
            color: var(--accent-dark);
        }

        tbody tr:nth-child(even) {
            background: #f4f8f7;
        }

        .text-right {
            text-align: right;
            white-space: nowrap;
        }

        .driver-section {
            border-radius: 3px;
            border-left: 3px solid var(--accent);
            padding: 14px 16px;
            box-shadow: none;
            break-inside: auto;
        }

        .driver-header {
            color: var(--accent-dark);
            break-after: avoid;
        }

        .balance-status {
            border-color: var(--line);
            border-radius: 3px;
            color: var(--accent-dark);
            background: var(--wash);
            break-inside: avoid;
        }

        .balance-status.unbalanced {
            border-color: #e8cba7;
            color: var(--warning);
            background: #fff7eb;
        }

        .grand-total {
            border-radius: 3px;
            color: var(--ink);
            background: var(--wash);
            border: 1px solid var(--line);
            break-inside: avoid;
        }

        .grand-total-header h2,
        .grand-total-card .value {
            color: var(--ink);
        }

        .grand-total-subtitle,
        .grand-total-card .label {
            color: var(--muted);
        }

        .grand-total-card,
        .grand-total-card.highlight {
            border-radius: 3px;
            border-color: var(--line);
            background: #fff;
            break-inside: avoid;
        }

        .expense-total,
        .reconciliation-total {
            font-weight: 700;
            background: var(--wash) !important;
        }

        .variance-value {
            color: var(--accent-dark);
            font-weight: 700;
        }

        .print-footer {
            color: var(--muted);
        }

        @media screen and (max-width: 700px) {
            .page {
                margin: 0;
                padding: 20px 16px;
                border: 0;
            }

            .header {
                align-items: flex-start;
            }

            .header-right {
                text-align: left;
            }

            .report-info,
            .summary-grid,
            .driver-summary-grid,
            .grand-total-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .driver-section {
                padding: 12px 10px;
            }

            table {
                display: block;
                overflow-x: auto;
            }
        }

        @media screen and (max-width: 420px) {
            .summary-grid,
            .driver-summary-grid,
            .grand-total-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }

            html, body {
                width: auto;
                height: auto;
                color: #183238;
                background: #fff;
                font-size: 8.5pt;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .screen-only {
                display: none !important;
            }

            nav,
            button,
            .navigation,
            .no-print {
                display: none !important;
            }

            .page {
                width: auto;
                max-width: none;
                min-height: 0;
                margin: 0;
                padding: 0;
                border: 0;
                box-shadow: none;
            }

            .header {
                page-break-after: avoid;
                break-after: avoid-page;
            }

            .section-title {
                page-break-after: avoid;
                break-after: avoid-page;
            }

            .summary-grid,
            .driver-summary-grid,
            .grand-total-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 5px;
            }

            .summary-item,
            .report-info div,
            .grand-total-card,
            .balance-status,
            .driver-header {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            table {
                display: table;
                width: 100%;
                margin-bottom: 9px;
                font-size: 7.7pt;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .grand-total {
                color: var(--ink);
                background: var(--wash) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="screen-only">
            <button type="button" class="print-button" onclick="window.print()">Print report</button>
        </div>
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Merrylay Enterprises</h1>
                <p class="report-title">Daily Account Summary</p>
                <p><?php echo date('F j, Y', strtotime($record_date)); ?></p>
            </div>
            <div class="header-right">
                <?php if ($selected_driver): ?>
                    <p><strong>Dispatcher:</strong> <?php echo htmlspecialchars($drivers[array_search($selected_driver, array_column($drivers, 'id'))]['name'] ?? 'Selected dispatcher'); ?></p>
                <?php else: ?>
                    <p><strong>Dispatcher:</strong> All dispatchers</p>
                <?php endif; ?>
                <p><strong>Date filter:</strong> <?php echo htmlspecialchars($record_date); ?></p>
            </div>
        </div>

        <!-- Report Info -->
        <div class="report-info">
            <div>Generated: <?php echo date('F j, Y g:i A'); ?></div>
            <div>Report ID: <?php echo strtoupper(uniqid('RPT-')); ?></div>
        </div>

        <!-- Quick Stats -->
        <div class="section">
            <div class="section-title">DAILY SUMMARY</div>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="label">Total Sales</div>
                    <div class="value">₦<?php echo number_format($grand_totals['total_dispatch_value'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Cash</div>
                    <div class="value">₦<?php echo number_format($grand_totals['total_cash'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Transfer</div>
                    <div class="value">₦<?php echo number_format($grand_totals['total_transfer'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Card</div>
                    <div class="value">₦<?php echo number_format($grand_totals['total_card'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Credit Sales</div>
                    <div class="value">₦<?php echo number_format($grand_totals['total_credit'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Expenses</div>
                    <div class="value">₦<?php echo number_format($grand_totals['total_expenses'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Net Cash After Expenses</div>
                    <div class="value">₦<?php echo number_format($grand_totals['net_revenue'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Balance / Payment Variance</div>
                    <div class="value"><?php echo $balance_label; ?> ₦<?php echo number_format(abs($balance_variance), 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Total Dispatches</div>
                    <div class="value"><?php echo count($dispatches); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Return Value</div>
                    <div class="value">₦<?php echo number_format($grand_totals['total_return_value'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Shop Sales</div>
                    <div class="value">₦<?php echo number_format($grand_totals['shop_sales_total'], 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Shop Units</div>
                    <div class="value"><?php echo $grand_totals['shop_sales_units']; ?></div>
                </div>
            </div>
        </div>

        <!-- Balance Status -->
        <div class="balance-status <?php echo $is_balanced ? 'balanced' : 'unbalanced'; ?>">
            ACCOUNT STATUS: <?php echo $is_balanced ? 'BALANCED' : 'UNBALANCED'; ?>
            <br>
            <span style="font-size: 10pt; font-weight: normal;">
                Variance: ₦<?php echo number_format(abs($balance_variance), 2); ?> 
                (<?php echo $balance_label; ?>)
            </span>
        </div>

        <!-- Driver Details -->
        <div class="section">
            <div class="section-title">DISPATCH AND ACTIVITY BREAKDOWN</div>
        </div>
        <?php if (empty($driver_summaries)): ?>
            <div class="section">
                <p style="text-align: center; padding: 20px;">No records found for <?php echo date('F j, Y', strtotime($record_date)); ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($driver_summaries as $ds): 
                $driver_dispatches = array_filter($dispatches, fn($d) => $d['dispatcher_name'] == $ds['driver_name']);
                $driver_returns = $returns_by_driver[$ds['driver_id']] ?? null;
                $driver_return_value = $driver_returns ? $driver_returns['total_return_value'] : 0;
                // Total collected = Cash + Transfer + Returns + Credit + Damaged
                $total_collected = $ds['cash_collected'] + $ds['transfer_amount'] + $driver_return_value + $ds['credit_amount'] + $ds['damaged_products'];
                $net_dispatch_value = $ds['total_dispatch_value'];
                // Variance = Collected - Dispatch (positive = surplus, negative = shortage)
                $variance = $total_collected - $net_dispatch_value;
                $is_driver_balanced = abs($variance) < 0.01;
                $driver_balance_label = $is_driver_balanced ? 'Balanced' : ($variance > 0 ? 'Surplus' : 'Shortage');
            ?>
                <div class="driver-section">
                    <div class="driver-header">
                        <?php echo htmlspecialchars($ds['driver_name']); ?> - 
                        <?php echo $ds['total_trips']; ?> Trip(s) - 
                        <?php echo $is_driver_balanced ? 'BALANCED' : 'UNBALANCED'; ?>
                    </div>
                    
                    <!-- Financial Summary -->
                    <div class="summary-grid" style="margin-bottom: 10px;">
                        <div class="summary-item">
                            <div class="label">Dispatch Value</div>
                            <div class="value">₦<?php echo number_format($ds['total_dispatch_value'], 2); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="label">Collected (Cash + Transfer + Returns)</div>
                            <div class="value">₦<?php echo number_format($total_collected, 2); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="label">Credit Sales</div>
                            <div class="value">₦<?php echo number_format($ds['credit_amount'], 2); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="label">Discounts</div>
                            <div class="value">₦<?php echo number_format($ds['discounts'], 2); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="label">Damaged Products</div>
                            <div class="value">₦<?php echo number_format($ds['damaged_products'], 2); ?></div>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th class="text-right">Cash</th>
                                <th class="text-right">Transfer</th>
                                <th class="text-right">Credit</th>
                                <th class="text-right">Credit Settlement</th>
                                <th class="text-right">Discounts</th>
                                <th class="text-right">Damaged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Daily activity total</td>
                                <td class="text-right">₦<?php echo number_format($ds['cash_collected'], 2); ?></td>
                                <td class="text-right">₦<?php echo number_format($ds['transfer_amount'], 2); ?></td>
                                <td class="text-right">₦<?php echo number_format($ds['credit_amount'], 2); ?></td>
                                <td class="text-right">₦<?php echo number_format($ds['credit_settlement'], 2); ?></td>
                                <td class="text-right">₦<?php echo number_format($ds['discounts'], 2); ?></td>
                                <td class="text-right">₦<?php echo number_format($ds['damaged_products'], 2); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Dispatches Table -->
                    <?php if (!empty($driver_dispatches)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Trip #</th>
                                    <th>Products</th>
                                    <th class="text-right">Expected Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($driver_dispatches as $d): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($d['dispatch_number']); ?></td>
                                        <td><?php echo htmlspecialchars($d['products'] ?? 'No products recorded'); ?></td>
                                        <td class="text-right">₦<?php echo number_format($d['expected_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <!-- Returns -->
                    <?php if ($driver_returns): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-right">Quantity</th>
                                    <th class="text-right">Unit Price</th>
                                    <th class="text-right">Total Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($driver_returns['products'] as $p): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                                        <td class="text-right"><?php echo $p['quantity']; ?></td>
                                        <td class="text-right">₦<?php echo number_format($p['unit_price'], 2); ?></td>
                                        <td class="text-right">₦<?php echo number_format($p['total_value'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight: bold;">
                                    <td colspan="3" class="text-right">Total Returns:</td>
                                    <td class="text-right">₦<?php echo number_format($driver_returns['total_return_value'], 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <!-- Driver Variance -->
                    <div style="margin-top: 10px; padding: 5px; border: 1px solid #000; text-align: center;">
                        <strong>Variance:</strong> ₦<?php echo number_format($variance, 2); ?>
                        <span style="margin-left: 20px;">
                            Cash: ₦<?php echo number_format($ds['cash_collected'], 2); ?> | 
                            Transfer: ₦<?php echo number_format($ds['transfer_amount'], 2); ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Shop Financial Breakdown -->
        <?php if ($shop_sales_total > 0 || $shop_sales_units > 0): ?>
            <div class="section">
                <div class="section-title">SHOP FINANCIAL BREAKDOWN</div>
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="label">Total Shop Sales</div>
                        <div class="value">₦<?php echo number_format($shop_sales_total, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Shop Cash</div>
                        <div class="value">₦<?php echo number_format($shop_sales_cash, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Shop Card</div>
                        <div class="value">₦<?php echo number_format($shop_sales_card, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Shop Transfer</div>
                        <div class="value">₦<?php echo number_format($shop_sales_transfer, 2); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Units Sold</div>
                        <div class="value"><?php echo $shop_sales_units; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Avg Price/Unit</div>
                        <div class="value">₦<?php echo $shop_sales_units > 0 ? number_format($shop_sales_total / $shop_sales_units, 2) : '0.00'; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Status</div>
                        <div class="value">Recorded</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Expenses -->
        <div class="section">
            <div class="section-title">EXPENSES</div>
            <table>
                <thead>
                    <tr>
                        <th>Dispatcher</th>
                        <th class="text-right">Expenses</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($driver_summaries as $ds): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ds['driver_name']); ?></td>
                            <td class="text-right">₦<?php echo number_format($ds['expenses_amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="expense-total">
                        <td>Total expenses</td>
                        <td class="text-right">₦<?php echo number_format($grand_totals['total_expenses'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Account Reconciliation -->
        <div class="section">
            <div class="section-title">VARIANCE AND ACCOUNT RECONCILIATION</div>
            <table>
                <thead>
                    <tr>
                        <th>Reconciliation item</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total sales / dispatch value</td>
                        <td class="text-right">₦<?php echo number_format($net_dispatch_value, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Payments received (cash + card + transfer)</td>
                        <td class="text-right">₦<?php echo number_format($grand_totals['total_collection'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Credit sales</td>
                        <td class="text-right">₦<?php echo number_format($grand_totals['total_credit'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Returns</td>
                        <td class="text-right">₦<?php echo number_format($grand_totals['total_return_value'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Damaged products</td>
                        <td class="text-right">₦<?php echo number_format($grand_totals['total_damaged'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Credit settlements</td>
                        <td class="text-right">₦<?php echo number_format($grand_totals['total_settlement'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Discounts</td>
                        <td class="text-right">₦<?php echo number_format($grand_totals['total_discounts'], 2); ?></td>
                    </tr>
                    <tr class="reconciliation-total">
                        <td>Total accounted for</td>
                        <td class="text-right">₦<?php echo number_format($total_accounted, 2); ?></td>
                    </tr>
                    <tr class="reconciliation-total">
                        <td>Payment-versus-sales variance (expenses excluded)</td>
                        <td class="text-right variance-value"><?php echo $balance_label; ?> ₦<?php echo number_format(abs($balance_variance), 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Grand Total Summary -->
        <div class="grand-total">
            <div class="grand-total-header">
                <div>
                    <h2>Financial Totals</h2>
                    <div class="grand-total-subtitle">Collected amounts and operational totals for the selected date</div>
                </div>
                <div class="grand-total-subtitle">Generated: <?php echo date('F j, Y g:i A'); ?></div>
            </div>
            <div class="grand-total-grid">
                <div class="grand-total-card">
                    <div>
                        <span class="label">Total Dispatch Value (incl. Shop)</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_dispatch_value'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Return Value</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_return_value'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card highlight">
                    <div>
                        <span class="label">Net Dispatch Value</span>
                        <div class="value">₦<?php echo number_format($net_dispatch_value, 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Total Cash Collected (incl. Shop)</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_cash'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Total Transfer</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_transfer'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Total Card</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_card'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Total Collected</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_collected_with_returns'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Credit Sales</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_credit'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Discounts</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_discounts'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card">
                    <div>
                        <span class="label">Damaged Products</span>
                        <div class="value">₦<?php echo number_format($grand_totals['total_damaged'], 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card highlight">
                    <div>
                        <span class="label">Total Accounted For</span>
                        <div class="value">₦<?php echo number_format($total_accounted, 2); ?></div>
                    </div>
                </div>
                <div class="grand-total-card highlight">
                    <div>
                        <span class="label">Balance Variance</span>
                        <div class="value">₦<?php echo number_format(abs($balance_variance), 2); ?> <?php echo $balance_label; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="print-footer">
            <p>Merrylay Enterprises - Daily Account Summary Report</p>
            <p>Generated on <?php echo date('F j, Y g:i A'); ?></p>
        </div>
    </div>
</body>
</html>


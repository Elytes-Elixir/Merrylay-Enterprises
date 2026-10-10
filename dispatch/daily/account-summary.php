<?php
/**
 * Daily Account Summary
 * 
 * Comprehensive daily summary showing all dispatches, sales records,
 * returns, and financial summary for each driver.
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';

// Manager can view but not edit sales data
if (!canViewFinancials()) {
    header('Location: ../../dashboard/index.php');
    exit();
}

$can_edit = canRecordFinancials();

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

// Get driver summaries with actual trip discounts from dispatch_items and damaged products
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
                  AND (dd.sales_channel IS NULL OR dd.sales_channel NOT IN ('shop', 'shop_sales'))
                  AND DATE(dd.dispatch_date) = DATE(da.activity_date)
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
        $dispatch_driver_filter = 'AND dsp.dispatcher_name = (SELECT name COLLATE utf8mb4_general_ci FROM drivers WHERE id = ?)';
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
            COALESCE(SUM(di.quantity), 0) as total_units,
            COALESCE(SUM(di.quantity * di.unit_price), 0) as total_amount,
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

// Get shop sales count for the date (to include in dispatch total)
$shop_sales_count = 0;
try {
    $shop_params = [$record_date];
    $shop_driver_filter = '';
    if ($selected_driver) {
        $shop_driver_filter = 'AND ss.recorded_by = ?';
        $shop_params[] = $selected_driver;
    }
    
    $stmt = executeQuery("
        SELECT COUNT(*) as count
        FROM shop_sales ss
        WHERE DATE(ss.sale_date) = ? $shop_driver_filter
    ", $shop_params);
    $result = $stmt->fetch();
    $shop_sales_count = intval($result['count'] ?? 0);
} catch (PDOException $e) {
    $shop_sales_count = 0;
}

// Total dispatches = dispatches from dispatches table + shop sales count
$total_dispatches = count($dispatches) + $shop_sales_count;

// Calculate dispatcher units sold from dispatch_items
$dispatcher_units_total = 0;
try {
    $dispatch_units_params = [$record_date];
    $dispatch_units_driver_filter = '';
    if ($selected_driver) {
        $dispatch_units_driver_filter = 'AND dsp.dispatcher_name = (SELECT name COLLATE utf8mb4_general_ci FROM drivers WHERE id = ?)';
        $dispatch_units_params[] = $selected_driver;
    }
    
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.quantity), 0) as total_units
        FROM dispatch_items di
        JOIN dispatches dsp ON di.dispatch_id = dsp.id
        WHERE DATE(dsp.dispatch_date) = ? $dispatch_units_driver_filter
          AND (dsp.sales_channel IS NULL OR dsp.sales_channel NOT IN ('shop', 'shop_sales'))
    ", $dispatch_units_params);
    $result = $stmt->fetch();
    $dispatcher_units_total = intval($result['total_units'] ?? 0);
} catch (PDOException $e) {
    $dispatcher_units_total = 0;
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
            dr.dispatch_id,
            dr.driver_id,
            dr.driver_name,
            dr.product_id,
            p.name as product_name,
            p.cost_price as unit_price,
            SUM(dr.quantity) as total_quantity,
            SUM(dr.quantity * p.cost_price) as total_value
        FROM daily_returns dr
        JOIN products p ON dr.product_id = p.id
        WHERE dr.return_date = ? $returns_driver_filter
        GROUP BY dr.dispatch_id, dr.driver_id, dr.driver_name, dr.product_id, p.name
        ORDER BY dr.driver_name
    ", $returns_params);
    $returns = $stmt->fetchAll();
} catch (PDOException $e) {
    $returns = [];
}

// Organize returns by driver with monetary value
$returns_by_driver = [];
// Also collect returns by dispatch so trip rows show actual dispatch-specific return values.
$returns_by_dispatch = [];
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
        'unit_price' => $r['unit_price'] ?? 0,
        'total_value' => $r['total_value'] ?? 0
    ];

    if (!empty($r['dispatch_id'])) {
        if (!isset($returns_by_dispatch[$r['dispatch_id']])) {
            $returns_by_dispatch[$r['dispatch_id']] = [
                'total_returns' => 0,
                'total_return_value' => 0,
                'products' => []
            ];
        }
        $returns_by_dispatch[$r['dispatch_id']]['total_returns'] += $r['total_quantity'];
        $returns_by_dispatch[$r['dispatch_id']]['total_return_value'] += $r['total_value'] ?? 0;
        $returns_by_dispatch[$r['dispatch_id']]['products'][] = [
            'product_id' => $r['product_id'],
            'product_name' => $r['product_name'] ?? 'Unknown',
            'quantity' => $r['total_quantity'],
            'unit_price' => $r['unit_price'] ?? 0,
            'total_value' => $r['total_value'] ?? 0
        ];
    }
}

// Load trip-specific activity totals so dispatch rows show only that dispatch's records.
$dispatch_activities = [];
try {
    $activity_params = [$record_date];
    $activity_filter = '';
    if ($selected_driver) {
        $activity_filter = 'AND da.driver_id = ?';
        $activity_params[] = $selected_driver;
    }
    $stmt = executeQuery(
        "SELECT da.dispatch_id,
                SUM(da.cash_collected) AS cash_collected,
                SUM(da.transfer_amount) AS transfer_amount,
                SUM(da.credit_amount) AS credit_amount,
                SUM(da.expenses_amount) AS expenses_amount,
                SUM(da.damaged_products) AS damaged_products,
                SUM(da.credit_settlement) AS credit_settlement
         FROM daily_activities da
         WHERE DATE(da.activity_date) = ? $activity_filter
         GROUP BY da.dispatch_id",
        $activity_params
    );
    $raw_dispatch_activities = $stmt->fetchAll();
    foreach ($raw_dispatch_activities as $row) {
        $dispatch_id = intval($row['dispatch_id'] ?? 0);
        if ($dispatch_id > 0) {
            $dispatch_activities[$dispatch_id] = [
                'cash_collected' => floatval($row['cash_collected'] ?? 0),
                'transfer_amount' => floatval($row['transfer_amount'] ?? 0),
                'credit_amount' => floatval($row['credit_amount'] ?? 0),
                'expenses_amount' => floatval($row['expenses_amount'] ?? 0),
                'damaged_products' => floatval($row['damaged_products'] ?? 0),
                'credit_settlement' => floatval($row['credit_settlement'] ?? 0)
            ];
        }
    }
} catch (PDOException $e) {
    error_log('Failed to load dispatch-specific activity totals: ' . $e->getMessage());
}

// Load dispatch-specific discounts from dispatch_items
$discounts_by_dispatch = [];
try {
    $dispatch_discount_params = [$record_date];
    $dispatch_discount_filter = '';
    if ($selected_driver) {
        $dispatch_discount_filter = 'AND d.dispatcher_name = (SELECT name COLLATE utf8mb4_general_ci FROM drivers WHERE id = ?)';
        $dispatch_discount_params[] = $selected_driver;
    }
    $stmt = executeQuery(
        "SELECT di.dispatch_id, COALESCE(SUM(di.discount_amount), 0) as total_discounts
         FROM dispatch_items di
         JOIN dispatches d ON di.dispatch_id = d.id
         WHERE DATE(d.dispatch_date) = ?
           AND (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales'))
           $dispatch_discount_filter
         GROUP BY di.dispatch_id",
        $dispatch_discount_params
    );
    $raw_dispatch_discounts = $stmt->fetchAll();
    foreach ($raw_dispatch_discounts as $row) {
        $dispatch_id = intval($row['dispatch_id'] ?? 0);
        if ($dispatch_id > 0) {
            $discounts_by_dispatch[$dispatch_id] = floatval($row['total_discounts'] ?? 0);
        }
    }
} catch (PDOException $e) {
    error_log('Failed to load dispatch-specific discounts: ' . $e->getMessage());
}

// Get actual trip discounts from dispatch_items for the selected date
$trip_discounts_total = 0;
try {
    $discount_params = [$record_date];
    $discount_driver_filter = '';
    if ($selected_driver) {
        $discount_driver_filter = 'AND d.dispatcher_name = (SELECT name COLLATE utf8mb4_general_ci FROM drivers WHERE id = ?)';
        $discount_params[] = $selected_driver;
    }
    
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.discount_amount), 0) as total_trip_discounts
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) = ?
          AND (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales'))
          $discount_driver_filter
    ", $discount_params);
    $result = $stmt->fetch();
    $trip_discounts_total = floatval($result['total_trip_discounts'] ?? 0);
} catch (PDOException $e) {
    $trip_discounts_total = 0;
}

// Get actual credit sales from daily_activities for selected date
$credit_sales_total = 0;
try {
    $credit_params = [$record_date];
    $credit_driver_filter = '';
    if ($selected_driver) {
        $credit_driver_filter = 'AND da.driver_id = ?';
        $credit_params[] = $selected_driver;
    }
    
    $stmt = executeQuery("
                SELECT COALESCE(SUM(da.credit_amount), 0) as total_credit_sales
                FROM daily_activities da
                WHERE da.activity_date = ? $credit_driver_filter
    ", $credit_params);
    $result = $stmt->fetch();
    $credit_sales_total = floatval($result['total_credit_sales'] ?? 0);
} catch (PDOException $e) {
    $credit_sales_total = 0;
}

// Get actual expenses from daily_activities for selected date
$expenses_total = 0;
try {
    $expense_params = [$record_date];
    $expense_driver_filter = '';
    if ($selected_driver) {
        $expense_driver_filter = 'AND da.driver_id = ?';
        $expense_params[] = $selected_driver;
    }

    $stmt = executeQuery("
        SELECT COALESCE(SUM(da.expenses_amount), 0) as total_expenses
        FROM daily_activities da
        WHERE da.activity_date = ? $expense_driver_filter
    ", $expense_params);
    $result = $stmt->fetch();
    $expenses_total = floatval($result['total_expenses'] ?? 0);
} catch (PDOException $e) {
    $expenses_total = 0;
}

// Get shop sales data for unified business view with payment method breakdown
$shop_sales_total = 0;
$shop_sales_units = 0;
$shop_sales_cash = 0;
$shop_sales_gross_cash = 0; // Gross cash sales before discount (for balancing)
$shop_sales_card = 0;
$shop_sales_transfer = 0;
$shop_sales_collections = 0;
$shop_collections_records = []; // Individual expense records
try {
    // From shop_sales table with payment method breakdown (primary source)
    // For single payment method, payment_method is in shop_sales table
    // For split payment, payment amounts are in shop_sale_payments table
    // Credit is NOT a payment method - it's a separate sales category
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'cash' THEN ss.total_amount - ss.discount_amount ELSE 0 END), 0) as cash_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'cash' THEN ss.total_amount ELSE 0 END), 0) as gross_cash_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'card' THEN ss.total_amount - ss.discount_amount ELSE 0 END), 0) as card_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'card' THEN ss.total_amount ELSE 0 END), 0) as gross_card_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'transfer' THEN ss.total_amount - ss.discount_amount ELSE 0 END), 0) as transfer_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'transfer' THEN ss.total_amount ELSE 0 END), 0) as gross_transfer_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'credit' THEN ss.total_amount - ss.discount_amount ELSE 0 END), 0) as credit_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'credit' THEN ss.total_amount ELSE 0 END), 0) as gross_credit_amount,
            COALESCE(SUM(ss.total_amount), 0) as total_amount,
            COALESCE(SUM(ss.discount_amount), 0) as discount_amount,
            COALESCE(SUM(ss.total_amount), 0) as gross_amount
        FROM shop_sales ss
        WHERE DATE(ss.sale_date) = ?
    ", [$record_date]);
    $shop_sales_single = $stmt->fetch();

    // Get split payment amounts from shop_sale_payments table (only actual payment methods)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN ssp.payment_method = 'cash' THEN ssp.amount ELSE 0 END), 0) as split_cash_amount,
            COALESCE(SUM(CASE WHEN ssp.payment_method = 'card' THEN ssp.amount ELSE 0 END), 0) as split_card_amount,
            COALESCE(SUM(CASE WHEN ssp.payment_method = 'transfer' THEN ssp.amount ELSE 0 END), 0) as split_transfer_amount
        FROM shop_sale_payments ssp
        JOIN shop_sales ss ON ssp.shop_sale_id = ss.id
        WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 1 AND ssp.payment_method != 'credit'
    ", [$record_date]);
    $shop_sales_split = $stmt->fetch();

    // Get credit sales separately (from both single and split payments)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'credit' THEN ss.total_amount - ss.discount_amount ELSE 0 END), 0) as single_credit_amount,
            COALESCE(SUM(CASE WHEN ss.payment_split = 0 AND ss.payment_method = 'credit' THEN ss.total_amount ELSE 0 END), 0) as single_gross_credit_amount
        FROM shop_sales ss
        WHERE DATE(ss.sale_date) = ?
    ", [$record_date]);
    $shop_credit_single = $stmt->fetch();

    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN ssp.payment_method = 'credit' THEN ssp.amount ELSE 0 END), 0) as split_credit_amount
        FROM shop_sale_payments ssp
        JOIN shop_sales ss ON ssp.shop_sale_id = ss.id
        WHERE DATE(ss.sale_date) = ? AND ss.payment_split = 1 AND ssp.payment_method = 'credit'
    ", [$record_date]);
    $shop_credit_split = $stmt->fetch();

    // Combine single and split payment amounts (excluding credit)
    $shop_sales = [
        'cash_amount' => ($shop_sales_single['cash_amount'] ?? 0) + ($shop_sales_split['split_cash_amount'] ?? 0),
        'gross_cash_amount' => ($shop_sales_single['gross_cash_amount'] ?? 0) + ($shop_sales_split['split_cash_amount'] ?? 0),
        'card_amount' => ($shop_sales_single['card_amount'] ?? 0) + ($shop_sales_split['split_card_amount'] ?? 0),
        'gross_card_amount' => ($shop_sales_single['gross_card_amount'] ?? 0) + ($shop_sales_split['split_card_amount'] ?? 0),
        'transfer_amount' => ($shop_sales_single['transfer_amount'] ?? 0) + ($shop_sales_split['split_transfer_amount'] ?? 0),
        'gross_transfer_amount' => ($shop_sales_single['gross_transfer_amount'] ?? 0) + ($shop_sales_split['split_transfer_amount'] ?? 0),
        'credit_amount' => ($shop_credit_single['single_credit_amount'] ?? 0) + ($shop_credit_split['split_credit_amount'] ?? 0),
        'gross_credit_amount' => ($shop_credit_single['single_gross_credit_amount'] ?? 0) + ($shop_credit_split['split_credit_amount'] ?? 0),
        'total_amount' => $shop_sales_single['total_amount'] ?? 0,
        'discount_amount' => $shop_sales_single['discount_amount'] ?? 0,
        'gross_amount' => $shop_sales_single['gross_amount'] ?? 0
    ];

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

    // Combine both sources for total shop units
    $shop_sales_units = ($shop_sales_units_new['total_units'] ?? 0) + ($shop_sales_units_old['total_units'] ?? 0);

    // Get shop expenses from shop_collections table (separate settled and unsettled)
    // Unsettled expenses: status != 'cleared' (pending or acknowledged)
    // Settled expenses: status = 'cleared'
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN collection_amount ELSE 0 END), 0) as unsettled_collections,
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as unsettled_acknowledged,
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN balance_due ELSE 0 END), 0) as unsettled_balance,
            COALESCE(SUM(CASE WHEN status = 'cleared' THEN collection_amount ELSE 0 END), 0) as settled_collections,
            COALESCE(SUM(CASE WHEN status = 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as settled_acknowledged,
            COALESCE(SUM(collection_amount), 0) as total_shop_expenses,
            COALESCE(SUM(amount_acknowledged), 0) as total_shop_returns
        FROM shop_collections
        WHERE DATE(collection_date) = ?
    ", [$record_date]);
    $shop_collections_data = $stmt->fetch();

    // Get individual expense records for detailed view
    $stmt = executeQuery("
        SELECT
            id,
            collection_number,
            collection_date,
            collector_name,
            collector_role,
            collection_purpose,
            collection_amount,
            amount_acknowledged,
            balance_due,
            status,
            created_at
        FROM shop_collections
        WHERE DATE(collection_date) = ?
        ORDER BY created_at DESC
    ", [$record_date]);
    $shop_collections_records = $stmt->fetchAll();

    // For unsettled expenses, use collection_amount (total amount withdrawn)
    $shop_sales_collections = $shop_collections_data['unsettled_collections'] ?? 0;
    // For settled expenses, use collection_amount (the total expense amount for cleared status)
    $shop_sales_settled_collections = $shop_collections_data['settled_collections'] ?? 0;
    $shop_sales_total_expenses = floatval($shop_collections_data['total_shop_expenses'] ?? 0);
    $shop_sales_total_returns = floatval($shop_collections_data['total_shop_returns'] ?? 0);

    // Calculate totals with payment method breakdown (using only shop_sales table)
    $shop_sales_cash = $shop_sales['cash_amount'] ?? 0;
    $shop_sales_gross_cash = $shop_sales['gross_cash_amount'] ?? 0; // Gross cash sales before discount
    $shop_sales_card = $shop_sales['card_amount'] ?? 0;
    $shop_sales_gross_card = $shop_sales['gross_card_amount'] ?? 0; // Gross card sales before discount
    $shop_sales_transfer = $shop_sales['transfer_amount'] ?? 0;
    $shop_sales_gross_transfer = $shop_sales['gross_transfer_amount'] ?? 0; // Gross transfer sales before discount
    $shop_sales_credit = $shop_sales['credit_amount'] ?? 0; // Shop credit sales
    $shop_sales_gross_credit = $shop_sales['gross_credit_amount'] ?? 0; // Gross credit sales before discount
    $shop_sales_total = $shop_sales['total_amount'] ?? 0;
    $shop_sales_discount_total = $shop_sales['discount_amount'] ?? 0;
    $shop_sales_gross_total = $shop_sales['gross_amount'] ?? 0;

    // Debug logging
    error_log("Account Summary Shop Sales - Total: $shop_sales_total, Cash: $shop_sales_cash, Card: $shop_sales_card, Transfer: $shop_sales_transfer, Credit: $shop_sales_credit, Expenses: $shop_sales_collections, Units: $shop_sales_units");
} catch (PDOException $e) {
    error_log("Account summary shop sales error: " . $e->getMessage());
    // Use defaults
}

// Get actual damaged products value from daily_activities for selected date
$damaged_products_total = 0;
try {
    $damaged_params = [$record_date];
    $damaged_driver_filter = '';
    if ($selected_driver) {
        $damaged_driver_filter = 'AND da.driver_id = ?';
        $damaged_params[] = $selected_driver;
    }
    
    $stmt = executeQuery("
        SELECT COALESCE(SUM(da.damaged_products), 0) as total_damaged_value
        FROM daily_activities da
        WHERE da.activity_date = ? $damaged_driver_filter
    ", $damaged_params);
    $result = $stmt->fetch();
    $damaged_products_total = floatval($result['total_damaged_value'] ?? 0);
} catch (PDOException $e) {
    $damaged_products_total = 0;
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
    'shop_sales_gross_total' => 0,
    'shop_sales_discount_total' => 0
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

// Override with actual values from proper source tables
$grand_totals['total_discounts'] = $trip_discounts_total + $shop_sales_discount_total;
$grand_totals['total_credit'] = $credit_sales_total + $shop_sales_credit; // Include shop credit sales
$grand_totals['total_expenses'] = $expenses_total + $shop_sales_total_expenses; // Include shop expenses separately from sales
$grand_totals['total_damaged'] = $damaged_products_total;

// Add shop sales to grand totals with payment method breakdown
$grand_totals['shop_sales_total'] = $shop_sales_total;
$grand_totals['shop_sales_units'] = $shop_sales_units;
$grand_totals['dispatcher_units'] = $dispatcher_units_total;
$grand_totals['total_units'] = $dispatcher_units_total + $shop_sales_units;
$grand_totals['shop_sales_cash'] = $shop_sales_cash;
$grand_totals['shop_sales_gross_cash'] = $shop_sales_gross_cash; // Gross cash sales before discount
$grand_totals['shop_sales_card'] = $shop_sales_card;
$grand_totals['shop_sales_gross_card'] = $shop_sales_gross_card; // Gross card sales before discount
$grand_totals['shop_sales_transfer'] = $shop_sales_transfer;
$grand_totals['shop_sales_gross_transfer'] = $shop_sales_gross_transfer; // Gross transfer sales before discount
$grand_totals['shop_sales_credit'] = $shop_sales_credit; // Shop credit sales
$grand_totals['shop_sales_gross_credit'] = $shop_sales_gross_credit; // Gross credit sales before discount
$grand_totals['shop_sales_discount_total'] = $shop_sales_discount_total;
$grand_totals['shop_sales_gross_total'] = $shop_sales_gross_total;
$grand_totals['shop_sales_collections'] = $shop_sales_collections; // Only unsettled expenses
$grand_totals['shop_sales_settled_collections'] = $shop_sales_settled_collections ?? 0; // Settled expenses
$grand_totals['shop_sales_total_expenses'] = $shop_sales_total_expenses;
$grand_totals['shop_sales_total_returns'] = $shop_sales_total_returns;

// Add shop sales to dispatch value (shop sales are part of total stock issued)
$grand_totals['total_dispatch_value'] += $grand_totals['shop_sales_gross_total'];

// Add shop sales payment methods to respective totals
// Use gross cash (no expense adjustments) - expenses handled separately in expenses section
$grand_totals['total_cash'] += $grand_totals['shop_sales_cash'];
$grand_totals['total_transfer'] += $grand_totals['shop_sales_gross_transfer'];
$grand_totals['total_card'] += $grand_totals['shop_sales_gross_card'];

// Total collected = Cash + Transfer + Card (from both dispatchers and shop)
$grand_totals['total_collection'] = $grand_totals['total_cash'] + $grand_totals['total_transfer'] + $grand_totals['total_card'];
$grand_totals['total_collected_with_returns'] = $grand_totals['total_collection'] + $grand_totals['total_return_value'];
// Sales reconciliation excludes expenses; they remain a separate operating cost.
$grand_totals['total_collected_complete'] = $grand_totals['total_collection'] + $grand_totals['total_return_value'] + $grand_totals['total_credit'] + $grand_totals['total_damaged'];
$grand_totals['net_revenue'] = $grand_totals['total_collection'] - ($grand_totals['total_expenses'] - $shop_sales_total_expenses);

// Balance Sheet Calculation - Professional Accounting Approach
// Total Dispatch Value = Total stock issued from depot (dispatcher trips + shop sales gross)
// Total Accounted For = All ways stock/value is accounted for

$net_dispatch_value = $grand_totals['total_dispatch_value'];

// Calculate net amounts for shop (after discounts) for card/transfer only (cash uses gross)
$shop_total_gross = $grand_totals['shop_sales_gross_cash'] + $grand_totals['shop_sales_card'] + $grand_totals['shop_sales_transfer'];
$shop_discount_percentage = $shop_total_gross > 0 ? ($grand_totals['shop_sales_discount_total'] / $shop_total_gross) : 0;
$net_shop_card = $grand_totals['shop_sales_card'] - ($grand_totals['shop_sales_card'] * $shop_discount_percentage);
$net_shop_transfer = $grand_totals['shop_sales_transfer'] - ($grand_totals['shop_sales_transfer'] * $shop_discount_percentage);

// Sales reconciliation includes collections, credit, returns, damage, and settlements; expenses are separate.
// Shop settled collections remain excluded because they are tracked separately for reconciliation.
$grand_totals['total_accounted_for'] = $grand_totals['total_cash'] + $grand_totals['total_transfer'] + $net_shop_card + $grand_totals['total_credit'] + $grand_totals['total_return_value'] + $grand_totals['total_damaged'] + $grand_totals['total_settlement'];

// Variance = Accounted For - Dispatch Value
// Positive variance = Accounted For > Dispatch Value = Surplus
// Negative variance = Accounted For < Dispatch Value = Shortage
$balance_variance = $grand_totals['total_accounted_for'] - $net_dispatch_value;
$is_balanced = abs($balance_variance) < 0.01; // Allow for small rounding differences
$balance_label = $is_balanced ? 'Balanced' : ($balance_variance > 0 ? 'Surplus' : 'Shortage');
$balance_text_class = $is_balanced ? 'text-success' : ($balance_variance > 0 ? 'text-success' : 'text-danger');

// For display purposes, keep the accounted-for total for reconciliation view
 $total_accounted = $grand_totals['total_accounted_for'];

// Include auth for role checks
require_once '../../config/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Daily account summary with comprehensive financial breakdown by driver">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Account Summary - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    
    <style>
        .grand-total {
            background: #e9ecef;
            color: #333;
            padding: 1.5rem;
            border-radius: 12px;
            margin-top: 1.5rem;
            border: 1px solid #dee2e6;
        }
        
        .grand-total h4 {
            color: #1e293b;
            font-weight: 600;
        }
        
        .grand-total h5 {
            color: #334155;
            font-weight: 600;
        }
        
        .grand-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .grand-total .text-white-50 {
            color: #64748b !important;
            font-weight: 500;
        }
        
        .grand-total .text-white {
            color: #1e293b !important;
        }
        
        .grand-total .text-muted {
            color: #64748b !important;
        }
        
        .grand-total .h4 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .grand-total .h5 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #334155;
        }
        
        .grand-total small {
            color: #64748b;
        }
        
        /* Background utility classes for grand total sections */
        .bg-white-10 {
            background-color: #ffffff !important;
            border: 1px solid #e2e8f0;
        }
        
        .bg-success-20 {
            background-color: #dcfce7 !important;
            border: 1px solid #86efac;
        }
        
        .bg-danger-20 {
            background-color: #fee2e2 !important;
            border: 1px solid #fca5a5;
        }
        
        .bg-info-20 {
            background-color: #dbeafe !important;
            border: 1px solid #93c5fd;
        }
        
        .bg-warning-20 {
            background-color: #fef3c7 !important;
            border: 1px solid #fcd34d;
        }
        
        .grand-total .border-bottom {
            border-color: #e2e8f0 !important;
        }
        
        .grand-total .border-secondary {
            border-color: #cbd5e1 !important;
        }
        
        .grand-total .border-top {
            border-color: #e2e8f0 !important;
        }
        
        /* Print-friendly styles */
        @media print {
            .grand-total {
                background: white !important;
                color: black !important;
                border: 2px solid #000;
                page-break-inside: avoid;
            }
            
            .grand-total h4,
            .grand-total h5,
            .grand-total strong {
                color: black !important;
            }
            
            .grand-total .text-white,
            .grand-total .text-white-50 {
                color: #333 !important;
            }
            
            .grand-total .text-muted {
                color: #666 !important;
            }
            
            .bg-white-10,
            .bg-success-20,
            .bg-danger-20,
            .bg-info-20,
            .bg-warning-20 {
                background-color: #f5f5f5 !important;
                border: 1px solid #ddd;
            }
            
            .grand-total .table-dark {
                background-color: white !important;
                color: black !important;
            }
            
            .grand-total .table-dark th,
            .grand-total .table-dark td {
                color: black !important;
                border-color: #000 !important;
            }
            
            .grand-total .alert {
                border: 1px solid #000;
                page-break-inside: avoid;
            }
            
            .grand-total .alert-success {
                background-color: #d4edda !important;
                color: #155724 !important;
            }
            
            .grand-total .alert-danger {
                background-color: #f8d7da !important;
                color: #721c24 !important;
            }
            
            .grand-total .alert-info {
                background-color: #d1ecf1 !important;
                color: #0c5460 !important;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../../dashboard/index.php" class="logo">
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
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search drivers, accounts, summaries..." id="summarySearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <div class="date-display" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #f1f5f9; border-radius: 8px; font-weight: 500; color: #475569;">
                            <i class="fas fa-calendar-day text-primary"></i>
                            <span><?php echo date('F j, Y', strtotime($record_date)); ?></span>
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
                                <li><a class="dropdown-item text-danger" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
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
                            <h1><i class="fas fa-file-invoice me-3"></i>Account Summary</h1>
                            <p>Daily financial report with comprehensive breakdown by driver</p>
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
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Filter Summary</h5>
                            <p class="card-subtitle">Filter by date and driver</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $record_date; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Driver</label>
                                <select name="driver_id" class="form-select">
                                    <option value="">All Drivers</option>
                                    <?php foreach ($drivers as $d): ?>
                                        <option value="<?php echo $d['id']; ?>" <?php echo $selected_driver == $d['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($d['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i>Apply Filter
                                </button>
                                <a href="account-summary.php" class="btn btn-outline-secondary ms-2">Clear</a>
                            </div>
                        </form>
                    </div>
                </div>

        <!-- Summary Stats -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Summary Statistics</h5>
                            <p class="card-subtitle">Daily financial overview</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Main Stats Row -->
                        <div class="row">
                            <div class="col-md-3 col-sm-6">
                                <div class="stat-card">
                                    <div class="stat-icon blue">
                                        <i class="fas fa-truck"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $total_dispatches; ?></div>
                                    <div class="stat-label">Dispatches</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="stat-card">
                                    <div class="stat-icon purple">
                                        <i class="fas fa-box"></i>
                                    </div>
                                    <div class="stat-value">₦<?php echo number_format($grand_totals['total_dispatch_value'], 0); ?></div>
                                    <div class="stat-label">Dispatch Value</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="stat-card">
                                    <div class="stat-icon green">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <div class="stat-value">₦<?php echo number_format($grand_totals['total_collection'], 0); ?></div>
                                    <div class="stat-label">Total Collected</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="stat-card">
                                    <div class="stat-icon <?php echo ($grand_totals['total_collection'] - $grand_totals['total_expenses']) >= 0 ? 'green' : 'red'; ?>">
                                        <i class="fas fa-wallet"></i>
                                    </div>
                                    <div class="stat-value">₦<?php echo number_format($grand_totals['total_collection'] - $grand_totals['total_expenses'], 0); ?></div>
                                    <div class="stat-label">Collected - Expenses</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

        <!-- Account Balance Status -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Account Balance Status</h5>
                            <p class="card-subtitle">Daily account reconciliation</p>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge <?php echo $is_balanced ? 'badge-success' : 'badge-warning'; ?> fs-6">
                                <i class="fas fa-<?php echo $is_balanced ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                <?php echo $is_balanced ? 'BALANCED' : 'UNBALANCED'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="stat-card text-center">
                                    <div class="stat-icon <?php echo $is_balanced ? 'green' : 'yellow'; ?>">
                                        <i class="fas fa-balance-scale"></i>
                                    </div>
                                    <div class="stat-value">₦<?php echo number_format($grand_totals['total_dispatch_value'], 2); ?></div>
                                    <div class="stat-label">Opening Stock</div>
                                    <small class="text-muted">Dispatch Value (incl. Shop)</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card text-center">
                                    <div class="stat-icon blue">
                                        <i class="fas fa-calculator"></i>
                                    </div>
                                    <div class="stat-value">₦<?php echo number_format($total_accounted, 2); ?></div>
                                    <div class="stat-label">Accounted For</div>
                                    <small class="text-muted">Cash + Transfer + Returns + Credit + Discounts + Damaged + Settlement + Shop (Card)</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card text-center">
                                    <div class="stat-icon <?php echo $balance_variance >= 0 ? 'green' : 'red'; ?>">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <div class="stat-value <?php echo $balance_text_class; ?>">
                                        ₦<?php echo number_format(abs($balance_variance), 2); ?>
                                    </div>
                                    <div class="stat-label">Variance</div>
                                    <small class="<?php echo $balance_text_class; ?>">
                                        <?php echo $balance_label; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

        <!-- Trip Details -->
                <?php if (empty($dispatches) && empty($driver_summaries)): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No trip records found for <?php echo date('F j, Y', strtotime($record_date)); ?>. Select a date with records to view detailed trip breakdowns.
                            </div>
                        </div>
                    </div>
                <?php elseif (empty($dispatches) && !empty($driver_summaries)): ?>
                    <!-- Fallback: Show driver-level summaries if no dispatches but daily_activities exist -->
                    <?php foreach ($driver_summaries as $ds):
                        $driver_dispatches = array_filter($dispatches, fn($d) => $d['dispatcher_name'] == $ds['driver_name']);
                        $driver_returns = $returns_by_driver[$ds['driver_id']] ?? [
                            'driver_name' => $ds['driver_name'],
                            'total_returns' => 0,
                            'total_return_value' => 0,
                            'products' => []
                        ];
                        $driver_return_value = $driver_returns['total_return_value'];
                        $total_collected = $ds['cash_collected'] + $ds['transfer_amount'] + $driver_return_value;
                        $net_dispatch_value = $ds['total_dispatch_value'];
                        $total_accounted_for_driver = ($ds['cash_collected'] + $ds['transfer_amount'] + $driver_return_value + $ds['credit_amount'] + $ds['credit_settlement'] + $ds['discounts'] + $ds['damaged_products']);
                        $variance = $total_accounted_for_driver - $net_dispatch_value;
                        $is_driver_balanced = abs($variance) < 0.01;
                        $driver_balance_label = $is_driver_balanced ? 'Balanced' : ($variance > 0 ? 'Surplus' : 'Shortage');
                    ?>
                        <div class="content-card mb-4">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-user-tie me-2"></i><?php echo htmlspecialchars($ds['driver_name']); ?>
                                    </h5>
                                    <p class="card-subtitle">Driver financial breakdown (no dispatches recorded)</p>
                                </div>
                                <div class="card-header-actions">
                                    <span class="badge badge-primary"><?php echo $ds['total_trips']; ?> Trip(s)</span>
                                    <span class="badge <?php echo $is_driver_balanced ? 'badge-success' : 'badge-warning'; ?> ms-2">
                                        <i class="fas fa-<?php echo $is_driver_balanced ? 'check' : 'exclamation'; ?>"></i>
                                        <?php echo $is_driver_balanced ? 'Balanced' : 'Unbalanced'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning small">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Note:</strong> No dispatch records found for this driver on this date. Showing daily activity summary instead.
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-2">
                                                <div class="h5 mb-0 text-success">₦<?php echo number_format($ds['cash_collected'], 2); ?></div>
                                                <small class="text-muted">Cash Collected</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-2">
                                                <div class="h5 mb-0 text-primary">₦<?php echo number_format($ds['transfer_amount'], 2); ?></div>
                                                <small class="text-muted">Transfer Amount</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-center py-2">
                                                <div class="h5 mb-0 text-info">₦<?php echo number_format($total_accounted_for_driver, 2); ?></div>
                                                <small class="text-muted">Total Accounted For</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="alert <?php echo $is_driver_balanced ? 'alert-success' : 'alert-warning'; ?> py-2 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-<?php echo $is_driver_balanced ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                    <?php echo $is_driver_balanced ? 'Account Balanced' : 'Account Unbalanced'; ?></span>
                                    <span>Variance: ₦<?php echo number_format(abs($variance), 2); ?> (<?php echo $driver_balance_label; ?>)</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Show trip-level breakdown when dispatches exist -->
                    <?php foreach ($dispatches as $dispatch):
                    // Use dispatch-specific activity totals for this trip instead of driver totals.
                    $dispatch_activity = $dispatch_activities[$dispatch['id']] ?? [
                        'cash_collected' => 0,
                        'transfer_amount' => 0,
                        'credit_amount' => 0,
                        'expenses_amount' => 0,
                        'damaged_products' => 0,
                        'credit_settlement' => 0
                    ];

                    $dispatch_return_data = $returns_by_dispatch[$dispatch['id']] ?? [
                        'total_returns' => 0,
                        'total_return_value' => 0,
                        'products' => []
                    ];
                    $driver_return_value = $dispatch_return_data['total_return_value'];

                    $cash_collected = $dispatch_activity['cash_collected'];
                    $transfer_amount = $dispatch_activity['transfer_amount'];
                    $credit_amount = $dispatch_activity['credit_amount'];
                    $expenses_amount = $dispatch_activity['expenses_amount'];
                    $discounts = $discounts_by_dispatch[$dispatch['id']] ?? 0; // Use actual dispatch-level discounts
                    $damaged_products = $dispatch_activity['damaged_products'];
                    $credit_settlement = $dispatch_activity['credit_settlement'];

                    $total_dispatch_value = $dispatch['expected_amount'];
                    $total_collected = $cash_collected + $transfer_amount + $driver_return_value;
                    // Total Accounted For = Cash + Transfer + Returns + Credit Settlement + Discounts + Damaged
                    // Credit Sales are NOT included - they are outstanding/receivable, not collected
                    $total_accounted_for_trip = ($cash_collected + $transfer_amount + $driver_return_value + $credit_settlement + $discounts + $damaged_products);
                    // Balance check: Dispatch Value should equal (Total Accounted For + Credit Sales Outstanding)
                    $variance = ($total_accounted_for_trip + $credit_amount) - $total_dispatch_value;
                    $is_trip_balanced = abs($variance) < 0.01;
                    $trip_balance_label = $is_trip_balanced ? 'Balanced' : ($variance > 0 ? 'Surplus' : 'Shortage');
                ?>
                    <div class="content-card mb-4">
                        <div class="card-header bg-primary text-white">
                            <div>
                                <h5 class="card-title mb-1">
                                    <i class="fas fa-truck me-2"></i><?php echo htmlspecialchars($dispatch['dispatch_number']); ?> - <?php echo htmlspecialchars($dispatch['dispatcher_name']); ?>
                                </h5>
                                <p class="card-subtitle text-white-50 mb-0">Trip Financial Breakdown</p>
                            </div>
                            <div class="card-header-actions">
                                <span class="badge bg-light text-dark"><?php echo number_format($dispatch['total_units'], 2); ?> Units Taken</span>
                                <?php $total_returned = $returns_by_dispatch[$dispatch['id']]['total_returns'] ?? 0; ?>
                                <span class="badge bg-warning text-dark ms-2"><?php echo number_format($total_returned, 2); ?> Units Returned</span>
                                <?php $units_sold = $dispatch['total_units'] - $total_returned; ?>
                                <span class="badge bg-success text-white ms-2"><?php echo number_format($units_sold, 2); ?> Units Sold</span>
                                <span class="badge bg-info text-white ms-2">
                                    <i class="fas fa-chart-line me-1"></i>
                                    ₦<?php echo number_format($dispatch['total_amount'], 2); ?>
                                </span>
                                <span class="badge <?php echo $is_trip_balanced ? 'bg-light text-dark' : 'bg-warning text-dark'; ?> ms-2">
                                    <i class="fas fa-<?php echo $is_trip_balanced ? 'check' : 'exclamation'; ?> me-1"></i>
                                    <?php echo $is_trip_balanced ? 'Balanced' : 'Unbalanced'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                        <?php $expected_amount = $total_dispatch_value - $driver_return_value; ?>
                        <div class="mb-3">
                            <ul class="nav nav-tabs" id="tripSummaryTabs-<?php echo $dispatch['id']; ?>" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="expected-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#expected-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="expected-<?php echo $dispatch['id']; ?>" aria-selected="true">Expected Amount</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="returns-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#returns-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="returns-<?php echo $dispatch['id']; ?>" aria-selected="false">Returns</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="cash-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#cash-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="cash-<?php echo $dispatch['id']; ?>" aria-selected="false">Cash</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="transfer-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#transfer-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="transfer-<?php echo $dispatch['id']; ?>" aria-selected="false">Transfer</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="credit-sales-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#credit-sales-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="credit-sales-<?php echo $dispatch['id']; ?>" aria-selected="false">Credit Sales</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="discounts-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#discounts-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="discounts-<?php echo $dispatch['id']; ?>" aria-selected="false">Discounts</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="damaged-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#damaged-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="damaged-<?php echo $dispatch['id']; ?>" aria-selected="false">Damaged</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="expenses-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#expenses-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="expenses-<?php echo $dispatch['id']; ?>" aria-selected="false">Expenses</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="settlement-tab-<?php echo $dispatch['id']; ?>" data-bs-toggle="tab" data-bs-target="#settlement-<?php echo $dispatch['id']; ?>" type="button" role="tab" aria-controls="settlement-<?php echo $dispatch['id']; ?>" aria-selected="false">Settlement</button>
                                </li>
                            </ul>
                            <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white" id="tripSummaryTabsContent-<?php echo $dispatch['id']; ?>">
                                <div class="tab-pane fade show active" id="expected-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="expected-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-chart-line me-2"></i>Revenue Overview</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Dispatch Value</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($total_dispatch_value, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-warning">Less: Returns</td>
                                                                <td class="text-end text-warning">(₦<?php echo number_format($driver_return_value, 2); ?>)</td>
                                                            </tr>
                                                            <tr class="table-success">
                                                                <td class="fw-bold">Expected Amount</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($expected_amount, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-credit-card me-2"></i>Payment Methods</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Cash Collected</td>
                                                                <td class="text-end">₦<?php echo number_format($cash_collected, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td>Transfer Amount</td>
                                                                <td class="text-end">₦<?php echo number_format($transfer_amount, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-warning">Credit Sales</td>
                                                                <td class="text-end text-warning">₦<?php echo number_format($credit_amount, 2); ?></td>
                                                            </tr>
                                                            <tr class="table-info">
                                                                <td class="fw-bold">Total Accounted</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($total_accounted_for_trip, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-tags me-2"></i>Adjustments</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Discounts</td>
                                                                <td class="text-end">₦<?php echo number_format($discounts, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td>Damaged Products</td>
                                                                <td class="text-end">₦<?php echo number_format($damaged_products, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-receipt me-2"></i>Settlement & Expenses</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Credit Settlement</td>
                                                                <td class="text-end">₦<?php echo number_format($credit_settlement, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td>Expenses</td>
                                                                <td class="text-end">₦<?php echo number_format($expenses_amount, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-truck me-2"></i>Dispatch Details</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr>
                                                        <td>Trip #</td>
                                                        <td class="text-end"><?php echo htmlspecialchars($dispatch['dispatch_number']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Products</td>
                                                        <td class="text-end"><?php echo htmlspecialchars($dispatch['products']); ?></td>
                                                    </tr>
                                                    <tr class="table-success">
                                                        <td class="fw-bold">Expected Amount</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($dispatch['expected_amount'], 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="returns-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="returns-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-undo me-2"></i>Returns Summary</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr>
                                                        <td>Total Units Returned</td>
                                                        <td class="text-end fw-bold"><?php echo $dispatch_return_data['total_returns']; ?></td>
                                                    </tr>
                                                    <tr class="table-warning">
                                                        <td class="fw-bold">Total Return Value</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($dispatch_return_data['total_return_value'], 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php if (!empty($dispatch_return_data['products'])): ?>
                                        <div class="card mb-2">
                                            <div class="card-header py-2 px-3">
                                                <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-list me-2"></i>Return Details</h6>
                                            </div>
                                            <div class="card-body py-2 px-3">
                                                <table class="table table-sm mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Product</th>
                                                            <th class="text-end">Qty</th>
                                                            <th class="text-end">Unit Price</th>
                                                            <th class="text-end">Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($dispatch_return_data['products'] as $p): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                                                                <td class="text-end"><?php echo $p['quantity']; ?></td>
                                                                <td class="text-end">₦<?php echo number_format($p['unit_price'], 2); ?></td>
                                                                <td class="text-end">₦<?php echo number_format($p['total_value'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="tab-pane fade" id="cash-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="cash-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-money-bill-wave me-2"></i>Cash Collected</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr class="table-success">
                                                        <td class="fw-bold">Cash Amount</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($cash_collected, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Cash collected from driver for the day
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="transfer-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="transfer-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-exchange-alt me-2"></i>Transfer Amount</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr class="table-primary">
                                                        <td class="fw-bold">Transfer Amount</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($transfer_amount, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Bank transfer amount collected for this driver
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="credit-sales-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="credit-sales-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-credit-card me-2"></i>Credit Sales</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr class="table-warning">
                                                        <td class="fw-bold">Credit Sales</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($credit_amount, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Amount sold on credit (owed by customers)
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="discounts-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="discounts-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-tags me-2"></i>Discounts</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr class="table-info">
                                                        <td class="fw-bold">Discount Amount</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($discounts, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Discounts applied for this driver
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="damaged-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="damaged-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-box-open me-2"></i>Damaged Products</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr class="table-secondary">
                                                        <td class="fw-bold">Damaged Value</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($damaged_products, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Damaged product value recorded for this driver
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="expenses-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="expenses-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-receipt me-2"></i>Expenses</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr class="table-danger">
                                                        <td class="fw-bold">Expenses Amount</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($expenses_amount, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Operating expenses incurred by this driver
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="settlement-<?php echo $dispatch['id']; ?>" role="tabpanel" aria-labelledby="settlement-tab-<?php echo $dispatch['id']; ?>">
                                    <div class="card mb-2">
                                        <div class="card-header py-2 px-3">
                                            <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-handshake me-2"></i>Credit Settlement</h6>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <table class="table table-sm mb-0">
                                                <tbody>
                                                    <tr class="table-info">
                                                        <td class="fw-bold">Settlement Amount</td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($credit_settlement, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Credit settlement received from this driver
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Trip Balance Status -->
                        <div class="alert <?php echo $is_trip_balanced ? 'alert-success' : 'alert-warning'; ?> py-2 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-<?php echo $is_trip_balanced ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                <?php echo $is_trip_balanced ? 'Account Balanced' : 'Account Unbalanced'; ?></span>
                                <span>Variance: ₦<?php echo number_format(abs($variance), 2); ?> (<?php echo $trip_balance_label; ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Shop Financial Breakdown -->
            <?php if ($shop_sales_gross_total > 0 || $shop_sales_units > 0 || count($shop_collections_records) > 0): ?>
                <div class="content-card mb-4">
                    <div class="card-header bg-success text-white">
                        <div>
                            <h5 class="card-title mb-1">
                                <i class="fas fa-store me-2"></i>Shop Sales
                            </h5>
                            <p class="card-subtitle text-white-50 mb-0">Shop Financial Breakdown</p>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge bg-light text-dark"><?php echo $shop_sales_units; ?> Units Sold</span>
                            <span class="badge bg-info text-white ms-2">
                                <i class="fas fa-chart-line me-1"></i>
                                ₦<?php echo number_format($shop_sales_gross_total, 0); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <style>
                                #shopSummaryTabs .nav-link {
                                    color: #333 !important;
                                    background-color: #f8f9fa !important;
                                    border: 1px solid #dee2e6 !important;
                                    margin-right: 2px;
                                }
                                #shopSummaryTabs .nav-link:hover {
                                    background-color: #e9ecef !important;
                                    color: #000 !important;
                                }
                                #shopSummaryTabs .nav-link.active {
                                    background-color: #fff !important;
                                    color: #0d6efd !important;
                                    border-bottom-color: #fff !important;
                                }
                            </style>
                            <ul class="nav nav-tabs" id="shopSummaryTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="shop-total-tab" data-bs-toggle="tab" data-bs-target="#shop-total" type="button" role="tab" aria-controls="shop-total" aria-selected="true">Total Sales</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="shop-cash-tab" data-bs-toggle="tab" data-bs-target="#shop-cash" type="button" role="tab" aria-controls="shop-cash" aria-selected="false">Cash</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="shop-card-tab" data-bs-toggle="tab" data-bs-target="#shop-card" type="button" role="tab" aria-controls="shop-card" aria-selected="false">Card</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="shop-transfer-tab" data-bs-toggle="tab" data-bs-target="#shop-transfer" type="button" role="tab" aria-controls="shop-transfer" aria-selected="false">Transfer</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="shop-credit-tab" data-bs-toggle="tab" data-bs-target="#shop-credit" type="button" role="tab" aria-controls="shop-credit" aria-selected="false">Credit Sales</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="shop-collections-tab" data-bs-toggle="tab" data-bs-target="#shop-collections" type="button" role="tab" aria-controls="shop-collections" aria-selected="false">Expenses</button>
                                </li>
                            </ul>
                            <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white" id="shopSummaryTabsContent">
                                <?php
                                // Professional Shop Financial Breakdown Calculation
                                // Revenue Section
                                $shop_gross_sales = $shop_sales_gross_total; // Total sales before discount
                                $shop_discounts = $shop_sales_discount_total; // Total discounts given
                                $shop_net_sales = $shop_gross_sales - $shop_discounts; // Net sales after discount

                                // Payment Method Breakdown (actual payment methods only - credit is separate)
                                $shop_gross_cash = $shop_sales_gross_cash; // Gross cash sales (before discount)
                                $shop_gross_card = $shop_sales_gross_card; // Gross card sales (before discount)
                                $shop_gross_transfer = $shop_sales_gross_transfer; // Gross transfer sales (before discount)
                                $shop_card_sales = $shop_sales_card; // Card sales (after discount)
                                $shop_transfer_sales = $shop_sales_transfer; // Transfer sales (after discount)

                                // Credit Sales (separate from payment methods)
                                $shop_gross_credit = $shop_sales_gross_credit; // Gross credit sales (before discount)
                                $shop_credit_sales = $shop_sales_credit; // Credit sales (after discount)

                                // Collections Section
                                $shop_unsettled_collections = $shop_sales_collections; // Cash taken out but not settled
                                $shop_settled_collections = $shop_sales_settled_collections ?? 0; // Cash taken out and returned/settled
                                $shop_net_collections = $shop_sales_total_expenses - $shop_sales_total_returns; // Expenses net of cash returned

                                // Balance Calculation - Professional Accounting Format with Discount Tracking
                                // Accounting Standards Approach:
                                // 1. Revenue Recognition: Gross Sales (Total before discounts) - Discounts = Net Sales (Amount collected)
                                // 2. Payment Method Analysis: Track Gross vs Net for each payment method
                                // 3. Discount Allocation: Discounts are distributed across payment methods proportionally
                                // 4. Cash Reconciliation: Gross Cash - Net Expenses = Actual Cash on Hand
                                // 5. Balance Verification: All components must reconcile to Gross Sales
                                
                                $shop_gross_sales_total = $shop_gross_sales; // Total gross sales (all payment methods, before discounts)
                                $shop_discounts_total = $shop_discounts; // Total discounts given
                                $shop_net_sales_total = $shop_net_sales; // Net sales after discounts (amount actually collected)
                                
                                // Payment Method Breakdown - Gross vs Net (actual payment methods only)
                                $shop_gross_cash_sales = $shop_gross_cash; // Gross cash sales (before discounts)
                                $shop_gross_card_sales = $shop_gross_card; // Gross card sales (before discounts)
                                $shop_gross_transfer_sales = $shop_gross_transfer; // Gross transfer sales (before discounts)

                                // Credit Sales (separate from payment methods)
                                $shop_gross_credit_sales = $shop_gross_credit; // Gross credit sales (before discounts)
                                $shop_net_credit_sales = $shop_credit_sales; // Net credit sales (after discounts)

                                // Calculate discount allocation by payment method (proportional to gross sales)
                                // Only apply discounts to actual payment methods, not credit
                                $shop_payment_gross_total = $shop_gross_cash_sales + $shop_gross_card_sales + $shop_gross_transfer_sales;
                                $shop_discount_percentage = $shop_payment_gross_total > 0 ? ($shop_discounts_total / $shop_payment_gross_total) : 0;
                                $shop_cash_discount = $shop_gross_cash_sales * $shop_discount_percentage;
                                $shop_card_discount = $shop_gross_card_sales * $shop_discount_percentage;
                                $shop_transfer_discount = $shop_gross_transfer_sales * $shop_discount_percentage;

                                // Net sales by payment method (after discounts)
                                $shop_net_cash_sales = $shop_gross_cash_sales - $shop_cash_discount;
                                $shop_net_card_sales = $shop_gross_card_sales - $shop_card_discount;
                                $shop_net_transfer_sales = $shop_gross_transfer_sales - $shop_transfer_discount;
                                
                                // Collections/Expenses Section
                                $shop_unsettled_expenses = $shop_unsettled_collections; // Unsettled expenses (cash taken out)
                                $shop_settled_expenses = $shop_settled_collections; // Settled expenses (cash returned)
                                $shop_net_expenses = $shop_net_collections;
                                
                                // Cash Reconciliation
                                $shop_expected_cash = $shop_net_cash_sales; // Cash received after sales discounts
                                $shop_actual_cash_on_hand = $shop_expected_cash - $shop_net_expenses;
                                
                                // Balance Verification - Multiple reconciliation points
                                // Check 1: Gross Sales = Gross Cash + Gross Card + Gross Transfer + Gross Credit
                                $shop_gross_payment_total = $shop_gross_cash_sales + $shop_gross_card_sales + $shop_gross_transfer_sales + $shop_gross_credit_sales;
                                $shop_gross_variance = $shop_gross_payment_total - $shop_gross_sales_total;

                                // Check 2: Net Sales = Net Cash + Net Card + Net Transfer + Net Credit
                                $shop_net_payment_total = $shop_net_cash_sales + $shop_net_card_sales + $shop_net_transfer_sales + $shop_net_credit_sales;
                                $shop_net_variance = $shop_net_payment_total - $shop_net_sales_total;

                                // Check 3: Discounts = Cash Discount + Card Discount + Transfer Discount (credit doesn't get discounts)
                                $shop_discount_reconciliation = $shop_cash_discount + $shop_card_discount + $shop_transfer_discount;
                                $shop_discount_variance = $shop_discount_reconciliation - $shop_discounts_total;
                                
                                // Overall Balance Status
                                $shop_is_gross_balanced = abs($shop_gross_variance) < 0.01;
                                $shop_is_net_balanced = abs($shop_net_variance) < 0.01;
                                $shop_is_discount_balanced = abs($shop_discount_variance) < 0.01;
                                $shop_is_fully_balanced = $shop_is_gross_balanced && $shop_is_net_balanced && $shop_is_discount_balanced;
                                
                                $shop_balance_label = $shop_is_fully_balanced ? 'FULLY BALANCED' : 'RECONCILIATION NEEDED';
                                $shop_balance_class = $shop_is_fully_balanced ? 'success' : 'warning';
                                ?>
                                <div class="tab-pane fade show active" id="shop-total" role="tabpanel" aria-labelledby="shop-total-tab">
                                    <div class="alert alert-info mb-2 py-2">
                                        <strong>Shop Financial Statement</strong> - Gross sales, discounts, net sales & reconciliation
                                    </div>

                                    <!-- Compact Financial Overview -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-chart-line me-2"></i>Income Statement</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Sales</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_gross_sales_total, 2); ?></td>
                                                                <td class="text-end">100%</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Discounts</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_discounts_total, 2); ?>)</td>
                                                                <td class="text-end text-danger"><?php echo $shop_gross_sales_total > 0 ? number_format(($shop_discounts_total / $shop_gross_sales_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr class="table-success">
                                                                <td class="fw-bold">Net Sales</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_sales_total, 2); ?></td>
                                                                <td class="text-end fw-bold"><?php echo $shop_gross_sales_total > 0 ? number_format(($shop_net_sales_total / $shop_gross_sales_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-credit-card me-2"></i>Payment Methods</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Method</th>
                                                                <th class="text-end">Gross</th>
                                                                <th class="text-end">Net</th>
                                                                <th class="text-end">%</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <td>Cash</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_gross_cash_sales, 2); ?></td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_cash_sales, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_payment_gross_total > 0 ? number_format(($shop_gross_cash_sales / $shop_payment_gross_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr>
                                                                <td>Card</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_gross_card_sales, 2); ?></td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_card_sales, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_payment_gross_total > 0 ? number_format(($shop_gross_card_sales / $shop_payment_gross_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr>
                                                                <td>Transfer</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_gross_transfer_sales, 2); ?></td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_transfer_sales, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_payment_gross_total > 0 ? number_format(($shop_gross_transfer_sales / $shop_payment_gross_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-warning">Credit Sales</td>
                                                                <td class="text-end text-warning">₦<?php echo number_format($shop_gross_credit_sales, 2); ?></td>
                                                                <td class="text-end text-warning fw-bold">₦<?php echo number_format($shop_net_credit_sales, 2); ?></td>
                                                                <td class="text-end text-warning"><?php echo $shop_gross_sales_total > 0 ? number_format(($shop_gross_credit_sales / $shop_gross_sales_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr class="table-primary">
                                                                <td class="fw-bold">TOTAL</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_gross_payment_total, 2); ?></td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_payment_total, 2); ?></td>
                                                                <td class="text-end fw-bold">100%</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Compact Adjustments Section -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-tags me-2"></i>Adjustments</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Discounts</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_discounts_total, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-receipt me-2"></i>Settlement & Expenses</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Expenses</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_net_expenses, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Compact Cash & Balance Verification -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-cash-register me-2"></i>Cash Reconciliation</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Cash Sales</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_gross_cash_sales, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Discounts</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_cash_discount, 2); ?>)</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="fw-bold">Net Cash from Sales</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_cash_sales, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Expenses</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_net_expenses, 2); ?>)</td>
                                                            </tr>
                                                            <tr class="table-info">
                                                                <td class="fw-bold">Cash on Hand</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_actual_cash_on_hand, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-check-double me-2"></i>Balance Verification</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Check</th>
                                                                <th class="text-end">Var</th>
                                                                <th class="text-end">Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Sales</td>
                                                                <td class="text-end <?php echo $shop_is_gross_balanced ? 'text-success' : 'text-danger'; ?>">₦<?php echo number_format(abs($shop_gross_variance), 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_is_gross_balanced ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✗</span>'; ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td>Net Sales</td>
                                                                <td class="text-end <?php echo $shop_is_net_balanced ? 'text-success' : 'text-danger'; ?>">₦<?php echo number_format(abs($shop_net_variance), 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_is_net_balanced ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✗</span>'; ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td>Discounts</td>
                                                                <td class="text-end <?php echo $shop_is_discount_balanced ? 'text-success' : 'text-danger'; ?>">₦<?php echo number_format(abs($shop_discount_variance), 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_is_discount_balanced ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✗</span>'; ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Balance Status Alert -->
                                    <div class="alert <?php echo $shop_is_fully_balanced ? 'alert-success' : 'alert-warning'; ?> mb-0 py-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-<?php echo $shop_is_fully_balanced ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                            <strong><?php echo $shop_balance_label; ?></strong>
                                            <span class="ms-auto small">
                                                Gross: ₦<?php echo number_format($shop_gross_sales_total, 2); ?> | 
                                                Net: ₦<?php echo number_format($shop_net_sales_total, 2); ?> | 
                                                Discount: ₦<?php echo number_format($shop_discounts_total, 2); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="shop-cash" role="tabpanel" aria-labelledby="shop-cash-tab">
                                    <div class="alert alert-info mb-2 py-2">
                                        <strong>Cash Payment Method Analysis</strong> - Gross vs Net cash with discount allocation
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-chart-line me-2"></i>Cash Sales</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Cash</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_gross_cash_sales, 2); ?></td>
                                                                <td class="text-end">100%</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Discounts</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_cash_discount, 2); ?>)</td>
                                                                <td class="text-end text-danger"><?php echo $shop_gross_cash_sales > 0 ? number_format(($shop_cash_discount / $shop_gross_cash_sales) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr class="table-success">
                                                                <td class="fw-bold">Net Cash</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_cash_sales, 2); ?></td>
                                                                <td class="text-end fw-bold"><?php echo $shop_gross_cash_sales > 0 ? number_format(($shop_net_cash_sales / $shop_gross_cash_sales) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-cash-register me-2"></i>Cash Reconciliation</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Net Cash from Sales</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_net_cash_sales, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Expenses</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_net_expenses, 2); ?>)</td>
                                                            </tr>
                                                            <tr class="table-info">
                                                                <td class="fw-bold">Cash on Hand</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_actual_cash_on_hand, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Gross cash before discounts, net cash after discounts, actual cash after expenses
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="shop-card" role="tabpanel" aria-labelledby="shop-card-tab">
                                    <div class="alert alert-info mb-2 py-2">
                                        <strong>Card Payment Method Analysis</strong> - Gross vs Net card with discount allocation
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-chart-line me-2"></i>Card Sales</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Card</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_gross_card_sales, 2); ?></td>
                                                                <td class="text-end">100%</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Discounts</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_card_discount, 2); ?>)</td>
                                                                <td class="text-end text-danger"><?php echo $shop_gross_card_sales > 0 ? number_format(($shop_card_discount / $shop_gross_card_sales) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr class="table-success">
                                                                <td class="fw-bold">Net Card</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_card_sales, 2); ?></td>
                                                                <td class="text-end fw-bold"><?php echo $shop_gross_card_sales > 0 ? number_format(($shop_net_card_sales / $shop_gross_card_sales) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-chart-pie me-2"></i>Sales Analysis</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Card Sales</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_gross_card_sales, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_gross_sales_total > 0 ? number_format(($shop_gross_card_sales / $shop_gross_sales_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr>
                                                                <td>Net Card Sales</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_net_card_sales, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_net_sales_total > 0 ? number_format(($shop_net_card_sales / $shop_net_sales_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr class="table-info">
                                                                <td class="fw-bold">Discount Rate</td>
                                                                <td class="text-end fw-bold"><?php echo number_format($shop_gross_card_sales > 0 ? ($shop_card_discount / $shop_gross_card_sales) * 100 : 0, 2); ?>%</td>
                                                                <td class="text-end fw-bold">-</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Electronic payments processed after discounts, no cash impact
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="shop-transfer" role="tabpanel" aria-labelledby="shop-transfer-tab">
                                    <div class="alert alert-info mb-2 py-2">
                                        <strong>Transfer Payment Method Analysis</strong> - Gross vs Net transfer with discount allocation
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-chart-line me-2"></i>Transfer Sales</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Transfer</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_gross_transfer_sales, 2); ?></td>
                                                                <td class="text-end">100%</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Discounts</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_transfer_discount, 2); ?>)</td>
                                                                <td class="text-end text-danger"><?php echo $shop_gross_transfer_sales > 0 ? number_format(($shop_transfer_discount / $shop_gross_transfer_sales) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr class="table-success">
                                                                <td class="fw-bold">Net Transfer</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_transfer_sales, 2); ?></td>
                                                                <td class="text-end fw-bold"><?php echo $shop_gross_transfer_sales > 0 ? number_format(($shop_net_transfer_sales / $shop_gross_transfer_sales) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-chart-pie me-2"></i>Sales Analysis</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Transfer Sales</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_gross_transfer_sales, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_gross_sales_total > 0 ? number_format(($shop_gross_transfer_sales / $shop_gross_sales_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr>
                                                                <td>Net Transfer Sales</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_net_transfer_sales, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_net_sales_total > 0 ? number_format(($shop_net_transfer_sales / $shop_net_sales_total) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr class="table-info">
                                                                <td class="fw-bold">Discount Rate</td>
                                                                <td class="text-end fw-bold"><?php echo number_format($shop_gross_transfer_sales > 0 ? ($shop_transfer_discount / $shop_gross_transfer_sales) * 100 : 0, 2); ?>%</td>
                                                                <td class="text-end fw-bold">-</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Direct bank payments after discounts, no cash impact
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="shop-collections" role="tabpanel" aria-labelledby="shop-collections-tab">
                                    <div class="alert alert-info mb-2 py-2">
                                        <strong>Shop Expenses Statement</strong> - Track cash withdrawn from shop for business expenses
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-receipt me-2"></i>Expenses Summary</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Total Expenses</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_unsettled_expenses + $shop_settled_expenses, 2); ?></td>
                                                                <td class="text-end"><?php echo $shop_gross_cash_sales > 0 ? number_format((($shop_unsettled_expenses + $shop_settled_expenses) / $shop_gross_cash_sales) * 100, 1) : 0; ?>%</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Unsettled</td>
                                                                <td class="text-end text-danger">₦<?php echo number_format($shop_unsettled_expenses, 2); ?></td>
                                                                <td class="text-end"><span class="badge bg-warning">Pending</span></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-success">Settled</td>
                                                                <td class="text-end text-success">₦<?php echo number_format($shop_settled_expenses, 2); ?></td>
                                                                <td class="text-end"><span class="badge bg-success">Cleared</span></td>
                                                            </tr>
                                                            <tr class="table-info">
                                                                <td class="fw-bold">Net Outstanding</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_net_expenses, 2); ?></td>
                                                                <td class="text-end fw-bold"><?php echo $shop_net_expenses > 0 ? 'Outstanding' : 'Settled'; ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card mb-2">
                                                <div class="card-header py-2 px-3">
                                                    <h6 class="mb-0 fw-bold text-primary small"><i class="fas fa-cash-register me-2"></i>Cash Impact</h6>
                                                </div>
                                                <div class="card-body py-2 px-3">
                                                    <table class="table table-sm mb-0">
                                                        <tbody>
                                                            <tr>
                                                                <td>Gross Cash Sales</td>
                                                                <td class="text-end">₦<?php echo number_format($shop_gross_cash_sales, 2); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Discounts</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_cash_discount, 2); ?>)</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-danger">Less: Expenses</td>
                                                                <td class="text-end text-danger">(₦<?php echo number_format($shop_net_expenses, 2); ?>)</td>
                                                            </tr>
                                                            <tr class="table-success">
                                                                <td class="fw-bold">Cash on Hand</td>
                                                                <td class="text-end fw-bold">₦<?php echo number_format($shop_actual_cash_on_hand, 2); ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-secondary small mb-0 py-2">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Unsettled expenses reduce cash on hand until returned/settled
                                    </div>
                                    
                                    <?php if (count($shop_collections_records) > 0): ?>
                                        <div class="mt-3">
                                            <h6 class="mb-2 small fw-bold">Expense Transaction Details</h6>
                                            <div class="table-responsive">
                                                <table class="table table-hover table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Expense #</th>
                                                            <th>Recipient</th>
                                                            <th>Purpose</th>
                                                            <th>Amount</th>
                                                            <th>Acknowledged</th>
                                                            <th>Balance Due</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($shop_collections_records as $collection): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($collection['collection_number']); ?></td>
                                                                <td>
                                                                    <?php echo htmlspecialchars($collection['collector_name']); ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($collection['collector_role']); ?></small>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($collection['collection_purpose']); ?></td>
                                                                <td style="font-weight: 600;">₦<?php echo number_format($collection['collection_amount'], 2); ?></td>
                                                                <td>₦<?php echo number_format($collection['amount_acknowledged'], 2); ?></td>
                                                                <td style="font-weight: 600;">₦<?php echo number_format($collection['balance_due'], 2); ?></td>
                                                                <td>
                                                                    <?php if ($collection['status'] === 'pending'): ?>
                                                                        <span class="badge bg-warning">Pending</span>
                                                                    <?php elseif ($collection['status'] === 'acknowledged'): ?>
                                                                        <span class="badge bg-info">Acknowledged</span>
                                                                    <?php elseif ($collection['status'] === 'cleared'): ?>
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
                            </div>
                        </div>

                        <!-- Shop Balance Status -->
                        <div class="alert <?php echo $shop_is_fully_balanced ? 'alert-success' : 'alert-warning'; ?> py-2 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-<?php echo $shop_is_fully_balanced ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                <?php echo $shop_is_fully_balanced ? 'Shop Account Fully Balanced' : 'Shop Account Reconciliation Needed'; ?></span>
                                <span>
                                    Gross: <?php echo $shop_is_gross_balanced ? '✓' : '✗'; ?> | 
                                    Net: <?php echo $shop_is_net_balanced ? '✓' : '✗'; ?> | 
                                    Discount: <?php echo $shop_is_discount_balanced ? '✓' : '✗'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Grand Total Summary -->
            <div class="grand-total">
                <h4 class="mb-2"><i class="fas fa-calculator me-2"></i>Grand Total Summary</h4>
                <p class="text-muted mb-3 small">Professional accounting report for <?php echo date('F j, Y', strtotime($record_date)); ?></p>
                <?php if ($grand_totals['total_units'] > 0): ?>
                <div class="alert alert-info py-2 mb-3">
                    <i class="fas fa-boxes me-2"></i>
                    <strong>Total Units Sold:</strong> <?php echo number_format($grand_totals['total_units'], 2); ?> units
                    <span class="text-muted ms-2">(Dispatchers: <?php echo number_format($grand_totals['dispatcher_units'], 2); ?> + Shop: <?php echo number_format($grand_totals['shop_sales_units'], 2); ?>)</span>
                </div>
                <?php endif; ?>

                <!-- Total Dispatch Value -->
                <div class="card mb-3 bg-light border">
                    <div class="card-header py-2 px-3 border bg-primary">
                        <h6 class="mb-0 fw-bold text-white small"><i class="fas fa-boxes me-2"></i>Total Dispatch Value (Stock Issued)</h6>
                    </div>
                    <div class="card-body py-2 px-3">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr>
                                            <td>Dispatcher Trips Value</td>
                                            <td class="text-end fw-bold">₦<?php echo number_format($grand_totals['total_dispatch_value'] - $grand_totals['shop_sales_gross_total'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Shop Sales Gross</td>
                                            <td class="text-end fw-bold">₦<?php echo number_format($grand_totals['shop_sales_gross_total'], 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center py-2">
                                    <small class="text-muted">Total Stock Issued</small>
                                    <h3 class="mb-0 text-primary fw-bold">₦<?php echo number_format($grand_totals['total_dispatch_value'], 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Total Accounted For -->
                <div class="card mb-3 bg-light border">
                    <div class="card-header py-2 px-3 border bg-success">
                        <h6 class="mb-0 fw-bold text-white small"><i class="fas fa-check-double me-2"></i>Total Accounted For</h6>
                    </div>
                    <div class="card-body py-2 px-3">
                        <div class="row">
                            <div class="col-md-4">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr><th colspan="2" class="text-muted small">Cash & Payments</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Cash Sales</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_cash'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Transfer Sales</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_transfer'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Card Sales (Net)</td>
                                            <td class="text-end">₦<?php echo number_format($net_shop_card, 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-4">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr><th colspan="2" class="text-muted small">Sales & Adjustments</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Credit Sales</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_credit'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Returns</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_return_value'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Damaged Products</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_damaged'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Credit Settlements</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_settlement'], 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-4">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr><th colspan="2" class="text-muted small">Expenses</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr class="table-danger">
                                            <td class="fw-bold">Total Expenses</td>
                                            <td class="text-end text-danger fw-bold">(₦<?php echo number_format($grand_totals['total_expenses'], 2); ?>)</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-success">
                                            <td class="fw-bold">Total Accounted For</td>
                                            <td class="text-end fw-bold">₦<?php echo number_format($grand_totals['total_accounted_for'], 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Balance Reconciliation -->
                <div class="card mb-3 bg-light border">
                    <div class="card-header py-2 px-3 border <?php echo $is_balanced ? 'bg-success' : ($balance_variance > 0 ? 'bg-info' : 'bg-danger'); ?>">
                        <h6 class="mb-0 fw-bold text-white small"><i class="fas fa-balance-scale me-2"></i>Balance Reconciliation</h6>
                    </div>
                    <div class="card-body py-2 px-3">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr>
                                            <td>Total Dispatch Value</td>
                                            <td class="text-end fw-bold">₦<?php echo number_format($grand_totals['total_dispatch_value'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Total Accounted For</td>
                                            <td class="text-end fw-bold">₦<?php echo number_format($grand_totals['total_accounted_for'], 2); ?></td>
                                        </tr>
                                        <tr class="<?php echo $is_balanced ? 'table-success' : ($balance_variance > 0 ? 'table-info' : 'table-danger'); ?>">
                                            <td class="fw-bold">Variance</td>
                                            <td class="text-end fw-bold <?php echo $balance_text_class; ?>">₦<?php echo number_format(abs($balance_variance), 2); ?> <?php echo $balance_variance > 0 ? '(Surplus)' : ($balance_variance < 0 ? '(Shortage)' : ''); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center py-2">
                                    <div class="alert <?php echo $is_balanced ? 'alert-success' : ($balance_variance > 0 ? 'alert-info' : 'alert-danger'); ?> mb-0">
                                        <h4 class="mb-1 fw-bold">
                                            <i class="fas fa-<?php echo $is_balanced ? 'check-circle' : ($balance_variance > 0 ? 'arrow-up' : 'arrow-down'); ?> me-2"></i>
                                            <?php echo $balance_label; ?>
                                        </h4>
                                        <small class="mb-0">
                                            <?php if ($is_balanced): ?>
                                                Account is perfectly balanced
                                            <?php elseif ($balance_variance > 0): ?>
                                                Accounted For exceeds Dispatch Value
                                            <?php else: ?>
                                                Dispatch Value exceeds Accounted For
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Breakdown (Collapsible) -->
                <div class="card bg-light border">
                    <div class="card-header py-2 px-3 border" data-bs-toggle="collapse" data-bs-target="#detailedBreakdown" style="cursor: pointer;">
                        <h6 class="mb-0 fw-bold text-dark small"><i class="fas fa-list me-2"></i>Detailed Breakdown (Click to Expand)</h6>
                    </div>
                    <div class="card-body py-2 px-3 collapse" id="detailedBreakdown">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted small mb-2">Payment Methods Breakdown</h6>
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr>
                                            <td>Cash (Gross)</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_cash'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Transfer</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_transfer'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Card Sales (Gross)</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['shop_sales_card'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Card Sales (Net after discount)</td>
                                            <td class="text-end text-success">₦<?php echo number_format($net_shop_card, 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Transfer Sales (Gross)</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['shop_sales_transfer'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Transfer Sales (Net after discount)</td>
                                            <td class="text-end text-success">₦<?php echo number_format($net_shop_transfer, 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted small mb-2">Discounts & Expenses</h6>
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr>
                                            <td>Total Discounts</td>
                                            <td class="text-end text-warning">₦<?php echo number_format($grand_totals['total_discounts'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Dispatcher Expenses</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['total_expenses'] - $grand_totals['shop_sales_collections'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Shop Expenses</td>
                                            <td class="text-end">₦<?php echo number_format($grand_totals['shop_sales_collections'], 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

        <!-- Action Buttons -->
        <div class="content-card mt-4">
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Daily Sales
                    </a>
                    <a href="account-summary-print.php?date=<?php echo $record_date; ?>&driver_id=<?php echo $selected_driver; ?>" class="btn btn-success" target="_blank">
                        <i class="fas fa-print me-2"></i>Print Summary (A4)
                    </a>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print me-2"></i>Print This Page
                    </button>
                </div>
            </div>
        </div>
    </main>
</div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/clean-dashboard.js"></script>
    
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
        document.getElementById('summarySearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const driverCards = document.querySelectorAll('.content-card');

            driverCards.forEach(card => {
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


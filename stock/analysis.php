<?php
// Daily Stock Analysis Page
require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/stock_manager.php';
require_once '../config/auth.php';

$date = $_GET['date'] ?? date('Y-m-d');
$stockManager = getStockManager();

$dailyReport = $stockManager->getDailyStockReport($date);

$supply_by_product = [];
try {
    // Get net supply from stock_movements (supply + adjustments)
    // This matches what's actually stored in daily_stock
    $stmt = executeQuery(
        "SELECT product_id,
                COALESCE(SUM(CASE WHEN movement_type = 'supply' THEN quantity_change ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN movement_type = 'adjustment' THEN quantity_change ELSE 0 END), 0) AS net_supply
         FROM stock_movements
         WHERE DATE(movement_date) = ? AND movement_type IN ('supply', 'adjustment')
         GROUP BY product_id",
        [$date]
    );
    foreach ($stmt->fetchAll() as $row) {
        $supply_by_product[intval($row['product_id'])] = floatval($row['net_supply']);
    }
} catch (Exception $e) {
    $supply_by_product = [];
}

$sales_by_product = [];
try {
    // Get all dispatch quantities from dispatch_items (same as stock summary for consistency)
    // This ensures both pages show the same dispatch quantities
    $dispatch_by_product = [];
    $stmt = executeQuery(
        "SELECT di.product_id, COALESCE(SUM(di.quantity), 0) AS dispatch_quantity
         FROM dispatch_items di
         JOIN dispatches d ON di.dispatch_id = d.id
         WHERE DATE(d.dispatch_date) = ?
           AND d.sales_channel != 'shop_sales'
         GROUP BY di.product_id",
        [$date]
    );
    foreach ($stmt->fetchAll() as $row) {
        $dispatch_by_product[intval($row['product_id'])] = floatval($row['dispatch_quantity']);
    }

    // Get all returns from daily_returns table (authoritative source)
    $returns_by_product = [];
    $stmt = executeQuery(
        "SELECT product_id, COALESCE(SUM(quantity), 0) AS return_qty
         FROM daily_returns
         WHERE return_date = ?
         GROUP BY product_id",
        [$date]
    );
    foreach ($stmt->fetchAll() as $row) {
        $returns_by_product[intval($row['product_id'])] = floatval($row['return_qty']);
    }

    // Get shop sales from shop_sales_items
    $shop_sales_by_product = [];
    try {
        $stmt = executeQuery(
            "SELECT ssi.product_id, COALESCE(SUM(ssi.quantity), 0) AS sales_quantity
             FROM shop_sales_items ssi
             JOIN shop_sales ss ON ssi.shop_sale_id = ss.id
             WHERE DATE(ss.sale_date) = ?
             GROUP BY ssi.product_id",
            [$date]
        );
        foreach ($stmt->fetchAll() as $row) {
            $shop_sales_by_product[intval($row['product_id'])] = floatval($row['sales_quantity']);
        }
    } catch (Exception $e) {
        $shop_sales_by_product = [];
    }

    // Calculate dispatcher sales (dispatch - returns) for products
    // dispatch is negative, so we use abs(dispatch) - returns
    $dispatcher_sales_by_product = [];
    foreach ($dispatch_by_product as $pid => $dispatch_qty) {
        $returns = $returns_by_product[$pid] ?? 0.0;
        $dispatcher_sales = max(0, abs($dispatch_qty) - $returns);
        $dispatcher_sales_by_product[$pid] = $dispatcher_sales;
    }

    // Calculate net sales (dispatcher sales + shop sales) for products
    foreach ($dispatch_by_product as $pid => $dispatch_qty) {
        $dispatcher_sales = $dispatcher_sales_by_product[$pid] ?? 0.0;
        $shop_sales = $shop_sales_by_product[$pid] ?? 0.0;
        $net_sales = $dispatcher_sales + $shop_sales;
        $sales_by_product[$pid] = $net_sales;
    }
    // Also include products that only have shop sales (no dispatch)
    foreach ($shop_sales_by_product as $pid => $shop_qty) {
        if (!isset($sales_by_product[$pid])) {
            $sales_by_product[$pid] = $shop_qty;
        }
    }

    // Ensure returns are still visible in summary even if no dispatch occurred for a product
    foreach ($returns_by_product as $pid => $return_qty) {
        if (!isset($sales_by_product[$pid])) {
            $sales_by_product[$pid] = 0.0;
        }
    }
} catch (Exception $e) {
    $sales_by_product = [];
}

$dailyFinancials = [
    'credit_sold' => 0.0,
    'bad_products' => 0.0,
    'net_sales_amount' => 0.0,
    'total_discount' => 0.0,
    'total_expenses' => 0.0
];
$badUnitsByProduct = [];
$totalBadProductUnits = 0.0;
try {
    // Get dispatcher damaged products from daily_activities
    $stmt = executeQuery(
        "SELECT
            COALESCE(SUM(credit_amount), 0) AS credit_sold,
            COALESCE(SUM(damaged_products), 0) AS bad_products
         FROM daily_activities
         WHERE DATE(activity_date) = ?",
        [$date]
    );
    $dailyFinancials = $stmt->fetch();
    $dailyFinancials['credit_sold'] = floatval($dailyFinancials['credit_sold'] ?? 0);
    $dailyFinancials['bad_products'] = floatval($dailyFinancials['bad_products'] ?? 0);

    // Get net sales amount from shop_sales (total_amount - discount_amount)
    try {
        $stmt = executeQuery(
            "SELECT COALESCE(SUM(total_amount - discount_amount), 0) AS net_sales_amount
             FROM shop_sales
             WHERE DATE(sale_date) = ?",
            [$date]
        );
        $shopSalesResult = $stmt->fetch();
        $dailyFinancials['net_sales_amount'] = floatval($shopSalesResult['net_sales_amount'] ?? 0);
    } catch (Exception $e) {
        $dailyFinancials['net_sales_amount'] = 0.0;
    }

    // Get dispatcher sales amount from dispatches (actual_sales_amount)
    try {
        $stmt = executeQuery(
            "SELECT COALESCE(SUM(actual_sales_amount), 0) AS dispatcher_sales_amount
             FROM dispatches
             WHERE DATE(dispatch_date) = ?
               AND (sales_channel IS NULL OR sales_channel NOT IN ('shop', 'shop_sales'))",
            [$date]
        );
        $dispatcherSalesResult = $stmt->fetch();
        $dailyFinancials['net_sales_amount'] += floatval($dispatcherSalesResult['dispatcher_sales_amount'] ?? 0);
    } catch (Exception $e) {
        // Keep existing net sales amount
    }

    // Get total discount from shop_sales
    try {
        $stmt = executeQuery(
            "SELECT COALESCE(SUM(discount_amount), 0) AS total_discount
             FROM shop_sales
             WHERE DATE(sale_date) = ?",
            [$date]
        );
        $shopDiscountResult = $stmt->fetch();
        $dailyFinancials['total_discount'] = floatval($shopDiscountResult['total_discount'] ?? 0);
    } catch (Exception $e) {
        $dailyFinancials['total_discount'] = 0.0;
    }

    // Get dispatcher discounts from dispatch_items
    try {
        $stmt = executeQuery(
            "SELECT COALESCE(SUM(di.discount_amount), 0) AS dispatcher_discount
             FROM dispatch_items di
             JOIN dispatches d ON di.dispatch_id = d.id
             WHERE DATE(d.dispatch_date) = ?
               AND (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales'))",
            [$date]
        );
        $dispatcherDiscountResult = $stmt->fetch();
        $dailyFinancials['total_discount'] += floatval($dispatcherDiscountResult['dispatcher_discount'] ?? 0);
    } catch (Exception $e) {
        // Keep existing discount value
    }

    // Get total expenses from daily_activities
    try {
        $stmt = executeQuery(
            "SELECT COALESCE(SUM(expenses_amount), 0) AS total_expenses
             FROM daily_activities
             WHERE DATE(activity_date) = ?",
            [$date]
        );
        $expensesResult = $stmt->fetch();
        $dailyFinancials['total_expenses'] = floatval($expensesResult['total_expenses'] ?? 0);
    } catch (Exception $e) {
        $dailyFinancials['total_expenses'] = 0.0;
    }

    $productMap = [];
    $productStmt = executeQuery("SELECT id, name FROM products");
    foreach ($productStmt->fetchAll() as $productRow) {
        $productMap[strtolower(trim($productRow['name']))] = intval($productRow['id']);
    }

    // Get dispatcher damaged products from daily_activities notes
    $damageStmt = executeQuery(
        "SELECT damaged_products_note
         FROM daily_activities
         WHERE DATE(activity_date) = ? AND damaged_products > 0",
        [$date]
    );
    foreach ($damageStmt->fetchAll() as $damageRow) {
        $note = trim($damageRow['damaged_products_note'] ?? '');
        if (preg_match('/Damaged\s*([\d.,]+)\s*units?\s*of\s*(.+)$/i', $note, $matches)) {
            $quantity = floatval(str_replace(',', '', $matches[1]));
            $noteProductName = strtolower(trim(rtrim($matches[2], '. ')));
            $productId = $productMap[$noteProductName] ?? null;
            if ($productId === null) {
                foreach ($productMap as $candidateName => $candidateId) {
                    if ($candidateName === $noteProductName
                        || strpos($candidateName, $noteProductName) !== false
                        || strpos($noteProductName, $candidateName) !== false) {
                        $productId = $candidateId;
                        break;
                    }
                }
            }
            if ($productId !== null && $quantity > 0) {
                $badUnitsByProduct[$productId] = ($badUnitsByProduct[$productId] ?? 0.0) + $quantity;
                $totalBadProductUnits += $quantity;
            }
        }
    }

    // Get damage from stock movements first, then only fallback to shop_damaged_products
    // for entries that do not already have a matching movement record.
    try {
        $movementDamageStmt = executeQuery(
            "SELECT product_id, reference_type, reference_id, reference_number, COALESCE(SUM(ABS(quantity_change)), 0) AS quantity
             FROM stock_movements
             WHERE DATE(movement_date) = ? AND movement_type = 'damage'
             GROUP BY product_id, reference_type, reference_id, reference_number",
            [$date]
        );
        $matchedShopDamageRefs = [];
        foreach ($movementDamageStmt->fetchAll() as $movementDamageRow) {
            $productId = intval($movementDamageRow['product_id']);
            $quantity = floatval($movementDamageRow['quantity']);
            if ($productId > 0 && $quantity > 0) {
                $badUnitsByProduct[$productId] = ($badUnitsByProduct[$productId] ?? 0.0) + $quantity;
                $totalBadProductUnits += $quantity;
                $dailyFinancials['bad_products'] += $quantity;
            }

            $referenceType = strtolower(trim((string) ($movementDamageRow['reference_type'] ?? '')));
            if ($referenceType === 'shop_damage' && intval($movementDamageRow['reference_id'] ?? 0) > 0) {
                $matchedShopDamageRefs['shop_damage:' . intval($movementDamageRow['reference_id'])] = true;
            }
            if ($referenceType === 'manual' && !empty($movementDamageRow['reference_number'])) {
                $matchedShopDamageRefs['damage_number:' . $movementDamageRow['reference_number']] = true;
            }
        }

        $shopDamageStmt = executeQuery(
            "SELECT id, product_id, quantity, damage_number
             FROM shop_damaged_products
             WHERE DATE(damage_date) = ?",
            [$date]
        );
        foreach ($shopDamageStmt->fetchAll() as $shopDamageRow) {
            $shopDamageRef = 'shop_damage:' . intval($shopDamageRow['id'] ?? 0);
            $damageNumberRef = 'damage_number:' . (string) ($shopDamageRow['damage_number'] ?? '');
            if (isset($matchedShopDamageRefs[$shopDamageRef])
                || isset($matchedShopDamageRefs[$damageNumberRef])) {
                continue;
            }

            $productId = intval($shopDamageRow['product_id']);
            $quantity = floatval($shopDamageRow['quantity']);

            if ($productId > 0 && $quantity > 0) {
                $badUnitsByProduct[$productId] = ($badUnitsByProduct[$productId] ?? 0.0) + $quantity;
                $totalBadProductUnits += $quantity;
                $dailyFinancials['bad_products'] += $quantity;
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to load damage products: " . $e->getMessage());
    }

} catch (Exception $e) {
    $dailyFinancials = ['credit_sold' => 0.0, 'bad_products' => 0.0];
    $badUnitsByProduct = [];
    $totalBadProductUnits = 0.0;
}

// Damaged units are separate from sales and should not be subtracted from sales count
// Sales = Dispatch - Returns (damaged units are accounted separately)

$totalOpening = 0.0;
$totalSupply = 0.0;
$totalSales = 0.0;
$totalReturns = 0.0;
$totalBadProductUnits = floatval($totalBadProductUnits ?? 0.0);
$totalCalculatedClosing = 0.0;
$totalActualClosing = 0.0;

foreach ($dailyReport as $row) {
    $pid = intval($row['product_id']);
    $opening = floatval($row['opening_stock'] ?? 0);
    $supply = $supply_by_product[$pid] ?? 0.0;
    $sales = $sales_by_product[$pid] ?? 0.0;
    $returns = $returns_by_product[$pid] ?? 0.0;
    $badUnits = floatval($badUnitsByProduct[$pid] ?? 0.0);
    $actualClosing = floatval($row['closing_stock'] ?? 0);
    $expectedClosing = max(0.0, $opening + $supply - $sales - $badUnits);

    $totalOpening += $opening;
    $totalSupply += $supply;
    $totalSales += $sales;
    $totalReturns += $returns;
    $totalActualClosing += $actualClosing;
    $totalCalculatedClosing += $expectedClosing;
}

// Closing stock should follow the stock analysis rule:
// Closing = Total Stock - Net Sales - Damage
$totalStock = $totalOpening + $totalSupply;
$expectedClosing = max(0.0, $totalStock - $totalSales - $totalBadProductUnits);
$balanceDifference = $totalActualClosing - $expectedClosing;
$isBalanced = abs($balanceDifference) < 0.01;
$balanceStatus = $isBalanced ? 'Balanced' : 'Not balanced';
$balanceClass = $isBalanced ? 'text-success' : 'text-danger';
$balanceMessage = $isBalanced
    ? 'Stock is balanced. Closing stock matches Total Stock - Net Sales - Damage.'
    : 'Stock is not balanced. Difference is ' . number_format($balanceDifference, 2) . '.';

// Calculate monetary value of current stock (after all daily activities)
// This reflects the actual current stock value after sales, dispatches, and damage
$totalStockValue = 0.0;
$totalOpeningStockValue = 0.0;
$totalClosingStockValue = 0.0;
try {
    $stmt = executeQuery(
        "SELECT p.id, p.cost_price,
                COALESCE(ds.opening_stock, 0) as opening_stock,
                COALESCE(ds.closing_stock, 0) as closing_stock
         FROM products p
         LEFT JOIN daily_stock ds ON p.id = ds.product_id AND DATE(ds.stock_date) = ?
         WHERE p.is_active = 1",
        [$date]
    );
    foreach ($stmt->fetchAll() as $row) {
        $price = floatval($row['cost_price'] ?? 0);
        $opening = floatval($row['opening_stock'] ?? 0);
        $closing = floatval($row['closing_stock'] ?? 0);
        $supply = $supply_by_product[intval($row['id'])] ?? 0.0;
        $sales = $sales_by_product[intval($row['id'])] ?? 0.0;
        $badUnits = floatval($badUnitsByProduct[intval($row['id'])] ?? 0.0);

        // Opening stock value
        $totalOpeningStockValue += $opening * $price;

        // Current stock = Opening + Supply - Sales - Damage
        $currentStock = max(0.0, $opening + $supply - $sales - $badUnits);
        $totalStockValue += $currentStock * $price;

        // Closing stock value (use actual closing from daily_stock)
        $totalClosingStockValue += $closing * $price;
    }
} catch (Exception $e) {
    $totalStockValue = 0.0;
    $totalOpeningStockValue = 0.0;
    $totalClosingStockValue = 0.0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Daily Stock Analysis - Merrylay Enterprises</title>
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/fonts/inter/inter.css" rel="stylesheet">

    <!-- CSS Framework -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/unified-design-system.css" rel="stylesheet">
    <style>
        .summary-header { padding-top: 1.5rem; padding-bottom: 1.5rem; }
        .summary-card { border-radius: 16px; border: 1px solid rgba(99,102,241,.15); }
    </style>
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/stock-pages.css" rel="stylesheet">
</head>
<body class="stock-ui">
    <div class="dashboard-container">
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
                <?php endif; ?>

                <?php if (hasRole(['admin', 'manager', 'dispatcher'])): ?>
                <div class="nav-section-title stock">Stock Management</div>
                <a href="../stock/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock Manage</span>
                </a>
                <a href="summary.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Stock Summary</span>
                </a>
                <a href="analysis.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-search-dollar"></i></span>
                    <span class="nav-text">Stock Analysis</span>
                </a>
                <a href="../stock/initial_setup.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-play-circle"></i></span>
                    <span class="nav-text">Initial Stock Setup</span>
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

                <a href="../finance/daily_balance.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>

                <?php if (canRecordBankDeposits()): ?>
                <a href="../cashier/deposit.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>

                <a href="../dispatch/credit/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-text">Credit Management</span>
                </a>
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
                <a href="../admin/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-text">Stock Statistics</span>
                </a>
                <div class="nav-section-title admin">System</div>
                <a href="../admin/backup.php" class="nav-link">
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

        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search stock, products..." id="globalSearch" aria-label="Search stock and products">
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
                <div class="page-header mb-4">
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumb stock-breadcrumb">
                            <li class="breadcrumb-item"><a href="../dashboard/index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="manage.php">Stock management</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Analysis</li>
                        </ol>
                    </nav>
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h1 class="page-title">
                                <i class="fas fa-search-dollar" style="margin-right: 15px;"></i>
                                Daily Stock Analysis
                            </h1>
                            <p class="page-subtitle">Review stock activity, supply, sales, credit and closing status</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <label class="visually-hidden" for="datePicker">Analysis date</label>
                            <input type="date" id="datePicker" class="form-control form-control-sm" value="<?php echo $date; ?>">
                            <button type="button" onclick="viewDate()" class="btn btn-primary btn-sm">
                                <i class="fas fa-calendar me-2"></i>View
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm stock-page-print-hide" onclick="window.print();">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="location.reload();">
                                <i class="fas fa-sync-alt me-2"></i>Refresh
                            </button>
                        </div>
                    </div>
                </div>
            <script>
                function viewDate() {
                    const datePicker = document.getElementById('datePicker');
                    if (datePicker) {
                        window.location.href = 'analysis.php?date=' + datePicker.value;
                    }
                }
            </script>
                <div class="row g-3 mb-4 summary-header">
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Opening Stock</span>
                                <i class="fas fa-boxes text-primary"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalOpening, 2); ?></h2>
                            <p class="text-muted mb-0">Total opening quantity</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Supply Made</span>
                                <i class="fas fa-truck-loading text-success"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalSupply, 2); ?></h2>
                            <p class="text-muted mb-0">Total stock received</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Net Sales</span>
                                <i class="fas fa-shopping-cart text-warning"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalSales, 2); ?></h2>
                            <p class="text-muted mb-0">Units sold after returns</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Returns</span>
                                <i class="fas fa-undo text-info"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalReturns, 2); ?></h2>
                            <p class="text-muted mb-0">Units returned today</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Damaged Units</span>
                                <i class="fas fa-exclamation-triangle text-danger"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalBadProductUnits, 2); ?></h2>
                            <p class="text-muted mb-0">Bad product units today</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Closing Stock</span>
                                <i class="fas fa-warehouse text-secondary"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalCalculatedClosing, 2); ?></h2>
                            <p class="text-muted mb-0">Total closing quantity</p>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Current Stock Value (₦)</span>
                                <i class="fas fa-layer-group text-info"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($totalStockValue, 2); ?></h2>
                            <p class="text-muted mb-0">Value after sales & dispatches</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Opening Stock Value (₦)</span>
                                <i class="fas fa-boxes-stacked text-primary"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($totalOpeningStockValue, 2); ?></h2>
                            <p class="text-muted mb-0">Value at start of day</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Closing Stock Value (₦)</span>
                                <i class="fas fa-warehouse text-secondary"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($totalClosingStockValue, 2); ?></h2>
                            <p class="text-muted mb-0">Value at end of day</p>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Credit Sold (₦)</span>
                                <i class="fas fa-credit-card text-info"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($dailyFinancials['credit_sold'], 2); ?></h2>
                            <p class="text-muted mb-0">Recorded credit amount</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Bad Products (₦)</span>
                                <i class="fas fa-exclamation-triangle text-danger"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($dailyFinancials['bad_products'], 2); ?></h2>
                            <p class="text-muted mb-0">Damaged products value</p>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Net Sales Amount (₦)</span>
                                <i class="fas fa-money-bill-wave text-success"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($dailyFinancials['net_sales_amount'], 2); ?></h2>
                            <p class="text-muted mb-0">Total sales value after discounts</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Total Discount Given (₦)</span>
                                <i class="fas fa-tags text-warning"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($dailyFinancials['total_discount'], 2); ?></h2>
                            <p class="text-muted mb-0">Total discount amount given today</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Total Expenses (₦)</span>
                                <i class="fas fa-receipt text-danger"></i>
                            </div>
                            <h2 class="mb-2">₦<?php echo number_format($dailyFinancials['total_expenses'], 2); ?></h2>
                            <p class="text-muted mb-0">Total expenses recorded today</p>
                        </div>
                    </div>
                </div>

                <div class="content-card bg-white shadow-sm rounded-4 border-0 mb-4">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 border-bottom pb-3">
                        <div>
                            <h5 class="mb-1">Balance Analysis</h5>
                            <p class="text-muted mb-0"><?php echo htmlspecialchars($balanceMessage); ?></p>
                            <small class="text-muted">Account is balanced when Closing Stock matches Total Stock - Net Sales - Damage.</small>
                        </div>
                        <div class="text-end">
                            <span class="badge <?php echo $balanceClass; ?> bg-opacity-10 border <?php echo $isBalanced ? 'border-success' : 'border-danger'; ?> py-2 px-3"><?php echo $balanceStatus; ?></span>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded-3">
                                    <strong>Total opening stock</strong>
                                    <div class="fs-4"><?php echo number_format($totalOpening, 2); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded-3">
                                    <strong>Total closing stock</strong>
                                    <div class="fs-4"><?php echo number_format($totalCalculatedClosing, 2); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded-3">
                                    <strong>Opening vs Closing diff</strong>
                                    <div class="fs-4"><?php echo number_format($balanceDifference, 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card bg-white shadow-sm rounded-4 border-0">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 border-bottom pb-3">
                        <div>
                            <h5 class="mb-1">Daily Product Breakdown</h5>
                            <p class="text-muted mb-0"><?php echo date('F j, Y', strtotime($date)); ?></p>
                            <small class="text-muted">Sales values shown here are net of returns.</small>
                        </div>
                        <form class="d-flex align-items-center gap-2 stock-page-print-hide" method="GET" action="analysis.php">
                            <label class="visually-hidden" for="analysisTableDate">Breakdown date</label>
                            <input type="date" id="analysisTableDate" name="date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date); ?>">
                            <button type="submit" class="btn btn-primary btn-sm">View</button>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Opening</th>
                                        <th class="text-end">Supply</th>
                                        <th class="text-end">Total Stock</th>
                                        <th class="text-end">Dispatch</th>
                                        <th class="text-end">Dispatcher Sales</th>
                                        <th class="text-end">Shop Sales</th>
                                        <th class="text-end">Net Sales</th>
                                        <th class="text-end">Returns</th>
                                        <th class="text-end">Damaged</th>
                                        <th class="text-end">Closing</th>
                                        <th class="text-end">Next Day Opening</th>
                                        <th class="text-end">Stock Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dailyReport)): ?>
                                        <tr>
                                            <td colspan="13" class="text-center py-4 text-muted">No stock analysis data available for this date.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($dailyReport as $row):
                                            $pid = intval($row['product_id']);
                                            $opening = floatval($row['opening_stock'] ?? 0);
                                            $supply = $supply_by_product[$pid] ?? 0.0;
                                            $totalStock = $opening + $supply;
                                            $dispatch = abs($dispatch_by_product[$pid] ?? 0.0) + ($shop_sales_by_product[$pid] ?? 0.0);
                                            $dispatcher_sales = $dispatcher_sales_by_product[$pid] ?? 0.0;
                                            $shop_sales = $shop_sales_by_product[$pid] ?? 0.0;
                                            $sales = $sales_by_product[$pid] ?? 0.0;
                                            $returns = $returns_by_product[$pid] ?? 0.0;
                                            $badUnits = floatval($badUnitsByProduct[$pid] ?? 0.0);
                                            $actualClosing = floatval($row['closing_stock'] ?? 0);
                                            $expectedClosing = max(0.0, $opening + $supply - $sales - $badUnits);
                                            $balance = $actualClosing - $expectedClosing;
                                            $isBalanced = abs($balance) < 0.01;
                                            $balanceRemark = $isBalanced ? 'Balanced' : 'Unbalanced (Diff: ' . number_format($balance, 2) . ')';
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['product_name'] ?? 'Unknown'); ?></td>
                                                <td class="text-end"><?php echo number_format($opening, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($supply, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($totalStock, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($dispatch, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($dispatcher_sales, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($shop_sales, 2); ?></td>
                                                <td class="text-end fw-semibold"><?php echo number_format($sales, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($returns, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($badUnits, 2); ?></td>
                                                <td class="text-end fw-semibold"><?php echo number_format($actualClosing, 2); ?></td>
                                                <td class="text-end text-primary fw-semibold"><?php echo number_format($actualClosing, 2); ?></td>
                                                <td class="text-end">
                                                    <span class="badge <?php echo $isBalanced ? 'bg-success' : 'bg-danger'; ?> bg-opacity-75">
                                                        <?php echo $balanceRemark; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php
                                        // Calculate totals for each column
                                        $totalOpening = 0.0;
                                        $totalSupply = 0.0;
                                        $totalStockSum = 0.0;
                                        $totalDispatch = 0.0;
                                        $totalDispatcherSales = 0.0;
                                        $totalShopSales = 0.0;
                                        $totalNetSales = 0.0;
                                        $totalReturns = 0.0;
                                        $totalDamaged = 0.0;
                                        $totalClosing = 0.0;

                                        foreach ($dailyReport as $row) {
                                            $pid = intval($row['product_id']);
                                            $opening = floatval($row['opening_stock'] ?? 0);
                                            $supply = $supply_by_product[$pid] ?? 0.0;
                                            $totalStock = $opening + $supply;
                                            $dispatch = abs($dispatch_by_product[$pid] ?? 0.0) + ($shop_sales_by_product[$pid] ?? 0.0);
                                            $dispatcher_sales = $dispatcher_sales_by_product[$pid] ?? 0.0;
                                            $shop_sales = $shop_sales_by_product[$pid] ?? 0.0;
                                            $sales = $sales_by_product[$pid] ?? 0.0;
                                            $returns = $returns_by_product[$pid] ?? 0.0;
                                            $badUnits = floatval($badUnitsByProduct[$pid] ?? 0.0);
                                            $closing = max(0.0, $opening + $supply - $sales - $badUnits);

                                            $totalOpening += $opening;
                                            $totalSupply += $supply;
                                            $totalStockSum += $totalStock;
                                            $totalDispatch += $dispatch;
                                            $totalDispatcherSales += $dispatcher_sales;
                                            $totalShopSales += $shop_sales;
                                            $totalNetSales += $sales;
                                            $totalReturns += $returns;
                                            $totalDamaged += $badUnits;
                                            $totalClosing += $closing;
                                        }
                                        ?>
                                        <tr class="table-primary fw-bold">
                                            <td>TOTALS</td>
                                            <td class="text-end"><?php echo number_format($totalOpening, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalSupply, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalStockSum, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalDispatch, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalDispatcherSales, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalShopSales, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalNetSales, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalReturns, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalDamaged, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalActualClosing, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($totalActualClosing, 2); ?></td>
                                            <td class="text-end">-</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/offline-handler.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/app.js"></script>
</body>
</html>

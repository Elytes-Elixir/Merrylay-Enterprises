<?php
// Stock Summary Dashboard Page
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

// Calculate dispatch from dispatch_items and shop_sales_items (same as analysis page)
$dispatch_by_product = [];
try {
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
} catch (Exception $e) {
    $dispatch_by_product = [];
}

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

// Calculate returns from stock_movements (same as analysis page - using SUM not ABS)
$returns_by_product = [];
try {
    $stmt = executeQuery(
        "SELECT product_id, COALESCE(SUM(quantity_change), 0) AS return_qty
         FROM stock_movements
         WHERE movement_date = ?
           AND movement_type = 'return'
         GROUP BY product_id",
        [$date]
    );
    foreach ($stmt->fetchAll() as $row) {
        $returns_by_product[intval($row['product_id'])] = floatval($row['return_qty']);
    }
} catch (Exception $e) {
    $returns_by_product = [];
}

// Calculate total dispatched (dispatch + shop sales)
$dispatch_total_by_product = [];
foreach ($dispatch_by_product as $pid => $dispatch_qty) {
    $shop_sales = $shop_sales_by_product[$pid] ?? 0.0;
    $dispatch_total_by_product[$pid] = $dispatch_qty + $shop_sales;
}
foreach ($shop_sales_by_product as $pid => $shop_qty) {
    if (!isset($dispatch_total_by_product[$pid])) {
        $dispatch_total_by_product[$pid] = $shop_qty;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stock Summary - Merrylay Enterprises</title>
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
                <a href="summary.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Stock Summary</span>
                </a>
                <a href="analysis.php" class="nav-link">
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
                            <li class="breadcrumb-item active" aria-current="page">Summary</li>
                        </ol>
                    </nav>
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h1 class="page-title">
                                <i class="fas fa-chart-bar" style="margin-right: 15px;"></i>
                                Stock Summary
                            </h1>
                            <p class="page-subtitle">Review opening, dispatched, returned and remaining stock</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <label class="visually-hidden" for="datePicker">Summary date</label>
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
                        window.location.href = 'summary.php?date=' + datePicker.value;
                    }
                }
            </script>
                <?php
                $totalProducts = count($dailyReport);
                $totalDispatched = 0.0;
                $totalReturned = 0.0;
                $totalRemaining = 0.0;
                foreach ($dailyReport as $row) {
                    $pid = intval($row['product_id']);
                    $opening = floatval($row['opening_stock'] ?? 0);
                    $totalDispatched += $dispatch_total_by_product[$pid] ?? 0.0;
                    $totalReturned += $returns_by_product[$pid] ?? 0.0;
                    $totalRemaining += floatval($row['closing_stock'] ?? 0);
                }
                ?>

            <div class="row g-3 mb-4 summary-header">
                    <div class="col-md-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Products</span>
                                <i class="fas fa-boxes text-primary"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalProducts); ?></h2>
                            <p class="text-muted mb-0">Tracked products today</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Dispatched</span>
                                <i class="fas fa-truck-fast text-success"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalDispatched, 2); ?></h2>
                            <p class="text-muted mb-0">Units dispatched</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card p-4 h-100 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted text-uppercase small">Remaining</span>
                                <i class="fas fa-layer-group text-warning"></i>
                            </div>
                            <h2 class="mb-2"><?php echo number_format($totalRemaining, 2); ?></h2>
                            <p class="text-muted mb-0">Stock remaining</p>
                        </div>
                    </div>
                </div>

                <div class="content-card bg-white shadow-sm rounded-4 border-0">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 border-bottom pb-3">
                        <div>
                            <h5 class="mb-1">Daily Stock Breakdown</h5>
                            <p class="text-muted mb-0"><?php echo date('F j, Y', strtotime($date)); ?></p>
                        </div>
                        <form class="d-flex align-items-center gap-2 stock-page-print-hide" method="GET" action="summary.php">
                            <label class="visually-hidden" for="summaryTableDate">Breakdown date</label>
                            <input type="date" id="summaryTableDate" name="date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date); ?>">
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
                                        <th class="text-end">Dispatch</th>
                                        <th class="text-end">Shop Sales</th>
                                        <th class="text-end">Total Dispatched</th>
                                        <th class="text-end">Returned</th>
                                        <th class="text-end">Remaining</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dailyReport)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">No stock summary available for this date.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($dailyReport as $row):
                                            $pid = intval($row['product_id']);
                                            $opening = floatval($row['opening_stock'] ?? 0);
                                            $dispatch = $dispatch_by_product[$pid] ?? 0.0;
                                            $shop_sales = $shop_sales_by_product[$pid] ?? 0.0;
                                            $total_dispatched = $dispatch_total_by_product[$pid] ?? 0.0;
                                            $returned = $returns_by_product[$pid] ?? 0.0;
                                            $closing = floatval($row['closing_stock'] ?? 0);
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['product_name'] ?? 'Unknown'); ?></td>
                                                <td class="text-end"><?php echo number_format($opening, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($dispatch, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($shop_sales, 2); ?></td>
                                                <td class="text-end fw-semibold"><?php echo number_format($total_dispatched, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($returned, 2); ?></td>
                                                <td class="text-end fw-semibold"><?php echo number_format($closing, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
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

    <script>
        // Sidebar toggle functionality
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
    </script>
</body>
</html>

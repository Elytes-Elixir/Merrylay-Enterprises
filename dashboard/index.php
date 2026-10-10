<?php
/**
 * Simplified Dashboard - Manual Refresh Only
 * Replaces the previous auto-refresh dashboard to avoid real-time issues.
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

// Ensure stock stats variable exists to avoid undefined notices
$stock_stats = [];

// Simple queries executed on page load (no aggressive caching)
try {
    $today = date('Y-m-d');

    $stmt = executeQuery("SELECT COUNT(*) as cnt FROM products");
    $total_products = intval($stmt->fetch()['cnt'] ?? 0);

    $stmt = executeQuery("SELECT COUNT(*) as cnt FROM products p LEFT JOIN daily_stock ds ON p.id = ds.product_id AND ds.stock_date = ? WHERE COALESCE(ds.closing_stock, p.quantity) <= p.reorder_level", [$today]);
    $low_stock = intval($stmt->fetch()['cnt'] ?? 0);

    // Sales: combine shop_sales and daily_activities totals for today
    $stmt = executeQuery("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as count FROM shop_sales WHERE sale_date = ?", [$today]);
    $shop_sales_today = $stmt->fetch();
    $shop_sales_today['total'] = floatval($shop_sales_today['total'] ?? 0);
    $shop_sales_today['count'] = intval($shop_sales_today['count'] ?? 0);

    $stmt = executeQuery("SELECT COALESCE(SUM(cash_collected + transfer_amount + credit_settlement),0) as total, COUNT(*) as count FROM daily_activities WHERE activity_date = ?", [$today]);
    $field_sales_today = $stmt->fetch();
    $field_sales_today['total'] = floatval($field_sales_today['total'] ?? 0);
    $field_sales_today['count'] = intval($field_sales_today['count'] ?? 0);

    // Numeric total used in the small card and a summary array used by the detailed panel
    $total_sales_numeric = $shop_sales_today['total'] + $field_sales_today['total'];
    $total_sales_summary = [
        'total' => $total_sales_numeric,
        'count' => $shop_sales_today['count'] + $field_sales_today['count']
    ];

    // Stock stats: totals and low/out of stock counts
    $countQuery = "SELECT 
        COALESCE(SUM(COALESCE(ds.closing_stock, p.quantity) * p.cost_price), 0) as inventory_value,
        COALESCE(SUM(COALESCE(ds.closing_stock, p.quantity)), 0) as total_quantity,
        COALESCE(SUM(CASE WHEN COALESCE(ds.closing_stock, p.quantity) <= p.reorder_level AND COALESCE(ds.closing_stock, p.quantity) > 0 THEN 1 ELSE 0 END), 0) as low_stock_count,
        COALESCE(SUM(CASE WHEN COALESCE(ds.closing_stock, p.quantity) = 0 THEN 1 ELSE 0 END), 0) as out_of_stock_count
    FROM products p
    LEFT JOIN daily_stock ds ON p.id = ds.product_id AND ds.stock_date = ?";
    $stmt = executeQuery($countQuery, [$today]);
    $stock_stats = $stmt->fetch();
    if (!$stock_stats) $stock_stats = [];

    // All products list for the table (limit to avoid heavy pages)
    $stmt = executeQuery("SELECT p.id, p.name, p.unit, p.cost_price, p.reorder_level, COALESCE(ds.closing_stock, p.quantity) as quantity FROM products p LEFT JOIN daily_stock ds ON p.id = ds.product_id AND ds.stock_date = ? ORDER BY p.name LIMIT 250", [$today]);
    $all_products = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Dashboard load error: ' . $e->getMessage());
    $total_products = 0;
    $low_stock = 0;
    $total_sales_numeric = 0;
    $shop_sales_today = ['total' => 0, 'count' => 0];
    $field_sales_today = ['total' => 0, 'count' => 0];
    $total_sales_summary = ['total' => 0, 'count' => 0];
    $stock_stats = [];
    $all_products = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="Dashboard overview for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Dashboard - Merrylay Enterprises</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- CSS Frameworks -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- App styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="logo">
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
            $dispatcherSidebarPath = __DIR__ . '/../dispatch/components/dispatcher-sidebar.php';
            $searchDir = __DIR__;
            while (!file_exists($dispatcherSidebarPath) && dirname($searchDir) !== $searchDir) {
                $searchDir = dirname($searchDir);
                $dispatcherSidebarPath = $searchDir . '/dispatch/components/dispatcher-sidebar.php';
            }
            if (file_exists($dispatcherSidebarPath)) {
                include $dispatcherSidebarPath;
            } else {
                // Fallback sidebar navigation to keep layout consistent
                ?>
                <nav class="sidebar-nav">
                    <div class="nav-section-title overview">Main Operations</div>
                    <a href="../dashboard/index.php" class="nav-link active">
                        <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                    <a href="../products/view.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                        <span class="nav-text">Products</span>
                    </a>
                    <a href="../shop/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-store"></i></span>
                        <span class="nav-text">Shop Sales</span>
                    </a>
                    <?php if (canRecordDispatches()): ?>
                    <div class="nav-section-title dispatch">Dispatch Operations</div>
                    <a href="../dispatch/trips/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-list"></i></span>
                        <span class="nav-text">Manage Dispatches</span>
                    </a>
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
                    <?php endif; ?>
                </nav>
                <?php
            }
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
                        <input type="text" placeholder="Search products, sales, dispatches..." id="globalSearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <button class="icon-btn position-relative" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($low_stock > 0): ?>
                                <span class="notification-dot"></span>
                            <?php endif; ?>
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

            <div class="container py-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4">Dashboard</h1>
                </div>

                <div class="stats-grid mb-4">
                    <div class="stat-card p-3 d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-boxes fa-2x text-primary"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($total_products); ?></div>
                            <div class="stat-label">Total Products</div>
                        </div>
                    </div>
                    <div class="stat-card p-3 d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-layer-group fa-2x text-info"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($stock_stats['total_quantity'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Stock Units</div>
                        </div>
                    </div>
                    <div class="stat-card p-3 d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value text-warning"><?php echo number_format($low_stock); ?></div>
                            <div class="stat-label">Low Stock Items</div>
                        </div>
                    </div>
                    <div class="stat-card p-3 d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-chart-line fa-2x text-success"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value text-success">₦<?php echo number_format($total_sales_numeric ?? 0,2); ?></div>
                            <div class="stat-label">Sales Today</div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0">Product & Stock Details</h5>
                                <small class="text-muted">Live inventory snapshot for all products.</small>
                            </div>
                            <span class="badge bg-secondary">Showing <?php echo count($all_products); ?> products</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-clean table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Unit</th>
                                        <th class="text-center">Stock</th>
                                        <th class="text-center">Reorder Level</th>
                                        <th class="text-center">Cost Price</th>
                                        <th class="text-center">Stock Value</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_products)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">No products available.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_products as $product): ?>
                                            <?php
                                                $quantity = floatval($product['quantity'] ?? 0);
                                                $reorder = intval($product['reorder_level'] ?? 0);
                                                $stockValue = $quantity * floatval($product['cost_price'] ?? 0);
                                                if ($quantity <= 0) {
                                                    $statusClass = 'badge bg-danger';
                                                    $statusLabel = 'Out of Stock';
                                                } elseif ($quantity <= $reorder) {
                                                    $statusClass = 'badge bg-warning text-dark';
                                                    $statusLabel = 'Low Stock';
                                                } else {
                                                    $statusClass = 'badge bg-success';
                                                    $statusLabel = 'In Stock';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['name'] ?? ''); ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($product['unit'] ?? ''); ?></td>
                                                <td class="text-center"><?php echo number_format($quantity, 2); ?></td>
                                                <td class="text-center"><?php echo number_format($reorder); ?></td>
                                                <td class="text-center"><?php echo number_format(floatval($product['cost_price'] ?? 0), 2); ?></td>
                                                <td class="text-center"><?php echo number_format($stockValue, 2); ?></td>
                                                <td class="text-center"><span class="<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <p class="text-muted">This dashboard does not auto-refresh. Press F5 to refresh the page and update data.</p>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js" defer></script>
    <script src="../assets/js/app.js" defer></script>
    <script src="../assets/js/offline-handler.js" defer></script>
    <script src="../assets/js/clean-dashboard.js" defer></script>
</body>
</html>


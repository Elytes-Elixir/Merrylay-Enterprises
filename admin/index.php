<?php
/**
 * Admin Dashboard - Real-time Stock Statistics
 *
 * Shows comprehensive stock tracking from opening to closing throughout the day
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard/index.php');
    exit();
}

require_once '../init.php';
require_once '../config/db.php';
require_once '../config/stock_manager.php';
require_once '../config/cache.php';

$stockManager = getStockManager();
$today = date('Y-m-d');
$error = '';
$success = '';

// Get real-time stock statistics
function getStockStatistics($date) {
    global $pdo;

    $stats = [
        'opening_stock' => 0,
        'total_dispatched' => 0,
        'total_sold' => 0,
        'total_returned' => 0,
        'closing_stock' => 0,
        'stock_value' => 0,
        'last_updated' => null
    ];

    try {
        // Opening stock (sum of all opening stocks for the day)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(opening_stock), 0) as total
            FROM daily_stock
            WHERE stock_date = ?
        ");
        $stmt->execute([$date]);
        $stats['opening_stock'] = $stmt->fetch()['total'];

        // Total dispatched (from stock movements)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ABS(quantity_change)), 0) as total
            FROM stock_movements
            WHERE movement_date = ? AND movement_type = 'dispatch'
        ");
        $stmt->execute([$date]);
        $stats['total_dispatched'] = $stmt->fetch()['total'];

        // Total sold (from daily activities - this represents actual sales)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(cash_collected + transfer_amount + credit_amount), 0) as total
            FROM daily_activities
            WHERE activity_date = ?
        ");
        $stmt->execute([$date]);
        $stats['total_sold'] = $stmt->fetch()['total'];

        // Total returned (from stock movements)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ABS(quantity_change)), 0) as total
            FROM stock_movements
            WHERE movement_date = ? AND movement_type = 'return'
        ");
        $stmt->execute([$date]);
        $stats['total_returned'] = $stmt->fetch()['total'];

        // Closing stock (sum of all closing stocks for the day)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(closing_stock), 0) as total
            FROM daily_stock
            WHERE stock_date = ?
        ");
        $stmt->execute([$date]);
        $stats['closing_stock'] = $stmt->fetch()['total'];

        // Stock value (closing stock × selling price)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ds.closing_stock * p.selling_price), 0) as value
            FROM daily_stock ds
            JOIN products p ON ds.product_id = p.id
            WHERE ds.stock_date = ?
        ");
        $stmt->execute([$date]);
        $stats['stock_value'] = $stmt->fetch()['value'];

        // Last updated timestamp
        $stmt = $pdo->prepare("
            SELECT MAX(created_at) as last_updated
            FROM (
                SELECT created_at FROM stock_movements WHERE movement_date = ?
                UNION
                SELECT recorded_at FROM daily_activities WHERE activity_date = ?
                UNION
                SELECT updated_at FROM daily_stock WHERE stock_date = ?
            ) as combined
        ");
        $stmt->execute([$date, $date, $date]);
        $stats['last_updated'] = $stmt->fetch()['last_updated'];

    } catch (Exception $e) {
        error_log("Stock statistics error: " . $e->getMessage());
    }

    return $stats;
}

// Get current stock statistics
$stockStats = getStockStatistics($today);

// Get recent stock movements for the activity feed
$recentMovements = [];
try {
    $stmt = executeQuery("
        SELECT sm.*, p.name as product_name, p.selling_price
        FROM stock_movements sm
        JOIN products p ON sm.product_id = p.id
        WHERE sm.movement_date = ?
        ORDER BY sm.created_at DESC
        LIMIT 10
    ", [$today]);
    $recentMovements = $stmt->fetchAll();
} catch (Exception $e) {
    $recentMovements = [];
}

// Get stock status (initialized/closed)
$stockStatus = 'not_initialized';
try {
    $stmt = executeQuery("SELECT COUNT(*) as count FROM daily_stock WHERE stock_date = ?", [$today]);
    $stockCount = $stmt->fetch()['count'];

    if ($stockCount > 0) {
        $stmt = executeQuery("SELECT COUNT(*) as count FROM daily_stock WHERE stock_date = ? AND closing_stock IS NOT NULL", [$today]);
        $closedCount = $stmt->fetch()['count'];

        if ($closedCount > 0) {
            $stockStatus = 'closed';
        } else {
            $stockStatus = 'active';
        }
    }
} catch (Exception $e) {
    $stockStatus = 'error';
}

// Handle AJAX requests for real-time updates
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    echo json_encode([
        'stats' => $stockStats,
        'status' => $stockStatus,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Statistics - Admin Dashboard</title>

    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/fonts/inter/inter.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/unified-design-system.css" rel="stylesheet">

    <style>
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
            transition: transform 0.2s ease;
        }

        .stats-card:hover {
            transform: translateY(-2px);
        }

        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }

        .stats-label {
            color: #718096;
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stats-change {
            display: flex;
            align-items: center;
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }

        .stats-change.positive {
            color: #38a169;
        }

        .stats-change.negative {
            color: #e53e3e;
        }

        .stats-change.neutral {
            color: #718096;
        }

        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .activity-icon.dispatch {
            background: #3182ce;
            color: white;
        }

        .activity-icon.return {
            background: #38a169;
            color: white;
        }

        .activity-icon.sale {
            background: #805ad5;
            color: white;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-closed {
            background: #fed7d7;
            color: #742a2a;
        }

        .status-not-initialized {
            background: #e2e8f0;
            color: #2d3748;
        }

        .realtime-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #38a169;
            font-size: 0.875rem;
        }

        .realtime-indicator::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #38a169;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/stock-pages.css" rel="stylesheet">
</head>
<body class="stock-ui stock-ui-admin-stats">
    <div class="dashboard-container">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../dashboard/index.php" class="logo">
                    <div class="logo-icon"><i class="fas fa-store"></i></div>
                    <span class="logo-text">Merrylay Enterprises</span>
                </a>
                <button type="button" class="toggle-btn" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <nav class="sidebar-nav" aria-label="Main navigation">
                <div class="nav-section-title overview">Main Operations</div>
                <a href="../dashboard/index.php" class="nav-link"><span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span><span class="nav-text">Dashboard</span></a>
                <a href="../products/view.php" class="nav-link"><span class="nav-icon"><i class="fas fa-boxes"></i></span><span class="nav-text">Current Stock</span></a>
                <div class="nav-section-title stock">Stock Management</div>
                <a href="../stock/manage.php" class="nav-link"><span class="nav-icon"><i class="fas fa-warehouse"></i></span><span class="nav-text">Daily Stock</span></a>
                <a href="index.php" class="nav-link active" aria-current="page"><span class="nav-icon"><i class="fas fa-chart-pie"></i></span><span class="nav-text">Stock Statistics</span></a>
                <a href="../stock/summary.php" class="nav-link"><span class="nav-icon"><i class="fas fa-chart-bar"></i></span><span class="nav-text">Stock Summary</span></a>
                <a href="../stock/analysis.php" class="nav-link"><span class="nav-icon"><i class="fas fa-chart-line"></i></span><span class="nav-text">Stock Analysis</span></a>
                <a href="../stock/supplies.php" class="nav-link"><span class="nav-icon"><i class="fas fa-truck-loading"></i></span><span class="nav-text">Supplies</span></a>
                <a href="../stock/initial_setup.php" class="nav-link"><span class="nav-icon"><i class="fas fa-play-circle"></i></span><span class="nav-text">Opening Baseline</span></a>
                <a href="end-of-day.php" class="nav-link"><span class="nav-icon"><i class="fas fa-calendar-check"></i></span><span class="nav-text">End of Day</span></a>
                <a href="../auth/logout.php" class="nav-link"><span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span><span class="nav-text">Logout</span></a>
            </nav>
        </aside>
        <main class="main-content" id="mainContent">
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="search" id="globalSearch" aria-label="Search stock statistics" placeholder="Search stock statistics...">
                    </div>
                </div>
                <div class="top-bar-actions">
                    <span class="text-muted">Administrator</span>
                </div>
            </header>
            <div class="dashboard-content">
    <div class="stock-admin-content container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <nav aria-label="Breadcrumb">
                            <ol class="breadcrumb stock-breadcrumb">
                                <li class="breadcrumb-item"><a href="../dashboard/index.php">Dashboard</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Stock statistics</li>
                            </ol>
                        </nav>
                        <h1 class="h3 mb-1">Stock Statistics Dashboard</h1>
                        <p class="text-muted mb-0">Real-time stock tracking for <?php echo date('F j, Y', strtotime($today)); ?></p>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="realtime-indicator">
                            <span>Live Updates</span>
                        </div>
                        <div class="status-indicator status-<?php echo $stockStatus; ?>">
                            <i class="fas fa-circle"></i>
                            <?php
                            switch($stockStatus) {
                                case 'active': echo 'Stock Active'; break;
                                case 'closed': echo 'Stock Closed'; break;
                                case 'not_initialized': echo 'Not Initialized'; break;
                                default: echo 'Unknown Status';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <!-- Opening Stock -->
            <div class="col-xl-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="stats-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <i class="fas fa-box-open text-white"></i>
                        </div>
                        <div class="stats-change neutral">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stats-value"><?php echo number_format($stockStats['opening_stock']); ?></div>
                    <div class="stats-label">Opening Stock</div>
                    <div class="stats-change neutral">
                        <span>Units at start of day</span>
                    </div>
                </div>
            </div>

            <!-- Dispatched -->
            <div class="col-xl-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="stats-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <i class="fas fa-truck text-white"></i>
                        </div>
                        <div class="stats-change negative">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                    </div>
                    <div class="stats-value"><?php echo number_format($stockStats['total_dispatched']); ?></div>
                    <div class="stats-label">Dispatched</div>
                    <div class="stats-change negative">
                        <span>Units sent out</span>
                    </div>
                </div>
            </div>

            <!-- Sales -->
            <div class="col-xl-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="stats-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <i class="fas fa-shopping-cart text-white"></i>
                        </div>
                        <div class="stats-change positive">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                    </div>
                    <div class="stats-value">₦<?php echo number_format($stockStats['total_sold'], 0); ?></div>
                    <div class="stats-label">Sales Revenue</div>
                    <div class="stats-change positive">
                        <span>Revenue from sales</span>
                    </div>
                </div>
            </div>

            <!-- Closing Stock -->
            <div class="col-xl-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="stats-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                            <i class="fas fa-boxes text-white"></i>
                        </div>
                        <div class="stats-change neutral">
                            <i class="fas fa-equals"></i>
                        </div>
                    </div>
                    <div class="stats-value"><?php echo number_format($stockStats['closing_stock']); ?></div>
                    <div class="stats-label">Closing Stock</div>
                    <div class="stats-change neutral">
                        <span>Units remaining</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card border-warning h-100">
                    <div class="card-body d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center mb-3">
                                <div class="stats-icon" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);">
                                    <i class="fas fa-balance-scale text-white"></i>
                                </div>
                                <div class="ms-3">
                                    <h5 class="mb-1">Daily Account Balance</h5>
                                    <p class="text-muted mb-0">Quick access to verify whether the accounts are balanced for today.</p>
                                </div>
                            </div>
                            <p class="mb-0 text-muted">This is the fastest route for the business owner to confirm daily reconciliation and account balance status.</p>
                        </div>
                        <div class="mt-4">
                            <a href="../finance/daily_balance.php" class="btn btn-warning btn-lg">
                                <i class="fas fa-arrow-right me-2"></i>Open Daily Balance Checker
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Statistics -->
        <div class="row g-4 mb-4">
            <!-- Returns -->
            <div class="col-xl-4 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="stats-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                            <i class="fas fa-undo text-white"></i>
                        </div>
                        <div class="stats-change positive">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                    </div>
                    <div class="stats-value"><?php echo number_format($stockStats['total_returned']); ?></div>
                    <div class="stats-label">Returns</div>
                    <div class="stats-change positive">
                        <span>Units returned to stock</span>
                    </div>
                </div>
            </div>

            <!-- Stock Value -->
            <div class="col-xl-4 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="stats-icon" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);">
                            <i class="fas fa-dollar-sign text-white"></i>
                        </div>
                        <div class="stats-change neutral">
                            <i class="fas fa-calculator"></i>
                        </div>
                    </div>
                    <div class="stats-value">₦<?php echo number_format($stockStats['stock_value'], 0); ?></div>
                    <div class="stats-label">Stock Value</div>
                    <div class="stats-change neutral">
                        <span>Current stock worth</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity Feed and Details -->
        <div class="row g-4">
            <!-- Recent Activity -->
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history me-2"></i>Recent Stock Activity
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="activity-feed">
                            <?php if (empty($recentMovements)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-3"></i>
                                <p>No stock movements recorded today yet.</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($recentMovements as $movement): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $movement['movement_type']; ?>">
                                    <i class="fas fa-<?php
                                        switch($movement['movement_type']) {
                                            case 'dispatch': echo 'truck'; break;
                                            case 'return': echo 'undo'; break;
                                            case 'adjustment': echo 'tools'; break;
                                            case 'sale': echo 'shopping-cart'; break;
                                            default: echo 'circle';
                                        }
                                    ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($movement['product_name']); ?></div>
                                    <small class="text-muted">
                                        <?php echo ucfirst($movement['movement_type']); ?>:
                                        <?php echo $movement['quantity_change'] > 0 ? '+' : ''; ?><?php echo $movement['quantity_change']; ?> units
                                        <?php if ($movement['reference_number']): ?>
                                        (<?php echo htmlspecialchars($movement['reference_number']); ?>)
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">
                                        <?php echo date('H:i', strtotime($movement['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Summary -->
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Daily Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Opening Stock</span>
                                <span class="fw-semibold"><?php echo number_format($stockStats['opening_stock']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">+ Returns</span>
                                <span class="fw-semibold text-success">+<?php echo number_format($stockStats['total_returned']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">+ Adjustments</span>
                                <span class="fw-semibold <?php echo $stockStats['adjustments'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $stockStats['adjustments'] >= 0 ? '+' : ''; ?><?php echo number_format($stockStats['adjustments']); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">- Dispatched</span>
                                <span class="fw-semibold text-danger">-<?php echo number_format($stockStats['total_dispatched']); ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">Closing Stock</span>
                                <span class="fw-bold"><?php echo number_format($stockStats['closing_stock']); ?></span>
                            </div>
                        </div>

                        <?php if ($stockStats['last_updated']): ?>
                        <div class="text-center">
                            <small class="text-muted">
                                Last updated: <?php echo date('M j, H:i', strtotime($stockStats['last_updated'])); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
            </div>
        </main>
    </div>

    <!-- Real-time Updates Script -->
    <script>
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
        });

        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });

        // Auto-refresh stock statistics every 30 seconds
        function updateStockStats() {
            fetch('<?php echo rtrim(BASE_URL, '/'); ?>/admin/index.php?ajax=stats&t=' + Date.now())
                .then(response => response.json())
                .then(data => {
                    // Update main statistics cards
                    updateStatCard('.stats-card:nth-child(1) .stats-value', data.stats.opening_stock);
                    updateStatCard('.stats-card:nth-child(2) .stats-value', data.stats.total_dispatched);
                    updateStatCard('.stats-card:nth-child(3) .stats-value', '₦' + formatNumber(data.stats.total_sold));
                    updateStatCard('.stats-card:nth-child(4) .stats-value', data.stats.closing_stock);

                    // Update additional stats
                    updateStatCard('.stats-card:nth-child(5) .stats-value', data.stats.total_returned);
                    updateStatCard('.stats-card:nth-child(6) .stats-value',
                        (data.stats.adjustments >= 0 ? '+' : '') + formatNumber(data.stats.adjustments));
                    updateStatCard('.stats-card:nth-child(7) .stats-value', '₦' + formatNumber(data.stats.stock_value));

                    // Update status indicator
                    updateStatusIndicator(data.status);

                    console.log('Stock stats updated at ' + data.timestamp);
                })
                .catch(error => {
                    console.error('Failed to update stock stats:', error);
                });
        }

        function updateStatCard(selector, value) {
            const element = document.querySelector(selector);
            if (element) {
                // Add animation effect
                element.style.transition = 'all 0.3s ease';
                element.style.transform = 'scale(1.05)';
                element.textContent = value;

                setTimeout(() => {
                    element.style.transform = 'scale(1)';
                }, 300);
            }
        }

        function updateStatusIndicator(status) {
            const indicator = document.querySelector('.status-indicator');
            if (indicator) {
                // Remove existing status classes
                indicator.classList.remove('status-active', 'status-closed', 'status-not-initialized');

                // Add new status class
                indicator.classList.add('status-' + status);

                // Update text
                const textElement = indicator.querySelector('span:last-child') || indicator;
                switch(status) {
                    case 'active':
                        textElement.textContent = 'Stock Active';
                        break;
                    case 'closed':
                        textElement.textContent = 'Stock Closed';
                        break;
                    case 'not_initialized':
                        textElement.textContent = 'Not Initialized';
                        break;
                }
            }
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('en-US').format(num);
        }

        // Start auto-refresh
        setInterval(updateStockStats, 30000); // Update every 30 seconds

        // Initial update
        updateStockStats();
    </script>
    <?php require_once __DIR__ . '/../components/footer.php'; ?>
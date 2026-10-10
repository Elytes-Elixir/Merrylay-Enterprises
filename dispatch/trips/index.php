<?php
/**
 * Dispatch Management Dashboard
 * 
 * For managing dispatches for vehicles
 * Non-shop attendant users create dispatches by selecting dispatchers, adding products, and recording dispatch
 * Shop attendants should use the direct sales system instead
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../init.php';
require_once '../../config/db.php';
require_once '../../config/cache.php';
require_once '../../config/auth.php';

// Restrict access - shop attendants should use direct sales system
if (isShopAttendant()) {
    header('Location: ../../shop/index.php');
    exit();
}

$today = date('Y-m-d');

// Get today's summary
$summary = ['trips' => 0, 'units' => 0, 'expected' => 0];
try {
    $stmt = executeQuery("
        SELECT 
            COUNT(DISTINCT d.id) as trips,
            COALESCE(SUM(di.quantity), 0) as units,
            COALESCE(SUM(di.quantity * di.unit_price), 0) as expected
        FROM dispatches d
        LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
        WHERE DATE(d.dispatch_date) = ?
                    AND COALESCE(d.status, '') <> 'cancelled'
    ", [$today]);
    $summary = $stmt->fetch();
} catch (PDOException $e) {
    // Use defaults
}

// Get recent trips
$recent_trips = [];
try {
    $stmt = executeQuery("
        SELECT d.*, SUM(di.quantity) as total_units
        FROM dispatches d
        LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
        WHERE DATE(d.dispatch_date) = ?
                    AND COALESCE(d.status, '') <> 'cancelled'
        GROUP BY d.id
        ORDER BY d.created_at DESC
        LIMIT 5
    ", [$today]);
    $recent_trips = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_trips = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dispatch management dashboard for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Manage Dispatches - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
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
                        <input type="text" placeholder="Search dispatches, dispatchers, products..." id="globalSearch">
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
                                <li><a class="dropdown-item" href="../../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <li><a class="dropdown-item" href="../../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
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
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-list" style="margin-right: 15px;"></i>
                        Manage Dispatches
                    </h1>
                    <p class="page-subtitle">Create and manage dispatches - select dispatcher, add products, and record dispatch</p>
                </div>
                <!-- Quick Actions -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Quick Actions</h5>
                            <p class="card-subtitle">Common dispatch management tasks</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="action-buttons">
                            <a href="record.php" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> New Dispatch
                            </a>
                            <a href="list.php" class="btn btn-success">
                                <i class="fas fa-list"></i> View All Dispatches
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Today's Summary -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($summary['trips']); ?></div>
                        <div class="stat-label">Today's Dispatches</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($summary['units'], 2); ?></div>
                        <div class="stat-label">Total Units Dispatched</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($summary['expected'], 2); ?></div>
                        <div class="stat-label">Expected Amount</div>
                    </div>
                </div>

                <!-- Recent Trips -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Recent Dispatches Today</h5>
                            <p class="card-subtitle">Latest dispatches recorded today</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_trips)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <h6>No dispatches recorded today</h6>
                                <p>Start recording dispatches to see activity here</p>
                                <a href="record.php" class="btn btn-primary">
                                    <i class="fas fa-plus-circle"></i> Record First Dispatch
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-clean">
                                    <thead>
                                        <tr>
                                            <th>Dispatch Number</th>
                                            <th>Dispatcher</th>
                                            <th>Units</th>
                                            <th>Expected Amount</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_trips as $trip): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($trip['dispatch_number']); ?></strong>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="user-avatar" style="width: 28px; height: 28px; font-size: 0.625rem;">
                                                            <?php echo strtoupper(substr($trip['dispatcher_name'] ?? 'NA', 0, 2)); ?>
                                                        </div>
                                                        <?php echo htmlspecialchars($trip['dispatcher_name']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo $trip['total_units']; ?> units</span>
                                                </td>
                                                <td>
                                                    <strong>₦<?php echo number_format($trip['expected_amount'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <div class="text-muted small">
                                                        <?php echo date('h:i A', strtotime($trip['created_at'])); ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4">
                                <a href="list.php" class="btn btn-outline-primary">
                                    <i class="fas fa-list"></i> View All Dispatches
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/app.js"></script>
    
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

        // Global search functionality
        document.getElementById('globalSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            if (searchTerm.length > 2) {
                // Implement search functionality as needed
                console.log('Searching for:', searchTerm);
            }
        });
    </script>
</body>
</html>


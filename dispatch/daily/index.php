<?php
/**
 * Daily Activities Dashboard
 * 
 * Central hub for recording and viewing daily driver activities.
 */

require_once __DIR__ . '/../../config/session.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';
$deleteActivityCsrfToken = hasRole(['admin']) ? SecurityUtils::generateCSRFToken() : '';

// Get selected date from query parameter or default to today
$selected_date = $_GET['date'] ?? date('Y-m-d');
$today = date('Y-m-d');

// Get today's stats - unified business totals
$stats = [
    'drivers_active' => 0,
    'total_cash' => 0,
    'total_transfer' => 0,
    'total_credit' => 0,
    'total_expenses' => 0,
    'total_returns' => 0,
    'total_sales_count' => 0,
    'total_revenue' => 0
];

try {
    // Get dispatch/driver activities
    $stmt = executeQuery("
        SELECT
            COUNT(DISTINCT driver_id) as drivers_active,
            COUNT(*) as activity_count,
            COALESCE(SUM(cash_collected), 0) as total_cash,
            COALESCE(SUM(transfer_amount), 0) as total_transfer,
            COALESCE(SUM(credit_amount), 0) as total_credit,
            COALESCE(SUM(expenses_amount), 0) as total_expenses
        FROM daily_activities
        WHERE activity_date = ?
    ", [$selected_date]);
    $result = $stmt->fetch();
    $stats['drivers_active'] = $result['drivers_active'];
    $stats['total_sales_count'] = $result['activity_count'];
    $stats['total_cash'] = $result['total_cash'];
    $stats['total_transfer'] = $result['total_transfer'];
    $stats['total_credit'] = $result['total_credit'];
    $stats['total_expenses'] = $result['total_expenses'];

    // Calculate initial total revenue from dispatch activities
    $stats['total_revenue'] = $stats['total_cash'] + $stats['total_transfer'] + $stats['total_credit'];
} catch (PDOException $e) {
    // Use defaults
}

// Get returns count
try {
    $stmt = executeQuery("SELECT COALESCE(SUM(quantity), 0) as total FROM daily_returns WHERE return_date = ?", [$selected_date]);
    $stats['total_returns'] = $stmt->fetch()['total'];
} catch (PDOException $e) {
    // Use default
}

// Get shop sales and add to unified totals with collections logic
try {
    // From shop_sales table with payment method breakdown (this is the primary source)
    $stmt = executeQuery("
        SELECT
            COUNT(*) as sales_count,
            COALESCE(SUM(total_amount), 0) as total_amount,
            SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END) as transfer_amount,
            SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END) as card_amount,
            COALESCE(SUM(discount_amount), 0) as discount_amount
        FROM shop_sales
        WHERE DATE(sale_date) = ?
    ", [$selected_date]);
    $shop_sales = $stmt->fetch();

    // Get shop collections from shop_collections table (separate from sales)
    $stmt = executeQuery("
        SELECT
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN collection_amount ELSE 0 END), 0) as unsettled_collections,
            COALESCE(SUM(CASE WHEN status != 'cleared' THEN balance_due ELSE 0 END), 0) as unsettled_balance,
            COALESCE(SUM(CASE WHEN status = 'cleared' THEN amount_acknowledged ELSE 0 END), 0) as settled_acknowledged
        FROM shop_collections
        WHERE DATE(collection_date) = ?
    ", [$selected_date]);
    $shop_collections_data = $stmt->fetch();

    // Calculate shop sales totals
    $shop_sales_count = $shop_sales['sales_count'] ?? 0;
    $shop_sales_amount = $shop_sales['total_amount'] ?? 0;
    $shop_sales_cash = $shop_sales['cash_amount'] ?? 0;
    $shop_sales_transfer = $shop_sales['transfer_amount'] ?? 0;
    $shop_sales_card = $shop_sales['card_amount'] ?? 0;
    $shop_sales_net_cash = $shop_sales_cash; // Use gross cash (no expense adjustments)

    // Record cash collected as is, collections tracked separately for balancing
    $stats['total_sales_count'] += $shop_sales_count;
    $stats['total_revenue'] += $shop_sales_amount;
    $stats['total_cash'] += $shop_sales_net_cash; // Add net shop cash after unsettled collections
    $stats['total_transfer'] += $shop_sales_transfer;
    $stats['total_credit'] += $shop_sales_card;

    $shop_sales_collections = $shop_collections_data['unsettled_collections'] ?? 0;

    // Debug output (remove in production)
    error_log("Shop Sales Debug - Count: $shop_sales_count, Amount: $shop_sales_amount, Cash: $shop_sales_cash, Collections: $shop_sales_collections");
} catch (PDOException $e) {
    error_log("Shop sales query error: " . $e->getMessage());
    // Use defaults
}

// Get recent activities
$recent_activities = [];
try {
    $stmt = executeQuery("
        SELECT da.*,
               (SELECT COALESCE(SUM(quantity), 0) FROM daily_returns WHERE driver_id = da.driver_id AND return_date = da.activity_date) as returns_count
        FROM daily_activities da
        WHERE da.activity_date = ?
        ORDER BY da.recorded_at DESC
        LIMIT 5
    ", [$selected_date]);
    $recent_activities = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_activities = [];
}

// Get recent shop sales
$recent_shop_sales = [];
try {
    // From shop_sales table only (primary source)
    $stmt = executeQuery("
        SELECT ss.*, u.name as attendant_name
        FROM shop_sales ss
        LEFT JOIN users u ON ss.recorded_by = u.id
        WHERE DATE(ss.sale_date) = ?
        ORDER BY ss.recorded_at DESC
        LIMIT 5
    ", [$selected_date]);
    $recent_shop_sales = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_shop_sales = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Daily sales and activities dashboard for recording and tracking driver performance">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Daily Sales & Records - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .quick-action-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .quick-action-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
            border-color: #3b82f6;
        }

        .quick-action-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .quick-action-icon.record {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .quick-action-icon.summary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .quick-action-content {
            flex: 1;
        }

        .quick-action-content h6 {
            font-weight: 600;
            font-size: 1rem;
            margin: 0 0 0.25rem 0;
            color: #111827;
        }

        .quick-action-content p {
            font-size: 0.875rem;
            color: #6b7280;
            margin: 0;
        }

        .quick-action-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #6b7280;
            transition: all 0.2s ease;
        }

        .quick-action-card:hover .quick-action-btn {
            background: #3b82f6;
            color: white;
        }

        .date-picker-container {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .date-picker-container input[type="date"] {
            max-width: 150px;
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.875rem;
        }

        .date-picker-container label {
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
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
                        <input type="text" placeholder="Search activities, drivers, sales..." id="activitySearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <a href="record.php?record_date=<?php echo urlencode($selected_date); ?>" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Record Activity
                        </a>
                        
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
                            <h1><i class="fas fa-cash-register me-3"></i>Daily Sales</h1>
                            <p>Record and track driver returns, collections, credit sales, and expenses</p>
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
                        <div class="date-picker-container">
                            <form method="GET" action="" class="d-flex align-items-center gap-2">
                                <label for="date_select" class="form-label mb-0">Select Date:</label>
                                <input type="date" id="date_select" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()">
                                <?php if ($selected_date !== $today): ?>
                                <a href="index.php" class="btn btn-sm btn-outline-secondary">Back to Today</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Today's Summary Stats -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title"><?php echo $selected_date === $today ? "Today's Summary" : date('F j, Y', strtotime($selected_date)) . ' Summary'; ?></h5>
                            <p class="card-subtitle">Daily sales and activity overview</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon blue">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-value"><?php echo $stats['drivers_active']; ?></div>
                                <div class="stat-label">Active Drivers</div>
                                <div class="stat-change positive">
                                    <i class="fas fa-arrow-up"></i> On road today
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon green">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($stats['total_cash'], 0); ?></div>
                                <div class="stat-label">Cash Collected</div>
                                <div class="stat-change positive">
                                    <i class="fas fa-arrow-up"></i> Physical payments
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon blue">
                                    <i class="fas fa-exchange-alt"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($stats['total_transfer'], 0); ?></div>
                                <div class="stat-label">Transfer</div>
                                <div class="stat-change positive">
                                    <i class="fas fa-arrow-up"></i> Digital payments
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon yellow">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($stats['total_credit'], 0); ?></div>
                                <div class="stat-label">Credit Sales</div>
                                <div class="stat-change positive">
                                    <i class="fas fa-arrow-up"></i> Credit payments
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon red">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($stats['total_expenses'], 0); ?></div>
                                <div class="stat-label">Expenses</div>
                                <div class="stat-change negative">
                                    <i class="fas fa-arrow-down"></i> Operating costs
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon purple">
                                    <i class="fas fa-undo"></i>
                                </div>
                                <div class="stat-value"><?php echo number_format($stats['total_returns']); ?></div>
                                <div class="stat-label">Units Returned</div>
                                <div class="stat-change positive">
                                    <i class="fas fa-arrow-up"></i> Product returns
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon green">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="stat-value">₦<?php echo number_format($stats['total_revenue'], 0); ?></div>
                                <div class="stat-label">Total Revenue</div>
                                <div class="stat-change positive">
                                    <i class="fas fa-arrow-up"></i> All sales combined
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon blue">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div class="stat-value"><?php echo $stats['total_sales_count']; ?></div>
                                <div class="stat-label">Total Transactions</div>
                                <div class="stat-change positive">
                                    <i class="fas fa-arrow-up"></i> Shop + Dispatch
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="quick-action-card">
                            <div class="quick-action-icon record">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <div class="quick-action-content">
                                <h6>Record Activity</h6>
                                <p>Log driver sales and expenses</p>
                            </div>
                            <a href="record.php" class="quick-action-btn">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="quick-action-card">
                            <div class="quick-action-icon summary">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <div class="quick-action-content">
                                <h6>View Summary</h6>
                                <p>Comprehensive daily report</p>
                            </div>
                            <a href="summary.php" class="quick-action-btn">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Recent Activity</h5>
                            <p class="card-subtitle">Latest driver sales records from <?php echo $selected_date === $today ? 'today' : date('F j, Y', strtotime($selected_date)); ?></p>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activities)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-clipboard-check"></i>
                                </div>
                                <h6>No records recorded <?php echo $selected_date === $today ? 'today' : 'on ' . date('F j, Y', strtotime($selected_date)); ?></h6>
                                <p>Start recording driver activities to see them here</p>
                                <a href="record.php?record_date=<?php echo urlencode($selected_date); ?>" class="btn btn-primary">
                                    <i class="fas fa-plus-circle"></i>Record First Activity
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-clean">
                                    <thead>
                                        <tr>
                                            <th>Driver</th>
                                            <th class="text-center">Cash</th>
                                            <th class="text-center">Transfer</th>
                                            <th class="text-center">Credit</th>
                                            <th class="text-center">Expenses</th>
                                            <th class="text-center">Returns</th>
                                            <th class="text-end">Time</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_activities as $activity): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($activity['driver_name']); ?></div>
                                                    <div class="text-muted small"><?php echo date('g:i A', strtotime($activity['recorded_at'])); ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-success fw-semibold">₦<?php echo number_format($activity['cash_collected'], 0); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-primary fw-semibold">₦<?php echo number_format($activity['transfer_amount'], 0); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-warning fw-semibold">₦<?php echo number_format($activity['credit_amount'], 0); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-danger fw-semibold">₦<?php echo number_format($activity['expenses_amount'], 0); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-info"><?php echo $activity['returns_count']; ?> units</span>
                                                </td>
                                                <td class="text-end text-muted small">
                                                    <?php echo date('H:i', strtotime($activity['recorded_at'])); ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#detailsModal" onclick="showActivityDetails(<?php echo htmlspecialchars(json_encode($activity)); ?>)">
                                                            <i class="fas fa-eye me-1"></i>View
                                                        </button>
                                                        <a href="record.php?activity_id=<?php echo $activity['id']; ?>&record_date=<?php echo urlencode($selected_date); ?>" class="btn btn-sm btn-outline-warning" title="Edit Record">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if (hasRole(['admin'])): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteActivity(<?php echo $activity['id']; ?>)">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4">
                                <a href="summary.php?date=<?php echo urlencode($selected_date); ?>" class="btn btn-primary">
                                    <i class="fas fa-chart-pie me-2"></i>View Full Summary
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Shop Sales -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Recent Shop Sales</h5>
                            <p class="card-subtitle">Latest shop sales transactions from <?php echo $selected_date === $today ? 'today' : date('F j, Y', strtotime($selected_date)); ?></p>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_shop_sales)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <h6>No shop sales recorded <?php echo $selected_date === $today ? 'today' : 'on ' . date('F j, Y', strtotime($selected_date)); ?></h6>
                                <p>Shop sales will appear here when recorded</p>
                                <a href="../../shop/index.php" class="btn btn-primary">
                                    <i class="fas fa-plus-circle"></i>Record Shop Sale
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-clean">
                                    <thead>
                                        <tr>
                                            <th>Receipt #</th>
                                            <th>Attendant</th>
                                            <th class="text-center">Amount</th>
                                            <th class="text-center">Payment</th>
                                            <th class="text-end">Time</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_shop_sales as $sale): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($sale['sale_number'] ?? $sale['dispatch_number']); ?></div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($sale['customer_name'] ?? $sale['notes'] ?? 'Walk-in'); ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($sale['attendant_name'] ?? 'N/A'); ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-success fw-semibold">₦<?php echo number_format($sale['total_amount'] ?? $sale['actual_sales_amount'], 0); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-info"><?php echo htmlspecialchars($sale['payment_method'] ?? 'cash'); ?></span>
                                                </td>
                                                <td class="text-end text-muted small">
                                                    <?php echo date('H:i', strtotime($sale['recorded_at'] ?? $sale['created_at'])); ?>
                                                </td>
                                                <td class="text-center">
                                                    <a href="../../shop/receipt.php?id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="fas fa-receipt me-1"></i>Receipt
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4">
                                <a href="../../shop/history.php" class="btn btn-primary">
                                    <i class="fas fa-history me-2"></i>View Shop Sales History
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Activity Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Activity Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="activityDetailsContent">
                        <!-- Content will be inserted here by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="record.php" class="btn btn-primary" id="editActivityBtn">
                        <i class="fas fa-edit me-2"></i>Edit Record
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/clean-dashboard.js"></script>
    
    <script>
        // Show activity details in modal
        function showActivityDetails(activity) {
            const totalReceived = (activity.cash_collected || 0) + (activity.transfer_amount || 0) + (activity.credit_amount || 0) + (activity.credit_settlement || 0);
            
            let html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="text-muted small">Driver</label>
                        <div class="fw-semibold">${escapeHtml(activity.driver_name)}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Date</label>
                        <div class="fw-semibold">${formatDate(activity.activity_date)}</div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="text-muted small">Dispatch/Trip</label>
                        <div class="fw-semibold">Trip #${activity.dispatch_id || 'N/A'}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Recorded At</label>
                        <div class="fw-semibold">${formatTime(activity.recorded_at)}</div>
                    </div>
                </div>
                
                <hr>
                
                <h6 class="mb-3">Cash Collections</h6>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="text-muted small">Cash Collected</label>
                        <div class="fw-semibold text-success">₦${formatCurrency(activity.cash_collected)}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Transfer/Bank</label>
                        <div class="fw-semibold text-primary">₦${formatCurrency(activity.transfer_amount)}</div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="text-muted small">Credit Sales</label>
                        <div class="fw-semibold text-warning">₦${formatCurrency(activity.credit_amount)}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Credit Settlement</label>
                        <div class="fw-semibold text-info">₦${formatCurrency(activity.credit_settlement)}</div>
                    </div>
                </div>
                
                ${activity.credit_customer_name ? `
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="text-muted small">Credit Customer Name</label>
                        <div class="fw-semibold">${escapeHtml(activity.credit_customer_name)}</div>
                    </div>
                </div>
                ` : ''}
                
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="text-muted small fw-semibold">Total Received</label>
                        <div class="fw-semibold text-success" style="font-size: 1.2rem;">₦${formatCurrency(totalReceived)}</div>
                    </div>
                </div>
                
                <hr>
                
                <h6 class="mb-3">Expenses & Deductions</h6>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="text-muted small">Expenses Amount</label>
                        <div class="fw-semibold text-danger">₦${formatCurrency(activity.expenses_amount)}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">Damaged Products</label>
                        <div class="fw-semibold text-danger">₦${formatCurrency(activity.damaged_products)}</div>
                    </div>
                </div>
                
                ${activity.expenses_note ? `
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="text-muted small">Expenses Note</label>
                        <div class="p-2 bg-light rounded">${escapeHtml(activity.expenses_note)}</div>
                    </div>
                </div>
                ` : ''}
                
                ${activity.damaged_products_note ? `
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="text-muted small">Damaged Products Note</label>
                        <div class="p-2 bg-light rounded">${escapeHtml(activity.damaged_products_note)}</div>
                    </div>
                </div>
                ` : ''}
                
                ${activity.returns_count > 0 ? `
                <hr>
                <h6 class="mb-3">Returns</h6>
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="text-muted small">Units Returned</label>
                        <div class="fw-semibold">${activity.returns_count} units</div>
                    </div>
                </div>
                ` : ''}
            `;
            
            document.getElementById('activityDetailsContent').innerHTML = html;
        }
        
        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatCurrency(value) {
            return (value || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
        }
        
        function formatTime(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }
        
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

        // Delete activity with confirmation
        function deleteActivity(activityId) {
            console.log('Delete function called with ID:', activityId);
            if (confirm('Are you sure you want to delete this activity record?\n\nThis will:\n- Delete the activity record\n- Remove associated returns and restore stock\n- Update dispatch sales amount\n- Adjust creditor records\n- Remove related customer payments\n\nThis action cannot be undone.')) {
                if (confirm('Please confirm again to delete this record permanently.')) {
                    const deleteForm = document.getElementById('deleteActivityForm');
                    if (deleteForm) {
                        deleteForm.elements.id.value = activityId;
                        deleteForm.requestSubmit();
                    }
                }
            }
        }
        
        // Search functionality
        document.getElementById('activitySearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const tableRows = document.querySelectorAll('tbody tr');
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
    <?php if (hasRole(['admin'])): ?>
    <form id="deleteActivityForm" method="POST" action="delete_activity.php" hidden>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($deleteActivityCsrfToken); ?>">
    </form>
    <?php endif; ?>
</body>
</html>


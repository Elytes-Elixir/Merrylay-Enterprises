<?php
/**
 * Today's Sales - All Trips for the Day
 * 
 * Shows all dispatches/trips recorded for the current day with edit functionality.
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

$today = date('Y-m-d');

// Get all dispatches/trips for today
$trips = [];
try {
    $stmt = executeQuery("
        SELECT 
            d.id,
            d.dispatch_number,
            d.dispatcher_name,
            d.dispatcher_id,
            d.expected_amount,
            d.dispatch_date,
            d.sales_status,
            COALESCE(da.id, 0) as activity_id,
            COALESCE(da.cash_collected, 0) as cash_collected,
            COALESCE(da.transfer_amount, 0) as transfer_amount,
            COALESCE(da.credit_amount, 0) as credit_amount,
            COALESCE(da.expenses_amount, 0) as expenses_amount
        FROM dispatches d
        LEFT JOIN daily_activities da ON d.id = da.dispatch_id
        WHERE DATE(d.dispatch_date) = ?
                    AND COALESCE(d.status, '') <> 'cancelled'
        ORDER BY d.dispatch_number
    ", [$today]);
    $trips = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load today's trips: " . $e->getMessage());
    $trips = [];
}

// Calculate totals
$total_expected = 0;
$total_cash = 0;
$total_transfer = 0;
$total_credit = 0;
$total_expenses = 0;

foreach ($trips as $trip) {
    $total_expected += $trip['expected_amount'];
    $total_cash += $trip['cash_collected'];
    $total_transfer += $trip['transfer_amount'];
    $total_credit += $trip['credit_amount'];
    $total_expenses += $trip['expenses_amount'];
}

// Include shop attendant sales so today's cash/transfer totals match the shop sales flow.
try {
    $stmt = executeQuery(
        "SELECT
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_amount,
            COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_amount
         FROM shop_sales
         WHERE DATE(sale_date) = ?",
        [$today]
    );
    $shop_sales_totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total_cash += floatval($shop_sales_totals['cash_amount'] ?? 0);
    $total_transfer += floatval($shop_sales_totals['transfer_amount'] ?? 0);
} catch (PDOException $e) {
    error_log('Failed to load shop sales totals for today-sales: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Today's sales overview for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <title>Today's Sales - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    
    <style>
        /* Modern Design System */
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --secondary: #64748b;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 12px;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        body {
            background: var(--bg);
            font-family: 'Inter', sans-serif;
        }

        /* Page Header */
        .page-header {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        
        .page-header h4 {
            font-weight: 700;
            font-size: 1.75rem;
            color: var(--text);
            margin-bottom: 0.25rem;
            letter-spacing: -0.025em;
        }
        
        .page-header p {
            color: var(--text-muted);
            margin: 0;
            font-size: 0.95rem;
        }

        /* Section Cards */
        .section-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: box-shadow 0.2s ease;
        }

        .section-card:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 1.25rem;
            padding-bottom: 0.875rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        
        /* Stats Cards */
        .stats-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            height: 100%;
            transition: all 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .stats-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stats-label {
            color: var(--text-muted);
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        /* Table Styles */
        .trips-table {
            background: var(--card);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        
        .trips-table .table {
            margin-bottom: 0;
        }
        
        .trips-table th {
            background: var(--bg);
            font-weight: 600;
            color: var(--text);
            border-bottom: 2px solid var(--border);
            padding: 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .trips-table td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        
        .trips-table tbody tr:hover {
            background: var(--bg);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .status-completed {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-pending {
            background: #fef9c3;
            color: #854d0e;
        }
        
        .status-in-progress {
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* Action Buttons */
        .btn-edit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            font-size: 0.875rem;
        }
        
        .btn-edit:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state h5 {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.5rem;
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
        <main class="main-content">
            <div class="content-wrapper">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4>Today's Sales</h4>
                            <p>All trips recorded for <?php echo date('F j, Y'); ?></p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Daily Sales
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-value text-success">₦<?php echo number_format($total_cash, 0); ?></div>
                            <div class="stats-label">Total Cash</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-value text-primary">₦<?php echo number_format($total_transfer, 0); ?></div>
                            <div class="stats-label">Total Transfer</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-value text-warning">₦<?php echo number_format($total_credit, 0); ?></div>
                            <div class="stats-label">Total Credit</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-value text-danger">₦<?php echo number_format($total_expenses, 0); ?></div>
                            <div class="stats-label">Total Expenses</div>
                        </div>
                    </div>
                </div>
                <div class="mb-3 text-muted small">
                    Includes shop sales cash and transfer recorded by the shop attendant.
                </div>
                
                <!-- Trips Table -->
                <div class="section-card">
                    <div class="section-title">
                        <i class="fas fa-route"></i>
                        <span>Today's Trips</span>
                    </div>
                    
                    <?php if (empty($trips)): ?>
                    <div class="empty-state">
                        <i class="fas fa-route"></i>
                        <h5>No trips recorded today</h5>
                        <p>Trips will appear here once they are created and dispatched.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Trip #</th>
                                    <th>Dispatcher</th>
                                    <th>Expected Amount</th>
                                    <th>Cash Collected</th>
                                    <th>Transfer</th>
                                    <th>Credit</th>
                                    <th>Expenses</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trips as $trip): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($trip['dispatch_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($trip['dispatcher_name']); ?></td>
                                    <td>₦<?php echo number_format($trip['expected_amount'], 0); ?></td>
                                    <td>₦<?php echo number_format($trip['cash_collected'], 0); ?></td>
                                    <td>₦<?php echo number_format($trip['transfer_amount'], 0); ?></td>
                                    <td>₦<?php echo number_format($trip['credit_amount'], 0); ?></td>
                                    <td>₦<?php echo number_format($trip['expenses_amount'], 0); ?></td>
                                    <td>
                                        <?php
                                        $status = $trip['sales_status'] ?? 'pending';
                                        $statusClass = 'status-pending';
                                        if ($status === 'completed') {
                                            $statusClass = 'status-completed';
                                        } elseif ($status === 'in_progress') {
                                            $statusClass = 'status-in-progress';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($trip['activity_id'] > 0): ?>
                                        <a href="record.php?activity_id=<?php echo $trip['activity_id']; ?>&open_edit_modal=1" class="btn btn-edit btn-sm">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </a>
                                        <?php if (hasRole(['admin'])): ?>
                                        <form method="POST" action="delete_activity.php" class="d-inline" onsubmit="return confirm('Delete this sales record completely? This cannot be undone. This will remove the recorded sales so the trip can be entered again.');">
                                            <input type="hidden" name="id" value="<?php echo (int) $trip['activity_id']; ?>">
                                            <input type="hidden" name="from" value="today-sales">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($deleteActivityCsrfToken); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger ms-2">
                                                <i class="fas fa-trash-alt me-1"></i>Delete
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <a href="record.php?dispatch_id=<?php echo $trip['id']; ?>" class="btn btn-edit btn-sm">
                                            <i class="fas fa-plus me-1"></i>Record
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/clean-dashboard.js?v=<?php echo time(); ?>"></script>
</body>
</html>

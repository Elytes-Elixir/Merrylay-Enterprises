<?php
/**
 * Credits by Driver
 * 
 * View all credits grouped by the driver who made the sale.
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';

$credit_exclusion_sql = " AND creditor_name NOT LIKE 'Shop Expense - %' AND creditor_name NOT LIKE 'Shop Collection - %' ";

$selected_driver = $_GET['driver'] ?? '';
$filter_status = $_GET['status'] ?? 'active';

// Get drivers
$drivers = [];
try {
    $stmt = executeQuery("
        SELECT DISTINCT driver_id, driver_name 
        FROM creditors 
        WHERE status = 'active'
        ORDER BY driver_name
    ");
    $drivers = $stmt->fetchAll();
} catch (PDOException $e) {
    $drivers = [];
}

// Get credits grouped by driver with creditor names
$driver_credits = [];
try {
    $sql = "
        SELECT 
            driver_id,
            driver_name,
            COUNT(*) as credit_count,
            COALESCE(SUM(credit_amount), 0) as total_credit,
            COALESCE(SUM(amount_paid), 0) as total_paid,
            COALESCE(SUM(balance), 0) as total_balance,
            GROUP_CONCAT(creditor_name ORDER BY creditor_name SEPARATOR ', ') as creditor_names
        FROM creditors
        WHERE status = ?
        $credit_exclusion_sql
    ";
    $params = [$filter_status];
    
    if (!empty($selected_driver)) {
        $sql .= " AND driver_id = ?";
        $params[] = $selected_driver;
    }
    
    $sql .= " GROUP BY driver_id, driver_name ORDER BY driver_name";
    
    $stmt = executeQuery($sql, $params);
    $driver_credits = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load data';
}

// Get detailed credits for selected driver
$detailed_credits = [];
if (!empty($selected_driver)) {
    try {
        $stmt = executeQuery("
            SELECT * FROM creditors 
                WHERE driver_id = ? AND status = ?
                $credit_exclusion_sql
            ORDER BY credit_date DESC
        ", [$selected_driver, $filter_status]);
        $detailed_credits = $stmt->fetchAll();
    } catch (PDOException $e) {
        $detailed_credits = [];
    }
}

// Get all creditors for the table (filtered by status)
$all_creditors = [];
try {
    $stmt = executeQuery(
        "SELECT c.*, d.name as driver_name FROM creditors c LEFT JOIN drivers d ON c.driver_id = d.id WHERE c.status = ? $credit_exclusion_sql ORDER BY c.creditor_name",
        [$filter_status]
    );
    $all_creditors = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_creditors = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits by Driver - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .driver-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            cursor: pointer;
            transition: all var(--transition-normal);
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .driver-card:hover {
            border-color: var(--border-medium);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        .driver-card.selected {
            border-left: 4px solid var(--blue);
            background: linear-gradient(135deg, rgb(59 130 246 / 0.05) 0%, rgb(59 130 246 / 0.02) 100%);
            border-color: rgb(59 130 246 / 0.3);
        }
        .credit-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
        }
        .credit-item:hover {
            border-color: var(--border-medium);
            box-shadow: var(--shadow-sm);
        }
        .balance-badge {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--red);
        }
        .stat-box {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            text-align: center;
            transition: all var(--transition-normal);
        }
        .stat-box:hover {
            border-color: var(--border-medium);
            box-shadow: var(--shadow-sm);
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-800);
        }
        .stat-value.text-danger {
            color: var(--red);
        }
        .progress {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-md);
        }
        .progress-bar {
            background-color: var(--green);
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

        <main class="main-content" id="mainContent">
            <div class="dashboard-content">
                <!-- Header intentionally left out per restructure request -->

                <!-- Status Filter -->
                <div class="mb-4">
                    <div class="btn-group" role="group">
                        <a href="?status=active" class="btn <?php echo $filter_status === 'active' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="fas fa-hourglass-start me-2"></i>Active
                        </a>
                        <a href="?status=cleared" class="btn <?php echo $filter_status === 'cleared' ? 'btn-success' : 'btn-outline-success'; ?>">
                            <i class="fas fa-check-circle me-2"></i>Cleared
                        </a>
                    </div>
                </div>

                <!-- Active Credits Section -->
                <?php if ($filter_status === 'active' || empty($filter_status)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="content-card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="fas fa-hourglass-start me-2"></i>Active Credits</h5>
                                <p class="card-subtitle">Outstanding creditor accounts requiring payment</p>
                            </div>
                            <div class="card-body">
                                <?php 
                                $active_creditors = array_filter($all_creditors, function($c) { return ($c['status'] ?? '') === 'active'; });
                                if (empty($active_creditors)): 
                                ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-inbox"></i>
                                        </div>
                                        <h6>No active credits</h6>
                                        <p>All creditor accounts are cleared.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle">
                                            <thead>
                                                <tr>
                                                    <th>Creditor</th>
                                                    <th>Credit Date</th>
                                                    <th>Dispatched</th>
                                                    <th>Owing</th>
                                                    <th>Paid</th>
                                                    <th>Outstanding</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($active_creditors as $c): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($c['creditor_name']); ?></strong>
                                                            <?php if (stripos($c['notes'] ?? '', 'dispatcher shortage') !== false): ?>
                                                                <span class="badge bg-danger ms-2">Dispatcher Shortage</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-muted small"><?php echo isset($c['credit_date']) ? date('M d, Y', strtotime($c['credit_date'])) : '—'; ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars($c['driver_name'] ?? '—'); ?></td>
                                                        <td>₦<?php echo number_format($c['credit_amount'], 2); ?></td>
                                                        <td>₦<?php echo number_format($c['amount_paid'], 2); ?></td>
                                                        <td><span class="badge bg-warning text-dark">₦<?php echo number_format($c['balance'], 2); ?></span></td>
                                                        <td class="text-end">
                                                            <a href="remit.php?creditor_id=<?php echo $c['id'] ?? ''; ?>" class="btn btn-sm btn-primary">Record Payment</a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cleared Credits Section -->
                <?php if ($filter_status === 'cleared' || empty($filter_status)): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="content-card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="fas fa-check-circle me-2 text-success"></i>Cleared Credits</h5>
                                <p class="card-subtitle">Paid creditor accounts - maintained for record keeping</p>
                            </div>
                            <div class="card-body">
                                <?php 
                                $cleared_creditors = array_filter($all_creditors, function($c) { return ($c['status'] ?? '') === 'cleared'; });
                                if (empty($cleared_creditors)): 
                                ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-inbox"></i>
                                        </div>
                                        <h6>No cleared credits</h6>
                                        <p>No creditor accounts have been cleared yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle">
                                            <thead>
                                                <tr>
                                                    <th>Creditor</th>
                                                    <th>Credit Date</th>
                                                    <th>Dispatched</th>
                                                    <th>Total Owed</th>
                                                    <th>Total Paid</th>
                                                    <th>Cleared Date</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($cleared_creditors as $c): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($c['creditor_name']); ?></strong>
                                                            <?php if (stripos($c['notes'] ?? '', 'dispatcher shortage') !== false): ?>
                                                                <span class="badge bg-danger ms-2">Dispatcher Shortage</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-muted small"><?php echo isset($c['credit_date']) ? date('M d, Y', strtotime($c['credit_date'])) : '—'; ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars($c['driver_name'] ?? '—'); ?></td>
                                                        <td>₦<?php echo number_format($c['credit_amount'], 2); ?></td>
                                                        <td><span class="badge bg-success">₦<?php echo number_format($c['amount_paid'], 2); ?></span></td>
                                                        <td><?php echo isset($c['cleared_at']) ? date('d M Y', strtotime($c['cleared_at'])) : '—'; ?></td>
                                                        <td class="text-end">
                                                            <a href="view_creditor.php?id=<?php echo $c['id'] ?? ''; ?>" class="btn btn-sm btn-outline-secondary">View History</a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="../../assets/js/clean-dashboard.js"></script>
</body>
</html>


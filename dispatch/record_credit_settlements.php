<?php
/**
 * Credit Management Page for Merrylay Enterprises
 *
 * View and manage credit accounts automatically sourced from daily sales records
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

header('Location: credit/index.php');
exit();

$selected_date = $_GET['date'] ?? date('Y-m-d');
$dispatcher_filter = $_GET['dispatcher'] ?? '';

// Get unique dispatchers for filter
try {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches ORDER BY dispatcher_name");
    $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $dispatchers = [];
}

// Get outstanding credit details automatically
$outstanding_credits = [];

if (!empty($dispatcher_filter)) {
    try {
        $stmt = executeQuery("
            SELECT 
                d.id,
                d.dispatch_number,
                d.dispatcher_name,
                d.dispatch_date,
                d.status
            FROM dispatches d
            WHERE d.dispatcher_name = ? 
            ORDER BY d.dispatch_date DESC
        ", [$dispatcher_filter]);
        $available_dispatches = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Load dispatches error: " . $e->getMessage());
    }

    // Get outstanding credits for this dispatcher
    try {
        $stmt = executeQuery("
            SELECT 
                c.id as creditor_id,
                c.creditor_name,
                c.credit_amount,
                c.amount_paid,
                c.balance,
                c.credit_date,
                c.driver_name,
                c.product_details,
                c.status,
                c.driver_id,
                d.dispatch_number,
                d.id as dispatch_id
            FROM creditors c
            LEFT JOIN dispatches d ON DATE(d.dispatch_date) = c.credit_date AND d.dispatcher_name = c.driver_name
            WHERE c.driver_name = ?
            ORDER BY c.status ASC, c.balance DESC, c.credit_date DESC, c.creditor_name
        ", [$dispatcher_filter]);
        $outstanding_credits = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Load outstanding credits error: " . $e->getMessage());
    }
}

// Get all credit accounts for the dispatcher
$creditors = [];
function getPaymentHistory($creditor_id) {
    try {
        $stmt = executeQuery("SELECT payment_date, amount, payment_method, notes FROM creditor_remittances WHERE creditor_id = ? ORDER BY payment_date DESC", [$creditor_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

try {
    $sql = "SELECT * FROM creditors WHERE 1=1";
    $params = [];
    if (!empty($dispatcher_filter)) {
        $sql .= " AND driver_name = ?";
        $params[] = $dispatcher_filter;
    }
    $sql .= " ORDER BY status ASC, balance DESC, credit_date DESC";
    $stmt = executeQuery($sql, $params);
    $creditors = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Load creditors error: " . $e->getMessage());
}

$overpaid_credits = [];
foreach ($creditors as $credit) {
    if (floatval($credit['amount_paid']) > floatval($credit['credit_amount'])) {
        $overpaid_credits[] = $credit;
    }
}

// Get recent credit settlements for selected dispatcher and date
$recent_settlements = [];
$settlements_total = 0;
$daily_activity_settlements = [];

if (!empty($dispatcher_filter)) {
    try {
        $stmt = executeQuery("
            SELECT
                da.id AS daily_activity_id,
                da.dispatch_id,
                da.credit_customer_name AS customer_name,
                da.credit_settlement AS amount_collected,
                da.activity_date AS settlement_date,
                d.dispatch_number,
                d.dispatcher_name,
                u.name AS collected_by,
                da.updated_at AS created_at
            FROM daily_activities da
            JOIN dispatches d ON da.dispatch_id = d.id
            LEFT JOIN users u ON da.recorded_by = u.id
            WHERE da.driver_name = ? AND da.activity_date = ? AND da.credit_settlement > 0
            ORDER BY da.updated_at DESC
        ", [$dispatcher_filter, $selected_date]);
        $daily_activity_settlements = $stmt->fetchAll();

        if (!empty($daily_activity_settlements)) {
            $total = 0;
            foreach ($daily_activity_settlements as $row) {
                $total += floatval($row['amount_collected']);
            }
            $today_summary = ['total' => $total, 'settlement_count' => count($daily_activity_settlements)];
            $recent_settlements = $daily_activity_settlements;
        } else {
            $stmt = executeQuery("
                SELECT 
                    cs.id,
                    cs.amount_collected,
                    cs.payment_method,
                    cs.settlement_date,
                    cs.reference_number,
                    d.dispatch_number,
                    d.dispatcher_name,
                    cs.customer_name,
                    u.name as collected_by,
                    cs.created_at
                FROM credit_settlements cs
                JOIN dispatches d ON cs.dispatch_id = d.id
                JOIN users u ON cs.collected_by = u.id
                WHERE d.dispatcher_name = ? AND DATE(cs.settlement_date) = ?
                ORDER BY cs.created_at DESC
            ", [$dispatcher_filter, $selected_date]);
            $recent_settlements = $stmt->fetchAll();

            // Calculate total for selected dispatcher and date
            $stmt = executeQuery("
                SELECT 
                    SUM(amount_collected) as total,
                    COUNT(*) as settlement_count
                FROM credit_settlements cs
                JOIN dispatches d ON cs.dispatch_id = d.id
                WHERE d.dispatcher_name = ? AND DATE(cs.settlement_date) = ?
            ", [$dispatcher_filter, $selected_date]);
            $today_summary = $stmt->fetch();
        }
    } catch (PDOException $e) {
        error_log("Load recent settlements error: " . $e->getMessage());
        $today_summary = ['total' => 0, 'settlement_count' => 0];
    }
} else {
    // Get summary for the selected date across all dispatchers
    $selected_date = date('Y-m-d', strtotime($selected_date));
    try {
        $stmt = executeQuery("
            SELECT 
                SUM(amount_collected) as total,
                COUNT(*) as settlement_count
            FROM credit_settlements
            WHERE DATE(settlement_date) = ?
        ", [$selected_date]);
        $today_summary = $stmt->fetch();
    } catch (PDOException $e) {
        $today_summary = ['total' => 0, 'settlement_count' => 0];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage credit accounts automatically sourced from daily sales records">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Credit Management - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    </style>
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../dashboard/index.php" class="logo">
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

        <!-- Main content -->
        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search settlements, dispatchers..." id="globalSearch">
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
                <div class="welcome-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <h1><i class="fas fa-hand-holding-usd me-2"></i>Credit Management</h1>
                            <p>View credit accounts and settlements automatically managed from daily sales records.</p>
                            <div class="welcome-meta">
                                <div>
                                    <i class="fas fa-calendar-day"></i>
                                    <span><?php echo date('l, F j, Y'); ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <span id="currentTime"><?php echo date('H:i'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-filter me-2"></i>Filter Dispatches</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Dispatcher</label>
                                <select class="form-select" name="dispatcher">
                                    <option value="">All Dispatchers</option>
                                    <?php foreach ($dispatchers as $dispatcher): ?>
                                        <option value="<?php echo htmlspecialchars($dispatcher); ?>" 
                                                <?php echo $dispatcher_filter === $dispatcher ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dispatcher); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </form>
                        <?php if (!empty($dispatcher_filter)): ?>
                            <div class="mt-2">
                                <a href="record_credit_settlements.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i>Clear Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($outstanding_credits)): ?>
                <div class="content-card mb-4">
                    <div class="card-header" style="background: #fef3c7; border-bottom: 2px solid #f59e0b;">
                        <h5 class="card-title"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Outstanding Credits</h5>
                        <p class="card-subtitle">Automatic credit details from daily sales activity</p>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Customer Name</th>
                                        <th>Original Credit</th>
                                        <th>Outstanding Balance</th>
                                        <th>Credit Date</th>
                                        <th>Dispatch</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($outstanding_credits as $credit): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($credit['creditor_name']); ?></strong>
                                                <?php if (!empty($credit['product_details'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($credit['product_details']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">₦<?php echo number_format($credit['credit_amount'], 2); ?></td>
                                            <td class="text-end">
                                                <span class="badge bg-warning text-dark">₦<?php echo number_format($credit['balance'], 2); ?></span>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($credit['credit_date'])); ?></td>
                                            <td>
                                                <?php if ($credit['dispatch_number']): ?>
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars($credit['dispatch_number']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">No dispatch</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-success" 
                                                        onclick='populateSettlementForm(<?php echo json_encode(intval($credit['creditor_id'])); ?>, <?php echo json_encode($credit['creditor_name']); ?>, <?php echo json_encode(floatval($credit['balance'])); ?>, <?php echo json_encode(intval($credit['dispatch_id'] ?? 0)); ?>)'>
                                                    <i class="fas fa-hand-holding-usd me-1"></i>Settle
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Total Outstanding:</strong> ₦<?php echo number_format(array_sum(array_column($outstanding_credits, 'balance')), 2); ?> 
                            for <?php echo count($outstanding_credits); ?> customer(s)
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($creditors)): ?>
                <?php if (!empty($overpaid_credits)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Excessive payment detected:</strong> the following account(s) have paid more than the original credit amount.
                    <ul class="mb-0 ps-3">
                        <?php foreach ($overpaid_credits as $credit): ?>
                            <li><?php echo htmlspecialchars($credit['creditor_name']); ?> — overpaid by ₦<?php echo number_format(abs($credit['balance']), 2); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="content-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-user-friends me-2"></i>Credit Accounts</h5>
                        <p class="card-subtitle">All customer credit accounts for the selected dispatcher</p>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Credit</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($creditors as $credit): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($credit['creditor_name']); ?></strong>
                                                <?php if (!empty($credit['product_details'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($credit['product_details']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>₦<?php echo number_format($credit['credit_amount'], 2); ?></td>
                                            <td>₦<?php echo number_format($credit['amount_paid'], 2); ?></td>
                                            <td>
                                                <?php if (floatval($credit['balance']) < 0): ?>
                                                    <span class="badge bg-danger">Overpaid by ₦<?php echo number_format(abs($credit['balance']), 2); ?></span>
                                                <?php else: ?>
                                                    ₦<?php echo number_format($credit['balance'], 2); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-<?php echo $credit['status'] === 'cleared' ? 'success' : 'warning'; ?> text-<?php echo $credit['status'] === 'cleared' ? 'white' : 'dark'; ?>"><?php echo ucfirst($credit['status']); ?></span></td>
                                            <td>
                                                <?php if ($credit['balance'] > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-success" onclick='populateSettlementForm(<?php echo json_encode(intval($credit['creditor_id'])); ?>, <?php echo json_encode($credit['creditor_name']); ?>, <?php echo json_encode(floatval($credit['balance'])); ?>, <?php echo json_encode(intval($credit['dispatch_id'] ?? 0)); ?>)'>
                                                        <i class="fas fa-hand-holding-usd me-1"></i>Collect
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">No action</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Credit accounts are now automatically managed from daily sales records -->
                <!-- Manual credit settlement form removed - all settlements are sourced from daily activities -->
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update time every minute (GMT+1)
        setInterval(() => {
            const now = new Date();
            // Convert to GMT+1 (West Africa Time)
            const gmtPlus1 = new Date(now.toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = gmtPlus1.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            }
        }, 60000);

        // Initial time update
        (() => {
            const now = new Date();
            const gmtPlus1 = new Date(now.toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = gmtPlus1.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            }
        })();

        // Function to populate settlement form with outstanding credit details
        function populateSettlementForm(creditorId, customerName, outstandingBalance, dispatchId) {
            // Since manual settlements are removed, this function now just shows an alert
            alert('Credit settlements are now automatically managed from daily sales records. Manual entry is no longer needed.');
        }

        // Auto-refresh outstanding credits when dispatcher changes
        document.getElementById('dispatcherFilter').addEventListener('change', function() {
            const selectedDispatcher = this.value;
            const currentDate = '<?php echo $selected_date; ?>';
            window.location.href = 'record_credit_settlements.php?dispatcher=' + encodeURIComponent(selectedDispatcher) + '&date=' + currentDate;
        });

        // Auto-refresh when date changes
        document.getElementById('dateFilter').addEventListener('change', function() {
            const selectedDate = this.value;
            const currentDispatcher = '<?php echo urlencode($dispatcher_filter); ?>';
            window.location.href = 'record_credit_settlements.php?dispatcher=' + currentDispatcher + '&date=' + selectedDate;
        });
    </script>
</body>
</html>
<?php
/**
 * Credit Management Dashboard
 * 
 * Central hub for managing customer credits and remittances.
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_settled_credit') {
    if (!canManageFinancials()) {
        error_log('Unauthorized settled-credit cleanup attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to clear settled credits.');
    }
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
        error_log('Rejected settled-credit cleanup: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('Your form has expired. Refresh the page and try again.');
    }
    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE creditors SET status = 'cleared', cleared_at = NOW() WHERE status = 'active' AND balance <= 0");
        $stmt->execute();
        $affectedRows = $stmt->rowCount();
        $pdo->commit();
        error_log('Cleared ' . $affectedRows . ' settled creditor(s) by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $_SESSION['credit_cleanup_message'] = 'Settled credit records updated.';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Settled-credit cleanup failed: ' . $e->getMessage());
        $_SESSION['credit_cleanup_message'] = 'Unable to update settled credit records.';
    }
    header('Location: index.php');
    exit();
}
$creditCleanupMessage = $_SESSION['credit_cleanup_message'] ?? '';
unset($_SESSION['credit_cleanup_message']);
$creditCleanupCsrfToken = SecurityUtils::generateCSRFToken();

$credit_exclusion_sql = " AND creditor_name NOT LIKE 'Shop Expense - %' AND creditor_name NOT LIKE 'Shop Collection - %' ";

// Get statistics
$stats = [
    'total_creditors' => 0,
    'total_credit' => 0,
    'total_paid' => 0,
    'total_balance' => 0,
    'cleared_count' => 0
];

try {
    // Active creditors stats - only include active (not settled/cleared) credits
    $stmt = executeQuery("
        SELECT 
            COUNT(*) as total_creditors,
            COALESCE(SUM(credit_amount), 0) as total_credit,
            COALESCE(SUM(amount_paid), 0) as total_paid,
            COALESCE(SUM(balance), 0) as total_balance
        FROM creditors
        WHERE status = 'active'
        $credit_exclusion_sql
    ");
    $result = $stmt->fetch();
    $stats['total_creditors'] = $result['total_creditors'];
    $stats['total_credit'] = $result['total_credit'];
    $stats['total_paid'] = $result['total_paid'];
    $stats['total_balance'] = $result['total_balance'];
    
    // Cleared count - for reference
    $stmt = executeQuery("SELECT COUNT(*) as count FROM creditors WHERE status = 'cleared' $credit_exclusion_sql");
    $stats['cleared_count'] = $stmt->fetch()['count'];
    
    // Verification: Ensure no active creditors have zero/negative balance (should be auto-cleared)
    $stmt = executeQuery("SELECT COUNT(*) as count FROM creditors WHERE status = 'active' AND balance <= 0 $credit_exclusion_sql");
    $invalid_count = $stmt->fetch()['count'];
    if ($invalid_count > 0) {
        error_log("Warning: Found {$invalid_count} active creditors with zero/negative balance - should be auto-cleared");
    }
} catch (PDOException $e) {
    error_log("Credit statistics error: " . $e->getMessage());
    // Use defaults
}

// Get recent creditors
$recent_creditors = [];
try {
    $stmt = executeQuery("
        SELECT * FROM creditors 
        WHERE status = 'active'
        $credit_exclusion_sql
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_creditors = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_creditors = [];
}

// Get recent remittances
$recent_remittances = [];
try {
    $stmt = executeQuery("
        SELECT cr.*, c.creditor_name 
        FROM creditor_remittances cr
        JOIN creditors c ON cr.creditor_id = c.id
        ORDER BY cr.recorded_at DESC
        LIMIT 5
    ");
    $recent_remittances = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_remittances = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Management - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .action-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        .action-card-link:hover {
            text-decoration: none;
            color: inherit;
        }
        .action-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: var(--space-3);
            background: linear-gradient(135deg, rgb(139 92 246 / 0.15) 0%, rgb(139 92 246 / 0.05) 100%);
            color: #8b5cf6;
            border: 1px solid rgb(139 92 246 / 0.2);
        }
        .creditor-item {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
        }
        .creditor-item:hover {
            border-color: var(--border-medium);
            box-shadow: var(--shadow-sm);
        }
        .balance-due { color: var(--red); font-weight: 600; }
        .paid { color: var(--green); font-weight: 600; }
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
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <h1><i class="fas fa-hand-holding-usd me-2"></i>Credit Management</h1>
                    <p>Track customer credits, remittances, and clearances</p>
                    <?php if ($creditCleanupMessage !== ''): ?><div class="alert alert-info" role="status"><?php echo htmlspecialchars($creditCleanupMessage); ?></div><?php endif; ?>
                    <?php if (canManageFinancials()): ?>
                    <form method="POST" action="index.php" onsubmit="return confirm('Mark all zero-balance active credit records as cleared?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($creditCleanupCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="clear_settled_credit">
                        <button type="submit" class="btn btn-outline-secondary">Update settled credit statuses</button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="stats-grid mb-5">
                    <a href="add.php" class="action-card-link">
                        <div class="stat-card">
                            <div class="action-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <h5>Add Creditor</h5>
                            <p class="text-muted mb-0">Record new customer credit</p>
                        </div>
                    </a>
                    <a href="by-driver.php" class="action-card-link">
                        <div class="stat-card">
                            <div class="action-icon">
                                <i class="fas fa-list"></i>
                            </div>
                            <h5>Manage Credits</h5>
                            <p class="text-muted mb-0">View all customer credit accounts</p>
                        </div>
                    </a>
                    <a href="remit.php" class="action-card-link">
                        <div class="stat-card">
                            <div class="action-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <h5>Record Payment</h5>
                            <p class="text-muted mb-0">Record remittance from creditor</p>
                        </div>
                    </a>
                </div>

                <!-- Statistics -->
                <h4 class="mb-4">Credit Overview</h4>
                <div class="stats-grid mb-5">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_creditors']; ?></div>
                        <div class="stat-label">Active Creditors</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($stats['total_credit'], 0); ?></div>
                        <div class="stat-label">Total Credit</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($stats['total_balance'], 0); ?></div>
                        <div class="stat-label">Balance Due</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['cleared_count']; ?></div>
                        <div class="stat-label">Cleared</div>
                    </div>
                </div>

                <div class="row">
                    <!-- Recent Creditors -->
                    <div class="col-md-6">
                        <div class="content-card">
                            <div class="card-header">
                                <h5 class="card-title">Recent Credits</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_creditors)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-inbox"></i>
                                        </div>
                                        <h6>No active credits</h6>
                                        <p>Start by adding your first creditor account.</p>
                                        <a href="add.php" class="btn btn-primary">Add Creditor</a>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recent_creditors as $c): ?>
                                        <div class="creditor-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($c['creditor_name']); ?></strong>
                                                    <?php if (stripos($c['notes'] ?? '', 'dispatcher shortage') !== false): ?>
                                                        <span class="badge bg-danger ms-2" style="font-size: 0.7rem;">Dispatcher Shortage</span>
                                                    <?php endif; ?>
                                                    <div class="text-muted small">
                                                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($c['driver_name']); ?>
                                                        <span class="mx-1">|</span>
                                                        <i class="fas fa-calendar me-1"></i><?php echo date('M j, Y', strtotime($c['credit_date'])); ?>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <div class="balance-due">₦<?php echo number_format($c['balance'], 2); ?></div>
                                                    <small class="text-muted">Balance</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="mt-3">
                                        <a href="by-driver.php" class="btn">View All Credits</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Remittances -->
                    <div class="col-md-6">
                        <div class="content-card">
                            <div class="card-header">
                                <h5 class="card-title">Recent Payments</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_remittances)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-receipt"></i>
                                        </div>
                                        <h6>No payments recorded</h6>
                                        <p>Record your first payment remittance.</p>
                                        <a href="remit.php" class="btn btn-success">Record Payment</a>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recent_remittances as $r): ?>
                                        <div class="creditor-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($r['creditor_name']); ?></strong>
                                                    <div class="text-muted small">
                                                        <i class="fas fa-money-bill me-1"></i><?php echo ucfirst($r['payment_method']); ?>
                                                        <span class="mx-1">|</span>
                                                        <?php echo date('M j, Y', strtotime($r['payment_date'])); ?>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <div class="paid">₦<?php echo number_format($r['amount'], 2); ?></div>
                                                    <small class="text-muted">Paid</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="mt-3">
                                        <a href="remit.php" class="btn btn-success">Record Payment</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <a href="../../dashboard/index.php" class="btn">Back to Main Dashboard</a>
                </div>
            </div>
        </main>
    </div>
    <script src="../../assets/js/clean-dashboard.js"></script>
</body>
</html>


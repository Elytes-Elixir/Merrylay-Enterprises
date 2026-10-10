<?php
/**
 * View Creditor Details
 * 
 * Display detailed creditor information with payment history
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
$canManageCredits = hasRole(['admin', 'manager', 'cashier']);
$clearCreditCsrfToken = SecurityUtils::generateCSRFToken();

$creditor_id = intval($_GET['id'] ?? 0);

if (!$creditor_id) {
    header('Location: index.php');
    exit();
}

// Get creditor details
$creditor = null;
try {
    $stmt = executeQuery("SELECT * FROM creditors WHERE id = ?", [$creditor_id]);
    $creditor = $stmt->fetch();
    
    if (!$creditor) {
        header('Location: index.php?error=not_found');
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=load_failed');
    exit();
}

// Get payment history
$payments = [];
try {
    $stmt = executeQuery("
        SELECT * FROM creditor_remittances 
        WHERE creditor_id = ? 
        ORDER BY payment_date DESC
    ", [$creditor_id]);
    $payments = $stmt->fetchAll();
} catch (PDOException $e) {
    $payments = [];
}

// Calculate statistics
$total_payments = count($payments);
$paid_in_period = 0;
$payments_30_days = 0;

foreach ($payments as $p) {
    $paid_in_period += $p['amount'];
    $days_ago = (strtotime(date('Y-m-d')) - strtotime($p['payment_date'])) / 86400;
    if ($days_ago <= 30) {
        $payments_30_days += $p['amount'];
    }
}

// Get driver details
$driver = null;
try {
    $stmt = executeQuery("SELECT * FROM drivers WHERE id = ?", [$creditor['driver_id']]);
    $driver = $stmt->fetch();
} catch (PDOException $e) {
    $driver = null;
}

// Calculate payment status
$status_badge = '';
$status_color = '';
if ($creditor['balance'] <= 0) {
    $status_badge = 'OVERPAID';
    $status_color = 'success';
} elseif ($creditor['status'] === 'cleared') {
    $status_badge = 'CLEARED';
    $status_color = 'info';
} else {
    $status_badge = 'ACTIVE';
    $status_color = 'warning';
}

$payment_percentage = $creditor['credit_amount'] > 0 
    ? round(($creditor['amount_paid'] / $creditor['credit_amount']) * 100) 
    : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creditor Details - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-8);
        }

        .creditor-hero {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-8);
            margin-bottom: var(--space-8);
            box-shadow: var(--shadow-sm);
        }

        .creditor-hero-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: var(--space-6);
        }

        .creditor-name {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-900);
            margin-bottom: var(--space-2);
            line-height: 1.2;
        }

        .creditor-driver {
            color: var(--primary-500);
            font-size: 0.95rem;
            margin-bottom: var(--space-4);
        }

        .status-badge {
            display: inline-block;
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-badge.active {
            background: rgba(249, 115, 22, 0.1);
            color: #d97706;
        }

        .status-badge.cleared {
            background: rgba(59, 130, 246, 0.1);
            color: var(--blue);
        }

        .status-badge.overpaid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--green);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-8);
        }

        .stat-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-5);
            text-align: center;
            transition: all var(--transition-normal);
            box-shadow: var(--shadow-sm);
        }

        .stat-card:hover {
            border-color: var(--border-medium);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-900);
            line-height: 1;
            margin-bottom: var(--space-2);
        }

        .stat-label {
            color: var(--primary-500);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 500;
        }

        .progress-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-5);
            margin-bottom: var(--space-8);
            box-shadow: var(--shadow-sm);
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: var(--space-3);
        }

        .progress-bar-custom {
            height: 24px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--green) 0%, var(--blue) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            font-weight: 600;
            transition: width var(--transition-normal);
        }

        .content-section {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }

        .section-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-5);
            box-shadow: var(--shadow-sm);
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-900);
            margin-bottom: var(--space-4);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }

        .info-item {
            border-bottom: 1px solid var(--border-light);
            padding-bottom: var(--space-3);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--primary-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: var(--space-1);
        }

        .info-value {
            font-size: 1rem;
            color: var(--primary-900);
            font-weight: 500;
        }

        .text-note {
            background: var(--bg-secondary);
            border-left: 3px solid var(--border-medium);
            padding: var(--space-3);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-3);
        }

        .payment-list {
            max-height: 500px;
            overflow-y: auto;
        }

        .payment-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
        }

        .payment-item:hover {
            border-color: var(--border-medium);
            box-shadow: var(--shadow-sm);
            background: #fafbfc;
        }

        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-2);
        }

        .payment-amount {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-900);
        }

        .payment-date {
            color: var(--primary-500);
            font-size: 0.85rem;
        }

        .payment-method-badge {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .payment-method-badge.cash {
            background: rgba(16, 185, 129, 0.15);
            color: var(--green-600);
        }

        .payment-method-badge.transfer {
            background: rgba(59, 130, 246, 0.15);
            color: var(--blue-600);
        }

        .payment-method-badge.other {
            background: rgba(245, 158, 11, 0.15);
            color: var(--yellow-600);
        }

        .payment-notes {
            color: var(--primary-600);
            font-size: 0.9rem;
            margin-top: var(--space-2);
        }

        .empty-state {
            text-align: center;
            padding: var(--space-12);
            color: var(--primary-500);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: var(--space-4);
            opacity: 0.3;
        }

        .empty-state-text {
            font-size: 0.95rem;
            color: var(--primary-600);
        }

        .action-buttons {
            display: flex;
            gap: var(--space-3);
            margin-top: var(--space-4);
        }

        .btn-custom {
            padding: var(--space-3) var(--space-4);
            border-radius: var(--radius-lg);
            font-size: 0.9rem;
            font-weight: 600;
            border: none;
            transition: all var(--transition-normal);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
        }

        .btn-custom.primary {
            background: var(--blue);
            color: white;
        }

        .btn-custom.primary:hover {
            background: var(--blue-600);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .btn-custom.success {
            background: var(--green);
            color: white;
        }

        .btn-custom.success:hover {
            background: var(--green-600);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .content-section {
                grid-template-columns: 1fr;
            }

            .creditor-hero-content {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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
                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <a href="by-driver.php" class="btn btn-custom" style="background: transparent; color: var(--blue); border: 1px solid var(--border-light); font-weight: 600;">
                            <i class="fas fa-arrow-left"></i>Back
                        </a>
                    </div>
                    <div style="text-align: right;">
                        <span class="status-badge <?php echo strtolower(str_replace(' ', '', $status_badge)); ?>">
                            <?php echo $status_badge; ?>
                        </span>
                    </div>
                </div>

                <!-- Hero Section -->
                <div class="creditor-hero">
                    <div class="creditor-hero-content">
                        <div>
                            <div class="creditor-name"><?php echo htmlspecialchars($creditor['creditor_name']); ?></div>
                            <div class="creditor-driver">
                                <i class="fas fa-user-tie" style="color: var(--primary-400); margin-right: 8px;"></i>
                                <?php echo htmlspecialchars($creditor['driver_name']); ?>
                                <?php if ($driver): ?><span style="color: var(--primary-400);"> • <?php echo htmlspecialchars($driver['phone'] ?? 'N/A'); ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value">₦<?php echo number_format($creditor['credit_amount'], 2); ?></div>
                        <div class="stat-label">Total Credit</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color: var(--green);">₦<?php echo number_format($creditor['amount_paid'], 2); ?></div>
                        <div class="stat-label">Amount Paid</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color: <?php echo $creditor['balance'] > 0 ? 'var(--red)' : 'var(--green)'; ?>;">
                            ₦<?php echo number_format($creditor['balance'], 2); ?>
                        </div>
                        <div class="stat-label"><?php echo $creditor['balance'] > 0 ? 'Outstanding' : 'Overpaid'; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $payment_percentage; ?>%</div>
                        <div class="stat-label">Paid Progress</div>
                    </div>
                </div>

                <!-- Progress Section -->
                <div class="progress-card">
                    <div class="progress-label">
                        <span style="font-weight: 600; color: var(--primary-900);">Payment Progress</span>
                        <span style="color: var(--primary-500);">₦<?php echo number_format($creditor['amount_paid'], 2); ?> of ₦<?php echo number_format($creditor['credit_amount'], 2); ?></span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="progress-bar-fill" style="width: <?php echo $payment_percentage; ?>%;">
                            <?php if ($payment_percentage > 10): ?><?php echo $payment_percentage; ?>%<?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Main Content Section -->
                <div class="content-section">
                    <!-- Left Column: Creditor Details -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-info-circle" style="color: var(--blue);"></i>
                            Creditor Information
                        </div>

                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Credit Date</div>
                                <div class="info-value"><?php echo date('M j, Y', strtotime($creditor['credit_date'])); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($creditor['phone'] ?? '—'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Status</div>
                                <div class="info-value"><?php echo ucfirst($creditor['status']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Created</div>
                                <div class="info-value"><?php echo date('M j, Y', strtotime($creditor['created_at'])); ?></div>
                            </div>
                        </div>

                        <?php if ($creditor['address']): ?>
                        <div class="text-note" style="margin-top: var(--space-4);">
                            <div class="info-label" style="margin-bottom: var(--space-2);">Address</div>
                            <div style="font-size: 0.95rem; color: var(--primary-700);">
                                <?php echo nl2br(htmlspecialchars($creditor['address'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($creditor['product_details']): ?>
                        <div class="text-note">
                            <div class="info-label" style="margin-bottom: var(--space-2);">Products</div>
                            <div style="font-size: 0.95rem; color: var(--primary-700);">
                                <?php echo nl2br(htmlspecialchars($creditor['product_details'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($creditor['notes']): ?>
                        <div class="text-note">
                            <div class="info-label" style="margin-bottom: var(--space-2);">Notes</div>
                            <div style="font-size: 0.95rem; color: var(--primary-700);">
                                <?php echo nl2br(htmlspecialchars($creditor['notes'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column: Payment Summary & Actions -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-chart-line" style="color: var(--green);"></i>
                            Payment Summary
                        </div>

                        <div class="info-grid" style="grid-template-columns: 1fr;">
                            <div class="info-item">
                                <div class="info-label">Total Payments</div>
                                <div class="info-value"><?php echo $total_payments; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Last 30 Days</div>
                                <div class="info-value">₦<?php echo number_format($payments_30_days, 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Average Payment</div>
                                <div class="info-value">
                                    ₦<?php 
                                    $avg = $total_payments > 0 ? $creditor['amount_paid'] / $total_payments : 0;
                                    echo number_format($avg, 2);
                                    ?>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <?php if ($canManageCredits && $creditor['status'] === 'active' && $creditor['balance'] > 0): ?>
                                <a href="remit.php?creditor_id=<?php echo $creditor_id; ?>" class="btn-custom primary" style="flex: 1; justify-content: center;">
                                    <i class="fas fa-money-bill-wave"></i> Record Payment
                                </a>
                            <?php endif; ?>

                            <?php if ($canManageCredits && $creditor['status'] === 'active' && $creditor['balance'] <= 0): ?>
                                <form method="POST" action="clear.php" onsubmit="return confirm('Mark this creditor as cleared?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($clearCreditCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $creditor_id; ?>">
                                    <button type="submit" class="btn-custom success" style="flex: 1; justify-content: center;">
                                        <i class="fas fa-check-circle"></i> Mark as Cleared
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Payment History Section -->
                <div class="section-card" style="margin-top: var(--space-8);">
                    <div class="section-title">
                        <i class="fas fa-history" style="color: var(--yellow);"></i>
                        Payment History
                    </div>

                    <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <div class="empty-state-text">No payments recorded yet</div>
                    </div>
                    <?php else: ?>
                    <div class="payment-list">
                        <?php foreach ($payments as $payment): ?>
                        <div class="payment-item">
                            <div class="payment-header">
                                <div>
                                    <div class="payment-amount">₦<?php echo number_format($payment['amount'], 2); ?></div>
                                    <div class="payment-date">
                                        <i class="fas fa-calendar" style="margin-right: 4px;"></i><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    </div>
                                </div>
                                <span class="payment-method-badge <?php echo strtolower($payment['payment_method']); ?>">
                                    <?php echo ucfirst($payment['payment_method']); ?>
                                </span>
                            </div>
                            <?php if ($payment['notes']): ?>
                            <div class="payment-notes">
                                <i class="fas fa-note-sticky" style="margin-right: 4px;"></i><?php echo htmlspecialchars($payment['notes']); ?>
                            </div>
                            <?php endif; ?>
                            <div style="margin-top: var(--space-2); color: var(--primary-500); font-size: 0.8rem;">
                                <i class="fas fa-user" style="margin-right: 4px;"></i>Recorded by system
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="../../assets/js/clean-dashboard.js"></script>
</body>
</html>

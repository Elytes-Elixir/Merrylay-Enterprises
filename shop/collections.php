<?php
/**
 * Shop Expenses Management
 * 
 * Interface for shop attendants to record money spent from the shop
 * for depot financial matters and to account for shortages
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/middleware.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';
require_once __DIR__ . '/cash-reconciliation.php';

// Restrict access to shop attendants and managers (for viewing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isShopAttendant() && !hasRole(['admin', 'manager'])) {
    error_log('Unauthorized shop expense mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to record shop expenses.');
}
requireShopAttendantOrManager();

$error = '';
$success = $_SESSION['shop_expense_success'] ?? '';
unset($_SESSION['shop_expense_success']);
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected shop expense mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the expense details and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();
$reconciliationDate = $_GET['reconciliation_date'] ?? date('Y-m-d');
if (!shopCashReconciliationIsValidDate($reconciliationDate)) {
    $reconciliationDate = date('Y-m-d');
    $error = 'Invalid reconciliation date.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_cash_reconciliation']) && $csrfValid) {
    if (!isShopAttendant()) {
        http_response_code(403);
        exit('Only the responsible shop attendant may submit daily cash.');
    }

    $reconciliationDate = $_POST['reconciliation_date'] ?? '';
    $cashSubmitted = shopCashReconciliationParseAmount($_POST['cash_submitted'] ?? null);
    $reconciliationNotes = shopCashReconciliationParseNotes($_POST['reconciliation_notes'] ?? '');

    if (!shopCashReconciliationIsValidDate($reconciliationDate)) {
        $error = 'Enter a valid reconciliation date.';
    } elseif ($cashSubmitted === null || $reconciliationNotes === null) {
        $error = 'Cash submitted must be a valid non-negative amount with no more than two decimal places. Notes must be text no longer than 2000 characters.';
    } else {
        $pdo = null;
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();
            $existing_stmt = $pdo->prepare('SELECT id FROM shop_cash_reconciliations WHERE reconciliation_date = ? FOR UPDATE');
            $existing_stmt->execute([$reconciliationDate]);
            if ($existing_stmt->fetchColumn()) {
                throw new DomainException('Cash has already been submitted for this shop and date.');
            }

            $snapshot = shopCashReconciliationGetSnapshot($pdo, $reconciliationDate);
            $variance = round($cashSubmitted - $snapshot['expected_cash'], 2);
            $insert_stmt = $pdo->prepare("
                INSERT INTO shop_cash_reconciliations (
                    reconciliation_date, responsible_user_id, cash_sales_snapshot,
                    cash_shop_collections_snapshot, shop_expenses_snapshot,
                    acknowledged_returned_cash_snapshot, expected_cash, cash_submitted,
                    variance, notes, submitted_by, submitted_at, updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $insert_stmt->execute([
                $reconciliationDate,
                $_SESSION['user_id'],
                $snapshot['cash_sales_snapshot'],
                $snapshot['cash_shop_collections_snapshot'],
                $snapshot['shop_expenses_snapshot'],
                $snapshot['acknowledged_returned_cash_snapshot'],
                $snapshot['expected_cash'],
                $cashSubmitted,
                $variance,
                $reconciliationNotes,
                $_SESSION['user_id'],
                $_SESSION['user_id'],
            ]);
            $pdo->commit();

            $_SESSION['shop_cash_reconciliation_success'] = 'Daily shop cash submitted successfully.';
            header('Location: collections.php?reconciliation_date=' . urlencode($reconciliationDate));
            exit();
        } catch (PDOException $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                $error = 'Cash has already been submitted for this shop and date.';
            } else {
                error_log('Shop cash reconciliation submission failed: ' . $e->getMessage());
                $error = 'Unable to submit daily shop cash.';
            }
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e instanceof DomainException ? $e->getMessage() : 'Unable to submit daily shop cash.';
        }
    }
}

$success = $_SESSION['shop_cash_reconciliation_success'] ?? $success;
unset($_SESSION['shop_cash_reconciliation_success']);

$reconciliationAvailable = true;
$reconciliationSnapshot = [];
$cashReconciliation = null;
try {
    $pdo = getDBConnection();
    $reconciliationSnapshot = shopCashReconciliationGetSnapshot($pdo, $reconciliationDate);
    $reconciliation_stmt = $pdo->prepare("
        SELECT scr.*, u.name AS responsible_name
        FROM shop_cash_reconciliations scr
        JOIN users u ON u.id = scr.responsible_user_id
        WHERE scr.reconciliation_date = ?
    ");
    $reconciliation_stmt->execute([$reconciliationDate]);
    $cashReconciliation = $reconciliation_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    $reconciliationAvailable = false;
    error_log('Failed to load shop cash reconciliation: ' . $e->getMessage());
}

$reconciliationDisplay = $cashReconciliation ?? $reconciliationSnapshot;
$reconciliationVariance = isset($cashReconciliation['variance']) && $cashReconciliation['variance'] !== null
    ? (float) $cashReconciliation['variance']
    : null;
$reconciliationStatus = shopCashReconciliationGetStatus($reconciliationVariance);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_expense']) && $csrfValid) {
    $collector_name = trim($_POST['collector_name'] ?? '');
    $collector_role = trim($_POST['collector_role'] ?? '');
    $expense_purpose = trim($_POST['expense_purpose'] ?? '');
    $expense_amount = floatval($_POST['expense_amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($collector_name)) {
        $error = 'Collector name is required';
    } elseif (empty($collector_role)) {
        $error = 'Collector role is required';
    } elseif (empty($expense_purpose)) {
        $error = 'Expense purpose is required';
    } elseif ($expense_amount == 0) {
        $error = 'Expense amount cannot be zero';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Generate expense number
            $expense_number = 'EXP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare("
                INSERT INTO shop_collections 
                (collection_number, collection_date, collector_name, collector_role, collection_purpose, 
                 collection_amount, amount_acknowledged, balance_due, shop_attendant_id, shop_attendant_name, 
                 notes, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->execute([
                $expense_number,
                $expense_date,
                $collector_name,
                $collector_role,
                $expense_purpose,
                $expense_amount,
                $expense_amount,
                $_SESSION['user_id'],
                $_SESSION['full_name'] ?? $_SESSION['username'],
                $notes,
                $_SESSION['user_id']
            ]);
            
            $shop_expense_id = $pdo->lastInsertId();
            
            // Create creditor entry for the collector
            $creditor_name = "Shop Expense - {$collector_name}";
            $creditor_notes = "Expense #{$expense_number} for {$expense_purpose}. Spent by {$collector_name} ({$collector_role}) on {$expense_date}.";
            
            $creditor_stmt = $pdo->prepare("
                INSERT INTO creditors 
                (creditor_name, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, notes, status, created_by, created_at)
                VALUES (?, ?, 0, ?, ?, 0, ?, ?, 'active', ?, NOW())
            ");
            $creditor_stmt->execute([
                $creditor_name,
                $expense_amount,
                $expense_amount,
                $expense_date,
                $collector_name,
                $creditor_notes,
                $_SESSION['user_id']
            ]);
            
            $creditor_id = $pdo->lastInsertId();
            
            // Link the shop expense to the creditor
            $link_stmt = $pdo->prepare("UPDATE shop_collections SET creditor_id = ? WHERE id = ?");
            $link_stmt->execute([$creditor_id, $shop_expense_id]);
            
            $_SESSION['shop_expense_success'] = "Expense recorded successfully! Expense #: $expense_number. Creditor entry created.";
            header('Location: collections.php');
            exit();
            
        } catch (Exception $e) {
            $error = 'Failed to record expense: ' . $e->getMessage();
        }
    }
}

// Get recent expenses for current shop attendant
$recent_expenses = [];
try {
    $stmt = executeQuery("
        SELECT sc.*, 
               CASE 
                   WHEN sc.status = 'pending' THEN 'Pending'
                   WHEN sc.status = 'acknowledged' THEN 'Acknowledged'
                   WHEN sc.status = 'cleared' THEN 'Cleared'
               END as status_label
        FROM shop_collections sc
        WHERE sc.shop_attendant_id = ?
        ORDER BY sc.created_at DESC
        LIMIT 10
    ", [$_SESSION['user_id']]);
    $recent_expenses = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load recent expenses: " . $e->getMessage());
}

// Get today's total expenses for current attendant
$today_total = 0;
try {
    $stmt = executeQuery("
        SELECT COALESCE(SUM(collection_amount), 0) as total
        FROM shop_collections
        WHERE shop_attendant_id = ? AND DATE(collection_date) = CURDATE()
    ", [$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $today_total = $result['total'] ?? 0;
} catch (PDOException $e) {
    error_log("Failed to calculate today's total: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Collections - Merrylay Enterprises</title>
    
    <!-- CSS Framework -->
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        .expense-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
        }
        
        .expense-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--border-dark);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-pending {
            background: rgb(251 191 36 / 0.1);
            color: #f59e0b;
            border: 1px solid rgb(251 191 36 / 0.3);
        }
        
        .status-acknowledged {
            background: rgb(59 130 246 / 0.1);
            color: #3b82f6;
            border: 1px solid rgb(59 130 246 / 0.3);
        }
        
        .status-cleared {
            background: rgb(16 185 129 / 0.1);
            color: #10b981;
            border: 1px solid rgb(16 185 129 / 0.3);
        }
        
        .total-box {
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
            color: white;
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow-lg);
        }
    </style>
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
            
            <nav class="sidebar-nav">
                <!-- Main Operations Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Main Operations</div>
                    <a href="index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span>
                        <span class="nav-text">Shop Sales</span>
                    </a>
                    <a href="history.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-history"></i></span>
                        <span class="nav-text">Sales History</span>
                    </a>
                </div>

                <!-- Shop Operations Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Shop Operations</div>
                    <a href="collections.php" class="nav-link active">
                        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-text">Expenses</span>
                    </a>
                    <a href="collection-history.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-list-alt"></i></span>
                        <span class="nav-text">Expense History</span>
                    </a>
                    <a href="damaged.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
                        <span class="nav-text">Damaged Products</span>
                    </a>
                    <a href="daily-accounting.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                        <span class="nav-text">Daily Accounting</span>
                    </a>
                </div>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="../stock/supplies.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-truck-loading"></i></span>
                        <span class="nav-text">Supplies</span>
                    </a>
                    <a href="../finance/daily_balance.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                        <span class="nav-text">Daily Balance</span>
                    </a>
                    <a href="../reports/daily.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                        <span class="nav-text">Reports</span>
                    </a>
                </div>
                <?php endif; ?>

                <!-- System Section -->
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <a href="../auth/profile.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-user"></i></span>
                        <span class="nav-text">Profile</span>
                    </a>
                    <a href="../auth/logout.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="nav-text">Logout</span>
                    </a>
                </div>
            </nav>
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
                        <input type="text" placeholder="Search expenses..." id="globalSearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-menu dropdown">
                            <button class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User', 0, 2)); ?>
                                </div>
                                <div class="user-info d-none d-lg-block">
                                    <div class="user-name"><?php echo htmlspecialchars(ucwords($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')); ?></div>
                                    <div class="user-role">Shop Attendant</div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Alert Messages -->
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$reconciliationAvailable): ?>
                    <div class="alert alert-warning">Daily cash reconciliation is unavailable until its schema migration is applied.</div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-hand-holding-usd" style="margin-right: 15px;"></i>
                            Shop Expenses
                        </h1>
                        <p class="page-subtitle">
                            Record money spent from the shop for depot financial matters
                        </p>
                        <div class="d-flex gap-4 flex-wrap" style="margin-top: var(--space-3);">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-calendar-day" style="color: var(--primary-500);"></i>
                                <small><?php echo date('l, F j, Y'); ?></small>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-clock" style="color: var(--primary-500);"></i>
                                <small id="currentTime"><?php echo date('H:i'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($reconciliationAvailable): ?>
                <section class="content-card mb-4">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <h5 class="card-title mb-1">Daily Shop Cash Reconciliation</h5>
                            <p class="card-subtitle mb-0">Cash expenses affect expected physical cash only.</p>
                        </div>
                        <form method="GET" class="d-flex align-items-end gap-2">
                            <div>
                                <label for="reconciliation_date" class="form-label">Business date</label>
                                <input type="date" name="reconciliation_date" id="reconciliation_date" class="form-control" value="<?php echo htmlspecialchars($reconciliationDate, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-outline-primary">View</button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-4">
                                <tbody>
                                    <tr><th>Total Shop Sales</th><td class="text-end">₦<?php echo number_format($reconciliationSnapshot['total_shop_sales'], 2); ?></td></tr>
                                    <tr><th>Cash Sales</th><td class="text-end">₦<?php echo number_format($reconciliationDisplay['cash_sales_snapshot'] ?? 0, 2); ?></td></tr>
                                    <tr><th>Cash Shop Collections</th><td class="text-end">₦<?php echo number_format($reconciliationDisplay['cash_shop_collections_snapshot'] ?? 0, 2); ?></td></tr>
                                    <tr><th>Transfer / Card / Credit</th><td class="text-end">₦<?php echo number_format(($reconciliationSnapshot['transfer_sales'] ?? 0) + ($reconciliationSnapshot['card_sales'] ?? 0) + ($reconciliationSnapshot['credit_sales'] ?? 0), 2); ?> <small class="text-muted">(Transfer ₦<?php echo number_format($reconciliationSnapshot['transfer_sales'] ?? 0, 2); ?>, Card ₦<?php echo number_format($reconciliationSnapshot['card_sales'] ?? 0, 2); ?>, Credit ₦<?php echo number_format($reconciliationSnapshot['credit_sales'] ?? 0, 2); ?>)</small></td></tr>
                                    <tr><th>Shop Expenses</th><td class="text-end">₦<?php echo number_format($reconciliationDisplay['shop_expenses_snapshot'] ?? 0, 2); ?></td></tr>
                                    <tr><th>Acknowledged Returned Cash</th><td class="text-end">₦<?php echo number_format($reconciliationDisplay['acknowledged_returned_cash_snapshot'] ?? 0, 2); ?></td></tr>
                                    <tr class="table-info"><th>Expected Shop Cash</th><td class="text-end fw-bold">₦<?php echo number_format($cashReconciliation['expected_cash'] ?? $reconciliationSnapshot['expected_cash'], 2); ?></td></tr>
                                    <tr><th>Cash Submitted</th><td class="text-end"><?php echo $cashReconciliation && $cashReconciliation['cash_submitted'] !== null ? '₦' . number_format((float) $cashReconciliation['cash_submitted'], 2) : 'Not submitted'; ?></td></tr>
                                    <tr><th>Variance</th><td class="text-end"><?php echo $reconciliationVariance === null ? 'Not submitted' : '₦' . number_format($reconciliationVariance, 2); ?></td></tr>
                                    <tr><th>Status</th><td class="text-end"><span class="badge <?php echo $reconciliationStatus === 'Balanced' ? 'bg-success' : ($reconciliationStatus === 'Unsubmitted' ? 'bg-secondary' : 'bg-warning text-dark'); ?>"><?php echo htmlspecialchars($reconciliationStatus); ?></span></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($cashReconciliation): ?>
                            <p class="mb-1"><strong>Responsible attendant:</strong> <?php echo htmlspecialchars($cashReconciliation['responsible_name'] ?? ('User #' . $cashReconciliation['responsible_user_id'])); ?></p>
                            <p class="mb-1"><strong>Submitted:</strong> <?php echo htmlspecialchars($cashReconciliation['submitted_at'] ?? ''); ?></p>
                            <?php if ($cashReconciliation['acknowledged_at']): ?>
                                <p class="mb-1"><strong>Approved:</strong> <?php echo htmlspecialchars($cashReconciliation['acknowledged_at']); ?></p>
                            <?php endif; ?>
                            <?php if ($cashReconciliation['notes']): ?>
                                <p class="mb-0"><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($cashReconciliation['notes'])); ?></p>
                            <?php endif; ?>
                            <?php if (hasRole(['admin', 'manager'])): ?>
                                <a class="btn btn-outline-primary mt-3" href="collection-history.php?date=<?php echo urlencode($reconciliationDate); ?>">Correct or approve</a>
                            <?php endif; ?>
                        <?php elseif (isShopAttendant()): ?>
                            <form method="POST" class="row g-3 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="reconciliation_date" value="<?php echo htmlspecialchars($reconciliationDate, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="col-md-4">
                                    <label for="cash_submitted" class="form-label">Cash Submitted by Attendant</label>
                                    <div class="input-group"><span class="input-group-text">₦</span><input type="number" name="cash_submitted" id="cash_submitted" class="form-control" min="0" max="9999999999.99" step="0.01" required></div>
                                </div>
                                <div class="col-md-5">
                                    <label for="reconciliation_notes" class="form-label">Notes</label>
                                    <input type="text" name="reconciliation_notes" id="reconciliation_notes" class="form-control" maxlength="2000">
                                </div>
                                <div class="col-md-3"><button type="submit" name="submit_cash_reconciliation" class="btn btn-primary w-100">Submit Cash</button></div>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Form Container -->
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-plus-circle" style="margin-right: 10px;"></i>
                                        Record New Expense
                                    </h5>
                                    <p class="card-subtitle">Enter expense details</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="expenseForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="collector_name" class="form-label">Collector Name *</label>
                                                <input type="text" name="collector_name" id="collector_name" class="form-control" placeholder="Name of person collecting money" required>
                                                <small class="text-muted">Who is collecting the money from the shop</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="collector_role" class="form-label">Collector Role *</label>
                                                <select name="collector_role" id="collector_role" class="form-select" required>
                                                    <option value="">Select Role</option>
                                                    <option value="Manager">Manager</option>
                                                    <option value="Accountant">Accountant</option>
                                                    <option value="Owner">Owner</option>
                                                    <option value="Supervisor">Supervisor</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                                <small class="text-muted">Role of the person collecting</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="expense_purpose" class="form-label">Expense Purpose *</label>
                                                <input type="text" name="expense_purpose" id="expense_purpose" class="form-control" placeholder="e.g., Bank deposit, Office expenses" required>
                                                <small class="text-muted">What is the money meant for</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="expense_amount" class="form-label">Expense Amount (₦) *</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₦</span>
                                                    <input type="number" name="expense_amount" id="expense_amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
                                                </div>
                                                <small class="text-muted">Amount being spent</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="expense_date" class="form-label">Expense Date *</label>
                                                <input type="date" name="expense_date" id="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="notes" class="form-label">Notes</label>
                                                <textarea name="notes" id="notes" class="form-control" rows="1" placeholder="Additional details (optional)"></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="action-buttons mt-4">
                                        <button type="submit" name="record_expense" class="btn btn-primary">
                                            <i class="fas fa-save"></i>
                                            Record Expense
                                        </button>
                                        <button type="reset" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Summary & Recent Expenses -->
                    <div class="col-lg-4">
                        <div class="content-card mb-3">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-chart-line"></i> Today's Summary
                                    </h5>
                                    <p class="card-subtitle">Expenses recorded today</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="total-box">
                                    <small class="d-block mb-2">Total Spent Today</small>
                                    <h3 class="mb-0">₦<?php echo number_format($today_total, 2); ?></h3>
                                    <small style="opacity: 0.8;">From <?php echo count($recent_expenses); ?> expense(s)</small>
                                </div>
                            </div>
                        </div>

                        <div class="content-card" style="position: sticky; top: 20px;">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-history"></i> Recent Expenses
                                    </h5>
                                    <p class="card-subtitle">Your recent records</p>
                                </div>
                            </div>
                            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                <?php if (count($recent_expenses) > 0): ?>
                                    <?php foreach ($recent_expenses as $expense): ?>
                                        <div class="expense-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <div style="font-weight: 600; color: var(--primary-800);">
                                                        <?php echo htmlspecialchars($expense['collection_number']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($expense['collector_name']); ?> 
                                                        (<?php echo htmlspecialchars($expense['collector_role']); ?>)
                                                    </small>
                                                </div>
                                                <span class="status-badge status-<?php echo $expense['status']; ?>">
                                                    <?php echo htmlspecialchars($expense['status_label']); ?>
                                                </span>
                                            </div>
                                            <div style="font-size: 12px; color: var(--primary-600); margin-bottom: 8px;">
                                                <?php echo htmlspecialchars($expense['collection_purpose']); ?>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div style="font-weight: 700; color: var(--green);">
                                                    ₦<?php echo number_format($expense['collection_amount'], 2); ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo date('M d, H:i', strtotime($expense['created_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center">No expenses recorded yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
    <script>
        // Force sidebar to be expanded for shop pages
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            // Remove collapsed state
            if (sidebar) {
                sidebar.classList.remove('collapsed');
            }
            if (mainContent) {
                mainContent.classList.remove('expanded');
            }
            
            // Clear localStorage preference
            localStorage.setItem('sidebarCollapsed', 'false');
            
            
            // Update time (GMT+1)
            setInterval(function() {
                const now = new Date();
                // Convert to GMT+1 (West Africa Time)
                const gmtPlus1 = new Date(now.toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
                const timeElement = document.getElementById('currentTime');
                if (timeElement) {
                    timeElement.textContent = gmtPlus1.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                }
            }, 1000);
        });
    </script>
</body>
</html>

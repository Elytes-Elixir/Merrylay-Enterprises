<?php
/**
 * Shop Expense History
 * 
 * View and manage shop expense records for shop attendants
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
    error_log('Unauthorized shop expense update by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to update shop expenses.');
}
requireShopAttendantOrManager();

$error = '';
$success = $_SESSION['shop_expense_update_success'] ?? '';
unset($_SESSION['shop_expense_update_success']);
$success = $_SESSION['shop_cash_reconciliation_success'] ?? $success;
unset($_SESSION['shop_cash_reconciliation_success']);
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected shop expense update: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the update and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cash_reconciliation']) && $csrfValid) {
    if (!hasRole(['admin', 'manager'])) {
        http_response_code(403);
        exit('You do not have permission to correct or approve shop cash.');
    }

    $reconciliation_id = filter_var($_POST['reconciliation_id'] ?? null, FILTER_VALIDATE_INT);
    $cash_submitted = shopCashReconciliationParseAmount($_POST['cash_submitted'] ?? null);
    $reconciliation_notes = shopCashReconciliationParseNotes($_POST['reconciliation_notes'] ?? '');
    $approve_reconciliation = isset($_POST['approve_reconciliation']) ? 1 : 0;

    if (!$reconciliation_id || $cash_submitted === null || $reconciliation_notes === null) {
        $error = 'Enter valid submitted cash and notes (up to 2000 characters).';
    } else {
        $pdo = null;
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();
            $reconciliation_stmt = $pdo->prepare('SELECT reconciliation_date FROM shop_cash_reconciliations WHERE id = ? FOR UPDATE');
            $reconciliation_stmt->execute([$reconciliation_id]);
            $reconciliation_date = $reconciliation_stmt->fetchColumn();
            if (!$reconciliation_date) {
                throw new DomainException('Shop cash reconciliation not found.');
            }

            $snapshot = shopCashReconciliationGetSnapshot($pdo, $reconciliation_date);
            $variance = round($cash_submitted - $snapshot['expected_cash'], 2);
            $update_stmt = $pdo->prepare("
                UPDATE shop_cash_reconciliations
                SET cash_sales_snapshot = ?,
                    cash_shop_collections_snapshot = ?,
                    shop_expenses_snapshot = ?,
                    acknowledged_returned_cash_snapshot = ?,
                    expected_cash = ?,
                    cash_submitted = ?,
                    variance = ?,
                    notes = ?,
                    acknowledged_by = CASE WHEN ? = 1 THEN ? ELSE NULL END,
                    acknowledged_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                    updated_by = ?
                WHERE id = ?
            ");
            $update_stmt->execute([
                $snapshot['cash_sales_snapshot'],
                $snapshot['cash_shop_collections_snapshot'],
                $snapshot['shop_expenses_snapshot'],
                $snapshot['acknowledged_returned_cash_snapshot'],
                $snapshot['expected_cash'],
                $cash_submitted,
                $variance,
                $reconciliation_notes,
                $approve_reconciliation,
                $_SESSION['user_id'],
                $approve_reconciliation,
                $_SESSION['user_id'],
                $reconciliation_id,
            ]);
            $pdo->commit();

            $_SESSION['shop_cash_reconciliation_success'] = $approve_reconciliation
                ? 'Shop cash reconciliation updated and approved.'
                : 'Shop cash reconciliation updated. Approval was cleared.';
            header('Location: collection-history.php?' . http_build_query(['date' => $reconciliation_date]));
            exit();
        } catch (PDOException $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Shop cash reconciliation update failed: ' . $e->getMessage());
            $error = 'Unable to update shop cash reconciliation.';
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e instanceof DomainException ? $e->getMessage() : 'Unable to update shop cash reconciliation.';
        }
    }
}

// Get filter parameters
$date_filter = $_GET['date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Handle expense update (acknowledge returned amount)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_expense']) && $csrfValid) {
    $expense_id = intval($_POST['expense_id'] ?? 0);
    $acknowledged_amount = floatval($_POST['acknowledged_amount'] ?? 0);
    $new_status = $_POST['status'] ?? 'pending';
    
    if ($expense_id > 0 && $acknowledged_amount >= 0) {
        try {
            $pdo = getDBConnection();
            
            // Get current expense details
            $stmt = $pdo->prepare("SELECT * FROM shop_collections WHERE id = ? AND shop_attendant_id = ?");
            $stmt->execute([$expense_id, $_SESSION['user_id']]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($expense) {
                $balance_due = $expense['collection_amount'] - $acknowledged_amount;
                
                // Update expense
                $update_stmt = $pdo->prepare("
                    UPDATE shop_collections 
                    SET amount_acknowledged = ?,
                        balance_due = ?,
                        status = ?,
                        acknowledged_at = CASE WHEN ? > 0 THEN NOW() ELSE acknowledged_at END,
                        acknowledged_by = CASE WHEN ? > 0 THEN ? ELSE acknowledged_by END,
                        cleared_at = CASE WHEN ? = 'cleared' THEN NOW() ELSE cleared_at END,
                        updated_at = NOW()
                    WHERE id = ? AND shop_attendant_id = ?
                ");
                $update_stmt->execute([
                    $acknowledged_amount,
                    $balance_due,
                    $new_status,
                    $acknowledged_amount,
                    $acknowledged_amount,
                    $_SESSION['user_id'],
                    $new_status,
                    $expense_id,
                    $_SESSION['user_id']
                ]);
                
                // Clear creditor entry when expense is cleared
                if ($new_status === 'cleared' && !empty($expense['creditor_id'])) {
                    $clear_creditor_stmt = $pdo->prepare("
                        UPDATE creditors 
                        SET status = 'cleared', 
                            amount_paid = credit_amount,
                            balance = 0,
                            cleared_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $clear_creditor_stmt->execute([$expense['creditor_id']]);
                }
                
                $_SESSION['shop_expense_update_success'] = 'Expense updated successfully!';
                header('Location: collection-history.php?' . http_build_query([
                    'date' => $date_filter,
                    'status' => $status_filter,
                    'search' => $search
                ]));
                exit();
            } else {
                $error = "Expense not found or you don't have permission to update it.";
            }
        } catch (Exception $e) {
            $error = "Failed to update expense: " . $e->getMessage();
        }
    } else {
        $error = "Invalid input data.";
    }
}

// Get shop expenses for current shop attendant (or all if manager/admin)
$expenses = [];
try {
    $query = "
        SELECT sc.*,
               CASE 
                   WHEN sc.status = 'pending' THEN 'Pending'
                   WHEN sc.status = 'acknowledged' THEN 'Acknowledged'
                   WHEN sc.status = 'cleared' THEN 'Cleared'
               END as status_label
        FROM shop_collections sc
        WHERE 1=1
    ";

    $params = [];

    // Only filter by shop_attendant_id if user is not admin or manager
    if (!hasRole(['admin', 'manager'])) {
        $query .= " AND sc.shop_attendant_id = ?";
        $params[] = $_SESSION['user_id'];
    }

    if ($date_filter) {
        $query .= " AND DATE(sc.collection_date) = ?";
        $params[] = $date_filter;
    }

    if ($status_filter) {
        $query .= " AND sc.status = ?";
        $params[] = $status_filter;
    }

    if ($search) {
        $query .= " AND (sc.collection_number LIKE ? OR sc.collector_name LIKE ? OR sc.collection_purpose LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $query .= " ORDER BY sc.created_at DESC";

    if (!empty($params)) {
        $stmt = executeQuery($query, $params);
    } else {
        $stmt = executeQuery($query);
    }

    $expenses = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load shop expenses: " . $e->getMessage());
    $error = 'Failed to load expense history';
}

$reconciliationAvailable = true;
$reconciliationSnapshot = [];
$cashReconciliation = null;
try {
    if (!shopCashReconciliationIsValidDate($date_filter)) {
        throw new InvalidArgumentException('Invalid reconciliation date.');
    }

    $pdo = getDBConnection();
    $reconciliationSnapshot = shopCashReconciliationGetSnapshot($pdo, $date_filter);
    $reconciliation_stmt = $pdo->prepare("
        SELECT scr.*, u.name AS responsible_name
        FROM shop_cash_reconciliations scr
        JOIN users u ON u.id = scr.responsible_user_id
        WHERE scr.reconciliation_date = ?
    ");
    $reconciliation_stmt->execute([$date_filter]);
    $cashReconciliation = $reconciliation_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    $reconciliationAvailable = false;
    error_log('Failed to load shop cash reconciliation history: ' . $e->getMessage());
} catch (InvalidArgumentException $e) {
    $reconciliationAvailable = false;
    $error = 'Enter a valid date to view cash reconciliation.';
}

$reconciliationVariance = isset($cashReconciliation['variance']) && $cashReconciliation['variance'] !== null
    ? (float) $cashReconciliation['variance']
    : null;
$reconciliationStatus = shopCashReconciliationGetStatus($reconciliationVariance);

// Calculate totals
$total_expenses = 0;
$total_acknowledged = 0;
$total_balance = 0;
$net_expenses = 0;

foreach ($expenses as $expense) {
    $total_expenses += $expense['collection_amount'];
    $total_acknowledged += $expense['amount_acknowledged'];
    $total_balance += $expense['balance_due'];
}
// Net expenses after refunds = total_expenses - total_acknowledged
$net_expenses = $total_expenses - $total_acknowledged;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense History - Merrylay Enterprises</title>
    
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
        
        .summary-card {
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
            color: white;
            padding: var(--space-4);
            border-radius: var(--radius-lg);
            text-align: center;
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
                    <a href="collections.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-text">Expenses</span>
                    </a>
                    <a href="collection-history.php" class="nav-link active">
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
                    <div class="alert alert-warning">Daily cash reconciliation is unavailable until its schema migration is applied, or the selected date is invalid.</div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-list-alt" style="margin-right: 15px;"></i>
                            Expense History
                        </h1>
                        <p class="page-subtitle">
                            View and manage shop expense records
                        </p>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
                    <div class="col">
                        <div class="summary-card">
                            <small class="d-block mb-2">Net Spent (After Refunds)</small>
                            <h3 class="mb-0">₦<?php echo number_format($net_expenses, 2); ?></h3>
                        </div>
                    </div>
                    <div class="col">
                        <div class="summary-card" style="background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);">
                            <small class="d-block mb-2">Total Acknowledged (Returned)</small>
                            <h3 class="mb-0">₦<?php echo number_format($total_acknowledged, 2); ?></h3>
                        </div>
                    </div>
                    <div class="col">
                        <div class="summary-card" style="background: linear-gradient(135deg, var(--orange) 0%, var(--orange-dark) 100%);">
                            <small class="d-block mb-2">Balance Due</small>
                            <h3 class="mb-0">₦<?php echo number_format($total_balance, 2); ?></h3>
                        </div>
                    </div>
                </div>

                <?php if ($reconciliationAvailable): ?>
                <section class="content-card mb-4">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <h5 class="card-title mb-1">Daily Shop Cash Reconciliation</h5>
                            <p class="card-subtitle mb-0">Business date: <?php echo htmlspecialchars($date_filter, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-4">
                                <tbody>
                                    <tr><th>Total Shop Sales</th><td class="text-end">₦<?php echo number_format($reconciliationSnapshot['total_shop_sales'], 2); ?></td></tr>
                                    <tr><th>Cash Sales</th><td class="text-end">₦<?php echo number_format($cashReconciliation['cash_sales_snapshot'] ?? $reconciliationSnapshot['cash_sales_snapshot'], 2); ?></td></tr>
                                    <tr><th>Cash Shop Collections</th><td class="text-end">₦<?php echo number_format($cashReconciliation['cash_shop_collections_snapshot'] ?? $reconciliationSnapshot['cash_shop_collections_snapshot'], 2); ?></td></tr>
                                    <tr><th>Transfer / Card / Credit</th><td class="text-end">₦<?php echo number_format($reconciliationSnapshot['transfer_sales'] + $reconciliationSnapshot['card_sales'] + $reconciliationSnapshot['credit_sales'], 2); ?> <small class="text-muted">(Transfer ₦<?php echo number_format($reconciliationSnapshot['transfer_sales'], 2); ?>, Card ₦<?php echo number_format($reconciliationSnapshot['card_sales'], 2); ?>, Credit ₦<?php echo number_format($reconciliationSnapshot['credit_sales'], 2); ?>)</small></td></tr>
                                    <tr><th>Shop Expenses</th><td class="text-end">₦<?php echo number_format($cashReconciliation['shop_expenses_snapshot'] ?? $reconciliationSnapshot['shop_expenses_snapshot'], 2); ?></td></tr>
                                    <tr><th>Acknowledged Returned Cash</th><td class="text-end">₦<?php echo number_format($cashReconciliation['acknowledged_returned_cash_snapshot'] ?? $reconciliationSnapshot['acknowledged_returned_cash_snapshot'], 2); ?></td></tr>
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
                                <p class="mb-3"><strong>Approved:</strong> <?php echo htmlspecialchars($cashReconciliation['acknowledged_at']); ?></p>
                            <?php endif; ?>
                            <?php if ($cashReconciliation['notes']): ?>
                                <p class="mb-3"><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($cashReconciliation['notes'])); ?></p>
                            <?php endif; ?>
                            <?php if (hasRole(['admin', 'manager'])): ?>
                                <form method="POST" class="row g-3 align-items-end">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="reconciliation_id" value="<?php echo (int) $cashReconciliation['id']; ?>">
                                    <div class="col-md-3">
                                        <label for="cash_submitted" class="form-label">Corrected cash submitted</label>
                                        <div class="input-group"><span class="input-group-text">₦</span><input type="number" name="cash_submitted" id="cash_submitted" class="form-control" min="0" max="9999999999.99" step="0.01" value="<?php echo htmlspecialchars((string) $cashReconciliation['cash_submitted'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                                    </div>
                                    <div class="col-md-5">
                                        <label for="reconciliation_notes" class="form-label">Notes</label>
                                        <input type="text" name="reconciliation_notes" id="reconciliation_notes" class="form-control" maxlength="2000" value="<?php echo htmlspecialchars($cashReconciliation['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-md-2 form-check mb-2">
                                        <input type="checkbox" name="approve_reconciliation" id="approve_reconciliation" class="form-check-input" value="1" <?php echo $cashReconciliation['acknowledged_at'] ? 'checked' : ''; ?>>
                                        <label for="approve_reconciliation" class="form-check-label">Approve</label>
                                    </div>
                                    <div class="col-md-2"><button type="submit" name="update_cash_reconciliation" class="btn btn-primary w-100">Save</button></div>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="mb-0 text-muted">No cash submission exists for this date. The attendant can submit it from the Expenses page.</p>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Filters -->
                <div class="content-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" id="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="acknowledged" <?php echo $status_filter === 'acknowledged' ? 'selected' : ''; ?>>Acknowledged</option>
                                    <option value="cleared" <?php echo $status_filter === 'cleared' ? 'selected' : ''; ?>>Cleared</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" name="search" id="search" class="form-control" placeholder="Search by number, collector, or purpose..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Expenses List -->
                <?php if (count($expenses) > 0): ?>
                    <?php foreach ($expenses as $expense): ?>
                        <div class="expense-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 style="color: var(--primary-800); font-weight: 600;">
                                        <?php echo htmlspecialchars($expense['collection_number']); ?>
                                    </h5>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y H:i', strtotime($expense['created_at'])); ?>
                                    </small>
                                </div>
                                <span class="status-badge status-<?php echo $expense['status']; ?>">
                                    <?php echo htmlspecialchars($expense['status_label']); ?>
                                </span>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div style="font-size: 13px; color: var(--primary-600);">
                                        <strong>Collector:</strong> <?php echo htmlspecialchars($expense['collector_name']); ?>
                                        <span class="text-muted">(<?php echo htmlspecialchars($expense['collector_role']); ?>)</span>
                                    </div>
                                    <div style="font-size: 13px; color: var(--primary-600); margin-top: 4px;">
                                        <strong>Purpose:</strong> <?php echo htmlspecialchars($expense['collection_purpose']); ?>
                                    </div>
                                    <?php if ($expense['cleared_at']): ?>
                                        <div style="font-size: 11px; color: var(--green); margin-top: 4px;">
                                            <i class="fas fa-check-circle me-1"></i>
                                            Cleared on <?php echo date('M d, Y H:i', strtotime($expense['cleared_at'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 text-end">
                                    <div style="font-size: 18px; font-weight: 700; color: var(--green);">
                                        ₦<?php echo number_format($expense['collection_amount'], 2); ?>
                                    </div>
                                    <small class="text-muted">
                                        Acknowledged: ₦<?php echo number_format($expense['amount_acknowledged'], 2); ?>
                                        <?php if ($expense['balance_due'] > 0): ?>
                                            | Balance: ₦<?php echo number_format($expense['balance_due'], 2); ?>
                                        <?php else: ?>
                                            | <span class="text-success">Fully Settled</span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                            
                            <?php if ($expense['notes']): ?>
                                <div style="font-size: 12px; color: var(--primary-500); margin-top: 8px; padding: 8px; background: var(--bg-primary); border-radius: var(--radius-md);">
                                    <i class="fas fa-sticky-note me-2"></i><?php echo htmlspecialchars($expense['notes']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($expense['acknowledged_at']): ?>
                                <div style="font-size: 11px; color: var(--green); margin-top: 8px;">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Acknowledged on <?php echo date('M d, Y H:i', strtotime($expense['acknowledged_at'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Update Expense Button -->
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-light);">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $expense['id']; ?>">
                                    <i class="fas fa-edit me-1"></i> Update Returned Amount
                                </button>
                            </div>
                        </div>
                        
                        <!-- Update Modal -->
                        <div class="modal fade" id="updateModal<?php echo $expense['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Update Returned Expense</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="modal-body">
                                            <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Expense Number</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($expense['collection_number']); ?>" readonly>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Original Amount</label>
                                                <input type="text" class="form-control" value="₦<?php echo number_format($expense['collection_amount'], 2); ?>" readonly>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="acknowledged_amount" class="form-label">Returned/Acknowledged Amount</label>
                                                <input type="number" name="acknowledged_amount" id="acknowledged_amount" class="form-control" 
                                                       value="<?php echo $expense['amount_acknowledged']; ?>" 
                                                       min="0" max="<?php echo $expense['collection_amount']; ?>" step="0.01" required>
                                                <small class="text-muted">Enter the amount that has been returned</small>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="status" class="form-label">Status</label>
                                                <select name="status" id="status" class="form-select">
                                                    <option value="pending" <?php echo $expense['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="acknowledged" <?php echo $expense['status'] === 'acknowledged' ? 'selected' : ''; ?>>Acknowledged</option>
                                                    <option value="cleared" <?php echo $expense['status'] === 'cleared' ? 'selected' : ''; ?>>Cleared</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="update_expense" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Update Expense
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <h6>No Expenses Found</h6>
                        <p>No expenses recorded for the selected criteria.</p>
                    </div>
                <?php endif; ?>
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
            
        });
    </script>
</body>
</html>

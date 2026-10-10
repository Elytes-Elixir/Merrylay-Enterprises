<?php
/**
 * Customer Payments Page for Merrylay Enterprises
 *
 * Record payments received from customers (cash, transfers, POS, etc.)
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/cache.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

$error = '';
$success = $_SESSION['customer_payment_success'] ?? '';
unset($_SESSION['customer_payment_success']);
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected customer payment mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the payment details and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $canCreatePayment = hasRole(['admin', 'manager', 'cashier', 'dispatcher']);
    $canManagePayment = hasRole(['admin', 'manager']);
    if ((isset($_POST['add_payment']) && !$canCreatePayment)
        || ((isset($_POST['edit_payment']) || isset($_POST['delete_payment'])) && !$canManagePayment)) {
        error_log('Unauthorized customer payment mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to perform this payment action.');
    }
}
$selected_date = $_GET['date'] ?? date('Y-m-d');
$dispatcher_filter = $_GET['dispatcher'] ?? '';

// Get unique dispatchers for filter
try {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches ORDER BY dispatcher_name");
    $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $dispatchers = [];
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment']) && $csrfValid) {
    if (!hasRole(['admin', 'manager', 'cashier', 'dispatcher'])) {
        error_log('Unauthorized customer payment creation attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        $error = 'You do not have permission to record customer payments.';
    } else {
    $dispatcher_name = trim($_POST['dispatcher_name'] ?? '');
    $payment_date = $_POST['payment_date'] ?? '';
    $cash_amount = floatval($_POST['cash_amount'] ?? 0);
    $transfer_amount = floatval($_POST['transfer_amount'] ?? 0);
    $reference_number = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (empty($dispatcher_name)) {
        $error = 'Dispatcher name is required.';
    } elseif ($cash_amount <= 0 && $transfer_amount <= 0) {
        $error = 'Please enter at least one payment amount (cash or transfer).';
    } else {
        try {
            // Insert cash payment if amount > 0
            if ($cash_amount > 0) {
                $query = "INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, notes, recorded_by, created_at)
                         VALUES (?, ?, 'cash', ?, ?, ?, NOW())";
                $stmt = executeQuery($query, [
                    $dispatcher_name,
                    $payment_date,
                    $cash_amount,
                    $notes,
                    $_SESSION['user_id']
                ]);
                if (!$stmt) {
                    $error = 'Failed to insert cash payment.';
                }
            }

            // Insert transfer payment if amount > 0
            if ($transfer_amount > 0) {
                $query = "INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, notes, recorded_by, created_at)
                         VALUES (?, ?, 'transfer', ?, ?, ?, NOW())";
                $stmt = executeQuery($query, [
                    $dispatcher_name,
                    $payment_date,
                    $transfer_amount,
                    $notes,
                    $_SESSION['user_id']
                ]);
                if (!$stmt) {
                    $error = 'Failed to insert transfer payment.';
                }
            }

            if (empty($error)) {
                $_SESSION['customer_payment_success'] = 'Payment(s) recorded successfully!';
                cache_clear();
                header('Location: customer-payments.php?' . http_build_query([
                    'date' => $selected_date,
                    'dispatcher' => $dispatcher_filter
                ]));
                exit();
            }
        } catch (PDOException $e) {
            error_log("Add payment error: " . $e->getMessage());
            $error = 'Unable to record payment: ' . $e->getMessage();
        }
    }
    }
}

// Handle edit payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_payment']) && $csrfValid && hasRole(['admin', 'manager'])) {
    $payment_id = intval($_POST['payment_id'] ?? 0);
    $new_amount = floatval($_POST['new_amount'] ?? 0);
    $new_notes = trim($_POST['new_notes'] ?? '');

    if ($payment_id <= 0) {
        $error = 'Invalid payment ID.';
    } elseif ($new_amount <= 0) {
        $error = 'Amount must be greater than 0.';
    } else {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // Get original payment details
            $stmt = $pdo->prepare("SELECT * FROM customer_payments WHERE id = ?");
            $stmt->execute([$payment_id]);
            $original_payment = $stmt->fetch();

            if (!$original_payment) {
                throw new Exception("Payment not found.");
            }

            // Get dispatch to update actual_sales_amount
            $stmt = $pdo->prepare("
                SELECT d.id FROM dispatches d
                WHERE d.dispatcher_name = ? AND DATE(d.dispatch_date) = ?
                LIMIT 1
            ");
            $stmt->execute([$original_payment['dispatcher_name'], $original_payment['payment_date']]);
            $dispatch = $stmt->fetch();

            if ($dispatch) {
                // Update dispatch actual_sales_amount (remove old amount, add new amount)
                $amount_diff = $new_amount - $original_payment['amount'];
                $stmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount + ? WHERE id = ?");
                $stmt->execute([$amount_diff, $dispatch['id']]);
            }

            // Update the payment
            $stmt = $pdo->prepare("UPDATE customer_payments SET amount = ?, notes = ? WHERE id = ?");
            $stmt->execute([$new_amount, $new_notes, $payment_id]);

            $pdo->commit();
            cache_clear();
            $success = 'Payment updated successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Edit payment error: " . $e->getMessage());
            $error = 'Unable to update payment: ' . $e->getMessage();
        }
    }
}

// Handle delete payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment']) && $csrfValid && hasRole(['admin', 'manager'])) {
    $payment_id = intval($_POST['payment_id'] ?? 0);

    if ($payment_id <= 0) {
        $error = 'Invalid payment ID.';
    } else {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // Get payment details
            $stmt = $pdo->prepare("SELECT * FROM customer_payments WHERE id = ?");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch();

            if (!$payment) {
                throw new Exception("Payment not found.");
            }

            // Get dispatch to update actual_sales_amount
            $stmt = $pdo->prepare("
                SELECT d.id FROM dispatches d
                WHERE d.dispatcher_name = ? AND DATE(d.dispatch_date) = ?
                LIMIT 1
            ");
            $stmt->execute([$payment['dispatcher_name'], $payment['payment_date']]);
            $dispatch = $stmt->fetch();

            if ($dispatch) {
                // Update dispatch actual_sales_amount (subtract the deleted amount)
                $stmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount - ? WHERE id = ?");
                $stmt->execute([$payment['amount'], $dispatch['id']]);
            }

            // Delete the payment
            $stmt = $pdo->prepare("DELETE FROM customer_payments WHERE id = ?");
            $stmt->execute([$payment_id]);

            $pdo->commit();
            cache_clear();
            $success = 'Payment deleted successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Delete payment error: " . $e->getMessage());
            $error = 'Unable to delete payment: ' . $e->getMessage();
        }
    }
}

// Get payments for the selected date and dispatcher
$payments = [];
$payments_total = 0;
$cash_total = 0;
$transfer_total = 0;

if (!empty($dispatcher_filter)) {
    try {
        $stmt = executeQuery("
            SELECT cp.*, u.name as recorded_by_name
            FROM customer_payments cp
            LEFT JOIN users u ON cp.recorded_by = u.id
            WHERE cp.dispatcher_name = ? AND DATE(cp.payment_date) = ?
            ORDER BY cp.created_at DESC
        ", [$dispatcher_filter, $selected_date]);

        $payments = $stmt->fetchAll();

        // Calculate totals
        $stmt = executeQuery("
            SELECT
                SUM(amount) as total,
                SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_total,
                SUM(CASE WHEN payment_method = 'transfer' THEN amount ELSE 0 END) as transfer_total
            FROM customer_payments
            WHERE dispatcher_name = ? AND DATE(payment_date) = ?
        ", [$dispatcher_filter, $selected_date]);

        $total_result = $stmt->fetch();
        $payments_total = $total_result['total'] ?? 0;
        $cash_total = $total_result['cash_total'] ?? 0;
        $transfer_total = $total_result['transfer_total'] ?? 0;

    } catch (PDOException $e) {
        error_log("Get payments error: " . $e->getMessage());
        $error = 'Unable to load payments.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Payments - Merrylay Enterprises</title>
    <link href="../assets/fonts/inter/inter.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
        }
        .page-header h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
        }
        .page-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }
        .form-card h5 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .payments-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .payments-table .table-header {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
            padding: 1rem;
            border-bottom: 1px solid #d1d5db;
        }
        .payments-table .table-header h5 {
            margin: 0;
            font-weight: 700;
            color: #374151;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .summary-card {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .summary-card.total { border-left-color: #10b981; }
        .summary-card.cash { border-left-color: #3b82f6; }
        .summary-card.transfer { border-left-color: #059669; }
        .summary-card h6 {
            color: #6b7280;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        .summary-card .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
        }
        .btn-payment {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }
        .btn-payment:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
        }
        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
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
                    <h1><i class="fas fa-money-bill-wave me-2"></i>Customer Payments</h1>
                    <p>Record payments received from customers (cash and transfers)</p>
                </div>

                <!-- Filters -->
                <div class="filter-row">
                    <div class="form-group">
                        <label for="date" class="form-label">Select Date</label>
                        <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="updateFilters()">
                    </div>

                    <div class="form-group">
                        <label for="dispatcher" class="form-label">Select Dispatcher</label>
                        <select id="dispatcher" name="dispatcher" class="form-select" onchange="updateFilters()">
                            <option value="">Select Dispatcher</option>
                            <?php foreach ($dispatchers as $disp): ?>
                                <option value="<?php echo htmlspecialchars($disp); ?>" <?php echo $disp === $dispatcher_filter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($disp); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <a href="daily-accounting.php?date=<?php echo $selected_date; ?>&dispatcher=<?php echo urlencode($dispatcher_filter); ?>" class="btn btn-primary">
                        <i class="fas fa-chart-line me-2"></i>View Accounting
                    </a>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-4">
                        <!-- Payment Form -->
                        <div class="form-card">
                            <h5><i class="fas fa-plus-circle me-2"></i>Record Payment</h5>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="mb-3">
                                    <label class="form-label">Dispatcher Name <span class="text-danger">*</span></label>
                                    <select name="dispatcher_name" class="form-select" required>
                                        <option value="">Select Dispatcher</option>
                                        <?php foreach ($dispatchers as $disp): ?>
                                            <option value="<?php echo htmlspecialchars($disp); ?>" <?php echo $disp === $dispatcher_filter ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($disp); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" name="payment_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                                </div>

                                <hr>
                                <h6 class="text-primary mb-3"><i class="fas fa-money-bill me-2"></i>Cash Payment</h6>

                                <div class="mb-3">
                                    <label class="form-label">Cash Amount (₦)</label>
                                    <input type="number" name="cash_amount" class="form-control" step="0.01" min="0" placeholder="0.00">
                                </div>

                                <hr>
                                <h6 class="text-success mb-3"><i class="fas fa-university me-2"></i>Bank Transfer</h6>

                                <div class="mb-3">
                                    <label class="form-label">Transfer Amount (₦)</label>
                                    <input type="number" name="transfer_amount" class="form-control" step="0.01" min="0" placeholder="0.00">
                                </div>

                                <hr>
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                                </div>

                                <button type="submit" name="add_payment" class="btn btn-payment w-100">
                                    <i class="fas fa-save me-2"></i>Record Payment
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <?php if (!empty($dispatcher_filter)): ?>
                            <!-- Summary Cards -->
                            <div class="summary-cards">
                                <div class="summary-card total">
                                    <h6><i class="fas fa-money-bill-wave me-2"></i>Total Payments</h6>
                                    <div class="value">₦<?php echo number_format($payments_total, 2); ?></div>
                                </div>

                                <div class="summary-card cash">
                                    <h6><i class="fas fa-money-bill me-2"></i>Cash Received</h6>
                                    <div class="value">₦<?php echo number_format($cash_total, 2); ?></div>
                                </div>

                                <div class="summary-card transfer">
                                    <h6><i class="fas fa-university me-2"></i>Transfers</h6>
                                    <div class="value">₦<?php echo number_format($transfer_total, 2); ?></div>
                                </div>
                            </div>

                            <!-- Cash Payments Section -->
                            <div class="payments-table mb-4">
                                <div class="table-header">
                                    <h5 class="mb-0"><i class="fas fa-money-bill text-primary me-2"></i>Cash Payments</h5>
                                </div>
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Amount</th>
                                            <th>Notes</th>
                                            <th>Time</th>
                                            <?php if (hasRole(['admin', 'manager'])): ?>
                                            <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $cash_payments = array_filter($payments, function($p) { return $p['payment_method'] === 'cash'; });
                                        if (empty($cash_payments)): ?>
                                            <tr>
                                                <td colspan="<?php echo hasRole(['admin', 'manager']) ? 4 : 3; ?>" class="text-center py-4">
                                                    <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                                    <p class="text-muted mb-0">No cash payments recorded.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($cash_payments as $payment): ?>
                                                <tr>
                                                    <td class="fw-bold text-primary">₦<?php echo number_format($payment['amount'], 2); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['notes'] ?: '-'); ?></td>
                                                    <td><?php echo date('H:i', strtotime($payment['created_at'])); ?></td>
                                                    <?php if (hasRole(['admin', 'manager'])): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $payment['id']; ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this payment?');" style="display:inline;">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="delete_payment" value="1">
                                                            <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Bank Transfer Payments Section -->
                            <div class="payments-table">
                                <div class="table-header">
                                    <h5 class="mb-0"><i class="fas fa-university text-success me-2"></i>Bank Transfer Payments</h5>
                                </div>
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Amount</th>
                                            <th>Notes</th>
                                            <th>Time</th>
                                            <?php if (hasRole(['admin', 'manager'])): ?>
                                            <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $transfer_payments = array_filter($payments, function($p) { return $p['payment_method'] === 'transfer'; });
                                        if (empty($transfer_payments)): ?>
                                            <tr>
                                                <td colspan="<?php echo hasRole(['admin', 'manager']) ? 4 : 3; ?>" class="text-center py-4">
                                                    <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                                    <p class="text-muted mb-0">No bank transfer payments recorded.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($transfer_payments as $payment): ?>
                                                <tr>
                                                    <td class="fw-bold text-success">₦<?php echo number_format($payment['amount'], 2); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['notes'] ?: '-'); ?></td>
                                                    <td><?php echo date('H:i', strtotime($payment['created_at'])); ?></td>
                                                    <?php if (hasRole(['admin', 'manager'])): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $payment['id']; ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this payment?');" style="display:inline;">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="delete_payment" value="1">
                                                            <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>Select a dispatcher to view and record their payments.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
    <script>
        function updateFilters() {
            const date = document.getElementById('date').value;
            const dispatcher = document.getElementById('dispatcher').value;
            const url = new URL(window.location);
            url.searchParams.set('date', date);
            url.searchParams.set('dispatcher', dispatcher);
            window.location.href = url.toString();
        }
    </script>

    <!-- Edit Modals for Payments -->
    <?php if (hasRole(['admin', 'manager'])): ?>
        <?php foreach ($payments as $payment): ?>
        <div class="modal fade" id="editModal<?php echo $payment['id']; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="edit_payment" value="1">
                            <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                            <div class="mb-3">
                                <label class="form-label">Payment Method</label>
                                <input type="text" class="form-control" value="<?php echo ucfirst($payment['payment_method']); ?>" disabled>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Amount (₦)</label>
                                <input type="number" name="new_amount" class="form-control" step="0.01" min="0" value="<?php echo $payment['amount']; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="new_notes" class="form-control" rows="3"><?php echo htmlspecialchars($payment['notes'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Payment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>


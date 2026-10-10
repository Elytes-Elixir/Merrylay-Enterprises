<?php
/**
 * Dispatcher Expenses Page for Merrylay Enterprises
 *
 * Record expenses incurred by dispatchers during trips (fueling, repairs, etc.)
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

if (!canViewFinancials() && !hasPermission(PERMISSION_FINANCE_VIEW)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized dispatcher expense action by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to view or record dispatcher expenses.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

$canRecordExpenses = hasRole(['admin', 'manager']) || canRecordFinancials() || hasPermission(PERMISSION_FINANCE_CREATE);

$error = '';
$success = (is_string($_GET['saved'] ?? null) && $_GET['saved'] === '1') ? 'Expense recorded successfully.' : '';
$selected_date = is_string($_GET['date'] ?? null) ? $_GET['date'] : date('Y-m-d');
$selected_date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $selected_date);
if (!$selected_date_object || $selected_date_object->format('Y-m-d') !== $selected_date) {
    $selected_date = date('Y-m-d');
}
$dispatcher_filter = is_string($_GET['dispatcher'] ?? null) ? trim($_GET['dispatcher']) : '';

if (hasRole('dispatcher')) {
    $dispatcher_filter = getDispatcherName() ?? '';
    if ($dispatcher_filter === '') {
        http_response_code(403);
        exit('Access denied.');
    }
}

// Get unique dispatchers for filter
try {
    if (hasRole('dispatcher')) {
        $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches WHERE dispatcher_name = ? ORDER BY dispatcher_name", [$dispatcher_filter]);
        $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches ORDER BY dispatcher_name");
        $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    error_log("Get expense dispatchers error: " . $e->getMessage());
    $dispatchers = [];
}

// Dispatch links are optional; any submitted link is checked against its owner and date.
$dispatch_options = [];
try {
    $dispatch_query = "SELECT id, dispatcher_name, dispatch_date FROM dispatches WHERE DATE(dispatch_date) = ?";
    $dispatch_params = [$selected_date];
    if ($dispatcher_filter !== '') {
        $dispatch_query .= " AND dispatcher_name = ?";
        $dispatch_params[] = $dispatcher_filter;
    }
    $dispatch_query .= " ORDER BY dispatcher_name, id DESC";
    $stmt = executeQuery($dispatch_query, $dispatch_params);
    $dispatch_options = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get expense dispatch options error: " . $e->getMessage());
}

// Consume a one-use token before validation so replays and browser refreshes cannot resubmit.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!$canRecordExpenses) {
        error_log('Unauthorized dispatcher expense write by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('Access denied.');
    }
    if (!SecurityUtils::consumeCSRFToken($csrf_token)) {
        $error = 'Your request could not be verified. Please reload the page and try again.';
    } elseif (!isset($_POST['add_expense'])) {
        $error = 'Unable to record expense.';
    } else {
        $posted_dispatcher = is_string($_POST['dispatcher_name'] ?? null) ? trim($_POST['dispatcher_name']) : '';
        $dispatcher_name = hasRole('dispatcher') ? $dispatcher_filter : $posted_dispatcher;
        $expense_date = is_string($_POST['expense_date'] ?? null) ? trim($_POST['expense_date']) : '';
        $expense_type = is_string($_POST['expense_type'] ?? null) ? trim($_POST['expense_type']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $amount_input = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
        $payment_method = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
        $receipt_number = is_string($_POST['receipt_number'] ?? null) ? trim($_POST['receipt_number']) : '';
        $notes = is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : '';
        $dispatch_id_input = is_string($_POST['dispatch_id'] ?? null) ? trim($_POST['dispatch_id']) : '';

        $date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $expense_date);
        $date_errors = DateTimeImmutable::getLastErrors();
        $date_valid = $date_object !== false
            && $date_object->format('Y-m-d') === $expense_date
            && ($date_errors === false || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0));
        $allowed_types = ['fuel', 'repair', 'maintenance', 'toll', 'parking', 'food', 'accommodation', 'other'];
        $allowed_payment_methods = ['cash', 'card', 'company_account'];
        $amount_valid = preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount_input) === 1;
        $amount = $amount_valid ? (float) $amount_input : 0.0;
        $amount_valid = $amount_valid && is_finite($amount) && $amount > 0 && $amount <= 99999999.99;

        if ($dispatcher_name === '' || strlen($dispatcher_name) > 100) {
            $error = 'Select a valid dispatcher.';
        } elseif (!$date_valid) {
            $error = 'Enter a valid expense date.';
        } elseif (!in_array($expense_type, $allowed_types, true)) {
            $error = 'Select a valid expense category.';
        } elseif ($description === '' || strlen($description) > 255) {
            $error = 'Enter a description of no more than 255 characters.';
        } elseif (!$amount_valid) {
            $error = 'Enter an amount greater than zero, up to 99,999,999.99.';
        } elseif (!in_array($payment_method, $allowed_payment_methods, true)) {
            $error = 'Select a valid payment method.';
        } elseif (strlen($receipt_number) > 50) {
            $error = 'Receipt number must be no more than 50 characters.';
        } elseif ($dispatch_id_input !== '' && !ctype_digit($dispatch_id_input)) {
            $error = 'Select a valid dispatch.';
        } else {
            try {
                $stmt = executeQuery("SELECT 1 FROM dispatches WHERE dispatcher_name = ? LIMIT 1", [$dispatcher_name]);
                if (!$stmt->fetchColumn()) {
                    $error = 'Select a valid dispatcher.';
                } elseif ($dispatch_id_input !== '') {
                    $stmt = executeQuery(
                        "SELECT 1 FROM dispatches WHERE id = ? AND dispatcher_name = ? AND DATE(dispatch_date) = ? LIMIT 1",
                        [(int) $dispatch_id_input, $dispatcher_name, $expense_date]
                    );
                    if (!$stmt->fetchColumn()) {
                        $error = 'The selected dispatch does not match this dispatcher and expense date.';
                    }
                }

                if ($error === '') {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        "INSERT INTO dispatcher_expenses (dispatch_id, dispatcher_name, expense_date, expense_type, description, amount, payment_method, receipt_number, notes, recorded_by, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                    );
                    $stmt->execute([
                        $dispatch_id_input === '' ? null : (int) $dispatch_id_input,
                        $dispatcher_name,
                        $expense_date,
                        $expense_type,
                        $description,
                        $amount_input,
                        $payment_method,
                        $receipt_number === '' ? null : $receipt_number,
                        $notes === '' ? null : $notes,
                        $_SESSION['user_id']
                    ]);
                    $pdo->commit();

                    header('Location: dispatcher-expenses.php?date=' . urlencode($selected_date) . '&dispatcher=' . urlencode($dispatcher_name) . '&saved=1', true, 303);
                    exit();
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Add dispatcher expense error: " . $e->getMessage());
                $error = 'Unable to record expense.';
            }
        }
    }
}

$csrf_token = SecurityUtils::generateCSRFToken();

// Get expenses for the selected date and dispatcher
$expenses = [];
$expenses_total = 0;

if (!empty($dispatcher_filter)) {
    try {
        $stmt = executeQuery("
            SELECT de.*, u.name as recorded_by_name
            FROM dispatcher_expenses de
            LEFT JOIN users u ON de.recorded_by = u.id
            WHERE de.dispatcher_name = ? AND DATE(de.expense_date) = ?
            ORDER BY de.created_at DESC
        ", [$dispatcher_filter, $selected_date]);

        $expenses = $stmt->fetchAll();

        // Calculate total
        $stmt = executeQuery("
            SELECT SUM(amount) as total
            FROM dispatcher_expenses
            WHERE dispatcher_name = ? AND DATE(expense_date) = ?
        ", [$dispatcher_filter, $selected_date]);

        $total_result = $stmt->fetch();
        $expenses_total = $total_result['total'] ?? 0;

    } catch (PDOException $e) {
        error_log("Get expenses error: " . $e->getMessage());
        $error = 'Unable to load expenses.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatcher Expenses - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }

        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }

        .header-section h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .header-section p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .form-card h5 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
        }

        .expenses-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .expenses-table thead {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
        }

        .expenses-table th {
            padding: 1rem;
            font-weight: 700;
            color: #6b7280;
            border-bottom: 1px solid #d1d5db;
        }

        .expenses-table td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .expense-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .expense-fuel { background: #dbeafe; color: #1e40af; }
        .expense-repair { background: #fee2e2; color: #dc2626; }
        .expense-maintenance { background: #fef3c7; color: #d97706; }
        .expense-toll { background: #ecfdf5; color: #059669; }
        .expense-parking { background: #f3e8ff; color: #7c3aed; }
        .expense-food { background: #fdf2f8; color: #db2777; }
        .expense-accommodation { background: #ecfeff; color: #0891b2; }
        .expense-other { background: #f9fafb; color: #6b7280; }

        .total-card {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
        }

        .total-card h4 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .total-card p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }

        .alert {
            border-radius: 12px;
            border: none;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .filter-row .form-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-row .form-group label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .btn-expense {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }

        .btn-expense:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
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

        <!-- Main content -->
        <main class="main-content" id="mainContent">
            <div class="container-fluid py-4">
        <!-- Header -->
        <div class="header-section">
            <h1><i class="fas fa-money-bill-wave me-2"></i>Dispatcher Expenses</h1>
            <p>Track expenses incurred by dispatchers during trips (fueling, repairs, tolls, etc.)</p>
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
                <!-- Add Expense Form -->
                <?php if ($canRecordExpenses): ?>
                <div class="form-card">
                    <h5><i class="fas fa-plus-circle me-2"></i>Add New Expense</h5>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
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
                            <label class="form-label">Dispatch (optional)</label>
                            <select name="dispatch_id" class="form-select">
                                <option value="">Not linked to a dispatch</option>
                                <?php foreach ($dispatch_options as $dispatch): ?>
                                    <option value="<?php echo (int) $dispatch['id']; ?>">
                                        <?php echo htmlspecialchars($dispatch['dispatcher_name'] . ' - ' . date('d/m/Y', strtotime($dispatch['dispatch_date'])) . ' - #' . $dispatch['id'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Expense Type <span class="text-danger">*</span></label>
                            <select name="expense_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="fuel">Fueling</option>
                                <option value="repair">Vehicle Repair</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="toll">Toll Fees</option>
                                <option value="parking">Parking</option>
                                <option value="food">Food/Refreshment</option>
                                <option value="accommodation">Accommodation</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control" placeholder="Brief description of expense" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Amount (₦) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="company_account">Company Account</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Receipt Number</label>
                            <input type="text" name="receipt_number" class="form-control" placeholder="Optional receipt reference">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                        </div>

                        <button type="submit" name="add_expense" class="btn btn-expense w-100">
                            <i class="fas fa-save me-2"></i>Record Expense
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-8">
                <?php if (!empty($dispatcher_filter)): ?>
                    <!-- Total Expenses Card -->
                    <div class="total-card mb-4">
                        <h4>₦<?php echo number_format($expenses_total, 2); ?></h4>
                        <p>Total Expenses for <?php echo htmlspecialchars($dispatcher_filter); ?> on <?php echo date('d/m/Y', strtotime($selected_date)); ?></p>
                    </div>

                    <!-- Expenses Table -->
                    <div class="expenses-table">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Receipt</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($expenses)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No expenses recorded for this dispatcher on this date.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($expenses as $expense): ?>
                                        <tr>
                                            <td>
                                                <span class="expense-type expense-<?php echo $expense['expense_type']; ?>">
                                                    <?php echo ucfirst($expense['expense_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                            <td class="fw-bold">₦<?php echo number_format($expense['amount'], 2); ?></td>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $expense['payment_method'])); ?></td>
                                            <td><?php echo htmlspecialchars($expense['receipt_number'] ?: '-'); ?></td>
                                            <td><?php echo date('H:i', strtotime($expense['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>Select a dispatcher to view and record their expenses.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
</body>
</html>


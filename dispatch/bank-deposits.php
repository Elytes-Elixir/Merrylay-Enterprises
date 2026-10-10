<?php
/**
 * Bank Deposits Page for Merrylay Enterprises
 *
 * Record what was actually deposited to bank accounts for reconciliation
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

// Check if user has permission to record bank deposits (cashiers only)
if (!canRecordBankDeposits()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized bank deposit attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to record bank deposits.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';
$success = $_SESSION['bank_deposit_success'] ?? '';
unset($_SESSION['bank_deposit_success']);
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected bank deposit mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the deposit details and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();
$selected_date = $_GET['date'] ?? date('Y-m-d');
$dispatcher_filter = $_GET['dispatcher'] ?? '';

// Get unique dispatchers for filter
try {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches ORDER BY dispatcher_name");
    $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $dispatchers = [];
}

// Handle bank deposit submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_deposit']) && $csrfValid) {
    $dispatcher_name = trim($_POST['dispatcher_name'] ?? '');
    $deposit_date = $_POST['deposit_date'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $deposit_slip_number = trim($_POST['deposit_slip_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (empty($dispatcher_name)) {
        $error = 'Dispatcher name is required.';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } elseif (empty($bank_name)) {
        $error = 'Bank name is required.';
    } elseif (empty($account_number)) {
        $error = 'Account number is required.';
    } elseif (empty($deposit_slip_number)) {
        $error = 'Deposit slip number is required.';
    } else {
        try {
            $query = "INSERT INTO bank_deposits (dispatcher_name, deposit_date, amount, bank_name, account_number, account_name, deposit_slip_number, notes, recorded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            executeQuery($query, [
                $dispatcher_name,
                $deposit_date,
                $amount,
                $bank_name,
                $account_number,
                $account_name,
                $deposit_slip_number,
                $notes,
                $_SESSION['user_id']
            ]);

            $_SESSION['bank_deposit_success'] = 'Bank deposit recorded successfully!';
            header('Location: bank-deposits.php?date=' . urlencode($deposit_date));
            exit();
        } catch (PDOException $e) {
            error_log("Add bank deposit error: " . $e->getMessage());
            $error = 'Unable to record bank deposit.';
        }
    }
}

// Get bank deposits for the selected date and dispatcher
$deposits = [];
$deposits_total = 0;

if (!empty($dispatcher_filter)) {
    try {
        $stmt = executeQuery("
            SELECT bd.*, u.name as recorded_by_name
            FROM bank_deposits bd
            LEFT JOIN users u ON bd.recorded_by = u.id
            WHERE bd.dispatcher_name = ? AND DATE(bd.deposit_date) = ?
            ORDER BY bd.created_at DESC
        ", [$dispatcher_filter, $selected_date]);

        $deposits = $stmt->fetchAll();

        // Calculate total
        $stmt = executeQuery("
            SELECT SUM(amount) as total
            FROM bank_deposits
            WHERE dispatcher_name = ? AND DATE(deposit_date) = ?
        ", [$dispatcher_filter, $selected_date]);

        $total_result = $stmt->fetch();
        $deposits_total = $total_result['total'] ?? 0;

    } catch (PDOException $e) {
        error_log("Get bank deposits error: " . $e->getMessage());
        $error = 'Unable to load bank deposits.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Deposits - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }
        
        .page-header h4 {
            font-weight: 600;
            font-size: 1.5rem;
            color: #111827;
            margin-bottom: 0.25rem;
        }
        
        .page-header p {
            color: #6b7280;
            margin: 0;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            margin-bottom: 1.5rem;
        }

        .form-card h5 {
            color: #374151;
            font-weight: 600;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            font-size: 1rem;
        }

        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-size: 0.95rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .deposits-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .deposits-table thead {
            background: #f9fafb;
        }

        .deposits-table th {
            padding: 0.875rem 1rem;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.875rem;
        }

        .deposits-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.9rem;
        }

        .total-card {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white;
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
        }

        .total-card h4 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
        }

        .total-card p {
            margin: 0.25rem 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .alert {
            border-radius: 8px;
            border: none;
            font-size: 0.95rem;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #065f46;
            border: 1px solid #86efac;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        .filter-row .form-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-row .form-group label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.375rem;
            font-size: 0.875rem;
        }

        .btn-primary {
            background: #3b82f6;
            border: none;
            border-radius: 8px;
            padding: 0.625rem 1.25rem;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .btn-outline-secondary {
            border: 1px solid #d1d5db;
            color: #374151;
            border-radius: 8px;
            padding: 0.625rem 1.25rem;
            font-weight: 500;
        }
        
        .btn-outline-secondary:hover {
            border-color: #9ca3af;
            background: #f9fafb;
            color: #374151;
        }

        .bank-info {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
        }

        .bank-info .bank-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 1rem;
        }

        .bank-info .account-details {
            color: #6b7280;
            font-size: 0.875rem;
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
        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search...">
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
                                    <div class="user-role"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
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
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4>Bank Deposits</h4>
                            <p>Record cash deposits to bank accounts for reconciliation</p>
                        </div>
                    </div>
                </div>

        <div class="container-fluid">
        <!-- Filter Section -->
        <div class="filter-row">
            <div class="form-group">
                <label for="date" class="form-label">Date</label>
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
                <!-- Add Bank Deposit Form -->
                <div class="form-card">
                    <h5><i class="fas fa-plus-circle me-2"></i>Record Bank Deposit</h5>
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
                            <label class="form-label">Deposit Date <span class="text-danger">*</span></label>
                            <input type="date" name="deposit_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Amount (₦) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                            <select name="bank_name" class="form-select" required>
                                <option value="">Select Bank</option>
                                <option value="Access Bank">Access Bank</option>
                                <option value="First Bank">First Bank</option>
                                <option value="GTBank">GTBank</option>
                                <option value="UBA">UBA</option>
                                <option value="Zenith Bank">Zenith Bank</option>
                                <option value="Fidelity Bank">Fidelity Bank</option>
                                <option value="Union Bank">Union Bank</option>
                                <option value="Sterling Bank">Sterling Bank</option>
                                <option value="Wema Bank">Wema Bank</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Number <span class="text-danger">*</span></label>
                            <input type="text" name="account_number" class="form-control" placeholder="Account number" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Name</label>
                            <input type="text" name="account_name" class="form-control" placeholder="Account holder name">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Deposit Slip Number <span class="text-danger">*</span></label>
                            <input type="text" name="deposit_slip_number" class="form-control" placeholder="Deposit slip/reference number" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                        </div>

                        <button type="submit" name="add_deposit" class="btn btn-deposit w-100">
                            <i class="fas fa-save me-2"></i>Record Deposit
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-8">
                <?php if (!empty($dispatcher_filter)): ?>
                    <!-- Total Deposits Card -->
                    <div class="total-card mb-4">
                        <h4>₦<?php echo number_format($deposits_total, 2); ?></h4>
                        <p>Total Deposits for <?php echo htmlspecialchars($dispatcher_filter); ?> on <?php echo date('d/m/Y', strtotime($selected_date)); ?></p>
                    </div>

                    <!-- Deposits Table -->
                    <div class="deposits-table">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Bank Details</th>
                                    <th>Amount</th>
                                    <th>Deposit Slip</th>
                                    <th>Deposited By</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($deposits)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No bank deposits recorded for this dispatcher on this date.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deposits as $deposit): ?>
                                        <tr>
                                            <td>
                                                <div class="bank-info">
                                                    <div class="bank-name"><?php echo htmlspecialchars($deposit['bank_name']); ?></div>
                                                    <div class="account-details">
                                                        <?php echo htmlspecialchars($deposit['account_number']); ?>
                                                        <?php if (!empty($deposit['account_name'])): ?>
                                                            (<?php echo htmlspecialchars($deposit['account_name']); ?>)
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="fw-bold">₦<?php echo number_format($deposit['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($deposit['deposit_slip_number']); ?></td>
                                            <td><?php echo htmlspecialchars($deposit['recorded_by'] ?: '-'); ?></td>
                                            <td><?php echo date('H:i', strtotime($deposit['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>Select a dispatcher to view and record their bank deposits.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </main>
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


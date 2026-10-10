<?php
/**
 * Daily Accounting Page for Merrylay Enterprises
 *
 * Main reconciliation dashboard showing all financial flows and balance checking
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

// Check if user has permission to view accounting
if (!canRecordDispatches() && !canRecordFinancials()) {
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$dispatcher_filter = $_GET['dispatcher'] ?? '';


// Get unique dispatchers for filter
try {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches ORDER BY dispatcher_name");
    $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $dispatchers = [];
}

// Get accounting summary for the selected date and dispatcher
$accounting_summary = null;
$balance_status = 'balanced';
$balance_difference = 0;

if (!empty($dispatcher_filter)) {
    try {
        // OPTIMIZED: Replaced SELECT * with specific columns
        $query = "
            SELECT 
                dispatcher_name,
                summary_date,
                expected_amount,
                total_trips,
                cash_received,
                transfer_received,
                paid_outstanding,
                total_expenses,
                expense_count,
                total_deposits,
                deposit_count,
                outstanding_amount,
                outstanding_count
            FROM daily_accounting_summary
            WHERE dispatcher_name = ? AND summary_date = ?
        ";
        $params = [$dispatcher_filter, $selected_date];
        
        // Add sales channel filter if specified
        if (!empty($sales_channel_filter)) {
            $query .= " AND sales_channel = ?";
            $params[] = $sales_channel_filter;
        }
        
        $stmt = executeQuery($query, $params);

        $accounting_summary = $stmt->fetch();

        if ($accounting_summary) {
            // Calculate balance difference
            $expected_total = $accounting_summary['expected_amount'];
            $received_total = $accounting_summary['cash_received'] + $accounting_summary['transfer_received'] + $accounting_summary['paid_outstanding'];
            $expenses_total = $accounting_summary['total_expenses'];
            $deposits_total = $accounting_summary['total_deposits'];

            // Balance check: Expected - Expenses should equal Deposits + Outstanding
            $balance_difference = ($expected_total - $expenses_total) - ($deposits_total + $accounting_summary['outstanding_amount']);

            if (abs($balance_difference) > 0.01) { // Allow for small floating point differences
                $balance_status = $balance_difference > 0 ? 'shortage' : 'surplus';
            }
        }

    } catch (PDOException $e) {
        error_log("Get accounting summary error: " . $e->getMessage());
        $error = 'Unable to load accounting summary.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Accounting - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
        }

        .page-header h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
        }

        .page-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .balance-banner {
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .balance-balanced {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .balance-shortage {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .balance-surplus {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .balance-banner .status-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }

        .balance-banner .status-text {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .balance-banner .difference {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .accounting-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .accounting-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-top: 4px solid;
        }

        .accounting-card.money-in {
            border-top-color: #10b981;
        }

        .accounting-card.money-out {
            border-top-color: #ef4444;
        }

        .accounting-card.money-held {
            border-top-color: #f59e0b;
        }

        .accounting-card h6 {
            color: #6b7280;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .accounting-card .amount {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .accounting-card .breakdown {
            font-size: 0.9rem;
            color: #6b7280;
            line-height: 1.6;
        }

        .accounting-card .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 0.25rem 0;
            border-bottom: 1px dashed #e5e7eb;
        }

        .accounting-card .breakdown-item:last-child {
            border-bottom: none;
        }

        .reconciliation-box {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 2rem;
        }

        .reconciliation-box h5 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reconciliation-equation {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 1.1rem;
        }

        .equation-term {
            padding: 0.75rem 1.5rem;
            background: white;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            font-weight: 600;
            min-width: 150px;
            text-align: center;
        }

        .equation-term.highlight {
            border-color: #667eea;
            background: #eff6ff;
        }

        .equation-operator {
            font-size: 1.5rem;
            color: #6b7280;
            font-weight: 700;
        }

        .filter-bar {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-bar .form-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-bar .form-group label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .quick-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .quick-actions .btn {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .alert {
            border-radius: 12px;
            border: none;
        }

        .info-table {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .info-table h5 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .info-table .table {
            margin-bottom: 0;
        }

        .info-table .table th {
            font-weight: 600;
            color: #6b7280;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }

        .info-table .table td {
            font-weight: 500;
            color: #374151;
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
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-balance-scale me-2"></i>Daily Accounting</h1>
            <p>Financial reconciliation for <?php echo htmlspecialchars($dispatcher_filter ?: 'all dispatchers'); ?> on <?php echo date('l, F j, Y', strtotime($selected_date)); ?></p>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="form-group">
                <label for="date" class="form-label">Date</label>
                <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="updateFilters()">
            </div>

            <div class="form-group">
                <label for="dispatcher" class="form-label">Dispatcher</label>
                <select id="dispatcher" name="dispatcher" class="form-select" onchange="updateFilters()">
                    <option value="">Select Dispatcher</option>
                    <?php foreach ($dispatchers as $disp): ?>
                        <option value="<?php echo htmlspecialchars($disp); ?>" <?php echo $disp === $dispatcher_filter ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disp); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="quick-actions">
                <a href="../finance/daily_records.php?date=<?php echo $selected_date; ?>&dispatcher=<?php echo urlencode($dispatcher_filter); ?>" class="btn btn-primary">
                    <i class="fas fa-calculator me-1"></i>Finance Records
                </a>
                <?php if (!empty($dispatcher_filter)): ?>
                <a href="credit/remit.php" class="btn btn-success">
                    <i class="fas fa-money-bill-wave me-1"></i>Settle Credits
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($dispatcher_filter)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>Please select a dispatcher to view their accounting reconciliation.
            </div>
        <?php elseif (!$accounting_summary): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>No accounting data found for <?php echo htmlspecialchars($dispatcher_filter); ?> on <?php echo date('d/m/Y', strtotime($selected_date)); ?>.
                <br><small>This could mean no dispatches were made on this date, or accounting records haven't been entered yet.</small>
            </div>
        <?php else: ?>
            <!-- Balance Status Banner -->
            <div class="balance-banner balance-<?php echo $balance_status; ?>">
                <div class="status-icon">
                    <i class="fas fa-<?php echo $balance_status === 'balanced' ? 'check-circle' : ($balance_status === 'shortage' ? 'exclamation-triangle' : 'plus-circle'); ?>"></i>
                </div>
                <div class="status-text"><?php echo ucfirst($balance_status); ?></div>
                <?php if ($balance_status !== 'balanced'): ?>
                    <div class="difference">Difference: ₦<?php echo number_format(abs($balance_difference), 2); ?></div>
                    <?php if ($balance_status === 'shortage'): ?>
                        <div class="note" style="margin-top: 0.5rem; font-size: 0.875rem; color: #666;">
                            <i class="fas fa-info-circle me-1"></i>Calculated variance only; viewing this report does not change creditor records.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="difference">All accounts balanced perfectly</div>
                <?php endif; ?>
            </div>

            <!-- Accounting Grid -->
            <div class="accounting-grid">
                <!-- Money In -->
                <div class="accounting-card money-in">
                    <h6><i class="fas fa-arrow-down text-success me-2"></i>Money Received</h6>
                    <div class="amount">₦<?php echo number_format($accounting_summary['cash_received'] + $accounting_summary['transfer_received'] + $accounting_summary['paid_outstanding'], 2); ?></div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Cash</span>
                            <span>₦<?php echo number_format($accounting_summary['cash_received'], 2); ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span>Transfer</span>
                            <span>₦<?php echo number_format($accounting_summary['transfer_received'], 2); ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span>Outstanding Paid</span>
                            <span>₦<?php echo number_format($accounting_summary['paid_outstanding'], 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Money Out -->
                <div class="accounting-card money-out">
                    <h6><i class="fas fa-arrow-up text-danger me-2"></i>Money Out</h6>
                    <div class="amount">₦<?php echo number_format($accounting_summary['total_expenses'], 2); ?></div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Expenses</span>
                            <span>₦<?php echo number_format($accounting_summary['total_expenses'], 2); ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span>Entries</span>
                            <span><?php echo $accounting_summary['expense_count']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Money Held -->
                <div class="accounting-card money-held">
                    <h6><i class="fas fa-clock text-warning me-2"></i>Money Held</h6>
                    <div class="amount">₦<?php echo number_format($accounting_summary['outstanding_amount'], 2); ?></div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Outstanding</span>
                            <span>₦<?php echo number_format($accounting_summary['outstanding_amount'], 2); ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span>Items</span>
                            <span><?php echo $accounting_summary['outstanding_count']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Bank Deposits -->
                <div class="accounting-card money-in">
                    <h6><i class="fas fa-university text-primary me-2"></i>Bank Deposits</h6>
                    <div class="amount">₦<?php echo number_format($accounting_summary['total_deposits'], 2); ?></div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Deposited</span>
                            <span>₦<?php echo number_format($accounting_summary['total_deposits'], 2); ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span>Transactions</span>
                            <span><?php echo $accounting_summary['deposit_count']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Expected -->
                <div class="accounting-card money-held">
                    <h6><i class="fas fa-calculator text-info me-2"></i>Expected Amount</h6>
                    <div class="amount">₦<?php echo number_format($accounting_summary['expected_amount'], 2); ?></div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>From Sales</span>
                            <span>₦<?php echo number_format($accounting_summary['expected_amount'], 2); ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span>Trips</span>
                            <span><?php echo $accounting_summary['total_trips']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Net Balance -->
                <div class="accounting-card <?php echo abs($balance_difference) < 0.01 ? 'money-in' : ($balance_difference > 0 ? 'money-out' : 'money-held'); ?>">
                    <h6><i class="fas fa-balance-scale me-2"></i>Net Balance</h6>
                    <div class="amount">₦<?php echo number_format($balance_difference, 2); ?></div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Status</span>
                            <span><?php echo ucfirst($balance_status); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reconciliation Equation -->
            <div class="reconciliation-box">
                <h5><i class="fas fa-equals me-2"></i>Balance Equation</h5>
                <div class="reconciliation-equation">
                    <div class="equation-term">
                        <div>Expected</div>
                        <div class="text-success">₦<?php echo number_format($accounting_summary['expected_amount'], 2); ?></div>
                    </div>
                    <div class="equation-operator">−</div>
                    <div class="equation-term">
                        <div>Expenses</div>
                        <div class="text-danger">₦<?php echo number_format($accounting_summary['total_expenses'], 2); ?></div>
                    </div>
                    <div class="equation-operator">=</div>
                    <div class="equation-term highlight">
                        <div>Net Expected</div>
                        <div class="fw-bold">₦<?php echo number_format($accounting_summary['expected_amount'] - $accounting_summary['total_expenses'], 2); ?></div>
                    </div>
                    <div class="equation-operator">⇅</div>
                    <div class="equation-term highlight">
                        <div>Deposits + Outstanding</div>
                        <div class="fw-bold">₦<?php echo number_format($accounting_summary['total_deposits'] + $accounting_summary['outstanding_amount'], 2); ?></div>
                    </div>
                </div>
            </div>

            <!-- Detailed Breakdown Table -->
            <div class="info-table">
                <h5><i class="fas fa-list me-2"></i>Detailed Breakdown</h5>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Total Expected from Sales</td>
                            <td>₦<?php echo number_format($accounting_summary['expected_amount'], 2); ?></td>
                            <td><span class="badge bg-success">Expected</span></td>
                        </tr>
                        <tr>
                            <td>Cash Payments Received</td>
                            <td>₦<?php echo number_format($accounting_summary['cash_received'], 2); ?></td>
                            <td><span class="badge bg-success">Received</span></td>
                        </tr>
                        <tr>
                            <td>Transfer Payments Received</td>
                            <td>₦<?php echo number_format($accounting_summary['transfer_received'], 2); ?></td>
                            <td><span class="badge bg-success">Received</span></td>
                        </tr>
                        <tr>
                            <td>Outstanding Payments Cleared</td>
                            <td>₦<?php echo number_format($accounting_summary['paid_outstanding'], 2); ?></td>
                            <td><span class="badge bg-success">Cleared</span></td>
                        </tr>
                        <tr>
                            <td>Total Expenses Incurred</td>
                            <td>₦<?php echo number_format($accounting_summary['total_expenses'], 2); ?></td>
                            <td><span class="badge bg-danger">Expense</span></td>
                        </tr>
                        <tr>
                            <td>Bank Deposits Made</td>
                            <td>₦<?php echo number_format($accounting_summary['total_deposits'], 2); ?></td>
                            <td><span class="badge bg-primary">Deposited</span></td>
                        </tr>
                        <tr>
                            <td>Current Outstanding Amount</td>
                            <td>₦<?php echo number_format($accounting_summary['outstanding_amount'], 2); ?></td>
                            <td><span class="badge bg-warning">Outstanding</span></td>
                        </tr>
                        <tr class="table-active">
                            <td><strong>Balance Difference</strong></td>
                            <td><strong>₦<?php echo number_format($balance_difference, 2); ?></strong></td>
                            <td><span class="badge <?php echo abs($balance_difference) < 0.01 ? 'bg-success' : 'bg-danger'; ?>"><?php echo ucfirst($balance_status); ?></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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


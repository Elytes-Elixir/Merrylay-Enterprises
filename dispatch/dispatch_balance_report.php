<?php
/**
 * Dispatch Balance Report for Merrylay Enterprises
 * 
 * Shows expected vs actual sales per dispatch, accounting for returns
 * Used for balancing individual dispatch records and daily accounting
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

$error = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');
$dispatcher_filter = $_GET['dispatcher'] ?? '';

// Get unique dispatchers for filter
try {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches WHERE COALESCE(status, '') <> 'cancelled' ORDER BY dispatcher_name");
    $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $dispatchers = [];
}

// Get dispatch balance data
$dispatch_balances = [];

if (!empty($dispatcher_filter)) {
    try {
        $stmt = executeQuery("
            SELECT 
                d.id,
                d.dispatch_number,
                d.dispatcher_name,
                d.dispatch_date,
                d.expected_amount,
                d.actual_sales_amount as old_actual_sales_amount,
                COALESCE(da.total_actual_sales, 0) as actual_sales_amount,
                COUNT(DISTINCT di.id) as item_count,
                SUM(di.quantity) as total_quantity,
                SUM(di.quantity_sold) as total_sold,
                COALESCE(SUM(dr.quantity_returned), 0) as total_returned,
                COALESCE(SUM(dr.return_amount), 0) as total_return_amount,
                COALESCE(SUM(cs.amount_collected), 0) as total_credit_settlements,
                (d.expected_amount - COALESCE(SUM(dr.return_amount), 0)) as adjusted_expected,
                (COALESCE(da.total_actual_sales, 0) - COALESCE(SUM(dr.return_amount), 0) + COALESCE(SUM(cs.amount_collected), 0)) as adjusted_actual,
                ((COALESCE(da.total_actual_sales, 0) - COALESCE(SUM(dr.return_amount), 0) + COALESCE(SUM(cs.amount_collected), 0)) - (d.expected_amount - COALESCE(SUM(dr.return_amount), 0))) as net_balance
            FROM dispatches d
            LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
            LEFT JOIN dispatch_returns dr ON d.id = dr.dispatch_id
            LEFT JOIN credit_settlements cs ON d.id = cs.dispatch_id
            LEFT JOIN (
                SELECT driver_name, activity_date,
                    SUM(cash_collected + transfer_amount + credit_amount) as total_actual_sales
                FROM daily_activities
                GROUP BY driver_name, activity_date
            ) da ON d.dispatcher_name = da.driver_name AND DATE(d.dispatch_date) = DATE(da.activity_date)
            WHERE d.dispatcher_name = ? 
              AND DATE(d.dispatch_date) = ?
                            AND COALESCE(d.status, '') <> 'cancelled'
            GROUP BY d.id, d.dispatch_number, d.dispatcher_name, d.dispatch_date, d.expected_amount, d.actual_sales_amount
            ORDER BY d.dispatch_date DESC
        ", [$dispatcher_filter, $selected_date]);
        $dispatch_balances = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Load dispatch balances error: " . $e->getMessage());
    }
}

// Calculate daily totals
$daily_totals = [
    'total_expected' => 0,
    'total_actual' => 0,
    'total_returned' => 0,
    'total_return_amount' => 0,
    'total_credit_settlements' => 0,
    'net_balance' => 0
];

foreach ($dispatch_balances as $balance) {
    $daily_totals['total_expected'] += $balance['expected_amount'];
    $daily_totals['total_actual'] += $balance['actual_sales_amount'];
    $daily_totals['total_returned'] += $balance['total_returned'];
    $daily_totals['total_return_amount'] += $balance['total_return_amount'];
    $daily_totals['total_credit_settlements'] += $balance['total_credit_settlements'];
    $daily_totals['net_balance'] += $balance['net_balance'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="../assets/fonts/inter/inter.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .form-section h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .balance-positive {
            color: #10b981;
            font-weight: bold;
        }
        
        .balance-negative {
            color: #ef4444;
            font-weight: bold;
        }
        
        .balance-neutral {
            color: #6b7280;
            font-weight: bold;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1rem;
        }
        
        .summary-card h6 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .summary-card .value {
            font-size: 1.5rem;
            font-weight: bold;
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
            <div class="page-header">
                <div class="container-fluid">
                    <h1><i class="fas fa-balance-scale me-2"></i>Dispatch Balance Report</h1>
                    <p class="mb-0">Balance individual dispatch records and daily accounting with returns</p>
                </div>
            </div>
            
            <div class="container-fluid px-4">
                <!-- Filter Section -->
                <div class="form-section">
                    <h5><i class="fas fa-filter me-2"></i>Filter Report</h5>
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Dispatcher</label>
                            <select class="form-select" name="dispatcher" required>
                                <option value="">Select Dispatcher</option>
                                <?php foreach ($dispatchers as $dispatcher): ?>
                                    <option value="<?php echo htmlspecialchars($dispatcher); ?>" 
                                            <?php echo $dispatcher_filter === $dispatcher ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dispatcher); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Generate Report</button>
                        </div>
                    </form>
                    <?php if (!empty($dispatcher_filter)): ?>
                        <div class="mt-2">
                            <a href="dispatch_balance_report.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Clear Filters
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($dispatcher_filter)): ?>
                    <!-- Daily Summary -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="summary-card">
                                <h6>Total Expected</h6>
                                <div class="value">₦<?php echo number_format($daily_totals['total_expected'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-card">
                                <h6>Total Actual Sales</h6>
                                <div class="value">₦<?php echo number_format($daily_totals['total_actual'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-card">
                                <h6>Total Returns</h6>
                                <div class="value text-danger">₦<?php echo number_format($daily_totals['total_return_amount'], 2); ?></div>
                                <small><?php echo $daily_totals['total_returned']; ?> units</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-card">
                                <h6>Credit Settlements</h6>
                                <div class="value text-success">₦<?php echo number_format($daily_totals['total_credit_settlements'], 2); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-card">
                                <h6>Net Balance</h6>
                                <div class="value <?php echo $daily_totals['net_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                    ₦<?php echo number_format($daily_totals['net_balance'], 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dispatch Balance Table -->
                    <div class="form-section">
                        <h5><i class="fas fa-table me-2"></i>Dispatch Balances</h5>
                        <?php if (empty($dispatch_balances)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No dispatch records found for the selected dispatcher and date.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Dispatch #</th>
                                            <th>Dispatcher</th>
                                            <th>Date</th>
                                            <th>Expected</th>
                                            <th>Actual Sales</th>
                                            <th>Returns (Qty)</th>
                                            <th>Returns (₦)</th>
                                            <th>Credit Settlements</th>
                                            <th>Adjusted Expected</th>
                                            <th>Adjusted Actual</th>
                                            <th>Net Balance</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dispatch_balances as $balance): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($balance['dispatch_number']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($balance['dispatcher_name']); ?></td>
                                                <td><?php echo $balance['dispatch_date']; ?></td>
                                                <td>₦<?php echo number_format($balance['expected_amount'], 2); ?></td>
                                                <td>₦<?php echo number_format($balance['actual_sales_amount'], 2); ?></td>
                                                <td><?php echo $balance['total_returned']; ?></td>
                                                <td class="text-danger">-₦<?php echo number_format($balance['total_return_amount'], 2); ?></td>
                                                <td class="text-success">+₦<?php echo number_format($balance['total_credit_settlements'], 2); ?></td>
                                                <td>₦<?php echo number_format($balance['adjusted_expected'], 2); ?></td>
                                                <td>₦<?php echo number_format($balance['adjusted_actual'], 2); ?></td>
                                                <td class="<?php 
                                                    if ($balance['net_balance'] > 0) echo 'balance-positive';
                                                    elseif ($balance['net_balance'] < 0) echo 'balance-negative';
                                                    else echo 'balance-neutral';
                                                ?>">
                                                    ₦<?php echo number_format($balance['net_balance'], 2); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $balance['status'] === 'delivered' ? 'success' : 
                                                             ($balance['status'] === 'returned' ? 'warning' : 'secondary'); 
                                                    ?>">
                                                        <?php echo ucfirst($balance['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-dark">
                                        <tr>
                                            <td colspan="3"><strong>Totals</strong></td>
                                            <td><strong>₦<?php echo number_format($daily_totals['total_expected'], 2); ?></strong></td>
                                            <td><strong>₦<?php echo number_format($daily_totals['total_actual'], 2); ?></strong></td>
                                            <td><strong><?php echo $daily_totals['total_returned']; ?></strong></td>
                                            <td class="text-danger"><strong>-₦<?php echo number_format($daily_totals['total_return_amount'], 2); ?></strong></td>
                                            <td class="text-success"><strong>+₦<?php echo number_format($daily_totals['total_credit_settlements'], 2); ?></strong></td>
                                            <td><strong>₦<?php echo number_format($daily_totals['total_expected'] - $daily_totals['total_return_amount'], 2); ?></strong></td>
                                            <td><strong>₦<?php echo number_format($daily_totals['total_actual'] - $daily_totals['total_return_amount'] + $daily_totals['total_credit_settlements'], 2); ?></strong></td>
                                            <td class="<?php echo $daily_totals['net_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                <strong>₦<?php echo number_format($daily_totals['net_balance'], 2); ?></strong>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Accounting Notes -->
                    <div class="form-section">
                        <h5><i class="fas fa-info-circle me-2"></i>Accounting Notes</h5>
                        <div class="alert alert-info">
                            <strong>Balance Calculation:</strong><br>
                            Net Balance = Adjusted Actual - Adjusted Expected<br>
                            Adjusted Expected = Expected - Return Amount<br>
                            Adjusted Actual = Actual Sales - Return Amount<br>
                            <br>
                            <strong>For Daily Accounting:</strong><br>
                            Use the Net Balance to reconcile the dispatcher's daily account.<br>
                            Positive balance = Excess collected<br>
                            Negative balance = Shortage<br>
                            Zero = Perfect balance
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


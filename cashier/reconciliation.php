<?php
/**
 * Cashier Reconciliation Summary
 * 
 * View bank deposits and cash reconciliation reports.
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

$record_date = $_GET['date'] ?? date('Y-m-d');
$filter_status = $_GET['status'] ?? '';

// Get deposits for the date
$deposits = [];
$all_deposits = [];
try {
    $allowed_statuses = ['balanced', 'surplus', 'short'];
    $status_filter = '';
    $queryParams = [$record_date];

    if ($filter_status && in_array($filter_status, $allowed_statuses, true)) {
        $status_filter = 'AND status = ?';
        $queryParams[] = $filter_status;
    } else {
        $filter_status = '';
    }

    $stmt = executeQuery("
        SELECT * FROM bank_deposits 
        WHERE deposit_date = ? $status_filter
        ORDER BY recorded_at DESC
    ", $queryParams);
    $deposits = $stmt->fetchAll();

    $stmt = executeQuery("
        SELECT * FROM bank_deposits
        WHERE deposit_date = ?
        ORDER BY recorded_at DESC
    ", [$record_date]);
    $all_deposits = $stmt->fetchAll();
} catch (PDOException $e) {
    $deposits = [];
    $all_deposits = [];
}

// Calculate totals
$totals = [
    'cash_from_sales' => 0,
    'cash_from_credit_collections' => 0,
    'other_cash_income' => 0,
    'total_cash_available' => 0,
    'cashier_expenses' => 0,
    'expected_deposit' => 0,
    'bank_deposit_amount' => 0,
    'variance' => 0
];

$status_counts = [
    'balanced' => 0,
    'surplus' => 0,
    'short' => 0,
    'pending' => 0
];

foreach ($all_deposits as $d) {
    $totals['cash_from_sales'] += $d['cash_from_sales'];
    $totals['cash_from_credit_collections'] += $d['cash_from_credit_collections'];
    $totals['other_cash_income'] += $d['other_cash_income'];
    $totals['total_cash_available'] += $d['total_cash_available'];
    $totals['cashier_expenses'] += $d['cashier_expenses'];
    $totals['expected_deposit'] += $d['expected_deposit'];
    $totals['bank_deposit_amount'] += $d['bank_deposit_amount'];
    $totals['variance'] += $d['variance'];
    if (isset($status_counts[$d['status']])) {
        $status_counts[$d['status']]++;
    }
}

// Get daily activities summary for comparison
$daily_summary = null;
try {
    $stmt = executeQuery("
        SELECT 
            COALESCE(SUM(cash_collected), 0) as total_cash,
            COALESCE(SUM(transfer_amount), 0) as total_transfer,
            COALESCE(SUM(credit_amount), 0) as total_credit,
            COALESCE(SUM(expenses_amount), 0) as total_expenses
        FROM daily_activities
        WHERE activity_date = ?
    ", [$record_date]);
    $daily_summary = $stmt->fetch();
} catch (PDOException $e) {
    $daily_summary = null;
}

// Get credit collections for the date
$credit_collections = 0;
try {
    $stmt = executeQuery("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM creditor_remittances 
        WHERE payment_date = ? AND payment_method = 'cash'
    ", [$record_date]);
    $credit_collections = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $credit_collections = 0;
}

// Calculate overall reconciliation
$total_system_cash = ($daily_summary ? $daily_summary['total_cash'] : 0) + $credit_collections;
$total_deposited = $totals['bank_deposit_amount'];
$overall_variance = $total_deposited - $total_system_cash;
$is_overall_balanced = abs($overall_variance) < 0.01;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Reconciliation - Merrylay Enterprises</title>
    <link href="<?php echo BASE_URL; ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        :root {
            --recon-ink: #172b3a;
            --recon-muted: #526675;
            --recon-line: #d5dee4;
            --recon-paper: #ffffff;
            --recon-canvas: #edf2f4;
            --recon-accent: #ba5b25;
            --recon-green: #176b4a;
            --recon-red: #a23131;
            --recon-blue: #245781;
        }
        body { background: var(--recon-canvas); color: var(--recon-ink); }
        .report-header { background: var(--recon-ink); color: #fff; padding: 1.75rem 0; margin-bottom: 1.5rem; border-bottom: 5px solid var(--recon-accent); }
        .report-header h1 { color: #fff; font-size: 1.75rem; margin: 0; }
        .brand-line { color: #f3c49e; font-size: .78rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .report-meta { color: #e5edf2; font-size: .9rem; }
        .report-meta strong { color: #fff; }
        .report-content { max-width: 1440px; }
        .section-title { color: var(--recon-ink); font-size: 1.05rem; font-weight: 700; margin: 1.75rem 0 .75rem; }
        .summary-card, .report-panel, .deposit-card { background: var(--recon-paper); border: 1px solid var(--recon-line); border-radius: 6px; }
        .summary-card { height: 100%; min-width: 0; padding: 1rem; border-top: 3px solid var(--recon-blue); }
        .summary-label { color: var(--recon-muted); font-size: .78rem; font-weight: 600; }
        .summary-number { color: var(--recon-ink); font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .currency { text-align: right; font-variant-numeric: tabular-nums; }
        .summary-status { border-left: 4px solid var(--recon-blue); }
        .variance-positive { color: var(--recon-green) !important; }
        .variance-negative { color: var(--recon-red) !important; }
        .status-balanced { background: #d8eee4; color: #155b3e; }
        .status-surplus { background: #dceaf4; color: #204f73; }
        .status-short { background: #f7e4df; color: #7f2929; }
        .status-pending { background: #fff0d1; color: #714b00; }
        .status-badge { display: inline-block; border-radius: 3px; padding: .35rem .6rem; font-size: .75rem; font-weight: 800; text-transform: uppercase; }
        .deposit-card { margin-bottom: 1rem; overflow: hidden; break-inside: avoid; }
        .deposit-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--recon-line); background: #f7f9fa; }
        .deposit-body { padding: 1.25rem; }
        .deposit-table { margin: 0; }
        .deposit-table th { color: var(--recon-muted); font-size: .78rem; font-weight: 700; }
        .deposit-table td, .deposit-table th { padding: .55rem .65rem; vertical-align: middle; }
        .deposit-table tbody tr:last-child td { border-bottom: 0; }
        .note-copy { overflow-wrap: anywhere; white-space: pre-wrap; }
        .grand-total { background: var(--recon-ink); color: #fff; padding: 1.4rem; border-radius: 6px; margin-top: 1.5rem; break-inside: avoid; }
        .grand-total h2 { color: #fff; font-size: 1.15rem; }
        .grand-total .result-value { font-size: 1.2rem; font-weight: 700; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .action-group { display: flex; flex-wrap: wrap; gap: .6rem; }
        .report-content .card { border-color: var(--recon-line); border-radius: 6px; }
        .report-content .form-label { font-weight: 600; }
        @media (max-width: 575.98px) {
            .report-header { padding: 1.25rem 0; }
            .report-header h1 { font-size: 1.4rem; }
            .report-meta { margin-top: .75rem; }
            .deposit-body { padding: .75rem; }
            .deposit-table td, .deposit-table th { padding: .45rem .35rem; }
            .action-group > * { flex: 1 1 100%; }
            .action-group .btn { width: 100%; }
        }
        @media print {
            @page { size: A4; margin: 14mm; }
            body { background: #fff !important; color: #111 !important; font-size: 10pt; }
            .sidebar, .no-print, .toggle-btn { display: none !important; }
            .main-content { margin: 0 !important; width: 100% !important; }
            .dashboard-container { display: block; }
            .report-header { background: #fff !important; color: #111 !important; border: 0; border-bottom: 2px solid #333; padding: 0 0 10px; margin: 0 0 12px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .report-header h1, .report-meta, .report-meta strong, .brand-line { color: #111 !important; }
            .report-content { max-width: none; }
            .summary-card, .report-panel, .deposit-card, .grand-total { box-shadow: none !important; border: 1px solid #777 !important; break-inside: avoid; }
            .grand-total { background: #fff !important; color: #111 !important; padding: 12px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .grand-total h2, .grand-total small, .grand-total .result-value { color: #111 !important; }
            .deposit-header { background: #eee !important; color: #111 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .deposit-table thead { display: table-header-group; }
            .deposit-table tr, .section-title { break-inside: avoid; }
            a { color: #111 !important; text-decoration: none !important; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
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
                <div class="nav-section-title overview">Main Operations</div>
                <a href="../dashboard/index.php" class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../products/view.php" class="nav-link <?php echo $currentPage === 'view.php' && strpos($_SERVER['PHP_SELF'], 'products') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="../stock/manage.php" class="nav-link <?php echo $currentPage === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
                </a>
                <a href="../stock/initial_setup.php" class="nav-link <?php echo $currentPage === 'initial_setup.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-play-circle"></i></span>
                    <span class="nav-text">Initial Stock Setup</span>
                </a>
                <?php endif; ?>

                <?php if (canRecordDispatches()): ?>
                <div class="nav-section-title dispatch">Dispatch Operations</div>
                <a href="../dispatch/trips/record.php" class="nav-link <?php echo $currentPage === 'record.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="nav-text">New Dispatch</span>
                </a>
                <a href="../dispatch/trips/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-list"></i></span>
                    <span class="nav-text">Manage Dispatches</span>
                </a>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../dispatch/drivers.php" class="nav-link <?php echo $currentPage === 'drivers.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span class="nav-text">Dispatchers</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title sales">Sales & Finance</div>
                <a href="../dispatch/daily/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'daily') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Daily Sales</span>
                </a>
                <a href="../dispatch/daily/account-summary.php" class="nav-link <?php echo $currentPage === 'account-summary.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>
                <a href="../finance/daily_balance.php" class="nav-link <?php echo $currentPage === 'daily_balance.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>
                <?php if (isCashier()): ?>
                <a href="../cashier/deposit.php" class="nav-link <?php echo $currentPage === 'deposit.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="../admin/end-of-day.php" class="nav-link <?php echo $currentPage === 'end-of-day.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../reports/daily.php" class="nav-link <?php echo $currentPage === 'daily.php' && strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <a href="../users/manage.php" class="nav-link <?php echo $currentPage === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'users') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole('admin')): ?>
                <a href="../admin/data_management.php" class="nav-link <?php echo $currentPage === 'data_management.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <a href="../admin/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-text">Stock Statistics</span>
                </a>
                <div class="nav-section-title admin">System</div>
                <a href="../admin/backup.php" class="nav-link <?php echo $currentPage === 'backup.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-server"></i></span>
                    <span class="nav-text">Database Backup</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <a href="../auth/logout.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </a>
            </nav>
        </aside>

        <main class="main-content" id="mainContent">
            <header class="report-header">
                <div class="container-fluid report-content">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <div class="brand-line">Merrylay Enterprises</div>
                            <h1>Cashier Reconciliation</h1>
                            <div class="report-meta">Daily deposit and cash-flow report</div>
                        </div>
                        <div class="col-lg-4 text-lg-end report-meta">
                            <div><strong>Report date</strong> · <?php echo date('F j, Y', strtotime($record_date)); ?></div>
                            <div><strong>Viewing user</strong> · <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? ('User #' . $_SESSION['user_id'])); ?></div>
                            <div class="mt-2">
                                <?php $overall_status_label = $is_overall_balanced ? 'Balanced' : ($overall_variance > 0 ? 'Surplus' : 'Shortage'); ?>
                                <span class="status-badge <?php echo $is_overall_balanced ? 'status-balanced' : ($overall_variance > 0 ? 'status-surplus' : 'status-short'); ?>" aria-label="Overall reconciliation status: <?php echo $overall_status_label; ?>">
                                    <?php echo $overall_status_label; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="container-fluid report-content pb-4">
                <section class="card mb-4 no-print" aria-label="Report filters">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-12 col-sm-6 col-lg-4">
                                <label class="form-label" for="filter-date">Date</label>
                                <input type="date" id="filter-date" name="date" class="form-control" value="<?php echo htmlspecialchars($record_date, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label" for="filter-status">Status filter</label>
                                <select id="filter-status" name="status" class="form-select">
                                    <option value="">All statuses</option>
                                    <option value="balanced" <?php echo $filter_status === 'balanced' ? 'selected' : ''; ?>>Balanced</option>
                                    <option value="surplus" <?php echo $filter_status === 'surplus' ? 'selected' : ''; ?>>Surplus</option>
                                    <option value="short" <?php echo $filter_status === 'short' ? 'selected' : ''; ?>>Short</option>
                                </select>
                            </div>
                            <div class="col-6 col-lg-2">
                                <button type="submit" class="btn btn-warning w-100"><i class="fas fa-filter me-2" aria-hidden="true"></i>Filter</button>
                            </div>
                            <div class="col-6 col-lg-2">
                                <button type="button" onclick="window.print()" class="btn btn-outline-secondary w-100"><i class="fas fa-print me-2" aria-hidden="true"></i>Print</button>
                            </div>
                        </form>
                    </div>
                </section>

                <section aria-labelledby="overview-heading">
                    <h2 class="section-title" id="overview-heading">Reconciliation overview</h2>
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-lg-3"><div class="summary-card"><div class="summary-label">Expected deposit</div><div class="summary-number currency">₦<?php echo number_format($totals['expected_deposit'], 2); ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="summary-card"><div class="summary-label">Cash recorded</div><div class="summary-number currency">₦<?php echo number_format($daily_summary ? $daily_summary['total_cash'] : 0, 2); ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="summary-card"><div class="summary-label">Bank transfers</div><div class="summary-number currency">₦<?php echo number_format($daily_summary ? $daily_summary['total_transfer'] : 0, 2); ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="summary-card"><div class="summary-label">Credit recorded</div><div class="summary-number currency">₦<?php echo number_format($daily_summary ? $daily_summary['total_credit'] : 0, 2); ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="summary-card"><div class="summary-label">Credit collections</div><div class="summary-number currency">₦<?php echo number_format($totals['cash_from_credit_collections'], 2); ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="summary-card"><div class="summary-label">Cashier expenses deducted</div><div class="summary-number currency">₦<?php echo number_format($totals['cashier_expenses'], 2); ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="summary-card"><div class="summary-label">Actual bank deposits</div><div class="summary-number currency">₦<?php echo number_format($total_deposited, 2); ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="summary-card summary-status"><div class="summary-label">Overall variance · <?php echo $overall_status_label; ?></div><div class="summary-number currency <?php echo $overall_variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>"><?php echo $overall_variance >= 0 ? '+' : '-'; ?>₦<?php echo number_format(abs($overall_variance), 2); ?></div></div></div>
                    </div>
                </section>

                <section aria-labelledby="daily-activity-heading">
                    <h2 class="section-title" id="daily-activity-heading">Expected collections and recorded channels</h2>
                    <div class="report-panel p-3 p-md-4 mb-4">
                        <div class="table-responsive">
                            <table class="table table-sm deposit-table">
                                <thead><tr><th scope="col">Category</th><th scope="col" class="text-end">Amount</th></tr></thead>
                                <tbody>
                                    <tr><th scope="row">Cash from sales</th><td class="currency">₦<?php echo number_format($totals['cash_from_sales'], 2); ?></td></tr>
                                    <tr><th scope="row">Bank transfers recorded</th><td class="currency">₦<?php echo number_format($daily_summary ? $daily_summary['total_transfer'] : 0, 2); ?></td></tr>
                                    <tr><th scope="row">Credit recorded</th><td class="currency">₦<?php echo number_format($daily_summary ? $daily_summary['total_credit'] : 0, 2); ?></td></tr>
                                    <tr><th scope="row">Cash credit collections</th><td class="currency">₦<?php echo number_format($totals['cash_from_credit_collections'], 2); ?></td></tr>
                                    <tr><th scope="row">Daily expenses recorded</th><td class="currency">₦<?php echo number_format($daily_summary ? $daily_summary['total_expenses'] : 0, 2); ?></td></tr>
                                    <tr><th scope="row">Other cash income</th><td class="currency">₦<?php echo number_format($totals['other_cash_income'], 2); ?></td></tr>
                                    <tr class="table-light"><th scope="row">Total cash available for deposit</th><td class="currency fw-bold">₦<?php echo number_format($totals['total_cash_available'], 2); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted mb-0 mt-2">Card payments, returns, and outstanding credit balances are not provided by this report's current data source.</p>
                    </div>
                </section>

                <section aria-labelledby="deposit-records-heading">
                    <h2 class="section-title" id="deposit-records-heading">Deposit records <span class="text-muted fw-normal">(<?php echo count($deposits); ?>)</span></h2>
                    <?php if (empty($deposits)): ?>
                        <div class="alert alert-info" role="status"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>No deposits recorded for <?php echo date('F j, Y', strtotime($record_date)); ?>.</div>
                    <?php else: ?>
                        <?php foreach ($deposits as $d): ?>
                            <article class="deposit-card">
                                <header class="deposit-header">
                                    <div class="overflow-hidden">
                                        <h3 class="h6 mb-1 text-break"><?php echo htmlspecialchars($d['bank_name']); ?></h3>
                                        <?php if ($d['deposit_slip_number']): ?><div class="small text-muted text-break">Slip: <?php echo htmlspecialchars($d['deposit_slip_number']); ?></div><?php endif; ?>
                                    </div>
                                    <span class="status-badge status-<?php echo htmlspecialchars($d['status']); ?>"><?php echo htmlspecialchars(ucfirst($d['status'])); ?></span>
                                </header>
                                <div class="deposit-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm deposit-table">
                                            <thead><tr><th scope="col">Reconciliation item</th><th scope="col" class="text-end">Amount</th></tr></thead>
                                            <tbody>
                                                <tr><th scope="row">Cash from sales</th><td class="currency">₦<?php echo number_format($d['cash_from_sales'], 2); ?></td></tr>
                                                <tr><th scope="row">Credit collections</th><td class="currency">₦<?php echo number_format($d['cash_from_credit_collections'], 2); ?></td></tr>
                                                <tr><th scope="row">Other cash income</th><td class="currency">₦<?php echo number_format($d['other_cash_income'], 2); ?></td></tr>
                                                <tr class="table-light"><th scope="row">Total cash available</th><td class="currency fw-bold">₦<?php echo number_format($d['total_cash_available'], 2); ?></td></tr>
                                                <tr><th scope="row">Expenses and deductions</th><td class="currency text-danger">-₦<?php echo number_format($d['cashier_expenses'], 2); ?></td></tr>
                                                <tr><th scope="row">Expected deposit</th><td class="currency">₦<?php echo number_format($d['expected_deposit'], 2); ?></td></tr>
                                                <tr><th scope="row">Actual bank deposit</th><td class="currency">₦<?php echo number_format($d['bank_deposit_amount'], 2); ?></td></tr>
                                                <tr><th scope="row">Deposit variance</th><td class="currency fw-bold <?php echo $d['variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>"><?php echo $d['variance'] >= 0 ? '+' : '-'; ?>₦<?php echo number_format(abs($d['variance']), 2); ?></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if ($d['expenses_note']): ?><p class="note-copy small text-muted mt-3 mb-1"><strong>Expense note:</strong> <?php echo htmlspecialchars($d['expenses_note']); ?></p><?php endif; ?>
                                    <?php if ($d['notes']): ?><p class="note-copy small text-muted mt-2 mb-0"><strong>Notes:</strong> <?php echo htmlspecialchars($d['notes']); ?></p><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="grand-total" aria-labelledby="final-result-heading">
                    <h2 id="final-result-heading"><i class="fas fa-calculator me-2" aria-hidden="true"></i>Final reconciliation result</h2>
                    <div class="row g-3 mt-1">
                        <div class="col-6 col-lg-3"><small>Total cash available</small><div class="result-value currency">₦<?php echo number_format($totals['total_cash_available'], 2); ?></div></div>
                        <div class="col-6 col-lg-3"><small>Less: expenses</small><div class="result-value currency">-₦<?php echo number_format($totals['cashier_expenses'], 2); ?></div></div>
                        <div class="col-6 col-lg-3"><small>Expected deposit</small><div class="result-value currency">₦<?php echo number_format($totals['expected_deposit'], 2); ?></div></div>
                        <div class="col-6 col-lg-3"><small>Actual bank deposit</small><div class="result-value currency">₦<?php echo number_format($totals['bank_deposit_amount'], 2); ?></div></div>
                    </div>
                    <hr>
                    <div class="row align-items-end g-3">
                        <div class="col-md-6"><small>Deposit variance</small><div class="result-value <?php echo $totals['variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>"><?php echo $totals['variance'] >= 0 ? '+' : '-'; ?>₦<?php echo number_format(abs($totals['variance']), 2); ?> · <?php echo $totals['variance'] >= 0 ? 'Surplus' : 'Shortage'; ?></div></div>
                        <div class="col-md-6 text-md-end"><small>Overall reconciliation status</small><div class="mt-1"><span class="status-badge <?php echo $is_overall_balanced ? 'status-balanced' : ($overall_variance > 0 ? 'status-surplus' : 'status-short'); ?>"><?php echo $overall_status_label; ?></span></div></div>
                    </div>
                    <div class="mt-3 pt-3 border-top"><small>System cash (sales + collections)</small><div class="result-value currency">₦<?php echo number_format($total_system_cash, 2); ?></div><small>Total bank deposits</small><div class="result-value currency">₦<?php echo number_format($total_deposited, 2); ?></div><small>Overall variance</small><div class="result-value currency <?php echo $overall_variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>"><?php echo $overall_variance >= 0 ? '+' : '-'; ?>₦<?php echo number_format(abs($overall_variance), 2); ?></div></div>
                </section>

                <nav class="action-group mt-4 no-print" aria-label="Reconciliation actions">
                    <a href="deposit.php" class="btn btn-warning"><i class="fas fa-plus me-2" aria-hidden="true"></i>Record new deposit</a>
                    <a href="../dashboard/index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>Back to dashboard</a>
                </nav>
            </div>
        </main>
    </div>
    <script src="<?php echo BASE_URL; ?>/assets/js/clean-dashboard.js"></script>
</body>
</html>


<?php
/**
 * Daily Activities Summary
 * 
 * Clean summary of all daily activities:
 * - Products returned to stock
 * - Cash collected
 * - Transfer payments
 * - Credit sales
 * - Expenses incurred
 */

require_once __DIR__ . '/../../config/session.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';
$deleteActivityCsrfToken = hasRole(['admin']) ? SecurityUtils::generateCSRFToken() : '';

$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_driver = $_GET['driver'] ?? '';

// Get summary data
$summary = [];
$summaryAvailable = true;
try {
    $sql = "
        SELECT 
            da.*,
            (SELECT COALESCE(SUM(quantity), 0) FROM daily_returns WHERE driver_id = da.driver_id AND return_date = da.activity_date) as total_returns
        FROM daily_activities da
        WHERE da.activity_date = ?
    ";
    $params = [$filter_date];
    
    if (!empty($filter_driver)) {
        $sql .= " AND da.driver_id = ?";
        $params[] = $filter_driver;
    }
    
    $sql .= " ORDER BY da.recorded_at DESC";
    
    $stmt = executeQuery($sql, $params);
    $summary = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load summary';
    $summaryAvailable = false;
}

// Get drivers for filter
$drivers = [];
try {
    $stmt = executeQuery("SELECT id, name FROM drivers WHERE status = 'active' ORDER BY name");
    $drivers = $stmt->fetchAll();
} catch (PDOException $e) {
    $drivers = [];
}

// Get returns details
$returns_details = [];
$returnsDetailsAvailable = true;
try {
    $sql = "
        SELECT dr.*, p.name as product_name
        FROM daily_returns dr
        JOIN products p ON dr.product_id = p.id
        WHERE dr.return_date = ?
    ";
    $params = [$filter_date];
    
    if (!empty($filter_driver)) {
        $sql .= " AND dr.driver_id = ?";
        $params[] = $filter_driver;
    }
    
    $stmt = executeQuery($sql, $params);
    $returns_details = $stmt->fetchAll();
} catch (PDOException $e) {
    $returns_details = [];
    $returnsDetailsAvailable = false;
}

// Calculate totals
$totals = [
    'cash' => 0,
    'transfer' => 0,
    'credit' => 0,
    'expenses' => 0,
    'net_collection' => 0,
    'total_returns' => 0,
    'shop_sales_count' => 0,
    'shop_sales_amount' => 0,
    'shop_sales_units' => 0
];

foreach ($summary as $s) {
    $totals['cash'] += $s['cash_collected'];
    $totals['transfer'] += $s['transfer_amount'];
    $totals['credit'] += $s['credit_amount'];
    $totals['expenses'] += $s['expenses_amount'];
    $totals['total_returns'] += $s['total_returns'];
}
$totals['net_collection'] = $totals['cash'] + $totals['transfer'];
$totals['total_revenue'] = $totals['net_collection'] + $totals['credit'];

// Get shop sales data
$shopSalesAvailable = true;
try {
    // From shop_sales table
    $stmt = executeQuery("
        SELECT
            COUNT(*) as sales_count,
            COALESCE(SUM(total_amount), 0) as total_amount
        FROM shop_sales
        WHERE DATE(sale_date) = ?
    ", [$filter_date]);
    $shop_sales_new = $stmt->fetch();

    // From dispatches table (shop channel) - no payment_method column
    $stmt = executeQuery("
        SELECT
            COUNT(*) as sales_count,
            COALESCE(SUM(actual_sales_amount), 0) as total_amount
        FROM dispatches
        WHERE DATE(dispatch_date) = ? AND sales_channel IN ('shop', 'shop_sales')
    ", [$filter_date]);
    $shop_sales_old = $stmt->fetch();

    // Get shop sales units from shop_sales_items
    $stmt = executeQuery("
        SELECT COALESCE(SUM(ssi.quantity), 0) as total_units
        FROM shop_sales_items ssi
        JOIN shop_sales ss ON ssi.shop_sale_id = ss.id
        WHERE DATE(ss.sale_date) = ?
    ", [$filter_date]);
    $shop_sales_units_new = $stmt->fetch();

    // Get shop sales units from dispatch_items (shop channel)
    $stmt = executeQuery("
        SELECT COALESCE(SUM(di.quantity), 0) as total_units
        FROM dispatch_items di
        JOIN dispatches d ON di.dispatch_id = d.id
        WHERE DATE(d.dispatch_date) = ? AND d.sales_channel IN ('shop', 'shop_sales')
    ", [$filter_date]);
    $shop_sales_units_old = $stmt->fetch();

    $totals['shop_sales_count'] = ($shop_sales_new['sales_count'] ?? 0) + ($shop_sales_old['sales_count'] ?? 0);
    $totals['shop_sales_amount'] = ($shop_sales_new['total_amount'] ?? 0) + ($shop_sales_old['total_amount'] ?? 0);
    $totals['shop_sales_units'] = ($shop_sales_units_new['total_units'] ?? 0) + ($shop_sales_units_old['total_units'] ?? 0);

    // Add shop sales to unified totals
    $totals['total_revenue'] += $totals['shop_sales_amount'];
} catch (PDOException $e) {
    // Use defaults
    $shopSalesAvailable = false;
}

// Group returns by product
$returns_by_product = [];
foreach ($returns_details as $ret) {
    if (!isset($returns_by_product[$ret['product_name']])) {
        $returns_by_product[$ret['product_name']] = 0;
    }
    $returns_by_product[$ret['product_name']] += $ret['quantity'];
}

$selectedDriverLabel = 'All Drivers';
if ($filter_driver !== '') {
    $selectedDriverLabel = 'Selected Driver';
    foreach ($drivers as $driverOption) {
        if ($driverOption['id'] == $filter_driver) {
            $selectedDriverLabel = $driverOption['name'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Summary - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        :root {
            --report-ink: #18352f;
            --report-muted: #62736e;
            --report-line: #d9e3df;
            --report-paper: #ffffff;
            --report-wash: #f2f7f4;
            --report-green: #176b52;
            --report-green-soft: #e8f4ed;
            --report-amber: #9a5b12;
            --report-amber-soft: #fff4df;
        }

        body { color: var(--report-ink); }
        .daily-report { max-width: 1440px; margin: 0 auto; padding: 1.5rem clamp(0.75rem, 2.4vw, 2rem) 2.5rem; }
        .report-heading, .report-section, .filter-panel { background: var(--report-paper); border: 1px solid var(--report-line); border-radius: 8px; }
        .report-heading { padding: clamp(1rem, 3vw, 2rem); border-top: 4px solid var(--report-green); margin-bottom: 1.25rem; }
        .report-brand { color: var(--report-green); font-size: 0.78rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
        .report-heading h1 { color: var(--report-ink); font-size: clamp(1.45rem, 3vw, 2rem); font-weight: 750; margin: 0.35rem 0 0.8rem; }
        .report-meta { display: flex; flex-wrap: wrap; gap: 0.5rem 1.25rem; color: var(--report-muted); }
        .report-meta strong { color: var(--report-ink); }
        .report-heading-actions { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 0.5rem; }
        .filter-panel { padding: 1rem; margin-bottom: 1.25rem; }
        .filter-grid { display: grid; grid-template-columns: minmax(170px, 1fr) minmax(200px, 1.4fr) auto; gap: 0.9rem; align-items: end; }
        .filter-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .form-label { color: var(--report-ink); font-size: 0.88rem; font-weight: 700; }
        .form-control, .form-select { min-height: 44px; border-color: #b9c9c2; border-radius: 5px; }
        .form-control:focus, .form-select:focus { border-color: var(--report-green); box-shadow: 0 0 0 3px rgba(23, 107, 82, 0.14); }
        .btn-report { min-height: 42px; border-radius: 5px; font-weight: 700; }
        .btn-report-primary { color: #fff; background: var(--report-green); border: 1px solid var(--report-green); }
        .btn-report-primary:hover { color: #fff; background: #10523e; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 185px), 1fr)); gap: 0.8rem; margin-bottom: 1.25rem; }
        .metric-card { min-width: 0; padding: 1rem; background: var(--report-paper); border: 1px solid var(--report-line); border-left: 4px solid var(--report-green); border-radius: 6px; }
        .metric-label { color: var(--report-muted); font-size: 0.82rem; font-weight: 700; }
        .metric-value { overflow-wrap: anywhere; margin-top: 0.35rem; color: var(--report-ink); font-size: 1.25rem; font-weight: 800; font-variant-numeric: tabular-nums; }
        .metric-card:nth-child(3n + 2) { border-left-color: #327a91; }
        .metric-card:nth-child(3n) { border-left-color: #c17a26; }
        .report-section { margin-bottom: 1rem; overflow: hidden; }
        .section-heading { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.5rem; padding: 1rem 1.1rem; border-bottom: 1px solid var(--report-line); }
        .section-heading h2 { margin: 0; color: var(--report-ink); font-size: 1.08rem; font-weight: 750; }
        .section-note { color: var(--report-muted); font-size: 0.82rem; }
        .section-content { padding: 1rem 1.1rem; }
        .report-table-wrap { width: 100%; overflow-x: auto; overscroll-behavior-x: contain; }
        .report-table { width: 100%; min-width: 580px; margin: 0; border-color: var(--report-line); }
        .report-table thead th { color: var(--report-ink); background: var(--report-wash); font-size: 0.79rem; font-weight: 800; white-space: nowrap; }
        .report-table th, .report-table td { padding: 0.7rem 0.75rem; vertical-align: middle; }
        .report-table td { overflow-wrap: anywhere; }
        .currency, .numeric { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .empty-state, .unavailable-state { padding: 0.9rem 1rem; color: var(--report-muted); background: var(--report-wash); border-radius: 5px; }
        .unavailable-state { color: #72511f; background: var(--report-amber-soft); }
        .status-badge { display: inline-block; padding: 0.25rem 0.55rem; color: #72511f; background: var(--report-amber-soft); border: 1px solid #eed39d; border-radius: 999px; font-size: 0.76rem; font-weight: 800; }
        .driver-name, .note-cell { overflow-wrap: anywhere; white-space: normal; }
        .inline-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.35rem; }
        .back-link { color: var(--report-green); font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .screen-only { display: initial; }

        @media (max-width: 767.98px) {
            .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .filter-actions { grid-column: 1 / -1; }
            .filter-actions .btn { flex: 1 1 auto; }
        }
        @media (max-width: 420px) {
            .filter-grid { grid-template-columns: minmax(0, 1fr); }
            .filter-actions { grid-column: auto; }
            .report-heading-actions { width: 100%; }
            .report-heading-actions .btn { flex: 1 1 auto; }
            .section-content { padding: 0.75rem; }
        }
        @media print {
            @page { size: A4 portrait; margin: 14mm 12mm; }
            body { color: #17241f !important; background: #fff !important; font-size: 9.5pt; }
            .sidebar, .top-bar, .filter-panel, .screen-only, .inline-actions, .mobile-menu-toggle { display: none !important; }
            .main-content, .dashboard-content { width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .daily-report { max-width: none; padding: 0; }
            .report-heading, .report-section, .metric-card { box-shadow: none !important; background: #fff !important; }
            .report-heading { padding: 0 0 8mm; border: 0; border-bottom: 2px solid #176b52; border-radius: 0; }
            .report-heading h1 { font-size: 19pt; }
            .summary-grid { grid-template-columns: repeat(3, 1fr); gap: 3mm; }
            .metric-card { padding: 3mm; break-inside: avoid; }
            .metric-value { font-size: 11pt; }
            .report-section { margin-bottom: 5mm; border-radius: 0; break-inside: avoid; }
            .section-heading, .section-content { padding: 3mm; }
            .report-table-wrap { overflow: visible; }
            .report-table { min-width: 0; font-size: 8pt; }
            .report-table thead { display: table-header-group; }
            .report-table tr { break-inside: avoid; }
            .report-table th, .report-table td { padding: 2mm; }
            .status-badge { color: #543b0d !important; background: #fff4df !important; border-color: #9a7b43 !important; }
            a { color: inherit !important; text-decoration: none !important; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="<?php echo htmlspecialchars(BASE_URL); ?>/dashboard/index.php" class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <span class="logo-text">Merrylay Enterprises</span>
                </a>
                <button class="toggle-btn" id="sidebarToggle" type="button" aria-label="Toggle navigation">
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
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle" type="button" aria-label="Open navigation">
                        <i class="fas fa-bars"></i>
                    </button>
                    <a class="back-link" href="<?php echo htmlspecialchars(BASE_URL); ?>/dispatch/daily/index.php">Back to Daily Sales</a>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-menu dropdown">
                            <button class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-label="Open user menu">
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
                                <li><a class="dropdown-item" href="../../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="../../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <div class="dashboard-content">
                <div class="daily-report">
                    <header class="report-heading">
                        <div class="report-heading-actions justify-content-between">
                            <div>
                                <div class="report-brand">Merrylay Enterprises</div>
                                <h1>Daily Dispatcher Summary</h1>
                                <div class="report-meta">
                                    <span><strong>Report date:</strong> <?php echo htmlspecialchars(date('F j, Y', strtotime($filter_date))); ?></span>
                                    <span><strong>Driver:</strong> <?php echo htmlspecialchars($selectedDriverLabel); ?></span>
                                </div>
                            </div>
                            <?php if (!hasRole('dispatcher')): ?>
                            <a href="<?php echo htmlspecialchars(BASE_URL); ?>/dispatch/daily/record.php?record_date=<?php echo urlencode($filter_date); ?>" class="btn btn-outline-secondary btn-report screen-only">
                                <i class="fas fa-plus me-2" aria-hidden="true"></i>Record Activity
                            </a>
                            <?php endif; ?>
                        </div>
                    </header>

                    <section class="filter-panel screen-only" aria-label="Report filters">
                        <form method="GET" action="<?php echo htmlspecialchars(BASE_URL); ?>/dispatch/daily/summary.php">
                            <div class="filter-grid">
                                <div>
                                    <label class="form-label" for="daily-summary-date">Date</label>
                                    <input id="daily-summary-date" type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>">
                                </div>
                                <div>
                                    <label class="form-label" for="daily-summary-driver">Driver</label>
                                    <select id="daily-summary-driver" name="driver" class="form-select">
                                        <option value="">All Drivers</option>
                                        <?php foreach ($drivers as $d): ?>
                                            <option value="<?php echo htmlspecialchars($d['id']); ?>" <?php echo $filter_driver == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-report btn-report-primary"><i class="fas fa-filter me-2" aria-hidden="true"></i>Apply Filter</button>
                                    <a href="<?php echo htmlspecialchars(BASE_URL); ?>/dispatch/daily/summary.php" class="btn btn-outline-secondary btn-report">Clear / Today</a>
                                </div>
                            </div>
                        </form>
                    </section>

                    <section aria-label="Daily summary totals">
                        <div class="summary-grid">
                            <article class="metric-card">
                                <div class="metric-label">Cash Collected</div>
                                <div class="metric-value"><?php echo $summaryAvailable ? '₦' . number_format($totals['cash'], 2) : 'Unavailable'; ?></div>
                            </article>
                            <article class="metric-card">
                                <div class="metric-label">Transfers</div>
                                <div class="metric-value"><?php echo $summaryAvailable ? '₦' . number_format($totals['transfer'], 2) : 'Unavailable'; ?></div>
                            </article>
                            <article class="metric-card">
                                <div class="metric-label">Credit</div>
                                <div class="metric-value"><?php echo $summaryAvailable ? '₦' . number_format($totals['credit'], 2) : 'Unavailable'; ?></div>
                            </article>
                            <article class="metric-card">
                                <div class="metric-label">Expenses</div>
                                <div class="metric-value"><?php echo $summaryAvailable ? '₦' . number_format($totals['expenses'], 2) : 'Unavailable'; ?></div>
                            </article>
                            <article class="metric-card">
                                <div class="metric-label">Units Returned</div>
                                <div class="metric-value"><?php echo $summaryAvailable ? number_format($totals['total_returns']) : 'Unavailable'; ?></div>
                            </article>
                            <article class="metric-card">
                                <div class="metric-label">Shop Sales</div>
                                <div class="metric-value"><?php echo $shopSalesAvailable ? '₦' . number_format($totals['shop_sales_amount'], 2) : 'Unavailable'; ?></div>
                            </article>
                        </div>
                    </section>

                    <section class="report-section" aria-labelledby="dispatch-overview-title">
                        <div class="section-heading">
                            <h2 id="dispatch-overview-title">Dispatch Overview</h2>
                            <span class="section-note">Figures reflect the existing daily activity and shop sales sources.</span>
                        </div>
                        <div class="section-content">
                            <div class="report-table-wrap">
                                <table class="table table-bordered report-table">
                                    <thead><tr><th scope="col">Measure</th><th scope="col" class="numeric">Value</th><th scope="col">Availability</th></tr></thead>
                                    <tbody>
                                        <tr><th scope="row">Daily activity records</th><td class="numeric"><?php echo $summaryAvailable ? number_format(count($summary)) : 'Unavailable'; ?></td><td><?php echo $summaryAvailable ? 'Available' : 'Query unavailable'; ?></td></tr>
                                        <tr><th scope="row">Shop sales transactions</th><td class="numeric"><?php echo $shopSalesAvailable ? number_format($totals['shop_sales_count']) : 'Unavailable'; ?></td><td><?php echo $shopSalesAvailable ? 'Available' : 'Query unavailable'; ?></td></tr>
                                        <tr><th scope="row">Shop sales units</th><td class="numeric"><?php echo $shopSalesAvailable ? number_format($totals['shop_sales_units']) : 'Unavailable'; ?></td><td><?php echo $shopSalesAvailable ? 'Available' : 'Query unavailable'; ?></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="section-note mt-2 mb-0">Overall dispatch count and expected dispatch value are not supplied by this report's existing queries.</p>
                        </div>
                    </section>

                    <section class="report-section" aria-labelledby="sales-collections-title">
                        <div class="section-heading">
                            <h2 id="sales-collections-title">Sales and Collections</h2>
                            <span class="section-note">Currency values are shown to two decimal places.</span>
                        </div>
                        <div class="section-content">
                            <?php if (!$summaryAvailable): ?>
                                <div class="unavailable-state">Sales and collection values are unavailable because the activity summary could not be loaded.</div>
                            <?php elseif (empty($summary)): ?>
                                <div class="empty-state">No activity records are available for this date and driver filter.</div>
                            <?php else: ?>
                                <div class="report-table-wrap">
                                    <table class="table table-bordered report-table">
                                        <thead><tr><th scope="col">Driver</th><th scope="col" class="currency">Cash</th><th scope="col" class="currency">Transfer</th><th scope="col" class="currency">Credit</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($summary as $s): ?>
                                            <tr>
                                                <th scope="row" class="driver-name"><?php echo htmlspecialchars($s['driver_name']); ?></th>
                                                <td class="currency">₦<?php echo number_format($s['cash_collected'], 2); ?></td>
                                                <td class="currency">₦<?php echo number_format($s['transfer_amount'], 2); ?></td>
                                                <td class="currency">₦<?php echo number_format($s['credit_amount'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <p class="section-note mt-2 mb-0">Shop sales are presented separately; the existing queries do not provide an overall actual driver-sales figure.</p>
                        </div>
                    </section>

                    <section class="report-section" aria-labelledby="returns-damage-title">
                        <div class="section-heading"><h2 id="returns-damage-title">Returns and Damage</h2></div>
                        <div class="section-content">
                            <h3 class="h6 fw-bold">Products Returned</h3>
                            <?php if (!$returnsDetailsAvailable): ?>
                                <div class="unavailable-state mb-3">Product return details are unavailable.</div>
                            <?php elseif (empty($returns_by_product)): ?>
                                <div class="empty-state mb-3">No product returns recorded for this date.</div>
                            <?php else: ?>
                                <div class="report-table-wrap mb-3">
                                    <table class="table table-bordered report-table">
                                        <thead><tr><th scope="col">Product</th><th scope="col" class="numeric">Quantity Returned</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($returns_by_product as $product => $qty): ?>
                                            <tr><th scope="row"><?php echo htmlspecialchars($product); ?></th><td class="numeric"><?php echo number_format($qty); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <div class="unavailable-state">Damage figures are not supplied by this report's existing queries.</div>
                        </div>
                    </section>

                    <section class="report-section" aria-labelledby="expenses-title">
                        <div class="section-heading"><h2 id="expenses-title">Expenses</h2></div>
                        <div class="section-content">
                            <?php if (!$summaryAvailable): ?>
                                <div class="unavailable-state">Expense details are unavailable because the activity summary could not be loaded.</div>
                            <?php elseif (empty($summary)): ?>
                                <div class="empty-state">No expense records are available for this date and driver filter.</div>
                            <?php else: ?>
                                <div class="report-table-wrap">
                                    <table class="table table-bordered report-table">
                                        <thead><tr><th scope="col">Driver</th><th scope="col">Note</th><th scope="col" class="currency">Expense</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($summary as $s): ?>
                                            <tr>
                                                <th scope="row" class="driver-name"><?php echo htmlspecialchars($s['driver_name']); ?></th>
                                                <td class="note-cell"><?php echo !empty($s['expenses_note']) ? nl2br(htmlspecialchars($s['expenses_note'])) : '—'; ?></td>
                                                <td class="currency">₦<?php echo number_format($s['expenses_amount'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="report-section" aria-labelledby="driver-performance-title">
                        <div class="section-heading"><h2 id="driver-performance-title">Driver / Trip Performance</h2></div>
                        <div class="section-content">
                            <?php if (!$summaryAvailable): ?>
                                <div class="unavailable-state">Driver activity details are unavailable.</div>
                            <?php elseif (empty($summary)): ?>
                                <div class="empty-state">No driver activity records are available for this date and filter.</div>
                            <?php else: ?>
                                <div class="report-table-wrap">
                                    <table class="table table-bordered report-table">
                                        <thead><tr><th scope="col">Driver</th><th scope="col">Recorded</th><th scope="col" class="numeric">Returns</th><th scope="col">Actions</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($summary as $s): ?>
                                            <tr>
                                                <th scope="row" class="driver-name"><?php echo htmlspecialchars($s['driver_name']); ?></th>
                                                <td><?php echo htmlspecialchars(date('g:i A', strtotime($s['recorded_at']))); ?></td>
                                                <td class="numeric"><?php echo number_format($s['total_returns']); ?></td>
                                                <td>
                                                    <div class="inline-actions">
                                                        <?php if (!hasRole('dispatcher')): ?>
                                                        <a href="<?php echo htmlspecialchars(BASE_URL); ?>/dispatch/daily/record.php?activity_id=<?php echo urlencode($s['id']); ?>&amp;record_date=<?php echo urlencode($filter_date); ?>" class="btn btn-sm btn-outline-warning" aria-label="Edit activity for <?php echo htmlspecialchars($s['driver_name']); ?>"><i class="fas fa-edit" aria-hidden="true"></i><span class="visually-hidden">Edit record</span></a>
                                                        <?php endif; ?>
                                                        <?php if (hasRole(['admin'])): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteActivity(<?php echo (int) $s['id']; ?>)" aria-label="Delete activity for <?php echo htmlspecialchars($s['driver_name']); ?>"><i class="fas fa-trash-alt" aria-hidden="true"></i><span class="visually-hidden">Delete record</span></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="report-section" aria-labelledby="reconciliation-title">
                        <div class="section-heading"><h2 id="reconciliation-title">Variance and Reconciliation</h2></div>
                        <div class="section-content">
                            <div class="report-table-wrap">
                                <table class="table table-bordered report-table">
                                    <thead><tr><th scope="col">Measure</th><th scope="col">Result</th><th scope="col">Status</th></tr></thead>
                                    <tbody>
                                        <tr><th scope="row">Expected dispatch value</th><td>Unavailable</td><td><span class="status-badge">Pending</span></td></tr>
                                        <tr><th scope="row">Overall actual sales</th><td>Unavailable</td><td><span class="status-badge">Pending</span></td></tr>
                                        <tr><th scope="row">Variance / balance</th><td>Unavailable</td><td><span class="status-badge">Pending</span></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="section-note mt-2 mb-0">Balanced, shortage, or surplus status cannot be determined from the values supplied to this report.</p>
                        </div>
                    </section>

                    <nav class="screen-only" aria-label="Report navigation">
                        <a class="back-link" href="<?php echo htmlspecialchars(BASE_URL); ?>/dispatch/daily/index.php"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>Back to Daily Sales</a>
                    </nav>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/clean-dashboard.js"></script>
    <script>
        function deleteActivity(activityId) {
            console.log('Delete function called with ID:', activityId);
            if (confirm('Are you sure you want to delete this activity record?\n\nThis will:\n- Delete the activity record\n- Remove associated returns and restore stock\n- Update dispatch sales amount\n- Adjust creditor records\n- Remove related customer payments\n\nThis action cannot be undone.')) {
                if (confirm('Please confirm again to delete this record permanently.')) {
                    const deleteForm = document.getElementById('deleteActivityForm');
                    if (deleteForm) {
                        deleteForm.elements.id.value = activityId;
                        deleteForm.requestSubmit();
                    }
                }
            }
        }
    </script>
    <?php if (hasRole(['admin'])): ?>
    <form id="deleteActivityForm" method="POST" action="delete_activity.php" hidden>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($deleteActivityCsrfToken); ?>">
    </form>
    <?php endif; ?>
</body>
</html>


<?php
/**
 * Profit & Loss Statement Page for Merrylay Enterprises
 * 
 * Displays income statement with revenue, expenses, and profit/loss
 */

// Start session
require_once __DIR__ . '/../config/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration
require_once '../config/db.php';
require_once '../config/auth.php';

if (!hasRole(['admin', 'manager']) && !hasPermission(PERMISSION_REPORTS_VIEW)) {
    error_log('Unauthorized profit and loss report access by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to view this report.');
}

// Get date range
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Calculate revenue and costs
try {
        // Sales revenue - use completed sales totals because this system records revenue in the sales ledger.
    // Note: cost_price is used as the effective selling price in this implementation,
    // so product gross margin is expected to be zero and net profit reflects operating costs.
    $stmt = executeQuery("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(discount_amount), 0) as total_discounts,
            COALESCE(SUM(tax_amount), 0) as total_tax
        FROM sales
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND status = 'completed'
    ", [$start_date, $end_date]);
    $revenue = $stmt->fetch();
    
    // Cost of goods sold - use the historical sale item cost_price to preserve accuracy.
    $stmt = executeQuery("
        SELECT COALESCE(SUM(si.quantity * si.cost_price), 0) as total_cogs
        FROM sales s
        JOIN sale_items si ON s.id = si.sale_id
        WHERE DATE(s.created_at) BETWEEN ? AND ?
        AND s.status = 'completed'
    ", [$start_date, $end_date]);
    $cogs = $stmt->fetch()['total_cogs'];
    
    // Returns and allowances (negative revenue) - using recorded daily returns values.
    $stmt = executeQuery("
        SELECT COALESCE(SUM(dr.quantity * p.cost_price), 0) as returns_allowances
        FROM daily_returns dr
        JOIN products p ON dr.product_id = p.id
        WHERE dr.return_date BETWEEN ? AND ?
    ", [$start_date, $end_date]);
    $returns = $stmt->fetch()['returns_allowances'];

    // Operating expenses - aggregate recorded dispatcher and activity expenses
    $stmt = executeQuery("
        SELECT COALESCE(SUM(amount), 0) as dispatcher_expenses
        FROM dispatcher_expenses
        WHERE DATE(expense_date) BETWEEN ? AND ?
                    AND (notes IS NULL OR notes NOT LIKE '[daily_activity_id:%')
    ", [$start_date, $end_date]);
    $dispatcher_expenses = $stmt->fetch()['dispatcher_expenses'];

    $stmt = executeQuery("
        SELECT COALESCE(SUM(expenses_amount), 0) as activity_expenses
        FROM daily_activities
        WHERE activity_date BETWEEN ? AND ?
    ", [$start_date, $end_date]);
    $activity_expenses = $stmt->fetch()['activity_expenses'];

    $operating_expenses = $dispatcher_expenses + $activity_expenses;

    // Calculate gross profit
    $gross_revenue = $revenue['total_revenue'];
    $net_revenue = $gross_revenue - $returns;
    $gross_profit = $net_revenue - $cogs;
    $operating_profit = $gross_profit - $operating_expenses;
    $net_profit = $operating_profit; // Before taxes
    
    // Additional metrics
    $stmt = executeQuery("
        SELECT 
            COUNT(DISTINCT s.id) as total_transactions,
            COALESCE(AVG(s.total_amount), 0) as avg_transaction_value,
            COUNT(DISTINCT si.product_id) as products_sold
        FROM sales s
        LEFT JOIN sale_items si ON s.id = si.sale_id
        WHERE DATE(s.created_at) BETWEEN ? AND ?
        AND s.status = 'completed'
    ", [$start_date, $end_date]);
    $metrics = $stmt->fetch();
    
    // Monthly comparison (previous month)
    $prev_start = date('Y-m-01', strtotime('-1 month', strtotime($start_date)));
    $prev_end = date('Y-m-t', strtotime('-1 month', strtotime($end_date)));
    
    $stmt = executeQuery("
        SELECT COALESCE(SUM(total_amount), 0) as prev_revenue
        FROM sales 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND status = 'completed'
    ", [$prev_start, $prev_end]);
    $prev_revenue = $stmt->fetch()['prev_revenue'];
    
    $revenue_growth = $prev_revenue > 0 ? (($net_revenue - $prev_revenue) / $prev_revenue) * 100 : 0;
    
} catch (PDOException $e) {
    error_log("P&L statement error: " . $e->getMessage());
    $error_message = "Unable to generate profit & loss statement.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Statement - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <link href="../assets/fonts/inter/inter.css" rel="stylesheet">
    <style>
        .pl-statement {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
        }
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 12px 12px 0 0;
        }
        .income-item, .expense-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        .total-row {
            background: #f8f9fa;
            font-weight: bold;
            border-top: 2px solid #dee2e6;
        }
        .net-profit {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        .net-loss {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .growth-positive {
            color: #28a745;
        }
        .growth-negative {
            color: #dc3545;
        }

        .print-context {
            display: none;
        }

        @page {
            size: A4 portrait;
            margin: 14mm;
        }

        @media print {
            html,
            body {
                background: #fff !important;
                color: #111 !important;
                font-size: 10pt;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .sidebar,
            .top-bar,
            .btn-toolbar,
            .mobile-menu-toggle {
                display: none !important;
            }

            .dashboard-container {
                display: block !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .print-context {
                display: block;
                margin: 0.25rem 0 1rem;
                color: #333;
            }

            .pl-statement,
            .metric-card,
            .card {
                box-shadow: none !important;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .section-header,
            .net-profit,
            .net-loss {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .col-lg-8 {
                width: 66.6667% !important;
            }

            .col-lg-4 {
                width: 33.3333% !important;
            }

            h1,
            h2,
            h3,
            h4,
            h5,
            h6 {
                break-after: avoid;
                page-break-after: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <nav class="sidebar-nav">
                <a href="../dashboard/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="../products/view.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-box"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="../stock/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock Mgmt</span>
                </a>
                <a href="../dispatch/daily/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Sales</span>
                </a>
                <a href="../dispatch/trips/record.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-truck"></i></span>
                    <span class="nav-text">Dispatch</span>
                </a>
                <a href="../dispatch/daily/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-calendar-day"></i></span>
                    <span class="nav-text">Daily Sales</span>
                </a>
                <a href="../admin/end-of-day.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../accounts/balance_sheet.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Accounts</span>
                </a>
                <a href="../reports/daily.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="../admin/data_management.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <?php endif; ?>
                <a href="../auth/logout.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main content -->
        <main class="main-content" id="mainContent">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">Profit & Loss Statement</h1>
                        <p class="print-context">Reporting period: <?php echo date('M j, Y', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?></p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <form method="GET" class="d-flex">
                            <label class="visually-hidden" for="profitLossStartDate">Start date</label>
                            <input type="date" id="profitLossStartDate" class="form-control me-2" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                            <label class="visually-hidden" for="profitLossEndDate">End date</label>
                            <input type="date" id="profitLossEndDate" class="form-control me-2" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>Update
                            </button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="window.print()">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h3 class="text-primary">₦<?php echo number_format($net_profit, 2); ?></h3>
                            <p class="mb-0">Net <?php echo $net_profit >= 0 ? 'Profit' : 'Loss'; ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h3 class="text-success">₦<?php echo number_format($net_revenue, 2); ?></h3>
                            <p class="mb-0">Total Revenue</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h3 class="text-info"><?php echo $metrics['total_transactions']; ?></h3>
                            <p class="mb-0">Transactions</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <h3 class="text-warning <?php echo $revenue_growth >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                                <?php echo number_format($revenue_growth, 1); ?>%
                            </h3>
                            <p class="mb-0">Revenue Growth</p>
                        </div>
                    </div>
                </div>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php else: ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="pl-statement">
                            <div class="section-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-line me-2"></i>Income Statement
                                </h5>
                                <small><?php echo date('M j, Y', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?></small>
                            </div>
                            <div class="p-3">
                                <!-- Revenue -->
                                <h6 class="text-muted mb-3">Revenue</h6>
                                <div class="income-item">
                                    <div class="d-flex justify-content-between">
                                        <span>Gross Sales</span>
                                        <span class="fw-bold">₦<?php echo number_format($revenue['total_revenue'], 2); ?></span>
                                    </div>
                                </div>
                                <div class="expense-item">
                                    <div class="d-flex justify-content-between">
                                        <span>Less: Discounts</span>
                                        <span class="text-danger">-₦<?php echo number_format($revenue['total_discounts'], 2); ?></span>
                                    </div>
                                </div>
                                <div class="expense-item">
                                    <div class="d-flex justify-content-between">
                                        <span>Less: Returns & Allowances</span>
                                        <span class="text-danger">-₦<?php echo number_format($returns, 2); ?></span>
                                    </div>
                                </div>
                                <div class="income-item total-row">
                                    <div class="d-flex justify-content-between">
                                        <span><strong>Net Revenue</strong></span>
                                        <span class="fw-bold">₦<?php echo number_format($net_revenue, 2); ?></span>
                                    </div>
                                </div>

                                <!-- Cost of Goods Sold -->
                                <h6 class="text-muted mb-3 mt-4">Cost of Goods Sold</h6>
                                <div class="expense-item">
                                    <div class="d-flex justify-content-between">
                                        <span>Cost of Goods Sold</span>
                                        <span class="text-danger">-₦<?php echo number_format($cogs, 2); ?></span>
                                    </div>
                                </div>
                                <div class="income-item total-row">
                                    <div class="d-flex justify-content-between">
                                        <span><strong>Gross Profit</strong></span>
                                        <span class="fw-bold">₦<?php echo number_format($gross_profit, 2); ?></span>
                                    </div>
                                </div>

                                <!-- Operating Expenses -->
                                <h6 class="text-muted mb-3 mt-4">Operating Expenses</h6>
                                <div class="expense-item">
                                    <div class="d-flex justify-content-between">
                                        <span>Operating Expenses</span>
                                        <span class="text-danger">-₦<?php echo number_format($operating_expenses, 2); ?></span>
                                    </div>
                                    <small class="text-muted">* Expense tracking not yet implemented</small>
                                </div>
                                <div class="income-item total-row">
                                    <div class="d-flex justify-content-between">
                                        <span><strong>Operating Profit</strong></span>
                                        <span class="fw-bold">₦<?php echo number_format($operating_profit, 2); ?></span>
                                    </div>
                                </div>

                                <!-- Net Profit/Loss -->
                                <div class="mt-4 <?php echo $net_profit >= 0 ? 'net-profit' : 'net-loss'; ?>">
                                    <div class="d-flex justify-content-between align-items-center p-3">
                                        <span class="fs-5"><strong>Net <?php echo $net_profit >= 0 ? 'Profit' : 'Loss'; ?></strong></span>
                                        <span class="fs-4 fw-bold">₦<?php echo number_format(abs($net_profit), 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Additional Insights -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="fas fa-lightbulb me-2"></i>Key Insights
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted d-block">Average Transaction Value</small>
                                    <strong>₦<?php echo number_format($metrics['avg_transaction_value'], 2); ?></strong>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted d-block">Products Sold</small>
                                    <strong><?php echo $metrics['products_sold']; ?> different products</strong>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted d-block">Gross Margin</small>
                                    <strong><?php echo $net_revenue > 0 ? number_format(($gross_profit / $net_revenue) * 100, 1) : 0; ?>%</strong>
                                </div>
                                <div class="mb-0">
                                    <small class="text-muted d-block">Revenue Trend</small>
                                    <strong class="<?php echo $revenue_growth >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <i class="fas fa-arrow-<?php echo $revenue_growth >= 0 ? 'up' : 'down'; ?> me-1"></i>
                                        <?php echo abs($revenue_growth); ?>% vs last period
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <!-- Top Products -->
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="fas fa-trophy me-2"></i>Top Products
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $stmt = executeQuery("
                                    SELECT 
                                        p.name,
                                        SUM(si.quantity) as total_quantity,
                                        SUM(si.price) as total_revenue
                                    FROM sale_items si
                                    JOIN sales s ON si.sale_id = s.id
                                    JOIN products p ON si.product_id = p.id
                                    WHERE DATE(s.created_at) BETWEEN ? AND ?
                                    AND s.status = 'completed'
                                    GROUP BY p.id, p.name
                                    ORDER BY total_quantity DESC
                                    LIMIT 5
                                ", [$start_date, $end_date]);
                                $top_products = $stmt->fetchAll();
                                
                                if (empty($top_products)): ?>
                                    <p class="text-muted mb-0">No sales data for this period</p>
                                <?php else: 
                                    foreach ($top_products as $index => $product): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <small class="fw-bold">#<?php echo $index + 1; ?></small>
                                                <span class="small"><?php echo htmlspecialchars($product['name']); ?></span>
                                            </div>
                                            <small class="text-muted"><?php echo $product['total_quantity']; ?> sold</small>
                                        </div>
                                    <?php endforeach; 
                                endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


<?php
/**
 * Dispatcher Daily Summary Page for Merrylay Enterprises
 * 
 * Aggregates all dispatches for a specific dispatcher (driver/salesperson) on a given date.
 * 
 * Terminology:
 * - Dispatch: Each dispatch made by a driver to sell products from depot
 * - Dispatcher: Driver who also serves as salesperson
 * 
 * Shows:
 * - All dispatches for the dispatcher on the selected date
 * - Total expected amount across all dispatches
 * - Total products taken from depot
 * - Total returns to depot
 * - Dispatch details with products
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

$error = '';
$success = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Restrict dispatcher to only view their own data
if (hasRole('dispatcher')) {
    $dispatcher_filter = getDispatcherName();
    $available_dispatchers = [$dispatcher_filter];
} else {
    $dispatcher_filter = $_GET['dispatcher'] ?? '';
    
    // Get unique dispatchers for admin/manager
    try {
        $stmt = executeQuery("
            SELECT DISTINCT dispatcher_name 
            FROM dispatches 
            WHERE DATE(dispatch_date) = ? 
                            AND COALESCE(status, '') <> 'cancelled'
            ORDER BY dispatcher_name
        ", [$selected_date]);
        $available_dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Get dispatchers error: " . $e->getMessage());
        $available_dispatchers = [];
    }
    
    // If no dispatcher selected, use the first one
    if (empty($dispatcher_filter) && !empty($available_dispatchers)) {
        $dispatcher_filter = $available_dispatchers[0];
    }
}

$dispatch_trips = [];
$dispatcher_totals = [];

if (!empty($dispatcher_filter)) {
    try {
        // Get all dispatches for this dispatcher on the selected date
        $stmt = executeQuery("
            SELECT 
                d.id,
                d.dispatch_number,
                d.dispatcher_name,
                d.dispatch_date,
                d.dispatch_type,
                d.status,
                d.expected_amount,
                d.actual_sales_amount as old_actual_sales_amount,
                COALESCE(da.total_actual_sales, 0) as actual_sales_amount,
                d.shipping_address,
                d.tracking_number,
                d.created_at,
                COUNT(DISTINCT di.id) as item_count,
                SUM(di.quantity) as total_quantity
            FROM dispatches d
            LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
            LEFT JOIN (
                SELECT driver_name, activity_date,
                    SUM(cash_collected + transfer_amount + credit_amount) as total_actual_sales
                FROM daily_activities
                GROUP BY driver_name, activity_date
            ) da ON da.driver_name = d.dispatcher_name AND DATE(da.activity_date) = DATE(d.dispatch_date)
            WHERE d.dispatcher_name = ? 
                AND DATE(d.dispatch_date) = ?
                AND COALESCE(d.status, '') <> 'cancelled'
            GROUP BY d.id, d.dispatch_number, d.dispatcher_name, d.dispatch_date, d.dispatch_type, 
                     d.status, d.expected_amount, d.actual_sales_amount, d.shipping_address, 
                     d.tracking_number, d.created_at
            ORDER BY d.created_at DESC
        ", [$dispatcher_filter, $selected_date]);
        
        $dispatch_trips = $stmt->fetchAll();
        
        // Get dispatch items for each dispatch
        foreach ($dispatch_trips as &$trip) {
            $stmt = executeQuery("
                SELECT 
                    di.*,
                    p.name as product_name,
                    p.unit
                FROM dispatch_items di
                LEFT JOIN products p ON di.product_id = p.id
                WHERE di.dispatch_id = ?
                ORDER BY di.created_at
            ", [$trip['id']]);
            $trip['items'] = $stmt->fetchAll();
        }
        
        // Calculate totals for this dispatcher on this date
        $stmt = executeQuery("
            SELECT 
                COUNT(DISTINCT d.id) as total_trips,
                SUM(d.expected_amount) as total_expected,
                COALESCE(MAX(da.total_actual_sales), SUM(d.actual_sales_amount)) as total_actual_sales,
                (SELECT COALESCE(SUM(de.amount), 0) FROM dispatcher_expenses de WHERE de.dispatcher_name = d.dispatcher_name AND DATE(de.expense_date) = DATE(d.dispatch_date)) as total_expenses,
                COALESCE(MAX(da.total_actual_sales), SUM(d.actual_sales_amount)) - SUM(d.expected_amount) - (SELECT COALESCE(SUM(de.amount), 0) FROM dispatcher_expenses de WHERE de.dispatcher_name = d.dispatcher_name AND DATE(de.expense_date) = DATE(d.dispatch_date)) as balance,
                SUM(di.quantity) as total_quantity_dispatched
            FROM dispatches d
            LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
            LEFT JOIN (
                SELECT driver_name, activity_date,
                    SUM(cash_collected + transfer_amount + credit_amount) as total_actual_sales
                FROM daily_activities
                GROUP BY driver_name, activity_date
            ) da ON da.driver_name = d.dispatcher_name AND DATE(da.activity_date) = DATE(d.dispatch_date)
            WHERE d.dispatcher_name = ? 
                AND DATE(d.dispatch_date) = ?
                AND COALESCE(d.status, '') <> 'cancelled'
        ", [$dispatcher_filter, $selected_date]);
        
        $dispatcher_totals = $stmt->fetch();
        
    } catch (PDOException $e) {
        error_log("Get dispatcher trips error: " . $e->getMessage());
        $error = 'Unable to load dispatcher trips.';
        $dispatch_trips = [];
        $dispatcher_totals = [];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatcher Daily Summary (Dispatches) - Merrylay Enterprises</title>
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
        
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .summary-card.trips {
            border-left-color: #667eea;
        }
        
        .summary-card.expected {
            border-left-color: #f59e0b;
        }
        
        .summary-card.actual {
            border-left-color: #10b981;
        }
        
        .summary-card.balance {
            border-left-color: #8b5cf6;
        }
        
        .summary-card.quantity {
            border-left-color: #06b6d4;
        }
        
        .summary-card h6 {
            color: #6b7280;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        
        .summary-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .summary-card.balance .value {
            color: #8b5cf6;
        }
        
        .trip-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .trip-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
        }
        
        .trip-header {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .trip-number {
            font-weight: 700;
            color: #667eea;
            font-size: 1.1rem;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-processing {
            background-color: #bfdbfe;
            color: #1e3a8a;
        }
        
        .status-shipped {
            background-color: #a5f3fc;
            color: #164e63;
        }
        
        .status-delivered {
            background-color: #86efac;
            color: #166534;
        }
        
        .status-cancelled {
            background-color: #fecaca;
            color: #7f1d1d;
        }
        
        .trip-body {
            padding: 1.5rem;
        }
        
        .trip-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .info-item {
            font-size: 0.9rem;
        }
        
        .info-label {
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: #1f2937;
            font-size: 1rem;
        }
        
        .trip-items {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .trip-items h6 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }
        
        .item-table {
            width: 100%;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .item-table thead {
            background: #f3f4f6;
        }
        
        .item-table th {
            padding: 0.75rem;
            font-weight: 700;
            color: #6b7280;
            border-bottom: 1px solid #d1d5db;
        }
        
        .item-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .trip-totals {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .trip-total-item {
            text-align: center;
        }
        
        .trip-total-label {
            color: #6b7280;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        
        .trip-total-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #667eea;
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
        
        .filter-row button {
            margin-top: 0.5rem;
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
            <h1><i class="fas fa-user-tie me-2"></i>Daily Dispatch Summary</h1>
            <p>View all dispatches and aggregated performance for each dispatcher (driver/salesperson)</p>
        </div>
        
        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="filter-row">
                <div class="form-group">
                    <label for="date" class="form-label">Select Date</label>
                    <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>">
                </div>
                
                <?php if (!hasRole('dispatcher')): ?>
                <div class="form-group">
                    <label for="dispatcher" class="form-label">Select Dispatcher (Driver)</label>
                    <select id="dispatcher" name="dispatcher" class="form-select">
                        <option value="">All Dispatchers (Drivers)</option>
                        <?php foreach ($available_dispatchers as $disp): ?>
                            <option value="<?php echo htmlspecialchars($disp); ?>" <?php echo $disp === $dispatcher_filter ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($disp); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search me-2"></i>Search
                </button>
                
            </form>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($dispatcher_filter) && !empty($dispatcher_totals)): ?>
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card trips">
                    <h6><i class="fas fa-cube me-2"></i>Total Trips</h6>
                    <div class="value"><?php echo (int)$dispatcher_totals['total_trips']; ?></div>
                </div>
                
                <div class="summary-card quantity">
                    <h6><i class="fas fa-boxes me-2"></i>Total Items</h6>
                    <div class="value"><?php echo (int)($dispatcher_totals['total_quantity_dispatched'] ?? 0); ?></div>
                </div>
                
                <div class="summary-card expected">
                    <h6><i class="fas fa-tag me-2"></i>Expected Amount</h6>
                    <div class="value">₦<?php echo number_format($dispatcher_totals['total_expected'] ?? 0, 2); ?></div>
                </div>
                
                <div class="summary-card actual">
                    <h6><i class="fas fa-cash-register me-2"></i>Actual Sales</h6>
                    <div class="value">₦<?php echo number_format($dispatcher_totals['total_actual_sales'] ?? 0, 2); ?></div>
                </div>

                <div class="summary-card expected">
                    <h6><i class="fas fa-money-bill-wave me-2"></i>Expenses</h6>
                    <div class="value">₦<?php echo number_format($dispatcher_totals['total_expenses'] ?? 0, 2); ?></div>
                </div>
                
                <div class="summary-card balance">
                    <h6><i class="fas fa-balance-scale me-2"></i>Balance</h6>
                    <div class="value">₦<?php echo number_format($dispatcher_totals['balance'] ?? 0, 2); ?></div>
                </div>
            </div>
            
            <!-- Trip Details -->
            <div>
                <h5 class="mb-3">
                    <i class="fas fa-truck me-2"></i>Trip Details for <?php echo htmlspecialchars($dispatcher_filter); ?>
                    <span class="badge bg-primary"><?php echo count($dispatch_trips); ?> trips</span>
                </h5>
                
                <?php if (empty($dispatch_trips)): ?>
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-info-circle me-2"></i>No trips recorded for this dispatcher on <?php echo htmlspecialchars($selected_date); ?>.
                    </div>
                <?php else: ?>
                    <?php foreach ($dispatch_trips as $trip): ?>
                        <div class="trip-card">
                            <div class="trip-header">
                                <div>
                                    <div class="trip-number">
                                        <i class="fas fa-truck me-2"></i><?php echo htmlspecialchars($trip['dispatch_number']); ?>
                                    </div>
                                    <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($trip['created_at'])); ?></small>
                                </div>
                                <div>
                                    <span class="status-badge status-<?php echo strtolower($trip['status']); ?>">
                                        <?php echo ucfirst($trip['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="trip-body">
                                <!-- Trip Info -->
                                <div class="trip-info">
                                    <div class="info-item">
                                        <div class="info-label">Destination</div>
                                        <div class="info-value"><?php echo htmlspecialchars(explode("\n", $trip['shipping_address'])[0]); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Type</div>
                                        <div class="info-value"><?php echo ucfirst($trip['dispatch_type']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Tracking</div>
                                        <div class="info-value"><?php echo !empty($trip['tracking_number']) ? htmlspecialchars($trip['tracking_number']) : '-'; ?></div>
                                    </div>
                                </div>
                                
                                <!-- Trip Items -->
                                <?php if (!empty($trip['items'])): ?>
                                    <div class="trip-items">
                                        <h6>Items in This Trip</h6>
                                        <table class="item-table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th class="text-end">Qty</th>
                                                    <th class="text-end">Unit Price</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($trip['items'] as $item): 
                                                    // Use full unit price (without discount) - discounts are tracked separately for account balancing
                                                    $subtotal = $item['quantity'] * $item['unit_price'];
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                        <td class="text-end"><?php echo (int)$item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                                                        <td class="text-end">₦<?php echo number_format($item['unit_price'], 2); ?></td>
                                                        <td class="text-end"><?php echo $item['discount_amount'] > 0 ? '₦' . number_format($item['discount_amount'], 2) : '-'; ?></td>
                                                        <td class="text-end fw-bold">₦<?php echo number_format($subtotal, 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Trip Totals -->
                                <div class="trip-totals">
                                    <div class="trip-total-item">
                                        <div class="trip-total-label">Expected</div>
                                        <div class="trip-total-value">₦<?php echo number_format($trip['expected_amount'], 2); ?></div>
                                    </div>
                                    <div class="trip-total-item">
                                        <div class="trip-total-label">Actual</div>
                                        <div class="trip-total-value">₦<?php echo number_format($trip['actual_sales_amount'] ?? 0, 2); ?></div>
                                    </div>
                                    <div class="trip-total-item">
                                        <div class="trip-total-label">Items</div>
                                        <div class="trip-total-value"><?php echo (int)$trip['total_quantity']; ?></div>
                                    </div>
                                </div>
                                
                                <!-- Trip Actions -->
                                <div class="mt-2">
                                    <a href="dispatch_detail.php?id=<?php echo $trip['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye me-1"></i>View Details
                                    </a>
                                    <?php if (($trip['status'] === 'pending' || $trip['status'] === 'processing') && hasRole(['admin', 'manager'])): ?>
                                        <a href="edit_dispatch.php?id=<?php echo $trip['id']; ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php elseif (empty($dispatcher_filter)): ?>
            <div class="alert alert-info" role="alert">
                <i class="fas fa-info-circle me-2"></i>Select a dispatcher to view their trip summary.
            </div>
        <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


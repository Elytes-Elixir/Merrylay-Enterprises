<?php
/**
 * Daily Dispatch View Page for Merrylay Enterprises
 * 
 * Shows all dispatches for a specific date with complete details:
 * - Dispatch information (dispatch number, dispatcher, date)
 * - Products taken from depot for each dispatch
 * - Expected sales amounts
 * - Actual sales recorded
 * - Returns to depot
 * - Dispatch status
 * 
 * Terminology:
 * - Dispatch: Each dispatch made by a driver to sell products from depot to customers
 * - Dispatcher: Driver who also serves as salesperson
 * - Dispatch Items: Products taken from depot for the dispatch
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
$dispatcher_filter = $_GET['dispatcher'] ?? '';

// Pagination parameters
$page = intval($_GET['page'] ?? 1);
$per_page = 20; // Show 20 dispatches per page
$offset = ($page - 1) * $per_page;

// Get unique dispatchers for filter
$dispatchers = cache_remember("dispatchers_list", function() {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches WHERE COALESCE(status, '') <> 'cancelled' ORDER BY dispatcher_name");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}, 3600); // Cache for 1 hour

// Get total count for pagination
$total_trips = cache_remember("daily_trips_count_{$selected_date}_{$dispatcher_filter}", function() use ($selected_date, $dispatcher_filter) {
    $count_query = "
        SELECT COUNT(DISTINCT d.id) as count
        FROM dispatches d
        WHERE DATE(d.dispatch_date) = ?
                    AND COALESCE(d.status, '') <> 'cancelled'
    ";
    $count_params = [$selected_date];

    if (!empty($dispatcher_filter)) {
        $count_query .= " AND d.dispatcher_name = ?";
        $count_params[] = $dispatcher_filter;
    }
    
    $stmt = executeQuery($count_query, $count_params);
    return $stmt->fetch()['count'];
}, 300); // Cache for 5 minutes
$total_pages = ceil($total_trips / $per_page);

// Get paginated trips for the selected date with complete details
$trips = cache_remember("daily_trips_{$selected_date}_{$dispatcher_filter}_page_{$page}", function() use ($selected_date, $dispatcher_filter, $per_page, $offset) {
    $query = "
        SELECT 
            d.id,
            d.dispatch_number,
            d.dispatcher_name,
            d.dispatch_date,
            d.expected_amount,
            d.actual_sales_amount,
            d.notes,
            d.created_at,
            COUNT(DISTINCT di.id) as product_count,
            SUM(di.quantity) as total_quantity_loaded,
            COALESCE(SUM(dr.quantity_returned), 0) as total_quantity_returned
        FROM dispatches d
        LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
        LEFT JOIN dispatch_returns dr ON d.id = dr.dispatch_id
        WHERE DATE(d.dispatch_date) = ?
                    AND COALESCE(d.status, '') <> 'cancelled'
    ";
    $params = [$selected_date];

    if (!empty($dispatcher_filter)) {
        $query .= " AND d.dispatcher_name = ?";
        $params[] = $dispatcher_filter;
    }

    $query .= " GROUP BY d.id ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = executeQuery($query, $params);
    $trips = $stmt->fetchAll();
    
    // Get detailed items for each trip (limit to avoid too much data)
    foreach ($trips as &$trip) {
        $stmt = executeQuery("SELECT 
            di.*, 
            p.name as product_name, 
            p.unit,
            COALESCE(SUM(dr.quantity_returned), 0) as quantity_returned
        FROM dispatch_items di 
        LEFT JOIN products p ON di.product_id = p.id 
        LEFT JOIN dispatch_returns dr ON di.dispatch_id = dr.dispatch_id AND di.product_id = dr.product_id
        WHERE di.dispatch_id = ? 
        GROUP BY di.id
        ORDER BY p.name", [$trip['id']]);
        $trip['items'] = $stmt->fetchAll();
        
        // Get returns summary
        $stmt = executeQuery("SELECT 
            dr.*,
            p.name as product_name
        FROM dispatch_returns dr
        JOIN products p ON dr.product_id = p.id
        WHERE dr.dispatch_id = ?", [$trip['id']]);
        $trip['returns'] = $stmt->fetchAll();
    }
    
    return $trips;
}, 120); // Cache for 2 minutes

// Calculate daily summary (for all trips, not just paginated)
$daily_summary = cache_remember("daily_summary_{$selected_date}_{$dispatcher_filter}", function() use ($selected_date, $dispatcher_filter, $total_trips) {
    $summary_query = "
        SELECT 
            SUM(di.quantity) as total_products_loaded,
            COALESCE(SUM(dr.quantity_returned), 0) as total_products_returned,
            SUM(d.expected_amount) as total_expected,
            SUM(d.actual_sales_amount) as total_actual
        FROM dispatches d
        LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
        LEFT JOIN dispatch_returns dr ON d.id = dr.dispatch_id
        WHERE DATE(d.dispatch_date) = ?
                    AND COALESCE(d.status, '') <> 'cancelled'
    ";
    $summary_params = [$selected_date];

    if (!empty($dispatcher_filter)) {
        $summary_query .= " AND d.dispatcher_name = ?";
        $summary_params[] = $dispatcher_filter;
    }

    $stmt = executeQuery($summary_query, $summary_params);
    $summary_data = $stmt->fetch();
    
    return [
        'total_trips' => $total_trips,
        'total_products_loaded' => $summary_data['total_products_loaded'] ?? 0,
        'total_products_returned' => $summary_data['total_products_returned'] ?? 0,
        'total_expected' => $summary_data['total_expected'] ?? 0,
        'total_actual' => $summary_data['total_actual'] ?? 0
    ];
}, 300); // Cache for 5 minutes
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Dispatches - Merrylay Enterprises</title>
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
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #667eea;
        }
        
        .summary-card h6 {
            color: #6b7280;
            font-weight: 600;
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
        
        .trip-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .trip-header {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .trip-number {
            font-weight: 700;
            color: #667eea;
            font-size: 1.1rem;
        }
        
        .trip-body {
            padding: 1.5rem;
        }
        
        .trip-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
        }
        
        .info-item label {
            display: block;
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
        
        .info-item value {
            font-weight: 600;
            color: #1f2937;
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
        
        .status-completed {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-returned {
            background-color: #e0e7ff;
            color: #3730a3;
        }
        
        .products-table {
            width: 100%;
            margin-top: 1rem;
        }
        
        .products-table th {
            background: #f3f4f6;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        .products-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
        }
        
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
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
                    <h1><i class="fas fa-calendar-day me-2"></i>Daily Dispatch View</h1>
                    <p class="mb-0">View all dispatches for <?php echo date('F j, Y', strtotime($selected_date)); ?> with complete details</p>
                </div>
            </div>
            
            <div class="container-fluid px-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Select Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Dispatcher (Driver)</label>
                            <select name="dispatcher" class="form-select">
                                <option value="">All Dispatchers (Drivers)</option>
                                <?php foreach ($dispatchers as $dispatcher): ?>
                                    <option value="<?php echo htmlspecialchars($dispatcher); ?>" <?php echo $dispatcher_filter === $dispatcher ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dispatcher); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i>View Dispatches
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Daily Summary -->
                <div class="summary-cards">
                    <div class="summary-card">
                        <h6><i class="fas fa-truck me-2"></i>Total Dispatches</h6>
                        <div class="value"><?php echo $daily_summary['total_trips']; ?></div>
                    </div>
                    <div class="summary-card">
                        <h6><i class="fas fa-box me-2"></i>Products Loaded</h6>
                        <div class="value"><?php echo $daily_summary['total_products_loaded']; ?></div>
                    </div>
                    <div class="summary-card">
                        <h6><i class="fas fa-check me-2"></i>Products Sold</h6>
                        <div class="value"><?php echo $daily_summary['total_products_sold']; ?></div>
                    </div>
                    <div class="summary-card">
                        <h6><i class="fas fa-undo me-2"></i>Products Returned</h6>
                        <div class="value"><?php echo $daily_summary['total_products_returned']; ?></div>
                    </div>
                    <div class="summary-card">
                        <h6><i class="fas fa-tag me-2"></i>Expected Amount</h6>
                        <div class="value">₦<?php echo number_format($daily_summary['total_expected'], 2); ?></div>
                    </div>
                    <div class="summary-card">
                        <h6><i class="fas fa-cash-register me-2"></i>Actual Sales</h6>
                        <div class="value">₦<?php echo number_format($daily_summary['total_actual'], 2); ?></div>
                    </div>
                </div>
                
                <!-- Trips List -->
                <?php if (empty($trips)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times fa-3x mb-3"></i>
                        <h5>No Dispatches Found</h5>
                        <p>No dispatches recorded for <?php echo date('F j, Y', strtotime($selected_date)); ?>.</p>
                    </div>
                <?php else: ?>
                    <h5 class="mb-3"><i class="fas fa-list me-2"></i>Dispatch Details</h5>
                    
                    <?php foreach ($trips as $trip): ?>
                        <div class="trip-card">
                            <div class="trip-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="trip-number"><?php echo htmlspecialchars($trip['dispatch_number']); ?></span>
                                        <span class="ms-3 text-muted">
                                            <i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars($trip['dispatcher_name']); ?>
                                        </span>
                                    </div>
                                    <div class="trip-actions">
                                        <a href="trips/edit.php?id=<?php echo $trip['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="trips/inventory-receipt.php?id=<?php echo $trip['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                            <i class="fas fa-receipt"></i> Receipt
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="trip-body">
                                <!-- Trip Info Grid -->
                                <div class="trip-info-grid">
                                    <div class="info-item">
                                        <label>Trip Date</label>
                                        <value><?php echo date('M j, Y', strtotime($trip['dispatch_date'])); ?></value>
                                    </div>
                                    <div class="info-item">
                                        <label>Products Taken</label>
                                        <value><?php echo $trip['product_count']; ?> items (<?php echo $trip['total_quantity_loaded']; ?> units)</value>
                                    </div>
                                    <div class="info-item">
                                        <label>Expected Amount</label>
                                        <value>₦<?php echo number_format($trip['expected_amount'], 2); ?></value>
                                    </div>
                                    <div class="info-item">
                                        <label>Actual Sales</label>
                                        <value>₦<?php echo number_format($trip['actual_sales_amount'] ?? 0, 2); ?></value>
                                    </div>
                                    <div class="info-item">
                                        <label>Products Sold</label>
                                        <value><?php echo $trip['total_quantity_sold'] ?? 0; ?> units</value>
                                    </div>
                                    <div class="info-item">
                                        <label>Products Returned</label>
                                        <value><?php echo $trip['total_quantity_returned'] ?? 0; ?> units</value>
                                    </div>
                                    <?php if ($trip['tracking_number']): ?>
                                    <div class="info-item">
                                        <label>Tracking Number</label>
                                        <value><?php echo htmlspecialchars($trip['tracking_number']); ?></value>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($trip['shipping_address']): ?>
                                    <div class="info-item">
                                        <label>Destination</label>
                                        <value><?php echo htmlspecialchars($trip['shipping_address']); ?></value>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Products Table -->
                                <h6 class="mt-3 mb-2"><i class="fas fa-boxes me-2"></i>Products for This Trip</h6>
                                <div class="table-responsive">
                                    <table class="products-table">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Unit</th>
                                                <th>Loaded</th>
                                                <th>Sold</th>
                                                <th>Returned</th>
                                                <th>Unit Price</th>
                                                <th>Discount</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trip['items'] as $item): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                                    <td><?php echo $item['quantity']; ?></td>
                                                    <td><?php echo $item['quantity_sold'] ?? 0; ?></td>
                                                    <td><?php echo $item['quantity_returned'] ?? 0; ?></td>
                                                    <td>₦<?php echo number_format($item['unit_price'], 2); ?></td>
                                                    <td style="color: #ef4444;">-₦<?php echo number_format($item['discount_amount'] ?? 0, 2); ?></td>
                                                    <td>₦<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (!empty($trip['returns'])): ?>
                                    <h6 class="mt-3 mb-2"><i class="fas fa-undo me-2"></i>Returns from This Trip</h6>
                                    <div class="table-responsive">
                                        <table class="products-table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Quantity Returned</th>
                                                    <th>Reason</th>
                                                    <th>Condition</th>
                                                    <th>Return Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($trip['returns'] as $return): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($return['product_name']); ?></td>
                                                        <td><?php echo $return['quantity_returned']; ?></td>
                                                        <td><?php echo htmlspecialchars($return['return_reason']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $return['condition'] === 'good' ? 'success' : 'warning'; ?>">
                                                                <?php echo ucfirst($return['condition']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y', strtotime($return['return_date'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($trip['notes']): ?>
                                    <div class="mt-3 p-2 bg-light rounded">
                                        <small class="text-muted"><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($trip['notes']); ?></small>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Action Buttons -->
                                <div class="mt-3">
                                    <a href="daily/index.php?date=<?php echo $selected_date; ?>&dispatcher=<?php echo urlencode($trip['dispatcher_name']); ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-calendar-day me-1"></i>Daily Sales
                                    </a>
                                    <?php if (hasRole(['admin', 'manager'])): ?>
                                    <a href="dispatcher-summary.php?date=<?php echo $selected_date; ?>&dispatcher=<?php echo urlencode($trip['dispatcher_name']); ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-chart-line me-1"></i>View Summary
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="d-flex justify-content-center mt-4">
                            <nav aria-label="Trip pagination">
                                <ul class="pagination">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?date=<?php echo urlencode($selected_date); ?>&dispatcher=<?php echo urlencode($dispatcher_filter); ?>&page=<?php echo $page - 1; ?>">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?date=<?php echo urlencode($selected_date); ?>&dispatcher=<?php echo urlencode($dispatcher_filter); ?>&page=1">1</a>
                                        </li>
                                        <?php if ($start_page > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?date=<?php echo urlencode($selected_date); ?>&dispatcher=<?php echo urlencode($dispatcher_filter); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?date=<?php echo urlencode($selected_date); ?>&dispatcher=<?php echo urlencode($dispatcher_filter); ?>&page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?date=<?php echo urlencode($selected_date); ?>&dispatcher=<?php echo urlencode($dispatcher_filter); ?>&page=<?php echo $page + 1; ?>">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <div class="text-center mt-2 text-muted">
                            Showing <?php echo ($offset + 1) . ' - ' . min($offset + $per_page, $total_trips); ?> of <?php echo $total_trips; ?> trips
                        </div>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>


<?php
/**
 * Dispatch Records Page for Merrylay Enterprises
 * 
 * Displays and manages dispatch records
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

// Initialize variables
$search = '';
$status_filter = '';
$date_from = '';
$date_to = '';

// Handle search and filters
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT d.*, u.name as created_by_name 
          FROM dispatches d 
          LEFT JOIN users u ON d.dispatcher_id = u.id 
          WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (d.destination LIKE ? OR d.recipient LIKE ? OR d.notes LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $query .= " AND d.status = ?";
    $params[] = $status_filter;
} else {
    $query .= " AND COALESCE(d.status, '') <> 'cancelled'";
}

if (!empty($date_from)) {
    $query .= " AND DATE(d.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(d.created_at) <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY d.created_at DESC";

try {
    $stmt = executeQuery($query, $params);
    $dispatches = $stmt->fetchAll();
    
    // Get dispatch items for each dispatch
    foreach ($dispatches as &$dispatch) {
        $stmt = executeQuery("
            SELECT di.*, p.name as product_name, p.unit 
            FROM dispatch_items di 
            LEFT JOIN products p ON di.product_id = p.id 
            WHERE di.dispatch_id = ?
        ", [$dispatch['id']]);
        $dispatch['items'] = $stmt->fetchAll();
    }
    
} catch (PDOException $e) {
    error_log("View dispatch records error: " . $e->getMessage());
    $error_message = "Unable to load dispatch records.";
    $dispatches = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Records - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .dispatch-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s;
        }
        .dispatch-card:hover {
            transform: translateY(-3px);
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 20px;
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-processing {
            background-color: #cce5ff;
            color: #004085;
        }
        .status-shipped {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .status-delivered {
            background-color: #d1f2eb;
            color: #0e5f3e;
        }
        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-returned {
            background-color: #f5c6cb;
            color: #721c24;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        .dispatch-items {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dispatch Records</h1>
                </div>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Search and Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="search" class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="search" name="search" 
                                               placeholder="Destination, recipient..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                        <button class="btn btn-outline-secondary" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="">All Status</option>
                                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                        <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                        <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        <option value="returned" <?php echo $status_filter === 'returned' ? 'selected' : ''; ?>>Returned</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label for="date_from" class="form-label">From Date</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" 
                                           value="<?php echo htmlspecialchars($date_from); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label for="date_to" class="form-label">To Date</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" 
                                           value="<?php echo htmlspecialchars($date_to); ?>">
                                </div>
                                
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Dispatch Records -->
                <?php if (empty($dispatches)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No dispatch records found</h5>
                        <p class="text-muted">No dispatches match your search criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($dispatches as $dispatch): ?>
                            <?php
                            $status_class = match($dispatch['status']) {
                                'pending' => 'status-pending',
                                'processing' => 'status-processing',
                                'shipped' => 'status-shipped',
                                'delivered' => 'status-delivered',
                                'cancelled' => 'status-cancelled',
                                'returned' => 'status-returned',
                                default => 'status-pending'
                            };
                            $status_icon = match($dispatch['status']) {
                                'pending' => 'fas fa-clock',
                                'processing' => 'fas fa-cog',
                                'shipped' => 'fas fa-truck',
                                'delivered' => 'fas fa-check-circle',
                                'cancelled' => 'fas fa-times-circle',
                                'returned' => 'fas fa-undo',
                                default => 'fas fa-clock'
                            };
                            ?>
                            
                            <div class="col-lg-6 mb-4">
                                <div class="card dispatch-card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h6 class="card-title mb-1">
                                                    <?php echo htmlspecialchars($dispatch['dispatch_number']); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    Created: <?php echo date('M j, Y h:i A', strtotime($dispatch['created_at'])); ?>
                                                </small>
                                            </div>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <i class="<?php echo $status_icon; ?> me-1"></i>
                                                <?php echo ucfirst($dispatch['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Type</small>
                                                <strong><?php echo ucfirst($dispatch['dispatch_type']); ?></strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Created By</small>
                                                <strong><?php echo htmlspecialchars($dispatch['created_by_name']); ?></strong>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($dispatch['tracking_number'])): ?>
                                            <div class="row mb-3">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Tracking Number</small>
                                                    <strong><?php echo htmlspecialchars($dispatch['tracking_number']); ?></strong>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Carrier</small>
                                                    <strong><?php echo htmlspecialchars($dispatch['carrier'] ?: 'N/A'); ?></strong>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($dispatch['estimated_delivery'])): ?>
                                            <div class="row mb-3">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Est. Delivery</small>
                                                    <strong><?php echo date('M j, Y', strtotime($dispatch['estimated_delivery'])); ?></strong>
                                                </div>
                                                <?php if (!empty($dispatch['actual_delivery'])): ?>
                                                    <div class="col-6">
                                                        <small class="text-muted d-block">Actual Delivery</small>
                                                        <strong><?php echo date('M j, Y H:i', strtotime($dispatch['actual_delivery'])); ?></strong>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Shipping Address</small>
                                                <strong><?php echo htmlspecialchars($dispatch['shipping_address']); ?></strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Total Items</small>
                                                <strong><?php echo count($dispatch['items']); ?> items</strong>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($dispatch['notes'])): ?>
                                            <div class="mb-3">
                                                <small class="text-muted d-block">Notes</small>
                                                <p class="small mb-0"><?php echo htmlspecialchars($dispatch['notes']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="dispatch-items">
                                            <small class="text-muted d-block mb-2">Items:</small>
                                            <?php foreach ($dispatch['items'] as $item): ?>
                                                <div class="d-flex justify-content-between align-items-center py-1">
                                                    <div>
                                                        <span class="small"><?php echo htmlspecialchars($item['product_name']); ?></span>
                                                        <?php if (!empty($item['batch_number'])): ?>
                                                            <br><small class="text-muted">Batch: <?php echo htmlspecialchars($item['batch_number']); ?></small>
                                                        <?php endif; ?>
                                                        <?php if (!empty($item['expiry_date'])): ?>
                                                            <br><small class="text-muted">Expiry: <?php echo date('M j, Y', strtotime($item['expiry_date'])); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="small fw-bold"><?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <div class="mt-3 d-flex gap-2 flex-wrap">
                                            <button class="btn btn-sm btn-outline-primary" onclick="viewDispatch(<?php echo $dispatch['id']; ?>)">
                                                <i class="fas fa-eye me-1"></i>View Details
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewDispatch(dispatchId) {
            console.log('View dispatch:', dispatchId);
            alert('View details functionality would be implemented here');
        }

</body>
</html>


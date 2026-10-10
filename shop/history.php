<?php
/**
 * Shop Sales History
 * 
 * View and manage shop sales history for shop attendants
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/middleware.php';
require_once '../config/stock_manager.php';

// Restrict access to shop attendants and managers (for viewing)
requireShopAttendantOrManager();

$error = '';
$success = '';

// Handle delete sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale'])) {
    http_response_code(403);
    $error = 'Sale deletion is disabled. No sale, payment, dispatch, or stock records were changed. A future reversal requires a reliable sale-to-dispatch relationship.';
    error_log('Rejected legacy shop sale deletion POST for user ' . (int) ($_SESSION['user_id'] ?? 0));
}

// Get filter parameters
$date_filter = $_GET['date'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Allow showing all dates by passing ?date= (empty)
if (isset($_GET['date']) && $_GET['date'] === '') {
    $date_filter = '';
}

// Get shop sales for current shop attendant only (or all for admins)
$sales = [];
$grouped_sales = [];
try {
    // Determine if we should filter by user (shop attendants only see their own sales)
    // Admins see all sales
    $is_admin = hasRole('admin');

    // First, get all shop sales records (not using LEFT JOIN to avoid grouping issues)
    $query = "
        SELECT id, sale_number, sale_date, customer_name, total_amount, payment_method,
               payment_split, recorded_by, recorded_at, notes
        FROM shop_sales
        WHERE 1=1
    ";

    $params = [];

    // Filter by user if not admin
    if (!$is_admin) {
        $query .= " AND recorded_by = ?";
        $params[] = $_SESSION['user_id'];
    }

    if ($date_filter) {
        $query .= " AND DATE(sale_date) = ?";
        $params[] = $date_filter;
    }

    if ($search) {
        $query .= " AND (sale_number LIKE ? OR customer_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $query .= " ORDER BY recorded_at DESC";

    $stmt = executeQuery($query, $params);
    $sales_records = $stmt->fetchAll();

    // Now get items for each sale
    if (!empty($sales_records)) {
        foreach ($sales_records as $sale_record) {
            $receipt_number = $sale_record['sale_number'];

            if (!isset($grouped_sales[$receipt_number])) {
                $grouped_sales[$receipt_number] = [
                    'id' => $sale_record['id'],
                    'receipt_number' => $receipt_number,
                    'sale_date' => $sale_record['sale_date'],
                    'total_amount' => $sale_record['total_amount'],
                    'customer_name' => $sale_record['customer_name'] ?? 'Walk-in',
                    'recorded_at' => $sale_record['recorded_at'],
                    'items' => []
                ];
            }

            // Get items for this sale
            $items_query = "
                SELECT ssi.product_id, p.name as product_name, ssi.quantity, ssi.unit_price, ssi.unit_type,
                       (ssi.quantity * ssi.unit_price) as subtotal
                FROM shop_sales_items ssi
                LEFT JOIN products p ON ssi.product_id = p.id
                WHERE ssi.shop_sale_id = ?
                ORDER BY ssi.id
            ";

            $items_stmt = executeQuery($items_query, [$sale_record['id']]);
            $items = $items_stmt->fetchAll();

            if (!empty($items)) {
                $grouped_sales[$receipt_number]['items'] = $items;
            }
        }
    }

    // If no results from new system, try old system (dispatches)
    if (empty($grouped_sales)) {
        $query = "
            SELECT id, dispatch_number, dispatch_date, notes, actual_sales_amount, user_id, created_at
            FROM dispatches
            WHERE sales_channel = 'shop_sales'
        ";

        $params = [];

        // Filter by user if not admin
        if (!$is_admin) {
            $query .= " AND user_id = ?";
            $params[] = $_SESSION['user_id'];
        }

        if ($date_filter) {
            $query .= " AND DATE(dispatch_date) = ?";
            $params[] = $date_filter;
        }

        if ($search) {
            $query .= " AND (dispatch_number LIKE ? OR notes LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $query .= " ORDER BY created_at DESC";

        $stmt = executeQuery($query, $params);
        $dispatch_records = $stmt->fetchAll();

        // Process dispatch records similar to above
        if (!empty($dispatch_records)) {
            foreach ($dispatch_records as $dispatch) {
                $receipt_number = $dispatch['dispatch_number'];

                if (!isset($grouped_sales[$receipt_number])) {
                    $grouped_sales[$receipt_number] = [
                        'id' => $dispatch['id'],
                        'receipt_number' => $receipt_number,
                        'sale_date' => $dispatch['dispatch_date'],
                        'total_amount' => $dispatch['actual_sales_amount'],
                        'customer_name' => preg_replace('/^Customer: /', '', $dispatch['notes'] ?? 'Walk-in'),
                        'recorded_at' => $dispatch['created_at'],
                        'items' => []
                    ];
                }

                // Get items from dispatch_items
                $items_query = "
                    SELECT di.product_id, p.name as product_name, di.quantity, di.unit_price, NULL as unit_type,
                           (di.quantity * di.unit_price) as subtotal
                    FROM dispatch_items di
                    LEFT JOIN products p ON di.product_id = p.id
                    WHERE di.dispatch_id = ?
                    ORDER BY di.id
                ";

                $items_stmt = executeQuery($items_query, [$dispatch['id']]);
                $items = $items_stmt->fetchAll();

                if (!empty($items)) {
                    $grouped_sales[$receipt_number]['items'] = $items;
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log("Failed to load shop sales: " . $e->getMessage());
    $error = 'Failed to load sales history';
}


?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History - Merrylay Enterprises</title>
    
    <!-- CSS Framework -->
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        .sale-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
        }
        
        .sale-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--border-dark);
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
            
            <nav class="sidebar-nav">
                <!-- Main Operations Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Main Operations</div>
                    <a href="index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span>
                        <span class="nav-text">Shop Sales</span>
                    </a>
                    <a href="history.php" class="nav-link active">
                        <span class="nav-icon"><i class="fas fa-history"></i></span>
                        <span class="nav-text">Sales History</span>
                    </a>
                </div>

                <!-- Shop Operations Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Shop Operations</div>
                    <a href="collections.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-text">Expenses</span>
                    </a>
                    <a href="collection-history.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-list-alt"></i></span>
                        <span class="nav-text">Expense History</span>
                    </a>
                    <a href="damaged.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
                        <span class="nav-text">Damaged Products</span>
                    </a>
                    <a href="daily-accounting.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                        <span class="nav-text">Daily Accounting</span>
                    </a>
                </div>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="../stock/supplies.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-truck-loading"></i></span>
                        <span class="nav-text">Supplies</span>
                    </a>
                    <a href="../finance/daily_balance.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                        <span class="nav-text">Daily Balance</span>
                    </a>
                    <a href="../reports/daily.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                        <span class="nav-text">Reports</span>
                    </a>
                </div>
                <?php endif; ?>

                <!-- System Section -->
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <a href="../auth/profile.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-user"></i></span>
                        <span class="nav-text">Profile</span>
                    </a>
                    <a href="../auth/logout.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="nav-text">Logout</span>
                    </a>
                </div>
            </nav>
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
                        <input type="text" placeholder="Search sales..." id="globalSearch">
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
                                    <div class="user-role">Shop Attendant</div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Alert Messages -->
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-history" style="margin-right: 15px;"></i>
                            Sales History
                        </h1>
                        <p class="page-subtitle">
                            View and manage shop sales records
                        </p>
                    </div>
                </div>

                <!-- Filters -->
                <div class="content-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
                                <small class="text-muted d-block mt-1">Defaults to today. Leave blank to see all dates.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" name="search" id="search" class="form-control" placeholder="Search by receipt number or customer..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="history.php" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Sales List -->
                <?php if (count($grouped_sales) > 0): ?>
                    <?php foreach ($grouped_sales as $sale): ?>
                        <div class="sale-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 style="color: var(--primary-800); font-weight: 600;">
                                        <?php echo htmlspecialchars($sale['receipt_number']); ?>
                                    </h5>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y H:i', strtotime($sale['recorded_at'])); ?>
                                    </small>
                                    <?php if (!empty($sale['customer_name']) && $sale['customer_name'] !== 'Walk-in'): ?>
                                        <div class="mt-1">
                                            <small class="text-muted">
                                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($sale['customer_name']); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <h4 style="color: var(--green); font-weight: 700;">
                                        ₦<?php echo number_format($sale['total_amount'], 2); ?>
                                    </h4>
                                    <div class="mt-2">
                                        <a href="receipt.php?id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-receipt"></i> View Receipt
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <span class="small text-muted">Deletion disabled pending a reliable sale-to-dispatch relationship.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (count($sale['items']) > 0): ?>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end">Price</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sale['items'] as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                <?php $unitLabel = ['pieces' => 'Pieces', 'crate' => 'Crate'][$item['unit_type'] ?? ''] ?? 'Units'; ?>
                                                <td class="text-center">
                                                    <?php echo htmlspecialchars((string) $item['quantity']); ?>
                                                    <?php echo htmlspecialchars($unitLabel); ?>
                                                </td>
                                                <td class="text-end">₦<?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td class="text-end">₦<?php echo number_format($item['subtotal'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <h6>No Sales Found</h6>
                        <p>No sales recorded for the selected criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
    <script>
        // Force sidebar to be expanded for shop pages
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            // Remove collapsed state
            if (sidebar) {
                sidebar.classList.remove('collapsed');
            }
            if (mainContent) {
                mainContent.classList.remove('expanded');
            }
            
            // Clear localStorage preference
            localStorage.setItem('sidebarCollapsed', 'false');
            
        });
    </script>
</body>
</html>

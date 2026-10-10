<?php
/**
 * View Products Page for Merrylay Enterprises
 * 
 * Displays and manages product inventory
 */

// Start session
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration
require_once '../config/db.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';
$canManageProducts = hasRole(['admin', 'manager']);
$deleteProductCsrfToken = SecurityUtils::generateCSRFToken();

// Initialize variables
$search = '';
$category_filter = '';
$stock_filter = '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Handle search and filters
$search = trim($_GET['search'] ?? '');
$category_filter = $_GET['category'] ?? '';
$stock_filter = $_GET['stock'] ?? '';

// Build query - use closing_stock from daily_stock table for current date
$today = date('Y-m-d');
$query = "SELECT p.id, p.name, p.category, COALESCE(ds.closing_stock, p.quantity) as quantity, p.unit, p.cost_price, p.reorder_level, p.is_active 
          FROM products p 
          LEFT JOIN daily_stock ds ON p.id = ds.product_id AND ds.stock_date = ? 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) as count, 
    COALESCE(SUM(CASE WHEN COALESCE(ds.closing_stock, p.quantity) > 0 AND COALESCE(ds.closing_stock, p.quantity) <= p.reorder_level THEN 1 ELSE 0 END), 0) as low_stock_count,
    COALESCE(SUM(CASE WHEN COALESCE(ds.closing_stock, p.quantity) = 0 THEN 1 ELSE 0 END), 0) as out_of_stock_count,
    COALESCE(SUM(COALESCE(ds.closing_stock, p.quantity) * p.cost_price), 0) as inventory_cost,
    COALESCE(SUM(COALESCE(ds.closing_stock, p.quantity) * p.cost_price), 0) as inventory_value,
    COALESCE(SUM(CASE WHEN COALESCE(ds.closing_stock, p.quantity) > 0 THEN 1 ELSE 0 END), 0) as active_products
FROM products p 
LEFT JOIN daily_stock ds ON p.id = ds.product_id AND ds.stock_date = ? 
WHERE 1=1";
$params = [$today];
$countParams = [$today];

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR description LIKE ?)";
    $countQuery .= " AND (name LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
}

if (!empty($category_filter)) {
    $query .= " AND category = ?";
    $countQuery .= " AND category = ?";
    $params[] = $category_filter;
    $countParams[] = $category_filter;
}

if ($stock_filter === 'low') {
    $query .= " AND quantity > 0 AND quantity <= reorder_level";
    $countQuery .= " AND quantity > 0 AND quantity <= reorder_level";
} elseif ($stock_filter === 'out') {
    $query .= " AND quantity = 0";
    $countQuery .= " AND quantity = 0";
}

$query .= " ORDER BY name ASC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

try {
    $statsStmt = executeQuery($countQuery, $countParams);
    $stats = $statsStmt->fetch();
    $total_products = $stats['count'] ?? 0;
    $low_stock_count = $stats['low_stock_count'] ?? 0;
    $out_of_stock_count = $stats['out_of_stock_count'] ?? 0;
    $inventory_cost = $stats['inventory_cost'] ?? 0;
    $inventory_value = $stats['inventory_value'] ?? 0;
    $active_products = $stats['active_products'] ?? 0;
    $total_pages = max(1, ceil($total_products / $per_page));

    $stmt = executeQuery($query, $params);
    $products = $stmt->fetchAll();
    
    // Get categories for filter
    $stmt = executeQuery("SELECT DISTINCT category FROM products ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    error_log("View products error: " . $e->getMessage());
    $error_message = "Unable to load products.";
    $products = [];
    $categories = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage products inventory">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>View Products - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/fonts/inter/inter.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/unified-design-system.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/stock-pages.css" rel="stylesheet">
    <style>
        .stat-info {
            flex: 1;
        }
        .stat-info .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .stat-info .stat-label {
            font-size: 0.75rem;
            color: var(--primary-600);
            text-transform: uppercase;
        }
        .stat-card {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        /* View toggle styles - fixed */
        .grid-view {
            display: block;
        }
        .table-view {
            display: none;
        }
        .table-view.active {
            display: block !important;
        }
        .grid-view.hidden {
            display: none !important;
        }
    </style>
</head>
<body class="stock-ui stock-ui-products">
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
                <button type="button" class="toggle-btn" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section-title overview">Main Operations</div>
                <a href="../dashboard/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="view.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="add.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-plus"></i></span>
                    <span class="nav-text">Add Product</span>
                </a>
                <a href="../stock/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordDispatches()): ?>
                <div class="nav-section-title dispatch">Dispatch Operations</div>
                <a href="../dispatch/trips/record.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="nav-text">New Dispatch</span>
                </a>
                <a href="../dispatch/trips/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-list"></i></span>
                    <span class="nav-text">Manage Dispatches</span>
                </a>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../dispatch/drivers.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span class="nav-text">Dispatchers</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title sales">Sales & Finance</div>
                <a href="../dispatch/daily/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Daily Sales</span>
                </a>
                <a href="../dispatch/daily/account-summary.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>
                <a href="../finance/daily_balance.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>
                <?php if (isCashier()): ?>
                <a href="../cashier/deposit.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="../admin/end-of-day.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../reports/daily.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <a href="../users/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="../admin/data_management.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <a href="../auth/logout.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search products, sales, dispatches..." id="globalSearch" aria-label="Search products, sales, and dispatches">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($canManageProducts): ?><a href="add.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>Add Product
                        </a>

                        <a href="update_cost_prices.php" class="btn btn-warning">
                            <i class="fas fa-money-bill-wave me-1"></i>Update Cost Prices
                        </a><?php endif; ?>

                        <button type="button" class="icon-btn position-relative" title="Notifications" aria-label="Notifications">
                            <i class="fas fa-bell"></i>
                        </button>

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
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <li><a class="dropdown-item" href="../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="page-header">
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumb stock-breadcrumb">
                            <li class="breadcrumb-item"><a href="../dashboard/index.php">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Current stock</li>
                        </ol>
                    </nav>
                    <h1 class="page-title"><i class="fas fa-boxes me-2" aria-hidden="true"></i>Current Stock</h1>
                    <p class="page-subtitle">Search inventory, review current quantities, and identify products at or below their reorder level.</p>
                </div>
                <!-- Session Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Overview -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon bg-primary-soft">
                            <i class="fas fa-box text-primary"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value text-primary"><?php echo $total_products; ?></div>
                            <div class="stat-label">Total Products</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon bg-warning-soft">
                            <i class="fas fa-exclamation-triangle text-warning"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value text-warning"><?php echo $low_stock_count; ?></div>
                            <div class="stat-label">Low Stock</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon bg-success-soft">
                            <i class="fas fa-chart-line text-success"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value text-success">₦<?php echo number_format($inventory_value, 0); ?></div>
                            <div class="stat-label">Inventory Value</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-bar mb-4">
                    <form method="GET" action="" class="d-flex gap-3 align-items-center">
                        <div class="flex-grow-1">
                            <label class="form-label" for="productsSearch">Search products</label>
                            <input type="text" id="productsSearch" name="search" class="form-control form-control-sm" placeholder="Search products..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="flex-grow-1">
                            <label class="form-label" for="productsCategory">Category</label>
                            <select id="productsCategory" class="form-select form-select-sm" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" 
                                            <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex-grow-1">
                            <label class="form-label" for="productsStock">Stock status</label>
                            <select id="productsStock" class="form-select form-select-sm" name="stock">
                                <option value="">All Stock Levels</option>
                                <option value="low" <?php echo $stock_filter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="out" <?php echo $stock_filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-filter me-1"></i>Apply
                            </button>
                        </div>
                        <div class="view-toggle">
                            <button type="button" class="active" aria-label="Grid view" aria-pressed="true" onclick="toggleView('grid')">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button type="button" aria-label="Table view" aria-pressed="false" onclick="toggleView('table')">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Products Display -->
                <?php if (empty($products)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No products found</h5>
                        <p class="text-muted">Try adjusting your search criteria or add new products.</p>
                        <?php if ($canManageProducts): ?><a href="add.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Add Your First Product
                        </a><?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Grid View -->
                    <div class="grid-view" id="gridView">
                        <div class="product-grid">
                            <?php foreach ($products as $product): ?>
                                <?php
                                // Determine stock status
                                if ($product['quantity'] == 0) {
                                    $stock_class = 'stock-out';
                                    $stock_text = 'Out of Stock';
                                    $stock_icon = 'fas fa-times-circle';
                                } elseif ($product['quantity'] <= $product['reorder_level']) {
                                    $stock_class = 'stock-low';
                                    $stock_text = 'Low Stock';
                                    $stock_icon = 'fas fa-exclamation-triangle';
                                } else {
                                    $stock_class = 'stock-in';
                                    $stock_text = 'In Stock';
                                    $stock_icon = 'fas fa-check-circle';
                                }
                                ?>
                                
                                <div class="product-card">
                                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="product-category"><?php echo htmlspecialchars($product['category']); ?></div>
                                    <div class="product-price">₦<?php echo number_format($product['cost_price'], 2); ?></div>
                                    
                                    <div class="product-detail">
                                        <span class="detail-label">SKU</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="product-detail">
                                        <span class="detail-label">Stock</span>
                                        <span class="detail-value"><?php echo number_format($product['quantity'], 2); ?> <?php echo htmlspecialchars($product['unit']); ?></span>
                                    </div>
                                    <div class="product-detail">
                                        <span class="detail-label">Reorder Level</span>
                                        <span class="detail-value"><?php echo $product['reorder_level']; ?></span>
                                    </div>
                                    <div class="product-detail">
                                        <span class="detail-label">Supplier</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($product['supplier'] ?? 'N/A'); ?></span>
                                    </div>
                                    
                                    <div class="stock-badge <?php echo $stock_class; ?>">
                                        <i class="<?php echo $stock_icon; ?>"></i>
                                        <?php echo $stock_text; ?>
                                    </div>
                                    
                                    <div class="d-flex gap-2 mt-3">
                                        <button type="button" class="btn-action btn-view flex-1" onclick="viewProduct(<?php echo $product['id']; ?>)" aria-label="View <?php echo htmlspecialchars($product['name']); ?>">
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                        <?php if ($canManageProducts): ?><button type="button" class="btn-action btn-edit flex-1" onclick="editProduct(<?php echo $product['id']; ?>)" aria-label="Edit <?php echo htmlspecialchars($product['name']); ?>">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </button><?php endif; ?>
                                        <button type="button" class="btn-action btn-history flex-1" onclick="viewPriceHistory(<?php echo $product['id']; ?>)" aria-label="Price history for <?php echo htmlspecialchars($product['name']); ?>">
                                            <i class="fas fa-history me-1"></i>Price
                                        </button>
                                        <?php if ($canManageProducts): ?><button type="button" class="btn-action btn-delete flex-1" onclick="deleteProduct(<?php echo $product['id']; ?>)" aria-label="Delete <?php echo htmlspecialchars($product['name']); ?>">
                                            <i class="fas fa-trash me-1"></i>Delete
                                        </button><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Table View -->
                    <div class="table-view" id="tableView">
                        <div class="products-table">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Product Name</th>
                                            <th>Category</th>
                                            <th>SKU</th>
                                            <th>Stock</th>
                                            <th>Price</th>
                                            <th>Supplier</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $product): ?>
                                            <?php
                                            // Determine stock status
                                            if ($product['quantity'] == 0) {
                                                $status_class = 'status-danger';
                                                $status_text = 'Out of Stock';
                                            } elseif ($product['quantity'] <= $product['reorder_level']) {
                                                $status_class = 'status-warning';
                                                $status_text = 'Low Stock';
                                            } else {
                                                $status_class = 'status-success';
                                                $status_text = 'In Stock';
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-medium"><?php echo htmlspecialchars($product['name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($product['unit']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($product['category']); ?></td>
                                                <td><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="fw-medium"><?php echo number_format($product['quantity'], 2); ?></span>
                                                </td>
                                                <td><strong>₦<?php echo number_format($product['cost_price'], 2); ?></strong></td>
                                                <td><?php echo htmlspecialchars($product['supplier'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn-action btn-view" onclick="viewProduct(<?php echo $product['id']; ?>)" title="View" aria-label="View <?php echo htmlspecialchars($product['name']); ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <?php if ($canManageProducts): ?><button type="button" class="btn-action btn-edit" onclick="editProduct(<?php echo $product['id']; ?>)" title="Edit" aria-label="Edit <?php echo htmlspecialchars($product['name']); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button><?php endif; ?>
                                                        <button type="button" class="btn-action btn-history" onclick="viewPriceHistory(<?php echo $product['id']; ?>)" title="Price History" aria-label="Price history for <?php echo htmlspecialchars($product['name']); ?>">
                                                            <i class="fas fa-history"></i>
                                                        </button>
                                                        <?php if ($canManageProducts): ?><button type="button" class="btn-action btn-delete" onclick="deleteProduct(<?php echo $product['id']; ?>)" title="Delete" aria-label="Delete <?php echo htmlspecialchars($product['name']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button><?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-muted mt-3">
                        <small>Showing <?php echo count($products); ?> of <?php echo $total_products; ?> product(s)</small>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <?php
                        $baseFilters = array_filter([
                            'search' => $search,
                            'category' => $category_filter,
                            'stock' => $stock_filter,
                        ], fn($value) => $value !== '');
                        $queryString = http_build_query($baseFilters);
                        $prefix = 'view.php' . ($queryString ? '?' . $queryString . '&' : '?');
                        ?>
                        <nav aria-label="Product pagination" class="mt-4">
                            <ul class="pagination justify-content-center flex-wrap">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $prefix; ?>page=<?php echo max(1, $page - 1); ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo $prefix; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $prefix; ?>page=<?php echo min($total_pages, $page + 1); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/offline-handler.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/app.js"></script>
    
    <script>
        // Sidebar toggle functionality
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });

        function toggleView(viewType) {
            const gridView = document.getElementById('gridView');
            const tableView = document.getElementById('tableView');
            const toggleButtons = document.querySelectorAll('.view-toggle button');
            
            // Remove active class from all buttons
            toggleButtons.forEach(btn => btn.classList.remove('active'));
            
            if (viewType === 'grid') {
                // Show grid view
                gridView.classList.remove('hidden');
                gridView.style.display = 'block';
                tableView.classList.remove('active');
                tableView.style.display = 'none';
                toggleButtons[0].classList.add('active');
                // Save preference
                localStorage.setItem('productViewPreference', 'grid');
            } else {
                // Show table view
                gridView.classList.add('hidden');
                gridView.style.display = 'none';
                tableView.classList.add('active');
                tableView.style.display = 'block';
                toggleButtons[1].classList.add('active');
                // Save preference
                localStorage.setItem('productViewPreference', 'table');
            }
        }
        
        // Restore view preference on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Wait a bit for everything to load
            setTimeout(function() {
                const savedView = localStorage.getItem('productViewPreference');
                if (savedView === 'table') {
                    console.log('Restoring table view preference');
                    toggleView('table');
                }
            }, 100);
        });

        function viewProduct(productId) {
            alert('View product details for ID: ' + productId);
        }

        function editProduct(productId) {
            window.location.href = 'edit.php?id=' + productId;
        }

        function viewPriceHistory(productId) {
            window.location.href = 'price_history.php?id=' + productId;
        }

        function deleteProduct(productId) {
            if (confirm('Are you sure you want to delete this product? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete.php';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = <?php echo json_encode($deleteProductCsrfToken); ?>;
                form.appendChild(csrfInput);
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'product_id';
                input.value = productId;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>


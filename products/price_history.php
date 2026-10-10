<?php
/**
 * Price History Page for Merrylay Enterprises
 * 
 * Displays the price change history for a specific product
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

// Initialize variables
$product = null;
$price_history = [];
$error = '';
$success = '';

// Get product ID from URL
$product_id = $_GET['id'] ?? '';

if (empty($product_id)) {
    $_SESSION['error'] = 'Product ID is required.';
    header('Location: view.php');
    exit();
}

// Check if price history table exists
$tableCheck = executeQuery("SHOW TABLES LIKE 'price_history'");
$priceHistoryExists = $tableCheck->rowCount() > 0;

try {
    // Fetch product data
    $stmt = executeQuery("SELECT id, name, selling_price FROM products WHERE id = ?", [$product_id]);
    $product = $stmt->fetch();
    
    if (!$product) {
        $_SESSION['error'] = 'Product not found.';
        header('Location: view.php');
        exit();
    }
    
    // Fetch price history if table exists
    if ($priceHistoryExists) {
        $stmt = executeQuery("
            SELECT 
                ph.id,
                ph.price_type,
                ph.old_price,
                ph.new_price,
                ph.price_difference,
                ph.percentage_change,
                ph.change_reason,
                ph.change_date,
                u.name as changed_by_name,
                u.username as changed_by_username
            FROM price_history ph
            JOIN users u ON ph.changed_by = u.id
            WHERE ph.product_id = ?
            ORDER BY ph.change_date DESC
        ", [$product_id]);
        $price_history = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log("Price history error: " . $e->getMessage());
    $error = 'Unable to load price history data.';
}

// Handle filter form submission
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';

if (!empty($filter_date_from) || !empty($filter_date_to) || $filter_type !== 'all') {
    try {
        $query = "
            SELECT 
                ph.id,
                ph.price_type,
                ph.old_price,
                ph.new_price,
                ph.price_difference,
                ph.percentage_change,
                ph.change_reason,
                ph.change_date,
                u.name as changed_by_name,
                u.username as changed_by_username
            FROM price_history ph
            JOIN users u ON ph.changed_by = u.id
            WHERE ph.product_id = ?
        ";
        $params = [$product_id];
        
        if ($filter_type !== 'all') {
            $query .= " AND ph.price_type = ?";
            $params[] = $filter_type;
        }
        
        if (!empty($filter_date_from)) {
            $query .= " AND DATE(ph.change_date) >= ?";
            $params[] = $filter_date_from;
        }
        
        if (!empty($filter_date_to)) {
            $query .= " AND DATE(ph.change_date) <= ?";
            $params[] = $filter_date_to;
        }
        
        $query .= " ORDER BY ph.change_date DESC";
        
        $stmt = executeQuery($query, $params);
        $price_history = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Filter error: " . $e->getMessage());
        $error = 'Error filtering price history.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price History - <?php echo htmlspecialchars($product['name']); ?> - Merrylay Enterprises
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Fallback fonts for offline */
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    </style>
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        
        .history-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .product-info {
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f5f9 100%);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .price-increase {
            color: #10b981;
            font-weight: 600;
        }
        
        .price-decrease {
            color: #ef4444;
            font-weight: 600;
        }
        
        .price-unchanged {
            color: #6b7280;
            font-weight: 600;
        }
        
        .price-type-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-cost {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-selling {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-crate {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .filter-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd6 0%, #6a4190 100%);
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6b7280;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }
        
        .table thead th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            border: none;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #d1d5db;
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
                <div class="nav-section">
                    <div class="nav-section-title">Overview</div>
                    <a href="../dashboard/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                    <?php if (hasRole(['admin', 'manager'])): ?>
                    <a href="view.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                        <span class="nav-text">Products</span>
                    </a>
                    <a href="../stock/manage.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                        <span class="nav-text">Stock</span>
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (isDispatcher() || hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Dispatch</div>
                    <?php if (hasRole(['admin', 'manager'])): ?>
                    <a href="../dispatch/drivers.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-user-tie"></i></span>
                        <span class="nav-text">Dispatchers</span>
                    </a>
                    <?php endif; ?>
                    <a href="../dispatch/trips/record.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-truck"></i></span>
                        <span class="nav-text">New Dispatch</span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="nav-section">
                    <div class="nav-section-title">Sales</div>
                    <a href="../dispatch/daily/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                        <span class="nav-text">New Sale</span>
                    </a>
                    <a href="../cashier/daily.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                        <span class="nav-text">Daily Financials</span>
                    </a>
                </div>

                <?php if (isCashier() || hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Finance</div>
                    <?php if (hasRole(['admin', 'manager'])): ?>
                    <a href="../dispatch/daily-accounting.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-calculator"></i></span>
                        <span class="nav-text">Daily Accounting</span>
                    </a>
                    <?php endif; ?>
                    <a href="../dispatch/daily/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-calendar-day"></i></span>
                        <span class="nav-text">Daily Sales</span>
                    </a>
                    <a href="../dispatch/customer-payments.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-money-bill-wave"></i></span>
                        <span class="nav-text">Customer Payments</span>
                    </a>
                    <a href="../dispatch/credit/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-text">Credit Management</span>
                    </a>
                    <a href="../dispatch/dispatcher-expenses.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-receipt"></i></span>
                        <span class="nav-text">Dispatcher Expenses</span>
                    </a>
                    <?php if (isCashier() || hasRole(['admin', 'manager'])): ?>
                    <a href="../dispatch/bank-deposits.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-university"></i></span>
                        <span class="nav-text">Bank Deposits</span>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="../dispatch/daily-accounting.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                        <span class="nav-text">Account Summary</span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <a href="../auth/logout.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="nav-text">Logout</span>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main content -->
        <main class="main-content" id="mainContent">
            <div class="page-header">
                <div class="container-fluid">
                    <h1><i class="fas fa-history me-2"></i>Price History</h1>
                    <p class="mb-0">View price change history for: <?php echo htmlspecialchars($product['name']); ?></p>
                </div>
            </div>
            
            <div class="container-fluid px-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Product Information -->
                <div class="product-info">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Product:</strong><br>
                            <?php echo htmlspecialchars($product['name']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>SKU:</strong><br>
                            <?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Cost Price:</strong><br>
                            ₦<?php echo number_format($product['cost_price'], 2); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Selling Price:</strong><br>
                            ₦<?php echo number_format($product['selling_price'], 2); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Crate Price:</strong><br>
                            ₦<?php echo number_format($product['crate_price'] ?? 0, 2); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="history-card">
                    <h5><i class="fas fa-filter me-2"></i>Filter Price History</h5>
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="id" value="<?php echo $product_id; ?>">
                        <div class="col-md-3">
                            <label for="filter_type" class="form-label">Price Type</label>
                            <select class="form-select" id="filter_type" name="filter_type">
                                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="cost_price" <?php echo $filter_type === 'cost_price' ? 'selected' : ''; ?>>Cost Price</option>
                                <option value="selling_price" <?php echo $filter_type === 'selling_price' ? 'selected' : ''; ?>>Selling Price</option>
                                <option value="crate_price" <?php echo $filter_type === 'crate_price' ? 'selected' : ''; ?>>Crate Price</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="filter_date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" 
                                   value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="filter_date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" 
                                   value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i>Filter
                            </button>
                        </div>
                    </form>
                    <div class="mt-2">
                        <a href="?id=<?php echo $product_id; ?>" class="btn btn-sm btn-secondary">
                            <i class="fas fa-times me-1"></i>Clear Filters
                        </a>
                    </div>
                </div>
                
                <!-- Price History Table -->
                <div class="history-card">
                    <h5><i class="fas fa-list me-2"></i>Price Change History</h5>
                    
                    <?php if (!$priceHistoryExists): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Price history tracking is not enabled. Please run the price_history.sql migration file to enable this feature.
                        </div>
                    <?php elseif (empty($price_history)): ?>
                        <div class="no-data">
                            <i class="fas fa-inbox"></i>
                            <h5>No Price History Found</h5>
                            <p>There are no price changes recorded for this product yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Price Type</th>
                                        <th>Old Price</th>
                                        <th>New Price</th>
                                        <th>Change</th>
                                        <th>% Change</th>
                                        <th>Changed By</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($price_history as $history): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($history['change_date'])); ?></td>
                                            <td>
                                                <span class="price-type-badge badge-<?php echo str_replace('_price', '', $history['price_type']); ?>">
                                                    <?php echo str_replace('_', ' ', $history['price_type']); ?>
                                                </span>
                                            </td>
                                            <td>₦<?php echo number_format($history['old_price'], 2); ?></td>
                                            <td>₦<?php echo number_format($history['new_price'], 2); ?></td>
                                            <td class="<?php 
                                                if ($history['price_difference'] > 0) echo 'price-increase';
                                                elseif ($history['price_difference'] < 0) echo 'price-decrease';
                                                else echo 'price-unchanged';
                                            ?>">
                                                <?php echo $history['price_difference'] > 0 ? '+' : ''; ?><?php echo number_format($history['price_difference'], 2); ?>
                                            </td>
                                            <td class="<?php 
                                                if ($history['percentage_change'] > 0) echo 'price-increase';
                                                elseif ($history['percentage_change'] < 0) echo 'price-decrease';
                                                else echo 'price-unchanged';
                                            ?>">
                                                <?php echo $history['percentage_change'] > 0 ? '+' : ''; ?><?php echo number_format($history['percentage_change'], 2); ?>%
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($history['changed_by_name']); ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($history['changed_by_username']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($history['change_reason'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Showing <?php echo count($price_history); ?> price change(s)
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex gap-2 mb-4">
                    <a href="edit.php?id=<?php echo $product_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Product
                    </a>
                    <a href="view.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Products
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/offline-handler.js"></script>
<script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


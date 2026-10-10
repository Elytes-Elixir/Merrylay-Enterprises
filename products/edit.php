<?php
/**
 * Edit Product Page for Merrylay Enterprises
 * 
 * Handles product editing functionality
 */

// Start session
require_once __DIR__ . '/../config/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if (!hasRole(['admin', 'manager'])) {
    error_log('Unauthorized product edit attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to edit products.');
}

// Include database configuration
require_once '../config/db.php';
require_once '../config/cache.php';

// Include authentication helper functions
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Initialize variables
$product = null;
$error = '';
$success = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected product edit: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the product details and submit again.';
    }
}

// Get product ID from URL
$product_id = $_GET['id'] ?? '';

if (empty($product_id)) {
    $_SESSION['error'] = 'Product ID is required.';
    header('Location: view.php');
    exit();
}

// Fetch product data
try {
    $pdo = getDBConnection();
    
    // Check if products table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'products'");
    if ($tableCheck->rowCount() == 0) {
        $_SESSION['error'] = 'Products table does not exist in database.';
        header('Location: view.php');
        exit();
    }
    
    // Get actual columns from products table
    $columnsResult = $pdo->query("SHOW COLUMNS FROM products");
    $existingColumns = [];
    while ($row = $columnsResult->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $row['Field'];
    }
    
    // Build SELECT query with only existing columns
    $desiredColumns = ['id', 'name', 'category', 'sku', 'barcode', 'description', 'quantity', 'unit', 'cost_price', 'crate_price', 'reorder_level', 'max_stock', 'weight', 'dimensions', 'supplier', 'supplier_contact', 'image_url', 'is_active'];
    $selectColumns = [];
    foreach ($desiredColumns as $col) {
        if (in_array($col, $existingColumns)) {
            $selectColumns[] = $col;
        }
    }
    
    if (empty($selectColumns)) {
        $_SESSION['error'] = 'Products table has no valid columns.';
        header('Location: view.php');
        exit();
    }
    
    $selectClause = implode(', ', $selectColumns);
    $stmt = executeQuery(
        "SELECT $selectClause FROM products WHERE id = ?",
        [$product_id]
    );
    $product = $stmt->fetch();
    
    if (!$product) {
        $_SESSION['error'] = 'Product not found.';
        header('Location: view.php');
        exit();
    }

    $stmt = executeQuery("SELECT DISTINCT category FROM products ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch available units from products table
    $stmt = executeQuery("SELECT DISTINCT unit FROM products WHERE unit IS NOT NULL AND unit != '' ORDER BY unit");
    $available_units = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // If no units found, provide default options
    if (empty($available_units)) {
        $available_units = ['Crate', 'Pack', 'Bottle', 'Carton', 'Piece', 'Box', 'Case', 'Set', 'Pair', 'Dozen', 'Kg', 'Liter', 'Meter'];
    }
} catch (PDOException $e) {
    error_log("Edit product error: " . $e->getMessage());
    $_SESSION['error'] = 'Unable to load product data: ' . $e->getMessage();
    header('Location: view.php');
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    // Get and sanitize input
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    if ($sku === '') {
        $sku = null;
    }
    $barcode = trim($_POST['barcode'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity = floatval($_POST['quantity'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $cost_price = floatval($_POST['cost_price'] ?? 0);
    $crate_price = floatval($_POST['crate_price'] ?? 0);
    $reorder_level = floatval($_POST['reorder_level'] ?? 0);
    $max_stock = floatval($_POST['max_stock'] ?? 0);
    $weight = floatval($_POST['weight'] ?? 0);
    $dimensions = trim($_POST['dimensions'] ?? '');
    $supplier = trim($_POST['supplier'] ?? '');
    $supplier_contact = trim($_POST['supplier_contact'] ?? '');
    $image_url = trim($_POST['image_url'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $price_change_reason = trim($_POST['price_change_reason'] ?? '');
    
    // Validation
    if (empty($name)) {
        $error = 'Product name is required.';
    } elseif (empty($category)) {
        $error = 'Category is required.';
    } elseif (empty($unit)) {
        $error = 'Unit is required.';
    } elseif ($cost_price < 0) {
        $error = 'Cost price must be a positive number.';
    } elseif ($weight < 0) {
        $error = 'Weight must be a positive number.';
    } elseif ($crate_price < 0) {
        $error = 'Crate price must be a positive number.';
    } elseif ($quantity < 0) {
        $error = 'Quantity must be a positive number.';
    } elseif ($reorder_level < 0) {
        $error = 'Reorder level must be a positive number.';
    } elseif ($max_stock < 0) {
        $error = 'Max stock must be a positive number.';
    } elseif (!empty($image_url) && !filter_var($image_url, FILTER_VALIDATE_URL)) {
        $error = 'Image URL is not valid.';
    } else {
        try {
            // Check if price history table exists, if not create it
            $tableCheck = executeQuery("SHOW TABLES LIKE 'price_history'");
            if ($tableCheck->rowCount() == 0) {
                // Table doesn't exist, skip logging for now
                $logPriceChanges = false;
            } else {
                $logPriceChanges = true;
            }
            
            // Log price changes before updating
            if ($logPriceChanges) {
                $user_id = $_SESSION['user_id'] ?? 1;
                
                // Log cost price change
                if ($product['cost_price'] != $cost_price) {
                    $query = "INSERT INTO price_history (product_id, price_type, old_price, new_price, changed_by, change_reason) 
                             VALUES (?, 'cost_price', ?, ?, ?, ?)";
                    executeQuery($query, [$product_id, $product['cost_price'], $cost_price, $user_id, $price_change_reason ?: 'Product update']);
                }
                
                // Log selling price change
                if ($product['selling_price'] != $selling_price) {
                    $query = "INSERT INTO price_history (product_id, price_type, old_price, new_price, changed_by, change_reason) 
                             VALUES (?, 'selling_price', ?, ?, ?, ?)";
                    executeQuery($query, [$product_id, $product['selling_price'], $selling_price, $user_id, $price_change_reason ?: 'Product update']);
                }
                
                // Log crate price change
                if (($product['crate_price'] ?? 0) != $crate_price) {
                    $query = "INSERT INTO price_history (product_id, price_type, old_price, new_price, changed_by, change_reason) 
                             VALUES (?, 'crate_price', ?, ?, ?, ?)";
                    executeQuery($query, [$product_id, $product['crate_price'] ?? 0, $crate_price, $user_id, $price_change_reason ?: 'Product update']);
                }
            }
            
            // Update product - only update columns that exist
            $updateColumns = [];
            $updateParams = [];
            
            if (in_array('name', $existingColumns)) {
                $updateColumns[] = "name = ?";
                $updateParams[] = $name;
            }
            if (in_array('category', $existingColumns)) {
                $updateColumns[] = "category = ?";
                $updateParams[] = $category;
            }
            if (in_array('sku', $existingColumns)) {
                $updateColumns[] = "sku = ?";
                $updateParams[] = $sku;
            }
            if (in_array('barcode', $existingColumns)) {
                $updateColumns[] = "barcode = ?";
                $updateParams[] = $barcode;
            }
            if (in_array('description', $existingColumns)) {
                $updateColumns[] = "description = ?";
                $updateParams[] = $description;
            }
            if (in_array('quantity', $existingColumns)) {
                $updateColumns[] = "quantity = ?";
                $updateParams[] = $quantity;
            }
            if (in_array('unit', $existingColumns)) {
                $updateColumns[] = "unit = ?";
                $updateParams[] = $unit;
            }
            if (in_array('cost_price', $existingColumns)) {
                $updateColumns[] = "cost_price = ?";
                $updateParams[] = $cost_price;
            }
            if (in_array('crate_price', $existingColumns)) {
                $updateColumns[] = "crate_price = ?";
                $updateParams[] = $crate_price;
            }
            if (in_array('reorder_level', $existingColumns)) {
                $updateColumns[] = "reorder_level = ?";
                $updateParams[] = $reorder_level;
            }
            if (in_array('max_stock', $existingColumns)) {
                $updateColumns[] = "max_stock = ?";
                $updateParams[] = $max_stock;
            }
            if (in_array('weight', $existingColumns)) {
                $updateColumns[] = "weight = ?";
                $updateParams[] = $weight;
            }
            if (in_array('dimensions', $existingColumns)) {
                $updateColumns[] = "dimensions = ?";
                $updateParams[] = $dimensions;
            }
            if (in_array('supplier', $existingColumns)) {
                $updateColumns[] = "supplier = ?";
                $updateParams[] = $supplier;
            }
            if (in_array('supplier_contact', $existingColumns)) {
                $updateColumns[] = "supplier_contact = ?";
                $updateParams[] = $supplier_contact;
            }
            if (in_array('image_url', $existingColumns)) {
                $updateColumns[] = "image_url = ?";
                $updateParams[] = $image_url;
            }
            if (in_array('is_active', $existingColumns)) {
                $updateColumns[] = "is_active = ?";
                $updateParams[] = $is_active;
            }
            if (in_array('updated_at', $existingColumns)) {
                $updateColumns[] = "updated_at = CURRENT_TIMESTAMP";
            }
            
            $updateParams[] = $product_id;
            
            $query = "UPDATE products SET " . implode(', ', $updateColumns) . " WHERE id = ?";
            $stmt = executeQuery($query, $updateParams);
            cache_clear();
            
            $_SESSION['success'] = 'Product updated successfully!';
            header('Location: view.php');
            exit();
        } catch (PDOException $e) {
            error_log("Update product error: " . $e->getMessage());
            if ($e->getCode() === '23000' && strpos($e->getMessage(), "for key 'sku'") !== false) {
                $error = 'Failed to update product. SKU must be unique and cannot be duplicated.';
            } else {
                $error = 'Failed to update product. Error: ' . $e->getMessage();
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit product information">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Edit Product - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Fallback fonts for offline */
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    </style>
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
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
                <div class="nav-section-title overview">Main Operations</div>
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

                <?php if (isDispatcher() || hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title dispatch">Dispatch Operations</div>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../dispatch/drivers.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span class="nav-text">Dispatchers</span>
                </a>
                <?php endif; ?>
                <a href="../dispatch/trips/record.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-truck"></i></span>
                    <span class="nav-text">New Dispatch</span>
                </a>
                <?php endif; ?>

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
                <a href="../dispatch/credit/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-text">Credit Management</span>
                </a>
                <?php if (isCashier()): ?>
                <a href="../cashier/deposit.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
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
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search products..." id="globalSearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <button class="icon-btn position-relative" title="Notifications">
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
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <h1>Edit Product</h1>
                            <p>Update product information for: <?php echo htmlspecialchars($product['name']); ?></p>
                        </div>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="edit.php?id=<?php echo urlencode($product_id); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <!-- Basic Information -->
                    <div class="stat-card">
                        <h5><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Product Name *</label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($product['name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="category" class="form-label">Category *</label>
                                    <input type="text" class="form-control" id="category" name="category" 
                                           value="<?php echo htmlspecialchars($product['category']); ?>" 
                                           list="categories" required>
                                    <datalist id="categories">
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sku" class="form-label">SKU</label>
                                    <input type="text" class="form-control" id="sku" name="sku" 
                                           value="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="barcode" class="form-label">Barcode</label>
                                    <input type="text" class="form-control" id="barcode" name="barcode" 
                                           value="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Inventory Details -->
                    <div class="stat-card">
                        <h5><i class="fas fa-warehouse me-2"></i>Inventory Details</h5>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="quantity" class="form-label">Quantity *</label>
                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                           value="<?php echo $product['quantity']; ?>" min="0" step="0.25" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="unit" class="form-label">Unit *</label>
                                    <select class="form-select" id="unit" name="unit" required>
                                        <?php foreach ($available_units as $unit_option): ?>
                                            <option value="<?php echo htmlspecialchars($unit_option); ?>" 
                                                    <?php echo $product['unit'] === $unit_option ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($unit_option); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="reorder_level" class="form-label">Reorder Level *</label>
                                    <input type="number" class="form-control" id="reorder_level" name="reorder_level" 
                                           value="<?php echo $product['reorder_level']; ?>" min="0" step="0.25" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="max_stock" class="form-label">Max Stock</label>
                                    <input type="number" class="form-control" id="max_stock" name="max_stock" 
                                           value="<?php echo htmlspecialchars($product['max_stock'] ?? ''); ?>" min="0" step="0.25">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Stock Status</label>
                                    <input type="text" class="form-control stock-status" id="status" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Active Status</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo isset($product['is_active']) && $product['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">Active product</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pricing -->
                    <div class="stat-card">
                        <h5><i class="fas fa-money-bill-wave me-2"></i>Pricing</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="cost_price" class="form-label">Cost Price (₦) *</label>
                                    <input type="number" class="form-control" id="cost_price" name="cost_price" 
                                           value="<?php echo $product['cost_price']; ?>" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="crate_price" class="form-label">Crate Price (₦)</label>
                                    <input type="number" class="form-control" id="crate_price" name="crate_price" 
                                           value="<?php echo $product['crate_price'] ?? ''; ?>" step="0.01" min="0">
                                    <small class="text-muted">Price per crate (for drinks)</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label for="price_change_reason" class="form-label">Price Change Reason</label>
                                    <textarea class="form-control" id="price_change_reason" name="price_change_reason" 
                                              rows="2" placeholder="Explain why prices are being changed (e.g., supplier price increase, seasonal adjustment, etc.)"></textarea>
                                    <small class="text-muted">Required when changing prices</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Profit Margin</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control profit-margin" id="profit_margin" readonly>
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Profit per Unit</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="text" class="form-control profit-margin" id="profit_per_unit" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Supplier Information -->
                    <div class="stat-card">
                        <h5><i class="fas fa-truck-moving me-2"></i>Supplier Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier" class="form-label">Supplier</label>
                                    <input type="text" class="form-control" id="supplier" name="supplier" 
                                           value="<?php echo htmlspecialchars($product['supplier'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier_contact" class="form-label">Supplier Contact</label>
                                    <input type="text" class="form-control" id="supplier_contact" name="supplier_contact" 
                                           value="<?php echo htmlspecialchars($product['supplier_contact'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Product Details -->
                    <div class="stat-card">
                        <h5><i class="fas fa-cube me-2"></i>Product Details</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="weight" class="form-label">Weight (kg)</label>
                                    <input type="number" class="form-control" id="weight" name="weight" 
                                           value="<?php echo htmlspecialchars($product['weight'] ?? ''); ?>" step="0.01" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="dimensions" class="form-label">Dimensions</label>
                                    <input type="text" class="form-control" id="dimensions" name="dimensions" 
                                           value="<?php echo htmlspecialchars($product['dimensions'] ?? ''); ?>" 
                                           placeholder="e.g., 30x20x10 cm">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="image_url" class="form-label">Image URL</label>
                                    <input type="url" class="form-control" id="image_url" name="image_url" 
                                           value="<?php echo htmlspecialchars($product['image_url'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between gap-2">
                        <div class="d-flex gap-2">
                            <a href="price_history.php?id=<?php echo $product_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-history me-2"></i>View Price History
                            </a>
                            <a href="view.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Product
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/offline-handler.js"></script>
<script src="../assets/js/app.js"></script>
    <script>
        // Calculate profit margin
        function calculateProfitMargin() {
            const costPrice = parseFloat(document.getElementById('cost_price').value) || 0;
            const cratePrice = parseFloat(document.getElementById('crate_price').value) || 0;
            
            if (costPrice > 0 && cratePrice > 0) {
                const margin = ((cratePrice - costPrice) / costPrice * 100).toFixed(2);
                const profit = (cratePrice - costPrice).toFixed(2);
                document.getElementById('profit_margin').value = margin;
                document.getElementById('profit_per_unit').value = profit;
                
                const marginEl = document.getElementById('profit_margin');
                const profitEl = document.getElementById('profit_per_unit');
                
                if (parseFloat(profit) >= 0) {
                    marginEl.classList.remove('profit-negative');
                    marginEl.classList.add('profit-positive');
                    profitEl.classList.remove('profit-negative');
                    profitEl.classList.add('profit-positive');
                } else {
                    marginEl.classList.remove('profit-positive');
                    marginEl.classList.add('profit-negative');
                    profitEl.classList.remove('profit-positive');
                    profitEl.classList.add('profit-negative');
                }
            } else {
                document.getElementById('profit_margin').value = '';
                document.getElementById('profit_per_unit').value = '';
            }
        }
        
        // Update stock status
        function updateStockStatus() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const reorderLevel = parseFloat(document.getElementById('reorder_level').value) || 0;
            const statusField = document.getElementById('status');
            
            statusField.classList.remove('status-in-stock', 'status-low-stock', 'status-out-of-stock');
            
            if (quantity === 0) {
                statusField.value = 'Out of Stock';
                statusField.classList.add('status-out-of-stock');
            } else if (quantity <= reorderLevel) {
                statusField.value = 'Low Stock';
                statusField.classList.add('status-low-stock');
            } else {
                statusField.value = 'In Stock';
                statusField.classList.add('status-in-stock');
            }
        }
        
        // Event listeners
        document.getElementById('cost_price').addEventListener('input', calculateProfitMargin);
        document.getElementById('crate_price').addEventListener('input', calculateProfitMargin);
        document.getElementById('quantity').addEventListener('input', updateStockStatus);
        document.getElementById('reorder_level').addEventListener('input', updateStockStatus);
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateProfitMargin();
            updateStockStatus();
        });
    </script>
</body>
</html>


<?php
/**
 * Modern Add Product Page for Merrylay Enterprises
 * 
 * Handles adding new products to inventory with modern UI
 */

require_once '../init.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if (!hasRole(['admin', 'manager'])) {
    error_log('Unauthorized product creation attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to add products.');
}

// Initialize variables
$error = '';
$success = $_SESSION['product_add_success'] ?? '';
unset($_SESSION['product_add_success']);
$product_name = '';
$description = '';
$category = '';
$unit = 'Crate';
$cost_price = '';
$quantity = 0;
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_name = is_string($_POST['product_name'] ?? null) ? trim($_POST['product_name']) : '';
    $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
    $category = is_string($_POST['category'] ?? null) ? trim($_POST['category']) : '';
    $unit = is_string($_POST['unit'] ?? null) ? trim($_POST['unit']) : 'Crate';
    $cost_price = is_scalar($_POST['cost_price'] ?? null) ? (string) $_POST['cost_price'] : '';
    $quantity = is_scalar($_POST['quantity'] ?? null) ? (string) $_POST['quantity'] : '0';
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected product creation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the product details and submit again.';
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    // Get and sanitize input
    $product_name = trim($_POST['product_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $unit = trim($_POST['unit'] ?? 'Crate');
    $cost_price = floatval($_POST['cost_price'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);

    // Validate input
    if (empty($product_name)) {
        $error = 'Product name is required.';
    } elseif (empty($category)) {
        $error = 'Category is required.';
    } elseif ($cost_price <= 0) {
        $error = 'Cost price must be greater than 0.';
    } elseif ($quantity < 0) {
        $error = 'Quantity cannot be negative.';
    } else {
        try {
            // Check if product already exists
            $stmt = executeQuery("SELECT id FROM products WHERE name = ?", [$product_name]);
            if ($stmt->fetch()) {
                $error = 'Product with this name already exists.';
            } else {
                // Insert new product with current schema fields
                $query = "INSERT INTO products (name, description, category, unit, cost_price, selling_price, quantity, reorder_level, max_stock, weight, dimensions, supplier, supplier_contact, image_url, is_active, created_at, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
                $params = [$product_name, $description, $category, $unit, $cost_price, $cost_price, $quantity, 10, 1000, 0, '', '', '', '', 1, $_SESSION['user_id']];

                executeQuery($query, $params);
                $_SESSION['product_add_success'] = 'Product added successfully!';

                // Clear form
                $product_name = '';
                $description = '';
                $category = '';
                $unit = 'Crate';
                $cost_price = '';
                $quantity = 0;
                header('Location: add.php');
                exit();
            }
        } catch (PDOException $e) {
            error_log("Add product error: " . $e->getMessage());
            $error = 'Failed to add product. Please try again.';
        }
    }
}

// Get categories for dropdown
try {
    $stmt = executeQuery("SELECT DISTINCT category FROM products ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Add new product to inventory">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Add New Product - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
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
                <a href="view.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="add.php" class="nav-link active">
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
                        <input type="text" placeholder="Search products, sales, dispatches..." id="globalSearch">
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
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-plus-circle" style="margin-right: 15px;"></i>
            Add New Product
        </h1>
        <p class="page-subtitle">Complete the form below to add a new product to your inventory</p>
    </div>

    <!-- Alerts -->
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

    <!-- Main Grid -->
    <div class="row">
        <!-- Form Section -->
        <div class="col-lg-8">
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h5 class="card-title">Product Information</h5>
                        <p class="card-subtitle">Enter the details for the new product</p>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="productForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        Product Name <span style="color: var(--red); font-weight: 700;">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="product_name" name="product_name" 
                                           value="<?php echo htmlspecialchars($product_name); ?>" 
                                           placeholder="e.g., Coca Cola 500ml" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        Category <span style="color: var(--red); font-weight: 700;">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="category" name="category" 
                                           value="<?php echo htmlspecialchars($category); ?>" 
                                           list="categories" placeholder="e.g., Soft Drinks" required>
                                    <datalist id="categories">
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="3" placeholder="Brief description of the product"><?php echo htmlspecialchars($description); ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">
                                        Unit Type <span style="color: var(--red); font-weight: 700;">*</span>
                                    </label>
                                    <select class="form-select" id="unit" name="unit" required>
                                        <option value="Crate" <?php echo $unit === 'Crate' ? 'selected' : ''; ?>>Crate</option>
                                        <option value="Pack" <?php echo $unit === 'Pack' ? 'selected' : ''; ?>>Pack</option>
                                        <option value="Bottle" <?php echo $unit === 'Bottle' ? 'selected' : ''; ?>>Bottle</option>
                                        <option value="Carton" <?php echo $unit === 'Carton' ? 'selected' : ''; ?>>Carton</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">
                                        Cost Price <span style="color: var(--red); font-weight: 700;">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" class="form-control" id="cost_price" name="cost_price"
                                               value="<?php echo htmlspecialchars($cost_price); ?>"
                                               step="0.01" min="0.01" placeholder="0.00" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">
                                        Initial Quantity <span style="color: var(--red); font-weight: 700;">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                           value="<?php echo htmlspecialchars($quantity); ?>" 
                                           min="0" step="0.25" placeholder="0" required>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <a href="view.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i>
                                Add Product
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar Section -->
        <div class="col-lg-4">
            <!-- Preview Card -->
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h5 class="card-title">Product Preview</h5>
                        <p class="card-subtitle">Live preview of your product</p>
                    </div>
                </div>
                <div class="card-body text-center">
                    <div class="stat-icon green" style="margin: 0 auto 20px; width: 56px; height: 56px; font-size: 1.25rem;">
                        <i class="fas fa-box"></i>
                    </div>
                    <h3 id="previewName" style="font-size: 1.125rem; font-weight: 700; color: var(--primary-800); margin-bottom: 8px;">Product Name</h3>
                    <p id="previewCategory" style="color: var(--primary-500); font-size: 0.875rem; margin-bottom: 20px;">Category</p>
                    <div style="margin-top: 20px;">
                        <h2 id="previewPrice" style="font-size: 1.5rem; font-weight: 800; color: var(--green); margin-bottom: 8px;">₦0.00</h2>
                        <p id="previewStock" style="color: var(--primary-500); font-size: 0.875rem;">0 in stock</p>
                    </div>
                </div>
            </div>
            
            <!-- Tips Section -->
            <div class="content-card" style="background: linear-gradient(135deg, #fef3c7, #fed7aa); border: 1px solid var(--yellow);">
                <div class="card-header" style="background: linear-gradient(135deg, rgba(254, 243, 199, 0.5), rgba(254, 215, 170, 0.3)); border-left: 4px solid var(--yellow);">
                    <div>
                        <h5 class="card-title" style="color: #92400e;">
                            <i class="fas fa-lightbulb"></i>
                            Quick Tips
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <ul style="list-style: none; padding: 0;">
                        <li style="padding: 6px 0; color: #78350f; font-size: 0.8125rem; font-weight: 500; display: flex; align-items: center; gap: 10px;">
                            <span style="color: var(--green); font-weight: 700;">✓</span>
                            Use clear product names for drinks
                        </li>
                        <li style="padding: 6px 0; color: #78350f; font-size: 0.8125rem; font-weight: 500; display: flex; align-items: center; gap: 10px;">
                            <span style="color: var(--green); font-weight: 700;">✓</span>
                            Set accurate crate/pack prices
                        </li>
                        <li style="padding: 6px 0; color: #78350f; font-size: 0.8125rem; font-weight: 500; display: flex; align-items: center; gap: 10px;">
                            <span style="color: var(--green); font-weight: 700;">✓</span>
                            Choose appropriate unit types
                        </li>
                        <li style="padding: 6px 0; color: #78350f; font-size: 0.8125rem; font-weight: 500; display: flex; align-items: center; gap: 10px;">
                            <span style="color: var(--green); font-weight: 700;">✓</span>
                            Keep descriptions concise but informative
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
        </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
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

        // Real-time preview updates
        document.addEventListener('DOMContentLoaded', function() {
        const product_name = document.getElementById('product_name');
        const category = document.getElementById('category');
        const cost_price = document.getElementById('cost_price');
        const quantity = document.getElementById('quantity');
        const unit = document.getElementById('unit');

        // Preview elements
        const previewName = document.getElementById('previewName');
        const previewCategory = document.getElementById('previewCategory');
        const previewPrice = document.getElementById('previewPrice');
        const previewStock = document.getElementById('previewStock');

        // Update preview on input
        product_name.addEventListener('input', function() {
            previewName.textContent = this.value || 'Product Name';
        });

        category.addEventListener('input', function() {
            previewCategory.textContent = this.value || 'Category';
        });

        cost_price.addEventListener('input', function() {
            const value = parseFloat(this.value) || 0;
            previewPrice.textContent = '₦' + value.toLocaleString('en-NG', {minimumFractionDigits: 2});
        });

        quantity.addEventListener('input', function() {
            const value = parseInt(this.value) || 0;
            const unitValue = unit.value || 'crates';
            previewStock.textContent = value + ' ' + unitValue.toLowerCase() + ' in stock';
        });

        unit.addEventListener('change', function() {
            const quantityValue = parseInt(quantity.value) || 0;
            const unitText = this.value.toLowerCase();
            previewStock.textContent = quantityValue + ' ' + unitText + ' in stock';
        });

        // Initialize preview
        if (previewName) previewName.textContent = product_name.value || 'Product Name';
        if (previewCategory) previewCategory.textContent = category.value || 'Category';
        if (previewPrice) {
            const priceValue = parseFloat(cost_price.value) || 0;
            previewPrice.textContent = '₦' + priceValue.toLocaleString('en-NG', {minimumFractionDigits: 2});
        }
        if (previewStock) {
            const qtyValue = parseInt(quantity.value) || 0;
            const unitValue = unit.value || 'crates';
            previewStock.textContent = qtyValue + ' ' + unitValue.toLowerCase() + ' in stock';
        }
    });
</script>


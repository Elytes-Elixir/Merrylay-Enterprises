<?php
/**
 * Edit Trip Page
 * 
 * Allows dispatchers to edit trip details including products, quantities, prices, and discounts
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../init.php';
require_once '../../config/db.php';
require_once '../../config/cache.php';
require_once '../../config/auth.php';
require_once '../../config/stock_manager.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';

// Load stock manager
$stockManager = getStockManager();

// Check if user has permission to edit trips
if (!canRecordDispatches()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized trip edit attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to edit trips.');
    }
    header('Location: index.php');
    exit();
}

$trip_id = $_GET['id'] ?? null;
$error = '';
$success = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected trip edit: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the trip details and submit again.';
    }
}

// Check for success messages
if (isset($_GET['success']) && $_GET['success'] === 'trip_updated') {
    $success = 'Trip updated successfully!';
}

if (!$trip_id) {
    header('Location: index.php');
    exit();
}

// Get trip details
$trip = null;
$trip_items = [];
try {
    $stmt = executeQuery("
        SELECT d.* 
        FROM dispatches d 
        WHERE d.id = ?
    ", [$trip_id]);
    $trip = $stmt->fetch();
    
    if (!$trip) {
        header('Location: index.php');
        exit();
    }
    if (($trip['status'] ?? '') === 'cancelled') {
        header('Location: index.php');
        exit();
    }
    
    // Get trip items
    $stmt = executeQuery("
        SELECT di.*, p.name as product_name
        FROM dispatch_items di
        LEFT JOIN products p ON di.product_id = p.id
        WHERE di.dispatch_id = ?
        ORDER BY di.id
    ", [$trip_id]);
    $trip_items = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get trip error: " . $e->getMessage());
    $error = 'Unable to load trip details.';
}

// Get drivers
$drivers = [];
try {
    $stmt = executeQuery("SELECT id, name FROM drivers WHERE status = 'active' ORDER BY name");
    $drivers = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load drivers';
}

// Get products
$products = [];
try {
    $stmt = executeQuery("SELECT id, name, cost_price FROM products WHERE is_active = TRUE ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load products';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    $driver_id = intval($_POST['driver_id'] ?? 0);
    $trip_date = $_POST['trip_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    $discount_amounts = $_POST['discount_amount'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    
    if (empty($driver_id)) {
        $error = 'Please select a driver';
    } elseif (empty($product_ids) || empty(array_filter($quantities))) {
        $error = 'Please add at least one product';
    } else {
        try {
            // Get driver name
            $stmt = executeQuery("SELECT name FROM drivers WHERE id = ?", [$driver_id]);
            $driver = $stmt->fetch();
            if (!$driver) {
                throw new Exception('Invalid driver selected');
            }
            
            // Calculate expected amount (total value without discount)
            $expected_amount = 0;
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $price = floatval($unit_prices[$index] ?? 0);
                    $expected_amount += ($qty * $price);
                }
            }
            
            $pdo = getDBConnection();
            $pdo->beginTransaction();
            
            // Capture the original trip date and existing items before changing anything
            $old_trip_date = $trip['dispatch_date'] ?? $trip_date;
            $old_items = [];
            $stmt = $pdo->prepare("
                SELECT product_id, quantity
                FROM dispatch_items
                WHERE dispatch_id = ?
            ");
            $stmt->execute([$trip_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $old_items[$row['product_id']] = $row['quantity'];
            }

            // Validate stock availability for updated trip item quantities WITH ROW LOCKING
            // This prevents race conditions where multiple users could validate simultaneously
            $requestedStock = [];
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $old_qty = $old_items[$product_id] ?? 0;
                $qty_diff = $qty - $old_qty;
                if ($qty_diff > 0) {
                    $requestedStock[$product_id] = ($requestedStock[$product_id] ?? 0) + $qty_diff;
                }
            }

            if (!empty($requestedStock)) {
                $placeholders = implode(',', array_fill(0, count($requestedStock), '?'));
                $stmt = $pdo->prepare("SELECT id, name, quantity FROM products WHERE id IN ($placeholders) FOR UPDATE");
                $stmt->execute(array_keys($requestedStock));
                $productStock = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $productStock[$row['id']] = $row;
                }

                foreach ($requestedStock as $product_id => $qty_diff) {
                    if (!isset($productStock[$product_id])) {
                        throw new Exception('Product not found during trip update');
                    }

                    $product = $productStock[$product_id];
                    if ($product['quantity'] < $qty_diff) {
                        throw new Exception('Only ' . $product['quantity'] . ' unit' . ($product['quantity'] == 1 ? '' : 's') . ' of "' . htmlspecialchars($product['name']) . '" are available for this update.');
                    }
                }
            }
            
            // Update trip record
            $stmt = $pdo->prepare("
                UPDATE dispatches 
                SET dispatcher_name = ?, expected_amount = ?, actual_sales_amount = ?, dispatch_date = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$driver['name'], $expected_amount, $expected_amount, $trip_date, $notes, $trip_id]);
            
            // Delete existing trip items
            $stmt = $pdo->prepare("DELETE FROM dispatch_items WHERE dispatch_id = ?");
            $stmt->execute([$trip_id]);
            
            // Add updated trip items
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $price = floatval($unit_prices[$index] ?? 0);
                    $discount = floatval($discount_amounts[$index] ?? 0);
                    $stmt = $pdo->prepare("
                        INSERT INTO dispatch_items (dispatch_id, product_id, quantity, unit_price, discount_amount)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$trip_id, $product_id, $qty, $price, $discount]);
                }
            }

            // Reconcile stock balances using the shared movement flow so edits stay fully balanced.
            // We undo the old trip effect on its original date and apply the new trip effect on the new date.
            $new_item_quantities = [];
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $new_item_quantities[intval($product_id)] = $qty;
                }
            }

            $has_stock_impact = ($old_trip_date !== $trip_date);
            if (!$has_stock_impact) {
                $old_signature = [];
                foreach ($old_items as $product_id => $qty) {
                    $old_signature[intval($product_id)] = floatval($qty);
                }
                $has_stock_impact = ($old_signature !== $new_item_quantities);
            }

            $affectedProducts = [];
            if ($has_stock_impact) {
                if ($old_trip_date !== $trip_date) {
                    // Shift stock effect from old date to new date when trip date changes.
                    foreach ($old_items as $product_id => $old_qty) {
                        $product_id = intval($product_id);
                        $old_qty = floatval($old_qty);
                        if ($old_qty > 0) {
                            $affectedProducts[] = $product_id;
                            $stockManager->recordMovement(
                                $product_id,
                                'return',
                                $old_qty,
                                'dispatch',
                                $trip_id,
                                "TRIP-EDIT-$trip_id",
                                'Trip edit - reverse original stock',
                                $_SESSION['user_id'],
                                $old_trip_date
                            );
                        }
                    }

                    foreach ($new_item_quantities as $product_id => $new_qty) {
                        $product_id = intval($product_id);
                        $new_qty = floatval($new_qty);
                        if ($new_qty > 0) {
                            $affectedProducts[] = $product_id;
                            $stockManager->recordMovement(
                                $product_id,
                                'dispatch',
                                -$new_qty,
                                'dispatch',
                                $trip_id,
                                "TRIP-EDIT-$trip_id",
                                'Trip edit - apply updated stock',
                                $_SESSION['user_id'],
                                $trip_date
                            );
                        }
                    }
                } else {
                    // On the same day, only record the net quantity changes for each product.
                    $all_product_ids = array_unique(array_merge(array_keys($old_items), array_keys($new_item_quantities)));
                    foreach ($all_product_ids as $product_id) {
                        $product_id = intval($product_id);
                        $old_qty = floatval($old_items[$product_id] ?? 0);
                        $new_qty = floatval($new_item_quantities[$product_id] ?? 0);
                        $qty_diff = $new_qty - $old_qty;

                        if ($qty_diff === 0.0) {
                            continue;
                        }

                        $affectedProducts[] = $product_id;
                        if ($qty_diff > 0) {
                            $stockManager->recordMovement(
                                $product_id,
                                'dispatch',
                                -$qty_diff,
                                'dispatch',
                                $trip_id,
                                "TRIP-EDIT-$trip_id",
                                'Trip edit - apply updated stock',
                                $_SESSION['user_id'],
                                $trip_date
                            );
                        } else {
                            $stockManager->recordMovement(
                                $product_id,
                                'return',
                                abs($qty_diff),
                                'dispatch',
                                $trip_id,
                                "TRIP-EDIT-$trip_id",
                                'Trip edit - reverse original stock',
                                $_SESSION['user_id'],
                                $trip_date
                            );
                        }
                    }
                }
            }

            $affectedProducts = array_values(array_unique($affectedProducts));
            if (!empty($affectedProducts)) {
                $rebuildStartDate = min($old_trip_date, $trip_date);
                $rebuildEndDate = date('Y-m-d');
                foreach ($affectedProducts as $product_id) {
                    $stockManager->recalculateDailyStock($rebuildStartDate, $rebuildEndDate, $product_id);
                }
            }

            $pdo->commit();
            cache_clear();
            
            header('Location: edit.php?id=' . $trip_id . '&success=trip_updated');
            exit();
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to update trip: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit trip for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Edit Trip - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        /* Product Row Styles */
        .product-row {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
            position: relative;
        }
        
        .product-row:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--border-dark);
        }
        
        .product-row::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--blue) 0%, var(--green) 100%);
            opacity: 0;
            transition: opacity var(--transition-normal);
        }
        
        .product-row:hover::before {
            opacity: 1;
        }
        
        /* Total Box Styles */
        .total-box {
            background: var(--blue);
            color: white;
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            text-align: center;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        
        .total-box::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: float 8s ease-in-out infinite;
        }
        
        .total-content {
            position: relative;
            z-index: 1;
        }
        
        /* Button Styles */
        .btn-add {
            background: var(--green);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: var(--space-3) var(--space-4);
            font-weight: 600;
            transition: all var(--transition-normal);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-add:hover {
            background: var(--green-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: white;
        }
        
        .btn-remove {
            background: var(--red);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-normal);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-remove:hover {
            background: var(--red-dark);
            transform: scale(1.1);
            box-shadow: var(--shadow-md);
            color: white;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../../dashboard/index.php" class="logo">
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
                        <input type="text" placeholder="Search trips, drivers, products..." id="globalSearch">
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
                                <li><a class="dropdown-item" href="../../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <li><a class="dropdown-item" href="../../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
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
                            <i class="fas fa-edit" style="margin-right: 15px;"></i>
                            Edit Trip
                        </h1>
                        <p class="page-subtitle">
                            Correct mistakes in trip details. Edit products, quantities, prices, and discounts.
                        </p>
                        <div class="d-flex gap-4 flex-wrap" style="margin-top: var(--space-3);">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-hashtag" style="color: var(--primary-500);"></i>
                                <small><?php echo htmlspecialchars($trip['dispatch_number'] ?? ''); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Container -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-truck" style="margin-right: 10px;"></i>
                                Trip Information
                            </h5>
                            <p class="card-subtitle">Edit trip details and products for dispatch</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="tripForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="driver_id" class="form-label">Dispatcher (Driver) <span style="color: var(--red); font-weight: 700;">*</span></label>
                                        <select name="driver_id" id="driver_id" class="form-select" required>
                                            <option value="">Select Dispatcher</option>
                                            <?php foreach ($drivers as $driver): ?>
                                                <option value="<?php echo $driver['id']; ?>" <?php echo ($trip['dispatcher_name'] ?? '') === $driver['name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($driver['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="trip_date" class="form-label">Trip Date <span style="color: var(--red); font-weight: 700;">*</span></label>
                                        <input type="date" name="trip_date" id="trip_date" class="form-control" value="<?php echo htmlspecialchars($trip['dispatch_date'] ?? date('Y-m-d')); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5">
                                <h5 class="mb-4" style="color: var(--primary-800); font-weight: 600;">
                                    <i class="fas fa-boxes" style="margin-right: 10px;"></i>
                                    Products for This Trip
                                </h5>
                                <div id="productList">
                                    <?php foreach ($trip_items as $index => $item): ?>
                                    <div class="product-row row align-items-center">
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted">Product</label>
                                            <div class="form-group mb-0">
                                                <select name="product_id[]" class="form-select product-select" required>
                                                    <option value="">Select Product</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['cost_price']; ?>" <?php echo $item['product_id'] == $product['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($product['name']); ?> - ₦<?php echo number_format($product['cost_price'], 2); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted">Quantity</label>
                                            <div class="form-group mb-0">
                                                <input type="number" name="quantity[]" class="form-control quantity-input" placeholder="Qty" min="0.1" step="any" value="<?php echo $item['quantity']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted">Unit Price (₦)</label>
                                            <div class="form-group mb-0">
                                                <input type="number" name="unit_price[]" class="form-control price-input" placeholder="Unit Price" step="0.01" value="<?php echo $item['unit_price']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted" style="color: var(--primary-600); font-weight: 600;">Discount (₦)</label>
                                            <div class="form-group mb-0">
                                                <input type="number" name="discount_amount[]" class="form-control discount-input" placeholder="Discount" step="0.01" min="0" value="<?php echo $item['discount_amount'] ?? 0; ?>" style="border-color: var(--primary-300);">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted">Subtotal (₦)</label>
                                            <div class="form-group mb-0">
                                                <input type="text" class="form-control subtotal-display" placeholder="Subtotal" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
                                            </div>
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <label class="form-label small text-muted">&nbsp;</label>
                                            <button type="button" class="btn-remove w-100" onclick="removeProduct(this)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <button type="button" class="btn-add mt-3" onclick="addProduct()">
                                    <i class="fas fa-plus" style="margin-right: 8px;"></i>
                                    Add Another Product
                                </button>
                            </div>

                            <div class="row mt-5">
                                <div class="col-md-8">
                                    <div class="form-group">
                                        <label for="notes" class="form-label">Notes</label>
                                        <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Optional notes about this trip..."><?php echo htmlspecialchars($trip['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="total-box">
                                        <div class="total-content">
                                            <small class="d-block mb-2">Expected Amount</small>
                                            <h3 class="mb-0">₦<span id="totalAmount">0.00</span></h3>
                                            <small style="opacity: 0.8;">Auto-calculated</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="action-buttons mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Update Trip
                                </button>
                                <a href="inventory-receipt.php?id=<?php echo $trip_id; ?>" class="btn btn-success" target="_blank">
                                    <i class="fas fa-file-invoice"></i> View Receipt
                                </a>
                                <a href="list.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/app.js"></script>
    
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

        // Global search functionality
        document.getElementById('globalSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            if (searchTerm.length > 2) {
                console.log('Searching for:', searchTerm);
            }
        });

        // Product management functions
        function addProduct() {
            const row = document.querySelector('.product-row').cloneNode(true);
            row.querySelector('.product-select').value = '';
            row.querySelector('.quantity-input').value = '';
            row.querySelector('.price-input').value = '';
            row.querySelector('.discount-input').value = '0';
            row.querySelector('.subtotal-display').value = '';
            
            row.style.opacity = '0';
            row.style.transform = 'translateY(-20px)';
            document.getElementById('productList').appendChild(row);
            
            setTimeout(() => {
                row.style.transition = 'all 0.3s ease-out';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, 10);
            
            attachListeners();
        }

        function removeProduct(btn) {
            const rows = document.querySelectorAll('.product-row');
            if (rows.length > 1) {
                const row = btn.closest('.product-row');
                
                row.style.transition = 'all 0.3s ease-out';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px) scale(0.95)';
                
                setTimeout(() => {
                    row.remove();
                    calculateTotal();
                }, 300);
            }
        }

        function calculateTotal() {
            let total = 0;
            document.querySelectorAll('.product-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.quantity-input').value) || 0;
                const price = parseFloat(row.querySelector('.price-input').value) || 0;
                const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
                const subtotal = (qty * price) - discount;
                
                row.querySelector('.subtotal-display').value = subtotal.toFixed(2);
                
                // Add total value without discount for dispatch amount
                total += (qty * price);
            });
            
            const totalElement = document.getElementById('totalAmount');
            const currentValue = parseFloat(totalElement.textContent.replace(/,/g, '')) || 0;
            
            if (currentValue !== total) {
                animateValue(totalElement, currentValue, total, 300);
            }
        }

        function animateValue(element, start, end, duration) {
            const range = end - start;
            const increment = range / (duration / 16);
            let current = start;
            
            const timer = setInterval(() => {
                current += increment;
                if ((increment > 0 && current >= end) || (increment <= 0 && current <= end)) {
                    current = end;
                    clearInterval(timer);
                }
                element.textContent = current.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }, 16);
        }

        function attachListeners() {
            document.querySelectorAll('.product-select').forEach(select => {
                select.onchange = function() {
                    const price = this.options[this.selectedIndex].dataset.price;
                    if (price) {
                        const priceInput = this.closest('.product-row').querySelector('.price-input');
                        priceInput.value = price;
                        
                        priceInput.style.transition = 'all 0.3s ease';
                        priceInput.style.background = 'rgba(99, 102, 241, 0.1)';
                        setTimeout(() => {
                            priceInput.style.background = '';
                        }, 500);
                        
                        calculateTotal();
                    }
                };
            });
            
            document.querySelectorAll('.quantity-input, .price-input, .discount-input').forEach(input => {
                input.oninput = calculateTotal;
            });
        }

        // Initialize
        attachListeners();
        calculateTotal();
    </script>
</body>
</html>

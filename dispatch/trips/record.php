<?php
/**
 * Record Dispatch - Dispatch Creation
 * 
 * Records dispatches by selecting dispatcher, adding products, and recording dispatch.
 * Non-shop attendant users create dispatches for vehicles.
 * Shop attendants should use the direct sales system instead.
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

// Check if user has permission to record dispatches
if (!canRecordDispatches()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized dispatch creation attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to create dispatches.');
    }
    header('Location: ../../dashboard/index.php');
    exit();
}

// Check if daily stock is initialized
$stockManager = getStockManager();
$today = date('Y-m-d');
if (!$stockManager->isDailyStockInitialized($today)) {
    // Redirect to stock management page with error message
    header('Location: ../../stock/manage.php?error=stock_not_initialized');
    exit();
}

$error = '';
$success = $_SESSION['trip_record_success'] ?? '';
unset($_SESSION['trip_record_success']);
const MAX_DISPATCH_QUANTITY = 999999.99;
const MAX_DISPATCH_AMOUNT = 99999999.99;

function parseDispatchDecimal($value, $fieldName, $maximum, $allowZero = true)
{
    if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', trim($value))) {
        throw new InvalidArgumentException("$fieldName must be a valid number.");
    }

    $number = (float) $value;
    if (!is_finite($number) || (!$allowZero && $number <= 0) || ($allowZero && $number < 0)) {
        throw new InvalidArgumentException("$fieldName must be valid.");
    }
    if ($number > $maximum) {
        throw new InvalidArgumentException("$fieldName cannot exceed " . number_format($maximum, 2, '.', '') . '.');
    }

    return $number;
}

function parseDispatchDate($value)
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Dispatch date is invalid.');
    }

    $date = DateTime::createFromFormat('!Y-m-d', trim($value));
    if (!$date || $date->format('Y-m-d') !== trim($value)) {
        throw new InvalidArgumentException('Dispatch date must use YYYY-MM-DD format.');
    }

    return $date->format('Y-m-d');
}

// Get dispatchers (drivers)
$dispatchers = [];
try {
    $stmt = executeQuery("SELECT id, name, phone FROM drivers WHERE status = 'active' ORDER BY name");
    $dispatchers = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load dispatchers: " . $e->getMessage());
    $error = 'Failed to load dispatchers';
}

// Get vehicles
$vehicles = [];
try {
    $stmt = executeQuery("SELECT id, vehicle_number, vehicle_type, make, model FROM vehicles WHERE status = 'active' ORDER BY vehicle_number");
    $vehicles = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load vehicles: " . $e->getMessage());
    // Don't set error here as vehicles are optional for now
}

// Get products
$products = [];
try {
    $stmt = executeQuery("SELECT id, name, cost_price, quantity FROM products WHERE is_active = TRUE ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load products';
}

// Business details for receipt preview
$company_name = 'Merrylay Enterprises';
$company_phone = '';
$company_address = '';
try {
    $stmt = executeQuery("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_phone', 'company_address', 'company_name')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($settings['company_name'])) {
        $company_name = $settings['company_name'];
    }
    if (!empty($settings['company_phone'])) {
        $company_phone = $settings['company_phone'];
    }
    if (!empty($settings['company_address'])) {
        $company_address = $settings['company_address'];
    }
} catch (PDOException $e) {
    // ignore missing settings table or settings
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfToken = $_POST['csrf_token'] ?? null;
        if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
            throw new InvalidArgumentException('Invalid security token. Please refresh the page and try again.');
        }

        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        $trip_date = parseDispatchDate($_POST['trip_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];
        $discount_amounts = $_POST['discount_amount'] ?? [];
        $dispatcher_id = intval($_POST['dispatcher_id'] ?? 0);

        if ($dispatcher_id <= 0) {
            throw new InvalidArgumentException('Please select a dispatcher.');
        }
        if (!is_array($product_ids) || !is_array($quantities) || !is_array($unit_prices) || !is_array($discount_amounts) || empty($product_ids)) {
            throw new InvalidArgumentException('Please add at least one product.');
        }

        $stmt = executeQuery("SELECT id, name FROM drivers WHERE id = ? AND status = 'active'", [$dispatcher_id]);
        $dispatcher = $stmt->fetch();
        if (!$dispatcher) {
            throw new InvalidArgumentException('Invalid dispatcher selected.');
        }

        $items = [];
        $requestedStock = [];
        $expected_amount = 0.0;
        foreach ($product_ids as $index => $submitted_product_id) {
            if (!is_string($submitted_product_id) || !preg_match('/^[1-9][0-9]*$/', $submitted_product_id)) {
                throw new InvalidArgumentException('Invalid product selected.');
            }
            $product_id = (int) $submitted_product_id;
            if (array_key_exists($product_id, $requestedStock)) {
                throw new InvalidArgumentException('A product cannot appear more than once in the same dispatch.');
            }
            $qty = parseDispatchDecimal($quantities[$index] ?? null, 'Quantity', MAX_DISPATCH_QUANTITY, false);
            $price = parseDispatchDecimal($unit_prices[$index] ?? null, 'Unit price', MAX_DISPATCH_AMOUNT);
            $discount = parseDispatchDecimal($discount_amounts[$index] ?? null, 'Discount', MAX_DISPATCH_AMOUNT);
            $line_amount = $qty * $price;
            if (!is_finite($line_amount) || $line_amount > MAX_DISPATCH_AMOUNT) {
                throw new InvalidArgumentException('Dispatch amount exceeds the permitted limit.');
            }
            if ($discount > $line_amount) {
                throw new InvalidArgumentException('Discount cannot exceed the product line value.');
            }

            $items[] = [
                'product_id' => $product_id,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_amount' => $discount
            ];
            $requestedStock[$product_id] = $qty;
            $expected_amount += $line_amount;
            if (!is_finite($expected_amount) || $expected_amount > MAX_DISPATCH_AMOUNT) {
                throw new InvalidArgumentException('Expected dispatch amount exceeds the permitted limit.');
            }
        }

        $pdo = getDBConnection();
        $pdo->beginTransaction();

        $placeholders = implode(',', array_fill(0, count($requestedStock), '?'));
        $stmt = $pdo->prepare("SELECT id, name, quantity, is_active FROM products WHERE id IN ($placeholders) FOR UPDATE");
        $stmt->execute(array_keys($requestedStock));
        $productStock = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $productStock[(int) $row['id']] = $row;
        }
        foreach ($requestedStock as $product_id => $qty) {
            if (!isset($productStock[$product_id]) || !(int) $productStock[$product_id]['is_active']) {
                throw new InvalidArgumentException('One or more selected products are invalid or inactive.');
            }
            if ($qty > (float) $productStock[$product_id]['quantity']) {
                throw new InvalidArgumentException('Insufficient available stock for one or more selected products.');
            }
        }

        $trip_number = 'TRIP-' . date('Ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT INTO dispatches (dispatch_number, user_id, dispatcher_id, dispatcher_name, vehicle_id, expected_amount, actual_sales_amount, dispatch_date, sales_channel, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'dispatch', ?, NOW())");
        $stmt->execute([$trip_number, $_SESSION['user_id'], $dispatcher['id'], $dispatcher['name'], $vehicle_id > 0 ? $vehicle_id : null, $expected_amount, 0, $trip_date, $notes]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Dispatch could not be saved.');
        }
        $trip_id = $pdo->lastInsertId();
        if (!$trip_id) {
            throw new RuntimeException('Dispatch could not be identified after saving.');
        }

        foreach ($items as $item) {
            $stmt = $pdo->prepare("INSERT INTO dispatch_items (dispatch_id, product_id, quantity, unit_price, discount_amount) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$trip_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['discount_amount']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Dispatch item could not be saved.');
            }

            $stockManager->recordMovement(
                $item['product_id'],
                'dispatch',
                -$item['quantity'],
                'dispatch',
                $trip_id,
                "TRIP-$trip_number",
                'Dispatch transaction',
                $_SESSION['user_id'],
                $trip_date
            );
        }

        $pdo->commit();
        cache_clear();
        $_SESSION['trip_record_success'] = 'Trip recorded successfully';
        header('Location: record.php');
        exit();
    } catch (InvalidArgumentException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Dispatch creation error: ' . $e->getMessage());
        $error = 'Unable to record dispatch. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Record dispatch trips for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Record New Dispatch - Merrylay Enterprises</title>
    
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
                            <i class="fas fa-plus-circle" style="margin-right: 15px;"></i>
                            Record New Dispatch
                        </h1>
                        <p class="page-subtitle">
                            Select a dispatcher, add products for dispatch, and record the dispatch. Track inventory movement and manage dispatches efficiently.
                        </p>
                        <div class="d-flex gap-4 flex-wrap" style="margin-top: var(--space-3);">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-calendar-day" style="color: var(--primary-500);"></i>
                                <small><?php echo date('l, F j, Y'); ?></small>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-clock" style="color: var(--primary-500);"></i>
                                <small id="currentTime"><?php echo date('H:i'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Container with Receipt Preview -->
                <div class="row g-3">
                    <!-- Form Section -->
                    <div class="col-lg-8">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-truck" style="margin-right: 10px;"></i>
                                        Trip Information
                                    </h5>
                                    <p class="card-subtitle">Select dispatcher and enter trip details</p>
                                </div>
                            </div>
                            <div class="card-body">
                        <form method="POST" id="tripForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="dispatcher_id" class="form-label">Dispatcher <span style="color: var(--red); font-weight: 700;">*</span></label>
                                        <select name="dispatcher_id" id="dispatcher_id" class="form-select" required>
                                            <option value="">Select Dispatcher</option>
                                            <?php foreach ($dispatchers as $dispatcher): ?>
                                                <option value="<?php echo $dispatcher['id']; ?>">
                                                    <?php echo htmlspecialchars($dispatcher['name']); ?>
                                                    <?php if (!empty($dispatcher['phone'])): ?>
                                                        (<?php echo htmlspecialchars($dispatcher['phone']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="vehicle_id" class="form-label">Vehicle</label>
                                        <select name="vehicle_id" id="vehicle_id" class="form-select">
                                            <option value="">Select Vehicle (Optional)</option>
                                            <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?php echo $vehicle['id']; ?>">
                                                    <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                                    <?php if (!empty($vehicle['vehicle_type'])): ?>
                                                        - <?php echo htmlspecialchars($vehicle['vehicle_type']); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($vehicle['make']) || !empty($vehicle['model'])): ?>
                                                        (<?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="trip_date" class="form-label">Trip Date <span style="color: var(--red); font-weight: 700;">*</span></label>
                                        <input type="date" name="trip_date" id="trip_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5">
                                <h5 class="mb-4" style="color: var(--primary-800); font-weight: 600;">
                                    <i class="fas fa-boxes" style="margin-right: 10px;"></i>
                                    Products for This Trip
                                </h5>
                                <div id="productList">
                                    <div class="product-row row align-items-center">
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted" for="trip-product-0">Product</label>
                                            <div class="form-group mb-0">
                                                <select id="trip-product-0" name="product_id[]" class="form-select product-select" required>
                                                    <option value="">Select Product</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['cost_price']; ?>" data-stock="<?php echo $product['quantity']; ?>" <?php echo $product['quantity'] <= 0 ? 'disabled' : ''; ?>>
                                                            <?php echo htmlspecialchars($product['name']); ?><?php echo $product['quantity'] <= 0 ? ' (Out of stock)' : ''; ?> - ₦<?php echo number_format($product['cost_price'], 2); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted" for="trip-quantity-0">Quantity</label>
                                            <div class="form-group mb-0">
                                                <input type="number" id="trip-quantity-0" name="quantity[]" class="form-control quantity-input" placeholder="Qty" min="0.1" step="any" required>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted" for="trip-unit-price-0">Unit Price (₦)</label>
                                            <div class="form-group mb-0">
                                                <input type="number" id="trip-unit-price-0" name="unit_price[]" class="form-control price-input" placeholder="Unit Price" step="0.01" required>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted" for="trip-discount-0" style="color: var(--primary-600); font-weight: 600;">Discount (₦)</label>
                                            <div class="form-group mb-0">
                                                <input type="number" id="trip-discount-0" name="discount_amount[]" class="form-control discount-input" placeholder="Discount" step="0.01" min="0" value="0" style="border-color: var(--primary-300);">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small text-muted" for="trip-subtotal-0">Subtotal (₦)</label>
                                            <div class="form-group mb-0">
                                                <input type="text" id="trip-subtotal-0" class="form-control subtotal-display" placeholder="Subtotal" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
                                            </div>
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button type="button" class="btn-remove w-100" onclick="removeProduct(this)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
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
                                        <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Optional notes about this trip..."></textarea>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="total-box">
                                        <div class="total-content">
                                            <small class="d-block mb-2">Total Amount</small>
                                            <h3 class="mb-0">₦<span id="totalAmount">0.00</span></h3>
                                            <small style="opacity: 0.8;">Auto-calculated</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="action-buttons mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Record Trip
                                </button>
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                            </div>
                        </div>
                    </div>

                    <!-- Receipt Preview Section -->
                    <div class="col-lg-4">
                        <div class="content-card" style="position: sticky; top: 20px;">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-receipt"></i> Receipt Preview
                                    </h5>
                                    <p class="card-subtitle">Live preview of trip receipt</p>
                                </div>
                            </div>
                            <div class="card-body" style="max-height: 600px; overflow-y: auto; display: flex; justify-content: center;">
                                <div class="receipt" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; background: white; max-width: 300px; margin: 0 auto; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                                    <!-- Receipt Header -->
                                    <div class="receipt-header" style="text-align: center; border-bottom: 2px dashed #333; padding-bottom: 10px; margin-bottom: 15px;">
                                        <h1 style="font-size: 16px; font-weight: bold; margin-bottom: 5px;"><?php echo htmlspecialchars($company_name); ?></h1>
                                        <?php if (!empty($company_phone)): ?>
                                        <p style="font-size: 10px; color: #666; margin: 2px 0;"><?php echo htmlspecialchars($company_phone); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($company_address)): ?>
                                        <p style="font-size: 10px; color: #666; margin: 2px 0;"><?php echo htmlspecialchars($company_address); ?></p>
                                        <?php endif; ?>
                                        <p style="font-size: 10px; color: #666; margin: 2px 0;">DISPATCH TRIP RECEIPT</p>
                                    </div>

                                    <!-- Receipt Info -->
                                    <div class="receipt-info" style="margin-bottom: 15px;">
                                        <div class="receipt-info-row" style="display: flex; justify-content: space-between; margin: 5px 0;">
                                            <span class="receipt-info-label" style="color: #666; font-weight: bold;">Dispatcher:</span>
                                            <span id="preview-dispatcher">Select dispatcher...</span>
                                        </div>
                                        <div class="receipt-info-row" style="display: flex; justify-content: space-between; margin: 5px 0;">
                                            <span class="receipt-info-label" style="color: #666; font-weight: bold;">Date:</span>
                                            <span id="preview-date"><?php echo date('M d, Y'); ?></span>
                                        </div>
                                    </div>

                                    <!-- Receipt Items -->
                                    <div class="receipt-items" style="margin-bottom: 15px;">
                                        <div class="receipt-item" style="display: flex; justify-content: space-between; margin: 5px 0; border-bottom: 2px solid #333; padding-bottom: 5px; font-weight: bold;">
                                            <span class="receipt-item-name" style="flex: 1;">Item</span>
                                            <span class="receipt-item-qty" style="text-align: center; width: 40px;">Qty</span>
                                            <span class="receipt-item-discount" style="text-align: center; width: 50px;">Disc</span>
                                            <span class="receipt-item-price" style="text-align: right; width: 70px;">Total</span>
                                        </div>
                                        <div id="preview-items">
                                            <div class="receipt-item" style="display: flex; justify-content: space-between; margin: 5px 0; border-bottom: 1px dotted #ddd; padding-bottom: 5px;">
                                                <span class="receipt-item-name" style="flex: 1; color: #999;">Add products above...</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Receipt Total -->
                                    <div class="receipt-total" style="border-top: 2px dashed #333; padding-top: 10px; margin-top: 15px;">
                                        <div class="receipt-total-row" style="display: flex; justify-content: space-between; margin: 5px 0; font-weight: bold;">
                                            <span>TOTAL:</span>
                                            <span>₦<span id="preview-total-amount">0.00</span></span>
                                        </div>
                                    </div>

                                    <!-- Receipt Footer -->
                                    <div class="receipt-footer" style="text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px dashed #ddd;">
                                        <p style="font-size: 10px; color: #666; margin: 5px 0;">Thanks for your Patronage</p>
                                        <p style="font-size: 10px; color: #666; margin: 5px 0;">Processed by: Merrylay Enterprises</p>
                                    </div>

                                    <!-- Print Button -->
                                    <button type="button" onclick="printReceipt()" class="btn btn-success w-100 mt-3" style="font-size: 12px;">
                                        <i class="fas fa-print"></i> Print Receipt
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

    </div>

    <!-- Print Stylesheet -->
    <style media="print">
        body { background: white; padding: 0; }
        .sidebar, .top-bar, .page-header, .col-lg-8 { display: none !important; }
        .dashboard-container { margin: 0; padding: 0; }
        .dashboard-content { margin: 0; padding: 0; }
        .col-lg-4 { width: 100% !important; max-width: 100% !important; }
        .content-card { box-shadow: none; border: none; }
        .card-header { display: none; }
        .card-body { max-height: none !important; overflow: visible !important; display: block !important; }
        .receipt { box-shadow: none; max-width: 300px; width: 300px; margin: 0 auto; }
        .btn { display: none !important; }
        @page { margin: 10mm; }
    </style>

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

        // Driver selection update
        document.getElementById('driver_id')?.addEventListener('change', function() {
            const driverName = this.options[this.selectedIndex].text;
            document.getElementById('preview-driver').textContent = driverName === 'Select Dispatcher' ? 'Select Driver' : driverName;
        });

        // Trip date update
        document.getElementById('trip_date')?.addEventListener('change', function() {
            const date = new Date(this.value);
            const formatted = date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
            document.getElementById('preview-date').textContent = formatted;
        });

        // Product management functions
        let nextTripProductRowId = 1;

        function addProduct() {
            const row = document.querySelector('.product-row').cloneNode(true);
            const rowId = nextTripProductRowId++;
            const fieldIds = [
                `trip-product-${rowId}`,
                `trip-quantity-${rowId}`,
                `trip-unit-price-${rowId}`,
                `trip-discount-${rowId}`,
                `trip-subtotal-${rowId}`
            ];
            const fields = [
                row.querySelector('.product-select'),
                row.querySelector('.quantity-input'),
                row.querySelector('.price-input'),
                row.querySelector('.discount-input'),
                row.querySelector('.subtotal-display')
            ];
            row.querySelectorAll('.form-label').forEach((label, index) => {
                if (fieldIds[index]) {
                    label.htmlFor = fieldIds[index];
                }
            });
            fields.forEach((field, index) => {
                field.id = fieldIds[index];
            });
            row.querySelector('.product-select').value = '';
            row.querySelector('.quantity-input').value = '';
            row.querySelector('.price-input').value = '';
            row.querySelector('.discount-input').value = '0';
            row.querySelector('.subtotal-display').value = '';

            // Add animation
            row.style.opacity = '0';
            row.style.transform = 'translateY(-20px)';
            document.getElementById('productList').appendChild(row);

            // Animate in
            setTimeout(() => {
                row.style.transition = 'all 0.3s ease-out';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, 10);

            attachListeners();
            updateReceipt();
        }

        // Check for duplicate products
        function checkDuplicateProducts() {
            const selectedProducts = [];
            const rows = document.querySelectorAll('.product-row');
            let hasDuplicates = false;

            rows.forEach((row, index) => {
                const select = row.querySelector('.product-select');
                const productId = select.value;
                const productName = select.options[select.selectedIndex]?.text || '';

                if (productId && productId !== '') {
                    if (selectedProducts.includes(productId)) {
                        hasDuplicates = true;
                        select.style.borderColor = 'red';
                        select.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
                    } else {
                        selectedProducts.push(productId);
                        select.style.borderColor = '';
                        select.style.backgroundColor = '';
                    }
                }
            });

            return hasDuplicates;
        }

        function removeProduct(btn) {
            const rows = document.querySelectorAll('.product-row');
            if (rows.length > 1) {
                const row = btn.closest('.product-row');
                
                // Animate out
                row.style.transition = 'all 0.3s ease-out';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px) scale(0.95)';
                
                setTimeout(() => {
                    row.remove();
                    calculateTotal();
                    updateReceipt();
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
                
                // Update subtotal display for this row
                row.querySelector('.subtotal-display').value = subtotal.toFixed(2);
                
                // Add total value without discount for dispatch amount
                total += (qty * price);
            });
            
            const totalElement = document.getElementById('totalAmount');
            const currentValue = parseFloat(totalElement.textContent.replace(/,/g, '')) || 0;
            
            // Animate total change
            if (currentValue !== total) {
                animateValue(totalElement, currentValue, total, 300);
            }
        }

        function updateReceipt() {
            let totalAmount = 0;
            let itemsHtml = '';

            document.querySelectorAll('.product-row').forEach(row => {
                const productSelect = row.querySelector('.product-select');
                const productName = productSelect.options[productSelect.selectedIndex]?.text || '';
                const qty = parseFloat(row.querySelector('.quantity-input').value) || 0;
                const price = parseFloat(row.querySelector('.price-input').value) || 0;
                const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
                const subtotal = (qty * price) - discount;

                if (qty > 0 && price > 0 && productName && productName !== 'Select Product') {
                    totalAmount += subtotal;
                    // Extract just product name without price
                    const productNameOnly = productName.split(' - ₦')[0];
                    itemsHtml += `
                        <div class="receipt-item" style="display: flex; justify-content: space-between; margin: 5px 0; border-bottom: 1px dotted #ddd; padding-bottom: 5px;">
                            <span class="receipt-item-name" style="flex: 1;">${productNameOnly}</span>
                            <span class="receipt-item-qty" style="text-align: center; width: 40px;">${qty.toFixed(2)}</span>
                            <span class="receipt-item-discount" style="text-align: center; width: 50px;">${discount > 0 ? '₦' + discount.toFixed(2) : '-'}</span>
                            <span class="receipt-item-price" style="text-align: right; width: 70px;">₦${subtotal.toFixed(2)}</span>
                        </div>
                    `;
                }
            });

            // Update receipt items
            if (itemsHtml) {
                document.getElementById('preview-items').innerHTML = itemsHtml;
            } else {
                document.getElementById('preview-items').innerHTML = '<div class="receipt-item" style="display: flex; justify-content: space-between; margin: 5px 0; border-bottom: 1px dotted #ddd; padding-bottom: 5px;"><span class="receipt-item-name" style="flex: 1; color: #999;">Add products above...</span></div>';
            }

            // Update total
            document.getElementById('preview-total-amount').textContent = totalAmount.toFixed(2);
        }

        function animateValue(element, start, end, duration) {
            const range = end - start;
            const increment = range / (duration / 16);
            let current = start;
            
            const timer = setInterval(() => {
                current += increment;
                if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
                    current = end;
                    clearInterval(timer);
                }
                element.textContent = current.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }, 16);
        }

        function printReceipt() {
            window.print();
        }

        function attachListeners() {
            document.querySelectorAll('.product-select').forEach(select => {
                select.onchange = function() {
                    const price = this.options[this.selectedIndex].dataset.price;
                    if (price) {
                        const priceInput = this.closest('.product-row').querySelector('.price-input');
                        priceInput.value = price;

                        // Add highlight effect
                        priceInput.style.transition = 'all 0.3s ease';
                        priceInput.style.background = 'rgba(99, 102, 241, 0.1)';
                        setTimeout(() => {
                            priceInput.style.background = '';
                        }, 500);

                        calculateTotal();
                        updateReceipt();
                    }

                    // Check for duplicates
                    checkDuplicateProducts();
                };
            });

            document.querySelectorAll('.quantity-input, .price-input, .discount-input').forEach(input => {
                input.oninput = function() {
                    calculateTotal();
                    updateReceipt();
                };
            });

            // Dispatcher dropdown listener
            const dispatcherSelect = document.getElementById('dispatcher_id');
            if (dispatcherSelect) {
                dispatcherSelect.onchange = function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const dispatcherName = selectedOption.text || 'Select dispatcher...';
                    document.getElementById('preview-dispatcher').textContent = dispatcherName;
                };
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            attachListeners();
            updateReceipt();
            
            // Update current time (GMT+1)
            function updateTime() {
                const now = new Date();
                // Convert to GMT+1 (West Africa Time)
                const gmtPlus1 = new Date(now.toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
                const timeElement = document.getElementById('currentTime');
                if (timeElement) {
                    timeElement.textContent = gmtPlus1.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
                }
            }

            setInterval(updateTime, 1000);

            // Initial time update
            updateTime();
            
            // Form submission animation
            document.getElementById('tripForm').addEventListener('submit', function(e) {
                // Check for duplicate products before submission
                if (checkDuplicateProducts()) {
                    e.preventDefault();
                    alert('Please remove duplicate products before submitting. Each product can only be added once per trip.');
                    return false;
                }

                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording...';
                submitBtn.disabled = true;
            });
        });
    </script>
</body>
</html>
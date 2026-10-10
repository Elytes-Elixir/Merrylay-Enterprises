<?php
/**
 * Direct Sales Interface
 * 
 * Pure sales interface for shop retail
 * Records sales directly with product inventory tracking
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
require_once '../config/cache.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Restrict access to shop attendants only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isShopAttendant()) {
    error_log('Unauthorized shop sale by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to record shop sales.');
}
requireShopAttendant();

$error = '';
$success = $_SESSION['shop_sales_success'] ?? '';
unset($_SESSION['shop_sales_success']);
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected shop sale: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the sale and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Get products
$products = [];
try {
    $stmt = executeQuery("SELECT id, name, unit, cost_price, quantity FROM products WHERE is_active = TRUE AND quantity > 0 ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load products';
}

// Business details for receipt
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
    // ignore missing settings
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_sale']) && $csrfValid) {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $payment_type = $_POST['payment_type'] ?? 'single';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_types = $_POST['unit_type'] ?? [];
    $normalized_unit_types = [];
    $unit_types_valid = is_array($product_ids) && is_array($unit_types)
        && count($unit_types) === count($product_ids);
    if ($unit_types_valid) {
        foreach ($product_ids as $index => $_product_id) {
            $submitted_unit_type = $unit_types[$index] ?? null;
            if (!is_string($submitted_unit_type)) {
                $unit_types_valid = false;
                break;
            }

            $unit_type = strtolower(trim($submitted_unit_type));
            if ($unit_type === 'pack') {
                $unit_type = 'pieces';
            }
            if (!in_array($unit_type, ['pieces', 'crate'], true)) {
                $unit_types_valid = false;
                break;
            }
            $normalized_unit_types[$index] = $unit_type;
        }
    }
    if ($unit_types_valid) {
        $unit_types = $normalized_unit_types;
    }

    // Split payment amounts
    $payment_cash = floatval($_POST['payment_cash'] ?? 0);
    $payment_transfer = floatval($_POST['payment_transfer'] ?? 0);
    $payment_card = floatval($_POST['payment_card'] ?? 0);
    $payment_credit = floatval($_POST['payment_credit'] ?? 0);

    // Validate customer name for credit sales (client-side validation handles this, server-side is backup)
    if (empty($product_ids) || empty(array_filter($quantities))) {
        $error = 'Please add at least one product';
    } elseif (!$unit_types_valid) {
        $error = 'One or more product units are invalid. Please choose Pieces or Crate.';
        $preserve_data = [
            'customer_name' => $customer_name,
            'payment_type' => $payment_type,
            'payment_method' => $payment_method,
            'product_ids' => $product_ids,
            'quantities' => $quantities,
            'unit_types' => $unit_types,
            'payment_cash' => $payment_cash,
            'payment_transfer' => $payment_transfer,
            'payment_card' => $payment_card,
            'payment_credit' => $payment_credit
        ];
    } elseif ($payment_type === 'single' && $payment_method === 'credit' && empty($customer_name)) {
        $error = 'Customer name is required for credit sales';
        // Preserve form data for re-rendering
        $preserve_data = [
            'customer_name' => $customer_name,
            'payment_type' => $payment_type,
            'payment_method' => $payment_method,
            'product_ids' => $product_ids,
            'quantities' => $quantities,
            'unit_types' => $unit_types,
            'payment_cash' => $payment_cash,
            'payment_transfer' => $payment_transfer,
            'payment_card' => $payment_card,
            'payment_credit' => $payment_credit
        ];
    } elseif ($payment_type === 'split' && $payment_credit > 0 && empty($customer_name)) {
        $error = 'Customer name is required when using credit payment';
        // Preserve form data for re-rendering
        $preserve_data = [
            'customer_name' => $customer_name,
            'payment_type' => $payment_type,
            'payment_method' => $payment_method,
            'product_ids' => $product_ids,
            'quantities' => $quantities,
            'unit_types' => $unit_types,
            'payment_cash' => $payment_cash,
            'payment_transfer' => $payment_transfer,
            'payment_card' => $payment_card,
            'payment_credit' => $payment_credit
        ];
    } else {
        try {
            // Ensure daily stock has been initialized for today before recording any sales
            $stockManager = getStockManager();
            if (!$stockManager->isDailyStockInitialized(date('Y-m-d'))) {
                if (!initializeTodayStock()) {
                    throw new Exception('Failed to initialize daily stock. Please ensure products have been added to the system before recording sales. Go to Products > View Products to add products.');
                }
            }

            // Validate stock availability
            $requestedStock = [];
            foreach ($product_ids as $index => $product_id) {
                $product_id = intval($product_id);
                $qty = floatval($quantities[$index] ?? 0);
                if ($product_id > 0 && $qty > 0) {
                    $requestedStock[$product_id] = ($requestedStock[$product_id] ?? 0) + $qty;
                }
            }

            // Calculate total amount
            $total_amount = 0;
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $stmt = executeQuery("SELECT cost_price FROM products WHERE id = ?", [$product_id]);
                    $product = $stmt->fetch();
                    $price = $product['cost_price'];
                    $total_amount += $qty * $price;
                }
            }

            // Calculate surplus amount for split payments
            $surplus_amount = 0;
            if ($payment_type === 'split') {
                $split_total = $payment_cash + $payment_transfer + $payment_card + $payment_credit;
                if ($split_total > $total_amount) {
                    $surplus_amount = $split_total - $total_amount;
                }
            }

            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // Validate stock availability WITH ROW LOCKING
            // This prevents race conditions where multiple users could validate simultaneously
            if (!empty($requestedStock)) {
                $placeholders = implode(',', array_fill(0, count($requestedStock), '?'));
                $stmt = $pdo->prepare("SELECT id, name, quantity FROM products WHERE id IN ($placeholders) FOR UPDATE");
                $stmt->execute(array_keys($requestedStock));
                $productStock = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $productStock[$row['id']] = $row;
                }

                foreach ($requestedStock as $product_id => $qty) {
                    if (!isset($productStock[$product_id])) {
                        throw new Exception('One or more selected products are invalid.');
                    }

                    $product = $productStock[$product_id];
                    if ($product['quantity'] <= 0) {
                        throw new Exception('Product "' . htmlspecialchars($product['name']) . '" is out of stock.');
                    }

                    if ($qty > $product['quantity']) {
                        throw new Exception('Only ' . $product['quantity'] . ' unit(s) of "' . htmlspecialchars($product['name']) . '" are available.');
                    }
                }
            }

            // Create direct sales record
            $sale_number = 'SALE-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $sale_date = date('Y-m-d');

            // Insert into dispatches table with shop_sales flag
            $stmt = $pdo->prepare("
                INSERT INTO dispatches (dispatch_number, user_id, dispatcher_id, dispatcher_name, expected_amount, actual_sales_amount, dispatch_date, sales_channel, notes, created_at)
                VALUES (?, ?, ?, 'Shop Sales', ?, ?, ?, 'shop_sales', ?, NOW())
            ");
            $stmt->execute([$sale_number, $_SESSION['user_id'], $_SESSION['user_id'], $total_amount, $total_amount, $sale_date, $customer_name ? "Customer: $customer_name" : 'Walk-in customer']);
            $sale_id = $pdo->lastInsertId();

            // Create shop_sales record for payment tracking
            $shop_sale_stmt = $pdo->prepare("
                INSERT INTO shop_sales (sale_number, sale_date, customer_name, total_amount, discount_amount, surplus_amount, payment_method, payment_split, recorded_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $shop_sale_stmt->execute([
                $sale_number,
                $sale_date,
                $customer_name ?: 'Walk-in customer',
                $total_amount,
                0,
                $surplus_amount,
                $payment_method,
                $payment_type === 'split' ? 1 : 0,
                $_SESSION['user_id'],
                $customer_name ? "Customer: $customer_name" : 'Walk-in customer'
            ]);
            $shop_sale_id = $pdo->lastInsertId();

            // Handle split payments
            if ($payment_type === 'split') {
                $split_total = $payment_cash + $payment_transfer + $payment_card + $payment_credit;
                
                // VALIDATION: Split total must be at least equal to sale amount (allow surplus)
                if ($split_total < $total_amount - 0.01) {
                    throw new Exception('Split payment total ₦' . number_format($split_total, 2) . ' is less than sale amount ₦' . number_format($total_amount, 2) . '. Please adjust payment amounts.');
                }

                // VALIDATION: Check for existing payment records (prevent duplicates)
                $check_stmt = $pdo->prepare("SELECT COUNT(*) as record_count FROM shop_sale_payments WHERE shop_sale_id = ?");
                $check_stmt->execute([$shop_sale_id]);
                $check_result = $check_stmt->fetch();
                if ($check_result['record_count'] > 0) {
                    throw new Exception('Payment records already exist for this sale. Duplicate submission detected.');
                }

                // Save each payment method
                if ($payment_cash > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, notes)
                        VALUES (?, 'cash', ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $payment_cash, 'Cash payment']);
                }
                
                if ($payment_transfer > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, notes)
                        VALUES (?, 'transfer', ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $payment_transfer, 'Transfer payment']);
                }
                
                if ($payment_card > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, notes)
                        VALUES (?, 'card', ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $payment_card, 'Card payment']);
                }
                
                // Handle credit payment in split
                if ($payment_credit > 0 && !empty($customer_name)) {
                    $creditor_notes = "Shop credit sale #$sale_number on {$sale_date}. ₦$payment_credit sold on credit to customer.";
                    
                    $creditor_stmt = $pdo->prepare("
                        INSERT INTO creditors 
                        (creditor_name, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, notes, status, created_by, created_at)
                        VALUES (?, ?, 0, ?, ?, 0, 'Shop Sales', ?, 'active', ?, NOW())
                    ");
                    $creditor_stmt->execute([
                        $customer_name,
                        $payment_credit,
                        $payment_credit,
                        $sale_date,
                        $creditor_notes,
                        $_SESSION['user_id']
                    ]);
                    $creditor_id = $pdo->lastInsertId();
                    
                    // Save credit payment with creditor link
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, creditor_id, notes)
                        VALUES (?, 'credit', ?, ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $payment_credit, $creditor_id, 'Credit payment']);
                    
                    // Link the shop sale to the creditor
                    $link_stmt = $pdo->prepare("UPDATE shop_sales SET creditor_id = ? WHERE id = ?");
                    $link_stmt->execute([$creditor_id, $shop_sale_id]);
                }
            } else {
                // Handle single payment method
                // Prevent duplicate payment records if this sale was already recorded
                $check_stmt = $pdo->prepare("SELECT COUNT(*) as record_count FROM shop_sale_payments WHERE shop_sale_id = ?");
                $check_stmt->execute([$shop_sale_id]);
                $check_result = $check_stmt->fetch();
                if ($check_result['record_count'] > 0) {
                    throw new Exception('Payment records already exist for this sale. Duplicate submission detected.');
                }

                // If payment method is credit, create a creditor entry
                $creditor_id = null;
                if ($payment_method === 'credit' && !empty($customer_name)) {
                    $creditor_notes = "Shop credit sale #$sale_number on {$sale_date}. Products sold on credit to customer.";
                    
                    $creditor_stmt = $pdo->prepare("
                        INSERT INTO creditors 
                        (creditor_name, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, notes, status, created_by, created_at)
                        VALUES (?, ?, 0, ?, ?, 0, 'Shop Sales', ?, 'active', ?, NOW())
                    ");
                    $creditor_stmt->execute([
                        $customer_name,
                        $total_amount,
                        $total_amount,
                        $sale_date,
                        $creditor_notes,
                        $_SESSION['user_id']
                    ]);
                    $creditor_id = $pdo->lastInsertId();
                    
                    // Link the shop sale to the creditor
                    $link_stmt = $pdo->prepare("UPDATE shop_sales SET creditor_id = ? WHERE id = ?");
                    $link_stmt->execute([$creditor_id, $shop_sale_id]);
                    
                    // Save single payment
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, creditor_id, notes)
                        VALUES (?, 'credit', ?, ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $total_amount, $creditor_id, 'Credit payment']);
                } else {
                    // Save single payment for cash/transfer/card
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, notes)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $payment_method, $total_amount, ucfirst($payment_method) . ' payment']);
                }
            }

            // Add sales items to both dispatch_items and shop_sales_items
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $unit_type = $unit_types[$index];
                    $stmt = executeQuery("SELECT cost_price FROM products WHERE id = ?", [$product_id]);
                    $product = $stmt->fetch();
                    $price = $product['cost_price'];

                    // Save to dispatch_items (for dispatch tracking)
                    $stmt = $pdo->prepare("
                        INSERT INTO dispatch_items (dispatch_id, product_id, quantity, unit_price, discount_amount)
                        VALUES (?, ?, ?, ?, 0)
                    ");
                    $stmt->execute([$sale_id, $product_id, $qty, $price]);

                    // Save to shop_sales_items (for shop unit tracking)
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sales_items (shop_sale_id, product_id, quantity, unit_price, unit_type)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $product_id, $qty, $price, $unit_type]);
                }
            }

            // Record stock movements using StockManager for automatic recalculation
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $stockManager->recordMovement(
                        $product_id,
                        'dispatch',
                        -$qty,
                        'dispatch',
                        $sale_id,
                        "SALE-$sale_number",
                        "Shop sale transaction",
                        $_SESSION['user_id'],
                        $sale_date
                    );
                }
            }

            $pdo->commit();
            cache_clear();
            
            $success_message = "Sale recorded successfully! Receipt #: $sale_number";
            if ($payment_method === 'credit') {
                $success_message .= " (Credit recorded to dashboard for customer: $customer_name)";
            }
            $_SESSION['shop_sales_success'] = "$success_message <a href='receipt.php?id=$sale_id' class='btn btn-sm btn-primary ms-2' target='_blank'><i class='fas fa-receipt'></i> View Receipt</a>";
            header('Location: sales.php');
            exit();
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to record sale: ' . $e->getMessage();
        }
    }
}

// Get recent direct sales for today only
$recent_sales = [];
try {
    $stmt = executeQuery("
        SELECT d.dispatch_number, d.dispatch_date, d.expected_amount, d.actual_sales_amount, d.created_at
        FROM dispatches d
        WHERE d.sales_channel = 'shop_sales'
          AND DATE(d.dispatch_date) = CURDATE()
        ORDER BY d.created_at DESC
        LIMIT 10
    ");
    $recent_sales = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load recent sales: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Direct Sales - Merrylay Enterprises</title>
    
    <!-- CSS Framework -->
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        .product-row {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
        }
        
        .product-row:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--border-dark);
        }
        
        .total-box {
            background: var(--blue);
            color: white;
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow-lg);
        }
        
        .btn-add {
            background: var(--green);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: var(--space-3) var(--space-4);
            font-weight: 600;
            transition: all var(--transition-normal);
        }
        
        .btn-add:hover {
            background: var(--green-dark);
            transform: translateY(-2px);
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
        }
        
        .btn-remove:hover {
            background: var(--red-dark);
            transform: scale(1.1);
            color: white;
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
                    <a href="history.php" class="nav-link">
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
                        <input type="text" placeholder="Search products..." id="productSearch">
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
                            <i class="fas fa-shopping-cart" style="margin-right: 15px;"></i>
                            Direct Sales
                        </h1>
                        <p class="page-subtitle">
                            Record sales directly - no trip selection needed
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

                <!-- Form Container -->
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-shopping-cart" style="margin-right: 10px;"></i>
                                        New Sales
                                    </h5>
                                    <p class="card-subtitle">Select products and record sale</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="saleForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="mt-5">
                                        <h5 class="mb-4" style="color: var(--primary-800); font-weight: 600;">
                                            <i class="fas fa-boxes" style="margin-right: 10px;"></i>
                                            Products
                                        </h5>
                                        <div id="productList">
                                            <div class="product-row row align-items-center">
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted" for="direct-sale-product-0">Product</label>
                                                    <select id="direct-sale-product-0" name="product_id[]" class="form-select product-select" required>
                                                        <option value="">Select Product</option>
                                                        <?php foreach ($products as $product): ?>
                                                            <option value="<?php echo $product['id']; ?>" 
                                                                    data-price="<?php echo $product['cost_price']; ?>" 
                                                                    data-stock="<?php echo $product['quantity']; ?>">
                                                                <?php echo htmlspecialchars($product['name']); ?> - ₦<?php echo number_format($product['cost_price'], 2); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="direct-sale-unit-0">Unit Type</label>
                                                    <select id="direct-sale-unit-0" name="unit_type[]" class="form-select unit-type-select">
                                                        <option value="pieces">Pieces</option>
                                                        <option value="crate">Crate</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="direct-sale-quantity-0">Quantity</label>
                                                    <input type="number" id="direct-sale-quantity-0" name="quantity[]" class="form-control quantity-input" placeholder="Qty" min="0.1" step="any" required>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="direct-sale-price-0">Price (₦)</label>
                                                    <input type="text" id="direct-sale-price-0" class="form-control price-display" placeholder="Price" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="direct-sale-subtotal-0">Subtotal (₦)</label>
                                                    <input type="text" id="direct-sale-subtotal-0" class="form-control subtotal-display" placeholder="Subtotal" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
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
                                        <div class="col-md-12">
                                            <div class="total-box">
                                                <small class="d-block mb-2">Total Amount</small>
                                                <h3 class="mb-0">₦<span id="totalAmount">0.00</span></h3>
                                                <small style="opacity: 0.8;">Auto-calculated</small>
                                            </div>
                                        </div>
                                    </div>

                                    <hr class="my-5">

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="customer_name" class="form-label">Customer Name <span id="customerNameRequired">*</span></label>
                                                <input type="text" name="customer_name" id="customer_name" class="form-control" placeholder="Walk-in customer" value="<?php echo isset($preserve_data['customer_name']) ? htmlspecialchars($preserve_data['customer_name']) : ''; ?>">
                                                <small class="text-muted" id="customerNameHint">Required for credit sales</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="payment_type" class="form-label">Payment Type</label>
                                                <select name="payment_type" id="payment_type" class="form-select" onchange="togglePaymentType()">
                                                    <option value="single" <?php echo (isset($preserve_data['payment_type']) && $preserve_data['payment_type'] === 'single') ? 'selected' : ''; ?>>Single Payment Method</option>
                                                    <option value="split" <?php echo (isset($preserve_data['payment_type']) && $preserve_data['payment_type'] === 'split') ? 'selected' : ''; ?>>Split Payment (Multiple Methods)</option>
                                                </select>
                                                <small class="text-muted" id="paymentTypeHint">Select payment type</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Single Payment Method -->
                                    <div id="singlePaymentSection" class="row mt-3">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label for="payment_method" class="form-label">Payment Method</label>
                                                <select name="payment_method" id="payment_method" class="form-select" onchange="toggleCustomerName()">
                                                    <option value="cash" <?php echo (isset($preserve_data['payment_method']) && $preserve_data['payment_method'] === 'cash') ? 'selected' : ''; ?>>Cash</option>
                                                    <option value="transfer" <?php echo (isset($preserve_data['payment_method']) && $preserve_data['payment_method'] === 'transfer') ? 'selected' : ''; ?>>Transfer</option>
                                                    <option value="card" <?php echo (isset($preserve_data['payment_method']) && $preserve_data['payment_method'] === 'card') ? 'selected' : ''; ?>>Card</option>
                                                    <option value="credit" <?php echo (isset($preserve_data['payment_method']) && $preserve_data['payment_method'] === 'credit') ? 'selected' : ''; ?>>Credit (Record to Dashboard)</option>
                                                </select>
                                                <small class="text-muted" id="paymentMethodHint">Select how the customer will pay</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Split Payment Methods -->
                                    <div id="splitPaymentSection" class="mt-3" style="display: none;">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> Enter payment amounts for each method. Total must equal the sale amount.
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="payment_cash" class="form-label">Cash Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" name="payment_cash" id="payment_cash" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()" value="<?php echo isset($preserve_data['payment_cash']) ? number_format($preserve_data['payment_cash'], 2, '.', '') : ''; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="payment_transfer" class="form-label">Transfer Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" name="payment_transfer" id="payment_transfer" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()" value="<?php echo isset($preserve_data['payment_transfer']) ? number_format($preserve_data['payment_transfer'], 2, '.', '') : ''; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="payment_card" class="form-label">Card Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" name="payment_card" id="payment_card" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()" value="<?php echo isset($preserve_data['payment_card']) ? number_format($preserve_data['payment_card'], 2, '.', '') : ''; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="payment_credit" class="form-label">Credit Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" name="payment_credit" id="payment_credit" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()" value="<?php echo isset($preserve_data['payment_credit']) ? number_format($preserve_data['payment_credit'], 2, '.', '') : ''; ?>">
                                                    </div>
                                                    <small class="text-muted" id="creditPaymentHint">Requires customer name</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <div class="col-12">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <strong>Split Payment Total:</strong>
                                                    <strong id="splitPaymentTotal">₦0.00</strong>
                                                </div>
                                                <small class="text-muted" id="splitPaymentStatus"></small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="action-buttons mt-4">
                                        <button type="button" name="record_sale" class="btn btn-primary" onclick="submitSaleForm()">
                                            <i class="fas fa-save"></i>
                                            Complete Sale
                                        </button>
                                        <button type="reset" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Sales -->
                    <div class="col-lg-4">
                        <div class="content-card" style="position: sticky; top: 20px;">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-history"></i> Recent Sales
                                    </h5>
                                    <p class="card-subtitle">Today's direct sales</p>
                                </div>
                            </div>
                            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                                <?php if (count($recent_sales) > 0): ?>
                                    <?php foreach ($recent_sales as $sale): ?>
                                        <div style="padding: 10px; border-bottom: 1px solid var(--border-light);">
                                            <div style="font-weight: 600; color: var(--primary-800);"><?php echo htmlspecialchars($sale['dispatch_number']); ?></div>
                                            <div style="font-size: 12px; color: var(--primary-600);"><?php echo date('M d, Y H:i', strtotime($sale['created_at'])); ?></div>
                                            <div style="font-weight: 600; color: var(--green);">₦<?php echo number_format($sale['actual_sales_amount'], 2); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center">No sales recorded today</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
    <script>
        function togglePaymentType() {
            const paymentType = document.getElementById('payment_type').value;
            const singleSection = document.getElementById('singlePaymentSection');
            const splitSection = document.getElementById('splitPaymentSection');
            const customerNameInput = document.getElementById('customer_name');
            const customerNameRequired = document.getElementById('customerNameRequired');
            const customerNameHint = document.getElementById('customerNameHint');

            if (paymentType === 'split') {
                singleSection.style.display = 'none';
                splitSection.style.display = 'block';
                // Customer name is optional for split unless credit is used
                customerNameInput.required = false;
                customerNameInput.placeholder = 'Walk-in customer';
                customerNameRequired.textContent = '(Optional)';
                customerNameRequired.style.color = 'inherit';
                customerNameHint.textContent = 'Required only if using credit payment';
                customerNameHint.style.color = 'inherit';
            } else {
                singleSection.style.display = 'block';
                splitSection.style.display = 'none';
                // Reset to single payment behavior
                toggleCustomerName();
            }
        }

        function calculateSplitTotal() {
            const cash = parseFloat(document.getElementById('payment_cash').value) || 0;
            const transfer = parseFloat(document.getElementById('payment_transfer').value) || 0;
            const card = parseFloat(document.getElementById('payment_card').value) || 0;
            const credit = parseFloat(document.getElementById('payment_credit').value) || 0;
            
            const total = cash + transfer + card + credit;
            document.getElementById('splitPaymentTotal').textContent = '₦' + total.toFixed(2);
            
            // Get the expected amount from the sale
            const status = document.getElementById('splitPaymentStatus');
            const creditHint = document.getElementById('creditPaymentHint');
            
            if (credit > 0) {
                creditHint.textContent = 'Requires customer name';
                creditHint.style.color = '#dc3545';
            } else {
                creditHint.textContent = 'Optional';
                creditHint.style.color = 'inherit';
            }
            
            // Calculate total from product rows
            const rows = document.querySelectorAll('.product-row');
            let expectedAmount = 0;
            rows.forEach(row => {
                const subtotal = parseFloat(row.querySelector('.subtotal-display')?.value) || 0;
                expectedAmount += subtotal;
            });
            
            if (expectedAmount > 0) {
                const difference = total - expectedAmount;
                if (Math.abs(difference) < 0.01) {
                    status.textContent = '✓ Payment total matches sale amount';
                    status.style.color = '#10b981';
                } else if (difference < 0) {
                    status.textContent = '⚠ Payment total is ₦' + Math.abs(difference).toFixed(2) + ' short';
                    status.style.color = '#f59e0b';
                } else {
                    status.textContent = '✓ Surplus of ₦' + difference.toFixed(2) + ' will be recorded';
                    status.style.color = '#10b981';
                }
            }
        }

        function toggleCustomerName() {
            const paymentMethod = document.getElementById('payment_method').value;
            const customerNameInput = document.getElementById('customer_name');
            const customerNameRequired = document.getElementById('customerNameRequired');
            const customerNameHint = document.getElementById('customerNameHint');
            const paymentMethodHint = document.getElementById('paymentMethodHint');

            if (paymentMethod === 'credit') {
                customerNameInput.required = true;
                customerNameInput.placeholder = 'Customer name (Required)';
                customerNameRequired.textContent = '*';
                customerNameRequired.style.color = 'red';
                customerNameHint.textContent = 'Required for credit sales - will be recorded in credit dashboard';
                customerNameHint.style.color = '#dc3545';
                paymentMethodHint.textContent = 'Credit will be recorded to dashboard against customer name';
                paymentMethodHint.style.color = '#dc3545';
            } else {
                customerNameInput.required = false;
                customerNameInput.placeholder = 'Walk-in customer';
                customerNameRequired.textContent = '(Optional)';
                customerNameRequired.style.color = 'inherit';
                customerNameHint.textContent = 'Optional for cash & transfer/card sales';
                customerNameHint.style.color = 'inherit';
                paymentMethodHint.textContent = 'Select how the customer will pay';
                paymentMethodHint.style.color = 'inherit';
            }
        }

        function validateCustomerName() {
            const paymentType = document.getElementById('payment_type').value;
            const paymentMethod = document.getElementById('payment_method').value;
            const customerName = document.getElementById('customer_name').value.trim();
            const paymentCredit = parseFloat(document.getElementById('payment_credit')?.value) || 0;

            // Check if customer name is required
            let isCustomerNameRequired = false;
            if (paymentType === 'single' && paymentMethod === 'credit') {
                isCustomerNameRequired = true;
            } else if (paymentType === 'split' && paymentCredit > 0) {
                isCustomerNameRequired = true;
            }

            if (isCustomerNameRequired && !customerName) {
                alert('Customer name is required for credit sales. Please enter the customer name before completing the sale.');
                document.getElementById('customer_name').focus();
                return false; // Prevent form submission
            }

            return true; // Allow form submission
        }

        function submitSaleForm() {
            // Validate customer name before submitting
            if (!validateCustomerName()) {
                return; // Don't submit if validation fails
            }

            // Submit the form
            document.getElementById('saleForm').submit();
        }

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


            // Restore preserved form data if exists
            <?php if (isset($preserve_data) && !empty($preserve_data['product_ids'])): ?>
                restorePreservedData();
            <?php else: ?>
                // Initialize payment type toggle only if no preserved data
                togglePaymentType();
            <?php endif; ?>
        });

        function restorePreservedData() {
            const preservedData = <?php echo json_encode($preserve_data ?? []); ?>;
            if (!preservedData || !preservedData.product_ids) return;

            const productList = document.getElementById('productList');
            const productIds = preservedData.product_ids;
            const quantities = preservedData.quantities || [];
            const unitTypes = preservedData.unit_types || [];

            // Clear existing product rows except the first one
            const rows = productList.querySelectorAll('.product-row');
            for (let i = rows.length - 1; i > 0; i--) {
                rows[i].remove();
            }

            // Restore product data
            for (let i = 0; i < productIds.length; i++) {
                if (i > 0) {
                    addProduct();
                }

                const currentRows = productList.querySelectorAll('.product-row');
                const row = currentRows[i];

                if (row) {
                    const productSelect = row.querySelector('.product-select');
                    const unitTypeSelect = row.querySelector('.unit-type-select');
                    const quantityInput = row.querySelector('.quantity-input');

                    if (productSelect && productIds[i]) {
                        productSelect.value = productIds[i];
                        // Trigger change to update price
                        productSelect.dispatchEvent(new Event('change'));
                    }

                    if (unitTypeSelect && unitTypes[i]) {
                        unitTypeSelect.value = unitTypes[i];
                    }

                    if (quantityInput && quantities[i]) {
                        quantityInput.value = quantities[i];
                        // Trigger input to update subtotal
                        quantityInput.dispatchEvent(new Event('input'));
                    }
                }
            }

            // Recalculate total
            calculateTotal();

            // Restore payment type toggle state
            if (preservedData.payment_type) {
                // First set the payment type value
                document.getElementById('payment_type').value = preservedData.payment_type;
                // Then toggle to show correct section
                togglePaymentType();
            }

            // If split payment, recalculate split total
            if (preservedData.payment_type === 'split') {
                calculateSplitTotal();
            }
        }
    </script>
    
    <script>
        let nextDirectSaleRowId = 1;

        function addProduct() {
            const container = document.getElementById('productList');
            const rowId = `direct-sale-${nextDirectSaleRowId++}`;
            const newRow = document.createElement('div');
            newRow.className = 'product-row row align-items-center';
            newRow.innerHTML = `
                <div class="col-md-4">
                    <label class="form-label small text-muted" for="${rowId}-product">Product</label>
                    <select id="${rowId}-product" name="product_id[]" class="form-select product-select" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" 
                                    data-price="<?php echo $product['cost_price']; ?>" 
                                    data-stock="<?php echo $product['quantity']; ?>">
                                <?php echo htmlspecialchars($product['name']); ?> - ₦<?php echo number_format($product['cost_price'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-unit">Unit Type</label>
                    <select id="${rowId}-unit" name="unit_type[]" class="form-select unit-type-select">
                        <option value="pieces">Pieces</option>
                        <option value="crate">Crate</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-quantity">Quantity</label>
                    <input type="number" id="${rowId}-quantity" name="quantity[]" class="form-control quantity-input" placeholder="Qty" min="0.1" step="any" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-price">Price (₦)</label>
                    <input type="text" id="${rowId}-price" class="form-control price-display" placeholder="Price" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-subtotal">Subtotal (₦)</label>
                    <input type="text" id="${rowId}-subtotal" class="form-control subtotal-display" placeholder="Subtotal" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn-remove w-100" onclick="removeProduct(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            container.appendChild(newRow);
            attachEventListeners(newRow);
        }

        function removeProduct(button) {
            const rows = document.querySelectorAll('.product-row');
            if (rows.length > 1) {
                button.closest('.product-row').remove();
                updateTotal();
            } else {
                alert('At least one product is required.');
            }
        }

        function attachEventListeners(row) {
            const productSelect = row.querySelector('.product-select');
            const unitTypeSelect = row.querySelector('.unit-type-select');
            const quantityInput = row.querySelector('.quantity-input');
            const priceDisplay = row.querySelector('.price-display');
            const subtotalDisplay = row.querySelector('.subtotal-display');

            function updatePrice() {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const unitType = unitTypeSelect.value;
                let price = parseFloat(selectedOption.dataset.price) || 0;
                
                if (unitType === 'crate') {
                    price = parseFloat(selectedOption.dataset.cratePrice) || price;
                }
                
                priceDisplay.value = price.toFixed(2);
                const quantity = parseFloat(quantityInput.value) || 0;
                subtotalDisplay.value = (quantity * price).toFixed(2);
                updateTotal();
            }

            productSelect.addEventListener('change', updatePrice);
            unitTypeSelect.addEventListener('change', updatePrice);
            quantityInput.addEventListener('input', updatePrice);
        }

        function updateTotal() {
            const rows = document.querySelectorAll('.product-row');
            let total = 0;

            rows.forEach(row => {
                const subtotal = parseFloat(row.querySelector('.subtotal-display').value) || 0;
                total += subtotal;
            });

            document.getElementById('totalAmount').textContent = total.toFixed(2);
        }

        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.product-row').forEach(row => {
                attachEventListeners(row);
            });
            
            // Update time (GMT+1)
            setInterval(function() {
                const now = new Date();
                // Convert to GMT+1 (West Africa Time)
                const gmtPlus1 = new Date(now.toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
                const timeElement = document.getElementById('currentTime');
                if (timeElement) {
                    timeElement.textContent = gmtPlus1.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                }
            }, 1000);
        });
    </script>
</body>
</html>

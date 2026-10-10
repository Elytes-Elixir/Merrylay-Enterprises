<?php
/**
 * Shop Sales Interface
 * 
 * Main interface for shop attendants to record retail sales
 * Uses shared inventory management system for stock deduction and reporting
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

// Restrict access to shop attendants and managers (for viewing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isShopAttendant() && !hasRole(['admin', 'manager'])) {
    error_log('Unauthorized shop sale by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to record shop sales.');
}
requireShopAttendantOrManager();

$error = '';
$success = $_SESSION['shop_index_success'] ?? '';
unset($_SESSION['shop_index_success']);
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

// Get products with caching
$products = [];
try {
    $products = cache_remember("shop_products_active", function() {
        $stmt = executeQuery("SELECT id, name, cost_price, quantity FROM products WHERE is_active = 1 ORDER BY name");
        return $stmt->fetchAll();
    }, 300); // Cache for 5 minutes
    if (empty($products)) {
        $error = 'No products available in stock. Please add products to inventory.';
    }
} catch (PDOException $e) {
    error_log("Failed to load products: " . $e->getMessage());
    $error = 'Failed to load products: ' . $e->getMessage();
}

// Business details for receipt with caching
$company_name = 'Merrylay Enterprises';
$company_phone = '';
$company_address = '';
try {
    $settings = cache_remember("shop_business_settings", function() {
        $stmt = executeQuery("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_phone', 'company_address', 'company_name')");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }, 3600); // Cache for 1 hour - settings rarely change
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
    $payment_type = 'split';
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
    $discounts = $_POST['discount_amount'] ?? [];
    
    // Split payment amounts
    $payment_cash = floatval($_POST['payment_cash'] ?? 0);
    $payment_transfer = floatval($_POST['payment_transfer'] ?? 0);
    $payment_card = floatval($_POST['payment_card'] ?? 0);
    $payment_credit = floatval($_POST['payment_credit'] ?? 0);
    
    // Validate customer name for credit sales
    if ($payment_credit > 0 && empty($customer_name)) {
        $error = 'Customer name is required when using credit payment';
    } elseif (empty($product_ids) || empty(array_filter($quantities))) {
        $error = 'Please add at least one product';
    } elseif (!$unit_types_valid) {
        $error = 'One or more product units are invalid. Please choose Pieces or Crate.';
    } else {
        try {
            // Ensure daily stock has been initialized for the selected sale date before recording any sales
            $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
            $stockManager = getStockManager();
            if (!$stockManager->isDailyStockInitialized($sale_date)) {
                if (!initializeTodayStock($sale_date)) {
                    throw new Exception('Failed to initialize daily stock for ' . $sale_date . '. Please ensure products have been added to the system before recording sales. Go to Products > View Products to add products.');
                }
            }

            // Prepare requested stock for validation
            $requestedStock = [];
            foreach ($product_ids as $index => $product_id) {
                $product_id = intval($product_id);
                $qty = floatval($quantities[$index] ?? 0);
                if ($product_id > 0 && $qty > 0) {
                    $requestedStock[$product_id] = ($requestedStock[$product_id] ?? 0) + $qty;
                }
            }

            // Calculate gross amount and total item discount
            $gross_amount = 0;
            $total_discount = 0;
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $stmt = executeQuery("SELECT cost_price FROM products WHERE id = ?", [$product_id]);
                    $product = $stmt->fetch();
                    $price = $product['cost_price'];
                    $subtotal = $qty * $price;
                    $item_discount = max(0, floatval($discounts[$index] ?? 0));
                    if ($item_discount > $subtotal) {
                        $item_discount = $subtotal;
                    }
                    $gross_amount += $subtotal;
                    $total_discount += $item_discount;
                }
            }

            $total_amount = $gross_amount;

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

            // Create sales record for shop (using new shop_sales table)
            $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
            $sale_number = 'SHOP-' . date('Ymd', strtotime($sale_date)) . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Determine the dominant payment method and actual split flag
            $payment_methods = [
                'cash' => $payment_cash,
                'transfer' => $payment_transfer,
                'card' => $payment_card,
                'credit' => $payment_credit,
            ];
            $positive_methods = array_filter($payment_methods, fn($amount) => $amount > 0);
            if (empty($positive_methods)) {
                throw new Exception('No payment amount was provided.');
            }
            arsort($positive_methods);
            $primary_payment_method = key($positive_methods);
            $payment_split_flag = count($positive_methods) > 1 ? 1 : 0;
            $split_total = array_sum($positive_methods);

            // Calculate surplus if payment exceeds total amount
            $surplus_amount = 0;
            if ($split_total > $total_amount) {
                $surplus_amount = $split_total - $total_amount;
            }

            // VALIDATION: Split total must be at least equal to sale amount (allow surplus)
            if ($split_total < $total_amount - 0.01) {
                throw new Exception('Payment total ₦' . number_format($split_total, 2) . ' is less than sale amount ₦' . number_format($total_amount, 2) . '. Please adjust payment amounts.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO shop_sales (sale_number, sale_date, customer_name, total_amount, discount_amount, surplus_amount, payment_method, payment_split, recorded_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sale_number,
                $sale_date,
                $customer_name ?: 'Walk-in customer',
                $total_amount,
                $total_discount,
                $surplus_amount,
                $primary_payment_method,
                $payment_split_flag,
                $_SESSION['user_id'],
                $customer_name ? "Customer: $customer_name" : 'Walk-in customer'
            ]);
            $shop_sale_id = $pdo->lastInsertId();

            // Persist payment method records, ensuring no duplicate inserts
            $check_stmt = $pdo->prepare("SELECT COUNT(*) as record_count FROM shop_sale_payments WHERE shop_sale_id = ?");
            $check_stmt->execute([$shop_sale_id]);
            $check_result = $check_stmt->fetch();
            if ($check_result['record_count'] > 0) {
                throw new Exception('Payment records already exist for this sale. Duplicate submission detected.');
            }

            if ($payment_split_flag === 1) {
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
                if ($primary_payment_method === 'credit' && !empty($customer_name)) {
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

                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, creditor_id, notes)
                        VALUES (?, 'credit', ?, ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $total_amount, $creditor_id, 'Credit payment']);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sale_payments (shop_sale_id, payment_method, amount, notes)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $primary_payment_method, $total_amount, ucfirst($primary_payment_method) . ' payment']);
                }
            }

            // Also create dispatch record so shop sales are counted in dispatch total
            $dispatch_number = 'SHOP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("
                INSERT INTO dispatches (dispatch_number, user_id, dispatcher_id, dispatcher_name, expected_amount, actual_sales_amount, dispatch_date, sales_channel, notes, created_at)
                VALUES (?, ?, ?, 'Shop Sales', ?, ?, ?, 'shop_sales', ?, NOW())
            ");
            $stmt->execute([$dispatch_number, $_SESSION['user_id'], $_SESSION['user_id'], $gross_amount, $gross_amount, $sale_date, $customer_name ? "Customer: $customer_name" : 'Walk-in customer']);
            $dispatch_id = $pdo->lastInsertId();

            // Add sales items to both shop_sales_items and dispatch_items
            foreach ($product_ids as $index => $product_id) {
                $qty = floatval($quantities[$index] ?? 0);
                if ($qty > 0) {
                    $unit_type = $unit_types[$index];
                    $stmt = executeQuery("SELECT cost_price FROM products WHERE id = ?", [$product_id]);
                    $product = $stmt->fetch();
                    $price = $product['cost_price'];
                    $subtotal = $qty * $price;
                    $item_discount = max(0, floatval($discounts[$index] ?? 0));
                    if ($item_discount > $subtotal) {
                        $item_discount = $subtotal;
                    }

                    // Add to shop_sales_items
                    $stmt = $pdo->prepare("
                        INSERT INTO shop_sales_items (shop_sale_id, product_id, quantity, unit_price, discount_amount, subtotal, unit_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$shop_sale_id, $product_id, $qty, $price, $item_discount, $subtotal, $unit_type]);

                    // Add to dispatch_items
                    $stmt = $pdo->prepare("
                        INSERT INTO dispatch_items (dispatch_id, product_id, quantity, unit_price, discount_amount)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$dispatch_id, $product_id, $qty, $price, $item_discount]);
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
                        $dispatch_id,
                        "SHOP-$sale_number",
                        "Shop sale transaction",
                        $_SESSION['user_id'],
                        $sale_date
                    );
                }
            }

            $pdo->commit();
            cache_clear();

            $_SESSION['open_receipt'] = "receipt.php?id=$shop_sale_id";
            $success_message = "Sale recorded successfully! Receipt #: $sale_number";
            if ($payment_credit > 0) {
                $success_message .= " (Credit recorded to dashboard for customer: $customer_name)";
            }
            $_SESSION['shop_index_success'] = "$success_message <a href='receipt.php?id=$shop_sale_id' class='btn btn-sm btn-primary ms-2' target='_blank'><i class='fas fa-receipt'></i> View Receipt</a>";
            header('Location: index.php');
            exit();

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to record sale: ' . $e->getMessage();
        }
    }
}

// Get recent shop sales for current shop attendant only with caching
$recent_sales = [];
try {
    $today = date('Y-m-d');
    $recent_sales = cache_remember("shop_recent_sales_{$_SESSION['user_id']}_{$today}", function() use ($today) {
        $stmt = executeQuery("
            SELECT ss.sale_number, ss.sale_date, ss.total_amount, ss.payment_method, ss.recorded_at
            FROM shop_sales ss
            WHERE ss.recorded_by = ?
              AND DATE(ss.sale_date) = ?
            ORDER BY ss.recorded_at DESC
            LIMIT 10
        ", [$_SESSION['user_id'], $today]);
        return $stmt->fetchAll();
    }, 60); // Cache for 1 minute - recent sales change frequently
} catch (PDOException $e) {
    error_log("Failed to load recent shop sales: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Sales - Merrylay Enterprises</title>
    
    <!-- CSS Framework -->
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        /* Professional Shop Sales Interface Styles */
        
        .product-row {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .product-row:hover {
            border-color: #0d6efd;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.1);
        }
        
        .total-box {
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            color: white;
            padding: 24px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(13, 110, 253, 0.25);
        }
        
        .btn-add {
            background: #198754;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(25, 135, 84, 0.2);
        }
        
        .btn-add:hover {
            background: #157347;
            transform: translateY(-1px);
            color: white;
            box-shadow: 0 4px 12px rgba(25, 135, 84, 0.3);
        }
        
        .btn-remove {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(220, 53, 69, 0.2);
        }
        
        .btn-remove:hover {
            background: #b02a37;
            transform: scale(1.05);
            color: white;
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }

        .shop-sales-layout {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
        }

        .shop-sales-main {
            flex: 1 1 680px;
            min-width: 0;
            max-width: 100%;
        }

        .shop-sales-sidebar {
            flex: 0 1 380px;
            min-width: 0;
            max-width: 100%;
        }

        .receipt-card {
            position: sticky;
            top: 20px;
            align-self: flex-start;
        }

        /* Form Input Styling */
        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }

        .form-label {
            font-weight: 500;
            color: #495057;
            font-size: 13px;
            margin-bottom: 6px;
        }

        /* Card Styling */
        .content-card {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }

        .card-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            padding: 20px 24px;
            border-radius: 12px 12px 0 0;
        }

        .card-body {
            padding: 24px;
        }

        .card-title {
            font-weight: 600;
            color: #212529;
            font-size: 18px;
            margin-bottom: 4px;
        }

        .card-subtitle {
            color: #6c757d;
            font-size: 13px;
            margin: 0;
        }

        /* Button Styling */
        .btn-primary {
            background: #0d6efd;
            border-color: #0d6efd;
            border-radius: 6px;
            padding: 10px 24px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background: #0b5ed7;
            border-color: #0a58ca;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.25);
        }

        .btn-secondary {
            background: #6c757d;
            border-color: #6c757d;
            border-radius: 6px;
            padding: 10px 24px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #5c636a;
            border-color: #565e64;
            transform: translateY(-1px);
        }

        /* Alert Styling */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 16px 20px;
            font-size: 14px;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Page Header Styling */
        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-weight: 600;
            color: #212529;
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 15px;
            margin: 0;
        }

        /* Receipt Preview Styling */
        .receipt-card .card-body {
            background: #fafbfc;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        @media (max-width: 991.98px) {
            .shop-sales-layout {
                flex-direction: column;
            }
            .shop-sales-main,
            .shop-sales-sidebar {
                flex-basis: 100%;
                width: 100%;
            }
            .receipt-card {
                position: static;
            }
        }

        @media (max-width: 359.98px) {
            .shop-sales-layout {
                gap: 16px;
            }

            .product-row {
                padding: 12px;
            }
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
                    <a href="index.php" class="nav-link active">
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
                            <i class="fas fa-cash-register" style="margin-right: 15px;"></i>
                            Shop Sales
                        </h1>
                        <p class="page-subtitle">
                            Record retail sales for walk-in customers
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
                <div class="shop-sales-layout">
                    <div class="shop-sales-main">
                        <div class="content-card">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-shopping-cart" style="margin-right: 10px;"></i>
                                        New Sale
                                    </h5>
                                    <p class="card-subtitle">Enter sale details</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="saleForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="row mb-4">
                                        <div class="col-md-4">
                                            <label class="form-label" for="shop-sale-date">Sale Date <span style="color: var(--red); font-weight: 700;">*</span></label>
                                            <input type="date" id="shop-sale-date" name="sale_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="mt-5">
                                        <h5 class="mb-4" style="color: var(--primary-800); font-weight: 600;">
                                            <i class="fas fa-boxes" style="margin-right: 10px;"></i>
                                            Products
                                        </h5>
                                        <div id="productList">
                                            <div class="product-row row g-2 align-items-center">
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="shop-sale-product-0">Product</label>
                                                    <select id="shop-sale-product-0" name="product_id[]" class="form-select product-select" required>
                                                        <option value="">Select Product</option>
                                                        <?php foreach ($products as $product): ?>
                                                            <option value="<?php echo $product['id']; ?>" 
                                                                    data-price="<?php echo $product['cost_price']; ?>" 
                                                                    data-unit="<?php echo htmlspecialchars($product['unit']); ?>" 
                                                                    data-stock="<?php echo $product['quantity']; ?>">
                                                                <?php echo htmlspecialchars($product['name']); ?> - ₦<?php echo number_format($product['cost_price'], 2); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="shop-sale-unit-0">Unit</label>
                                                    <select id="shop-sale-unit-0" name="unit_type[]" class="form-select unit-select">
                                                        <option value="pieces">Pieces</option>
                                                        <option value="crate">Crate</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="shop-sale-quantity-0">Qty</label>
                                                    <input type="number" id="shop-sale-quantity-0" name="quantity[]" class="form-control quantity-input" placeholder="Qty" min="0.1" step="any" required>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="shop-sale-price-0">Price (₦)</label>
                                                    <input type="text" id="shop-sale-price-0" class="form-control price-display" placeholder="Price" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small text-muted" for="shop-sale-discount-0">Discount (₦)</label>
                                                    <input type="number" id="shop-sale-discount-0" name="discount_amount[]" class="form-control discount-input" placeholder="0.00" min="0" step="0.01" value="0.00">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small text-muted" for="shop-sale-subtotal-0">Subtotal</label>
                                                    <input type="text" id="shop-sale-subtotal-0" class="form-control subtotal-display" placeholder="Subtotal" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
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
                                        <div class="col-12">
                                            <div class="total-box">
                                                <small class="d-block mb-2">Net Amount</small>
                                                <h3 class="mb-0">₦<span id="netAmount">0.00</span></h3>
                                                <div class="mt-3 text-start">
                                                    <div class="d-flex justify-content-between">
                                                        <small>Total Discount Given</small>
                                                        <strong>₦<span id="discountDisplay">0.00</span></strong>
                                                    </div>
                                                    <div class="d-flex justify-content-between">
                                                        <small>Expected Amount</small>
                                                        <strong>₦<span id="expectedAmount">0.00</span></strong>
                                                    </div>
                                                </div>
                                                <small style="opacity: 0.8;">Discounts are tracked separately and do not reduce the expected sale amount.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <hr class="my-5">

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="customer_name" class="form-label">Customer Name <span id="customerNameRequired">(Optional)</span></label>
                                                <input type="text" name="customer_name" id="customer_name" class="form-control" placeholder="Walk-in customer">
                                                <small class="text-muted" id="customerNameHint">Required only if using credit payment</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Split Payment Methods -->
                                    <div id="splitPaymentSection" class="mt-3">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> Enter payment amounts for each method. Total must equal the sale amount.
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="payment_cash" class="form-label">Cash Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" name="payment_cash" id="payment_cash" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="payment_transfer" class="form-label">Transfer Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" name="payment_transfer" id="payment_transfer" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()">
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
                                                        <input type="number" name="payment_card" id="payment_card" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="payment_credit" class="form-label">Credit Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" name="payment_credit" id="payment_credit" class="form-control" placeholder="0.00" step="0.01" min="0" oninput="calculateSplitTotal()">
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
                                        <button type="submit" name="record_sale" class="btn btn-primary">
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

                    </div>
                </div>
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
            
            // Attach event listeners to existing product rows
            document.querySelectorAll('.product-row').forEach(row => {
                attachEventListeners(row);
            });
        });
    </script>
    
    <!-- Print Stylesheet -->
    <style media="print">
        body { background: white; }
        .sidebar, .top-bar, .page-header, .shop-sales-main { display: none !important; }
        .dashboard-container { margin: 0; padding: 0; }
        .dashboard-content { margin: 0; padding: 0; }
        .shop-sales-sidebar { width: 100% !important; max-width: 100% !important; flex: 1 1 100% !important; }
        .content-card { box-shadow: none; border: none; }
        .card-header { display: none; }
        .btn { display: none !important; }
        .receipt { box-shadow: none; max-width: 300px; width: 300px; margin: 0 auto; }
        @page { margin: 10mm; }
    </style>
    
    <script>
        let nextShopSaleRowId = 1;

        function addProduct() {
            const container = document.getElementById('productList');
            const rowId = `shop-sale-${nextShopSaleRowId++}`;
            const newRow = document.createElement('div');
            newRow.className = 'product-row row g-2 align-items-center';
            newRow.innerHTML = `
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-product">Product</label>
                    <select id="${rowId}-product" name="product_id[]" class="form-select product-select" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" 
                                    data-price="<?php echo $product['cost_price']; ?>" 
                                    data-unit="<?php echo htmlspecialchars($product['unit']); ?>" 
                                    data-stock="<?php echo $product['quantity']; ?>">
                                <?php echo htmlspecialchars($product['name']); ?> - ₦<?php echo number_format($product['cost_price'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-unit">Unit</label>
                    <select id="${rowId}-unit" name="unit_type[]" class="form-select unit-select">
                        <option value="pieces">Pieces</option>
                        <option value="crate">Crate</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-quantity">Qty</label>
                    <input type="number" id="${rowId}-quantity" name="quantity[]" class="form-control quantity-input" placeholder="Qty" min="0.1" step="any" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-price">Price (₦)</label>
                    <input type="text" id="${rowId}-price" class="form-control price-display" placeholder="Price" readonly style="background-color: var(--bg-secondary); font-weight: 600;">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted" for="${rowId}-discount">Discount (₦)</label>
                    <input type="number" id="${rowId}-discount" name="discount_amount[]" class="form-control discount-input" placeholder="0.00" min="0" step="0.01" value="0.00">
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted" for="${rowId}-subtotal">Subtotal</label>
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
            updateReceipt();
        }

        function removeProduct(button) {
            const rows = document.querySelectorAll('.product-row');
            if (rows.length > 1) {
                button.closest('.product-row').remove();
                updateTotal();
                updateReceipt();
            } else {
                alert('At least one product is required.');
            }
        }

        function attachEventListeners(row) {
            const productSelect = row.querySelector('.product-select');
            const unitSelect = row.querySelector('.unit-select');
            const quantityInput = row.querySelector('.quantity-input');
            const priceDisplay = row.querySelector('.price-display');
            const discountInput = row.querySelector('.discount-input');
            const subtotalDisplay = row.querySelector('.subtotal-display');

            function updateRow() {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const selectedUnit = (selectedOption.dataset.unit || '').toLowerCase();
                let price = parseFloat(selectedOption.dataset.price) || 0;
                
                unitSelect.value = selectedUnit === 'crate' ? 'crate' : 'pieces';
                priceDisplay.value = price.toFixed(2);
                const quantity = parseFloat(quantityInput.value) || 0;
                subtotalDisplay.value = (quantity * price).toFixed(2);
                updateTotal();
                updateReceipt();
            }

            productSelect.addEventListener('change', updateRow);
            quantityInput.addEventListener('input', updateRow);
            if (discountInput) {
                discountInput.addEventListener('input', function() {
                    updateTotal();
                    updateReceipt();
                });
            }
        }

        function updateReceipt() {
            // Receipt preview removed from shop sales page.
        }

        function updateTotal() {
            const rows = document.querySelectorAll('.product-row');
            let total = 0;
            let totalDiscount = 0;

            rows.forEach(row => {
                const subtotal = parseFloat(row.querySelector('.subtotal-display').value) || 0;
                const discount = Math.max(0, parseFloat(row.querySelector('.discount-input')?.value) || 0);
                total += subtotal;
                totalDiscount += discount;
            });

            const netAmount = Math.max(0, total - totalDiscount);
            document.getElementById('netAmount').textContent = netAmount.toFixed(2);
            document.getElementById('discountDisplay').textContent = totalDiscount.toFixed(2);
            document.getElementById('expectedAmount').textContent = total.toFixed(2);
        }


        function calculateSplitTotal() {
            const cash = parseFloat(document.getElementById('payment_cash').value) || 0;
            const transfer = parseFloat(document.getElementById('payment_transfer').value) || 0;
            const card = parseFloat(document.getElementById('payment_card').value) || 0;
            const credit = parseFloat(document.getElementById('payment_credit').value) || 0;
            
            const total = cash + transfer + card + credit;
            document.getElementById('splitPaymentTotal').textContent = '₦' + total.toFixed(2);
            
            // Get the expected amount from the sale
            const expectedAmount = parseFloat(document.getElementById('expectedAmount').textContent.replace(/[^0-9.-]+/g, '')) || 0;
            
            const status = document.getElementById('splitPaymentStatus');
            const creditHint = document.getElementById('creditPaymentHint');
            
            if (credit > 0) {
                creditHint.textContent = 'Requires customer name';
                creditHint.style.color = '#dc3545';
            } else {
                creditHint.textContent = 'Optional';
                creditHint.style.color = 'inherit';
            }
            
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
            
            // Initialize sale totals
            updateTotal();
            
            // Open receipt if set in session after sale completion
            <?php if (!empty($_SESSION['open_receipt'])): ?>
                const receiptUrl = '<?php echo $_SESSION['open_receipt']; ?>';
                const receiptWindow = window.open(receiptUrl, '_blank');
                if (!receiptWindow) {
                    alert('Receipt popup was blocked by the browser. Use the View Receipt button shown in the success message.');
                }
                <?php unset($_SESSION['open_receipt']); ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>

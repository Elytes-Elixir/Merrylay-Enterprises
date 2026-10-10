<?php
/**
 * Sales Receipt Page for Merrylay Enterprises
 * 
 * Handles creating sales receipts and processing payments
 */

// Start session
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Redirect to new daily dispatch sales page
// Note: Counter sales should be recorded through the dispatch system
header('Location: ../dispatch/daily/index.php');
exit();

// Include database configuration and stock manager
require_once '../config/db.php';
require_once '../config/stock_manager.php';

// Initialize variables
$error = '';
$success = '';
$sale_items = [];
$customer_name = '';
$customer_phone = '';
$payment_method = 'cash';
$amount_paid = 0;
$discount = 0;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize input
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $discount = floatval($_POST['discount'] ?? 0);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    
    // Validate input
    if (empty($product_ids) || empty(array_filter($quantities))) {
        $error = 'At least one product with quantity must be selected.';
    } elseif ($amount_paid < 0) {
        $error = 'Amount paid cannot be negative.';
    } elseif ($discount < 0) {
        $error = 'Discount cannot be negative.';
    } else {
        try {
            // Begin transaction
            $pdo = getDBConnection();
            $pdo->beginTransaction();
            
            // Calculate total amount
            $total_amount = 0;
            foreach ($product_ids as $index => $product_id) {
                $quantity = intval($quantities[$index] ?? 0);
                $price = floatval($prices[$index] ?? 0);
                if ($quantity > 0 && $price > 0) {
                    $total_amount += $quantity * $price;
                }
            }

            // Apply discount
            $total_amount -= $discount;
            if ($total_amount < 0) $total_amount = 0;

            // Check if customer exists or create new one
            $customer_id = null;
            if (!empty($customer_name)) {
                $stmt = executeQuery("SELECT id FROM customers WHERE name = ? AND phone = ?", [$customer_name, $customer_phone]);
                $customer = $stmt->fetch();

                if (!$customer) {
                    $stmt = executeQuery("INSERT INTO customers (name, phone, created_at) VALUES (?, ?, NOW())", [$customer_name, $customer_phone]);
                    $customer_id = $pdo->lastInsertId();
                } else {
                    $customer_id = $customer['id'];
                }
            }

            // Calculate subtotal and tax
            $subtotal = $total_amount + $discount; // subtotal is before discount
            $tax_amount = 0; // For now, no tax calculation
            $change_amount = max(0, $amount_paid - $total_amount);
            $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Prepare requested stock for validation
            $requestedStock = [];
            foreach ($product_ids as $index => $product_id) {
                $quantity = intval($quantities[$index] ?? 0);
                if ($quantity > 0) {
                    $requestedStock[$product_id] = ($requestedStock[$product_id] ?? 0) + $quantity;
                }
            }

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

                foreach ($requestedStock as $product_id => $quantity) {
                    if (!isset($productStock[$product_id])) {
                        throw new Exception('Product not found.');
                    }

                    $product = $productStock[$product_id];
                    if ($product['quantity'] < $quantity) {
                        throw new Exception('Insufficient stock for ' . $product['name'] . '. Available: ' . $product['quantity']);
                    }
                }
            }

            // Create sale record
            $query = "INSERT INTO sales (receipt_number, customer_id, user_id, subtotal, discount_amount, tax_amount, total_amount, amount_paid, change_amount, payment_method, payment_status, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'completed', NOW())";
            $stmt = $pdo->prepare($query);
            $result = $stmt->execute([$receipt_number, $customer_id, $_SESSION['user_id'], $subtotal, $discount, $tax_amount, $total_amount, $amount_paid, $change_amount, $payment_method]);

            if (!$result) {
                throw new Exception("Failed to execute INSERT statement.");
            }

            $sale_id = $pdo->lastInsertId();

            if (!$sale_id) {
                throw new Exception("Failed to get sale ID. Last insert ID returned: " . var_export($sale_id, true));
            }

            // Add sale items and update product quantities
            foreach ($product_ids as $index => $product_id) {
                $quantity = intval($quantities[$index] ?? 0);
                $price = floatval($prices[$index] ?? 0);

                if ($quantity > 0 && $price > 0) {
                    // Get product cost price
                    $stmt = executeQuery("SELECT cost_price FROM products WHERE id = ?", [$product_id]);
                    $product_cost = $stmt->fetch()['cost_price'] ?? 0;

                    // Add sale item
                    $query = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount_percent, discount_amount, price, cost_price, created_at)
                             VALUES (?, ?, ?, ?, 0, 0, ?, ?, NOW())";
                    $total_price = $quantity * $price;
                    $stmt2 = $pdo->prepare($query);
                    $stmt2->execute([$sale_id, $product_id, $quantity, $price, $total_price, $product_cost]);

                    // Direct stock update with validation (already locked above)
                    $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
                    $stmt->execute([$quantity, $product_id, $quantity]);
                    if ($stmt->rowCount() === 0) {
                        throw new Exception("Insufficient stock for product ID $product_id");
                    }

                    // Record stock movement
                    $sale_date = date('Y-m-d');
                    $stmt = $pdo->prepare("
                        INSERT INTO stock_movements (
                            product_id, movement_date, movement_type, quantity_change,
                            reference_type, reference_id, reference_number, notes, user_id
                        ) VALUES (?, ?, 'sale', ?, 'sale', ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $product_id,
                        $sale_date,
                        -$quantity,
                        $sale_id,
                        "RCP-$receipt_number",
                        "Sale transaction",
                        $_SESSION['user_id']
                    ]);

                    // Update daily stock
                    $stmt = $pdo->prepare("
                        INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
                        VALUES (?, ?, 0, ?)
                        ON DUPLICATE KEY UPDATE
                            closing_stock = COALESCE(closing_stock, opening_stock, 0) - ?,
                            updated_at = NOW()
                    ");
                    $stmt->execute([$product_id, $sale_date, $quantity, $quantity]);
                }
            }
            
            // Commit transaction
            $pdo->commit();
            $success = "Sale completed successfully! Receipt #: " . str_pad($sale_id, 6, '0', STR_PAD_LEFT);
            
            // Clear form
            $customer_name = '';
            $customer_phone = '';
            $payment_method = 'cash';
            $amount_paid = 0;
            $discount = 0;
            $sale_items = [];
            
        } catch (Exception $e) {
            // Rollback transaction if active
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Create sale error: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

// Get available products
try {
    $stmt = executeQuery("SELECT id, name, quantity, unit, selling_price FROM products WHERE quantity > 0 ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Load products error: " . $e->getMessage());
    $products = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Receipt - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
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
        
        .form-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .form-section h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .sale-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e5e7eb;
        }
        
        .receipt-preview {
            background: white;
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 1.5rem;
            min-height: 300px;
        }
        
        .total-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .receipt-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .receipt-item:last-child {
            border-bottom: none;
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
                    <a href="../products/view.php" class="nav-link">
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
                    <a href="receipt.php" class="nav-link active">
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
                    <h1><i class="fas fa-cash-register me-2"></i>Sales Receipt</h1>
                    <p class="mb-0">Create and process sales receipts</p>
                </div>
            </div>
            
            <div class="container-fluid px-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <form method="POST" action="">
                            <!-- Customer Information -->
                            <div class="form-section">
                                <h5><i class="fas fa-user me-2"></i>Customer Information</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="customer_name" class="form-label">Customer Name</label>
                                            <input type="text" class="form-control" id="customer_name" name="customer_name" 
                                                   value="<?php echo htmlspecialchars($customer_name); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="customer_phone" class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" id="customer_phone" name="customer_phone" 
                                                   value="<?php echo htmlspecialchars($customer_phone); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Sale Items -->
                            <div class="form-section">
                                <h5><i class="fas fa-shopping-cart me-2"></i>Sale Items</h5>
                                
                                <div id="sale-items">
                                    <div class="sale-item">
                                        <div class="row g-3">
                                            <div class="col-md-5">
                                                <label class="form-label">Product</label>
                                                <select class="form-select product-select" name="product_id[]" required>
                                                    <option value="">Select Product</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?php echo $product['id']; ?>" 
                                                                data-price="<?php echo $product['selling_price']; ?>"
                                                                data-quantity="<?php echo $product['quantity']; ?>"
                                                                data-unit="<?php echo htmlspecialchars($product['unit']); ?>">
                                                            <?php echo htmlspecialchars($product['name']); ?> 
                                                            (₦<?php echo number_format($product['selling_price'], 2); ?>/<?php echo htmlspecialchars($product['unit']); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Quantity</label>
                                                <input type="number" class="form-control quantity-input" name="quantity[]" 
                                                       min="1" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Unit Price</label>
                                                <input type="number" class="form-control price-input" name="price[]" 
                                                       step="0.01" min="0" required>
                                            </div>
                                            <div class="col-md-1 d-flex align-items-end">
                                                <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(this)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-outline-primary mt-2" onclick="addSaleItem()">
                                    <i class="fas fa-plus me-2"></i>Add Item
                                </button>
                            </div>

                            <!-- Payment Information -->
                            <div class="form-section">
                                <h5><i class="fas fa-credit-card me-2"></i>Payment Information</h5>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="discount" class="form-label">Discount (₦)</label>
                                            <input type="number" class="form-control" id="discount" name="discount" 
                                                   value="<?php echo htmlspecialchars($discount); ?>" step="0.01" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="payment_method" class="form-label">Payment Method</label>
                                            <select class="form-select" id="payment_method" name="payment_method">
                                                <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                                <option value="card" <?php echo $payment_method === 'card' ? 'selected' : ''; ?>>Card</option>
                                                <option value="transfer" <?php echo $payment_method === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="amount_paid" class="form-label">Amount Paid (₦)</label>
                                            <input type="number" class="form-control" id="amount_paid" name="amount_paid" 
                                                   value="<?php echo htmlspecialchars($amount_paid); ?>" step="0.01" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Balance</label>
                                            <input type="text" class="form-control" id="balance" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Clear
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Complete Sale
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-lg-4">
                        <div class="form-section">
                            <h5><i class="fas fa-receipt me-2"></i>Receipt Preview</h5>
                            <div class="receipt-preview">
                                <div class="text-center mb-3">
                                    <h5>Merrylay Enterprises</h5>
                                    <p class="small text-muted">Inventory & Sales Management</p>
                                    <hr>
                                </div>
                                
                                <div id="receipt-items">
                                    <p class="text-muted text-center">No items added yet</p>
                                </div>
                                
                                <div class="total-section">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Subtotal:</span>
                                        <span id="subtotal">₦0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Discount:</span>
                                        <span id="discount-display">₦0.00</span>
                                    </div>
                                    <hr class="my-2">
                                    <div class="d-flex justify-content-between">
                                        <strong>Total:</strong>
                                        <strong id="total">₦0.00</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/offline-handler.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
    <script>
        function addSaleItem() {
            const container = document.getElementById('sale-items');
            const newItem = document.createElement('div');
            newItem.className = 'sale-item';
            newItem.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Product</label>
                        <select class="form-select product-select" name="product_id[]" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>" 
                                        data-price="<?php echo $product['selling_price']; ?>"
                                        data-quantity="<?php echo $product['quantity']; ?>"
                                        data-unit="<?php echo htmlspecialchars($product['unit']); ?>">
                                    <?php echo htmlspecialchars($product['name']); ?> 
                                    (₦<?php echo number_format($product['selling_price'], 2); ?>/<?php echo htmlspecialchars($product['unit']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control quantity-input" name="quantity[]" 
                               min="1" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit Price</label>
                        <input type="number" class="form-control price-input" name="price[]" 
                               step="0.01" min="0" required>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newItem);
            attachEventListeners(newItem);
        }

        function removeItem(button) {
            const items = document.querySelectorAll('.sale-item');
            if (items.length > 1) {
                button.closest('.sale-item').remove();
                updateReceipt();
            } else {
                alert('At least one item is required.');
            }
        }

        function attachEventListeners(container) {
            container.querySelector('.product-select').addEventListener('change', function() {
                const priceInput = container.querySelector('.price-input');
                const quantityInput = container.querySelector('.quantity-input');
                const maxQuantity = parseInt(this.options[this.selectedIndex].dataset.quantity);
                const price = parseFloat(this.options[this.selectedIndex].dataset.price);
                
                if (price) {
                    priceInput.value = price.toFixed(2);
                }
                if (maxQuantity) {
                    quantityInput.max = maxQuantity;
                }
                updateReceipt();
            });

            container.querySelector('.quantity-input').addEventListener('input', updateReceipt);
            container.querySelector('.price-input').addEventListener('input', updateReceipt);
        }

        function updateReceipt() {
            const items = document.querySelectorAll('.sale-item');
            let subtotal = 0;
            let receiptHTML = '';

            items.forEach(item => {
                const select = item.querySelector('.product-select');
                const quantity = parseInt(item.querySelector('.quantity-input').value) || 0;
                const price = parseFloat(item.querySelector('.price-input').value) || 0;
                const selectedOption = select.options[select.selectedIndex];
                
                if (quantity > 0 && price > 0 && selectedOption.value) {
                    const itemTotal = quantity * price;
                    subtotal += itemTotal;
                    
                    receiptHTML += `
                        <div class="d-flex justify-content-between align-items-center py-1">
                            <div>
                                <small class="d-block">${selectedOption.text.split('(')[0].trim()}</small>
                                <small class="text-muted">${quantity} × ₦${price.toFixed(2)}</small>
                            </div>
                            <strong>₦${itemTotal.toFixed(2)}</strong>
                        </div>
                    `;
                }
            });

            if (receiptHTML === '') {
                receiptHTML = '<p class="text-muted text-center">No items added yet</p>';
            }

            document.getElementById('receipt-items').innerHTML = receiptHTML;
            
            const discount = parseFloat(document.getElementById('discount').value) || 0;
            const total = Math.max(0, subtotal - discount);
            
            document.getElementById('subtotal').textContent = `₦${subtotal.toFixed(2)}`;
            document.getElementById('discount-display').textContent = `₦${discount.toFixed(2)}`;
            document.getElementById('total').textContent = `₦${total.toFixed(2)}`;
            
            // Update balance
            const amountPaid = parseFloat(document.getElementById('amount_paid').value) || 0;
            const balance = amountPaid - total;
            document.getElementById('balance').value = `₦${balance.toFixed(2)}`;
        }

        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.sale-item').forEach(item => {
                attachEventListeners(item);
            });
            
            document.getElementById('discount').addEventListener('input', updateReceipt);
            document.getElementById('amount_paid').addEventListener('input', updateReceipt);
        });
    </script>
</body>
</html>


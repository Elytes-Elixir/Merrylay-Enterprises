<?php
/**
 * Purchase/Restock Page for Merrylay Enterprises
 *
 * Records new supply/purchases and adds to opening stock
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/stock_manager.php';

$error = '';
$success = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Check for success messages
if (isset($_GET['success']) && $_GET['success'] === 'purchase_recorded') {
    $success = 'Purchase recorded successfully! Stock has been updated.';
}

// Initialize stock manager
$stockManager = getStockManager();

// Handle purchase submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_purchase'])) {
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $cost_price = floatval($_POST['cost_price'] ?? 0);
    $supplier = trim($_POST['supplier'] ?? '');
    $supplier_contact = trim($_POST['supplier_contact'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if ($product_id <= 0) {
        $error = 'Please select a product.';
    } elseif ($quantity <= 0) {
        $error = 'Quantity must be greater than zero.';
    } elseif ($cost_price <= 0) {
        $error = 'Cost price must be greater than zero.';
    } elseif (empty($purchase_date)) {
        $error = 'Purchase date is required.';
    } else {
        try {
            // Start transaction
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // Record stock movement as purchase
            $movementResult = $stockManager->recordMovement(
                $product_id,
                'purchase',
                $quantity,
                'purchase',
                null,
                $invoice_number ?: 'PUR-' . date('YmdHis'),
                "Purchase from supplier: " . $supplier . ($notes ? " - " . $notes : ''),
                $_SESSION['user_id']
            );

            if (!$movementResult) {
                throw new Exception('Failed to record stock movement');
            }

            // Update product cost price if provided
            if ($cost_price > 0) {
                $stmt = $pdo->prepare("UPDATE products SET cost_price = ?, supplier = ?, supplier_contact = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$cost_price, $supplier, $supplier_contact, $product_id]);
            }

            // Initialize daily stock for the purchase date if not already initialized
            $stockManager->initializeDailyStock($purchase_date);

            $pdo->commit();
            header('Location: add.php?success=purchase_recorded&date=' . urlencode($purchase_date));
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Add purchase error: " . $e->getMessage());
            $error = 'Unable to record purchase: ' . $e->getMessage();
        }
    }
}

// Get products for selection
try {
    $stmt = executeQuery("SELECT id, name, quantity, unit, cost_price, selling_price FROM products WHERE is_active = TRUE ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
    $error = 'Unable to load products.';
}

// Get recent purchases
try {
    $stmt = executeQuery("
        SELECT sm.*, p.name as product_name, p.unit, u.full_name as user_name
        FROM stock_movements sm
        JOIN products p ON sm.product_id = p.id
        LEFT JOIN users u ON sm.user_id = u.id
        WHERE sm.movement_type = 'purchase'
        ORDER BY sm.created_at DESC
        LIMIT 20
    ");
    $recent_purchases = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_purchases = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase/Restock - Merrylay Enterprises</title>
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
        
        .stock-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .stock-card h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.25rem;
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
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
        }
        
        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-outline-secondary {
            border: 2px solid #6b7280;
            color: #6b7280;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-outline-secondary:hover {
            background: #6b7280;
            border-color: #6b7280;
            color: white;
            transform: translateY(-1px);
        }
        
        .positive-change {
            color: #10b981;
            font-weight: 700;
        }
        
        .table-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .table-section h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }
        
        .table-section table {
            margin-bottom: 0;
        }
        
        .table-section thead {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
        }
        
        .table-section th {
            padding: 1rem;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .table-section td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .input-group-text {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #f8f9fa;
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
                    <h1><i class="fas fa-boxes me-2"></i>Purchase/Restock</h1>
                    <p class="mb-0">Add new supply to inventory and update opening stock</p>
                </div>
            </div>
            
            <div class="container-fluid px-4">
                <!-- Alert Messages -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-4">
                        <!-- Add Purchase Form -->
                        <div class="stock-card">
                            <h5><i class="fas fa-plus-circle me-2"></i>Record New Purchase</h5>
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label class="form-label">Product <span class="text-danger">*</span></label>
                                    <select name="product_id" class="form-select" required id="productSelect">
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>"
                                                    data-quantity="<?php echo $product['quantity']; ?>"
                                                    data-unit="<?php echo htmlspecialchars($product['unit']); ?>"
                                                    data-cost="<?php echo $product['cost_price']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Current Stock</label>
                                    <input type="text" class="form-control" id="currentStock" readonly>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Quantity to Add <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="quantity" class="form-control" min="1" step="0.25" required>
                                        <span class="input-group-text" id="unitLabel">units</span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Cost Price per Unit (₦) <span class="text-danger">*</span></label>
                                    <input type="number" name="cost_price" class="form-control" step="0.01" min="0" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Supplier Name</label>
                                    <input type="text" name="supplier" class="form-control" placeholder="Supplier name">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Supplier Contact</label>
                                    <input type="text" name="supplier_contact" class="form-control" placeholder="Phone or email">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Invoice/Receipt Number</label>
                                    <input type="text" name="invoice_number" class="form-control" placeholder="Optional invoice reference">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
                                    <input type="date" name="purchase_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                                </div>

                                <button type="submit" name="add_purchase" class="btn btn-success w-100">
                                    <i class="fas fa-save me-2"></i>Record Purchase
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <!-- Recent Purchases -->
                        <div class="table-section">
                            <h5><i class="fas fa-history me-2"></i>Recent Purchases</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Product</th>
                                            <th>Quantity Added</th>
                                            <th>Reference</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_purchases)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">
                                                    <i class="fas fa-inbox fa-2x mb-2"></i><br>
                                                    No purchases recorded yet
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_purchases as $purchase): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($purchase['movement_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars($purchase['product_name']); ?></td>
                                                    <td class="positive-change">+<?php echo $purchase['quantity_change']; ?> <?php echo htmlspecialchars($purchase['unit']); ?></td>
                                                    <td><?php echo htmlspecialchars($purchase['reference_number'] ?: '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($purchase['user_name'] ?: 'System'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Quick Links -->
                        <div class="row">
                            <div class="col-md-6">
                                <a href="../stock/manage.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-warehouse me-2"></i>Manage Stock
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="../products/view.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-box me-2"></i>View Products
                                </a>
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
        // Update stock info when product is selected
        document.getElementById('productSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const quantity = selectedOption.getAttribute('data-quantity');
            const unit = selectedOption.getAttribute('data-unit');
            const cost = selectedOption.getAttribute('data-cost');

            document.getElementById('currentStock').value = quantity + ' ' + unit;
            document.getElementById('unitLabel').textContent = unit;
            
            if (cost && cost > 0) {
                document.querySelector('input[name="cost_price"]').value = cost;
            }
        });
    </script>
</body>
</html>


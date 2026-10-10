<?php
/**
 * Add Creditor
 * 
 * Record new customer credit with driver and product details.
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';

$error = '';
$success = $_SESSION['creditor_add_success'] ?? '';
unset($_SESSION['creditor_add_success']);
$csrfValid = true;
$canCreateCredit = hasRole(['admin', 'manager', 'cashier']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canCreateCredit) {
    error_log('Unauthorized creditor creation attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to record credit.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected creditor creation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the credit details and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Get drivers
$drivers = [];
try {
    $stmt = executeQuery("SELECT id, name FROM drivers WHERE status = 'active' ORDER BY name");
    $drivers = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load drivers';
}

// Get products for details
$products = [];
try {
    $stmt = executeQuery("SELECT id, name FROM products WHERE is_active = TRUE ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

// Get recent credit customers from daily_activities
$recent_credit_customers = [];
try {
    $stmt = executeQuery("
        SELECT 
            da.id as activity_id,
            da.credit_customer_name,
            da.credit_amount,
            da.driver_id,
            da.driver_name,
            da.activity_date,
            da.recorded_at
        FROM daily_activities 
        WHERE credit_customer_name IS NOT NULL 
          AND credit_customer_name != '' 
          AND credit_amount > 0
        ORDER BY recorded_at DESC 
        LIMIT 30
    ");
    $recent_credit_customers = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_credit_customers = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_creditor']) && $csrfValid) {
    $creditor_name = trim($_POST['creditor_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $credit_amount = floatval($_POST['credit_amount'] ?? 0);
    $credit_date = $_POST['credit_date'] ?? date('Y-m-d');
    $driver_id = intval($_POST['driver_id'] ?? 0);
    $product_details = trim($_POST['product_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($creditor_name)) {
        $error = 'Creditor name is required';
    } elseif ($credit_amount <= 0) {
        $error = 'Credit amount must be greater than zero';
    } elseif (empty($driver_id)) {
        $error = 'Please select the driver who gave credit';
    } else {
        try {
            // Get driver name
            $stmt = executeQuery("SELECT name FROM drivers WHERE id = ?", [$driver_id]);
            $driver = $stmt->fetch();
            if (!$driver) {
                throw new Exception('Invalid driver selected');
            }
            
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("
                INSERT INTO creditors 
                (creditor_name, phone, address, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, product_details, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $creditor_name, $phone, $address, $credit_amount, $credit_amount,
                $credit_date, $driver_id, $driver['name'], $product_details, $notes, $_SESSION['user_id']
            ]);
            
            $_SESSION['creditor_add_success'] = 'Creditor added successfully';
            header('Location: add.php');
            exit();
            
        } catch (Exception $e) {
            $error = 'Failed to add creditor: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Creditor - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .form-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: var(--space-8);
            box-shadow: var(--shadow-md);
        }
        .form-label {
            font-weight: 500;
            color: var(--primary-700);
            margin-bottom: var(--space-2);
        }
        .form-control, .form-select {
            border: 1px solid var(--border-medium);
            border-radius: var(--radius-lg);
            padding: var(--space-3) var(--space-4);
            font-size: 0.875rem;
            transition: all var(--transition-fast);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgb(59 130 246 / 0.1);
            outline: none;
        }
        .input-group-text {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-medium);
            color: var(--primary-700);
        }
        .btn-submit {
            background-color: var(--blue);
            border-color: var(--blue);
            color: white;
        }
        .btn-submit:hover {
            background-color: var(--blue-600);
            border-color: var(--blue-600);
            color: white;
        }
        .recent-credit-section {
            background: linear-gradient(135deg, rgb(59 130 246 / 0.05) 0%, rgb(59 130 246 / 0.02) 100%);
            border: 1px solid rgb(59 130 246 / 0.2);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
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

        <main class="main-content" id="mainContent">
            <div class="dashboard-content">
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <h1><i class="fas fa-user-plus me-2"></i>Add Creditor</h1>
                    <p>Record new customer credit details</p>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" style="background-color: rgb(239 68 68 / 0.1); border: 1px solid rgb(239 68 68 / 0.2); color: var(--red);"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success" style="background-color: rgb(16 185 129 / 0.1); border: 1px solid rgb(16 185 129 / 0.2); color: var(--green);"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <div class="form-card">
                            <form method="POST" id="creditorForm">
                                <input type="hidden" name="add_creditor" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                
                                <!-- Recent Credit Sales Selection -->
                                <?php if (!empty($recent_credit_customers)): ?>
                                <div class="mb-4 recent-credit-section">
                                    <label class="form-label"><i class="fas fa-history me-2"></i>Or Select from Recent Credit Sales</label>
                                    <select id="recentCreditSelect" class="form-select" onchange="loadCreditSaleData()">
                                        <option value="">-- Select a credit sale to auto-fill --</option>
                                        <?php foreach ($recent_credit_customers as $sale): ?>
                                            <option value="<?php echo htmlspecialchars(json_encode([
                                                'activity_id' => $sale['activity_id'],
                                                'customer_name' => $sale['credit_customer_name'],
                                                'driver_id' => $sale['driver_id'],
                                                'driver_name' => $sale['driver_name'],
                                                'amount' => $sale['credit_amount'],
                                                'date' => $sale['activity_date']
                                            ])); ?>">
                                                <?php echo htmlspecialchars($sale['driver_name'] . ' - ' . $sale['credit_customer_name'] . ' (₦' . number_format($sale['credit_amount'], 2) . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted d-block mt-2">Select a recent credit sale to automatically populate driver and customer information</small>
                                </div>
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Creditor Name *</label>
                                        <input type="text" name="creditor_name" class="form-control" id="creditorName" placeholder="Customer/creditor name" required>
                                        <small class="text-muted">Name of the customer who received credit</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" name="phone" class="form-control" id="creditorPhone" placeholder="Optional">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" class="form-control" id="creditorAddress" rows="2" placeholder="Optional"></textarea>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Credit Amount *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₦</span>
                                            <input type="number" name="credit_amount" class="form-control" id="creditAmount" step="0.01" min="0.01" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Credit Date *</label>
                                        <input type="date" name="credit_date" class="form-control" id="creditDate" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Driver Who Gave Credit *</label>
                                    <div class="input-group">
                                        <select name="driver_id" class="form-select" id="driverId" required>
                                            <option value="">Select Driver</option>
                                            <?php foreach ($drivers as $d): ?>
                                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-secondary" id="clearDriverBtn" onclick="clearDriverSelection()" style="display: none;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="driverNote"></small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Product Details</label>
                                    <textarea name="product_details" class="form-control" id="productDetails" rows="2" placeholder="e.g., 10 crates of Pepsi, 5 cartons of 7up"></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Additional Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Any other relevant information"></textarea>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="submit" class="btn btn-submit">
                                        <i class="fas fa-save me-2"></i>Add Creditor
                                    </button>
                                    <a href="index.php" class="btn">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="../../assets/js/clean-dashboard.js"></script>
    <script>
        // Auto-fill form from recent credit sales
        function loadCreditSaleData() {
            const selectEl = document.getElementById('recentCreditSelect');
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            
            if (!selectedOption.value) {
                clearForm();
                return;
            }
            
            try {
                const data = JSON.parse(selectedOption.value);
                
                // Fill in the form fields
                document.getElementById('creditorName').value = data.customer_name;
                document.getElementById('creditAmount').value = data.amount;
                document.getElementById('creditDate').value = data.date;
                document.getElementById('driverId').value = data.driver_id;
                
                // Show clear button and driver note
                document.getElementById('clearDriverBtn').style.display = 'inline-block';
                document.getElementById('driverNote').textContent = 'Auto-populated from credit sale recorded on ' + data.date + ' by ' + data.driver_name;
                document.getElementById('driverNote').style.color = '#10b981';
                
            } catch (e) {
                console.error('Error parsing credit sale data:', e);
            }
        }
        
        function clearForm() {
            document.getElementById('creditorName').value = '';
            document.getElementById('creditAmount').value = '';
            document.getElementById('creditDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('driverId').value = '';
            document.getElementById('creditorPhone').value = '';
            document.getElementById('creditorAddress').value = '';
            document.getElementById('productDetails').value = '';
            document.getElementById('clearDriverBtn').style.display = 'none';
            document.getElementById('driverNote').textContent = '';
            document.getElementById('recentCreditSelect').value = '';
        }
        
        function clearDriverSelection() {
            clearForm();
        }
    </script>
</body>
</html>


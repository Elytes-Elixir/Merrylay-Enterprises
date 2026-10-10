<?php
/**
 * Legacy cashier route retained as a no-write compatibility notice.
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/auth.php';

// Check if user is cashier or admin/manager
if (!isCashier() && !hasRole(['admin', 'manager'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized cashier-record mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to record cashier transactions.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(410);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Workflow Moved - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <main class="container py-5">
        <div class="alert alert-warning" role="status">
            <h1 class="h4">This cashier workflow is no longer available</h1>
            <p class="mb-3">No records were saved. Use the validated daily activity workflow to record sales, payments, returns, damage, expenses, and credit reconciliation.</p>
            <a class="btn btn-primary me-2" href="daily/record.php">Open daily activity</a>
            <a class="btn btn-outline-secondary" href="daily.php">Open dispatches</a>
        </div>
    </main>
</body>
</html>
<?php
exit();

$error = '';
$success = $_SESSION['cashier_record_success'] ?? '';
unset($_SESSION['cashier_record_success']);
$csrfToken = SecurityUtils::generateCSRFToken();
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_dispatcher = $_GET['dispatcher'] ?? '';

// Get unique dispatchers for selection
try {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches ORDER BY dispatcher_name");
    $dispatchers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $dispatchers = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrfToken = $_POST['csrf_token'] ?? null;
    if (!is_string($postedCsrfToken) || !SecurityUtils::consumeCSRFToken($postedCsrfToken)) {
        error_log('Rejected cashier financial mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('Your form has expired. Return to the page and try again.');
    }
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    
    try {
        // Process returns
        if (isset($_POST['record_returns']) && !empty($_POST['returns'])) {
            foreach ($_POST['returns'] as $return) {
                if (!empty($return['quantity_returned']) && $return['quantity_returned'] > 0) {
                    $dispatch_id = intval($return['dispatch_id']);
                    $product_id = intval($return['product_id']);
                    $quantityValue = $return['quantity_returned'];
                    $quantity_returned = floatval($quantityValue);
                    $return_reason = trim($return['return_reason'] ?? 'Unsold goods');
                    $condition = $return['condition'] ?? 'good';
                    
                    // Get dispatch item details
                    $stmt = $pdo->prepare("
                        SELECT di.quantity, di.quantity_sold, di.unit_price, di.discount_amount, p.is_active
                        FROM dispatch_items di
                        JOIN products p ON p.id = di.product_id
                        WHERE di.dispatch_id = ? AND di.product_id = ?
                        FOR UPDATE
                    ");
                    $stmt->execute([$dispatch_id, $product_id]);
                    $dispatch_item = $stmt->fetch();
                    
                    if ($dispatch_item) {
                        $available_to_return = $dispatch_item['quantity'] - $dispatch_item['quantity_sold'];

                        if ($condition !== 'good') {
                            if (!is_scalar($quantityValue) || trim((string) $quantityValue) === '' || !is_numeric($quantityValue)
                                || !is_finite($quantity_returned) || $quantity_returned <= 0 || $quantity_returned > 999999.99) {
                                throw new InvalidArgumentException('Damaged return quantity must be positive, finite, and within the permitted maximum.');
                            }
                            if (!(bool) $dispatch_item['is_active']) {
                                throw new InvalidArgumentException('The damaged product is inactive.');
                            }

                            $previousReturnsStmt = $pdo->prepare(
                                "SELECT COALESCE(SUM(quantity_returned), 0)
                                 FROM dispatch_returns
                                 WHERE dispatch_id = ? AND product_id = ? AND quantity_returned > 0"
                            );
                            if (!$previousReturnsStmt->execute([$dispatch_id, $product_id])) {
                                throw new RuntimeException('Previous return validation failed.');
                            }
                            $remainingUnsold = (float) $available_to_return - (float) $previousReturnsStmt->fetchColumn();
                            if ($remainingUnsold < 0 || $quantity_returned > $remainingUnsold) {
                                throw new InvalidArgumentException('Damaged return quantity exceeds the unaccounted dispatch quantity.');
                            }

                            $damageReturnStmt = $pdo->prepare(
                                "INSERT INTO dispatch_returns
                                 (dispatch_id, product_id, quantity_returned, return_amount, return_reason, `condition`, notes, recorded_by, return_date)
                                 VALUES (?, ?, ?, ?, ?, ?, '', ?, CURDATE())"
                            );
                            if (!$damageReturnStmt->execute([
                                $dispatch_id,
                                $product_id,
                                $quantity_returned,
                                $quantity_returned * (float) $dispatch_item['unit_price'],
                                $return_reason,
                                $condition,
                                $_SESSION['user_id']
                            ]) || $damageReturnStmt->rowCount() !== 1) {
                                throw new RuntimeException('Damaged dispatch return was not saved.');
                            }
                            continue;
                        }
                        
                        if ($quantity_returned <= $available_to_return) {
                            // Use full unit price (without discount) - discounts are tracked separately for account balancing
                            $unit_price = $dispatch_item['unit_price'];
                            $return_amount = $quantity_returned * $unit_price;
                            
                            // Record return
                            $stmt = $pdo->prepare("
                                INSERT INTO dispatch_returns (dispatch_id, product_id, quantity_returned, return_amount, return_reason, `condition`, notes, recorded_by, return_date)
                                VALUES (?, ?, ?, ?, ?, ?, '', ?, CURDATE())
                            ");
                            $stmt->execute([$dispatch_id, $product_id, $quantity_returned, $return_amount, $return_reason, $condition, $_SESSION['user_id']]);
                            $return_record_id = $pdo->lastInsertId();
                            
                            // Update inventory if good condition
                            if ($condition === 'good') {
                                recordReturnMovement($return_record_id, $product_id, $quantity_returned, 'manual', $_SESSION['user_id'], null, 'Dispatch return');
                            }
                            
                            // Update dispatch actual_sales_amount
                            $stmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount - ? WHERE id = ?");
                            $stmt->execute([$return_amount, $dispatch_id]);
                            
                            // Update dispatch_items quantity_sold
                            $stmt = $pdo->prepare("UPDATE dispatch_items SET quantity_sold = quantity_sold + ? WHERE dispatch_id = ? AND product_id = ?");
                            $stmt->execute([$quantity_returned, $dispatch_id, $product_id]);
                        }
                    }
                }
            }
        }
        
        // Process sales
        if (isset($_POST['record_sales']) && !empty($_POST['dispatch_id'])) {
            $dispatch_id = intval($_POST['dispatch_id']);
            $cash_amount = floatval($_POST['cash_amount'] ?? 0);
            $transfer_amount = floatval($_POST['transfer_amount'] ?? 0);
            $credit_amount = floatval($_POST['credit_amount'] ?? 0);
            $credit_customer = trim($_POST['credit_customer'] ?? '');
            $credit_phone = trim($_POST['credit_phone'] ?? '');
            
            // Get dispatcher name
            $stmt = $pdo->prepare("SELECT dispatcher_name FROM dispatches WHERE id = ?");
            $stmt->execute([$dispatch_id]);
            $dispatch = $stmt->fetch();
            $dispatcher_name = $dispatch['dispatcher_name'] ?? '';
            
            // Record cash payment
            if ($cash_amount > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, notes, recorded_by, created_at)
                    VALUES (?, CURDATE(), 'cash', ?, 'Sales recording', ?, NOW())
                ");
                $stmt->execute([$dispatcher_name, $cash_amount, $_SESSION['user_id']]);
            }
            
            // Record transfer payment
            if ($transfer_amount > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, notes, recorded_by, created_at)
                    VALUES (?, CURDATE(), 'transfer', ?, 'Sales recording', ?, NOW())
                ");
                $stmt->execute([$dispatcher_name, $transfer_amount, $_SESSION['user_id']]);
            }
            
            // Record credit
            if ($credit_amount > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, customer_name, customer_phone, notes, recorded_by, created_at)
                    VALUES (?, CURDATE(), 'credit', ?, ?, ?, 'Credit sale', ?, NOW())
                ");
                $stmt->execute([$dispatcher_name, $credit_amount, $credit_customer, $credit_phone, $_SESSION['user_id']]);
            }
            
            // Update dispatch actual_sales_amount
            $total_sales = $cash_amount + $transfer_amount + $credit_amount;
            if ($total_sales > 0) {
                $stmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount + ? WHERE id = ?");
                $stmt->execute([$total_sales, $dispatch_id]);
            }
        }
        
        // Process expenses
        if (isset($_POST['record_expenses']) && !empty($_POST['expenses'])) {
            foreach ($_POST['expenses'] as $expense) {
                if (!empty($expense['amount']) && $expense['amount'] > 0) {
                    $expense_type = trim($expense['expense_type'] ?? '');
                    $description = trim($expense['description'] ?? '');
                    $amount = floatval($expense['amount']);
                    $payment_method = $expense['payment_method'] ?? 'cash';
                    
                    if (!empty($expense_type) && !empty($description)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO dispatcher_expenses (dispatcher_name, expense_date, expense_type, description, amount, payment_method, notes, recorded_by, created_at)
                            VALUES (?, CURDATE(), ?, ?, ?, ?, '', ?, NOW())
                        ");
                        $stmt->execute([$selected_dispatcher, $expense_type, $description, $amount, $payment_method, $_SESSION['user_id']]);
                    }
                }
            }
        }
        
        $pdo->commit();
        cache_clear();
        $_SESSION['cashier_record_success'] = 'All records saved successfully!';
        header('Location: cashier-record.php?' . http_build_query([
            'date' => $selected_date,
            'dispatcher' => $selected_dispatcher
        ]));
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Cashier record error: " . $e->getMessage());
        $error = 'Unable to save records: ' . $e->getMessage();
    }
}

// Get dispatches for selected dispatcher and date
$dispatches = [];
if (!empty($selected_dispatcher)) {
    try {
        $stmt = executeQuery("
            SELECT 
                d.id,
                d.dispatch_number,
                d.dispatcher_name,
                d.dispatch_date,
                d.expected_amount,
                d.actual_sales_amount,
                d.status,
                GROUP_CONCAT(CONCAT(p.name, ' (', di.quantity, ')') SEPARATOR ', ') as items_summary
            FROM dispatches d
            LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
            LEFT JOIN products p ON di.product_id = p.id
            WHERE d.dispatcher_name = ? AND DATE(d.dispatch_date) = ?
            GROUP BY d.id
            ORDER BY d.dispatch_number
        ", [$selected_dispatcher, $selected_date]);
        $dispatches = $stmt->fetchAll();
    } catch (PDOException $e) {
        $dispatches = [];
    }
}

// Get dispatch items for returns
$dispatch_items = [];
if (!empty($selected_dispatcher)) {
    try {
        $stmt = executeQuery("
            SELECT 
                di.dispatch_id,
                di.product_id,
                p.name as product_name,
                di.quantity,
                di.quantity_sold,
                (di.quantity - di.quantity_sold) as available_to_return
            FROM dispatch_items di
            JOIN products p ON di.product_id = p.id
            JOIN dispatches d ON di.dispatch_id = d.id
            WHERE d.dispatcher_name = ? AND DATE(d.dispatch_date) = ?
            ORDER BY p.name
        ", [$selected_dispatcher, $selected_date]);
        $dispatch_items = $stmt->fetchAll();
    } catch (PDOException $e) {
        $dispatch_items = [];
    }
}

// Get existing expenses for the day
$expenses = [];
if (!empty($selected_dispatcher)) {
    try {
        $stmt = executeQuery("
            SELECT * FROM dispatcher_expenses
            WHERE dispatcher_name = ? AND DATE(expense_date) = ?
            ORDER BY created_at DESC
        ", [$selected_dispatcher, $selected_date]);
        $expenses = $stmt->fetchAll();
    } catch (PDOException $e) {
        $expenses = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Finance Recording - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .recording-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
        }
        .recording-card h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            font-weight: 600;
            margin-right: 0.5rem;
        }
        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }
        .btn-primary {
            background: #667eea;
            border: none;
        }
        .btn-primary:hover {
            background: #5a6fd6;
        }
        .total-row {
            background: #f8f9fa;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="../dashboard/index.php" class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <span class="logo-text">Merrylay Enterprises</span>
                </a>
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
        <main class="main-content">
            <div class="content-wrapper">
                <div class="page-header">
                    <h1><i class="fas fa-clipboard-check me-2"></i>Cashier Finance Recording</h1>
                    <p>Streamlined recording for returns, sales, and expenses</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Step 1: Select Dispatcher -->
                <div class="recording-card">
                    <h5><span class="step-number">1</span>Select Dispatcher</h5>
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Dispatcher</label>
                            <select name="dispatcher" class="form-select" required>
                                <option value="">Select Dispatcher</option>
                                <?php foreach ($dispatchers as $dispatcher): ?>
                                    <option value="<?php echo htmlspecialchars($dispatcher); ?>" <?php echo $selected_dispatcher === $dispatcher ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dispatcher); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>Load Dispatches
                            </button>
                        </div>
                    </form>
                </div>

                <?php if (!empty($selected_dispatcher)): ?>
                    <!-- Step 2: Record Returns -->
                    <div class="recording-card">
                        <h5><span class="step-number">2</span>Record Goods Returned (Unsold)</h5>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="record_returns" value="1">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Dispatched</th>
                                            <th>Available to Return</th>
                                            <th>Quantity Returned</th>
                                            <th>Reason</th>
                                            <th>Condition</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($dispatch_items)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">No items available for return</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($dispatch_items as $item): ?>
                                                <?php if ($item['available_to_return'] > 0): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                    <td><?php echo $item['quantity']; ?></td>
                                                    <td><?php echo $item['available_to_return']; ?></td>
                                                    <td>
                                                        <input type="number" name="returns[<?php echo $item['dispatch_id']; ?>][<?php echo $item['product_id']; ?>][quantity_returned]" 
                                                               class="form-control" min="0" max="<?php echo $item['available_to_return']; ?>" placeholder="0">
                                                        <input type="hidden" name="returns[<?php echo $item['dispatch_id']; ?>][<?php echo $item['product_id']; ?>][dispatch_id]" value="<?php echo $item['dispatch_id']; ?>">
                                                        <input type="hidden" name="returns[<?php echo $item['dispatch_id']; ?>][<?php echo $item['product_id']; ?>][product_id]" value="<?php echo $item['product_id']; ?>">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="returns[<?php echo $item['dispatch_id']; ?>][<?php echo $item['product_id']; ?>][return_reason]" 
                                                               class="form-control" placeholder="Reason" value="Unsold goods">
                                                    </td>
                                                    <td>
                                                        <select name="returns[<?php echo $item['dispatch_id']; ?>][<?php echo $item['product_id']; ?>][condition]" class="form-select">
                                                            <option value="good">Good</option>
                                                            <option value="damaged">Damaged</option>
                                                        </select>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" class="btn btn-primary mt-3">
                                <i class="fas fa-save me-1"></i>Save Returns
                            </button>
                        </form>
                    </div>

                    <!-- Step 3: Record Sales -->
                    <div class="recording-card">
                        <h5><span class="step-number">3</span>Record Sales</h5>
                        <?php if (!empty($dispatches)): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="record_sales" value="1">
                                <div class="mb-3">
                                    <label class="form-label">Select Dispatch</label>
                                    <select name="dispatch_id" class="form-select" required>
                                        <?php foreach ($dispatches as $dispatch): ?>
                                            <option value="<?php echo $dispatch['id']; ?>">
                                                <?php echo htmlspecialchars($dispatch['dispatch_number'] . ' - ' . $dispatch['items_summary']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Cash Payment</label>
                                        <input type="number" name="cash_amount" class="form-control" step="0.01" min="0" placeholder="0.00">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Transfer Payment</label>
                                        <input type="number" name="transfer_amount" class="form-control" step="0.01" min="0" placeholder="0.00">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Credit Owed</label>
                                        <input type="number" name="credit_amount" class="form-control" step="0.01" min="0" placeholder="0.00">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Credit Customer</label>
                                        <input type="text" name="credit_customer" class="form-control" placeholder="Customer name">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Customer Phone</label>
                                        <input type="text" name="credit_phone" class="form-control" placeholder="Phone number">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary mt-3">
                                    <i class="fas fa-save me-1"></i>Save Sales
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="text-muted">No dispatches found for this dispatcher on this date.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Step 4: Record Expenses -->
                    <div class="recording-card">
                        <h5><span class="step-number">4</span>Record Dispatcher Expenses</h5>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="record_expenses" value="1">
                            <div class="table-responsive">
                                <table class="table table-hover" id="expensesTable">
                                    <thead>
                                        <tr>
                                            <th>Expense Type</th>
                                            <th>Description</th>
                                            <th>Amount</th>
                                            <th>Payment Method</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <select name="expenses[0][expense_type]" class="form-select">
                                                    <option value="fuel">Fuel</option>
                                                    <option value="repair">Repair</option>
                                                    <option value="toll">Toll</option>
                                                    <option value="loading">Loading</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="expenses[0][description]" class="form-control" placeholder="Description" required>
                                            </td>
                                            <td>
                                                <input type="number" name="expenses[0][amount]" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                                            </td>
                                            <td>
                                                <select name="expenses[0][payment_method]" class="form-select">
                                                    <option value="cash">Cash</option>
                                                    <option value="transfer">Transfer</option>
                                                </select>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addExpenseRow()">
                                <i class="fas fa-plus me-1"></i>Add Expense
                            </button>
                            <button type="submit" class="btn btn-primary mt-3">
                                <i class="fas fa-save me-1"></i>Save Expenses
                            </button>
                        </form>
                    </div>

                    <!-- Existing Expenses Summary -->
                    <?php if (!empty($expenses)): ?>
                    <div class="recording-card">
                        <h5><i class="fas fa-history me-2"></i>Today's Expenses for <?php echo htmlspecialchars($selected_dispatcher); ?></h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Payment Method</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expenses as $expense): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(ucfirst($expense['expense_type'])); ?></td>
                                        <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                        <td><?php echo number_format($expense['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($expense['payment_method'])); ?></td>
                                        <td><?php echo date('H:i', strtotime($expense['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td colspan="2"><strong>Total</strong></td>
                                        <td><strong><?php echo number_format(array_sum(array_column($expenses, 'amount')), 2); ?></strong></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let expenseRowCount = 1;

        function addExpenseRow() {
            const table = document.getElementById('expensesTable').getElementsByTagName('tbody')[0];
            const newRow = table.insertRow();
            
            newRow.innerHTML = `
                <td>
                    <select name="expenses[${expenseRowCount}][expense_type]" class="form-select">
                        <option value="fuel">Fuel</option>
                        <option value="repair">Repair</option>
                        <option value="toll">Toll</option>
                        <option value="loading">Loading</option>
                        <option value="other">Other</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="expenses[${expenseRowCount}][description]" class="form-control" placeholder="Description" required>
                </td>
                <td>
                    <input type="number" name="expenses[${expenseRowCount}][amount]" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                </td>
                <td>
                    <select name="expenses[${expenseRowCount}][payment_method]" class="form-select">
                        <option value="cash">Cash</option>
                        <option value="transfer">Transfer</option>
                    </select>
                </td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove()">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            
            expenseRowCount++;
        }
    </script>
</body>
</html>


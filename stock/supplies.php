<?php
/**
 * Supply Management Page for Merrylay Enterprises
 * 
 * Handles recording of inbound stock supplies (purchases, transfers, etc.)
 */

// Start session
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration, stock manager, cache, and auth
require_once '../config/db.php';
require_once '../config/stock_manager.php';
require_once '../config/cache.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Check permissions
if (!hasRole(['admin', 'manager'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized supply mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to manage supplies.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$error = '';
const MAX_SUPPLY_QUANTITY = 999999.99;

function parsePositiveInteger($value, $fieldName)
{
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException("Invalid $fieldName.");
    }

    $value = (string) $value;
    if (!preg_match('/^[1-9][0-9]*$/', $value)) {
        throw new InvalidArgumentException("Invalid $fieldName.");
    }

    return (int) $value;
}

function parsePositiveQuantity($value)
{
    if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', trim($value))) {
        throw new InvalidArgumentException('Quantity must be a valid number greater than 0.');
    }

    $quantity = (float) $value;
    if (!is_finite($quantity) || $quantity <= 0) {
        throw new InvalidArgumentException('Quantity must be greater than 0.');
    }
    if ($quantity > MAX_SUPPLY_QUANTITY) {
        throw new InvalidArgumentException('Quantity cannot exceed 999999.99.');
    }

    return $quantity;
}

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'supply_created':
            $message = "Supply recorded successfully!";
            break;
        case 'supply_item_added':
            $message = "Supply item added successfully!";
            break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $csrfToken = $_POST['csrf_token'] ?? null;
        if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
            throw new RuntimeException('Invalid security token. Please refresh the page and try again.');
        }

        switch ($action) {
            case 'create_supply':
                $supply_date = trim($_POST['supply_date'] ?? '');
                $date = DateTime::createFromFormat('!Y-m-d', $supply_date);
                if (!$date || $date->format('Y-m-d') !== $supply_date) {
                    throw new InvalidArgumentException('Please provide a valid supply date.');
                }

                $supply_id = createSupply($supply_date, $_SESSION['user_id']);

                if ($supply_id) {
                    header('Location: supplies.php?action=add_items&id=' . $supply_id);
                    exit();
                } else {
                    $error = "Failed to create supply record.";
                }
                break;

            case 'add_supply_item':
                $supply_id = parsePositiveInteger($_POST['supply_id'] ?? null, 'supply ID');
                $product_id = parsePositiveInteger($_POST['product_id'] ?? null, 'product');
                $quantity = parsePositiveQuantity($_POST['quantity'] ?? null);

                if (addSupplyItem($supply_id, $product_id, $quantity, 0, '', null, '', $_SESSION['user_id'])) {
                    cache_clear();
                    header('Location: supplies.php?action=add_items&id=' . $supply_id . '&success=supply_item_added');
                    exit();
                }
                break;

            case 'complete_supply':
                $supply_id = parsePositiveInteger($_POST['supply_id'] ?? null, 'supply ID');
                $stmt = $pdo->prepare('SELECT id FROM supplies WHERE id = ?');
                $stmt->execute([$supply_id]);
                if (!$stmt->fetch()) {
                    throw new InvalidArgumentException('Supply not found.');
                }
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM supply_items WHERE supply_id = ?');
                $stmt->execute([$supply_id]);
                if ((int) $stmt->fetchColumn() === 0) {
                    throw new InvalidArgumentException('Add at least one item before completing the supply.');
                }
                if ($supply_id > 0) {
                    header('Location: supplies.php?success=supply_created');
                    exit();
                }
                break;

            case 'update_supply_item':
                $supply_item_id = parsePositiveInteger($_POST['supply_item_id'] ?? null, 'supply item ID');
                $selected_supply_id = parsePositiveInteger($_POST['supply_id'] ?? null, 'supply ID');
                $new_quantity = parsePositiveQuantity($_POST['quantity'] ?? null);
                $pdo = getDBConnection();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT si.product_id, si.quantity, si.supply_id, p.is_active FROM supply_items si JOIN products p ON p.id = si.product_id WHERE si.id = ? FOR UPDATE");
                $stmt->execute([$supply_item_id]);
                $old_item = $stmt->fetch();
                if (!$old_item || (int) $old_item['supply_id'] !== $selected_supply_id) {
                    throw new InvalidArgumentException('Supply item does not belong to the selected supply.');
                }
                if (!(int) $old_item['is_active']) {
                    throw new InvalidArgumentException('The selected product is inactive.');
                }
                $quantity_diff = $new_quantity - (float) $old_item['quantity'];
                $stmt = $pdo->prepare('UPDATE supply_items SET quantity = ? WHERE id = ? AND supply_id = ?');
                $stmt->execute([$new_quantity, $supply_item_id, $selected_supply_id]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Supply item update was not saved.');
                }
                if ($quantity_diff != 0.0) {
                    $stockManager = getStockManager();
                    $stockManager->recordMovement(
                        $old_item['product_id'],
                        $quantity_diff > 0 ? 'supply' : 'adjustment',
                        $quantity_diff,
                        'manual',
                        $supply_item_id,
                        "SUPPLY-EDIT-$supply_item_id",
                        "Adjustment for original supply item #$supply_item_id",
                        $_SESSION['user_id'],
                        date('Y-m-d')
                    );
                }
                $pdo->commit();
                cache_clear();
                $message = "Supply item updated successfully!";
                break;

            case 'delete_supply_item':
                $supply_item_id = parsePositiveInteger($_POST['supply_item_id'] ?? null, 'supply item ID');
                $selected_supply_id = parsePositiveInteger($_POST['supply_id'] ?? null, 'supply ID');
                $pdo = getDBConnection();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT product_id, quantity, supply_id FROM supply_items WHERE id = ? FOR UPDATE');
                $stmt->execute([$supply_item_id]);
                $item = $stmt->fetch();
                if (!$item || (int) $item['supply_id'] !== $selected_supply_id) {
                    throw new InvalidArgumentException('Supply item does not belong to the selected supply.');
                }
                $stmt = $pdo->prepare('DELETE FROM supply_items WHERE id = ? AND supply_id = ?');
                $stmt->execute([$supply_item_id, $selected_supply_id]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Supply item deletion was not saved.');
                }
                $stockManager = getStockManager();
                $stockManager->recordMovement(
                    $item['product_id'],
                    'adjustment',
                    -$item['quantity'],
                    'manual',
                    $supply_item_id,
                    "SUPPLY-DELETE-$supply_item_id",
                    "Adjustment for deleted original supply item #$supply_item_id",
                    $_SESSION['user_id'],
                    date('Y-m-d')
                );
                $pdo->commit();
                cache_clear();
                $message = "Supply item deleted successfully!";
                break;
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Supply workflow error: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'The supply change could not be completed. Please try again.';
    }
}

// Get supply details if editing or adding items
$current_supply = null;
$supply_items = [];
if (($action === 'add_items' || $action === 'edit_supply') && isset($_GET['id'])) {
    $supply_id = intval($_GET['id']);
    $stmt = executeQuery("SELECT * FROM supplies WHERE id = ?", [$supply_id]);
    $current_supply = $stmt->fetch();
    
    if ($current_supply) {
        $stmt = executeQuery("
            SELECT si.*, p.name as product_name, p.unit
            FROM supply_items si
            JOIN products p ON si.product_id = p.id
            WHERE si.supply_id = ?
            ORDER BY si.id
        ", [$supply_id]);
        $supply_items = $stmt->fetchAll();
    }
}

// Get recent supplies
try {
    $stmt = executeQuery("SELECT * FROM supplies_summary ORDER BY supply_date DESC, created_at DESC LIMIT 20");
    $recent_supplies = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_supplies = [];
}

// Get products for dropdown
try {
    $stock_date = date('Y-m-d');
    if (($action === 'add_items' || $action === 'edit_supply') && $current_supply) {
        $stock_date = $current_supply['supply_date'];
    }

    $stmt = executeQuery(
        "SELECT p.id, p.name,
                COALESCE(
                    ds.closing_stock,
                    (SELECT ds2.closing_stock FROM daily_stock ds2 WHERE ds2.product_id = p.id AND ds2.stock_date = DATE_SUB(?, INTERVAL 1 DAY) ORDER BY ds2.stock_date DESC LIMIT 1),
                    p.quantity
                ) as quantity,
                p.unit
         FROM products p
         LEFT JOIN daily_stock ds ON p.id = ds.product_id AND ds.stock_date = ?
         WHERE p.is_active = TRUE
         ORDER BY p.name",
        [$stock_date, $stock_date]
    );
    $products = $stmt->fetchAll();
} catch (Exception $e) {
    $products = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage stock supplies and inbound inventory">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Supply Management - Merrylay Enterprises</title>
    
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/fonts/inter/inter.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/unified-design-system.css" rel="stylesheet">
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/css/stock-pages.css" rel="stylesheet">
</head>
<body class="stock-ui">
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
                <button type="button" class="toggle-btn" id="sidebarToggle" aria-label="Toggle navigation">
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
                <a href="../products/view.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
                </a>
                <a href="supplies.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-truck-loading"></i></span>
                    <span class="nav-text">Supplies</span>
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
                    <button type="button" class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search supplies, products..." id="globalSearch" aria-label="Search supplies and products">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <button type="button" class="icon-btn position-relative" title="Notifications" aria-label="Notifications">
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
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumb stock-breadcrumb">
                            <li class="breadcrumb-item"><a href="../dashboard/index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="manage.php">Stock management</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Supplies</li>
                        </ol>
                    </nav>
                    <h1 class="page-title">
                        <i class="fas fa-truck-loading" style="margin-right: 15px;"></i>
                        Supply Management
                    </h1>
                    <p class="page-subtitle">Record incoming stock, edit line quantities, and review recent supply records.</p>
                </div>

                <!-- Supply Flow Information -->
                <div class="content-card mb-4" style="background: linear-gradient(135deg, #e0f2fe, #bae6fd); border: 1px solid var(--blue);">
                    <div class="card-header" style="background: linear-gradient(135deg, rgba(224, 242, 254, 0.5), rgba(186, 230, 253, 0.3)); border-left: 4px solid var(--blue);">
                        <h5 class="card-title" style="color: #0369a1;">
                            <i class="fas fa-info-circle"></i>
                            How Supply Works
                        </h5>
                    </div>
                    <div class="card-body">
                        <ul style="list-style: none; padding: 0;">
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">1</span>
                                <span><strong>Initialize Daily Stock</strong> - Previous day's closing stock becomes today's opening stock</span>
                            </li>
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">2</span>
                                <span><strong>Add Supplies</strong> - Supplies are recorded and added to today's closing stock</span>
                            </li>
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">3</span>
                                <span><strong>Record Sales/Dispatches</strong> - Sales and dispatches deduct from stock</span>
                            </li>
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">4</span>
                                <span><strong>Close Daily Stock</strong> - Final closing stock calculated (Opening + Supplies - Sales)</span>
                            </li>
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">5</span>
                                <span><strong>Next Day</strong> - Today's closing stock automatically becomes tomorrow's opening stock</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (($action === 'add_items' || $action === 'edit_supply') && $current_supply): ?>
                    <!-- Add/Edit Items to Supply -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="content-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="card-title">
                                            <i class="fas fa-<?php echo $action === 'edit_supply' ? 'edit' : 'plus-circle'; ?> me-2"></i>
                                            <?php echo $action === 'edit_supply' ? 'Edit Supply Items' : 'Add Items to Supply'; ?>
                                        </h5>
                                        <p class="card-subtitle">Supply Date: <?php echo date('M d, Y', strtotime($current_supply['supply_date'])); ?></p>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if ($action === 'add_items'): ?>
                                    <!-- Add New Item Form -->
                                    <form method="post">
                                        <input type="hidden" name="action" value="add_supply_item">
                                        <input type="hidden" name="supply_id" value="<?php echo $current_supply['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label class="form-label" for="supplyProduct">Product <span style="color: var(--red); font-weight: 700;">*</span></label>
                                                    <select name="product_id" id="supplyProduct" class="form-select" required>
                                                        <option value="">Select Product</option>
                                                        <?php foreach ($products as $product): ?>
                                                            <option value="<?php echo $product['id']; ?>">
                                                                <?php echo htmlspecialchars($product['name']); ?> (<?php echo $product['quantity']; ?> <?php echo $product['unit']; ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label class="form-label" for="supplyQuantity">Quantity <span style="color: var(--red); font-weight: 700;">*</span></label>
                                                    <input type="number" name="quantity" id="supplyQuantity" class="form-control" step="0.01" min="0.01" required placeholder="0.00">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="action-buttons">
                                            <a href="supplies.php" class="btn btn-secondary">
                                                <i class="fas fa-times"></i>
                                                Cancel
                                            </a>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-plus"></i>
                                                Add Item
                                            </button>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                    <!-- Edit Items Info -->
                                    <p class="text-muted mb-3">Edit quantities below. Stock will be automatically adjusted when you save changes.</p>
                                    <div class="action-buttons">
                                        <a href="supplies.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left"></i>
                                            Back to List
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="content-card">
                                <div class="card-header">
                                    <h5 class="card-title">Supply Items</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($supply_items)): ?>
                                        <p class="text-muted">No items added yet.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Quantity</th>
                                                        <?php if ($action === 'edit_supply'): ?>
                                                        <th>Actions</th>
                                                        <?php endif; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($supply_items as $item): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                            <td>
                                                                <?php if ($action === 'edit_supply'): ?>
                                                                        <form method="post" class="d-inline stock-item-quantity-form">
                                                                        <input type="hidden" name="action" value="update_supply_item">
                                                                        <input type="hidden" name="supply_item_id" value="<?php echo $item['id']; ?>">
                                                                        <input type="hidden" name="supply_id" value="<?php echo $current_supply['id']; ?>">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">
                                                                        <div class="input-group input-group-sm">
                                                                            <label class="visually-hidden" for="supply-item-quantity-<?php echo (int) $item['id']; ?>">Quantity for <?php echo htmlspecialchars($item['product_name']); ?></label>
                                                                            <input type="number" id="supply-item-quantity-<?php echo (int) $item['id']; ?>" name="quantity" class="form-control" step="0.01" min="0.01" value="<?php echo $item['quantity']; ?>" onchange="this.form.submit()" required>
                                                                        </div>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <?php echo $item['quantity']; ?> <?php echo $item['unit']; ?>
                                                                <?php endif; ?>
                                                            </td>
                                                            <?php if ($action === 'edit_supply'): ?>
                                                            <td>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="action" value="delete_supply_item">
                                                                    <input type="hidden" name="supply_item_id" value="<?php echo $item['id']; ?>">
                                                                    <input type="hidden" name="supply_id" value="<?php echo $current_supply['id']; ?>">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this item and return stock?');" title="Delete Item" aria-label="Delete <?php echo htmlspecialchars($item['product_name']); ?> from supply">
                                                                        <i class="fas fa-trash-alt"></i>
                                                                    </button>
                                                                </form>
                                                            </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <?php if ($action === 'add_items'): ?>
                                        <form method="post" class="mt-3">
                                            <input type="hidden" name="action" value="complete_supply">
                                            <input type="hidden" name="supply_id" value="<?php echo $current_supply['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">
                                            <button type="submit" class="btn btn-success w-100">
                                                <i class="fas fa-check"></i>
                                                Complete Supply
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Create New Supply Form -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="content-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="card-title">
                                            <i class="fas fa-plus-circle me-2"></i>
                                            New Supply
                                        </h5>
                                        <p class="card-subtitle">Record inbound stock for date</p>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <form method="post">
                                        <input type="hidden" name="action" value="create_supply">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">

                                        <div class="form-group">
                                            <label class="form-label" for="supplyDate">Supply Date <span style="color: var(--red); font-weight: 700;">*</span></label>
                                            <input type="date" name="supply_date" id="supplyDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>

                                        <div class="action-buttons">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-plus"></i>
                                                Create Supply
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <!-- Recent Supplies -->
                            <div class="content-card">
                                <div class="card-header">
                                    <h5 class="card-title">Recent Supplies</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_supplies)): ?>
                                        <p class="text-muted">No supplies recorded yet.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Items</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_supplies as $supply): ?>
                                                        <tr>
                                                            <td><?php echo date('M d, Y', strtotime($supply['supply_date'])); ?></td>
                                                            <td><?php echo $supply['item_count']; ?></td>
                                                            <td>
                                                                <a href="supplies.php?action=edit_supply&id=<?php echo $supply['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Supply">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/app.js"></script>
    
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
    </script>
</body>
</html>

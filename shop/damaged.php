<?php
/**
 * Shop Damaged Products Management
 *
 * Interface for shop attendants to record damaged products
 * on a daily basis for accurate stock tracking
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
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Restrict access to shop attendants and managers (for viewing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isShopAttendant() && !hasRole(['admin', 'manager'])) {
    error_log('Unauthorized damage report by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to record damaged stock.');
}
requireShopAttendantOrManager();

$error = '';
$success = $_SESSION['shop_damage_success'] ?? '';
unset($_SESSION['shop_damage_success']);

// Get products for dropdown
$products = [];
try {
    $stmt = executeQuery("SELECT id, name, unit FROM products WHERE is_active = 1 ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load products: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_damage'])) {
    $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantityValue = $_POST['quantity'] ?? null;
    $unit = trim($_POST['unit'] ?? 'pieces');
    $reason = trim($_POST['reason'] ?? '');
    $damage_date = $_POST['damage_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    // Handle editing existing damage record
    $edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : null;

    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } elseif ($product_id === false || $product_id === null) {
        $error = 'Product is required';
    } elseif (!is_scalar($quantityValue) || trim((string) $quantityValue) === '' || !is_numeric($quantityValue)) {
        $error = 'Quantity must be a valid number';
    } else {
        $quantity = (float) $quantityValue;
        if (!is_finite($quantity) || $quantity <= 0 || $quantity > 999999.99) {
            $error = 'Quantity must be positive and within the permitted maximum';
        }
    }

    if (empty($error) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $damage_date)) {
        $error = 'Damage date is invalid';
    }
    if (empty($error) && $reason === '') {
        $error = 'Reason for damage is required';
    }

    if (empty($error)) {
        try {
            $pdo = getDBConnection();

            if (!$pdo->beginTransaction()) {
                throw new RuntimeException('Unable to start damage transaction.');
            }

            $product_stmt = $pdo->prepare("SELECT name, is_active FROM products WHERE id = ? FOR UPDATE");
            if (!$product_stmt->execute([$product_id])) {
                throw new RuntimeException('Product validation failed.');
            }
            $product = $product_stmt->fetch();
            if (!$product || !(bool) $product['is_active']) {
                throw new InvalidArgumentException('The selected product is inactive or unavailable.');
            }
            $product_name = $product['name'];

            if ($edit_id) {
                $existingStmt = $pdo->prepare(
                    "SELECT id, damage_date, product_id, quantity, damage_number
                     FROM shop_damaged_products
                     WHERE id = ? AND shop_attendant_id = ?
                     FOR UPDATE"
                );
                if (!$existingStmt->execute([$edit_id, $_SESSION['user_id']])) {
                    throw new RuntimeException('Existing damage validation failed.');
                }
                $existingDamage = $existingStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingDamage) {
                    throw new InvalidArgumentException('Damage record not found or not owned by this user.');
                }

                $movementStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(quantity_change), 0) AS net_quantity_change
                     FROM stock_movements
                                         WHERE reference_id = ? AND reference_type IN ('shop_damage', 'manual')
                                             AND movement_type = 'damage' AND reference_number = ?
                     FOR UPDATE"
                );
                                if (!$movementStmt->execute([$edit_id, $existingDamage['damage_number']])) {
                    throw new RuntimeException('Existing damage movement validation failed.');
                }
                $existingMovement = $movementStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingMovement || (float) $existingMovement['net_quantity_change'] >= 0) {
                    throw new RuntimeException('Existing damage movement is missing.');
                }

                $stmt = $pdo->prepare("
                    UPDATE shop_damaged_products
                    SET damage_date = ?, product_id = ?, product_name = ?, quantity = ?, unit = ?, reason = ?, notes = ?
                    WHERE id = ? AND shop_attendant_id = ?
                ");
                if (!$stmt->execute([
                    $damage_date,
                    $product_id,
                    $product_name,
                    $quantity,
                    $unit,
                    $reason,
                    $notes,
                    $edit_id,
                    $_SESSION['user_id']
                ])) {
                    throw new RuntimeException('Damage record update failed.');
                }

                $stockManager = new StockManager($pdo);
                $oldQuantity = abs((float) $existingMovement['net_quantity_change']);
                if (!$stockManager->recordMovement(
                    $existingDamage['product_id'],
                    'adjustment',
                    $oldQuantity,
                    'manual',
                    $edit_id,
                    $existingDamage['damage_number'],
                    'Reverse previous shop damage record for edit #' . $edit_id,
                    $_SESSION['user_id'],
                    $existingDamage['damage_date']
                )) {
                    throw new RuntimeException('Previous damage reversal failed.');
                }
                if (!$stockManager->recordMovement(
                    $product_id,
                    'damage',
                    -$quantity,
                    'manual',
                    $edit_id,
                    $existingDamage['damage_number'],
                    "Shop damage record #{$edit_id}: {$reason}",
                    $_SESSION['user_id'],
                    $damage_date
                )) {
                    throw new RuntimeException('Updated damage movement failed.');
                }

                $success = "Damaged product record updated successfully!";
            } else {
                // Generate damage number
                $damage_number = 'DAM-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("
                    INSERT INTO shop_damaged_products
                    (damage_number, damage_date, product_id, product_name, quantity, unit, reason,
                     shop_attendant_id, shop_attendant_name, notes, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                ");
                if (!$stmt->execute([
                    $damage_number,
                    $damage_date,
                    $product_id,
                    $product_name,
                    $quantity,
                    $unit,
                    $reason,
                    $_SESSION['user_id'],
                    $_SESSION['full_name'] ?? $_SESSION['username'],
                    $notes,
                    $_SESSION['user_id']
                ]) || $stmt->rowCount() !== 1) {
                    throw new RuntimeException('Damage record was not saved.');
                }

                $damage_id = $pdo->lastInsertId();

                if (!$damage_id) {
                    throw new RuntimeException('Damage record ID was not generated.');
                }

                $stockManager = new StockManager($pdo);
                if (!$stockManager->recordMovement(
                    $product_id,
                    'damage',
                    -$quantity,
                    'manual',
                    $damage_id,
                    $damage_number,
                    "Shop damage record #{$damage_id}: {$reason}",
                    $_SESSION['user_id'],
                    $damage_date
                )) {
                    throw new RuntimeException('Damage stock movement failed.');
                }

                $success = "Damaged product recorded successfully! Damage #: $damage_number. Stock movement created.";
            }

            if (!$pdo->commit()) {
                throw new RuntimeException('Damage transaction could not be committed.');
            }
            cache_clear();
            if ($success !== '') {
                $_SESSION['shop_damage_success'] = $success;
                header('Location: damaged.php');
                exit();
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Failed to record damaged product: ' . $e->getMessage());
            $error = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'Failed to record damaged product. Please try again.';
        }
    }
}

// Get recent damaged products for current shop attendant
$recent_damages = [];
try {
    $stmt = executeQuery("
        SELECT sdp.*,
               CASE
                   WHEN sdp.status = 'pending' THEN 'Pending'
                   WHEN sdp.status = 'acknowledged' THEN 'Acknowledged'
                   WHEN sdp.status = 'cleared' THEN 'Cleared'
               END as status_label
        FROM shop_damaged_products sdp
        WHERE sdp.shop_attendant_id = ?
        ORDER BY sdp.created_at DESC
        LIMIT 10
    ", [$_SESSION['user_id']]);
    $recent_damages = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load recent damages: " . $e->getMessage());
}

// Check if editing a specific record
$editing_damage = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    try {
        $stmt = executeQuery("
            SELECT * FROM shop_damaged_products
            WHERE id = ? AND shop_attendant_id = ?
        ", [$edit_id, $_SESSION['user_id']]);
        $editing_damage = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Failed to load damage record for editing: " . $e->getMessage());
    }
}

// Get today's total damaged quantity for current attendant
$today_total = 0;
try {
    $stmt = executeQuery("
        SELECT COALESCE(SUM(quantity), 0) as total
        FROM shop_damaged_products
        WHERE shop_attendant_id = ? AND DATE(damage_date) = CURDATE()
    ", [$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $today_total = $result['total'] ?? 0;
} catch (PDOException $e) {
    error_log("Failed to calculate today's total: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damaged Products - Merrylay Enterprises</title>

    <!-- CSS Framework -->
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">

    <style>
        .damage-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            transition: all var(--transition-normal);
        }

        .damage-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--border-dark);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background: rgb(251 191 36 / 0.1);
            color: #f59e0b;
            border: 1px solid rgb(251 191 36 / 0.3);
        }

        .status-acknowledged {
            background: rgb(59 130 246 / 0.1);
            color: #3b82f6;
            border: 1px solid rgb(59 130 246 / 0.3);
        }

        .status-cleared {
            background: rgb(16 185 129 / 0.1);
            color: #10b981;
            border: 1px solid rgb(16 185 129 / 0.3);
        }

        .total-box {
            background: linear-gradient(135deg, var(--red) 0%, var(--red-dark) 100%);
            color: white;
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow-lg);
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
                    <a href="damaged.php" class="nav-link active">
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
                        <input type="text" placeholder="Search damaged products..." id="globalSearch">
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
                            <i class="fas fa-exclamation-triangle" style="margin-right: 15px;"></i>
                            Damaged Products
                        </h1>
                        <p class="page-subtitle">
                            Record damaged products for accurate stock tracking
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
                                        <i class="fas fa-plus-circle" style="margin-right: 10px;"></i>
                                        <?php echo $editing_damage ? 'Edit Damaged Product' : 'Record Damaged Product'; ?>
                                    </h5>
                                    <p class="card-subtitle"><?php echo $editing_damage ? 'Update damaged product details' : 'Enter damaged product details'; ?></p>
                                </div>
                                <?php if ($editing_damage): ?>
                                    <a href="damaged.php" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="damageForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken()); ?>">
                                    <?php if ($editing_damage): ?>
                                        <input type="hidden" name="edit_id" value="<?php echo $editing_damage['id']; ?>">
                                    <?php endif; ?>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="product_id" class="form-label">Product *</label>
                                                <select name="product_id" id="product_id" class="form-select" required>
                                                    <option value="">Select Product</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?php echo $product['id']; ?>" <?php echo ($editing_damage && $editing_damage['product_id'] == $product['id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($product['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Select the damaged product</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="quantity" class="form-label">Quantity *</label>
                                                <div class="input-group">
                                                    <input type="number" name="quantity" id="quantity" class="form-control" placeholder="0.00" step="0.01" min="0.01" required value="<?php echo $editing_damage ? htmlspecialchars($editing_damage['quantity']) : ''; ?>">
                                                    <span class="input-group-text" id="unitLabel">pieces</span>
                                                </div>
                                                <small class="text-muted">Number of units damaged</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="reason" class="form-label">Reason for Damage *</label>
                                                <input type="text" name="reason" id="reason" class="form-control" placeholder="e.g., Broken, Expired, Leaking" required value="<?php echo $editing_damage ? htmlspecialchars($editing_damage['reason']) : ''; ?>">
                                                <small class="text-muted">Why was the product damaged</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="damage_date" class="form-label">Damage Date *</label>
                                                <input type="date" name="damage_date" id="damage_date" class="form-control" value="<?php echo $editing_damage ? htmlspecialchars($editing_damage['damage_date']) : date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label for="notes" class="form-label">Notes</label>
                                                <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Additional details (optional)"><?php echo $editing_damage ? htmlspecialchars($editing_damage['notes']) : ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="action-buttons mt-4">
                                        <button type="submit" name="record_damage" class="btn btn-danger">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <?php echo $editing_damage ? 'Update Damage' : 'Record Damage'; ?>
                                        </button>
                                        <button type="reset" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Summary & Recent Damages -->
                    <div class="col-lg-4">
                        <div class="content-card mb-3">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-chart-line"></i> Today's Summary
                                    </h5>
                                    <p class="card-subtitle">Damaged products recorded today</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="total-box">
                                    <small class="d-block mb-2">Total Damaged Today</small>
                                    <h3 class="mb-0"><?php echo number_format($today_total, 2); ?></h3>
                                    <small style="opacity: 0.8;">From <?php echo count($recent_damages); ?> record(s)</small>
                                </div>
                            </div>
                        </div>

                        <div class="content-card" style="position: sticky; top: 20px;">
                            <div class="card-header">
                                <div>
                                    <h5 class="card-title">
                                        <i class="fas fa-history"></i> Recent Damages
                                    </h5>
                                    <p class="card-subtitle">Your recent records</p>
                                </div>
                            </div>
                            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                <?php if (count($recent_damages) > 0): ?>
                                    <?php foreach ($recent_damages as $damage): ?>
                                        <div class="damage-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <div style="font-weight: 600; color: var(--primary-800);">
                                                        <?php echo htmlspecialchars($damage['damage_number']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($damage['product_name']); ?>
                                                    </small>
                                                </div>
                                                <span class="status-badge status-<?php echo $damage['status']; ?>">
                                                    <?php echo htmlspecialchars($damage['status_label']); ?>
                                                </span>
                                            </div>
                                            <div style="font-size: 12px; color: var(--primary-600); margin-bottom: 8px;">
                                                <?php echo htmlspecialchars($damage['reason']); ?>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div style="font-weight: 700; color: var(--red);">
                                                    <?php echo number_format($damage['quantity'], 2); ?> <?php echo htmlspecialchars($damage['unit']); ?>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <a href="damaged.php?edit_id=<?php echo $damage['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <small class="text-muted">
                                                        <?php echo date('M d, H:i', strtotime($damage['created_at'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center">No damaged products recorded yet</p>
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
        // Update unit label when product changes
        document.getElementById('product_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const unit = selectedOption.getAttribute('data-unit') || 'pieces';
            document.getElementById('unitLabel').textContent = unit;
        });

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

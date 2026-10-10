<?php
/**
 * Initial Opening Stock Setup for Merrylay Enterprises
 * 
 * One-time setup to enter initial stock quantities before using the system
 */

// Start session
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration and stock manager
require_once '../config/db.php';
require_once '../config/stock_manager.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Check permissions
if (!hasRole(['admin', 'manager'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized initial stock setup attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to initialize stock.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

// Check if initial stock has already been set up
$stmt = executeQuery("SELECT COUNT(*) as count FROM stock_movements WHERE movement_type = 'initial'");
$has_stock_data = $stmt->fetch()['count'] > 0;

$message = $_SESSION['initial_stock_success'] ?? '';
unset($_SESSION['initial_stock_success']);
$error = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected initial stock setup: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the stock details and submit again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    $action = $_POST['action'] ?? '';
    
    try {
        // Ensure we have a PDO instance available for transactions
        $pdo = getDBConnection();

        if ($action === 'save_initial_stock') {
            $stock_date = $_POST['stock_date'] ?? null;
            $stock_data = $_POST['stock'] ?? [];

            $stockDateObject = is_string($stock_date)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $stock_date)
                : false;
            $stockDateErrors = DateTimeImmutable::getLastErrors();
            if (!$stockDateObject
                || $stockDateObject->format('Y-m-d') !== $stock_date
                || ($stockDateErrors !== false && ($stockDateErrors['warning_count'] > 0 || $stockDateErrors['error_count'] > 0))) {
                throw new InvalidArgumentException('Stock date must be a valid YYYY-MM-DD date.');
            }
            if ($stock_date > date('Y-m-d')) {
                throw new InvalidArgumentException('Opening stock date cannot be in the future.');
            }
            
            if (!is_array($stock_data) || empty($stock_data)) {
                throw new InvalidArgumentException('Enter an opening quantity for at least one product.');
            }

            $pdo->beginTransaction();
            $stockManager = new StockManager($pdo);
            $stockManager->initializeOpeningBaselines($stock_data, $stock_date, $_SESSION['user_id']);
            $pdo->commit();
            $_SESSION['initial_stock_success'] = 'Initial opening stock has been set up successfully!';
            header('Location: initial_setup.php');
            exit();
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof InvalidArgumentException || $e instanceof DomainException) {
            $error = $e->getMessage();
        } else {
            error_log('Initial stock setup failed: ' . $e->getMessage());
            $error = 'Initial stock could not be saved. No stock changes were committed.';
        }
    }
}

// Get all active products
try {
    $stmt = executeQuery("SELECT id, name, sku, unit, quantity FROM products WHERE is_active = TRUE ORDER BY name");
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
    <meta name="description" content="Initial opening stock setup">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Initial Stock Setup - Merrylay Enterprises</title>
    
    <link href="<?php echo rtrim(BASE_URL, '/'); ?>/assets/fonts/inter/inter.css" rel="stylesheet">
    
    <!-- CSS Framework -->
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
                        <input type="text" placeholder="Search products..." id="globalSearch" aria-label="Search products">
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
                            <li class="breadcrumb-item"><a href="manage.php">Stock Management</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Opening baseline</li>
                        </ol>
                    </nav>
                    <h1 class="page-title">
                        <i class="fas fa-warehouse" style="margin-right: 15px;"></i>
                        Initial Opening Stock Setup
                    </h1>
                    <p class="page-subtitle">Set the physical opening balance before ordinary stock movements begin. This is a baseline, not a supply, sale, or adjustment.</p>
                </div>

                <div class="stock-baseline-note mb-4" role="note">
                    <strong>Opening baseline:</strong> choose the date the count applies to, then enter each product's physical quantity. A previous day's closing balance controls the next day's opening; existing activity cannot be overwritten here.
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

                <?php if ($has_stock_data): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                                An opening baseline is already recorded. Repeat or unsafe initialization is rejected; use <a href="manage.php">Stock Management</a> for normal stock activity.
                    </div>
                <?php endif; ?>

                <!-- Initial Stock Setup Form -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">
                                <i class="fas fa-boxes me-2"></i>
                                Set Initial Stock Quantities
                            </h5>
                            <p class="card-subtitle">Enter the current stock levels for all products</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post" id="initialStockForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_initial_stock">
                            
                            <div class="form-group mb-4">
                                <label class="form-label" for="opening-stock-date">Opening date <span style="color: var(--red); font-weight: 700;">*</span></label>
                                <input type="date" id="opening-stock-date" name="stock_date" class="form-control" style="max-width: 250px;" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px;">#</th>
                                            <th>Product Name</th>
                                            <th>SKU</th>
                                            <th>Unit</th>
                                            <th style="width: 200px;">Initial Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($products)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">
                                                    No products found. Please add products first.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($products as $index => $product): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                    <td><code><?php echo htmlspecialchars($product['sku'] ?: 'N/A'); ?></code></td>
                                                    <td><?php echo htmlspecialchars($product['unit']); ?></td>
                                                    <td>
                                                        <label class="visually-hidden" for="opening-quantity-<?php echo (int) $product['id']; ?>">Opening quantity for <?php echo htmlspecialchars($product['name']); ?></label>
                                                        <input type="number" 
                                                            id="opening-quantity-<?php echo (int) $product['id']; ?>"
                                                               name="stock[<?php echo $product['id']; ?>]" 
                                                               class="form-control" 
                                                               step="0.25" 
                                                               min="0" 
                                                               value="<?php echo $product['quantity'] > 0 ? $product['quantity'] : ''; ?>"
                                                               placeholder="0">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="action-buttons mt-4">
                                <a href="manage.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to Stock Management
                                </a>
                                <button type="submit" class="btn btn-primary" id="saveInitialStockBtn">
                                    <i class="fas fa-save"></i>
                                    Save Initial Stock
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Information Card -->
                <div class="content-card mt-4" style="background: linear-gradient(135deg, #e0f2fe, #bae6fd); border: 1px solid var(--blue);">
                    <div class="card-header" style="background: linear-gradient(135deg, rgba(224, 242, 254, 0.5), rgba(186, 230, 253, 0.3)); border-left: 4px solid var(--blue);">
                        <h5 class="card-title" style="color: #0369a1;">
                            <i class="fas fa-info-circle"></i>
                            Important Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <ul style="list-style: none; padding: 0;">
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">•</span>
                                <span>This is a one-time setup to enter your current stock levels before using the system.</span>
                            </li>
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">•</span>
                                <span>Enter the actual physical stock count for each product.</span>
                            </li>
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">•</span>
                                <span>After setup, use the <strong>Supplies</strong> page to add inbound stock and <strong>Daily Stock</strong> to manage daily operations.</span>
                            </li>
                            <li style="padding: 8px 0; color: #0c4a6e; font-weight: 500; display: flex; align-items: flex-start; gap: 12px;">
                                <span style="color: var(--blue); font-weight: 700; font-size: 1.2em;">•</span>
                                <span>The system will automatically carry forward closing stock as opening stock for the next day.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/js/offline-handler.js"></script>
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

<?php
/**
 * Header Component for Merrylay Enterprises
 * 
 * Reusable header component with consistent branding and navigation
 */

require_once __DIR__ . '/../config/session.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once __DIR__ . '/../config/security.php';
$logoutCsrfToken = SecurityUtils::generateCSRFToken();

// Check if user is logged in for protected areas
$isProtectedArea = isset($protectedArea) && $protectedArea === true;
if ($isProtectedArea && !isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$appBaseUrl = defined('BASE_URL') ? BASE_URL : '';
if (empty($appBaseUrl)) {
    $appBaseUrl = '/merrylay_system';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo $metaDescription ?? 'Professional inventory and sales management system'; ?>">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="keywords" content="inventory, sales, management, business, POS, retail">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; object-src 'none';">
    <meta name="theme-color" content="#1e293b">
    <title><?php echo $pageTitle ?? 'Merrylay Enterprises'; ?></title>
    <base href="<?php echo rtrim($appBaseUrl, '/'); ?>/">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?php echo $appBaseUrl . '/manifest.json'; ?>">
    
    <!-- Local vendor CSS - works offline and on isolated networks without CDN access -->
    <link rel="stylesheet" href="<?php echo $appBaseUrl . '/assets/vendor/bootstrap/bootstrap.min.css'; ?>">
    <link rel="stylesheet" href="<?php echo $appBaseUrl . '/assets/vendor/fontawesome/css/all.min.css'; ?>">
    <link rel="stylesheet" href="<?php echo $appBaseUrl . '/assets/fonts/inter/inter.css'; ?>">
    
    <!-- OPTIMIZED: Load custom CSS asynchronously -->
    <link rel="preload" href="<?php echo $appBaseUrl . '/assets/css/unified-design-system.css'; ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo $appBaseUrl . '/assets/css/unified-design-system.css'; ?>"></noscript>
    
    <!-- Page-specific styles -->

    <?php if (isset($customStyles)): ?>
    <style><?php echo $customStyles; ?></style>
    <?php endif; ?>
    
    <!-- Offline Support Scripts - Load before other scripts -->
    <script src="<?php echo $appBaseUrl . '/assets/js/offline-database.js'; ?>" defer></script>
    <script src="<?php echo $appBaseUrl . '/assets/js/offline-sync-manager.js'; ?>" defer></script>
    <script src="<?php echo $appBaseUrl . '/assets/js/offline-assets-loader.js'; ?>" defer></script>
    <script src="<?php echo $appBaseUrl . '/assets/js/auth-manager.js'; ?>" defer></script>
    <script src="<?php echo $appBaseUrl . '/assets/js/data-preloader.js'; ?>" defer></script>
    <script src="<?php echo $appBaseUrl . '/assets/js/conflict-resolver.js'; ?>" defer></script>
</head>
<body class="<?php echo $bodyClass ?? ''; ?>">
    <?php if ($isProtectedArea): ?>
    <!-- Professional Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="<?php echo $appBaseUrl . '/dashboard/index.php'; ?>" class="logo">
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
            <div class="nav-section-title overview">Main Operations</div>
            <a href="<?php echo $appBaseUrl . '/dashboard/index.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                <span class="nav-text">Dashboard</span>
            </a>
            
            <?php if (hasRole(['admin', 'manager'])): ?>
            <a href="<?php echo $appBaseUrl . '/products/view.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'view.php' && strpos($_SERVER['PHP_SELF'], 'products') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                <span class="nav-text">Products</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/products/add.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'add.php' && strpos($_SERVER['PHP_SELF'], 'products') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-plus"></i></span>
                <span class="nav-text">Add Product</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/stock/manage.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                <span class="nav-text">Stock</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/stock/supplies.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'supplies.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-truck-loading"></i></span>
                <span class="nav-text">Supplies</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/stock/initial_setup.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'initial_setup.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-play-circle"></i></span>
                <span class="nav-text">Initial Stock Setup</span>
            </a>
            <?php endif; ?>
            
            <?php if (canRecordDispatches()): ?>
            <div class="nav-section-title dispatch">Dispatch Operations</div>
            <a href="<?php echo $appBaseUrl . '/dispatch/trips/record.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'record.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                <span class="nav-text">New Dispatch</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/dispatch/trips/index.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-list"></i></span>
                <span class="nav-text">Manage Dispatches</span>
            </a>
            <?php if (hasRole(['admin', 'manager'])): ?>
            <a href="<?php echo $appBaseUrl . '/dispatch/drivers.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'drivers.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-users"></i></span>
                <span class="nav-text">Dispatchers</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if (canRecordFinancials()): ?>
            <div class="nav-section-title sales">Sales & Finance</div>
            <a href="<?php echo $appBaseUrl . '/dispatch/daily/index.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' && strpos($_SERVER['PHP_SELF'], 'daily') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                <span class="nav-text">Daily Sales</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/dispatch/daily/account-summary.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'account-summary.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                <span class="nav-text">Account Summary</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/finance/daily_balance.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'daily_balance.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                <span class="nav-text">Daily Balance</span>
            </a>
            <?php if (isCashier()): ?>
            <a href="<?php echo $appBaseUrl . '/cashier/deposit.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'deposit.php' && strpos($_SERVER['PHP_SELF'], 'cashier') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-university"></i></span>
                <span class="nav-text">Bank Deposit</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if (hasRole(['admin', 'manager'])): ?>
            <div class="nav-section-title admin">Management</div>
            <a href="<?php echo $appBaseUrl . '/admin/end-of-day.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'end-of-day.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                <span class="nav-text">End of Day</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/reports/daily.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'daily.php' && strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                <span class="nav-text">Reports</span>
            </a>
            <a href="<?php echo $appBaseUrl . '/users/manage.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'users') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                <span class="nav-text">Users</span>
            </a>
            <?php if (hasRole(['admin'])): ?>
            <a href="<?php echo $appBaseUrl . '/admin/data_management.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'data_management.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-database"></i></span>
                <span class="nav-text">Data Management</span>
            </a>
            <div class="nav-section-title admin">System</div>
            <a href="<?php echo $appBaseUrl . '/admin/backup.php'; ?>" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'backup.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-server"></i></span>
                <span class="nav-text">Database Backup</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo htmlspecialchars($appBaseUrl . '/auth/logout.php', ENT_QUOTES, 'UTF-8'); ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="nav-link border-0 bg-transparent w-100 text-start">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </button>
            </form>
        </nav>
    </aside>

    <!-- Main Content Area -->
    <main class="main-content" id="mainContent">
        <!-- Top Bar -->
        <header class="top-bar">
            <div class="d-flex align-items-center gap-3">
                <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search products, sales, dispatches..." id="globalSearch">
                </div>
            </div>

            <div class="top-bar-actions">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn position-relative" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if (isset($low_stock_count) && $low_stock_count > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
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
                            <li><a class="dropdown-item" href="<?php echo $appBaseUrl . '/auth/profile.php'; ?>"><i class="fas fa-user me-2"></i> Profile</a></li>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <li><a class="dropdown-item" href="<?php echo $appBaseUrl . '/auth/settings.php'; ?>"><i class="fas fa-cog me-2"></i> Settings</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="<?php echo htmlspecialchars($appBaseUrl . '/auth/logout.php', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt me-2"></i> Logout</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Dashboard Content -->
        <div class="dashboard-content">
    <?php else: ?>
    <!-- Public Area Header -->
    <header class="public-header">
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container">
                <a class="navbar-brand" href="<?php echo $appBaseUrl . '/index.php'; ?>">
                    <i class="fas fa-store me-2"></i>
                    Merrylay Enterprises
                </a>
            </div>
        </nav>
    </header>
    <div class="container">
    <?php endif; ?>


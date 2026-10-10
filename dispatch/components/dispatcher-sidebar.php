<?php
/**
 * Shared dispatcher sidebar for all dispatch pages.
 */

if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once __DIR__ . '/../../config/security.php';
$logoutCsrfToken = SecurityUtils::generateCSRFToken();

function dispatcher_link_path($path) {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function dispatcher_active($needle) {
    $scriptPath = str_replace('\\', '/', $_SERVER['PHP_SELF']);
    return strpos($scriptPath, $needle) !== false ? 'active' : '';
}
?>

<nav class="sidebar-nav">
    <div class="nav-section-title overview">Main Operations</div>
    <a href="<?php echo dispatcher_link_path('dashboard/index.php'); ?>" class="nav-link <?php echo dispatcher_active('/dashboard/index.php'); ?>">
        <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
        <span class="nav-text">Dashboard</span>
    </a>

    <?php if (hasRole(['admin', 'manager'])): ?>
    <a href="<?php echo dispatcher_link_path('products/view.php'); ?>" class="nav-link <?php echo dispatcher_active('/products/view.php'); ?>">
        <span class="nav-icon"><i class="fas fa-boxes"></i></span>
        <span class="nav-text">Products</span>
    </a>
    <?php endif; ?>

    <?php if (hasRole(['admin', 'manager', 'dispatcher'])): ?>
    <div class="nav-section-title stock">Stock Management</div>
    <a href="<?php echo dispatcher_link_path('stock/manage.php'); ?>" class="nav-link <?php echo dispatcher_active('/stock/manage.php'); ?>">
        <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
        <span class="nav-text">Stock Manage</span>
    </a>
    <a href="<?php echo dispatcher_link_path('stock/summary.php'); ?>" class="nav-link <?php echo dispatcher_active('/stock/summary.php'); ?>">
        <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
        <span class="nav-text">Stock Summary</span>
    </a>
    <a href="<?php echo dispatcher_link_path('stock/analysis.php'); ?>" class="nav-link <?php echo dispatcher_active('/stock/analysis.php'); ?>">
        <span class="nav-icon"><i class="fas fa-search-dollar"></i></span>
        <span class="nav-text">Stock Analysis</span>
    </a>
    <a href="<?php echo dispatcher_link_path('stock/initial_setup.php'); ?>" class="nav-link <?php echo dispatcher_active('/stock/initial_setup.php'); ?>">
        <span class="nav-icon"><i class="fas fa-play-circle"></i></span>
        <span class="nav-text">Initial Stock Setup</span>
    </a>
    <?php if (hasRole(['admin', 'manager'])): ?>
    <a href="<?php echo dispatcher_link_path('stock/supplies.php'); ?>" class="nav-link <?php echo dispatcher_active('/stock/supplies.php'); ?>">
        <span class="nav-icon"><i class="fas fa-truck-loading"></i></span>
        <span class="nav-text">Supplies</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (canRecordDispatches()): ?>
    <div class="nav-section-title dispatch">Dispatch Operations</div>
    <?php if (hasRole(['admin', 'manager'])): ?>
    <a href="<?php echo dispatcher_link_path('dispatch/drivers.php'); ?>" class="nav-link <?php echo dispatcher_active('/dispatch/drivers.php'); ?>">
        <span class="nav-icon"><i class="fas fa-users"></i></span>
        <span class="nav-text">Drivers</span>
    </a>
    <a href="<?php echo dispatcher_link_path('dispatch/vehicles.php'); ?>" class="nav-link <?php echo dispatcher_active('/dispatch/vehicles.php'); ?>">
        <span class="nav-icon"><i class="fas fa-truck"></i></span>
        <span class="nav-text">Vehicles</span>
    </a>
    <?php endif; ?>
    <a href="<?php echo dispatcher_link_path('dispatch/trips/index.php'); ?>" class="nav-link <?php echo dispatcher_active('/dispatch/trips/index.php'); ?>">
        <span class="nav-icon"><i class="fas fa-route"></i></span>
        <span class="nav-text">Manage Trip</span>
    </a>
    <?php endif; ?>

    <?php if (isShopAttendant() || hasRole(['admin', 'manager'])): ?>
    <div class="nav-section-title sales">Shop Operations</div>
    <a href="<?php echo dispatcher_link_path('shop/index.php'); ?>" class="nav-link <?php echo dispatcher_active('/shop/index.php'); ?>">
        <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span>
        <span class="nav-text">Shop Sales</span>
    </a>
    <a href="<?php echo dispatcher_link_path('shop/history.php'); ?>" class="nav-link <?php echo dispatcher_active('/shop/history.php'); ?>">
        <span class="nav-icon"><i class="fas fa-history"></i></span>
        <span class="nav-text">Sales History</span>
    </a>
    <a href="<?php echo dispatcher_link_path('shop/collections.php'); ?>" class="nav-link <?php echo dispatcher_active('/shop/collections.php'); ?>">
        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
        <span class="nav-text">Expenses</span>
    </a>
    <a href="<?php echo dispatcher_link_path('shop/collection-history.php'); ?>" class="nav-link <?php echo dispatcher_active('/shop/collection-history.php'); ?>">
        <span class="nav-icon"><i class="fas fa-list-alt"></i></span>
        <span class="nav-text">Expense History</span>
    </a>
    <a href="<?php echo dispatcher_link_path('shop/daily-accounting.php'); ?>" class="nav-link <?php echo dispatcher_active('/shop/daily-accounting.php'); ?>">
        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
        <span class="nav-text">Daily Accounting</span>
    </a>
    <?php endif; ?>

    <?php if (canRecordFinancials() && !isShopAttendant() || hasRole(['admin', 'manager'])): ?>
    <div class="nav-section-title sales">Sales & Finance</div>
    <a href="<?php echo dispatcher_link_path('dispatch/daily/index.php'); ?>" class="nav-link <?php echo dispatcher_active('/dispatch/daily/index.php'); ?>">
        <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
        <span class="nav-text">Daily Sales</span>
    </a>
    <a href="<?php echo dispatcher_link_path('dispatch/daily/today-sales.php'); ?>" class="nav-link <?php echo dispatcher_active('/dispatch/daily/today-sales.php'); ?>">
        <span class="nav-icon"><i class="fas fa-calendar-day"></i></span>
        <span class="nav-text">Today's Sales</span>
    </a>
    <a href="<?php echo dispatcher_link_path('dispatch/daily/account-summary.php'); ?>" class="nav-link <?php echo dispatcher_active('/dispatch/daily/account-summary.php'); ?>">
        <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
        <span class="nav-text">Account Summary</span>
    </a>
    <a href="<?php echo dispatcher_link_path('finance/daily_balance.php'); ?>" class="nav-link <?php echo dispatcher_active('/finance/daily_balance.php'); ?>">
        <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
        <span class="nav-text">Daily Balance</span>
    </a>
    <?php endif; ?>

    <?php if (hasRole(['admin', 'manager']) || isCashier()): ?>
    <div class="nav-section-title admin">Account</div>
    <a href="<?php echo dispatcher_link_path('dispatch/credit/index.php'); ?>" class="nav-link <?php echo dispatcher_active('/dispatch/credit/index.php'); ?>">
        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
        <span class="nav-text">Credit Dashboard</span>
    </a>
    <?php if (isCashier()): ?>
    <a href="<?php echo dispatcher_link_path('cashier/deposit.php'); ?>" class="nav-link <?php echo dispatcher_active('/cashier/deposit.php'); ?>">
        <span class="nav-icon"><i class="fas fa-university"></i></span>
        <span class="nav-text">Bank Deposit</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (hasRole(['admin', 'manager'])): ?>
    <div class="nav-section-title admin">Management</div>
    <a href="<?php echo dispatcher_link_path('reports/daily.php'); ?>" class="nav-link <?php echo dispatcher_active('/reports/daily.php'); ?>">
        <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
        <span class="nav-text">Daily Reports</span>
    </a>
    <a href="<?php echo dispatcher_link_path('admin/end-of-day.php'); ?>" class="nav-link <?php echo dispatcher_active('/admin/end-of-day.php'); ?>">
        <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
        <span class="nav-text">End of Day</span>
    </a>
    <?php endif; ?>

    <?php if (canManageUsers()): ?>
    <div class="nav-section-title admin">Administration</div>
    <a href="<?php echo dispatcher_link_path('users/manage.php'); ?>" class="nav-link <?php echo dispatcher_active('/users/manage.php'); ?>">
        <span class="nav-icon"><i class="fas fa-users"></i></span>
        <span class="nav-text">User Management</span>
    </a>
    <?php if (hasRole('admin')): ?>
    <a href="<?php echo dispatcher_link_path('admin/data_management.php'); ?>" class="nav-link <?php echo dispatcher_active('/admin/data_management.php'); ?>">
        <span class="nav-icon"><i class="fas fa-database"></i></span>
        <span class="nav-text">Data Management</span>
    </a>
    <a href="<?php echo dispatcher_link_path('admin/backup.php'); ?>" class="nav-link <?php echo dispatcher_active('/admin/backup.php'); ?>">
        <span class="nav-icon"><i class="fas fa-save"></i></span>
        <span class="nav-text">Database Backup</span>
    </a>
    <a href="<?php echo dispatcher_link_path('auth/settings.php'); ?>" class="nav-link <?php echo dispatcher_active('/auth/settings.php'); ?>">
        <span class="nav-icon"><i class="fas fa-cog"></i></span>
        <span class="nav-text">Settings</span>
    </a>
    <a href="<?php echo dispatcher_link_path('WORKFLOW_GUIDE.md'); ?>" class="nav-link" target="_blank">
        <span class="nav-icon"><i class="fas fa-book"></i></span>
        <span class="nav-text">Workflow Guide</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <div class="nav-section-title">System</div>
    <form method="POST" action="<?php echo dispatcher_link_path('auth/logout.php'); ?>" class="m-0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="nav-link border-0 bg-transparent w-100 text-start">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="nav-text">Logout</span>
        </button>
    </form>
</nav>


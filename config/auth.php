<?php
/**
 * Authentication and Authorization Helper
 * 
 * Provides role-based and permission-based access control functions
 */

// Include permissions system
require_once __DIR__ . '/permissions.php';

if (!function_exists('hasRole')) {
    /**
     * Check if current user has a specific role
     * @param string|array $roles Single role or array of roles
     * @return bool
     */
    function hasRole($roles) {
        if (!isset($_SESSION['role'])) {
            return false;
        }
        
        if (is_array($roles)) {
            return in_array($_SESSION['role'], $roles);
        }
        
        return $_SESSION['role'] === $roles;
    }
}

if (!function_exists('requireRole')) {
    /**
     * Require user to have specific role(s), redirect if not
     * @param string|array $roles Single role or array of roles
     * @param string $redirectUrl URL to redirect to if unauthorized
     */
    function requireRole($roles, $redirectUrl = '../dashboard/index.php') {
        if (!hasRole($roles)) {
            header('Location: ' . $redirectUrl);
            exit();
        }
    }
}

if (!function_exists('canManageDispatches')) {
    /**
     * Check if user can manage dispatches (edit/delete)
     * Checks both role and individual permissions
     * @return bool
     */
    function canManageDispatches() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check role-based permission (dispatcher removed - they can only view, not edit)
        if (hasRole(['manager'])) return true;
        
        // Check individual permission
        return hasPermission(PERMISSION_DISPATCH_EDIT) || hasPermission(PERMISSION_DISPATCH_DELETE);
    }
}

if (!function_exists('canRecordDispatches')) {
    /**
     * Check if user can record dispatches (create new dispatches)
     * This is for dispatch creation for vehicles - NOT for shop attendants
     * Checks both role and individual permissions
     * @return bool
     */
    function canRecordDispatches() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check role-based permission
        if (hasRole(['dispatcher', 'manager'])) return true;
        
        // Shop attendants should NOT access dispatch operations
        // They use the direct sales system instead
        if (hasRole(['shop_attendant'])) return false;
        
        // Check individual permission
        return hasPermission(PERMISSION_DISPATCH_CREATE);
    }
}

if (!function_exists('canViewDispatches')) {
    /**
     * Check if user can view dispatches
     * Checks both role and individual permissions
     * @return bool
     */
    function canViewDispatches() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check role-based permission
        if (hasRole(['manager', 'dispatcher', 'cashier'])) return true;
        
        // Check individual permission
        return hasPermission(PERMISSION_DISPATCH_VIEW);
    }
}

if (!function_exists('isDispatcher')) {
    /**
     * Check if user is a dispatcher
     * @return bool
     */
    function isDispatcher() {
        return hasRole(['dispatcher']);
    }
}

if (!function_exists('isShopAttendant')) {
    /**
     * Check if user is a shop attendant
     * @return bool
     */
    function isShopAttendant() {
        return hasRole(['shop_attendant']);
    }
}

if (!function_exists('isCashier')) {
    /**
     * Check if user is a cashier
     * @return bool
     */
    function isCashier() {
        return hasRole(['cashier']);
    }
}

if (!function_exists('canRecordTripReturns')) {
    /**
     * Check if user can record trip returns (cashier records results)
     * @return bool
     */
    function canRecordTripReturns() {
        return hasRole(['admin', 'manager', 'cashier']);
    }
}

if (!function_exists('canViewAllDispatches')) {
    /**
     * Check if user can view all dispatches (not just their own)
     * Manager can view all, dispatcher can view all d (edit/delete)ispatches they created
     * @return bool
     */
    function canViewAllDispatches() {
        return hasRole(['admin', 'dispatcher']);
    }
}

if (!function_exists('canManageFinancials')) {
    /**
     * Check if user can manage financial transactions (edit/delete)
     * Checks both role and individual permissions
     * @return bool
     */
    function canManageFinancials() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check role-based permission
        if (hasRole(['cashier'])) return true;
        
        // Check individual permission
        return hasPermission(PERMISSION_FINANCE_EDIT) || hasPermission(PERMISSION_FINANCE_DELETE);
    }
}

if (!function_exists('canViewFinancials')) {
    /**
     * Check if user can view financial data
     * Checks both role and individual permissions
     * @return bool
     */
    function canViewFinancials() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check role-based permission
        if (hasRole(['manager', 'cashier', 'dispatcher'])) return true;
        
        // Check individual permission
        return hasPermission(PERMISSION_FINANCE_VIEW) || hasPermission(PERMISSION_SALES_VIEW);
    }
}

if (!function_exists('canRecordFinancials')) {
    /**
     * Check if user can record financial transactions
     * Checks both role and individual permissions
     * @return bool
     */
    function canRecordFinancials() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check role-based permission
        if (hasRole(['cashier', 'dispatcher'])) return true;
        
        // Default: deny access
        return false;
    }
}

if (!function_exists('canRecordBankDeposits')) {
    /**
     * Check if user can record bank deposits
     * @return bool
     */
    function canRecordBankDeposits() {
        return hasRole(['admin', 'cashier']);
    }
}

if (!function_exists('canViewAllFinancials')) {
    /**
     * Check if user can view all financial activities
     * @return bool
     */
    function canViewAllFinancials() {
        return hasRole(['admin', 'manager']);
    }
}

if (!function_exists('canManageUsers')) {
    /**
     * Check if user can manage users (edit/delete)
     * Checks both role and individual permissions
     * @return bool
     */
    function canManageUsers() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check individual permission
        return hasPermission(PERMISSION_USERS_EDIT) || hasPermission(PERMISSION_USERS_DELETE);
    }
}

if (!function_exists('canViewUsers')) {
    /**
     * Check if user can view users
     * Checks both role and individual permissions
     * @return bool
     */
    function canViewUsers() {
        // Admin always has permission
        if (hasRole('admin')) return true;
        
        // Check role-based permission
        if (hasRole(['manager'])) return true;
        
        // Check individual permission
        return hasPermission(PERMISSION_USERS_VIEW);
    }
}

if (!function_exists('getDispatcherName')) {
    /**
     * Get the dispatcher name for the current user
     * @return string|null
     */
    function getDispatcherName() {
        if (hasRole('dispatcher') && isset($_SESSION['full_name'])) {
            return $_SESSION['full_name'];
        }
        return null;
    }
}

if (!function_exists('getUserId')) {
    /**
     * Get current user ID
     * @return int|null
     */
    function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getUsername')) {
    /**
     * Get current username
     * @return string|null
     */
    function getUsername() {
        return $_SESSION['username'] ?? null;
    }
}

if (!function_exists('getCurrentRole')) {
    /**
     * Get current user role
     * @return string|null
     */
    function getCurrentRole() {
        return $_SESSION['role'] ?? null;
    }
}

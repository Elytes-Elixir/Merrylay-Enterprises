<?php
/**
 * Middleware Class
 * 
 * Common middleware functions for authentication and authorization
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

class Middleware {
    /**
     * Require authentication
     * Redirects to login if not authenticated
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function auth($redirectUrl = null) {
        if (!isset($_SESSION['user_id'])) {
            Router::redirect($redirectUrl ?? (BASE_URL . '/auth/login.php'));
            return false;
        }
        return true;
    }
    
    /**
     * Require specific role(s)
     * 
     * @param string|array $roles Role or array of roles
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function role($roles, $redirectUrl = null) {
        if (!self::auth()) {
            return false;
        }
        
        if (!hasRole($roles)) {
            Router::redirect($redirectUrl ?? (BASE_URL . '/dashboard/index.php'));
            return false;
        }
        
        return true;
    }
    
    /**
     * Require admin role
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function admin($redirectUrl = null) {
        return self::role('admin', $redirectUrl);
    }
    
    /**
     * Require shop attendant role
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function shopAttendant($redirectUrl = null) {
        return self::role('shop_attendant', $redirectUrl);
    }
    
    /**
     * Require dispatcher role
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function dispatcher($redirectUrl = null) {
        return self::role('dispatcher', $redirectUrl);
    }
    
    /**
     * Require cashier role
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function cashier($redirectUrl = '/dashboard/index.php') {
        return self::role('cashier', $redirectUrl);
    }
    
    /**
     * Require manager role
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function manager($redirectUrl = '/dashboard/index.php') {
        return self::role('manager', $redirectUrl);
    }
    
    /**
     * Check permission for dispatch management
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function canManageDispatches($redirectUrl = '/dashboard/index.php') {
        if (!self::auth()) {
            return false;
        }
        
        if (!canManageDispatches()) {
            Router::redirect($redirectUrl);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check permission for recording dispatches
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function canRecordDispatches($redirectUrl = '/dashboard/index.php') {
        if (!self::auth()) {
            return false;
        }
        
        if (!canRecordDispatches()) {
            Router::redirect($redirectUrl);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check permission for viewing financials
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function canViewFinancials($redirectUrl = '/dashboard/index.php') {
        if (!self::auth()) {
            return false;
        }
        
        if (!canViewFinancials()) {
            Router::redirect($redirectUrl);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check permission for managing financials
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function canManageFinancials($redirectUrl = '/dashboard/index.php') {
        if (!self::auth()) {
            return false;
        }
        
        if (!canManageFinancials()) {
            Router::redirect($redirectUrl);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check permission for managing users
     * 
     * @param string $redirectUrl URL to redirect to
     * @return bool
     */
    public static function canManageUsers($redirectUrl = '/dashboard/index.php') {
        if (!self::auth()) {
            return false;
        }
        
        if (!canManageUsers()) {
            Router::redirect($redirectUrl);
            return false;
        }
        
        return true;
    }
    
    /**
     * CSRF protection middleware
     * Validates CSRF token for POST requests
     * 
     * @return bool
     */
    public static function csrf() {
        if (Request::isPost()) {
            $token = Request::post('csrf_token');
            if (!$token || $token !== ($_SESSION['csrf_token'] ?? '')) {
                Response::error('CSRF token validation failed', 403);
                return false;
            }
        }
        return true;
    }
    
    /**
     * Generate CSRF token
     * 
     * @return string
     */
    public static function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

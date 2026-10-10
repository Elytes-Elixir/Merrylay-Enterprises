<?php
/**
 * Permission Management System
 * 
 * Provides granular permission control beyond role-based access control.
 * Admins can assign specific permissions to individual users.
 */

// Permission constants
if (!defined('PERMISSION_DISPATCH_VIEW')) define('PERMISSION_DISPATCH_VIEW', 'dispatch_view');
if (!defined('PERMISSION_DISPATCH_CREATE')) define('PERMISSION_DISPATCH_CREATE', 'dispatch_create');
if (!defined('PERMISSION_DISPATCH_EDIT')) define('PERMISSION_DISPATCH_EDIT', 'dispatch_edit');
if (!defined('PERMISSION_DISPATCH_DELETE')) define('PERMISSION_DISPATCH_DELETE', 'dispatch_delete');
if (!defined('PERMISSION_DISPATCH_VIEW_ALL')) define('PERMISSION_DISPATCH_VIEW_ALL', 'dispatch_view_all');

if (!defined('PERMISSION_SALES_VIEW')) define('PERMISSION_SALES_VIEW', 'sales_view');
if (!defined('PERMISSION_SALES_CREATE')) define('PERMISSION_SALES_CREATE', 'sales_create');
if (!defined('PERMISSION_SALES_EDIT')) define('PERMISSION_SALES_EDIT', 'sales_edit');
if (!defined('PERMISSION_SALES_DELETE')) define('PERMISSION_SALES_DELETE', 'sales_delete');

if (!defined('PERMISSION_FINANCE_VIEW')) define('PERMISSION_FINANCE_VIEW', 'finance_view');
if (!defined('PERMISSION_FINANCE_CREATE')) define('PERMISSION_FINANCE_CREATE', 'finance_create');
if (!defined('PERMISSION_FINANCE_EDIT')) define('PERMISSION_FINANCE_EDIT', 'finance_edit');
if (!defined('PERMISSION_FINANCE_DELETE')) define('PERMISSION_FINANCE_DELETE', 'finance_delete');
if (!defined('PERMISSION_BANK_DEPOSITS')) define('PERMISSION_BANK_DEPOSITS', 'bank_deposits');

if (!defined('PERMISSION_PRODUCTS_VIEW')) define('PERMISSION_PRODUCTS_VIEW', 'products_view');
if (!defined('PERMISSION_PRODUCTS_CREATE')) define('PERMISSION_PRODUCTS_CREATE', 'products_create');
if (!defined('PERMISSION_PRODUCTS_EDIT')) define('PERMISSION_PRODUCTS_EDIT', 'products_edit');
if (!defined('PERMISSION_PRODUCTS_DELETE')) define('PERMISSION_PRODUCTS_DELETE', 'products_delete');
if (!defined('PERMISSION_STOCK_VIEW')) define('PERMISSION_STOCK_VIEW', 'stock_view');
if (!defined('PERMISSION_STOCK_MANAGE')) define('PERMISSION_STOCK_MANAGE', 'stock_manage');

if (!defined('PERMISSION_USERS_VIEW')) define('PERMISSION_USERS_VIEW', 'users_view');
if (!defined('PERMISSION_USERS_CREATE')) define('PERMISSION_USERS_CREATE', 'users_create');
if (!defined('PERMISSION_USERS_EDIT')) define('PERMISSION_USERS_EDIT', 'users_edit');
if (!defined('PERMISSION_USERS_DELETE')) define('PERMISSION_USERS_DELETE', 'users_delete');
if (!defined('PERMISSION_USERS_MANAGE_PERMISSIONS')) define('PERMISSION_USERS_MANAGE_PERMISSIONS', 'users_manage_permissions');

if (!defined('PERMISSION_REPORTS_VIEW')) define('PERMISSION_REPORTS_VIEW', 'reports_view');
if (!defined('PERMISSION_REPORTS_GENERATE')) define('PERMISSION_REPORTS_GENERATE', 'reports_generate');
if (!defined('PERMISSION_REPORTS_EXPORT')) define('PERMISSION_REPORTS_EXPORT', 'reports_export');

if (!defined('PERMISSION_SYSTEM_SETTINGS')) define('PERMISSION_SYSTEM_SETTINGS', 'system_settings');
if (!defined('PERMISSION_SYSTEM_BACKUP')) define('PERMISSION_SYSTEM_BACKUP', 'system_backup');
if (!defined('PERMISSION_SYSTEM_AUDIT')) define('PERMISSION_SYSTEM_AUDIT', 'system_audit');

/**
 * Check if user has a specific permission
 * @param string $permissionKey The permission key to check
 * @param int|null $userId User ID (defaults to current user)
 * @return bool
 */
if (!function_exists('hasPermission')) {
    function hasPermission($permissionKey, $userId = null) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        
        if (!$userId) {
            return false;
        }
        
        // Admin always has all permissions
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            return true;
        }
        
        static $userPermissions = [];
        
        // Cache permissions for the user
        if (!isset($userPermissions[$userId])) {
            $userPermissions[$userId] = getUserPermissions($userId);
        }
        
        return in_array($permissionKey, $userPermissions[$userId]);
    }
}

/**
 * Get all permissions for a user (from role + individual assignments)
 * @param int $userId User ID
 * @return array Array of permission keys
 */
if (!function_exists('getUserPermissions')) {
    function getUserPermissions($userId) {
        try {
            $pdo = getDBConnection();
            
            // Check if permissions tables exist
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'permissions'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // Tables don't exist yet, return empty array
                return [];
            }
            
            // Get user's role
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return [];
            }
            
            $permissions = [];
            
            // Get permissions from role
            try {
                $stmt = $pdo->prepare("
                    SELECT p.permission_key 
                    FROM role_permissions rp
                    JOIN permissions p ON rp.permission_id = p.id
                    WHERE rp.role = ?
                ");
                $stmt->execute([$user['role']]);
                $rolePermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $permissions = array_merge($permissions, $rolePermissions);
            } catch (PDOException $e) {
                // Role permissions table might not exist
            }
            
            // Get individual user permissions (these can override role permissions)
            try {
                $stmt = $pdo->prepare("
                    SELECT p.permission_key 
                    FROM user_permissions up
                    JOIN permissions p ON up.permission_id = p.id
                    WHERE up.user_id = ?
                ");
                $stmt->execute([$userId]);
                $userSpecificPermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Merge and deduplicate
                $permissions = array_unique(array_merge($permissions, $userSpecificPermissions));
            } catch (PDOException $e) {
                // User permissions table might not exist
            }
            
            return $permissions;
        } catch (PDOException $e) {
            error_log("Get user permissions error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Grant a permission to a user
 * @param int $userId User ID
 * @param string $permissionKey Permission key
 * @param int $grantedBy User ID of admin granting permission
 * @return bool
 */
if (!function_exists('grantPermission')) {
    function grantPermission($userId, $permissionKey, $grantedBy) {
        try {
            $pdo = getDBConnection();
            
            $pdo->beginTransaction();
            
            // Get permission ID
            $stmt = $pdo->prepare("SELECT id FROM permissions WHERE permission_key = ?");
            $stmt->execute([$permissionKey]);
            $permission = $stmt->fetch();
            
            if (!$permission) {
                throw new Exception("Permission not found: $permissionKey");
            }
            
            // Check if already granted
            $stmt = $pdo->prepare("
                SELECT id FROM user_permissions 
                WHERE user_id = ? AND permission_id = ?
            ");
            $stmt->execute([$userId, $permission['id']]);
            if ($stmt->fetch()) {
                // Already granted, return true
                $pdo->rollBack();
                return true;
            }
            
            // Grant permission
            $stmt = $pdo->prepare("
                INSERT INTO user_permissions (user_id, permission_id, granted_by)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $permission['id'], $grantedBy]);
            
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Grant permission error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Revoke a permission from a user
 * @param int $userId User ID
 * @param string $permissionKey Permission key
 * @return bool
 */
if (!function_exists('revokePermission')) {
    function revokePermission($userId, $permissionKey) {
        $pdo = getDBConnection();
        
        try {
            $stmt = $pdo->prepare("
                DELETE up FROM user_permissions up
                JOIN permissions p ON up.permission_id = p.id
                WHERE up.user_id = ? AND p.permission_key = ?
            ");
            $stmt->execute([$userId, $permissionKey]);
            
            return true;
        } catch (Exception $e) {
            error_log("Revoke permission error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get all available permissions grouped by category
 * @return array
 */
if (!function_exists('getAllPermissions')) {
    function getAllPermissions() {
        try {
            $pdo = getDBConnection();
            
            // Check if permissions table exists
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'permissions'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // Table doesn't exist yet
                return [];
            }
            
            $stmt = $pdo->prepare("
                SELECT * FROM permissions 
                ORDER BY category, permission_name
            ");
            $stmt->execute();
            $permissions = $stmt->fetchAll();
            
            // Group by category
            $grouped = [];
            foreach ($permissions as $permission) {
                $grouped[$permission['category']][] = $permission;
            }
            
            return $grouped;
        } catch (PDOException $e) {
            error_log("Get all permissions error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get permissions for a specific user with granted status
 * @param int $userId User ID
 * @return array
 */
if (!function_exists('getUserPermissionsWithStatus')) {
    function getUserPermissionsWithStatus($userId) {
        try {
            $pdo = getDBConnection();
            
            // Check if permissions table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
            if (!$stmt->fetch()) {
                // Table doesn't exist yet
                return [];
            }
            
            $stmt = $pdo->prepare("
                SELECT p.*, 
                       CASE WHEN up.user_id IS NOT NULL THEN 1 ELSE 0 END as granted,
                       up.granted_at,
                       up.granted_by,
                       grantor.name as granted_by_name
                FROM permissions p
                LEFT JOIN user_permissions up ON p.id = up.permission_id AND up.user_id = ?
                LEFT JOIN users grantor ON up.granted_by = grantor.id
                ORDER BY p.category, p.permission_name
            ");
            $stmt->execute([$userId]);
            $permissions = $stmt->fetchAll();
            
            // Group by category
            $grouped = [];
            foreach ($permissions as $permission) {
                $grouped[$permission['category']][] = $permission;
            }
            
            return $grouped;
        } catch (PDOException $e) {
            error_log("Get user permissions with status error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Require user to have specific permission, redirect if not
 * @param string|array $permissionKey Single permission or array of permissions
 * @param string $redirectUrl URL to redirect to if unauthorized
 */
if (!function_exists('requirePermission')) {
    function requirePermission($permissionKey, $redirectUrl = '../dashboard/index.php') {
        if (!hasPermission($permissionKey)) {
            header('Location: ' . $redirectUrl);
            exit();
        }
    }
}

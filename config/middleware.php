<?php
/**
 * Middleware for Role-Based Access Control
 * 
 * Provides middleware functions to restrict access based on user roles
 */

/**
 * Require shop attendant role - redirect to shop dashboard if not shop attendant
 * @param string $redirectUrl URL to redirect to if unauthorized
 */
function requireShopAttendant($redirectUrl = '../dashboard/index.php') {
    if (!isShopAttendant()) {
        header('Location: ' . $redirectUrl);
        exit();
    }
}

/**
 * Require shop attendant or manager role for viewing shop records
 * Allows managers to view sales records and account summary
 * @param string $redirectUrl URL to redirect to if unauthorized
 */
function requireShopAttendantOrManager($redirectUrl = '../dashboard/index.php') {
    if (!isShopAttendant() && !hasRole(['admin', 'manager'])) {
        header('Location: ' . $redirectUrl);
        exit();
    }
}

/**
 * Restrict access to shop-related pages only
 * Shop attendants should only see shop sales interface
 * @param string $redirectUrl URL to redirect to if unauthorized
 */
function restrictToShopInterface($redirectUrl = '../shop/index.php') {
    if (isShopAttendant()) {
        // Shop attendants can only access shop interface
        $currentPath = $_SERVER['REQUEST_URI'] ?? '';
        
        // Allow access to shop pages
        $allowedPaths = [
            '/shop/',
            '/auth/logout.php',
            '/auth/profile.php'
        ];
        
        $isAllowed = false;
        foreach ($allowedPaths as $allowedPath) {
            if (strpos($currentPath, $allowedPath) !== false) {
                $isAllowed = true;
                break;
            }
        }
        
        if (!$isAllowed) {
            header('Location: ' . $redirectUrl);
            exit();
        }
    }
}

/**
 * Block shop attendants from accessing admin/dispatcher management pages
 * @param string $redirectUrl URL to redirect to if unauthorized
 */
function blockShopAttendantFromAdmin($redirectUrl = '../shop/index.php') {
    if (isShopAttendant()) {
        $currentPath = $_SERVER['REQUEST_URI'] ?? '';
        
        // Block access to admin and dispatcher management
        $blockedPaths = [
            '/admin/',
            '/users/',
            '/dispatch/drivers.php',
            '/dispatch/vehicles.php',
            '/auth/settings.php'
        ];
        
        foreach ($blockedPaths as $blockedPath) {
            if (strpos($currentPath, $blockedPath) !== false) {
                header('Location: ' . $redirectUrl);
                exit();
            }
        }
    }
}

/**
 * Get shop dispatcher ID
 * Returns the ID of the special "Shop Sales" dispatcher profile
 * @return int|null
 */
function getShopDispatcherId() {
    try {
        $stmt = executeQuery("SELECT id FROM drivers WHERE name = 'Shop Sales' LIMIT 1");
        $dispatcher = $stmt->fetch();
        return $dispatcher ? $dispatcher['id'] : null;
    } catch (PDOException $e) {
        error_log("Failed to get shop dispatcher ID: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if current user can access shop sales
 * @return bool
 */
function canAccessShopSales() {
    return isShopAttendant() || hasRole(['admin', 'manager', 'cashier']);
}

/**
 * Check if current user can view shop reports
 * @return bool
 */
function canViewShopReports() {
    return hasRole(['admin', 'manager']) || isShopAttendant();
}

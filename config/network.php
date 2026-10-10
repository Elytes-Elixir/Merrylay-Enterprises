<?php
/**
 * Network Configuration for Merrylay Enterprises
 * 
 * This file handles network-specific configurations for LAN access
 * and offline/online support
 */

// Keep generated URLs on the current request origin, including scheme and port.
if (!defined('BASE_URL')) {
    define('BASE_URL', '/merrylay_system');
}

// Performance optimizations for network
// Note: Output buffering is managed by init.php, don't set it here to avoid conflicts

// Timezone
date_default_timezone_set('Africa/Lagos');

// Debug mode (set to false in production)
define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Connection timeout settings for offline scenarios
ini_set('default_socket_timeout', 30); // 30 seconds for network operations



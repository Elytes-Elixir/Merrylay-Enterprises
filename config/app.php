<?php
/**
 * Application Configuration
 * 
 * Centralized application settings and constants
 */

// Application environment
if (!defined('APP_ENV')) {
    $env = getenv('APP_ENV');
    define('APP_ENV', $env ?: 'production');
}

// Application paths
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/..');
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/merrylay_system');
}

// Application settings
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Merrylay Enterprises');
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Africa/Lagos');
}

if (!defined('APP_LOCALE')) {
    define('APP_LOCALE', 'en_US');
}

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Error reporting based on environment
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Logging
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/logs/php_errors.log');

<?php
/**
 * Application Constants
 * 
 * Global constants used throughout the application
 */

// User roles
define('ROLE_ADMIN', 'admin');
define('ROLE_MANAGER', 'manager');
define('ROLE_DISPATCHER', 'dispatcher');
define('ROLE_CASHIER', 'cashier');
define('ROLE_SHOP_ATTENDANT', 'shop_attendant');

// Sales channels
define('SALES_CHANNEL_DISPATCH', 'dispatch');
define('SALES_CHANNEL_SHOP', 'shop');

// Payment methods
define('PAYMENT_CASH', 'cash');
define('PAYMENT_TRANSFER', 'transfer');
define('PAYMENT_CREDIT', 'credit');

// Stock movement types
define('STOCK_MOVEMENT_PURCHASE', 'purchase');
define('STOCK_MOVEMENT_SALE', 'sale');
define('STOCK_MOVEMENT_DISPATCH', 'dispatch');
define('STOCK_MOVEMENT_RETURN', 'return');
define('STOCK_MOVEMENT_DAMAGE', 'damage');
define('STOCK_MOVEMENT_SUPPLY', 'supply');

// Dispatch status
define('DISPATCH_STATUS_PENDING', 'pending');
define('DISPATCH_STATUS_COMPLETED', 'completed');
define('DISPATCH_STATUS_CANCELLED', 'cancelled');

// Cache TTL values (in seconds)
define('CACHE_TTL_SHORT', 60);        // 1 minute
define('CACHE_TTL_MEDIUM', 300);      // 5 minutes
define('CACHE_TTL_LONG', 600);        // 10 minutes
define('CACHE_TTL_VERY_LONG', 3600);  // 1 hour

// Pagination
define('PAGINATION_DEFAULT', 25);
define('PAGINATION_MAX', 100);

// File upload limits
define('MAX_FILE_SIZE', 5242880);     // 5MB in bytes
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);

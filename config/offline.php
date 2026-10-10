<?php
/**
 * Offline Optimization Configuration
 * 
 * Configures caching, asset loading, and sync strategies
 * for optimal offline performance and zero slowness
 */

// Cache Configuration
define('OFFLINE_CACHE_CONFIG', [
    // Service Worker caches
    'service_worker' => [
        'static_cache' => 'merrylay-v2',
        'runtime_cache' => 'merrylay-runtime-v2',
        'api_cache' => 'merrylay-api-v2'
    ],
    
    // Cache strategies per resource type
    'strategies' => [
        'html' => 'network-first',      // Try network first, fallback to cache
        'api' => 'network-first',       // API calls: network first
        'css' => 'cache-first',         // Stylesheets: use cache first
        'js' => 'cache-first',          // Scripts: use cache first
        'images' => 'cache-first',      // Images: use cache first
        'fonts' => 'cache-first'        // Fonts: use cache first
    ],
    
    // TTL (Time To Live) for cached data in milliseconds
    'ttl' => [
        'api_general' => 3600000,       // 1 hour
        'api_products' => 1800000,      // 30 minutes
        'api_sales' => 300000,          // 5 minutes
        'api_dispatches' => 300000,     // 5 minutes
        'api_user' => 600000,           // 10 minutes
        'html_pages' => 3600000,        // 1 hour
        'assets' => 604800000,          // 7 days (assets rarely change)
        'offline_queue_max_age' => 604800000 // 7 days
    ]
]);

// Offline Database Configuration
define('OFFLINE_DB_CONFIG', [
    'name' => 'MerrlayDB',
    'version' => 1,
    'stores' => [
        'offlineQueue' => [
            'keyPath' => 'id',
            'autoIncrement' => true,
            'indexes' => ['timestamp', 'endpoint', 'status']
        ],
        'cachedData' => [
            'keyPath' => 'key',
            'indexes' => ['timestamp', 'type', 'expiresAt']
        ],
        'syncMetadata' => [
            'keyPath' => 'key',
            'indexes' => ['timestamp']
        ]
    ]
]);

// Sync Manager Configuration
define('OFFLINE_SYNC_CONFIG', [
    'sync_interval' => 30000,           // Attempt sync every 30 seconds
    'max_retries' => 3,                 // Maximum retry attempts
    'initial_retry_delay' => 5000,      // Start with 5 second delay
    'request_timeout' => 30000,         // 30 second timeout per request
    'batch_size' => 10,                 // Process 10 items at a time
    'exponential_backoff' => true       // Use exponential backoff
]);

// Assets Loader Configuration
define('OFFLINE_ASSETS_CONFIG', [
    'offline_timeout' => 5000,          // 5 second timeout before fallback
    'preload_critical' => true,         // Preload critical assets on init
    'critical_assets' => [
        ['type' => 'css', 'url' => '/assets/css/unified-design-system.css'],
        ['type' => 'js', 'url' => '/assets/js/offline-handler.js'],
        ['type' => 'js', 'url' => '/assets/js/offline-database.js'],
        ['type' => 'js', 'url' => '/assets/js/offline-sync-manager.js']
    ],
    'fallback_fonts' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
]);

// Performance Optimization Configuration
define('OFFLINE_PERFORMANCE_CONFIG', [
    // Caching
    'use_page_cache' => true,
    'cache_dynamic_pages' => true,
    'cache_api_responses' => true,
    
    // Compression
    'gzip_enabled' => true,
    'minify_js' => true,
    'minify_css' => true,
    
    // Lazy Loading
    'lazy_load_images' => true,
    'lazy_load_iframes' => true,
    
    // Optimization
    'use_cdn' => true,
    'use_http2_push' => false,
    'use_service_worker' => true,
    'use_indexeddb' => true
]);

// Offline UI Configuration
define('OFFLINE_UI_CONFIG', [
    'show_offline_indicator' => true,
    'indicator_position' => 'top-right',
    'show_notifications' => true,
    'notification_duration' => 5000,
    'show_sync_status' => true,
    'sync_status_position' => 'bottom-right',
    'auto_retry_failed' => true,
    'notification_sounds' => false
]);

// Network Configuration
define('OFFLINE_NETWORK_CONFIG', [
    'fallback_url' => '/offline.html',
    'api_endpoints_offline_allowed' => [
        '/api/sales/*',
        '/api/dispatches/*',
        '/api/returns/*',
        '/api/products/*',
        '/api/stock/*'
    ],
    'api_endpoints_online_required' => [
        '/api/users/*',
        '/api/auth/*',
        '/api/admin/*'
    ],
    'retry_on_status_codes' => [408, 500, 502, 503, 504],
    'network_condition_check_interval' => 30000
]);

// Helper functions

/**
 * Get cache TTL for a resource type
 */
function getOfflineCacheTTL($type, $subtype = null) {
    $config = OFFLINE_CACHE_CONFIG['ttl'];
    
    if ($subtype && isset($config[$type . '_' . $subtype])) {
        return $config[$type . '_' . $subtype];
    }
    
    return $config[$type] ?? 3600000; // Default 1 hour
}

/**
 * Get cache strategy for a resource
 */
function getOfflineCacheStrategy($type) {
    return OFFLINE_CACHE_CONFIG['strategies'][$type] ?? 'network-first';
}

/**
 * Check if endpoint is allowed offline
 */
function isEndpointOfflineAllowed($endpoint) {
    $allowed = OFFLINE_NETWORK_CONFIG['api_endpoints_offline_allowed'];
    
    foreach ($allowed as $pattern) {
        if (fnmatch($pattern, $endpoint)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Check if endpoint requires online
 */
function isEndpointOnlineRequired($endpoint) {
    $required = OFFLINE_NETWORK_CONFIG['api_endpoints_online_required'];
    
    foreach ($required as $pattern) {
        if (fnmatch($pattern, $endpoint)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get offline sync configuration as JSON for JavaScript
 */
function getOfflineSyncConfigJSON() {
    return json_encode([
        'syncInterval' => OFFLINE_SYNC_CONFIG['sync_interval'],
        'maxRetries' => OFFLINE_SYNC_CONFIG['max_retries'],
        'retryDelay' => OFFLINE_SYNC_CONFIG['initial_retry_delay'],
        'requestTimeout' => OFFLINE_SYNC_CONFIG['request_timeout'],
        'batchSize' => OFFLINE_SYNC_CONFIG['batch_size']
    ]);
}

/**
 * Get offline UI configuration as JSON for JavaScript
 */
function getOfflineUIConfigJSON() {
    return json_encode([
        'showOfflineIndicator' => OFFLINE_UI_CONFIG['show_offline_indicator'],
        'indicatorPosition' => OFFLINE_UI_CONFIG['indicator_position'],
        'showNotifications' => OFFLINE_UI_CONFIG['show_notifications'],
        'notificationDuration' => OFFLINE_UI_CONFIG['notification_duration'],
        'showSyncStatus' => OFFLINE_UI_CONFIG['show_sync_status'],
        'autoRetryFailed' => OFFLINE_UI_CONFIG['auto_retry_failed']
    ]);
}

?>

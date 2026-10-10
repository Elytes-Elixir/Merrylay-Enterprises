<?php
/**
 * Application bootstrap file for Merrylay Enterprises
 *
 * This file initializes the session, environment, and shared configuration.
 */

require_once __DIR__ . '/config/session.php';

// Enable output buffering so headers can still be sent
if (!ob_get_level()) {
    ob_start();
}

// Set default timezone for the application
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Africa/Lagos');
}

// Application environment
if (!defined('APP_ENV')) {
    $env = getenv('APP_ENV');
    define('APP_ENV', $env ?: 'production');
}

// Enable error logging in all environments
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/logs/php_errors.log');

    if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Define root paths
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

// Load network configuration if available
if (file_exists(__DIR__ . '/config/network.php')) {
    require_once __DIR__ . '/config/network.php';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/merrylay_system');
}

// Load common configuration
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/cache.php';
require_once __DIR__ . '/config/performance.php';
require_once __DIR__ . '/config/auth.php';

// Auto-replace CDN assets with local vendor files at output time (before headers sent)
if (!function_exists('replace_cdn_with_local_assets')) {
    function replace_cdn_with_local_assets($buffer) {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        if (empty($baseUrl)) {
            $baseUrl = '/merrylay_system';
        }

        // Replace Bootstrap CSS CDN with local
        $buffer = str_replace(
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
            $baseUrl . '/assets/vendor/bootstrap/bootstrap.min.css',
            $buffer
        );

        // Replace Font Awesome CSS CDN with local
        $buffer = str_replace(
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
            $baseUrl . '/assets/vendor/fontawesome/css/all.min.css',
            $buffer
        );
        $buffer = str_replace(
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            $baseUrl . '/assets/vendor/fontawesome/css/all.min.css',
            $buffer
        );

        // Replace Bootstrap JS CDN with local
        $buffer = str_replace(
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
            $baseUrl . '/assets/vendor/bootstrap/bootstrap.bundle.min.js',
            $buffer
        );

        // Remove Google Fonts CDN link (use system fonts instead - faster)
        $buffer = preg_replace(
            '/<link[^>]*href="https:\/\/fonts\.googleapis\.com\/css[^"]*"[^>]*>/i',
            '',
            $buffer
        );
        $buffer = preg_replace(
            '/<link[^>]*href="https:\/\/fonts\.gstatic\.com[^"]*"[^>]*>/i',
            '',
            $buffer
        );

        return $buffer;
    }

    // Register output buffer handler if not already registered
    if (!in_array('replace_cdn_with_local_assets', ob_list_handlers())) {
        ob_start('replace_cdn_with_local_assets');
    }
}

// Custom error handler to prevent empty pages
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Error: $errstr in $errfile on line $errline");
    if (APP_ENV === 'development') {
        return false; // Let PHP handle it in development
    }
    return true; // Suppress error display in production but log it
});

if (!function_exists('renderErrorPage')) {
    function renderErrorPage($title, $message, $statusCode = 500) {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>' . htmlspecialchars($title) . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#f6f7fb;color:#1f2937;margin:0;padding:40px}';
        echo '.card{max-width:720px;margin:10vh auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;box-shadow:0 10px 30px rgba(0,0,0,.08)}';
        echo 'h1{margin:0 0 12px;font-size:28px}p{margin:0 0 10px;line-height:1.6}.meta{color:#6b7280;font-size:14px;margin-top:20px}</style></head><body>';
        echo '<div class="card"><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p class="meta">Please refresh the page. If the problem continues, check the server error log.</p></div></body></html>';
    }
}

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        error_log("Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}");
        if (APP_ENV !== 'development') {
            if (function_exists('renderErrorPage')) {
                renderErrorPage('Server Error', 'An error occurred while processing the request.');
            } else {
                echo '<h1>Server Error</h1><p>An error occurred while processing the request.</p>';
            }
        }
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
});

/**
 * Helper function to register offline support in pages
 * Call this before closing </head> tag in pages
 */
function offlineSupportHead() {
    ob_start();
    ?>
    <!-- Offline Support Scripts - Load early for offline mode -->
    <script src="<?php echo BASE_URL . '/assets/js/offline-database.js'; ?>" defer></script>
    <script src="<?php echo BASE_URL . '/assets/js/offline-sync-manager.js'; ?>" defer></script>
    <script src="<?php echo BASE_URL . '/assets/js/offline-assets-loader.js'; ?>" defer></script>
    <?php
    return ob_get_clean();
}

/**
 * Helper function for offline support in footer
 * Call this before closing </body> tag in pages
 */
function offlineSupportFooter() {
    ob_start();
    ?>
    <!-- Service Worker Registration -->
    <script>
        // Register service worker for offline support
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('<?php echo BASE_URL . '/sw.js'; ?>', { scope: '<?php echo BASE_URL . '/'; ?>' })
                    .then((registration) => {
                        console.log('[ServiceWorker] Registered successfully');
                        // Check for updates every minute
                        setInterval(() => registration.update(), 60000);
                    })
                    .catch((error) => {
                        console.warn('[ServiceWorker] Registration failed:', error);
                    });
            });
        }

        // Cleanup old offline data hourly
        if (window.offlineDB) {
            setInterval(async () => {
                try {
                    await window.offlineDB.clearExpiredCache();
                } catch (e) {
                    console.error('[Offline] Cleanup failed:', e);
                }
            }, 3600000);
        }
    </script>
    <?php
    return ob_get_clean();
}

?>
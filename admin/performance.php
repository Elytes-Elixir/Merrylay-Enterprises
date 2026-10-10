<?php
/**
 * Performance Monitoring Dashboard
 * 
 * Real-time performance metrics and system health monitoring
 */

// Start session
require_once __DIR__ . '/../config/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include configurations
require_once '../config/db.php';
require_once '../config/cache.php';
require_once '../config/performance.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

$message = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hasRole(['admin'])) {
        error_log('Unauthorized cache-clear attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to clear the cache.');
    }
    $postedCsrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($postedCsrfToken) && SecurityUtils::consumeCSRFToken($postedCsrfToken);
    if (!$csrfValid) {
        error_log('Rejected cache-clear request: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $message = 'Your form has expired. Refresh the page and try again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Get performance statistics
$performance_stats = performance_get_stats();
$cache_stats = CacheManager::getInstance()->getStats();
$memory_usage = $performance_stats['memory_usage'];

// Get database performance metrics
try {
    $db_stats = [
        'connections' => executeQuery("SHOW STATUS LIKE 'Threads_connected'")->fetch()['Value'],
        'queries_per_second' => executeQuery("SHOW GLOBAL STATUS LIKE 'Queries'")->fetch()['Value'],
        'slow_queries' => executeQuery("SHOW GLOBAL STATUS LIKE 'Slow_queries'")->fetch()['Value'],
        'uptime' => executeQuery("SHOW STATUS LIKE 'Uptime'")->fetch()['Value']
    ];
} catch (Exception $e) {
    $db_stats = ['error' => $e->getMessage()];
}

// Get system information
$system_info = [
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(),
    'server_time' => date('Y-m-d H:i:s'),
    'timezone' => date_default_timezone_get()
];

// Clear cache if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_cache' && $csrfValid) {
    cache_clear();
    $message = "Cache cleared successfully!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Monitor - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .metric-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        .status-good { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .status-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .status-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .progress-container {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            height: 8px;
            margin-top: 0.5rem;
        }
        .progress-bar {
            background: rgba(255,255,255,0.8);
            border-radius: 10px;
            height: 100%;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="fas fa-tachometer-alt me-2"></i>Performance Monitor</h1>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="clear_cache">
                        <button type="submit" class="btn btn-warning" onclick="return confirm('Clear all cache?')">
                            <i class="fas fa-trash me-2"></i>Clear Cache
                        </button>
                    </form>
                </div>

                <?php if (isset($message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- System Overview -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="metric-card status-<?php echo $performance_stats['total_time'] < 500 ? 'good' : ($performance_stats['total_time'] < 1000 ? 'warning' : 'danger'); ?>">
                            <div class="metric-value"><?php echo number_format($performance_stats['total_time'], 1); ?>ms</div>
                            <div class="metric-label">Page Load Time</div>
                            <div class="progress-container">
                                <div class="progress-bar" style="width: <?php echo min(100, $performance_stats['total_time'] / 10); ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card status-<?php echo $performance_stats['query_count'] < 10 ? 'good' : ($performance_stats['query_count'] < 20 ? 'warning' : 'danger'); ?>">
                            <div class="metric-value"><?php echo $performance_stats['query_count']; ?></div>
                            <div class="metric-label">Database Queries</div>
                            <div class="progress-container">
                                <div class="progress-bar" style="width: <?php echo min(100, $performance_stats['query_count'] * 5); ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card status-<?php echo $memory_usage['peak'] < 64 * 1024 * 1024 ? 'good' : ($memory_usage['peak'] < 128 * 1024 * 1024 ? 'warning' : 'danger'); ?>">
                            <div class="metric-value"><?php echo number_format($memory_usage['peak'] / 1024 / 1024, 1); ?>MB</div>
                            <div class="metric-label">Memory Peak</div>
                            <div class="progress-container">
                                <div class="progress-bar" style="width: <?php echo min(100, ($memory_usage['peak'] / $memory_usage['limit']) * 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card <?php echo $cache_stats['enabled'] ? 'status-good' : 'status-danger'; ?>">
                            <div class="metric-value"><i class="fas fa-<?php echo $cache_stats['enabled'] ? 'check' : 'times'; ?>"></i></div>
                            <div class="metric-label">Cache Status</div>
                            <small><?php echo ucfirst($cache_stats['type']); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Detailed Metrics -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-database me-2"></i>Database Performance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (isset($db_stats['error'])): ?>
                                    <div class="alert alert-danger"><?php echo htmlspecialchars($db_stats['error']); ?></div>
                                <?php else: ?>
                                    <table class="table table-sm">
                                        <tr>
                                            <td>Active Connections</td>
                                            <td><strong><?php echo $db_stats['connections']; ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td>Total Queries</td>
                                            <td><strong><?php echo number_format($db_stats['queries_per_second']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td>Slow Queries</td>
                                            <td><strong><?php echo $db_stats['slow_queries']; ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td>Server Uptime</td>
                                            <td><strong><?php echo number_format($db_stats['uptime'] / 3600, 1); ?> hours</strong></td>
                                        </tr>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-memory me-2"></i>Cache Statistics</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td>Cache Type</td>
                                        <td><strong><?php echo ucfirst($cache_stats['type']); ?></strong></td>
                                    </tr>
                                    <?php if (isset($cache_stats['hits'])): ?>
                                    <tr>
                                        <td>Cache Hits</td>
                                        <td><strong><?php echo number_format($cache_stats['hits']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Cache Misses</td>
                                        <td><strong><?php echo number_format($cache_stats['misses']); ?></strong></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td>Enabled</td>
                                        <td><strong><?php echo $cache_stats['enabled'] ? 'Yes' : 'No'; ?></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Information -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-info-circle me-2"></i>System Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>PHP Version:</strong><br>
                                        <?php echo $system_info['php_version']; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Memory Limit:</strong><br>
                                        <?php echo $system_info['memory_limit']; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>OPcache:</strong><br>
                                        <?php echo $system_info['opcache_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Server Time:</strong><br>
                                        <?php echo $system_info['server_time']; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slow Queries -->
                <?php if (!empty($performance_stats['slow_queries'])): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card border-danger">
                            <div class="card-header bg-danger text-white">
                                <h5><i class="fas fa-exclamation-triangle me-2"></i>Slow Queries Detected</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($performance_stats['slow_queries'] as $query): ?>
                                    <div class="alert alert-warning">
                                        <strong><?php echo number_format($query['time'], 1); ?>ms</strong><br>
                                        <code><?php echo htmlspecialchars(substr($query['query'], 0, 200)); ?>...</code>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>


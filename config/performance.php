<?php
/**
 * Performance Monitoring and Optimization for Merrylay Enterprises
 * 
 * Provides performance metrics, monitoring, and optimization tools
 */

// Performance configuration
define('PERFORMANCE_MONITORING', true);
define('SLOW_QUERY_THRESHOLD', 100); // milliseconds
define('MEMORY_LIMIT_WARNING', 128 * 1024 * 1024); // 128MB

/**
 * Performance Monitor Class
 */
class PerformanceMonitor {
    private static $instance = null;
    private $startTime;
    private $queries = [];
    private $memoryPeak = 0;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->startTime = microtime(true);
        register_shutdown_function([$this, 'logPerformance']);
    }
    
    /**
     * Log query execution time
     */
    public function logQuery($query, $executionTime, $params = []) {
        if (!PERFORMANCE_MONITORING) return;
        
        $this->queries[] = [
            'query' => $query,
            'time' => $executionTime,
            'params' => $params,
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
        ];
        
        // Log slow queries immediately
        if ($executionTime > SLOW_QUERY_THRESHOLD) {
            error_log("SLOW QUERY: {$executionTime}ms - {$query}");
        }
    }
    
    /**
     * Get current memory usage
     */
    public function getMemoryUsage() {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => $this->parseMemoryLimit(ini_get('memory_limit'))
        ];
    }
    
    /**
     * Parse memory limit string
     */
    private function parseMemoryLimit($limit) {
        $limit = strtolower($limit);
        $multiplier = 1;
        
        if (strpos($limit, 'g') !== false) {
            $multiplier = 1024 * 1024 * 1024;
        } elseif (strpos($limit, 'm') !== false) {
            $multiplier = 1024 * 1024;
        } elseif (strpos($limit, 'k') !== false) {
            $multiplier = 1024;
        }
        
        return intval($limit) * $multiplier;
    }
    
    /**
     * Get performance statistics
     */
    public function getStats() {
        $totalTime = (microtime(true) - $this->startTime) * 1000;
        $memory = $this->getMemoryUsage();
        $queryTime = array_sum(array_column($this->queries, 'time'));
        
        return [
            'total_time' => $totalTime,
            'query_time' => $queryTime,
            'query_count' => count($this->queries),
            'memory_usage' => $memory,
            'queries' => $this->queries,
            'slow_queries' => array_filter($this->queries, function($q) {
                return $q['time'] > SLOW_QUERY_THRESHOLD;
            })
        ];
    }
    
    /**
     * Log performance metrics
     */
    public function logPerformance() {
        if (!PERFORMANCE_MONITORING) return;
        
        $stats = $this->getStats();
        
        // Log to error log for monitoring
        error_log(sprintf(
            "Performance: %.2fms total, %d queries (%.2fms), %.2fMB memory",
            $stats['total_time'],
            $stats['query_count'],
            $stats['query_time'],
            $stats['memory_usage']['peak'] / 1024 / 1024
        ));
        
        // Check for performance issues
        if ($stats['total_time'] > 1000) {
            error_log("WARNING: Slow page load - {$stats['total_time']}ms");
        }
        
        if ($stats['memory_usage']['peak'] > MEMORY_LIMIT_WARNING) {
            error_log("WARNING: High memory usage - " . ($stats['memory_usage']['peak'] / 1024 / 1024) . "MB");
        }
        
        if (count($stats['slow_queries']) > 0) {
            error_log("WARNING: " . count($stats['slow_queries']) . " slow queries detected");
        }
    }
}

/**
 * Asset Optimization Class
 */
class AssetOptimizer {
    /**
     * Minify CSS
     */
    public static function minifyCSS($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // Remove whitespace
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);
        
        // Remove unnecessary spaces
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);
        
        // Remove last semicolon
        $css = preg_replace('/;}/', '}', $css);
        
        return trim($css);
    }
    
    /**
     * Minify JavaScript (basic)
     */
    public static function minifyJS($js) {
        // Remove comments
        $js = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $js);
        $js = preg_replace('!//.*$!m', '', $js);
        
        // Remove whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        $js = preg_replace('/\s*([{}();,])\s*/', '$1', $js);
        
        return trim($js);
    }
    
    /**
     * Get optimized asset URL with versioning
     */
    public static function getAssetURL($path, $version = null) {
        if ($version === null) {
            $version = filemtime(__DIR__ . '/../' . $path);
        }
        return $path . '?v=' . $version;
    }
}

/**
 * Database Query Optimizer
 */
class QueryOptimizer {
    /**
     * Get optimized query with proper indexing hints
     */
    public static function optimizeQuery($query, $table, $conditions = []) {
        // Add USE INDEX hint if conditions suggest specific indexes
        if (!empty($conditions)) {
            $indexHints = self::suggestIndexes($table, $conditions);
            if (!empty($indexHints)) {
                $query = str_replace("FROM {$table}", "FROM {$table} USE INDEX ({$indexHints})", $query);
            }
        }
        
        return $query;
    }
    
    /**
     * Suggest indexes based on conditions
     */
    private static function suggestIndexes($table, $conditions) {
        $indexes = [];
        
        // Common index suggestions based on table and conditions
        switch ($table) {
            case 'products':
                if (isset($conditions['category'])) $indexes[] = 'idx_category';
                if (isset($conditions['quantity'])) $indexes[] = 'idx_quantity';
                break;
            case 'sales':
                if (isset($conditions['created_at'])) $indexes[] = 'idx_created_at';
                if (isset($conditions['customer_id'])) $indexes[] = 'idx_customer_id';
                break;
            case 'dispatches':
                if (isset($conditions['created_at'])) $indexes[] = 'idx_created_at';
                break;
        }
        
        return implode(',', array_unique($indexes));
    }
}

/**
 * Helper functions
 */
function performance_start() {
    return PerformanceMonitor::getInstance();
}

function performance_log_query($query, $time, $params = []) {
    PerformanceMonitor::getInstance()->logQuery($query, $time, $params);
}

function performance_get_stats() {
    return PerformanceMonitor::getInstance()->getStats();
}

function optimize_asset_url($path, $version = null) {
    return AssetOptimizer::getAssetURL($path, $version);
}

// Initialize performance monitoring
if (PERFORMANCE_MONITORING) {
    performance_start();
}


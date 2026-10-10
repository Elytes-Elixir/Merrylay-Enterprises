<?php
/**
 * Database Configuration for Merrylay Enterprises
 *
 * This file contains database connection settings
 * and establishes the MySQL connection.
 */

// Set timezone to GMT+1 (West Africa Time) for all pages
date_default_timezone_set('Africa/Lagos');

// Database configuration constants
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'merrylay_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// Automatic stock closing check
// This ensures stock is closed at midnight even if manual closing wasn't done
if (!function_exists('autoCloseStockIfNeeded')) {
    function autoCloseStockIfNeeded() {
        try {
            $pdo = getDBConnection();
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            $previousDate = date('Y-m-d', strtotime('-1 day'));

            // Check if stock for previous date exists and needs closing
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_stock WHERE stock_date = ?");
            $stmt->execute([$previousDate]);
            $result = $stmt->fetch();

            if ($result['count'] > 0) {
                // Check if stock was already closed for previous day (by checking if closing stock matches opening stock + movements)
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as unclosed_count
                    FROM daily_stock ds
                    LEFT JOIN stock_movements sm ON ds.product_id = sm.product_id AND sm.movement_date = ds.stock_date
                    WHERE ds.stock_date = ?
                    AND ds.closing_stock != (ds.opening_stock + COALESCE(SUM(sm.quantity_change), 0))
                ");
                $stmt->execute([$previousDate]);
                $unclosedResult = $stmt->fetch();

                if ($unclosedResult['unclosed_count'] > 0) {
                    // Stock needs closing for previous day
                    require_once __DIR__ . '/stock_manager.php';
                    $stockManager = new StockManager($pdo);

                    // Close stock for previous day
                    $stockManager->closeDailyStock($previousDate);

                    // Initialize stock for current day
                    $stockManager->initializeDailyStock($currentDate);

                    error_log("Auto-closed stock for $previousDate and initialized for $currentDate at $currentTime");
                }
            }

            // Ensure stock is initialized for current day
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_stock WHERE stock_date = ?");
            $stmt->execute([$currentDate]);
            $currentDayResult = $stmt->fetch();

            if ($currentDayResult['count'] == 0) {
                require_once __DIR__ . '/stock_manager.php';
                $stockManager = new StockManager($pdo);
                $stockManager->initializeDailyStock($currentDate);
                error_log("Auto-initialized stock for $currentDate at $currentTime");
            }
        } catch (Exception $e) {
            error_log("Auto stock closing check failed: " . $e->getMessage());
        }
    }
}

// Performance settings
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DB_PERSISTENT')) define('DB_PERSISTENT', true); // Enable persistent connections
if (!defined('DB_EMULATE_PREPARES')) define('DB_EMULATE_PREPARES', false); // Use native prepares

// Connection pool settings
if (!defined('DB_MAX_CONNECTIONS')) define('DB_MAX_CONNECTIONS', 10);
if (!defined('DB_CONNECTION_TIMEOUT')) define('DB_CONNECTION_TIMEOUT', 30);
if (!defined('DB_CONNECTION_RETRIES')) define('DB_CONNECTION_RETRIES', 3); // Number of retries for connection timeout

// Create database connection with performance optimizations (only if not already created)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $retries = 0;
    $connected = false;
    while (!$connected && $retries < DB_CONNECTION_RETRIES) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => DB_EMULATE_PREPARES,
                PDO::ATTR_PERSISTENT         => DB_PERSISTENT,
                PDO::ATTR_TIMEOUT            => DB_CONNECTION_TIMEOUT,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Enable query buffering
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Set MySQL performance settings
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $pdo->exec("SET SESSION innodb_lock_wait_timeout = 5");
            
            $connected = true;
        } catch (PDOException $e) {
            // Log error and show user-friendly message
            error_log("Database connection failed (attempt " . ($retries + 1) . "): " . $e->getMessage());
            $retries++;
            if ($retries >= DB_CONNECTION_RETRIES) {
                die("Database connection failed after " . DB_CONNECTION_RETRIES . " retries. Please contact administrator.");
            }
            // Exponential backoff: 100ms, 200ms, 400ms instead of fixed 500ms
            usleep(100000 * pow(2, $retries - 1));
        }
    }
}

/**
 * Function to get database connection (singleton pattern)
 * @return PDO
 */
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        global $pdo;
        return $pdo;
    }
}

/**
 * Function to safely execute prepared statements
 * @param string $query SQL query
 * @param array $params Parameters for prepared statement
 * @param int $cacheTime Cache time in seconds (0 = no cache). Reserved for future query-level caching support.
 * @return PDOStatement
 */
if (!function_exists('executeQuery')) {
    function executeQuery($query, $params = [], $cacheTime = 0) {
        $pdo = getDBConnection();
        $start = microtime(true);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $duration = (microtime(true) - $start) * 1000;

        if (function_exists('performance_log_query')) {
            performance_log_query($query, $duration, $params);
        }

        return $stmt;
    }
}

/**
 * Function to execute multiple queries in a transaction
 * @param array $queries Array of ['query' => string, 'params' => array]
 * @return bool
 */
if (!function_exists('executeTransaction')) {
    function executeTransaction($queries) {
        $pdo = getDBConnection();
        
        try {
            $pdo->beginTransaction();
            
            foreach ($queries as $queryData) {
                $stmt = $pdo->prepare($queryData['query']);
                $stmt->execute($queryData['params']);
            }
            
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Transaction failed: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Function to get query execution time for debugging
 * @param callable $callback Function containing query
 * @return array ['result' => mixed, 'time' => float]
 */
if (!function_exists('profileQuery')) {
    function profileQuery($callback) {
        $start = microtime(true);
        $result = $callback();
        $end = microtime(true);

        return [
            'result' => $result,
            'time' => ($end - $start) * 1000 // Convert to milliseconds
        ];
    }
}

// Run auto stock closing check after database connection is established
// Commented out temporarily to prevent page loading issues
// autoCloseStockIfNeeded();

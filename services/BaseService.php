<?php
/**
 * Base Service Class
 * 
 * Provides common functionality for all service classes including:
 * - Database connection management
 * - Caching integration
 * - Error handling
 * - Logging
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/cache.php';

abstract class BaseService {
    protected $pdo;
    protected $cacheEnabled = true;
    protected $defaultCacheTTL = 300; // 5 minutes
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * Execute a cached query
     * 
     * @param string $cacheKey Cache key
     * @param callable $queryCallback Function that executes the query
     * @param int $ttl Cache time-to-live in seconds
     * @return mixed Query result
     */
    protected function cachedQuery($cacheKey, callable $queryCallback, $ttl = null) {
        if (!$this->cacheEnabled) {
            return $queryCallback();
        }
        
        $cached = cache_get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $result = $queryCallback();
        cache_set($cacheKey, $result, $ttl ?? $this->defaultCacheTTL);
        
        return $result;
    }
    
    /**
     * Invalidate cache by pattern
     * 
     * @param string $pattern Cache key pattern
     */
    protected function invalidateCache($pattern) {
        // This would need to be implemented based on your cache system
        // For file-based cache, you might delete files matching the pattern
        // For Redis, you'd use KEYS and DEL commands
        cache_clear_pattern($pattern);
    }
    
    /**
     * Execute a prepared statement with error handling
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return PDOStatement
     * @throws PDOException
     */
    protected function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database error in " . get_class($this) . ": " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute a transaction
     * 
     * @param callable $callback Function to execute within transaction
     * @return mixed Result of callback
     * @throws Exception
     */
    protected function transaction(callable $callback) {
        try {
            $this->pdo->beginTransaction();
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Transaction failed in " . get_class($this) . ": " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Fetch a single row
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|false Row data or false if not found
     */
    protected function fetchOne($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Fetch all rows
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array Array of rows
     */
    protected function fetchAll($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Fetch a single column value
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return mixed Column value
     */
    protected function fetchColumn($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Insert a record and return the last insert ID
     * 
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return int Last insert ID
     */
    protected function insert($table, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        $this->execute($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Update records
     * 
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @param string $where WHERE clause
     * @param array $whereParams WHERE clause parameters
     * @return int Number of affected rows
     */
    protected function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = "$column = ?";
        }
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $set),
            $where
        );
        
        $params = array_merge(array_values($data), $whereParams);
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Delete records
     * 
     * @param string $table Table name
     * @param string $where WHERE clause
     * @param array $params WHERE clause parameters
     * @return int Number of affected rows
     */
    protected function delete($table, $where, $params = []) {
        $sql = sprintf("DELETE FROM %s WHERE %s", $table, $where);
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Build date range condition for optimized queries
     * 
     * @param string $date Date in Y-m-d format
     * @return array ['start' => datetime, 'end' => datetime]
     */
    protected function buildDateRange($date) {
        return [
            'start' => $date . ' 00:00:00',
            'end' => date('Y-m-d H:i:s', strtotime($date . ' +1 day'))
        ];
    }
    
    /**
     * Enable or disable caching for this service instance
     * 
     * @param bool $enabled
     */
    public function setCacheEnabled($enabled) {
        $this->cacheEnabled = $enabled;
    }
    
    /**
     * Set default cache TTL
     * 
     * @param int $ttl Time-to-live in seconds
     */
    public function setDefaultCacheTTL($ttl) {
        $this->defaultCacheTTL = $ttl;
    }
}

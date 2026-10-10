<?php
/**
 * Caching Configuration for Merrylay Enterprises
 * 
 * Implements multiple caching strategies for optimal performance
 */

// Cache configuration
define('CACHE_ENABLED', true);
define('CACHE_TYPE', 'file'); // 'file', 'apcu', 'redis' - file is safest default
define('CACHE_DEFAULT_TTL', 300); // 5 minutes
define('CACHE_PREFIX', 'merrylay_');

// Redis configuration (if using Redis)
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', '');
define('REDIS_DB', 0);

/**
 * Cache Manager Class
 */
class CacheManager {
    private static $instance = null;
    private $redis = null;
    private $effectiveType = null;
    private $cacheDir = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->cacheDir = __DIR__ . '/../cache';
        $this->effectiveType = $this->resolveCacheType();

        if ($this->effectiveType === 'redis') {
            try {
                $this->redis = new Redis();
                $this->redis->connect(REDIS_HOST, REDIS_PORT);
                if (REDIS_PASSWORD) {
                    $this->redis->auth(REDIS_PASSWORD);
                }
                $this->redis->select(REDIS_DB);
            } catch (Exception $e) {
                error_log("Redis cache unavailable, falling back to file cache: " . $e->getMessage());
                $this->effectiveType = 'file';
            }
        }

        if ($this->effectiveType === 'file') {
            $this->ensureCacheDirectory();
        }
    }

    private function resolveCacheType() {
        if (!CACHE_ENABLED) {
            return null;
        }

        if (CACHE_TYPE === 'apcu' && function_exists('apcu_fetch') && function_exists('apcu_store')) {
            return 'apcu';
        }

        if (CACHE_TYPE === 'redis' && class_exists('Redis')) {
            return 'redis';
        }

        return 'file';
    }

    private function ensureCacheDirectory() {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cached value
     */
    public function get($key, $default = null) {
        if (!CACHE_ENABLED) return $default;
        
        $key = CACHE_PREFIX . $key;
        
        switch ($this->effectiveType) {
            case 'apcu':
                return function_exists('apcu_fetch') ? apcu_fetch($key) : $default;
            
            case 'redis':
                if ($this->redis) {
                    $value = $this->redis->get($key);
                    return $value !== false ? unserialize($value) : $default;
                }
                return $default;
            
            case 'file':
                return $this->getFileCache($key, $default);
            
            default:
                return $default;
        }
    }
    
    /**
     * Set cached value
     */
    public function set($key, $value, $ttl = CACHE_DEFAULT_TTL) {
        if (!CACHE_ENABLED) return true;
        
        $key = CACHE_PREFIX . $key;
        
        switch ($this->effectiveType) {
            case 'apcu':
                return function_exists('apcu_store') ? apcu_store($key, $value, $ttl) : false;
            
            case 'redis':
                if ($this->redis) {
                    return $this->redis->setex($key, $ttl, serialize($value));
                }
                return false;
            
            case 'file':
                return $this->setFileCache($key, $value, $ttl);
            
            default:
                return false;
        }
    }
    
    /**
     * Delete cached value
     */
    public function delete($key) {
        if (!CACHE_ENABLED) return true;
        
        $key = CACHE_PREFIX . $key;
        
        switch ($this->effectiveType) {
            case 'apcu':
                return function_exists('apcu_delete') ? apcu_delete($key) : false;
            
            case 'redis':
                return $this->redis ? $this->redis->del($key) : false;
            
            case 'file':
                return $this->deleteFileCache($key);
            
            default:
                return false;
        }
    }
    
    /**
     * Clear all cache
     */
    public function clear() {
        if (!CACHE_ENABLED) return true;
        
        switch ($this->effectiveType) {
            case 'apcu':
                return function_exists('apcu_clear_cache') ? apcu_clear_cache() : false;
            
            case 'redis':
                return $this->redis ? $this->redis->flushDB() : false;
            
            case 'file':
                return $this->clearFileCache();
            
            default:
                return false;
        }
    }
    
    /**
     * Get file cache
     */
    private function getFileCache($key, $default) {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) return $default;
        
        $data = include $file;
        if ($data['expires'] < time()) {
            unlink($file);
            return $default;
        }
        
        return $data['value'];
    }
    
    /**
     * Set file cache
     */
    private function setFileCache($key, $value, $ttl) {
        $file = $this->getCacheFile($key);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        $content = '<?php return ' . var_export($data, true) . ';';
        return file_put_contents($file, $content, LOCK_EX) !== false;
    }
    
    /**
     * Delete file cache
     */
    private function deleteFileCache($key) {
        $file = $this->getCacheFile($key);
        return file_exists($file) ? unlink($file) : true;
    }
    
    /**
     * Clear file cache
     */
    private function clearFileCache() {
        $files = glob(__DIR__ . '/../cache/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }
    
    /**
     * Get cache file path
     */
    private function getCacheFile($key) {
        $hash = md5($key);
        return __DIR__ . '/../cache/' . $hash . '.cache';
    }
    
    /**
     * Cache function result
     */
    public function remember($key, $callback, $ttl = CACHE_DEFAULT_TTL) {
        $value = $this->get($key);
        
        if ($value === null) {
            $value = $callback();
            $this->set($key, $value, $ttl);
        }
        
        return $value;
    }
    
    /**
     * Get cache statistics
     */
    public function getStats() {
        switch ($this->effectiveType) {
            case 'apcu':
                if (function_exists('apcu_cache_info')) {
                    $info = apcu_cache_info();
                    return [
                        'configured_type' => CACHE_TYPE,
                        'effective_type' => 'apcu',
                        'hits' => $info['num_hits'],
                        'misses' => $info['num_misses'],
                        'size' => $info['mem_size'],
                        'available' => $info['mem_avail']
                    ];
                }
                break;
            
            case 'redis':
                if ($this->redis) {
                    $info = $this->redis->info();
                    return [
                        'configured_type' => CACHE_TYPE,
                        'effective_type' => 'redis',
                        'hits' => $info['keyspace_hits'] ?? 0,
                        'misses' => $info['keyspace_misses'] ?? 0,
                        'memory' => $info['used_memory'] ?? 0,
                        'keys' => $info['db0']['keys'] ?? 0
                    ];
                }
                break;
            
            case 'file':
                return [
                    'configured_type' => CACHE_TYPE,
                    'effective_type' => 'file',
                    'enabled' => CACHE_ENABLED,
                ];
        }
        
        return [
            'configured_type' => CACHE_TYPE,
            'effective_type' => $this->effectiveType,
            'enabled' => CACHE_ENABLED
        ];
    }
}

/**
 * Helper functions for caching
 */
function cache_get($key, $default = null) {
    return CacheManager::getInstance()->get($key, $default);
}

function cache_set($key, $value, $ttl = CACHE_DEFAULT_TTL) {
    return CacheManager::getInstance()->set($key, $value, $ttl);
}

function cache_delete($key) {
    return CacheManager::getInstance()->delete($key);
}

function cache_clear() {
    return CacheManager::getInstance()->clear();
}

function cache_remember($key, $callback, $ttl = CACHE_DEFAULT_TTL) {
    return CacheManager::getInstance()->remember($key, $callback, $ttl);
}


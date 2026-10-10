<?php
/**
 * Dispatch Service
 * 
 * Handles all dispatch/trip-related database operations with built-in caching
 * and optimized queries.
 */

require_once __DIR__ . '/BaseService.php';

class DispatchService extends BaseService {
    
    /**
     * Get dispatches by date range (optimized with date range query)
     * 
     * @param string $date Date in Y-m-d format
     * @param string $salesChannel Optional sales channel filter
     * @param bool $useCache Whether to use cache
     * @return array Array of dispatches
     */
    public function getByDate($date, $salesChannel = null, $useCache = true) {
        $cacheKey = "dispatches_date_{$date}_{$salesChannel}";
        
        return $this->cachedQuery($cacheKey, function() use ($date, $salesChannel) {
            $range = $this->buildDateRange($date);
            
            $sql = "
                SELECT 
                    id, dispatch_number, dispatcher_name, dispatch_date,
                    expected_amount, actual_sales_amount, sales_channel,
                    status, notes, created_at, updated_at
                FROM dispatches
                WHERE dispatch_date >= ? AND dispatch_date < ?
            ";
            
            $params = [$range['start'], $range['end']];
            
            if ($salesChannel) {
                $sql .= " AND sales_channel = ?";
                $params[] = $salesChannel;
            }
            
            $sql .= " ORDER BY dispatch_date DESC";
            
            return $this->fetchAll($sql, $params);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get dispatch summary for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Dispatch summary
     */
    public function getSummaryByDate($date, $useCache = true) {
        $cacheKey = "dispatch_summary_date_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $range = $this->buildDateRange($date);
            
            $sql = "
                SELECT 
                    COUNT(*) as total_dispatches,
                    COALESCE(SUM(expected_amount), 0) as total_expected,
                    COALESCE(SUM(actual_sales_amount), 0) as total_actual,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
                FROM dispatches
                WHERE dispatch_date >= ? AND dispatch_date < ?
            ";
            
            return $this->fetchOne($sql, [$range['start'], $range['end']]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get dispatch by ID
     * 
     * @param int $dispatchId Dispatch ID
     * @param bool $useCache Whether to use cache
     * @return array|false Dispatch data or false if not found
     */
    public function getById($dispatchId, $useCache = true) {
        $cacheKey = "dispatch_{$dispatchId}";
        
        return $this->cachedQuery($cacheKey, function() use ($dispatchId) {
            $sql = "
                SELECT 
                    id, dispatch_number, dispatcher_name, dispatch_date,
                    expected_amount, actual_sales_amount, sales_channel,
                    status, notes, created_at, updated_at
                FROM dispatches
                WHERE id = ?
            ";
            return $this->fetchOne($sql, [$dispatchId]);
        }, $useCache ? 600 : 0); // 10 minute cache
    }
    
    /**
     * Get dispatch items for a dispatch
     * 
     * @param int $dispatchId Dispatch ID
     * @param bool $useCache Whether to use cache
     * @return array Array of dispatch items
     */
    public function getItems($dispatchId, $useCache = true) {
        $cacheKey = "dispatch_items_{$dispatchId}";
        
        return $this->cachedQuery($cacheKey, function() use ($dispatchId) {
            $sql = "
                SELECT 
                    di.id, di.product_id, di.quantity, di.unit_price,
                    p.name as product_name, p.unit
                FROM dispatch_items di
                JOIN products p ON di.product_id = p.id
                WHERE di.dispatch_id = ?
                ORDER BY p.name ASC
            ";
            return $this->fetchAll($sql, [$dispatchId]);
        }, $useCache ? 600 : 0); // 10 minute cache
    }
    
    /**
     * Get top dispatched products for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param int $limit Maximum results
     * @param bool $useCache Whether to use cache
     * @return array Array of top products
     */
    public function getTopProducts($date, $limit = 10, $useCache = true) {
        $cacheKey = "dispatch_top_products_{$date}_{$limit}";
        
        return $this->cachedQuery($cacheKey, function() use ($date, $limit) {
            $range = $this->buildDateRange($date);
            
            $sql = "
                SELECT 
                    p.name,
                    SUM(di.quantity) as total_quantity
                FROM dispatch_items di
                JOIN dispatches d ON di.dispatch_id = d.id
                JOIN products p ON di.product_id = p.id
                WHERE d.dispatch_date >= ? AND d.dispatch_date < ?
                GROUP BY p.id, p.name
                ORDER BY total_quantity DESC
                LIMIT ?
            ";
            
            return $this->fetchAll($sql, [$range['start'], $range['end'], $limit]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get distinct dispatcher names
     * 
     * @param bool $useCache Whether to use cache
     * @return array Array of dispatcher names
     */
    public function getDispatcherNames($useCache = true) {
        $cacheKey = 'dispatcher_names';
        
        return $this->cachedQuery($cacheKey, function() {
            $sql = "
                SELECT DISTINCT dispatcher_name
                FROM dispatches
                ORDER BY dispatcher_name ASC
            ";
            $result = $this->fetchAll($sql);
            return array_column($result, 'dispatcher_name');
        }, $useCache ? 600 : 0); // 10 minute cache
    }
    
    /**
     * Create a new dispatch
     * 
     * @param array $dispatchData Dispatch data
     * @param array $items Array of dispatch items
     * @return int New dispatch ID
     */
    public function create($dispatchData, $items = []) {
        return $this->transaction(function() use ($dispatchData, $items) {
            $dispatchData['created_at'] = date('Y-m-d H:i:s');
            $dispatchData['updated_at'] = date('Y-m-d H:i:s');
            
            $dispatchId = $this->insert('dispatches', $dispatchData);
            
            foreach ($items as $item) {
                $item['dispatch_id'] = $dispatchId;
                $this->insert('dispatch_items', $item);
            }
            
            // Invalidate caches
            $this->invalidateCache('dispatch_');
            $this->invalidateCache('dispatcher_names');
            
            return $dispatchId;
        });
    }
    
    /**
     * Update dispatch status
     * 
     * @param int $dispatchId Dispatch ID
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus($dispatchId, $status) {
        $result = $this->update(
            'dispatches',
            ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$dispatchId]
        );
        
        // Invalidate caches
        $this->invalidateCache('dispatch_' . $dispatchId);
        $this->invalidateCache('dispatch_');
        
        return $result > 0;
    }
}

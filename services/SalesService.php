<?php
/**
 * Sales Service
 * 
 * Handles all sales-related database operations with built-in caching
 * and optimized queries.
 */

require_once __DIR__ . '/BaseService.php';

class SalesService extends BaseService {
    
    /**
     * Get daily activities for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of daily activities
     */
    public function getDailyActivities($date, $useCache = true) {
        $cacheKey = "daily_activities_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    id, driver_name, driver_id, activity_date,
                    cash_collected, transfer_amount, credit_amount,
                    discounts, expenses_amount, damaged_products,
                    damaged_products_note, recorded_at
                FROM daily_activities
                WHERE activity_date = ?
                ORDER BY recorded_at DESC
            ";
            return $this->fetchAll($sql, [$date]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get sales summary for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Sales summary
     */
    public function getSummaryByDate($date, $useCache = true) {
        $cacheKey = "sales_summary_date_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    COUNT(*) as total_sales,
                    COALESCE(SUM(cash_collected + transfer_amount + credit_amount), 0) as total_revenue,
                    COALESCE(SUM(discounts), 0) as total_discount,
                    COALESCE(SUM(cash_collected + transfer_amount), 0) as total_paid,
                    COUNT(CASE WHEN cash_collected > 0 THEN 1 END) as cash_sales,
                    COUNT(CASE WHEN transfer_amount > 0 THEN 1 END) as transfer_sales,
                    COUNT(CASE WHEN credit_amount > 0 THEN 1 END) as credit_sales
                FROM daily_activities
                WHERE activity_date = ?
            ";
            return $this->fetchOne($sql, [$date]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get hourly sales for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of hourly sales data
     */
    public function getHourlySales($date, $useCache = true) {
        $cacheKey = "hourly_sales_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    HOUR(recorded_at) as hour,
                    COUNT(*) as sales_count,
                    COALESCE(SUM(cash_collected + transfer_amount + credit_amount), 0) as revenue
                FROM daily_activities
                WHERE activity_date = ?
                GROUP BY HOUR(recorded_at)
                ORDER BY hour ASC
            ";
            return $this->fetchAll($sql, [$date]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get recent sales
     * 
     * @param int $limit Maximum number of sales
     * @param bool $useCache Whether to use cache
     * @return array Array of recent sales
     */
    public function getRecent($limit = 5, $useCache = true) {
        $cacheKey = "recent_sales_{$limit}";
        
        return $this->cachedQuery($cacheKey, function() use ($limit) {
            $sql = "
                SELECT 
                    da.*,
                    (SELECT COALESCE(SUM(quantity), 0) 
                     FROM daily_returns 
                     WHERE driver_id = da.driver_id 
                     AND return_date = da.activity_date) as returns_count
                FROM daily_activities da
                ORDER BY da.recorded_at DESC
                LIMIT ?
            ";
            return $this->fetchAll($sql, [$limit]);
        }, $useCache ? 60 : 0); // 1 minute cache
    }
    
    /**
     * Get daily returns for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of daily returns
     */
    public function getDailyReturns($date, $useCache = true) {
        $cacheKey = "daily_returns_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    dr.id, dr.driver_id, dr.product_id, dr.quantity,
                    dr.return_date, dr.reason, dr.notes,
                    p.name as product_name
                FROM daily_returns dr
                JOIN products p ON dr.product_id = p.id
                WHERE dr.return_date = ?
                ORDER BY dr.return_date DESC
            ";
            return $this->fetchAll($sql, [$date]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get top returned products for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param int $limit Maximum results
     * @param bool $useCache Whether to use cache
     * @return array Array of top returned products
     */
    public function getTopReturnedProducts($date, $limit = 10, $useCache = true) {
        $cacheKey = "top_returns_{$date}_{$limit}";
        
        return $this->cachedQuery($cacheKey, function() use ($date, $limit) {
            $sql = "
                SELECT 
                    p.name,
                    SUM(dr.quantity) as total_quantity
                FROM daily_returns dr
                JOIN products p ON dr.product_id = p.id
                WHERE dr.return_date = ?
                GROUP BY p.id, p.name
                ORDER BY total_quantity DESC
                LIMIT ?
            ";
            return $this->fetchAll($sql, [$date, $limit]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Create a daily activity record
     * 
     * @param array $data Activity data
     * @return int New activity ID
     */
    public function createDailyActivity($data) {
        $data['recorded_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('daily_activities', $data);
        
        // Invalidate caches
        $this->invalidateCache('daily_activities_');
        $this->invalidateCache('sales_summary_');
        $this->invalidateCache('hourly_sales_');
        $this->invalidateCache('recent_sales_');
        
        return $id;
    }
    
    /**
     * Create a daily return record
     * 
     * @param array $data Return data
     * @return int New return ID
     */
    public function createDailyReturn($data) {
        $id = $this->insert('daily_returns', $data);
        
        // Invalidate caches
        $this->invalidateCache('daily_returns_');
        $this->invalidateCache('top_returns_');
        
        return $id;
    }
}

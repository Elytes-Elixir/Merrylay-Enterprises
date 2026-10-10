<?php
/**
 * Shop Service
 * 
 * Handles all shop-related database operations with built-in caching
 * and optimized queries.
 */

require_once __DIR__ . '/BaseService.php';

class ShopService extends BaseService {
    
    /**
     * Get shop sales for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param int|null $attendantId Optional attendant ID filter
     * @param bool $useCache Whether to use cache
     * @return array Array of shop sales
     */
    public function getSalesByDate($date, $attendantId = null, $useCache = true) {
        $cacheKey = "shop_sales_{$date}_{$attendantId}";
        
        return $this->cachedQuery($cacheKey, function() use ($date, $attendantId) {
            $sql = "
                SELECT 
                    ss.id, ss.sale_number, ss.sale_date, ss.total_amount,
                    ss.payment_method, ss.attendant_id, ss.recorded_at,
                    u.name as attendant_name
                FROM shop_sales ss
                LEFT JOIN users u ON ss.attendant_id = u.id
                WHERE ss.sale_date = ?
            ";
            
            $params = [$date];
            
            if ($attendantId) {
                $sql .= " AND ss.attendant_id = ?";
                $params[] = $attendantId;
            }
            
            $sql .= " ORDER BY ss.recorded_at DESC";
            
            return $this->fetchAll($sql, $params);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get shop sales summary for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param int|null $attendantId Optional attendant ID filter
     * @param bool $useCache Whether to use cache
     * @return array Sales summary
     */
    public function getSalesSummary($date, $attendantId = null, $useCache = true) {
        $cacheKey = "shop_sales_summary_{$date}_{$attendantId}";
        
        return $this->cachedQuery($cacheKey, function() use ($date, $attendantId) {
            $sql = "
                SELECT 
                    COUNT(*) as total_sales,
                    COALESCE(SUM(total_amount), 0) as total_revenue,
                    COUNT(CASE WHEN payment_method = 'cash' THEN 1 END) as cash_sales,
                    COUNT(CASE WHEN payment_method = 'transfer' THEN 1 END) as transfer_sales,
                    COUNT(CASE WHEN payment_method = 'credit' THEN 1 END) as credit_sales,
                    COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_revenue,
                    COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_revenue,
                    COALESCE(SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END), 0) as credit_revenue
                FROM shop_sales
                WHERE sale_date = ?
            ";
            
            $params = [$date];
            
            if ($attendantId) {
                $sql .= " AND attendant_id = ?";
                $params[] = $attendantId;
            }
            
            return $this->fetchOne($sql, $params);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get shop sale items for a sale
     * 
     * @param int $saleId Sale ID
     * @param bool $useCache Whether to use cache
     * @return array Array of sale items
     */
    public function getSaleItems($saleId, $useCache = true) {
        $cacheKey = "shop_sale_items_{$saleId}";
        
        return $this->cachedQuery($cacheKey, function() use ($saleId) {
            $sql = "
                SELECT 
                    ssi.id, ssi.product_id, ssi.quantity, ssi.unit_price,
                    ssi.subtotal, p.name as product_name, p.unit
                FROM shop_sale_items ssi
                JOIN products p ON ssi.product_id = p.id
                WHERE ssi.sale_id = ?
                ORDER BY p.name ASC
            ";
            return $this->fetchAll($sql, [$saleId]);
        }, $useCache ? 600 : 0); // 10 minute cache
    }
    
    /**
     * Get recent shop sales
     * 
     * @param int $limit Maximum number of sales
     * @param int|null $attendantId Optional attendant ID filter
     * @param bool $useCache Whether to use cache
     * @return array Array of recent sales
     */
    public function getRecentSales($limit = 10, $attendantId = null, $useCache = true) {
        $cacheKey = "shop_recent_sales_{$limit}_{$attendantId}";
        
        return $this->cachedQuery($cacheKey, function() use ($limit, $attendantId) {
            $sql = "
                SELECT 
                    ss.id, ss.sale_number, ss.sale_date, ss.total_amount,
                    ss.payment_method, ss.attendant_id, ss.recorded_at,
                    u.name as attendant_name
                FROM shop_sales ss
                LEFT JOIN users u ON ss.attendant_id = u.id
            ";
            
            $params = [];
            
            if ($attendantId) {
                $sql .= " WHERE ss.attendant_id = ?";
                $params[] = $attendantId;
            }
            
            $sql .= " ORDER BY ss.recorded_at DESC LIMIT ?";
            $params[] = $limit;
            
            return $this->fetchAll($sql, $params);
        }, $useCache ? 60 : 0); // 1 minute cache
    }
    
    /**
     * Create a shop sale
     * 
     * @param array $saleData Sale data
     * @param array $items Array of sale items
     * @return int New sale ID
     */
    public function createSale($saleData, $items = []) {
        return $this->transaction(function() use ($saleData, $items) {
            $saleData['recorded_at'] = date('Y-m-d H:i:s');
            
            $saleId = $this->insert('shop_sales', $saleData);
            
            foreach ($items as $item) {
                $item['sale_id'] = $saleId;
                $item['subtotal'] = $item['quantity'] * $item['unit_price'];
                $this->insert('shop_sale_items', $item);
                
                // Update product stock
                $this->updateProductStock($item['product_id'], -$item['quantity']);
            }
            
            // Invalidate caches
            $this->invalidateCache('shop_sales_');
            $this->invalidateCache('shop_sales_summary_');
            $this->invalidateCache('shop_recent_sales_');
            
            return $saleId;
        });
    }
    
    /**
     * Update product stock
     * 
     * @param int $productId Product ID
     * @param float $quantityChange Quantity change (can be negative)
     * @return bool Success status
     */
    private function updateProductStock($productId, $quantityChange) {
        $sql = "UPDATE products SET quantity = quantity + ? WHERE id = ?";
        $this->execute($sql, [$quantityChange, $productId]);
        
        // Invalidate product caches
        $this->invalidateCache('product_' . $productId);
        $this->invalidateCache('products_');
        
        return true;
    }
    
    /**
     * Get shop collections for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of collections
     */
    public function getCollectionsByDate($date, $useCache = true) {
        $cacheKey = "shop_collections_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    id, collection_date, amount, collection_type,
                    notes, recorded_by, created_at
                FROM shop_collections
                WHERE collection_date = ?
                ORDER BY created_at DESC
            ";
            return $this->fetchAll($sql, [$date]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Create a shop collection record
     * 
     * @param array $data Collection data
     * @return int New collection ID
     */
    public function createCollection($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('shop_collections', $data);
        
        // Invalidate caches
        $this->invalidateCache('shop_collections_');
        
        return $id;
    }
}

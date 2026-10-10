<?php
/**
 * Stock Service
 * 
 * Handles all stock-related database operations with built-in caching
 * and optimized queries.
 */

require_once __DIR__ . '/BaseService.php';

class StockService extends BaseService {
    
    /**
     * Get stock movements for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of stock movements
     */
    public function getMovementsByDate($date, $useCache = true) {
        $cacheKey = "stock_movements_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    sm.id, sm.product_id, sm.movement_type,
                    sm.quantity_change, sm.movement_date,
                    sm.notes, sm.created_at,
                    p.name as product_name,
                    u.name as recorded_by
                FROM stock_movements sm
                LEFT JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.user_id = u.id
                WHERE sm.movement_date = ?
                ORDER BY sm.created_at DESC
            ";
            return $this->fetchAll($sql, [$date]);
        }, $useCache ? 60 : 0); // 1 minute cache
    }
    
    /**
     * Get stock summary by movement type for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Stock summary by type
     */
    public function getSummaryByDate($date, $useCache = true) {
        $cacheKey = "stock_summary_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    movement_type,
                    COUNT(*) as count,
                    COALESCE(SUM(ABS(quantity_change)), 0) as total_quantity
                FROM stock_movements
                WHERE movement_date = ?
                GROUP BY movement_type
                ORDER BY movement_type
            ";
            return $this->fetchAll($sql, [$date]);
        }, $useCache ? 60 : 0); // 1 minute cache
    }
    
    /**
     * Get recent stock movements
     * 
     * @param int $limit Maximum number of movements
     * @param bool $useCache Whether to use cache
     * @return array Array of recent movements
     */
    public function getRecentMovements($limit = 10, $useCache = true) {
        $cacheKey = "recent_stock_movements_{$limit}";
        
        return $this->cachedQuery($cacheKey, function() use ($limit) {
            $sql = "
                SELECT 
                    sm.id, sm.product_id, sm.movement_type,
                    ABS(sm.quantity_change) as quantity,
                    sm.notes, sm.movement_date, sm.created_at,
                    p.name as product_name,
                    u.name as recorded_by
                FROM stock_movements sm
                LEFT JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.user_id = u.id
                ORDER BY sm.created_at DESC
                LIMIT ?
            ";
            return $this->fetchAll($sql, [$limit]);
        }, $useCache ? 30 : 0); // 30 second cache
    }
    
    /**
     * Get stock level for a product
     * 
     * @param int $productId Product ID
     * @param bool $useCache Whether to use cache
     * @return array Stock level data
     */
    public function getProductStock($productId, $useCache = true) {
        $cacheKey = "product_stock_{$productId}";
        
        return $this->cachedQuery($cacheKey, function() use ($productId) {
            $sql = "
                SELECT 
                    id, name, quantity, reorder_level, unit,
                    cost_price, selling_price,
                    CASE 
                        WHEN quantity = 0 THEN 'out_of_stock'
                        WHEN quantity <= reorder_level * 0.25 THEN 'critical'
                        WHEN quantity <= reorder_level THEN 'low'
                        ELSE 'adequate'
                    END as stock_status
                FROM products
                WHERE id = ?
            ";
            return $this->fetchOne($sql, [$productId]);
        }, $useCache ? 120 : 0); // 2 minute cache
    }
    
    /**
     * Create a stock movement
     * 
     * @param array $data Movement data
     * @return int New movement ID
     */
    public function createMovement($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('stock_movements', $data);
        
        // Update product quantity
        $this->updateProductQuantity($data['product_id'], $data['quantity_change'], $data['movement_type']);
        
        // Invalidate caches
        $this->invalidateCache('stock_movements_');
        $this->invalidateCache('stock_summary_');
        $this->invalidateCache('recent_stock_movements_');
        $this->invalidateCache('product_stock_' . $data['product_id']);
        
        return $id;
    }
    
    /**
     * Update product quantity based on movement
     * 
     * @param int $productId Product ID
     * @param float $quantityChange Quantity change
     * @param string $movementType Movement type
     */
    private function updateProductQuantity($productId, $quantityChange, $movementType) {
        // Determine the adjustment based on movement type
        $adjustment = 0;
        switch ($movementType) {
            case 'purchase':
            case 'supply':
            case 'return':
                $adjustment = abs($quantityChange);
                break;
            case 'sale':
            case 'dispatch':
            case 'damage':
                $adjustment = -abs($quantityChange);
                break;
        }
        
        if ($adjustment != 0) {
            $sql = "UPDATE products SET quantity = quantity + ? WHERE id = ?";
            $this->execute($sql, [$adjustment, $productId]);
        }
    }
    
    /**
     * Get stock analysis for a date range
     * 
     * @param string $startDate Start date in Y-m-d format
     * @param string $endDate End date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Stock analysis data
     */
    public function getAnalysis($startDate, $endDate, $useCache = true) {
        $cacheKey = "stock_analysis_{$startDate}_{$endDate}";
        
        return $this->cachedQuery($cacheKey, function() use ($startDate, $endDate) {
            $sql = "
                SELECT 
                    p.id, p.name, p.quantity, p.reorder_level,
                    COALESCE(SUM(CASE WHEN sm.movement_type = 'purchase' THEN sm.quantity_change ELSE 0 END), 0) as purchased,
                    COALESCE(SUM(CASE WHEN sm.movement_type = 'sale' THEN ABS(sm.quantity_change) ELSE 0 END), 0) as sold,
                    COALESCE(SUM(CASE WHEN sm.movement_type = 'dispatch' THEN ABS(sm.quantity_change) ELSE 0 END), 0) as dispatched,
                    COALESCE(SUM(CASE WHEN sm.movement_type = 'return' THEN ABS(sm.quantity_change) ELSE 0 END), 0) as returned
                FROM products p
                LEFT JOIN stock_movements sm ON p.id = sm.product_id 
                    AND sm.movement_date >= ? AND sm.movement_date <= ?
                WHERE p.is_active = 1
                GROUP BY p.id, p.name, p.quantity, p.reorder_level
                ORDER BY p.name ASC
            ";
            return $this->fetchAll($sql, [$startDate, $endDate]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }

    /**
     * Get net returns for a date, excluding reversed returns
     *
     * Reversals are identified by negative quantity_change entries with the same reference_number
     * This function returns only the net returns after excluding any reversed transactions
     *
     * @param string $date Date in Y-m-d format
     * @param int|null $productId Optional product ID to filter
     * @param int|null $driverId Optional driver ID to filter
     * @param bool $useCache Whether to use cache
     * @return array Net returns data
     */
    public function getNetReturns($date, $productId = null, $driverId = null, $useCache = true) {
        $cacheKey = "net_returns_{$date}_{$productId}_{$driverId}";

        return $this->cachedQuery($cacheKey, function() use ($date, $productId, $driverId) {
            $sql = "
                SELECT
                    sm.id, sm.product_id, sm.quantity_change, sm.reference_number,
                    sm.movement_date, sm.created_at,
                    p.name as product_name,
                    u.name as recorded_by
                FROM stock_movements sm
                LEFT JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.user_id = u.id
                WHERE sm.movement_type = 'return'
                AND DATE(sm.movement_date) = ?
                AND sm.reference_number NOT LIKE 'TRIP-EDIT-%'
                AND sm.reference_number NOT IN (
                    SELECT DISTINCT sm2.reference_number
                    FROM stock_movements sm2
                    WHERE sm2.movement_type = 'return'
                      AND sm2.quantity_change < 0
                      AND DATE(sm2.movement_date) = ?
                )
            ";
            $params = [$date, $date];

            if ($productId !== null) {
                $sql .= " AND sm.product_id = ?";
                $params[] = $productId;
            }

            if ($driverId !== null) {
                $sql .= " AND sm.driver_id = ?";
                $params[] = $driverId;
            }

            $sql .= " ORDER BY sm.created_at DESC";

            return $this->fetchAll($sql, $params);
        }, $useCache ? 60 : 0); // 1 minute cache
    }

    /**
     * Get net returns total quantity for a date, excluding reversed returns
     *
     * @param string $date Date in Y-m-d format
     * @param int|null $productId Optional product ID to filter
     * @param int|null $driverId Optional driver ID to filter
     * @param bool $useCache Whether to use cache
     * @return float Total net returns quantity
     */
    public function getNetReturnsTotal($date, $productId = null, $driverId = null, $useCache = true) {
        $cacheKey = "net_returns_total_{$date}_{$productId}_{$driverId}";

        return $this->cachedQuery($cacheKey, function() use ($date, $productId, $driverId) {
            $sql = "
                SELECT COALESCE(SUM(sm.quantity_change), 0) as total
                FROM stock_movements sm
                WHERE sm.movement_type = 'return'
                AND DATE(sm.movement_date) = ?
                AND sm.reference_number NOT LIKE 'TRIP-EDIT-%'
                AND sm.reference_number NOT IN (
                    SELECT DISTINCT sm2.reference_number
                    FROM stock_movements sm2
                    WHERE sm2.movement_type = 'return'
                      AND sm2.quantity_change < 0
                      AND DATE(sm2.movement_date) = ?
                )
            ";
            $params = [$date, $date];

            if ($productId !== null) {
                $sql .= " AND sm.product_id = ?";
                $params[] = $productId;
            }

            if ($driverId !== null) {
                $sql .= " AND sm.driver_id = ?";
                $params[] = $driverId;
            }

            $result = $this->fetchOne($sql, $params);
            return $result ? floatval($result['total']) : 0;
        }, $useCache ? 60 : 0); // 1 minute cache
    }
}

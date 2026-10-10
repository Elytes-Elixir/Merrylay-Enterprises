<?php
/**
 * Product Service
 * 
 * Handles all product-related database operations with built-in caching
 * and optimized queries.
 */

require_once __DIR__ . '/BaseService.php';

class ProductService extends BaseService {
    
    /**
     * Get all active products
     * 
     * @param bool $useCache Whether to use cache
     * @return array Array of products
     */
    public function getAllActive($useCache = true) {
        $cacheKey = 'products_all_active';
        
        return $this->cachedQuery($cacheKey, function() {
            $sql = "
                SELECT 
                    id, name, quantity, reorder_level, unit,
                    cost_price, category_id,
                    is_active, created_at, updated_at
                FROM products
                WHERE is_active = 1
                ORDER BY name ASC
            ";
            return $this->fetchAll($sql);
        }, $useCache ? 600 : 0); // 10 minute cache
    }
    
    /**
     * Get product by ID
     * 
     * @param int $id Product ID
     * @param bool $useCache Whether to use cache
     * @return array|false Product data or false if not found
     */
    public function getById($id, $useCache = true) {
        $cacheKey = "product_{$id}";
        
        return $this->cachedQuery($cacheKey, function() use ($id) {
            $sql = "
                SELECT 
                    id, name, quantity, reorder_level, unit,
                    cost_price, cost_price, category_id,
                    is_active, created_at, updated_at
                FROM products
                WHERE id = ?
            ";
            return $this->fetchOne($sql, [$id]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get low stock products
     * 
     * @param int $limit Maximum number of products to return
     * @param bool $useCache Whether to use cache
     * @return array Array of low stock products
     */
    public function getLowStock($limit = 10, $useCache = true) {
        $cacheKey = "products_low_stock_{$limit}";
        
        return $this->cachedQuery($cacheKey, function() use ($limit) {
            $sql = "
                SELECT 
                    id, name, quantity, reorder_level, unit,
                    cost_price
                FROM products
                WHERE quantity <= reorder_level AND is_active = 1
                ORDER BY quantity ASC
                LIMIT ?
            ";
            return $this->fetchAll($sql, [$limit]);
        }, $useCache ? 60 : 0); // 1 minute cache
    }
    
    /**
     * Get out of stock products
     * 
     * @param bool $useCache Whether to use cache
     * @return array Array of out of stock products
     */
    public function getOutOfStock($useCache = true) {
        $cacheKey = 'products_out_of_stock';
        
        return $this->cachedQuery($cacheKey, function() {
            $sql = "
                SELECT 
                    id, name, quantity, reorder_level, unit,
                    cost_price
                FROM products
                WHERE quantity = 0 AND is_active = 1
                ORDER BY name ASC
            ";
            return $this->fetchAll($sql);
        }, $useCache ? 120 : 0); // 2 minute cache
    }
    
    /**
     * Get product statistics
     * 
     * @param bool $useCache Whether to use cache
     * @return array Statistics array
     */
    public function getStatistics($useCache = true) {
        $cacheKey = 'products_statistics';
        
        return $this->cachedQuery($cacheKey, function() {
            $sql = "
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_products,
                    COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock,
                    COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock,
                    COALESCE(SUM(quantity), 0) as total_quantity,
                    COALESCE(SUM(quantity * cost_price), 0) as total_value
                FROM products
            ";
            return $this->fetchOne($sql);
        }, $useCache ? 30 : 0); // 30 second cache
    }
    
    /**
     * Search products by name
     * 
     * @param string $query Search query
     * @param int $limit Maximum results
     * @return array Array of matching products
     */
    public function searchByName($query, $limit = 20) {
        $sql = "
            SELECT 
                id, name, quantity, unit, cost_price
            FROM products
            WHERE name LIKE ? AND is_active = 1
            ORDER BY name ASC
            LIMIT ?
        ";
        return $this->fetchAll($sql, ["%{$query}%", $limit]);
    }
    
    /**
     * Update product quantity
     * 
     * @param int $productId Product ID
     * @param float $newQuantity New quantity
     * @return bool Success status
     */
    public function updateQuantity($productId, $newQuantity) {
        $result = $this->update(
            'products',
            ['quantity' => $newQuantity, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$productId]
        );
        
        // Invalidate relevant caches
        $this->invalidateCache('product_' . $productId);
        $this->invalidateCache('products_');
        
        return $result > 0;
    }
    
    /**
     * Create a new product
     * 
     * @param array $data Product data
     * @return int New product ID
     */
    public function create($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('products', $data);
        
        // Invalidate caches
        $this->invalidateCache('products_');
        
        return $id;
    }
    
    /**
     * Update a product
     * 
     * @param int $productId Product ID
     * @param array $data Product data to update
     * @return bool Success status
     */
    public function updateProduct($productId, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        $result = $this->update(
            'products',
            $data,
            'id = ?',
            [$productId]
        );
        
        // Invalidate relevant caches
        $this->invalidateCache('product_' . $productId);
        $this->invalidateCache('products_');
        
        return $result > 0;
    }
    
    /**
     * Delete a product (soft delete by setting is_active = 0)
     * 
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function delete($productId) {
        $result = $this->update(
            'products',
            ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$productId]
        );
        
        // Invalidate caches
        $this->invalidateCache('product_' . $productId);
        $this->invalidateCache('products_');
        
        return $result > 0;
    }
}

<?php
/**
 * Real-time Stock Statistics API
 * Returns current stock data for dashboard real-time updates
 * Optimized with caching and query improvements
 */

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/cache.php';

try {
    $pdo = getDBConnection();
    $today = date('Y-m-d');
    
    // Use caching for stock summary (30 second cache)
    $cache_key = "stock_stats_summary_{$today}";
    $stock_summary = cache_get($cache_key);
    
    if ($stock_summary === null) {
        // Get total available stock across all products - optimized single query
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_products,
                COALESCE(SUM(quantity), 0) as total_quantity,
                COALESCE(SUM(quantity * cost_price), 0) as total_value,
                COUNT(CASE WHEN quantity <= reorder_level THEN 1 END) as low_stock_count,
                COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock_count
            FROM products 
            WHERE is_active = TRUE
        ");
        $stmt->execute();
        $stock_summary = $stmt->fetch(PDO::FETCH_ASSOC);
        cache_set($cache_key, $stock_summary, 30); // Cache for 30 seconds
    }
    
    // Use caching for movements summary (1 minute cache)
    $movements_cache_key = "stock_movements_summary_{$today}";
    $today_movements = cache_get($movements_cache_key);
    
    if ($today_movements === null) {
        // Get stock movements for today - handle if table doesn't exist or is empty
        $today_movements = [];
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    movement_type,
                    COUNT(*) as count,
                    COALESCE(SUM(ABS(quantity_change)), 0) as total_quantity
                FROM stock_movements
                WHERE movement_date = ?
                GROUP BY movement_type
                ORDER BY movement_type
            ");
            $stmt->execute([$today]);
            $today_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            cache_set($movements_cache_key, $today_movements, 60); // Cache for 1 minute
        } catch (Exception $e) {
            // Table might not exist yet, return empty array
            error_log("Stock movements query failed: " . $e->getMessage());
            $today_movements = [];
        }
    }
    
    // Use caching for products list (1 minute cache) - with pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 50;
    $offset = ($page - 1) * $per_page;
    
    $products_cache_key = "stock_products_list_{$today}_page{$page}_per{$per_page}";
    $all_products = cache_get($products_cache_key);
    
    if ($all_products === null) {
        // Get total count for pagination
        $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE is_active = TRUE");
        $count_stmt->execute();
        $total_products = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get products with stock levels - optimized with index-friendly query and pagination
        $stmt = $pdo->prepare("
            SELECT 
                id,
                name,
                quantity,
                reorder_level,
                unit,
                selling_price,
                cost_price,
                COALESCE(quantity * cost_price, 0) as stock_value,
                CASE 
                    WHEN quantity = 0 THEN 'out_of_stock'
                    WHEN quantity <= reorder_level * 0.25 THEN 'critical'
                    WHEN quantity <= reorder_level THEN 'low'
                    ELSE 'adequate'
                END as stock_status
            FROM products
            WHERE is_active = TRUE
            ORDER BY quantity ASC, name ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$per_page, $offset]);
        $all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pagination_data = [
            'total' => $total_products,
            'per_page' => $per_page,
            'current_page' => $page,
            'total_pages' => ceil($total_products / $per_page)
        ];
        
        cache_set($products_cache_key, ['products' => $all_products, 'pagination' => $pagination_data], 60); // Cache for 1 minute
    } else {
        $pagination_data = $all_products['pagination'];
        $all_products = $all_products['products'];
    }
    
    // Use caching for recent movements (30 second cache)
    $recent_cache_key = "stock_recent_movements_{$today}";
    $recent_movements = cache_get($recent_cache_key);
    
    if ($recent_movements === null) {
        // Get today's stock movements details - handle if table doesn't exist or is empty
        $recent_movements = [];
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    sm.id,
                    sm.product_id,
                    p.name as product_name,
                    sm.movement_type,
                    ABS(sm.quantity_change) as quantity,
                    sm.notes as reason,
                    sm.movement_date,
                    sm.created_at,
                    u.name as recorded_by
                FROM stock_movements sm
                LEFT JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.user_id = u.id
                WHERE sm.movement_date = ?
                ORDER BY sm.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$today]);
            $recent_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            cache_set($recent_cache_key, $recent_movements, 30); // Cache for 30 seconds
        } catch (Exception $e) {
            // Table might not exist yet, return empty array
            error_log("Recent movements query failed: " . $e->getMessage());
            $recent_movements = [];
        }
    }
    
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'summary' => $stock_summary,
        'movements_today' => $today_movements,
        'all_products' => $all_products,
        'pagination' => $pagination_data,
        'recent_movements' => $recent_movements,
        'cached' => true
    ]);
    
} catch (Exception $e) {
    error_log("Stock Stats API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>

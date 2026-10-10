<?php
/**
 * Analytics API for Merrylay Enterprises
 * 
 * Provides data endpoints for dashboard analytics and reporting
 */

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include dependencies
require_once '../config/db.php';
require_once '../config/security.php';

// Set content type
header('Content-Type: application/json');

// Get request method and endpoint
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';

// Validate request
if (!SecurityUtils::validateInput($endpoint, 'general')) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid endpoint']);
    exit();
}

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($endpoint);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Analytics API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Handle GET requests
 */
function handleGetRequest($endpoint) {
    switch ($endpoint) {
        case 'sales-overview':
            getSalesOverview();
            break;
        case 'inventory-status':
            getInventoryStatus();
            break;
        case 'revenue-trends':
            getRevenueTrends();
            break;
        case 'top-products':
            getTopProducts();
            break;
        case 'customer-analytics':
            getCustomerAnalytics();
            break;
        case 'performance-metrics':
            getPerformanceMetrics();
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
}

/**
 * Get sales overview data
 */
function getSalesOverview() {
    $period = $_GET['period'] ?? 'week'; // week, month, year
    
    // Validate period
    if (!in_array($period, ['week', 'month', 'year'])) {
        $period = 'week';
    }
    
    try {
        $data = [];
        
        switch ($period) {
            case 'week':
                // Last 7 days
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $sales = getSalesForDate($date);
                    $data[] = [
                        'date' => $date,
                        'label' => date('D', strtotime($date)),
                        'sales' => $sales['count'],
                        'revenue' => $sales['revenue']
                    ];
                }
                break;
                
            case 'month':
                // Last 30 days
                for ($i = 29; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $sales = getSalesForDate($date);
                    $data[] = [
                        'date' => $date,
                        'label' => date('M j', strtotime($date)),
                        'sales' => $sales['count'],
                        'revenue' => $sales['revenue']
                    ];
                }
                break;
                
            case 'year':
                // Last 12 months
                for ($i = 11; $i >= 0; $i--) {
                    $date = date('Y-m', strtotime("-$i months"));
                    $sales = getSalesForMonth($date);
                    $data[] = [
                        'date' => $date,
                        'label' => date('M Y', strtotime($date)),
                        'sales' => $sales['count'],
                        'revenue' => $sales['revenue']
                    ];
                }
                break;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'period' => $period,
            'summary' => [
                'total_sales' => array_sum(array_column($data, 'sales')),
                'total_revenue' => array_sum(array_column($data, 'revenue')),
                'average_daily_sales' => array_sum(array_column($data, 'sales')) / count($data),
                'average_daily_revenue' => array_sum(array_column($data, 'revenue')) / count($data)
            ]
        ]);
        
    } catch (PDOException $e) {
        throw new Exception("Failed to get sales overview: " . $e->getMessage());
    }
}

/**
 * Get inventory status
 */
function getInventoryStatus() {
    try {
        // Get overall inventory stats
        $stmt = executeQuery("SELECT 
            COUNT(*) as total_products,
            SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) as in_stock,
            SUM(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(quantity) as total_quantity,
            SUM(quantity * cost_price) as total_value
        FROM products");
        
        $stats = $stmt->fetch();
        
        // Get category breakdown
        $stmt = executeQuery("SELECT 
            category,
            COUNT(*) as product_count,
            SUM(quantity) as total_quantity,
            SUM(quantity * cost_price) as total_value,
            SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) as low_stock_count
        FROM products 
        GROUP BY category 
        ORDER BY total_value DESC");
        
        $categories = $stmt->fetchAll();
        
        // Get critical stock items
        $stmt = executeQuery("SELECT 
            id, name, category, quantity, reorder_level, unit,
            selling_price, cost_price,
            CASE 
                WHEN quantity = 0 THEN 'Out of Stock'
                WHEN quantity <= reorder_level THEN 'Low Stock'
                ELSE 'In Stock'
            END as status
        FROM products 
        WHERE quantity <= reorder_level 
        ORDER BY quantity ASC 
        LIMIT 10");
        
        $critical_items = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'overview' => [
                    'total_products' => (int)$stats['total_products'],
                    'in_stock' => (int)$stats['in_stock'],
                    'low_stock' => (int)$stats['low_stock'],
                    'out_of_stock' => (int)$stats['out_of_stock'],
                    'total_quantity' => (int)$stats['total_quantity'],
                    'total_value' => (float)$stats['total_value'],
                    'stock_health' => [
                        'healthy' => round(($stats['in_stock'] - $stats['low_stock']) / $stats['total_products'] * 100, 1),
                        'warning' => round($stats['low_stock'] / $stats['total_products'] * 100, 1),
                        'critical' => round($stats['out_of_stock'] / $stats['total_products'] * 100, 1)
                    ]
                ],
                'categories' => $categories,
                'critical_items' => $critical_items
            ]
        ]);
        
    } catch (PDOException $e) {
        throw new Exception("Failed to get inventory status: " . $e->getMessage());
    }
}

/**
 * Get revenue trends
 */
function getRevenueTrends() {
    $period = $_GET['period'] ?? 'month';
    
    try {
        $data = [];
        
        switch ($period) {
            case 'month':
                // Last 12 months
                for ($i = 11; $i >= 0; $i--) {
                    $date = date('Y-m', strtotime("-$i months"));
                    $revenue = getRevenueForMonth($date);
                    $data[] = [
                        'period' => $date,
                        'label' => date('M Y', strtotime($date)),
                        'revenue' => $revenue,
                        'profit' => getProfitForMonth($date)
                    ];
                }
                break;
                
            case 'year':
                // Last 5 years
                for ($i = 4; $i >= 0; $i--) {
                    $year = date('Y') - $i;
                    $revenue = getRevenueForYear($year);
                    $data[] = [
                        'period' => $year,
                        'label' => $year,
                        'revenue' => $revenue,
                        'profit' => getProfitForYear($year)
                    ];
                }
                break;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'period' => $period,
            'growth' => calculateGrowthRate($data)
        ]);
        
    } catch (PDOException $e) {
        throw new Exception("Failed to get revenue trends: " . $e->getMessage());
    }
}

/**
 * Get top products
 */
function getTopProducts() {
    $period = $_GET['period'] ?? 'month';
    $limit = intval($_GET['limit'] ?? 10);
    
    try {
        $date_condition = getDateCondition($period);
        
        $stmt = executeQuery("SELECT 
            p.id, p.name, p.category, p.selling_price,
            SUM(si.quantity) as total_sold,
            SUM(si.quantity * si.price) as total_revenue,
            SUM(si.quantity * (si.price - p.cost_price)) as total_profit,
            COUNT(DISTINCT s.id) as sales_count
        FROM products p
        JOIN sale_items si ON p.id = si.product_id
        JOIN sales s ON si.sale_id = s.id
        WHERE $date_condition
        GROUP BY p.id, p.name, p.category, p.selling_price
        ORDER BY total_revenue DESC
        LIMIT ?", [$limit]);
        
        $products = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $products,
            'period' => $period
        ]);
        
    } catch (PDOException $e) {
        throw new Exception("Failed to get top products: " . $e->getMessage());
    }
}

/**
 * Get customer analytics
 */
function getCustomerAnalytics() {
    $period = $_GET['period'] ?? 'month';
    
    try {
        $date_condition = getDateCondition($period);
        
        // Customer metrics
        $stmt = executeQuery("SELECT 
            COUNT(DISTINCT c.id) as total_customers,
            COUNT(DISTINCT CASE WHEN s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN c.id END) as new_customers,
            COUNT(DISTINCT CASE WHEN s.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN c.id END) as returning_customers,
            AVG(s.total_amount) as average_order_value,
            COUNT(s.id) as total_orders
        FROM customers c
        JOIN sales s ON c.id = s.customer_id
        WHERE $date_condition");
        
        $metrics = $stmt->fetch();
        
        // Top customers
        $stmt = executeQuery("SELECT 
            c.id, c.name, c.phone,
            COUNT(s.id) as order_count,
            SUM(s.total_amount) as total_spent,
            AVG(s.total_amount) as average_order,
            MAX(s.created_at) as last_order
        FROM customers c
        JOIN sales s ON c.id = s.customer_id
        WHERE $date_condition
        GROUP BY c.id, c.name, c.phone
        ORDER BY total_spent DESC
        LIMIT 10");
        
        $top_customers = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'metrics' => [
                    'total_customers' => (int)$metrics['total_customers'],
                    'new_customers' => (int)$metrics['new_customers'],
                    'returning_customers' => (int)$metrics['returning_customers'],
                    'average_order_value' => (float)$metrics['average_order_value'],
                    'total_orders' => (int)$metrics['total_orders'],
                    'customer_retention_rate' => $metrics['total_customers'] > 0 ? 
                        round($metrics['returning_customers'] / $metrics['total_customers'] * 100, 1) : 0
                ],
                'top_customers' => $top_customers
            ],
            'period' => $period
        ]);
        
    } catch (PDOException $e) {
        throw new Exception("Failed to get customer analytics: " . $e->getMessage());
    }
}

/**
 * Get performance metrics
 */
function getPerformanceMetrics() {
    try {
        // Today's metrics
        $stmt = executeQuery("SELECT 
            COUNT(*) as sales_today,
            COALESCE(SUM(total_amount), 0) as revenue_today,
            COUNT(DISTINCT customer_id) as customers_today
        FROM sales 
        WHERE DATE(created_at) = CURDATE()");
        
        $today = $stmt->fetch();
        
        // Yesterday's metrics for comparison
        $stmt = executeQuery("SELECT 
            COUNT(*) as sales_yesterday,
            COALESCE(SUM(total_amount), 0) as revenue_yesterday,
            COUNT(DISTINCT customer_id) as customers_yesterday
        FROM sales 
        WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
        
        $yesterday = $stmt->fetch();
        
        // This month's metrics
        $stmt = executeQuery("SELECT 
            COUNT(*) as sales_month,
            COALESCE(SUM(total_amount), 0) as revenue_month,
            COUNT(DISTINCT customer_id) as customers_month
        FROM sales 
        WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
        
        $month = $stmt->fetch();
        
        // Last month's metrics for comparison
        $stmt = executeQuery("SELECT 
            COUNT(*) as sales_last_month,
            COALESCE(SUM(total_amount), 0) as revenue_last_month,
            COUNT(DISTINCT customer_id) as customers_last_month
        FROM sales 
        WHERE MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
        AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))");
        
        $last_month = $stmt->fetch();
        
        $metrics = [
            'today' => [
                'sales' => (int)$today['sales_today'],
                'revenue' => (float)$today['revenue_today'],
                'customers' => (int)$today['customers_today'],
                'change' => [
                    'sales' => calculatePercentageChange($yesterday['sales_yesterday'], $today['sales_today']),
                    'revenue' => calculatePercentageChange($yesterday['revenue_yesterday'], $today['revenue_today']),
                    'customers' => calculatePercentageChange($yesterday['customers_yesterday'], $today['customers_today'])
                ]
            ],
            'month' => [
                'sales' => (int)$month['sales_month'],
                'revenue' => (float)$month['revenue_month'],
                'customers' => (int)$month['customers_month'],
                'change' => [
                    'sales' => calculatePercentageChange($last_month['sales_last_month'], $month['sales_month']),
                    'revenue' => calculatePercentageChange($last_month['revenue_last_month'], $month['revenue_month']),
                    'customers' => calculatePercentageChange($last_month['customers_last_month'], $month['customers_month'])
                ]
            ]
        ];
        
        echo json_encode([
            'success' => true,
            'data' => $metrics,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (PDOException $e) {
        throw new Exception("Failed to get performance metrics: " . $e->getMessage());
    }
}

// ===== HELPER FUNCTIONS =====

function getSalesForDate($date) {
    $stmt = executeQuery("SELECT 
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as revenue
    FROM sales 
    WHERE DATE(created_at) = ?", [$date]);
    
    return $stmt->fetch();
}

function getSalesForMonth($month) {
    $stmt = executeQuery("SELECT 
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as revenue
    FROM sales 
    WHERE DATE_FORMAT(created_at, '%Y-%m') = ?", [$month]);
    
    return $stmt->fetch();
}

function getRevenueForMonth($month) {
    $stmt = executeQuery("SELECT COALESCE(SUM(total_amount), 0) as revenue
    FROM sales 
    WHERE DATE_FORMAT(created_at, '%Y-%m') = ?", [$month]);
    
    return (float)$stmt->fetchColumn();
}

function getProfitForMonth($month) {
    $stmt = executeQuery("SELECT COALESCE(SUM(si.quantity * (si.price - p.cost_price)), 0) as profit
    FROM sale_items si
    JOIN products p ON si.product_id = p.id
    JOIN sales s ON si.sale_id = s.id
    WHERE DATE_FORMAT(s.created_at, '%Y-%m') = ?", [$month]);
    
    return (float)$stmt->fetchColumn();
}

function getRevenueForYear($year) {
    $stmt = executeQuery("SELECT COALESCE(SUM(total_amount), 0) as revenue
    FROM sales 
    WHERE YEAR(created_at) = ?", [$year]);
    
    return (float)$stmt->fetchColumn();
}

function getProfitForYear($year) {
    $stmt = executeQuery("SELECT COALESCE(SUM(si.quantity * (si.price - p.cost_price)), 0) as profit
    FROM sale_items si
    JOIN products p ON si.product_id = p.id
    JOIN sales s ON si.sale_id = s.id
    WHERE YEAR(s.created_at) = ?", [$year]);
    
    return (float)$stmt->fetchColumn();
}

function getDateCondition($period) {
    switch ($period) {
        case 'week':
            return "s.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case 'month':
            return "s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        case 'year':
            return "s.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        default:
            return "s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
}

function calculatePercentageChange($old, $new) {
    if ($old == 0) {
        return $new > 0 ? 100 : 0;
    }
    return round((($new - $old) / $old) * 100, 1);
}

function calculateGrowthRate($data) {
    if (count($data) < 2) {
        return [
            'revenue_growth' => 0,
            'profit_growth' => 0
        ];
    }
    
    $first = $data[0];
    $last = $data[count($data) - 1];
    
    return [
        'revenue_growth' => calculatePercentageChange($first['revenue'], $last['revenue']),
        'profit_growth' => calculatePercentageChange($first['profit'], $last['profit'])
    ];
}

?>


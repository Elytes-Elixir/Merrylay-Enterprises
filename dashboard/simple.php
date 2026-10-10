<?php
/**
 * Simple Dashboard Page
 * 
 * Works with basic database structure without complex queries
 */

// Initialize application (handles output buffering and session)
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Include database configuration and cache
require_once '../config/db.php';
require_once '../config/cache.php';

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Simple Dashboard - Merrylay Enterprises</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>
    <style>
        body { background: #f8f9fa; }
        .dashboard-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem 0; }
        .stat-card { background: white; border-radius: 10px; padding: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 1rem; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #667eea; }
        .welcome-section { background: white; border-radius: 10px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class='dashboard-header'>
        <div class='container'>
            <div class='row align-items-center'>
                <div class='col-md-6'>
                    <h1><i class='fas fa-store me-2'></i>Merrylay Enterprises</h1>
                    <p class='mb-0'>Simple Dashboard</p>
                </div>
                <div class='col-md-6 text-end'>
                    <span class='me-3'><i class='fas fa-user me-1'></i> " . htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') . "</span>
                    <a href='../auth/logout.php' class='btn btn-light btn-sm'><i class='fas fa-sign-out-alt me-1'></i>Logout</a>
                </div>
            </div>
        </div>
    </div>";

echo "<div class='container mt-4'>
    <div class='welcome-section'>
        <h2>Welcome back, " . htmlspecialchars(explode(' ', $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')[0]) . "! 👋</h2>
        <p class='text-muted'>Here's your system overview.</p>
    </div>";

// Basic database info
echo "<div class='row'>
    <div class='col-md-12'>
        <div class='stat-card'>
            <h4><i class='fas fa-database me-2'></i>Database Information</h4>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
    ]);
    
    echo "<div class='row'>";
    
    // Show tables count
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<div class='col-md-3'>
        <div class='stat-card text-center'>
            <div class='stat-number'>" . count($tables) . "</div>
            <div class='text-muted'>Tables</div>
        </div>
    </div>";
    
    // Show users count
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $userCount = $stmt->fetch()['count'];
        echo "<div class='col-md-3'>
            <div class='stat-card text-center'>
                <div class='stat-number'>$userCount</div>
                <div class='text-muted'>Users</div>
            </div>
        </div>";
    } catch (Exception $e) {
        echo "<div class='col-md-3'>
            <div class='stat-card text-center'>
                <div class='stat-number'>?</div>
                <div class='text-muted'>Users (Error)</div>
            </div>
        </div>";
    }
    
    // Show products count if table exists
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
        $productCount = $stmt->fetch()['count'];
        echo "<div class='col-md-3'>
            <div class='stat-card text-center'>
                <div class='stat-number'>$productCount</div>
                <div class='text-muted'>Products</div>
            </div>
        </div>";
    } catch (Exception $e) {
        echo "<div class='col-md-3'>
            <div class='stat-card text-center'>
                <div class='stat-number'>?</div>
                <div class='text-muted'>Products (No table)</div>
            </div>
        </div>";
    }
    
    // Show sales count if table exists
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM sales");
        $salesCount = $stmt->fetch()['count'];
        echo "<div class='col-md-3'>
            <div class='stat-card text-center'>
                <div class='stat-number'>$salesCount</div>
                <div class='text-muted'>Sales</div>
            </div>
        </div>";
    } catch (Exception $e) {
        echo "<div class='col-md-3'>
            <div class='stat-card text-center'>
                <div class='stat-number'>?</div>
                <div class='text-muted'>Sales (No table)</div>
            </div>
        </div>";
    }
    
    echo "</div>";
    
    // Show available tables
    echo "<h5 class='mt-4'>Available Tables:</h5>";
    echo "<div class='row'>";
    foreach ($tables as $table) {
        echo "<div class='col-md-3 mb-2'>
            <span class='badge bg-primary'>$table</span>
        </div>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>
        <i class='fas fa-exclamation-triangle me-2'></i>
        Database Error: " . $e->getMessage() . "
    </div>";
}

echo "</div>
    </div>
</div>";

// Quick actions
echo "<div class='row mt-4'>
    <div class='col-md-12'>
        <div class='stat-card'>
            <h4><i class='fas fa-tools me-2'></i>Quick Actions</h4>
            <div class='row'>
                <div class='col-md-3'>
                    <a href='../products/view.php' class='btn btn-warning w-100 mb-2'>
                        <i class='fas fa-box me-2'></i>View Products
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>";

// User info
echo "<div class='row mt-4'>
    <div class='col-md-12'>
        <div class='stat-card'>
            <h4><i class='fas fa-user me-2'></i>Current Session</h4>
            <div class='row'>
                <div class='col-md-6'>
                    <strong>User ID:</strong> " . ($_SESSION['user_id'] ?? 'Not set') . "<br>
                    <strong>Username:</strong> " . ($_SESSION['username'] ?? 'Not set') . "<br>
                    <strong>Full Name:</strong> " . ($_SESSION['full_name'] ?? 'Not set') . "<br>
                    <strong>Role:</strong> " . ($_SESSION['role'] ?? 'Not set') . "
                </div>
                <div class='col-md-6'>
                    <strong>Login Time:</strong> " . (isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'Not set') . "<br>
                    <strong>Session ID:</strong> " . session_id() . "<br>
                    <strong>Database:</strong> " . DB_NAME . "<br>
                    <strong>Host:</strong> " . DB_HOST . "
                </div>
            </div>
        </div>
    </div>
</div>";

echo "</div>
<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>
</body>
</html>";
?>


<?php
/**
 * User Management Page for Merrylay Enterprises
 * Admin and Manager can view, only Admin can edit/delete
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/permissions.php';

// Check if user can view users (admin and manager)
if (!canViewUsers()) {
    header('Location: ../dashboard/index.php');
    exit();
}

$can_edit = canManageUsers();

$error = '';
$success = $_SESSION['user_management_success'] ?? '';
unset($_SESSION['user_management_success']);
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$can_edit) {
    error_log('Unauthorized user-management mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('You do not have permission to manage users.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!defined('MERRYLAY_SYSTEM')) {
        define('MERRYLAY_SYSTEM', true);
    }
    require_once '../config/security.php';
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected user-management mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the user details and submit again.';
    }
}
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';
$csrfToken = SecurityUtils::generateCSRFToken();

// Handle user creation - only admin can create users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user']) && $can_edit && $csrfValid) {
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'cashier';
    
    if (empty($username) || empty($name) || empty($password)) {
        $error = 'All required fields must be filled.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = 'Username must be 3-20 characters (letters, numbers, underscore only).';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Check if username exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already exists.';
            } else {
                // Create user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, name, password, role)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$username, $name, $hashed_password, $role]);
                $_SESSION['user_management_success'] = 'User created successfully!';
                header('Location: manage.php');
                exit();
            }
        } catch (PDOException $e) {
            error_log("Create user error: " . $e->getMessage());
            $error = 'Unable to create user.';
        }
    }
}

// Handle user update - only admin can update users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user']) && $can_edit && $csrfValid) {
    $user_id = $_POST['user_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? 'cashier';
    $new_password = $_POST['new_password'] ?? '';
    
    // Debug logging
    error_log("Update user attempt - ID: $user_id, Name: $name, Role: $role, Password provided: " . (!empty($new_password) ? 'yes' : 'no'));

    if (empty($user_id)) {
        $error = 'User ID is required.';
    } elseif (empty($name)) {
        $error = 'Full name is required.';
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $pdo = getDBConnection();

            if (!empty($new_password)) {
                // Update user with password change
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET name = ?, role = ?, password = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $role, $hashed_password, $user_id]);
                $_SESSION['user_management_success'] = 'User updated successfully! Password changed.';
                header('Location: manage.php');
                exit();
            } else {
                // Update user without password change
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET name = ?, role = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $role, $user_id]);
                $_SESSION['user_management_success'] = 'User updated successfully!';
                header('Location: manage.php');
                exit();
            }
        } catch (PDOException $e) {
            error_log("Update user error: " . $e->getMessage());
            $error = 'Unable to update user.';
        }
    }
}

// Handle user deletion - only admin can delete users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && $can_edit && $csrfValid) {
    $user_id = $_POST['user_id'] ?? '';

    if (empty($user_id)) {
        $error = 'User ID is required.';
    } elseif ($user_id == $_SESSION['user_id']) {
        $error = 'You cannot delete your own account.';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $_SESSION['user_management_success'] = 'User deleted successfully!';
            header('Location: manage.php');
            exit();
        } catch (PDOException $e) {
            error_log("Delete user error: " . $e->getMessage());
            $error = 'Unable to delete user.';
        }
    }
}

// Get users with pagination
$page = intval($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

try {
    // Get total count for pagination
    $countStmt = executeQuery("SELECT COUNT(*) as total FROM users");
    $totalUsers = $countStmt->fetch()['total'];
    $totalPages = ceil($totalUsers / $per_page);

    // Get paginated users
    $stmt = executeQuery("SELECT id, username, name, role FROM users ORDER BY id DESC LIMIT ? OFFSET ?", [$per_page, $offset]);
    $users = $stmt->fetchAll();
    error_log("Loaded " . count($users) . " users from database (page $page of $totalPages)");
} catch (PDOException $e) {
    error_log("Load users error: " . $e->getMessage());
    $error = "Error loading users: " . $e->getMessage();
    $users = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="User management dashboard for Merrylay Enterprises - Manage system users and permissions">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>User Management - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Fallback fonts for offline */
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    </style>
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <style>
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .user-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .user-section h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 0.875rem;
        }
        
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .role-admin { background: #fee2e2; color: #dc2626; }
        .role-manager { background: #dbeafe; color: #2563eb; }
        .role-cashier { background: #d1fae5; color: #059669; }
        .role-dispatcher { background: #fce7f3; color: #db2777; }
        .role-shop_attendant { background: #e0e7ff; color: #4338ca; }
        .role-viewer { background: #f3f4f6; color: #6b7280; }
        .role-staff { background: #fef3c7; color: #d97706; }

        /* Enhanced Table Styles */
        .users-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .table-header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            background: #fafafa;
        }

        .table-search {
            position: relative;
            width: 300px;
        }

        .table-search input {
            padding-left: 2.5rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
        }

        .table-search i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .users-table {
            margin-bottom: 0;
        }

        .users-table thead th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            padding: 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .users-table tbody tr {
            transition: background-color 0.2s;
        }

        .users-table tbody tr:hover {
            background-color: #f9fafb;
        }

        .users-table tbody td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #e5e7eb;
        }

        .user-info-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: #111827;
        }

        .user-id {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .username-cell {
            font-family: 'Courier New', monospace;
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            color: #4b5563;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.2s;
            border: 1px solid;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-edit {
            background: white;
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .btn-edit:hover {
            background: #3b82f6;
            color: white;
        }

        .btn-permissions {
            background: white;
            border-color: #8b5cf6;
            color: #8b5cf6;
        }

        .btn-permissions:hover {
            background: #8b5cf6;
            color: white;
        }

        .btn-delete {
            background: white;
            border-color: #ef4444;
            color: #ef4444;
        }

        .btn-delete:hover {
            background: #ef4444;
            color: white;
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stat-icon.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .stat-icon.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .stat-details h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }

        .stat-details p {
            font-size: 0.875rem;
            color: #6b7280;
            margin: 0;
        }

        /* Modal Improvements */
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-title {
            font-weight: 600;
        }

        .form-label {
            font-weight: 500;
            color: #374151;
            font-size: 0.875rem;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        /* Pagination Improvements */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: #fafafa;
            border-top: 1px solid #e5e7eb;
        }

        .pagination-info {
            font-size: 0.875rem;
            color: #6b7280;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #d1d5db;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .table-header-actions {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .table-search {
                width: 100%;
            }

            .action-buttons {
                flex-direction: column;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../dashboard/index.php" class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <span class="logo-text">Merrylay Enterprises</span>
                </a>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section-title overview">Main Operations</div>
                <a href="../dashboard/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../products/view.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="../stock/manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordDispatches()): ?>
                <div class="nav-section-title dispatch">Dispatch Operations</div>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../dispatch/drivers.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span class="nav-text">Dispatchers</span>
                </a>
                <?php endif; ?>
                <a href="../dispatch/trips/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-list"></i></span>
                    <span class="nav-text">Manage Dispatches</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title sales">Sales & Finance</div>
                <a href="../dispatch/daily/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Daily Sales</span>
                </a>
                <a href="../dispatch/daily/account-summary.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>
                <a href="../finance/daily_balance.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>
                <a href="../dispatch/credit/index.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-text">Credit Management</span>
                </a>
                <?php if (isCashier()): ?>
                <a href="../cashier/deposit.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="../admin/end-of-day.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../reports/daily.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <a href="../accounts/balance_sheet.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Accounts</span>
                </a>
                <a href="manage.php" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="../admin/data_management.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <a href="../admin/backup.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-server"></i></span>
                    <span class="nav-text">Database Backup</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <a href="../auth/logout.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <button class="icon-btn position-relative" title="Notifications">
                            <i class="fas fa-bell"></i>
                        </button>

                        <div class="user-menu dropdown">
                            <button class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User', 0, 2)); ?>
                                </div>
                                <div class="user-info d-none d-lg-block">
                                    <div class="user-name"><?php echo htmlspecialchars(ucwords($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')); ?></div>
                                    <div class="user-role"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <?php if (hasRole(['admin'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <h1><i class="fas fa-users-cog me-3"></i>User Management</h1>
                            <p>Manage system users, roles, and permissions</p>
                            <div class="welcome-meta">
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <span id="currentTime"><?php echo date('H:i'); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                            <?php if ($can_edit): ?>
                            <button type="button" class="btn btn-primary" id="toggleAddUserForm">
                                <i class="fas fa-user-plus me-2"></i>Add New User
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-container">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $totalUsers; ?></h3>
                            <p>Total Users</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo count(array_filter($users, fn($u) => in_array($u['role'], ['admin', 'manager']))); ?></h3>
                            <p>Admins & Managers</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon info">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo count(array_filter($users, fn($u) => !in_array($u['role'], ['admin', 'manager']))); ?></h3>
                            <p>Staff Members</p>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($error): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="content-card mb-4">
                        <div class="card-body">
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Create User Form -->
                <?php if ($can_edit): ?>
                <div class="content-card mb-4" id="addUserFormCard" style="display: none;">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-user-plus me-2"></i>Add New User
                                </h5>
                                <p class="card-subtitle">Create a new user account</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="closeAddUserForm">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="create_user" value="1">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username *</label>
                                        <input type="text" class="form-control" id="username" name="username" required pattern="[a-zA-Z0-9_]{3,20}" title="3-20 characters (letters, numbers, underscore)">
                                        <div class="form-text">3-20 characters (letters, numbers, underscore only)</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Full Name *</label>
                                        <input type="text" class="form-control" id="name" name="name" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Password *</label>
                                        <input type="password" class="form-control" id="password" name="password" required minlength="6">
                                        <div class="form-text">Minimum 6 characters</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Role</label>
                                        <select class="form-select" id="role" name="role">
                                            <option value="cashier">Cashier</option>
                                            <option value="dispatcher">Dispatcher</option>
                                            <option value="shop_attendant">Shop Attendant</option>
                                            <option value="staff">Staff</option>
                                            <option value="viewer">Viewer</option>
                                            <option value="manager">Manager</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Create User
                                </button>
                                <button type="button" class="btn btn-secondary" id="cancelAddUser">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Edit User Form -->
                <?php if ($can_edit): ?>
                <div class="content-card mb-4" id="editUserFormCard" style="display: none;">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-edit me-2"></i>Edit User
                                </h5>
                                <p class="card-subtitle">Update user information</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="closeEditUserForm">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="editUserForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="update_user" value="1">
                            <input type="hidden" name="user_id" id="edit_user_id">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit_name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="edit_name" name="name" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit_role" class="form-label">Role</label>
                                        <select class="form-select" id="edit_role" name="role">
                                            <option value="cashier">Cashier</option>
                                            <option value="dispatcher">Dispatcher</option>
                                            <option value="shop_attendant">Shop Attendant</option>
                                            <option value="staff">Staff</option>
                                            <option value="viewer">Viewer</option>
                                            <option value="manager">Manager</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="edit_password" class="form-label">New Password (leave blank to keep current)</label>
                                <input type="password" class="form-control" id="edit_password" name="new_password" minlength="6">
                                <div class="form-text">Minimum 6 characters. Leave blank to keep current password.</div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary" form="editUserForm">
                                    <i class="fas fa-save me-2"></i>Update User
                                </button>
                                <button type="button" class="btn btn-secondary" id="cancelEditUser">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Users List -->
                <div class="users-table-container">
                    <div class="table-header-actions">
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-list me-2"></i>System Users
                            </h5>
                            <p class="mb-0 text-muted small">Manage user accounts and permissions</p>
                        </div>
                        <div class="table-search">
                            <i class="fas fa-search"></i>
                            <input type="text" class="form-control" placeholder="Search users, roles..." id="usersSearch">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table users-table" id="usersTable">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="empty-state">
                                            <i class="fas fa-users-slash"></i>
                                            <p>No users found</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-info-cell">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($user['name'] ?? $user['username'], 0, 2)); ?>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></span>
                                                <span class="user-id">ID: <?php echo $user['id']; ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="username-cell"><?php echo htmlspecialchars($user['username']); ?></span>
                                    </td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <?php if ($can_edit): ?>
                                        <div class="action-buttons">
                                            <button class="action-btn btn-edit" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>', '<?php echo $user['role']; ?>')">
                                                <i class="fas fa-edit me-1"></i>Edit
                                            </button>
                                            <a href="permissions.php?user_id=<?php echo $user['id']; ?>" class="action-btn btn-permissions" title="Manage Permissions">
                                                <i class="fas fa-shield-alt me-1"></i>Permissions
                                            </a>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="delete_user" value="1">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="action-btn btn-delete" onclick="return confirm('Are you sure you want to delete this user?')">
                                                    <i class="fas fa-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">View Only</span>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <span class="badge bg-primary">Current User</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination-info">
                            Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $totalUsers); ?> of <?php echo $totalUsers; ?> users
                        </div>
                        <nav aria-label="Page navigation">
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == $page): ?>
                                <li class="page-item active">
                                    <span class="page-link"><?php echo $i; ?></span>
                                </li>
                                <?php elseif ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                                <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/offline-handler.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
    <script>
        // Update current time (GMT+1)
        function updateTime() {
            const now = new Date();
            // Convert to GMT+1 (West Africa Time)
            const gmtPlus1 = new Date(now.toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
            const timeString = gmtPlus1.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Update time every minute
        setInterval(updateTime, 60000);

        // Initial time update
        updateTime();
        
        // Search functionality
        document.getElementById('usersSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const table = document.getElementById('usersTable');
            const rows = table?.getElementsByTagName('tr') || [];
            
            for (let i = 1; i < rows.length; i++) { // Skip header row
                const row = rows[i];
                const text = row.textContent.toLowerCase();
                
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        });
        
        // Edit user function
        function editUser(id, name, role) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_role').value = role;
            
            const formCard = document.getElementById('editUserFormCard');
            if (formCard) {
                formCard.style.display = 'block';
                // Scroll to the form
                formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        
        // Close edit user form
        document.getElementById('closeEditUserForm')?.addEventListener('click', function(e) {
            e.preventDefault();
            const formCard = document.getElementById('editUserFormCard');
            if (formCard) {
                formCard.style.display = 'none';
            }
        });

        document.getElementById('cancelEditUser')?.addEventListener('click', function(e) {
            e.preventDefault();
            const formCard = document.getElementById('editUserFormCard');
            if (formCard) {
                formCard.style.display = 'none';
            }
        });

        // Toggle add user form
        document.getElementById('toggleAddUserForm')?.addEventListener('click', function() {
            const formCard = document.getElementById('addUserFormCard');
            if (formCard) {
                formCard.style.display = 'block';
                // Scroll to the form
                formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        // Close add user form
        document.getElementById('closeAddUserForm')?.addEventListener('click', function() {
            const formCard = document.getElementById('addUserFormCard');
            if (formCard) {
                formCard.style.display = 'none';
            }
        });

        document.getElementById('cancelAddUser')?.addEventListener('click', function() {
            const formCard = document.getElementById('addUserFormCard');
            if (formCard) {
                formCard.style.display = 'none';
            }
        });
    </script>
</body>
</html>t>


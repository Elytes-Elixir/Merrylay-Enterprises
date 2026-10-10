<?php
/**
 * User Permissions Management Page
 * 
 * Allows admins to assign granular permissions to individual users
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/permissions.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Only admin can manage permissions
if (!hasRole('admin')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized permission update by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to manage user permissions.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

$user_id = $_GET['user_id'] ?? null;
$error = '';
$success = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected permission mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please refresh and try again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

if (!$user_id) {
    header('Location: manage.php');
    exit();
}

// Get user details
try {
    $stmt = executeQuery("SELECT id, username, name, role FROM users WHERE id = ?", [$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: manage.php');
        exit();
    }
} catch (PDOException $e) {
    $error = 'Unable to load user details.';
    $user = null;
}

// Handle permission changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    if (isset($_POST['grant_permission'])) {
        $permission_key = $_POST['permission_key'] ?? '';
        if (grantPermission($user_id, $permission_key, $_SESSION['user_id'])) {
            $success = 'Permission granted successfully!';
        } else {
            $error = 'Unable to grant permission.';
        }
    }
    
    if (isset($_POST['revoke_permission'])) {
        $permission_key = $_POST['permission_key'] ?? '';
        if (revokePermission($user_id, $permission_key)) {
            $success = 'Permission revoked successfully!';
        } else {
            $error = 'Unable to revoke permission.';
        }
    }
}

// Get all permissions with user's current status
$permissions = getUserPermissionsWithStatus($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="User permissions management for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Manage Permissions - <?php echo htmlspecialchars($user['name']); ?> - Merrylay Enterprises</title>
    
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
        .permission-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .permission-category {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f3f4f6;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.5px;
        }
        
        .permission-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .permission-item:last-child {
            border-bottom: none;
        }
        
        .permission-info {
            flex: 1;
        }
        
        .permission-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }
        
        .permission-desc {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .permission-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-granted {
            background: #d1fae5;
            color: #059669;
        }
        
        .status-not-granted {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .user-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
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
                
                <div class="nav-section-title admin">Management</div>
                <a href="manage.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="../admin/data_management.php" class="nav-link">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <?php endif; ?>
                <a href="permissions.php?user_id=<?php echo $user_id; ?>" class="nav-link active">
                    <span class="nav-icon"><i class="fas fa-shield-alt"></i></span>
                    <span class="nav-text">Permissions</span>
                </a>
                
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
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search permissions..." id="permissionsSearch">
                    </div>
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
                                <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- User Header -->
                <div class="user-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1><i class="fas fa-shield-alt me-3"></i>Manage Permissions</h1>
                            <p class="mb-2"><?php echo htmlspecialchars($user['name']); ?></p>
                            <div class="d-flex gap-2">
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($user['username']); ?></span>
                                <span class="badge bg-light text-dark"><?php echo ucfirst($user['role']); ?></span>
                            </div>
                        </div>
                        <a href="manage.php" class="btn btn-light">
                            <i class="fas fa-arrow-left me-2"></i>Back to Users
                        </a>
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

                <!-- Setup Required Message -->
                <?php if (empty($permissions)): ?>
                <div class="content-card mb-4">
                    <div class="card-body">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Permission system not set up yet.</strong><br>
                            The permission database tables need to be created before you can manage permissions.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Permissions List -->
                <?php if (!empty($permissions)): foreach ($permissions as $category => $categoryPermissions): ?>
                <div class="permission-card">
                    <h5 class="permission-category">
                        <i class="fas fa-folder me-2"></i><?php echo ucfirst($category); ?>
                    </h5>
                    
                    <?php foreach ($categoryPermissions as $permission): ?>
                    <div class="permission-item">
                        <div class="permission-info">
                            <div class="permission-name"><?php echo htmlspecialchars($permission['permission_name']); ?></div>
                            <div class="permission-desc"><?php echo htmlspecialchars($permission['description']); ?></div>
                        </div>
                        <div class="permission-status">
                            <?php if ($permission['granted']): ?>
                                <span class="status-badge status-granted">
                                    <i class="fas fa-check me-1"></i>Granted
                                </span>
                                <?php if ($permission['granted_by_name']): ?>
                                <small class="text-muted">by <?php echo htmlspecialchars($permission['granted_by_name']); ?></small>
                                <?php endif; ?>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="revoke_permission" value="1">
                                    <input type="hidden" name="permission_key" value="<?php echo htmlspecialchars($permission['permission_key']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke this permission?')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="status-badge status-not-granted">
                                    <i class="fas fa-minus me-1"></i>Not Granted
                                </span>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="grant_permission" value="1">
                                    <input type="hidden" name="permission_key" value="<?php echo htmlspecialchars($permission['permission_key']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/offline-handler.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
    <script>
        // Search functionality
        document.getElementById('permissionsSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const permissionItems = document.querySelectorAll('.permission-item');
            
            permissionItems.forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>


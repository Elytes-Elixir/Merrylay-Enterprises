<?php
/**
 * User Profile Page for Merrylay Enterprises
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/db.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

$error = '';
$success = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected profile mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review your changes and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'profile_updated':
            $success = 'Profile updated successfully!';
            break;
        case 'password_changed':
            $success = 'Password changed successfully!';
            break;
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $csrfValid) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    if (empty($full_name)) {
        $error = 'Full name is required.';
    } elseif (empty($email)) {
        $error = 'Email is required.';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("
                UPDATE users 
                SET full_name = ?, email = ?, phone = ?, address = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $email, $phone, $address, $_SESSION['user_id']]);
            
            // Update session
            $_SESSION['full_name'] = $full_name;
            
            header('Location: profile.php?success=profile_updated');
            exit();
        } catch (PDOException $e) {
            error_log("Profile update error: " . $e->getMessage());
            $error = 'Unable to update profile. Email may already be in use.';
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password']) && $csrfValid) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All password fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($current_password, $user['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                header('Location: profile.php?success=password_changed');
                exit();
            } else {
                $error = 'Current password is incorrect.';
            }
        } catch (PDOException $e) {
            error_log("Password change error: " . $e->getMessage());
            $error = 'Unable to change password.';
        }
    }
}

// Get user data
try {
    $stmt = executeQuery("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Load user error: " . $e->getMessage());
    $user = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - Merrylay Enterprises</title>
    <link href="../assets/fonts/inter/inter.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/clean-dashboard.css" rel="stylesheet">
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
        
        .profile-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            color: white;
        }
        
        .profile-info h2 {
            margin-bottom: 0.5rem;
            color: #1f2937;
        }
        
        .profile-info p {
            color: #6b7280;
            margin: 0;
        }
        
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            background: #e0e7ff;
            color: #4338ca;
            margin-top: 0.5rem;
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
                <div class="nav-section">
                    <div class="nav-section-title">Overview</div>
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
                </div>

                <?php if (isDispatcher() || hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Dispatch</div>
                    <?php if (hasRole(['admin', 'manager'])): ?>
                    <a href="../dispatch/drivers.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-user-tie"></i></span>
                        <span class="nav-text">Dispatchers</span>
                    </a>
                    <?php endif; ?>
                    <a href="../dispatch/trips/record.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-truck"></i></span>
                        <span class="nav-text">New Dispatch</span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="nav-section">
                    <div class="nav-section-title">Sales</div>
                    <a href="../dispatch/daily/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-plus-circle"></i></span>
                        <span class="nav-text">New Sale</span>
                    </a>
                    <a href="../cashier/daily.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                        <span class="nav-text">Daily Financials</span>
                    </a>
                </div>

                <?php if (isCashier() || hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Finance</div>
                    <?php if (hasRole(['admin', 'manager'])): ?>
                    <a href="../dispatch/daily-accounting.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-calculator"></i></span>
                        <span class="nav-text">Daily Accounting</span>
                    </a>
                    <?php endif; ?>
                    <a href="../dispatch/daily/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-calendar-day"></i></span>
                        <span class="nav-text">Daily Sales</span>
                    </a>
                    <a href="../dispatch/customer-payments.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-money-bill-wave"></i></span>
                        <span class="nav-text">Customer Payments</span>
                    </a>
                    <a href="../dispatch/credit/index.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-text">Credit Management</span>
                    </a>
                    <a href="../dispatch/dispatcher-expenses.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-receipt"></i></span>
                        <span class="nav-text">Dispatcher Expenses</span>
                    </a>
                    <?php if (isCashier() || hasRole(['admin', 'manager'])): ?>
                    <a href="../dispatch/bank-deposits.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-university"></i></span>
                        <span class="nav-text">Bank Deposits</span>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="../dispatch/daily-accounting.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                        <span class="nav-text">Account Summary</span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <?php if (hasRole(['admin'])): ?>
                    <a href="../admin/backup.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-server"></i></span>
                        <span class="nav-text">Database Backup</span>
                    </a>
                    <?php endif; ?>
                    <a href="../auth/logout.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="nav-text">Logout</span>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main content -->
        <main class="main-content" id="mainContent">
            <div class="page-header">
                <div class="container-fluid">
                    <h1><i class="fas fa-user me-2"></i>User Profile</h1>
                    <p class="mb-0">Manage your account settings and preferences</p>
                </div>
            </div>
            
            <div class="container-fluid px-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($user): ?>
                    <div class="profile-section">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($user['full_name'] ?? $user['username'] ?? 'User', 0, 2)); ?>
                            </div>
                            <div class="profile-info">
                                <h2><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h2>
                                <p><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                                <span class="role-badge"><?php echo ucfirst($user['role']); ?></span>
                            </div>
                        </div>

                        <h5>Update Profile Information</h5>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="update_profile" value="1">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" 
                                               value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone</label>
                                        <input type="text" class="form-control" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <input type="text" class="form-control" id="address" name="address" 
                                               value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                        </form>
                    </div>

                    <div class="profile-section">
                        <h5>Change Password</h5>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="change_password" value="1">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </form>
                    </div>

                    <div class="profile-section">
                        <h5>Account Information</h5>
                        <table class="table">
                            <tr>
                                <th>Username</th>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                            </tr>
                            <tr>
                                <th>Role</th>
                                <td><?php echo ucfirst($user['role']); ?></td>
                            </tr>
                            <tr>
                                <th>Last Login</th>
                                <td><?php echo $user['last_login'] ?? null ? date('Y-m-d H:i:s', strtotime($user['last_login'])) : 'Never'; ?></td>
                            </tr>
                            <tr>
                                <th>Account Created</th>
                                <td><?php echo $user['created_at'] ?? null ? date('Y-m-d H:i:s', strtotime($user['created_at'])) : 'Unknown'; ?></td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>
                                    <?php if ($user['is_active'] ?? true): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


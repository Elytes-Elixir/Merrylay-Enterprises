<?php
/**
 * Database Backup System for Merrylay Enterprises
 * Admin only
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized backup action by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to manage backups.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

/**
 * Find mysqldump executable in the environment or common XAMPP path.
 *
 * @return string|null
 */
function findMysqldumpExecutable() {
    $output = [];
    $return_var = 1;
    exec('where mysqldump 2>&1', $output, $return_var);
    if ($return_var === 0 && !empty($output)) {
        return trim($output[0]);
    }

    $commonPath = getenv('SystemDrive') . '\\xampp\\mysql\\bin\\mysqldump.exe';
    if ($commonPath && file_exists($commonPath)) {
        return $commonPath;
    }

    return null;
}

/**
 * Find mysql executable in the environment or common XAMPP path.
 *
 * @return string|null
 */
function findMysqlExecutable() {
    $output = [];
    $return_var = 1;
    exec('where mysql 2>&1', $output, $return_var);
    if ($return_var === 0 && !empty($output)) {
        return trim($output[0]);
    }

    $commonPath = getenv('SystemDrive') . '\xampp\mysql\bin\mysql.exe';
    if ($commonPath && file_exists($commonPath)) {
        return $commonPath;
    }

    return null;
}

/**
 * Restore a SQL file to the current database using PDO.
 *
 * @param PDO $pdo
 * @param string $sqlFile
 * @return bool
 */
function restoreSqlFile(PDO $pdo, $sqlFile) {
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        return false;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Restore error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Safely quote a SQL value for export.
 *
 * @param mixed $value
 * @return string
 */
function quoteSqlValue($value) {
    if ($value === null) {
        return 'NULL';
    }

    return "'" . str_replace(
        ["\\", "\n", "\r", "\t", "\0", "'", '"'],
        ["\\\\", "\\n", "\\r", "\\t", "\\0", "\\'", '\\"'],
        $value
    ) . "'";
}

/**
 * Create a SQL backup using PHP when mysqldump is unavailable.
 *
 * @param PDO $pdo
 * @param string $backup_file
 * @return bool
 */
function createPhpSqlBackup($pdo, $backup_file) {
    $sql = "-- Backup created on " . date('Y-m-d H:i:s') . "\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $createResult = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        if (!$createResult || !isset($createResult['Create Table'])) {
            continue;
        }

        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $createResult['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map('quoteSqlValue', array_values($row));
            $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
        }

        $sql .= "\n";
    }

    $views = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);
    foreach ($views as $viewRow) {
        $view = $viewRow[0];
        $createResult = $pdo->query("SHOW CREATE VIEW `{$view}`")->fetch(PDO::FETCH_ASSOC);
        $createViewSql = $createResult['Create View'] ?? $createResult['Create Table'] ?? null;
        if (!$createViewSql) {
            continue;
        }

        $sql .= "DROP VIEW IF EXISTS `{$view}`;\n";
        $sql .= $createViewSql . ";\n\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return file_put_contents($backup_file, $sql) !== false;
}

$error = '';
$success = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected backup mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please try again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'backup_created':
            $success = 'Backup created successfully!';
            break;
        case 'backup_deleted':
            $success = 'Backup deleted successfully!';
            break;
        case 'restore_completed':
            $success = 'Restore completed successfully!';
            break;
    }
}

// Handle backup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup']) && $csrfValid) {
    try {
        $pdo = getDBConnection();
        $backup_dir = '../backups';
        
        // Create backups directory if it doesn't exist
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        // Get database configuration
        $stmt = $pdo->query("SELECT DATABASE() as db_name");
        $db_info = $stmt->fetch();
        $db_name = $db_info['db_name'];
        
        // Create backup filename
        $backup_file = $backup_dir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Use mysqldump if available, otherwise fall back to PHP export
        $host = DB_HOST;
        $username = DB_USER;
        $password = DB_PASS;
        $mysqldump = findMysqldumpExecutable();

        if ($mysqldump) {
            if ($password === '') {
                $command = sprintf(
                    '"%s" -h%s -u%s %s > %s',
                    $mysqldump,
                    escapeshellarg($host),
                    escapeshellarg($username),
                    escapeshellarg($db_name),
                    escapeshellarg($backup_file)
                );
            } else {
                $command = sprintf(
                    '"%s" -h%s -u%s -p%s %s > %s',
                    $mysqldump,
                    escapeshellarg($host),
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($db_name),
                    escapeshellarg($backup_file)
                );
            }

            exec($command, $output, $return_var);

            if ($return_var !== 0) {
                $mysqldump = null;
            }
        } else {
            $output = ['mysqldump not found, using PHP fallback'];
            $return_var = 1;
        }

        if ($mysqldump && file_exists($backup_file) && filesize($backup_file) > 0) {
            header('Location: backup.php?success=backup_created');
            exit();
        } elseif (createPhpSqlBackup($pdo, $backup_file)) {
            header('Location: backup.php?success=backup_created');
            exit();
        } else {
            $error = 'Failed to create backup. Check database connection and file permissions.';
            if (!empty($output)) {
                $error .= ' Error: ' . implode(', ', $output);
            }
        }
    } catch (Exception $e) {
        error_log("Backup error: " . $e->getMessage());
        $error = 'Unable to create backup: ' . $e->getMessage();
    }
}

// Handle backup deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup']) && $csrfValid) {
    $backup_file = $_POST['backup_file'] ?? '';
    
    if (empty($backup_file)) {
        $error = 'Backup file is required.';
    } elseif (!file_exists($backup_file)) {
        $error = 'Backup file does not exist.';
    } else {
        if (unlink($backup_file)) {
            header('Location: backup.php?success=backup_deleted');
            exit();
        } else {
            $error = 'Unable to delete backup file.';
        }
    }
}

// Handle restore upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup']) && $csrfValid) {
    if (($_POST['confirm_restore'] ?? '') !== '1') {
        $error = 'Confirm that the restore will overwrite existing data before continuing.';
    } elseif (!isset($_FILES['restore_file']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a valid SQL file to restore.';
    } else {
        $uploadedFile = $_FILES['restore_file'];
        $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

        if ($extension !== 'sql') {
            $error = 'Only .sql files are supported for restore.';
        } elseif ($uploadedFile['size'] > 50 * 1024 * 1024) {
            $error = 'Restore file is too large. Maximum allowed size is 50MB.';
        } else {
            $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($uploadedFile['tmp_name']) . '_' . time() . '.sql';
            if (!move_uploaded_file($uploadedFile['tmp_name'], $tempPath)) {
                $error = 'Unable to save the uploaded file. Check server upload permissions.';
            } else {
                try {
                    $pdo = getDBConnection();
                    $stmt = $pdo->query("SELECT DATABASE() as db_name");
                    $db_info = $stmt->fetch();
                    $db_name = $db_info['db_name'];
                    $mysql = findMysqlExecutable();

                    if ($mysql) {
                        $host = DB_HOST;
                        $username = DB_USER;
                        $password = DB_PASS;
                        if ($password === '') {
                            $command = sprintf(
                                '"%s" -h%s -u%s %s < %s 2>&1',
                                $mysql,
                                escapeshellarg($host),
                                escapeshellarg($username),
                                escapeshellarg($db_name),
                                escapeshellarg($tempPath)
                            );
                        } else {
                            $command = sprintf(
                                '"%s" -h%s -u%s -p%s %s < %s 2>&1',
                                $mysql,
                                escapeshellarg($host),
                                escapeshellarg($username),
                                escapeshellarg($password),
                                escapeshellarg($db_name),
                                escapeshellarg($tempPath)
                            );
                        }
                        exec($command, $output, $return_var);
                        if ($return_var === 0) {
                            header('Location: backup.php?success=restore_completed');
                            exit();
                        } else {
                            $error = 'Restore failed using mysql command.';
                            if (!empty($output)) {
                                $error .= ' Error: ' . implode(' ', $output);
                            }
                        }
                    } else {
                        if (restoreSqlFile($pdo, $tempPath)) {
                            header('Location: backup.php?success=restore_completed');
                            exit();
                        } else {
                            $error = 'Restore failed using PHP fallback import.';
                        }
                    }
                } catch (Exception $e) {
                    error_log('Restore error: ' . $e->getMessage());
                    $error = 'Unable to restore backup: ' . $e->getMessage();
                } finally {
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            }
        }
    }
}

// Get list of existing backups
$backups = [];
$backup_dir = '../backups';
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $filepath = $backup_dir . '/' . $file;
            $backups[] = [
                'filename' => $file,
                'filepath' => $filepath,
                'size' => filesize($filepath),
                'created' => filemtime($filepath)
            ];
        }
    }
    
    // Sort by creation date (newest first)
    usort($backups, function($a, $b) {
        return $b['created'] - $a['created'];
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Database backup administration for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Database Backup - Merrylay Enterprises</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Dashboard Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    <style>
        .backup-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.06);
            padding: 1.75rem;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .backup-card h5 {
            margin-bottom: 1.5rem;
            color: #3730a3;
        }

        .backup-info {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #f8fafc;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .backup-info .backup-icon {
            font-size: 1.5rem;
            color: #4f46e5;
        }
    </style>
</head>
<body>
    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
    <div class="dashboard-container">
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
                <a href="../dashboard/index.php" class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="../products/view.php" class="nav-link <?php echo $currentPage === 'view.php' && strpos($_SERVER['PHP_SELF'], 'products') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                    <span class="nav-text">Products</span>
                </a>
                <a href="../stock/manage.php" class="nav-link <?php echo $currentPage === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-text">Stock</span>
                </a>
                <a href="../stock/initial_setup.php" class="nav-link <?php echo $currentPage === 'initial_setup.php' && strpos($_SERVER['PHP_SELF'], 'stock') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-play-circle"></i></span>
                    <span class="nav-text">Initial Stock Setup</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordDispatches()): ?>
                <a href="../dispatch/trips/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'trips') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-truck"></i></span>
                    <span class="nav-text">Trips (Dispatch)</span>
                </a>
                <?php endif; ?>
                
                <?php if (canRecordFinancials()): ?>
                <div class="nav-section-title overview">Daily Workflow</div>

                <a href="../dispatch/daily/index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'daily') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                    <span class="nav-text">Today's Records</span>
                </a>

                <a href="../dispatch/daily/account-summary.php" class="nav-link <?php echo $currentPage === 'account-summary.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-text">Account Summary</span>
                </a>

                <a href="../finance/daily_balance.php" class="nav-link <?php echo $currentPage === 'daily_balance.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-balance-scale"></i></span>
                    <span class="nav-text">Daily Balance</span>
                </a>

                <?php if (canRecordBankDeposits()): ?>
                <a href="../cashier/deposit.php" class="nav-link <?php echo $currentPage === 'deposit.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-university"></i></span>
                    <span class="nav-text">Bank Deposit</span>
                </a>
                <?php endif; ?>

                <a href="../dispatch/credit/index.php" class="nav-link <?php echo $currentPage === 'record_credit_settlements.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-text">Credit Management</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'manager'])): ?>
                <div class="nav-section-title admin">Management</div>
                <a href="end-of-day.php" class="nav-link <?php echo $currentPage === 'end-of-day.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-text">End of Day</span>
                </a>
                <a href="../reports/daily.php" class="nav-link <?php echo $currentPage === 'daily.php' && strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-text">Reports</span>
                </a>
                <a href="../users/manage.php" class="nav-link <?php echo $currentPage === 'manage.php' && strpos($_SERVER['PHP_SELF'], 'users') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-text">Users</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="data_management.php" class="nav-link <?php echo $currentPage === 'data_management.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-database"></i></span>
                    <span class="nav-text">Data Management</span>
                </a>
                <a href="index.php" class="nav-link <?php echo $currentPage === 'index.php' && strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-text">Stock Statistics</span>
                </a>
                <div class="nav-section-title admin">System</div>
                <a href="backup.php" class="nav-link <?php echo $currentPage === 'backup.php' ? 'active' : ''; ?>">
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

        <main class="main-content" id="mainContent">
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search products, sales, dispatches..." id="globalSearch">
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
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <li><a class="dropdown-item" href="../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <div class="dashboard-content">
                <div class="welcome-header mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <h1>Database Backup</h1>
                            <p class="text-muted">Create and manage your backups with the dashboard experience.</p>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12">
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
                    </div>

                    <div class="col-12 col-xl-6">
                        <div class="backup-card">
                            <h5><i class="fas fa-plus-circle me-2"></i>Create New Backup</h5>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="create_backup" value="1">
                                <p>Create a full database backup. This will save all tables and data to a SQL file.</p>
                                <button type="submit" class="btn btn-primary px-4 py-2">
                                    <i class="fas fa-download me-2"></i>Create Backup
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-12 col-xl-6">
                        <div class="backup-card">
                            <h5><i class="fas fa-info-circle me-2"></i>Restore Backup</h5>
                            <p class="text-muted">Upload an SQL file to restore your database. This process will overwrite existing data.</p>
                            <form method="POST" action="" enctype="multipart/form-data" onsubmit="return confirm('Restore will overwrite existing data. Continue only if you have a current backup.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="mb-3">
                                    <label for="restore_file" class="form-label">Select SQL file</label>
                                    <input class="form-control" type="file" id="restore_file" name="restore_file" accept=".sql" required>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="confirm_restore" name="confirm_restore" value="1" required>
                                    <label class="form-check-label" for="confirm_restore">I confirm this restore will overwrite existing data.</label>
                                </div>
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <button type="submit" name="restore_backup" value="1" class="btn btn-warning px-4 py-2">
                                        <i class="fas fa-upload me-2"></i>Upload & Restore
                                    </button>
                                    <span class="text-muted small mb-0">Please backup current data before restoring.</span>
                                </div>
                            </form>
                            <div class="mt-4">
                                <h6 class="mb-2">Manual restore command</h6>
                                <pre class="bg-light p-3 rounded"><code>mysql -u username -p database_name &lt; backup_file.sql</code></pre>
                                <p class="mb-0 small text-muted">If the upload restore fails, download the backup and run this on your database server.</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="backup-card">
                            <h5><i class="fas fa-list me-2"></i>Existing Backups</h5>
                            <?php if (empty($backups)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-database fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No backups found. Create your first backup above.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($backups as $backup): ?>
                                    <div class="backup-info">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="backup-icon"><i class="fas fa-file-alt"></i></div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($backup['filename']); ?></strong>
                                                <div class="text-muted small">
                                                    Size: <?php echo number_format($backup['size'] / 1024, 2); ?> KB | Created: <?php echo date('Y-m-d H:i:s', $backup['created']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="btn-group">
                                            <a href="<?php echo htmlspecialchars($backup['filepath']); ?>" download class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-download me-1"></i>Download
                                            </a>
                                            <form method="POST" action="" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="delete_backup" value="1">
                                                <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($backup['filepath']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this backup?')">
                                                    <i class="fas fa-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/clean-dashboard.js"></script>
</body>
</html>


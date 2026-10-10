<?php
/**
 * Dispatcher Management Page
 * 
 * Dispatchers are drivers who also serve as sales persons.
 * They take products from the depot and sell to customers.
 * This page manages dispatcher/driver records.
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../init.php';
require_once '../config/db.php';
require_once '../config/cache.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Check if user has permission to manage dispatchers
if (!hasRole(['admin', 'manager'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized driver mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to manage drivers.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';
$success = $_SESSION['driver_success'] ?? '';
unset($_SESSION['driver_success']);
$edit_dispatcher = null;
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected driver mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the driver details and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Handle dispatcher edit request
if (isset($_GET['edit'])) {
    $dispatcher_id = intval($_GET['edit']);
    try {
        $stmt = executeQuery("SELECT * FROM drivers WHERE id = ?", [$dispatcher_id]);
        $edit_dispatcher = $stmt->fetch();
        if (!$edit_dispatcher) {
            $error = 'Dispatcher not found.';
        }
    } catch (PDOException $e) {
        $error = 'Failed to load dispatcher: ' . $e->getMessage();
    }
}

// Handle dispatcher creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_dispatcher']) && $csrfValid) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $notes = trim($_POST['notes'] ?? '');

    if (empty($name) || empty($phone)) {
        $error = 'Name and phone are required.';
    } else {
        try {
            $query = "INSERT INTO drivers (name, phone, license_number, status, notes)
                     VALUES (?, ?, ?, ?, ?)";
            executeQuery($query, [$name, $phone, $license_number, $status, $notes]);
            $_SESSION['driver_success'] = 'Driver added successfully!';
            header('Location: drivers.php');
            exit();
        } catch (PDOException $e) {
            $error = 'Failed to add driver: ' . $e->getMessage();
        }
    }
}

// Handle dispatcher update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dispatcher']) && $csrfValid) {
    $dispatcher_id = intval($_POST['dispatcher_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $notes = trim($_POST['notes'] ?? '');

    if (empty($name) || empty($phone)) {
        $error = 'Name and phone are required.';
    } else {
        try {
            $query = "UPDATE drivers SET name = ?, phone = ?, license_number = ?, status = ?, notes = ? WHERE id = ?";
            executeQuery($query, [$name, $phone, $license_number, $status, $notes, $dispatcher_id]);
            $_SESSION['driver_success'] = 'Driver updated successfully!';
            header('Location: drivers.php');
            exit();
            $edit_dispatcher = null; // Clear edit mode
        } catch (PDOException $e) {
            $error = 'Failed to update driver: ' . $e->getMessage();
        }
    }
}

// Handle dispatcher deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_dispatcher']) && $csrfValid) {
    $dispatcher_id = intval($_POST['dispatcher_id'] ?? 0);
    try {
        executeQuery("DELETE FROM drivers WHERE id = ?", [$dispatcher_id]);
        $_SESSION['driver_success'] = 'Dispatcher deleted successfully!';
        header('Location: drivers.php');
        exit();
    } catch (PDOException $e) {
        $error = 'Failed to delete dispatcher: ' . $e->getMessage();
    }
}

// Get all dispatchers
try {
    $stmt = executeQuery("SELECT * FROM drivers ORDER BY name");
    $dispatchers = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Load dispatchers error: " . $e->getMessage());
    $dispatchers = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage dispatchers and drivers for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Dispatchers (Drivers/Salespersons) - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        /* Dispatcher Card Styles */
        .dispatcher-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: var(--space-5);
            margin-bottom: var(--space-4);
            border: 1px solid var(--border-light);
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }
        
        .dispatcher-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--blue) 0%, var(--green) 100%);
            opacity: 0;
            transition: opacity var(--transition-normal);
        }
        
        .dispatcher-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-dark);
        }
        
        .dispatcher-card:hover::before {
            opacity: 1;
        }
        
        .dispatcher-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
        }
        
        .dispatcher-name {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--primary-800);
            margin: 0;
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .dispatcher-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.75rem;
            color: var(--primary-500);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--space-1);
        }
        
        .info-value {
            font-size: 0.875rem;
            color: var(--primary-700);
            font-weight: 500;
        }
        
        /* Status Badge Styles */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--space-1);
            padding: var(--space-1) var(--space-3);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-badge.active {
            background-color: rgb(16 185 129 / 0.1);
            color: var(--green);
            border: 1px solid rgb(16 185 129 / 0.2);
        }
        
        .status-badge.inactive {
            background-color: rgb(239 68 68 / 0.1);
            color: var(--red);
            border: 1px solid rgb(239 68 68 / 0.2);
        }
        
        .status-badge.on_leave {
            background-color: rgb(245 158 11 / 0.1);
            color: var(--yellow);
            border: 1px solid rgb(245 158 11 / 0.2);
        }
        
        .status-badge.retired {
            background-color: rgb(107 114 128 / 0.1);
            color: var(--primary-600);
            border: 1px solid rgb(107 114 128 / 0.2);
        }
        
        /* View Controls */
        .view-controls {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .view-label {
            color: var(--primary-600);
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .view-toggles {
            display: flex;
            gap: var(--space-1);
            background: var(--bg-secondary);
            padding: var(--space-1);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
        }
        
        .btn-view {
            background: transparent;
            border: none;
            color: var(--primary-600);
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
            font-size: 0.75rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: var(--space-1);
        }
        
        .btn-view:hover {
            background: var(--bg-tertiary);
            color: var(--primary-800);
        }
        
        .btn-view.active {
            background: var(--blue);
            color: white;
        }
        
        /* Dispatcher Container Views */
        .dispatchers-container {
            transition: all var(--transition-normal);
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-5);
        }
        
        /* Grid View (Default) */
        .dispatchers-container.view-grid .dispatcher-card {
            width: calc(33.333% - 1.667rem);
            min-width: 320px;
        }
        
        /* List View */
        .dispatchers-container.view-list {
            flex-direction: column;
            gap: var(--space-4);
        }
        
        .dispatchers-container.view-list .dispatcher-card {
            display: flex;
            align-items: center;
            gap: var(--space-6);
            padding: var(--space-4) var(--space-6);
            width: 100%;
        }
        
        .dispatchers-container.view-list .dispatcher-header {
            min-width: 250px;
            margin-bottom: 0;
        }
        
        .dispatchers-container.view-list .dispatcher-info {
            flex: 1;
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-8);
        }
        
        .dispatchers-container.view-list .action-buttons {
            margin-left: auto;
        }
        
        /* Compact View */
        .dispatchers-container.view-compact .dispatcher-card {
            padding: var(--space-3) var(--space-4);
            width: calc(25% - 1.875rem);
            min-width: 250px;
        }
        
        .dispatchers-container.view-compact .dispatcher-name {
            font-size: 0.95rem;
        }
        
        .dispatchers-container.view-compact .dispatcher-info {
            gap: var(--space-3);
        }
        
        .dispatchers-container.view-compact .info-label {
            font-size: 0.65rem;
        }
        
        .dispatchers-container.view-compact .info-value {
            font-size: 0.8rem;
        }
        
        .dispatchers-container.view-compact .status-badge {
            padding: var(--space-1) var(--space-2);
            font-size: 0.65rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .dispatchers-container.view-grid .dispatcher-card {
                width: calc(50% - 1.25rem);
            }
            .dispatchers-container.view-compact .dispatcher-card {
                width: calc(33.333% - 1.667rem);
            }
        }
        
        @media (max-width: 768px) {
            .dispatchers-container.view-grid .dispatcher-card,
            .dispatchers-container.view-compact .dispatcher-card {
                width: 100%;
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
            
                        <?php
            $dispatcherSidebarPath = __DIR__ . '/components/dispatcher-sidebar.php';
            $searchDir = __DIR__;
            while (!file_exists($dispatcherSidebarPath) && dirname($searchDir) !== $searchDir) {
                $searchDir = dirname($searchDir);
                $dispatcherSidebarPath = $searchDir . '/components/dispatcher-sidebar.php';
            }
            include $dispatcherSidebarPath;
            ?>
        </aside>

        <!-- Main content -->
        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search dispatchers, drivers, trips..." id="globalSearch">
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

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1 class="page-title">
                                <i class="fas fa-users" style="margin-right: 15px;"></i>
                                Manage Drivers
                            </h1>
                            <p class="page-subtitle">
                                Manage drivers available for dispatch. Drivers can be assigned to any vehicle for each dispatch.
                            </p>
                        </div>
                        <div class="view-controls">
                            <span class="view-label">View:</span>
                            <div class="view-toggles">
                                <button class="btn-view active" data-view="grid" title="Grid View">
                                    <i class="fas fa-th-large"></i> Grid
                                </button>
                                <button class="btn-view" data-view="list" title="List View">
                                    <i class="fas fa-list"></i> List
                                </button>
                                <button class="btn-view" data-view="compact" title="Compact View">
                                    <i class="fas fa-th"></i> Compact
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Add/Edit Dispatcher Form -->
                <div class="content-card">
                    <div class="card-header" style="cursor: pointer;" onclick="toggleForm()">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-<?php echo $edit_dispatcher ? 'edit' : 'plus-circle'; ?>" style="margin-right: 10px;"></i>
                                    <?php echo $edit_dispatcher ? 'Edit Driver' : 'Add New Driver'; ?>
                                </h5>
                                <p class="card-subtitle">Add or update driver information. Drivers will be assigned to vehicles during dispatch creation.</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleFormBtn">
                                <i class="fas fa-chevron-up" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="dispatcherForm">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($edit_dispatcher): ?>
                                <input type="hidden" name="dispatcher_id" value="<?php echo $edit_dispatcher['id']; ?>">
                            <?php endif; ?>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="name" class="form-label">Driver Name <span style="color: var(--red); font-weight: 700;">*</span></label>
                                        <input type="text" class="form-control" id="name" name="name"
                                               value="<?php echo htmlspecialchars($edit_dispatcher['name'] ?? ''); ?>"
                                               placeholder="e.g., John Doe" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="phone" class="form-label">Phone <span style="color: var(--red); font-weight: 700;">*</span></label>
                                        <input type="text" class="form-control" id="phone" name="phone"
                                               value="<?php echo htmlspecialchars($edit_dispatcher['phone'] ?? ''); ?>"
                                               placeholder="08012345678" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="license_number" class="form-label">License Number</label>
                                        <input type="text" class="form-control" id="license_number" name="license_number"
                                               value="<?php echo htmlspecialchars($edit_dispatcher['license_number'] ?? ''); ?>"
                                               placeholder="e.g., DL-123456">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="active" <?php echo ($edit_dispatcher['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($edit_dispatcher['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="on_leave" <?php echo ($edit_dispatcher['status'] ?? '') === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                            <option value="retired" <?php echo ($edit_dispatcher['status'] ?? '') === 'retired' ? 'selected' : ''; ?>>Retired</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="notes" class="form-label">Notes</label>
                                        <input type="text" class="form-control" id="notes" name="notes"
                                               value="<?php echo htmlspecialchars($edit_dispatcher['notes'] ?? ''); ?>"
                                               placeholder="Additional notes...">
                                    </div>
                                </div>
                            </div>
                            <div class="action-buttons">
                                <button type="submit" name="<?php echo $edit_dispatcher ? 'update_dispatcher' : 'add_dispatcher'; ?>" class="btn btn-primary">
                                    <i class="fas fa-<?php echo $edit_dispatcher ? 'save' : 'plus'; ?>"></i>
                                    <?php echo $edit_dispatcher ? 'Update Driver' : 'Add Driver'; ?>
                                </button>
                                <?php if ($edit_dispatcher): ?>
                                    <a href="drivers.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Dispatchers List -->
                <?php if (count($dispatchers) > 0): ?>
                    <div class="dispatchers-container view-grid" id="dispatchersContainer">
                        <?php foreach ($dispatchers as $dispatcher): ?>
                        <div class="dispatcher-card">
                            <div class="dispatcher-header">
                                <h5 class="dispatcher-name">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($dispatcher['name']); ?>
                                </h5>
                                <span class="status-badge status-<?php echo $dispatcher['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $dispatcher['status'])); ?>
                                </span>
                            </div>
                            <div class="dispatcher-info">
                                <div class="info-item">
                                    <div class="info-label">Phone</div>
                                    <div class="info-value"><?php echo htmlspecialchars($dispatcher['phone']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">License Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($dispatcher['license_number'] ?: 'N/A'); ?></div>
                                </div>
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-primary" onclick="editDispatcher(<?php echo $dispatcher['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <form method="POST" action="drivers.php" onsubmit="return confirm('Are you sure you want to delete this dispatcher?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="delete_dispatcher" value="1">
                                    <input type="hidden" name="dispatcher_id" value="<?php echo (int) $dispatcher['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <h6>No Dispatchers Found</h6>
                        <p>Add your first dispatcher (driver/salesperson) to get started with trip tracking.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
    <script>
        // Sidebar toggle functionality
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainContent').classList.toggle('expanded');
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
        function editDispatcher(id) {
            window.location.href = 'drivers.php?edit=' + id;
        }

        function toggleForm() {
            const form = document.getElementById('dispatcherForm');
            const icon = document.getElementById('toggleIcon');

            if (form.style.display === 'none') {
                form.style.display = 'block';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                form.style.display = 'none';
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }

        // View switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            const viewToggles = document.querySelectorAll('.btn-view');
            const dispatchersContainer = document.getElementById('dispatchersContainer');

            // Load saved view preference
            const savedView = localStorage.getItem('dispatcherView') || 'grid';
            setView(savedView);

            // Handle view toggle clicks
            viewToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const view = this.getAttribute('data-view');
                    setView(view);
                    localStorage.setItem('dispatcherView', view);
                });
            });

            function setView(view) {
                // Update active button
                viewToggles.forEach(toggle => {
                    toggle.classList.toggle('active', toggle.getAttribute('data-view') === view);
                });

                // Update container class
                dispatchersContainer.classList.remove('view-grid', 'view-list', 'view-compact');
                dispatchersContainer.classList.add('view-' + view);
            }

            // Auto-collapse form if not in edit mode
            <?php if (!$edit_dispatcher): ?>
            const form = document.getElementById('dispatcherForm');
            const icon = document.getElementById('toggleIcon');
            form.style.display = 'none';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
            <?php endif; ?>
        });
    </script>
</body>
</html>


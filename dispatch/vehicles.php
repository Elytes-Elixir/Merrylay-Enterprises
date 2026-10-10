<?php
/**
 * Vehicle Management Page
 * 
 * Allows management of vehicles/trucks available for dispatch
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

// Check if user has permission to manage vehicles
if (!hasRole(['admin', 'manager'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized vehicle mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to manage vehicles.');
    }
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';
$success = $_SESSION['vehicle_success'] ?? '';
unset($_SESSION['vehicle_success']);
$edit_vehicle = null;
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected vehicle mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the vehicle details and submit again.';
    }
}
$csrfToken = SecurityUtils::generateCSRFToken();

// Handle vehicle edit request
if (isset($_GET['edit'])) {
    $vehicle_id = intval($_GET['edit']);
    try {
        $stmt = executeQuery("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id]);
        $edit_vehicle = $stmt->fetch();
        if (!$edit_vehicle) {
            $error = 'Vehicle not found.';
        }
    } catch (PDOException $e) {
        $error = 'Failed to load vehicle: ' . $e->getMessage();
    }
}

// Handle vehicle creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vehicle']) && $csrfValid) {
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');
    $vehicle_type = $_POST['vehicle_type'] ?? 'truck';
    $status = $_POST['status'] ?? 'active';

    if (empty($vehicle_number)) {
        $error = 'Vehicle number is required.';
    } else {
        try {
            $query = "INSERT INTO vehicles (vehicle_number, vehicle_type, status)
                     VALUES (?, ?, ?)";
            executeQuery($query, [$vehicle_number, $vehicle_type, $status]);
            $_SESSION['vehicle_success'] = 'Vehicle added successfully!';
            header('Location: vehicles.php');
            exit();
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = 'Vehicle number already exists.';
            } else {
                $error = 'Failed to add vehicle: ' . $e->getMessage();
            }
        }
    }
}

// Handle vehicle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vehicle']) && $csrfValid) {
    $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');
    $vehicle_type = $_POST['vehicle_type'] ?? 'truck';
    $status = $_POST['status'] ?? 'active';

    if (empty($vehicle_number)) {
        $error = 'Vehicle number is required.';
    } else {
        try {
            $query = "UPDATE vehicles SET vehicle_number = ?, vehicle_type = ?, status = ? WHERE id = ?";
            executeQuery($query, [$vehicle_number, $vehicle_type, $status, $vehicle_id]);
            $_SESSION['vehicle_success'] = 'Vehicle updated successfully!';
            header('Location: vehicles.php');
            exit();
            $edit_vehicle = null; // Clear edit mode
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = 'Vehicle number already exists.';
            } else {
                $error = 'Failed to update vehicle: ' . $e->getMessage();
            }
        }
    }
}

// Handle vehicle deletion (only if not a POST request for add/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vehicle']) && $csrfValid) {
    $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
    try {
        // Check if vehicle is used in any dispatch
        $stmt = executeQuery("SELECT COUNT(*) as count FROM dispatches WHERE vehicle_id = ?", [$vehicle_id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $error = 'Cannot delete vehicle that is used in dispatches. Please deactivate it instead.';
        } else {
            executeQuery("DELETE FROM vehicles WHERE id = ?", [$vehicle_id]);
            $_SESSION['vehicle_success'] = 'Vehicle deleted successfully!';
            header('Location: vehicles.php');
            exit();
        }
    } catch (PDOException $e) {
        $error = 'Failed to delete vehicle: ' . $e->getMessage();
    }
}

// Get all vehicles
try {
    $stmt = executeQuery("SELECT v.*, COUNT(d.id) as dispatch_count 
                          FROM vehicles v 
                          LEFT JOIN dispatches d ON v.id = d.vehicle_id 
                          GROUP BY v.id ORDER BY v.vehicle_number");
    $vehicles = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Load vehicles error: " . $e->getMessage());
    $vehicles = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vehicle management dashboard for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <meta name="robots" content="index, follow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Manage Vehicles - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
    
    <style>
        /* Vehicle Card Styles */
        .vehicle-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: var(--space-5);
            margin-bottom: var(--space-4);
            border: 1px solid var(--border-light);
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }
        
        .vehicle-card::before {
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
        
        .vehicle-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-dark);
        }
        
        .vehicle-card:hover::before {
            opacity: 1;
        }
        
        .vehicle-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
        }
        
        .vehicle-name {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--primary-800);
            margin: 0;
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .vehicle-info {
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
        
        .status-badge.maintenance {
            background-color: rgb(245 158 11 / 0.1);
            color: var(--yellow);
            border: 1px solid rgb(245 158 11 / 0.2);
        }
        
        .status-badge.inactive {
            background-color: rgb(239 68 68 / 0.1);
            color: var(--red);
            border: 1px solid rgb(239 68 68 / 0.2);
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

        /* Vehicle Container Views */
        .vehicles-container {
            transition: all var(--transition-normal);
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-5);
        }
        
        /* Grid View (Default) */
        .vehicles-container.view-grid .vehicle-card {
            width: calc(33.333% - 1.667rem);
            min-width: 320px;
        }
        
        /* List View */
        .vehicles-container.view-list {
            flex-direction: column;
            gap: var(--space-4);
        }
        
        .vehicles-container.view-list .vehicle-card {
            display: flex;
            align-items: center;
            gap: var(--space-6);
            padding: var(--space-4) var(--space-6);
            width: 100%;
        }
        
        .vehicles-container.view-list .vehicle-header {
            min-width: 250px;
            margin-bottom: 0;
        }
        
        .vehicles-container.view-list .vehicle-info {
            flex: 1;
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-8);
        }
        
        .vehicles-container.view-list .action-buttons {
            margin-left: auto;
        }
        
        /* Compact View */
        .vehicles-container.view-compact .vehicle-card {
            padding: var(--space-3) var(--space-4);
            width: calc(25% - 1.875rem);
            min-width: 250px;
        }
        
        .vehicles-container.view-compact .vehicle-name {
            font-size: 0.95rem;
        }
        
        .vehicles-container.view-compact .vehicle-info {
            gap: var(--space-3);
        }
        
        .vehicles-container.view-compact .info-label {
            font-size: 0.65rem;
        }
        
        .vehicles-container.view-compact .info-value {
            font-size: 0.8rem;
        }
        
        .vehicles-container.view-compact .status-badge {
            padding: var(--space-1) var(--space-2);
            font-size: 0.65rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .vehicles-container.view-grid .vehicle-card {
                width: calc(50% - 1.25rem);
            }
            .vehicles-container.view-compact .vehicle-card {
                width: calc(33.333% - 1.667rem);
            }
        }
        
        @media (max-width: 768px) {
            .vehicles-container.view-grid .vehicle-card,
            .vehicles-container.view-compact .vehicle-card {
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
                        <input type="text" placeholder="Search vehicles, drivers, trips..." id="globalSearch">
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
                                <i class="fas fa-truck" style="margin-right: 15px;"></i>
                                Manage Vehicles
                            </h1>
                            <p class="page-subtitle">
                                Manage vehicles and trucks available for dispatch operations
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

                <!-- Add/Edit Vehicle Form -->
                <div class="content-card">
                    <div class="card-header" style="cursor: pointer;" onclick="toggleForm()">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-<?php echo $edit_vehicle ? 'edit' : 'plus-circle'; ?>" style="margin-right: 10px;"></i>
                                    <?php echo $edit_vehicle ? 'Edit Vehicle' : 'Add New Vehicle'; ?>
                                </h5>
                                <p class="card-subtitle">Manage your fleet. Drivers are assigned per dispatch, not tied to vehicles.</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleFormBtn">
                                <i class="fas fa-chevron-up" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="vehicleForm">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($edit_vehicle): ?>
                                <input type="hidden" name="vehicle_id" value="<?php echo $edit_vehicle['id']; ?>">
                            <?php endif; ?>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="vehicle_number" class="form-label">Vehicle Number <span style="color: var(--red); font-weight: 700;">*</span></label>
                                        <input type="text" class="form-control" id="vehicle_number" name="vehicle_number"
                                               value="<?php echo htmlspecialchars($edit_vehicle['vehicle_number'] ?? ''); ?>"
                                               placeholder="e.g., TRK-001" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="vehicle_type" class="form-label">Vehicle Type</label>
                                        <select class="form-select" id="vehicle_type" name="vehicle_type">
                                            <option value="truck" <?php echo ($edit_vehicle['vehicle_type'] ?? '') === 'truck' ? 'selected' : ''; ?>>Truck</option>
                                            <option value="van" <?php echo ($edit_vehicle['vehicle_type'] ?? '') === 'van' ? 'selected' : ''; ?>>Van</option>
                                            <option value="motorcycle" <?php echo ($edit_vehicle['vehicle_type'] ?? '') === 'motorcycle' ? 'selected' : ''; ?>>Motorcycle</option>
                                            <option value="car" <?php echo ($edit_vehicle['vehicle_type'] ?? '') === 'car' ? 'selected' : ''; ?>>Car</option>
                                            <option value="other" <?php echo ($edit_vehicle['vehicle_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="active" <?php echo ($edit_vehicle['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="maintenance" <?php echo ($edit_vehicle['status'] ?? '') === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                            <option value="inactive" <?php echo ($edit_vehicle['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="retired" <?php echo ($edit_vehicle['status'] ?? '') === 'retired' ? 'selected' : ''; ?>>Retired</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="action-buttons">
                                <button type="submit" name="<?php echo $edit_vehicle ? 'update_vehicle' : 'add_vehicle'; ?>" class="btn btn-primary">
                                    <i class="fas fa-<?php echo $edit_vehicle ? 'save' : 'plus'; ?>"></i>
                                    <?php echo $edit_vehicle ? 'Update Vehicle' : 'Add Vehicle'; ?>
                                </button>
                                <?php if ($edit_vehicle): ?>
                                    <a href="vehicles.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Vehicles List -->
                <?php if (count($vehicles) > 0): ?>
                    <div class="vehicles-container view-grid" id="vehiclesContainer">
                        <?php foreach ($vehicles as $vehicle): ?>
                        <div class="vehicle-card">
                            <div class="vehicle-header">
                                <h5 class="vehicle-name">
                                    <i class="fas fa-truck"></i>
                                    <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                </h5>
                                <span class="status-badge status-<?php echo $vehicle['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $vehicle['status'])); ?>
                                </span>
                            </div>
                            <div class="vehicle-info">
                                <div class="info-item">
                                    <div class="info-label">Type</div>
                                    <div class="info-value"><?php echo ucfirst($vehicle['vehicle_type']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Make</div>
                                    <div class="info-value"><?php echo htmlspecialchars($vehicle['make'] ?: 'N/A'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Model</div>
                                    <div class="info-value"><?php echo htmlspecialchars($vehicle['model'] ?: 'N/A'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Dispatch Count</div>
                                    <div class="info-value"><?php echo number_format($vehicle['dispatch_count'] ?? 0); ?> trips</div>
                                </div>
                            </div>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-primary" onclick="editVehicle(<?php echo $vehicle['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($vehicle['dispatch_count'] == 0): ?>
                                    <form method="POST" action="vehicles.php" onsubmit="return confirm('Are you sure you want to delete this vehicle?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_vehicle" value="1">
                                        <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <h6>No Vehicles Found</h6>
                        <p>Add your first vehicle to get started with dispatch tracking.</p>
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

        // Global search functionality
        document.getElementById('globalSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            if (searchTerm.length > 2) {
                // Implement search functionality as needed
                console.log('Searching for:', searchTerm);
            }
        });

        function editVehicle(id) {
            window.location.href = 'vehicles.php?edit=' + id;
        }

        function toggleForm() {
            const form = document.getElementById('vehicleForm');
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
            const vehiclesContainer = document.getElementById('vehiclesContainer');
            
            // Load saved view preference
            const savedView = localStorage.getItem('vehiclesView') || 'grid';
            setView(savedView);
            
            viewToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const view = this.dataset.view;
                    setView(view);
                    localStorage.setItem('vehiclesView', view);
                });
            });
            
            function setView(view) {
                // Remove all view classes
                vehiclesContainer.classList.remove('view-grid', 'view-list', 'view-compact');
                // Add new view class
                vehiclesContainer.classList.add('view-' + view);
                
                // Update button states
                viewToggles.forEach(btn => {
                    btn.classList.remove('active');
                    if (btn.dataset.view === view) {
                        btn.classList.add('active');
                    }
                });
            }

            // Auto-collapse form if not in edit mode
            <?php if (!$edit_vehicle): ?>
            const form = document.getElementById('vehicleForm');
            const icon = document.getElementById('toggleIcon');
            form.style.display = 'none';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
            <?php endif; ?>
        });
    </script>
</body>
</html>


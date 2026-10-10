<?php
/**
 * Edit Dispatch Page
 * 
 * Allows editing of dispatch details before delivery
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Only admin and dispatcher can edit dispatches
if (!canManageDispatches()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized dispatch edit attempt by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        exit('You do not have permission to edit dispatches.');
    }
    header('Location: daily.php');
    exit();
}

$dispatch_id = $_GET['id'] ?? null;
$error = '';
$success = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected dispatch edit: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        $error = 'Your form has expired. Please review the dispatch details and submit again.';
    }
}

if (!$dispatch_id) {
    header('Location: daily.php');
    exit();
}

// Get dispatch details
try {
    $stmt = executeQuery("SELECT d.*, v.vehicle_number, v.vehicle_type, v.make, v.model FROM dispatches d LEFT JOIN vehicles v ON d.vehicle_id = v.id WHERE d.id = ?", [$dispatch_id]);
    $dispatch = $stmt->fetch();
    
    if (!$dispatch) {
        header('Location: daily.php');
        exit();
    }
    if (($dispatch['status'] ?? '') === 'cancelled') {
        header('Location: daily.php');
        exit();
    }
    
    // Get products for selection
    $stmt = executeQuery("SELECT id, name, quantity, unit, selling_price FROM products WHERE is_active = TRUE ORDER BY name");
    $products = $stmt->fetchAll();
    
    // Get dispatch items to calculate expected amount
    $stmt = executeQuery("
        SELECT di.*, p.name as product_name 
        FROM dispatch_items di 
        LEFT JOIN products p ON di.product_id = p.id 
        WHERE di.dispatch_id = ?
    ", [$dispatch_id]);
    $dispatch_items = $stmt->fetchAll();
    
    // Get available vehicles
    $stmt = executeQuery("SELECT id, vehicle_number, vehicle_type, make, model, status FROM vehicles WHERE status = 'active' ORDER BY vehicle_number");
    $vehicles = $stmt->fetchAll();
    
    // Get available drivers
    $stmt = executeQuery("SELECT id, name FROM drivers WHERE status = 'active' ORDER BY name");
    $drivers = $stmt->fetchAll();
    
    // Calculate expected amount from items
    $calculated_expected_amount = 0;
    foreach ($dispatch_items as $item) {
        // Use full unit price (without discount) - discounts are tracked separately for account balancing
        $item_subtotal = $item['quantity'] * $item['unit_price'];
        $calculated_expected_amount += $item_subtotal;
    }
    
} catch (PDOException $e) {
    error_log("Get dispatch error: " . $e->getMessage());
    $error = 'Unable to load dispatch details.';
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    $dispatcher_name = trim($_POST['dispatcher_name'] ?? '');
    $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
    $dispatch_date = $_POST['dispatch_date'] ?? '';
    $destination = trim($_POST['destination'] ?? '');
    $recipient = trim($_POST['recipient'] ?? '');
    $tracking_number = trim($_POST['tracking_number'] ?? '');
    $carrier = trim($_POST['carrier'] ?? '');
    $estimated_delivery = $_POST['estimated_delivery'] ?? '';
    $dispatch_type = $_POST['dispatch_type'] ?? 'sale';
    $notes = trim($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? $dispatch['status'];
    
    if (empty($dispatcher_name)) {
        $error = 'Dispatcher name is required.';
    } elseif (empty($destination)) {
        $error = 'Destination is required.';
    } elseif (empty($recipient)) {
        $error = 'Recipient is required.';
    } else {
        try {
            $shipping_address = $destination . (empty($recipient) ? '' : "\nRecipient: " . $recipient);
            
            $query = "UPDATE dispatches 
                     SET dispatcher_name = ?, vehicle_id = ?, dispatch_date = ?, expected_amount = ?, 
                         shipping_address = ?, tracking_number = ?, carrier = ?, 
                         estimated_delivery = ?, dispatch_type = ?, status = ?, notes = ?
                     WHERE id = ?";
            
            executeQuery($query, [
                $dispatcher_name,
                $vehicle_id ?: null,
                $dispatch_date,
                $calculated_expected_amount, // Use calculated amount
                $shipping_address,
                $tracking_number,
                $carrier,
                !empty($estimated_delivery) ? $estimated_delivery : null,
                $dispatch_type,
                $status,
                $notes,
                $dispatch_id
            ]);
            
            $success = 'Dispatch updated successfully!';
            
            // Reload dispatch data with specific columns
            $stmt = executeQuery("SELECT d.*, v.vehicle_number, v.vehicle_type, v.make, v.model FROM dispatches d LEFT JOIN vehicles v ON d.vehicle_id = v.id WHERE d.id = ?", [$dispatch_id]);
            $dispatch = $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Update dispatch error: " . $e->getMessage());
            $error = 'Unable to update dispatch.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Dispatch - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }

        .container {
            margin-top: 2rem;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .form-card .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1.5rem;
        }

        .form-card .card-header h5 {
            margin: 0;
            font-weight: 700;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
        }

        .btn-action {
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }

        .alert {
            border-radius: 12px;
            border: none;
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
            <div class="container">
                <div class="row">
                    <div class="col-lg-8 offset-lg-2">
                <!-- Back Button -->
                <a href="dispatch_detail.php?id=<?php echo $dispatch_id; ?>" class="btn btn-outline-secondary mb-3">
                    <i class="fas fa-arrow-left me-2"></i>Back to Details
                </a>

                <!-- Alert Messages -->
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Edit Form -->
                <div class="form-card">
                    <div class="card-header">
                        <h5><i class="fas fa-edit me-2"></i>Edit Dispatch #<?php echo htmlspecialchars($dispatch['dispatch_number']); ?></h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <!-- Dispatcher Information -->
                            <div class="form-section">
                                <h6 class="form-section-title">
                                    <i class="fas fa-user me-2"></i>Dispatcher Information
                                </h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Dispatcher Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="dispatcher_name" 
                                               value="<?php echo htmlspecialchars($dispatch['dispatcher_name']); ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Vehicle/Truck</label>
                                        <select class="form-select" name="vehicle_id">
                                            <option value="">Select Vehicle (Optional)</option>
                                            <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?php echo $vehicle['id']; ?>" 
                                                        <?php echo $dispatch['vehicle_id'] == $vehicle['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($vehicle['vehicle_number']); ?> 
                                                    - <?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?>
                                                    (<?php echo htmlspecialchars($vehicle['driver_name'] ?? 'No driver'); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Dispatch Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="dispatch_date" 
                                               value="<?php echo htmlspecialchars($dispatch['dispatch_date']); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <!-- Financial Information -->
                            <div class="form-section">
                                <h6 class="form-section-title">
                                    <i class="fas fa-cash-register me-2"></i>Financial Information
                                </h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Expected Amount (₦)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₦</span>
                                            <input type="number" class="form-control" value="<?php echo number_format($calculated_expected_amount, 2, '.', ''); ?>" 
                                                   step="0.01" min="0" readonly style="background-color: #f8f9fa; font-weight: 600;">
                                        </div>
                                        <small class="text-muted">Auto-calculated from dispatch items</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Delivery Information -->
                            <div class="form-section">
                                <h6 class="form-section-title">
                                    <i class="fas fa-map-marker-alt me-2"></i>Delivery Information
                                </h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Destination <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="destination" 
                                               value="<?php echo htmlspecialchars(explode("\n", $dispatch['shipping_address'])[0]); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Recipient Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="recipient" 
                                               value="<?php echo htmlspecialchars(preg_replace('/Recipient: /', '', end(explode("\n", $dispatch['shipping_address'])))); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="form-section">
                                <h6 class="form-section-title">
                                    <i class="fas fa-sticky-note me-2"></i>Notes
                                </h6>
                                <div class="mb-3">
                                    <textarea class="form-control" name="notes" rows="4"><?php echo htmlspecialchars($dispatch['notes'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex gap-2">
                                <a href="dispatch_detail.php?id=<?php echo $dispatch_id; ?>" class="btn btn-secondary btn-action">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary btn-action">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
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


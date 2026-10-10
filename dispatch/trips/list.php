<?php
/**
 * Dispatch List - Simple View of All Dispatches
 * 
 * View all dispatches recorded by driver and date.
 * Shows products taken and expected amounts.
 */

require_once __DIR__ . '/../../config/session.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';
$voidDispatchCsrfToken = hasRole(['admin']) ? SecurityUtils::generateCSRFToken() : '';

$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_driver = $_GET['driver'] ?? '';

// Force date to be today if no date parameter is provided
if (!isset($_GET['date'])) {
    $filter_date = date('Y-m-d');
}

// Debug: Log the actual date being used
error_log("Dispatch list - Using filter_date: $filter_date, GET date param: " . ($_GET['date'] ?? 'not set'));

// Get trips
$trips = [];
try {
    $sql = "
        SELECT d.*,
               COUNT(di.id) as product_count,
               SUM(di.quantity) as total_units
        FROM dispatches d
        LEFT JOIN dispatch_items di ON d.id = di.dispatch_id
        WHERE DATE(d.dispatch_date) = ?
                    AND COALESCE(d.status, '') <> 'cancelled'
          AND (d.sales_channel IS NULL OR d.sales_channel NOT IN ('shop', 'shop_sales'))
    ";
    $params = [$filter_date];

    if (!empty($filter_driver)) {
        $sql .= " AND d.dispatcher_name = ?";
        $params[] = $filter_driver;
    }

    $sql .= " GROUP BY d.id ORDER BY d.created_at DESC";

    $stmt = executeQuery($sql, $params);
    $trips = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load trips';
}

// Get drivers for filter
$drivers = [];
try {
    $stmt = executeQuery("SELECT DISTINCT dispatcher_name FROM dispatches WHERE COALESCE(status, '') <> 'cancelled' ORDER BY dispatcher_name");
    $drivers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $drivers = [];
}

// Get trip items for a specific trip
function getTripItems($trip_id) {
    try {
        $stmt = executeQuery("
            SELECT di.*, p.name as product_name
            FROM dispatch_items di
            JOIN products p ON di.product_id = p.id
            WHERE di.dispatch_id = ?
        ", [$trip_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// Calculate total discount for a trip
function getTripDiscount($trip_id) {
    try {
        $stmt = executeQuery("
            SELECT COALESCE(SUM(discount_amount), 0) as total_discount
            FROM dispatch_items
            WHERE dispatch_id = ?
        ", [$trip_id]);
        $result = $stmt->fetch();
        return $result['total_discount'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View all dispatches and products taken from depot">
    <meta name="author" content="Merrylay Enterprises">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Dispatch List - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../../dashboard/index.php" class="logo">
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
                        <input type="text" placeholder="Search dispatches, drivers, products..." id="dispatchSearch">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <a href="record.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>New Dispatch
                        </a>
                        
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
                                <li><a class="dropdown-item" href="../../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="../../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
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
                            <h1><i class="fas fa-list me-3"></i>Dispatch List</h1>
                            <p>View all recorded dispatches and products taken from depot</p>
                            <div class="welcome-meta">
                                <div>
                                    <i class="fas fa-calendar-day"></i>
                                    <span><?php echo $filter_date === date('Y-m-d') ? date('l, F j, Y') : date('l, F j, Y', strtotime($filter_date)); ?></span>
                                </div>
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <span id="currentTime"><?php echo date('H:i'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (($_GET['voided'] ?? '') === '1'): ?>
                    <div class="alert alert-success" role="alert">
                        Dispatch voided. Existing payment records were left untouched and require manual review because some legacy payments are not reliably linked to a dispatch.
                    </div>
                <?php endif; ?>

                <!-- Filters Section -->
                <div class="content-card mb-4">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Filter Trips</h5>
                            <p class="card-subtitle">Search and filter trips by date and driver</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Driver</label>
                                <select name="driver" class="form-select">
                                    <option value="">All Drivers</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?php echo htmlspecialchars($driver); ?>" <?php echo $filter_driver === $driver ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($driver); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i>Filter
                                </button>
                                <a href="list.php" class="btn btn-outline-secondary ms-2">Clear</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Stats -->
                <?php
                $total_trips = count($trips);
                $total_expected = array_sum(array_column($trips, 'expected_amount'));
                $total_units = array_sum(array_column($trips, 'total_units'));
                ?>
                <div class="stats-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="stat-value"><?php echo $total_trips; ?></div>
                        <div class="stat-label">Trips <?php echo $filter_date === date('Y-m-d') ? 'Today' : 'on ' . date('M j, Y', strtotime($filter_date)); ?></div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo $filter_date === date('Y-m-d') ? 'Active today' : 'For selected date'; ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($total_units, 2); ?></div>
                        <div class="stat-label">Total Units</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> Products dispatched
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i class="fas fa-naira-sign"></i>
                        </div>
                        <div class="stat-value">₦<?php echo number_format($total_expected, 2); ?></div>
                        <div class="stat-label">Total Expected</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> Revenue expected
                        </div>
                    </div>
                </div>

                <!-- Trips List -->
                <div class="content-card">
                    <div class="card-header">
                        <div>
                            <h5 class="card-title">Trip Details</h5>
                            <p class="card-subtitle">Products taken from depot for each trip</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($trips)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <h6>No trips recorded <?php echo $filter_date === date('Y-m-d') ? 'today' : 'for ' . date('M j, Y', strtotime($filter_date)); ?></h6>
                                <p>Start recording trips to see them here</p>
                                <a href="record.php" class="btn btn-primary">
                                    <i class="fas fa-plus-circle"></i>Record First Trip
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($trips as $trip): 
                                $items = getTripItems($trip['id']);
                                $trip_discount = getTripDiscount($trip['id']);
                            ?>
                                <div class="content-card mb-3" style="margin-left: 0; margin-right: 0;">
                                    <div class="card-header">
                                        <div class="row align-items-center">
                                            <div class="col-md-4">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($trip['dispatch_number']); ?></h6>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="d-flex align-items-center gap-3 text-muted small">
                                                    <div>
                                                        <i class="fas fa-user me-1"></i>
                                                        <?php echo htmlspecialchars($trip['dispatcher_name']); ?>
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-calendar me-1"></i>
                                                        <?php echo date('M j, Y', strtotime($trip['dispatch_date'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <span class="badge badge-info"><?php echo $trip['product_count']; ?> products</span>
                                                <span class="badge badge-primary"><?php echo $trip['total_units']; ?> units</span>
                                                <a href="inventory-receipt.php?id=<?php echo $trip['id']; ?>" class="btn btn-sm btn-outline-success ms-2" title="View Inventory Receipt" target="_blank">
                                                    <i class="fas fa-file-invoice"></i> Receipt
                                                </a>
                                                <?php if (hasRole(['admin', 'manager'])): ?>
                                                <a href="edit.php?id=<?php echo $trip['id']; ?>" class="btn btn-sm btn-outline-primary ms-2" title="Edit Trip">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if (hasRole(['admin'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger ms-2" title="Void Trip" onclick="voidTrip(<?php echo $trip['id']; ?>, '<?php echo htmlspecialchars($trip['dispatch_number']); ?>')">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-clean">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th class="text-center">Quantity</th>
                                                        <th class="text-end">Unit Price</th>
                                                        <th class="text-end">Discount</th>
                                                        <th class="text-end">Subtotal</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($items as $item): 
                                                        // Use full unit price (without discount) - discounts are tracked separately for account balancing
                                                        $subtotal = $item['quantity'] * $item['unit_price'];
                                                    ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                                                            <td class="text-end">₦<?php echo number_format($item['unit_price'], 2); ?></td>
                                                            <td class="text-end" style="color: #ef4444;">-₦<?php echo number_format($item['discount_amount'] ?? 0, 2); ?></td>
                                                            <td class="text-end">₦<?php echo number_format($subtotal, 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot>
                                                    <?php if ($trip_discount > 0): ?>
                                                    <tr>
                                                        <td colspan="3" class="text-end fw-semibold">Total Discount:</td>
                                                        <td class="text-end fw-semibold" style="color: #ef4444;">-₦<?php echo number_format($trip_discount, 2); ?></td>
                                                        <td></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <tr>
                                                        <td colspan="4" class="text-end fw-semibold">Expected Amount:</td>
                                                        <td class="text-end fw-semibold">₦<?php echo number_format($trip['expected_amount'], 2); ?></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                        <?php if (!empty($trip['notes'])): ?>
                                            <div class="mt-3 text-muted">
                                                <small><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($trip['notes']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="mt-4">
                            <a href="record.php" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Record New Dispatch
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/clean-dashboard.js"></script>
    
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
        document.getElementById('tripSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const tripCards = document.querySelectorAll('.content-card.mb-3');
            
            tripCards.forEach(card => {
                const text = card.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
        
        // Void trip with confirmation
        let pendingVoidTripId = null;
        function voidTrip(tripId, tripNumber) {
            pendingVoidTripId = tripId;
            document.getElementById('voidTripNumber').textContent = tripNumber;
            const modalElement = document.getElementById('voidTripModal');
            if (modalElement) {
                const voidTripModal = new bootstrap.Modal(modalElement);
                voidTripModal.show();
            } else {
                const message = `Void trip "${tripNumber}"? Its records will be retained. Legacy payment records will require manual review.`;
                if (confirm(message)) {
                    submitTripVoid(tripId);
                }
            }
        }

        function submitTripVoid(tripId) {
            const voidForm = document.getElementById('voidTripForm');
            if (voidForm) {
                voidForm.elements.id.value = tripId;
                voidForm.requestSubmit();
            }
        }

        function confirmTripVoid() {
            if (!pendingVoidTripId) {
                return;
            }
            submitTripVoid(pendingVoidTripId);
        }
    </script>

    <!-- Trip void confirmation modal -->
    <div class="modal fade" id="voidTripModal" tabindex="-1" aria-labelledby="voidTripModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="voidTripModalLabel">Confirm Trip Void</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Void trip <strong id="voidTripNumber"></strong>?</p>
                    <p>The dispatch and its related records will be retained. Its remaining stock will be restored only when the movement history can be reconciled safely.</p>
                    <ul>
                        <li>Payment records will not be changed.</li>
                        <li>Legacy payments without a reliable dispatch link require manual review.</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmTripVoid()">Void Dispatch</button>
                </div>
            </div>
        </div>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <form id="voidTripForm" method="POST" action="../delete_dispatch.php" hidden>
        <input type="hidden" name="id" value="">
        <input type="hidden" name="from" value="trips">
        <input type="hidden" name="confirm_void" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($voidDispatchCsrfToken); ?>">
    </form>
    <?php endif; ?>
</body>
</html>


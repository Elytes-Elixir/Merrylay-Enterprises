<?php
/**
 * Bulk Update Cost Prices for Products
 * This script helps update cost prices for products that currently have 0.00 cost prices
 */

// Start session
require_once '../init.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if (!hasRole(['admin', 'manager'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized product cost update by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    }
    http_response_code(403);
    exit('You are not authorized to update product cost prices.');
}

// Include database configuration
require_once '../config/db.php';

// Handle form submission
$message = $_SESSION['cost_price_update_message'] ?? '';
unset($_SESSION['cost_price_update_message']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && (isset($_GET['update_cost_prices']) || isset($_GET['cost_prices']))) {
    http_response_code(405);
    header('Allow: POST');
    exit('Cost prices can only be updated by POST.');
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('This request method is not allowed.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updates = $_POST['cost_prices'] ?? null;
    $validatedUpdates = [];

    if (!isset($_POST['update_cost_prices']) || !is_array($updates) || count($updates) > 500) {
        $error = 'The submitted cost price list is invalid.';
    } else {
        foreach ($updates as $product_id => $new_cost_price) {
            $validatedId = is_scalar($product_id)
                ? filter_var((string) $product_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
                : false;
            if ($validatedId === false || !is_string($new_cost_price)) {
                $error = 'The submitted product or cost price is invalid.';
                break;
            }

            $costText = trim($new_cost_price);
            if ($costText === '') {
                continue;
            }
            if (strlen($costText) > 11 || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $costText)) {
                $error = 'Enter a valid non-negative cost price with no more than two decimal places.';
                break;
            }

            $costPrice = (float) $costText;
            if (!is_finite($costPrice) || $costPrice > 99999999.99) {
                $error = 'Cost prices must not exceed 99,999,999.99.';
                break;
            }
            $validatedUpdates[$validatedId] = $costPrice;
        }
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if ($error === '' && (!is_string($csrfToken) || !SecurityUtils::validateCSRFToken($csrfToken))) {
        $error = 'This form has expired. Refresh the page and try again.';
    } elseif ($error === '' && !SecurityUtils::consumeCSRFToken($csrfToken)) {
        $error = 'This form has expired. Refresh the page and try again.';
    }

    if ($error === '') {
        $pdo = null;
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $updated_count = 0;
            foreach ($validatedUpdates as $product_id => $cost_price) {
                if ($cost_price <= 0) {
                    continue;
                }

                $stmt = $pdo->prepare("SELECT cost_price FROM products WHERE id = ? AND is_active = 1 AND cost_price = 0.00 FOR UPDATE");
                $stmt->execute([$product_id]);
                $current = $stmt->fetch();
                if (!$current) {
                    throw new InvalidArgumentException('One or more products are no longer eligible for a cost price update.');
                }

                $update_stmt = $pdo->prepare("UPDATE products SET cost_price = ? WHERE id = ? AND is_active = 1 AND cost_price = 0.00");
                $update_stmt->execute([$cost_price, $product_id]);

                $log_stmt = $pdo->prepare("INSERT INTO price_history (product_id, price_type, old_price, new_price, changed_by, change_reason) VALUES (?, 'cost_price', ?, ?, ?, ?)");
                $log_stmt->execute([$product_id, $current['cost_price'], $cost_price, $_SESSION['user_id'], 'Bulk cost price update']);

                $updated_count++;
            }

            $pdo->commit();
            $_SESSION['cost_price_update_message'] = "Successfully updated cost prices for $updated_count products.";
            header('Location: update_cost_prices.php', true, 303);
            exit();
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Cost price update failed: ' . $e->getMessage());
            $error = 'Unable to update cost prices. Please try again.';
        }
    }
}

$csrfToken = SecurityUtils::generateCSRFToken();

// Get products with zero cost prices
try {
    $stmt = executeQuery("SELECT id, name, cost_price, selling_price FROM products WHERE cost_price = 0.00 AND is_active = 1 ORDER BY name");
    $products = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Cost price product list load failed: ' . $e->getMessage());
    $products = [];
    $error = 'Unable to load products. Please try again.';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Cost Prices - Merrylay Enterprises</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <link href="../assets/css/unified-design-system.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">Update Cost Prices</h1>
                        <p class="text-muted">Update cost prices for products that currently have zero cost prices</p>
                    </div>
                    <a href="view.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Products
                    </a>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (empty($products)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>All products already have cost prices set.
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Products Needing Cost Price Updates (<?php echo count($products); ?> products)</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Product Name</th>
                                                <th>Current Cost Price</th>
                                                <th>Selling Price</th>
                                                <th>New Cost Price</th>
                                                <th>Suggested Margin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($products as $product): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                    <td>₦<?php echo number_format($product['cost_price'], 2); ?></td>
                                                    <td>₦<?php echo number_format($product['selling_price'], 2); ?></td>
                                                    <td>
                                                        <div class="input-group input-group-sm" style="width: 150px;">
                                                            <span class="input-group-text">₦</span>
                                                            <input type="number" class="form-control" name="cost_prices[<?php echo $product['id']; ?>]"
                                                                   step="0.01" min="0" placeholder="0.00"
                                                                   value="<?php
                                                                   // Suggest cost price based on 30% margin
                                                                   if ($product['selling_price'] > 0) {
                                                                       $suggested = $product['selling_price'] * 0.7; // 30% profit margin
                                                                       echo number_format($suggested, 2);
                                                                   }
                                                                   ?>">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($product['selling_price'] > 0): ?>
                                                            <small class="text-muted">
                                                                <?php
                                                                $current_margin = (($product['selling_price'] - $product['cost_price']) / $product['selling_price']) * 100;
                                                                $suggested_cost = $product['selling_price'] * 0.7;
                                                                $suggested_margin = (($product['selling_price'] - $suggested_cost) / $product['selling_price']) * 100;
                                                                echo 'Current: ' . number_format($current_margin, 1) . '% | Suggested: ' . number_format($suggested_margin, 1) . '% (30% margin)';
                                                                ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <small class="text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-4">
                                    <div class="text-muted">
                                        <small>
                                            Suggested cost prices are calculated for a 30% profit margin.<br>
                                            You can modify these values as needed. Leave blank to skip updating a product.
                                        </small>
                                    </div>
                                    <button type="submit" name="update_cost_prices" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Cost Prices
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
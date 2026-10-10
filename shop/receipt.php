<?php
/**
 * Shop Sales Receipt
 * 
 * Generate and print receipts for shop sales
 */

require_once '../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/db.php';
require_once '../config/auth.php';

$sale_id = intval($_GET['id'] ?? 0);

if (!$sale_id) {
    header('Location: index.php');
    exit();
}

// Get sales details
$sale = null;
$items = [];
try {
    // Try to get from shop_sales table first (new system)
    $stmt = executeQuery("
        SELECT ss.*, u.name as attendant_name
        FROM shop_sales ss
        LEFT JOIN users u ON ss.recorded_by = u.id
        WHERE ss.id = ?
    ", [$sale_id]);
    $sale = $stmt->fetch();

    if ($sale) {
        $stmt = executeQuery("
            SELECT ssi.*, p.name as product_name
            FROM shop_sales_items ssi
            LEFT JOIN products p ON ssi.product_id = p.id
            WHERE ssi.shop_sale_id = ?
        ", [$sale_id]);
        $items = $stmt->fetchAll();
    } else {
        // Fallback to dispatches table (old system)
        $stmt = executeQuery("
            SELECT d.*, u.name as attendant_name
            FROM dispatches d
            LEFT JOIN users u ON d.user_id = u.id
            WHERE d.id = ?
        ", [$sale_id]);
        $sale = $stmt->fetch();

        if ($sale) {
            $stmt = executeQuery("
                SELECT di.*, p.name as product_name
                FROM dispatch_items di
                LEFT JOIN products p ON di.product_id = p.id
                WHERE di.dispatch_id = ?
            ", [$sale_id]);
            $items = $stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    error_log("Failed to load sale details: " . $e->getMessage());
    http_response_code(500);
    exit('Unable to load this receipt. Please try again later.');
}

if (!$sale) {
    http_response_code(404);
    exit('Receipt not found or you do not have permission to view it.');
}

$ownsSale = (isset($sale['recorded_by']) && (int) $sale['recorded_by'] === (int) $_SESSION['user_id'])
    || (isset($sale['user_id']) && (int) $sale['user_id'] === (int) $_SESSION['user_id']);
if (!hasRole(['admin', 'manager']) && !hasPermission(PERMISSION_SALES_VIEW) && !$ownsSale) {
    error_log('Unauthorized receipt access by user ' . ($_SESSION['user_id'] ?? 'unknown') . ' for sale ' . $sale_id);
    http_response_code(403);
    exit('You do not have permission to view this receipt.');
}

$sale_discount = floatval($sale['discount_amount'] ?? 0);
$sale_total_amount = floatval($sale['total_amount'] ?? $sale['actual_sales_amount']);
$sale_gross_amount = $sale_total_amount;
$sale_net_amount = max(0, $sale_total_amount - $sale_discount);

// Get payment methods for this sale
$payment_methods = [];
$payment_breakdown_display = [];
try {
    $primary_method = $sale['payment_method'] ?? 'cash';

    if (isset($sale['payment_split']) && $sale['payment_split'] == 1) {
        // This sale has split payments, try to get from shop_sale_payments
        $stmt = executeQuery("
            SELECT payment_method, SUM(amount) as total_amount
            FROM shop_sale_payments
            WHERE shop_sale_id = ?
            GROUP BY payment_method
        ", [$sale_id]);
        $payment_methods = $stmt->fetchAll();

        if (!empty($payment_methods)) {
            // Use the exact amounts recorded in shop_sale_payments (what user entered)
            foreach ($payment_methods as $payment) {
                $payment_breakdown_display[] = [
                    'payment_method' => $payment['payment_method'],
                    'amount' => floatval($payment['total_amount'])
                ];
            }
        } else {
            // No records in shop_sale_payments - use primary method
            $payment_breakdown_display = [['payment_method' => $primary_method, 'amount' => $sale_net_amount]];
        }
    } else {
        // Not marked as split - use primary method
        $payment_breakdown_display = [['payment_method' => $primary_method, 'amount' => $sale_net_amount]];
    }
} catch (PDOException $e) {
    // Fallback to single payment method
    $payment_breakdown_display = [['payment_method' => $sale['payment_method'] ?? 'cash', 'amount' => $sale_net_amount]];
}

// Business details
$company_name = 'Merrylay Enterprises';
$company_phone = '';
$company_address = '';
try {
    $stmt = executeQuery("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_phone', 'company_address', 'company_name')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($settings['company_name'])) {
        $company_name = $settings['company_name'];
    }
    if (!empty($settings['company_phone'])) {
        $company_phone = $settings['company_phone'];
    }
    if (!empty($settings['company_address'])) {
        $company_address = $settings['company_address'];
    }
} catch (PDOException $e) {
    // ignore missing settings
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($sale['sale_number'] ?? $sale['dispatch_number']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .receipt {
            background: white;
            max-width: 300px;
            margin: 0 auto;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            text-align: center;
            border-bottom: 2px dashed #333;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .receipt-header h1 {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .receipt-header p {
            font-size: 10px;
            color: #666;
            margin: 2px 0;
        }
        
        .receipt-info {
            margin-bottom: 15px;
        }
        
        .receipt-info-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
        }
        
        .receipt-info-label {
            color: #666;
            font-weight: bold;
        }
        
        .receipt-items {
            margin-bottom: 15px;
        }
        
        .receipt-item {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            border-bottom: 1px dotted #ddd;
            padding-bottom: 5px;
        }
        
        .receipt-item-name {
            flex: 1;
        }
        
        .receipt-item-qty {
            text-align: center;
            width: 80px;
            white-space: nowrap;
        }
        
        .receipt-item-price {
            text-align: right;
            width: 80px;
        }
        
        .receipt-total {
            border-top: 2px dashed #333;
            padding-top: 10px;
            margin-top: 15px;
        }
        
        .receipt-total-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            font-weight: bold;
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }
        
        .receipt-footer p {
            font-size: 10px;
            color: #666;
            margin: 5px 0;
        }
        
        .print-btn {
            display: block;
            margin: 20px auto;
            padding: 10px 30px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .print-btn:hover {
            background: #0056b3;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .receipt {
                box-shadow: none;
                max-width: 300px;
                width: 300px;
            }
            .print-btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Receipt Header -->
        <div class="receipt-header">
            <h1><?php echo htmlspecialchars($company_name); ?></h1>
            <?php if ($company_phone): ?>
                <p><?php echo htmlspecialchars($company_phone); ?></p>
            <?php endif; ?>
            <?php if ($company_address): ?>
                <p><?php echo htmlspecialchars($company_address); ?></p>
            <?php endif; ?>
            <p>RETAIL RECEIPT</p>
        </div>
        
        <!-- Receipt Info -->
        <div class="receipt-info">
            <div class="receipt-info-row">
                <span class="receipt-info-label">Receipt #:</span>
                <span><?php echo htmlspecialchars($sale['sale_number'] ?? $sale['dispatch_number']); ?></span>
            </div>
            <div class="receipt-info-row">
                <span class="receipt-info-label">Date:</span>
                <span><?php echo date('M d, Y H:i', strtotime($sale['recorded_at'] ?? $sale['created_at'] ?? $sale['sale_date'] ?? $sale['dispatch_date'])); ?></span>
            </div>
            <?php if ($sale['customer_name'] ?? $sale['notes']): ?>
                <div class="receipt-info-row">
                    <span class="receipt-info-label">Customer:</span>
                    <span><?php echo htmlspecialchars($sale['customer_name'] ?? str_replace('Customer: ', '', $sale['notes'])); ?></span>
                </div>
            <?php endif; ?>
            <div class="receipt-info-row">
                <span class="receipt-info-label">Attendant:</span>
                <span><?php echo htmlspecialchars($sale['attendant_name'] ?? 'N/A'); ?></span>
            </div>
        </div>
        
        <!-- Receipt Items -->
        <div class="receipt-items">
            <div class="receipt-item" style="font-weight: bold; border-bottom: 2px solid #333;">
                <span class="receipt-item-name">Item</span>
                <span class="receipt-item-qty">Qty</span>
                <span class="receipt-item-price">Total</span>
            </div>
            <?php foreach ($items as $item): ?>
                <?php
                    $itemSubtotal = floatval($item['quantity']) * floatval($item['unit_price']);
                    $itemDiscount = floatval($item['discount_amount'] ?? 0);
                    $itemTotal = max(0, $itemSubtotal - $itemDiscount);
                    $unitLabel = ['pieces' => 'Pieces', 'crate' => 'Crate'][$item['unit_type'] ?? ''] ?? 'Units';
                ?>
                <div class="receipt-item">
                    <span class="receipt-item-name"><?php echo htmlspecialchars($item['product_name']); ?></span>
                    <span class="receipt-item-qty">
                        <?php echo htmlspecialchars((string) $item['quantity']); ?>
                        <?php echo htmlspecialchars($unitLabel); ?>
                    </span>
                    <span class="receipt-item-price">₦<?php echo number_format($itemTotal, 2); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Receipt Total -->
        <div class="receipt-total">
            <?php if ($sale_discount > 0): ?>
                <div class="receipt-total-row">
                    <span>Gross:</span>
                    <span>₦<?php echo number_format($sale_gross_amount, 2); ?></span>
                </div>
                <div class="receipt-total-row">
                    <span>Discount:</span>
                    <span>-₦<?php echo number_format($sale_discount, 2); ?></span>
                </div>
            <?php endif; ?>
            <div class="receipt-total-row">
                <span>TOTAL:</span>
                <span>₦<?php echo number_format($sale_net_amount, 2); ?></span>
            </div>
        </div>
        
        <!-- Payment Methods -->
        <div class="receipt-total" style="border-top: 1px dashed #333; margin-top: 10px; padding-top: 10px;">
            <div style="margin-bottom: 8px; font-weight: bold; font-size: 11px;">PAYMENT METHOD:</div>
            <?php foreach ($payment_breakdown_display as $payment): ?>
                <div class="receipt-info-row">
                    <span style="text-transform: capitalize; font-weight: bold;">
                        <?php echo htmlspecialchars($payment['payment_method']); ?>:
                    </span>
                    <span>₦<?php echo number_format(floatval($payment['amount']), 2); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Receipt Footer -->
        <div class="receipt-footer">
            <p>Thanks for your Patronage</p>
            <p>Processed by: Merrylay Enterprises</p>
            <?php if ($company_phone): ?>
                <p><?php echo htmlspecialchars($company_phone); ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 20px;">
        <button class="print-btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print Receipt
        </button>
        <a href="index.php" style="display: inline-block; margin-left: 10px; padding: 10px 30px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to Shop
        </a>
    </div>
</body>
</html>

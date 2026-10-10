<?php
/**
 * Inventory Receipt for Trip
 * 
 * Generates a detailed inventory receipt for a trip to be given to the driver.
 * Includes all loaded products with quantities for verification and onward loading.
 * Styled to match shop sales receipts for consistency.
 */

require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/db.php';
require_once '../../config/auth.php';

$trip_id = $_GET['id'] ?? null;

if (!$trip_id) {
    header('Location: list.php');
    exit();
}

// Get trip details
try {
    $stmt = executeQuery("
        SELECT d.*
        FROM dispatches d
        WHERE d.id = ?
    ", [$trip_id]);
    $trip = $stmt->fetch();
    
    if (!$trip) {
        header('Location: list.php');
        exit();
    }
    
    // Get trip items with product details
    $stmt = executeQuery("
        SELECT di.*,
               p.id as product_id,
               p.name as product_name,
               p.unit as product_unit,
               p.sku as product_sku
        FROM dispatch_items di
        LEFT JOIN products p ON di.product_id = p.id
        WHERE di.dispatch_id = ?
        ORDER BY p.name ASC
    ", [$trip_id]);
    $trip_items = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get trip receipt error: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Calculate totals
$total_quantity = 0;
$total_expected_amount = 0;

foreach ($trip_items as $item) {
    $total_quantity += $item['quantity'];
    $total_expected_amount += $item['quantity'] * $item['unit_price'];
}

// Get company settings
$company_name = 'Merrylay Enterprises';
$company_phone = '';
$company_address = '';

try {
    $stmt = executeQuery("SELECT setting_value FROM system_settings WHERE setting_key = ?", ['company_phone']);
    $setting = $stmt->fetch();
    if ($setting) $company_phone = $setting['setting_value'];
} catch (Exception $e) {}

try {
    $stmt = executeQuery("SELECT setting_value FROM system_settings WHERE setting_key = ?", ['company_address']);
    $setting = $stmt->fetch();
    if ($setting) $company_address = $setting['setting_value'];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($trip['dispatch_number']); ?></title>
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
            width: 50px;
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
        
        .back-btn {
            display: inline-block;
            margin-left: 10px;
            padding: 10px 30px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        
        .back-btn:hover {
            background: #545b62;
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
            .print-btn, .back-btn {
                display: none;
            }
            .button-container {
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
            <p>INVENTORY RECEIPT</p>
        </div>
        
        <!-- Receipt Info -->
        <div class="receipt-info">
            <div class="receipt-info-row">
                <span class="receipt-info-label">Trip #:</span>
                <span><?php echo htmlspecialchars($trip['dispatch_number']); ?></span>
            </div>
            <div class="receipt-info-row">
                <span class="receipt-info-label">Date:</span>
                <span><?php echo date('M d, Y H:i', strtotime($trip['dispatch_date'])); ?></span>
            </div>
            <div class="receipt-info-row">
                <span class="receipt-info-label">Driver:</span>
                <span><?php echo htmlspecialchars($trip['dispatcher_name']); ?></span>
            </div>
            <div class="receipt-info-row">
                <span class="receipt-info-label">Expected:</span>
                <span>₦<?php echo number_format($trip['expected_amount'], 2); ?></span>
            </div>
        </div>
        
        <!-- Receipt Items -->
        <div class="receipt-items">
            <div class="receipt-item" style="font-weight: bold; border-bottom: 2px solid #333;">
                <span class="receipt-item-name">Item</span>
                <span class="receipt-item-qty">Qty</span>
                <span class="receipt-item-price">Total</span>
            </div>
            <?php foreach ($trip_items as $item): ?>
                <?php
                    $itemTotal = $item['quantity'] * $item['unit_price'];
                ?>
                <div class="receipt-item">
                    <span class="receipt-item-name"><?php echo htmlspecialchars($item['product_name']); ?></span>
                    <span class="receipt-item-qty"><?php echo $item['quantity']; ?></span>
                    <span class="receipt-item-price">₦<?php echo number_format($itemTotal, 2); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Receipt Total -->
        <div class="receipt-total">
            <div class="receipt-total-row">
                <span>Items:</span>
                <span><?php echo number_format($total_quantity, 0); ?></span>
            </div>
            <div class="receipt-total-row">
                <span>TOTAL:</span>
                <span>₦<?php echo number_format($total_expected_amount, 2); ?></span>
            </div>
        </div>
        
        <!-- Receipt Footer -->
        <div class="receipt-footer">
            <p>Driver: Verify all items loaded</p>
            <p>For: Merrylay Enterprises</p>
            <?php if ($company_phone): ?>
                <p><?php echo htmlspecialchars($company_phone); ?></p>
            <?php endif; ?>
            <p style="margin-top: 10px; font-size: 9px;">Receipt generated on <?php echo date('M d, Y H:i'); ?></p>
        </div>
    </div>
    
    <div class="button-container" style="text-align: center; margin-top: 20px;">
        <button class="print-btn" onclick="window.print()">Print Receipt</button>
        <a href="list.php" class="back-btn">Back to Trips</a>
    </div>
</body>
</html>

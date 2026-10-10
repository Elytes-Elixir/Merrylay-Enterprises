# Inventory Management System Guide

## Overview

The Merrylay System has been restructured to implement a proper inventory management flow with the following components:

1. **Product Registration** - Products and prices are registered into the system
2. **Initial Opening Stock** - Opening stock is entered before beginning to use the system
3. **Supply Management** - Supplies are added to existing opening stock to serve as total opening stock for the day
4. **Daily Stock Flow** - Closing stock = Opening stock + Supplies - Sales
5. **Automatic Carry Forward** - Closing stock automatically becomes opening stock for the next day

---

## Database Structure

### Tables Added/Modified

#### 1. `supplies` Table
Tracks all inbound stock supplies (purchases, transfers, etc.)

**Columns:**
- `id` - Primary key
- `supply_number` - Unique supply identifier (auto-generated)
- `supply_date` - Date of supply
- `supplier_name` - Name of supplier
- `supplier_contact` - Supplier contact information
- `supplier_address` - Supplier address
- `invoice_number` - Invoice reference
- `invoice_date` - Invoice date
- `total_cost` - Total cost of supply
- `payment_method` - Payment method (cash, transfer, credit, cheque)
- `payment_status` - Payment status (paid, partial, pending)
- `notes` - Additional notes
- `created_by` - User who created the supply
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

#### 2. `supply_items` Table
Tracks individual products in each supply

**Columns:**
- `id` - Primary key
- `supply_id` - Reference to supplies table
- `product_id` - Reference to products table
- `quantity` - Quantity supplied
- `unit_cost` - Cost per unit
- `total_cost` - Total cost (calculated: quantity × unit_cost)
- `batch_number` - Batch number for tracking
- `expiry_date` - Expiry date (if applicable)
- `notes` - Additional notes
- `created_at` - Creation timestamp

#### 3. Existing Tables Used
- `products` - Product registration with prices
- `daily_stock` - Daily opening and closing stock tracking
- `stock_movements` - All stock movement history

---

## System Flow

### Step 1: Product Registration
1. Navigate to **Products → Add Product**
2. Enter product details:
   - Product name
   - Category
   - Unit type (Crate, Pack, Bottle, Carton)
   - Cost price
   - Initial quantity (can be set to 0 initially)
3. Save the product

**Location:** `products/add.php`

### Step 2: Initial Opening Stock Setup (One-Time)
1. Navigate to **Stock → Initial Setup**
2. Select the stock date
3. Enter the current physical stock count for each product
4. Click "Save Initial Stock"
5. The system will:
   - Update product quantities
   - Create daily_stock records
   - Record as initial stock movements

**Location:** `stock/initial_setup.php`

**Note:** This is a one-time setup. After initial setup, use the Supplies page to add inbound stock.

### Step 3: Daily Stock Initialization
Each day, you must initialize daily stock before operations:
1. Navigate to **Stock → Stock Management**
2. Click "Initialize Today's Stock"
3. The system will:
   - Carry forward previous day's closing stock as opening stock
   - Create daily_stock records for all active products
   - Include any supplies already recorded for today in the closing stock

**Location:** `stock/manage.php`

### Step 4: Add Supplies (Inbound Stock)
When you receive new stock:
1. Navigate to **Stock → Supplies**
2. Click "Create Supply"
3. Enter the supply date
4. Click "Create Supply"
5. Add items to the supply:
   - Select product from existing products
   - Enter supply quantity
   - Click "Add Item"
6. Repeat for all products in the supply
7. Click "Complete Supply"

**The system will automatically:**
- Update product quantities (current physical stock)
- Record stock movements (type: 'purchase')
- Add supply quantity to daily_stock closing stock for the supply date

**Location:** `stock/supplies.php`

**Note:** Supplies are now simplified - only date, product, and quantity are tracked. Supplier information, costs, and payment details are not recorded.

### Step 5: Record Sales/Dispatches
When products are sold or dispatched:
- Sales are recorded through the sales system
- Dispatches are recorded through the trip/dispatch system
- These automatically deduct from stock via stock_movements

### Step 6: Close Daily Stock
At the end of each day:
1. Navigate to **Stock → Stock Management**
2. Click "Close Today's Stock"
3. The system will:
   - Calculate final closing stock based on all movements
   - Update daily_stock records
   - Prepare for next day's initialization

**Formula:**
```
Closing Stock = Opening Stock + Supplies - Sales - Dispatches + Returns + Adjustments
```

### Step 7: Next Day
- The next day's opening stock = previous day's closing stock
- Repeat from Step 3

---

## Key Features

### 1. Automatic Stock Carry Forward
- The system automatically uses the previous day's closing stock as the next day's opening stock
- No manual calculation required

### 2. Complete Audit Trail
- All stock movements are recorded in `stock_movements` table
- Track who made changes, when, and why
- Reference numbers link movements to sales, supplies, dispatches, etc.

### 3. Real-time Stock Updates
- Product quantities are updated immediately when:
  - Supplies are added
  - Sales are made
  - Dispatches are created
  - Returns are processed
  - Adjustments are made

### 4. Daily Stock Reports
- View detailed daily stock reports showing:
  - Opening stock
  - Sales quantity
  - Dispatch quantity
  - Supply/purchase quantity
  - Other changes
  - Net change
  - Closing stock

### 5. Supply Management
- Track all inbound stock with supplier details
- Record invoice information
- Track payment status
- Monitor supply costs
- Batch and expiry date tracking

---

## Stock Movement Types

The system tracks the following movement types in `stock_movements`:

| Movement Type | Description | Quantity Change |
|--------------|-------------|-----------------|
| `initial` | Initial stock setup | Positive |
| `purchase` | Supply/inbound stock | Positive |
| `sale` | Sales transactions | Negative |
| `dispatch` | Dispatch/trip stock | Negative |
| `return` | Product returns | Positive |
| `adjustment` | Manual adjustments | Positive/Negative |

---

## Pages and Locations

| Page | URL | Description |
|------|-----|-------------|
| Product Management | `products/view.php` | View and manage products |
| Add Product | `products/add.php` | Register new products |
| Stock Management | `stock/manage.php` | Daily stock operations and reports |
| Initial Stock Setup | `stock/initial_setup.php` | One-time initial stock setup |
| Supply Management | `stock/supplies.php` | Record inbound supplies |

---

## Helper Functions

### StockManager Class Methods

```php
// Initialize daily stock for all products
$stockManager->initializeDailyStock($date);

// Record a stock movement
$stockManager->recordMovement($productId, $movementType, $quantityChange, ...);

// Close daily stock (calculate final closing stock)
$stockManager->closeDailyStock($date);

// Check if daily stock is initialized
$stockManager->isDailyStockInitialized($date);

// Get daily stock report
$stockManager->getDailyStockReport($date);

// Get stock movements for a date range
$stockManager->getStockMovements($startDate, $endDate, $productId);
```

### Supply Functions

```php
// Create a new supply record
createSupply($supplyNumber, $supplyDate, $supplierName, ...);

// Add item to supply and update stock
addSupplyItem($supplyId, $productId, $quantity, $unitCost, ...);

// Record supply stock movement
recordSupplyMovement($supplyId, $productId, $quantity, $userId);
```

---

## Best Practices

1. **Always initialize daily stock** before starting operations each day
2. **Close daily stock** at the end of each day to ensure accurate records
3. **Record supplies immediately** when stock arrives
4. **Use batch numbers** for better tracking of product batches
5. **Record expiry dates** for products with shelf life
6. **Review daily stock reports** to identify discrepancies
7. **Perform physical stock counts** regularly and use adjustments if needed

---

## Troubleshooting

### Issue: Daily stock not initialized
**Solution:** Navigate to Stock Management and click "Initialize Today's Stock"

### Issue: Supply not updating stock
**Solution:** Ensure the supply is completed after adding all items

### Issue: Closing stock incorrect
**Solution:** Close daily stock again after all movements are recorded

### Issue: Stock quantity mismatch
**Solution:** Use the "Stock Adjustment" feature to correct discrepancies

---

## Summary

The restructured inventory system provides:

✅ **Product Registration** - Complete product and price management
✅ **Initial Stock Setup** - One-time setup for starting inventory
✅ **Supply Management** - Track all inbound stock with supplier details
✅ **Daily Stock Flow** - Automatic opening/closing stock calculations
✅ **Audit Trail** - Complete history of all stock movements
✅ **Real-time Updates** - Instant stock level updates
✅ **Daily Reports** - Detailed stock movement reports

**Key Formula:**
```
Closing Stock = Opening Stock + Supplies - Sales - Dispatches + Returns + Adjustments
Next Day's Opening Stock = Previous Day's Closing Stock
```

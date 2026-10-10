# Dispatch-to-Sales Flow - Implementation Guide

## Overview
The Dispatch-to-Sales Flow restructures the Merrylay Enterprises to enforce that sales can only be recorded after products have been dispatched to the point of sale. This ensures proper inventory tracking: **Warehouse ? Dispatch ? Point of Sale ? Sale**.

## Business Logic
- **Warehouse**: Products are stored and managed in the warehouse
- **Dispatch**: Products are dispatched to point of sale locations by dispatchers
- **Point of Sale**: Only after dispatch can sales be confirmed
- **Sale**: Sales are recorded against dispatched items, not warehouse inventory

## New Workflow

### Traditional Flow (Before)
```
Warehouse ? Direct Sale
```

### New Flow (After)
```
Warehouse ? Dispatch ? Point of Sale ? Sale
```

## Installation Steps

### 1. Run the Migration
Execute the PHP migration script to update the database:

```
http://localhost/merrylay_system/migrate_dispatch_sales_flow.php
```

This will:
- Add columns to track sold quantities in dispatch_items
- Add sales_status tracking to dispatches
- Create dispatch_sales_tracking table
- Add dispatch reference to sales table
- Create views and stored procedures
- Set up triggers for automatic updates

### 2. Verify Migration
After running the migration, verify that:
- `dispatch_sales_tracking` table exists
- `dispatch_items` has `quantity_sold` and `quantity_available` columns
- `dispatches` has `sales_status` column
- `sales` table has `dispatch_id` and `dispatch_number` columns

### 3. Update Navigation
Update the sidebar navigation to point to the new dispatch-based sales page:
- Old: `/sales/receipt.php`
- New: `/sales/receipt_dispatch.php`

## Database Changes

### New Tables
- **dispatch_sales_tracking**: Tracks individual sales from dispatch items
  - Links dispatch items to sales
  - Records quantity sold per transaction
  - Tracks who made the sale and when

### Modified Tables
- **dispatch_items**: Added columns
  - `quantity_sold`: Total quantity sold from this dispatch item
  - `quantity_available`: Generated column (quantity - quantity_sold)

- **dispatches**: Added columns
  - `delivered_at`: Timestamp when dispatch was delivered
  - `sales_status`: Tracks sales progress (pending/in_progress/completed/partial)

- **sales**: Added columns
  - `dispatch_id`: Reference to the dispatch
  - `dispatch_number`: Dispatch number for easy reference

### New Views
- **dispatched_stock_available**: Shows all dispatched items available for sale
- Filters by shipped/delivered status
- Shows availability status

### Stored Procedures
- **sp_get_available_dispatched_items()**: Returns items available for sale
- **sp_record_dispatch_sale()**: Records a sale from dispatch with validation

## New Files Created

### Database Files
- `database/dispatch_sales_flow.sql` - SQL migration file
- `migrate_dispatch_sales_flow.php` - PHP migration script

### PHP Files
- `sales/receipt_dispatch.php` - New dispatch-based sales page
- `dispatch/mark_ready_for_sale.php` - Mark dispatch as ready for sale
- `dispatch/record_returns.php` - Record returns from dispatch to depot
- `dispatch/dispatch_balance_report.php` - Balance report for dispatch accounting
- `database/add_return_amount_column.php` - Migration for return amounts

### Documentation
- `DISPATCH_SALES_FLOW_GUIDE.md` - This guide

## How to Use

### Step 1: Create a Dispatch
1. Go to **Dispatch ? Create Dispatch**
2. Fill in dispatcher name, destination, recipient
3. Add products and quantities to dispatch
4. Submit the dispatch
5. Status will be 'pending'

### Step 2: Mark Dispatch as Ready for Sale
When products reach the point of sale:
1. Go to **Dispatch ? Create Dispatch** or view dispatch details
2. Click **Mark Ready for Sale** button
3. Status changes to 'delivered'
4. Items are now available for sale

### Step 3: Record Sales from Dispatch
1. Go to **Sales ? Sales Receipt (Dispatch-Based)**
2. Select a dispatch from the dropdown
   - Only shows dispatched items marked as 'shipped' or 'delivered'
   - Shows available quantities
3. Add customer information
4. Select items and quantities from the dispatched items
5. Enter payment information
6. Complete the sale

### Step 4: Record Returns (Optional)
If products are returned to the depot without being sold:
1. Go to **Dispatch ? Record Returns**
2. Select the dispatcher from the filter
3. Choose the dispatch from the dropdown
4. Select the product to return
5. Enter quantity returned
6. Select condition (Good/Damaged/Expired/Unsaleable)
7. Choose return reason (Not sold, Damaged in transit, etc.)
8. Add notes if needed
9. Submit the return

The system automatically:
- Records the return in `dispatch_returns` table
- Adds items back to warehouse inventory if condition is "Good"
- Updates dispatch status to 'returned' if all items are returned
- Tracks returns per dispatcher for accountability

### Step 5: Track Sales Progress
The system automatically:
- Updates `quantity_sold` in dispatch_items
- Updates `quantity_available` (calculated)
- Updates `sales_status` in dispatches
  - 'pending': No sales recorded
  - 'in_progress': Partial sales
  - 'completed': All items sold
- Records transaction in `dispatch_sales_tracking`

## Returns Workflow

### When Products Are Returned
Dispatchers may return products to the depot without selling them for various reasons:
- Products not sold at point of sale
- Damaged during transit
- Wrong products dispatched
- Excess stock
- Customer refusal
- Quality issues

### Recording Returns
1. Navigate to **Dispatch ? Record Returns**
2. Filter by dispatcher name
3. Select the dispatch from which products are being returned
4. Choose the specific product
5. Enter quantity being returned
6. Select condition:
   - **Good**: Will be restocked to warehouse inventory
   - **Damaged**: Will not be restocked
   - **Expired**: Will not be restocked
   - **Unsaleable**: Will not be restocked
7. Choose return reason from dropdown
8. Add notes for additional context
9. Submit the return

### Automatic Processing
- **Good condition**: Items are added back to warehouse inventory
- **Other conditions**: Items are recorded but not restocked
- Dispatch status updates to 'returned' if all items are returned
- Return is tracked with dispatcher name for accountability
- Complete audit trail of all returns

### Returns Tracking
- View recent returns on the returns page
- Filter returns by dispatcher and date
- See returns summary by condition
- Track total returns per day
- Monitor dispatcher return rates

## Dispatch Balancing & Daily Accounting

### Purpose
The dispatch balance system ensures that:
1. Individual dispatch records are balanced (expected vs actual)
2. Daily accounts are reconciled with returns taken into account
3. Dispatcher accountability is maintained
4. Financial discrepancies are easily identified

### Balance Calculation
When returns are recorded, the system automatically:
- Calculates return amount based on unit price and discount
- Subtracts return amount from dispatch's actual_sales_amount
- Tracks cumulative returns per dispatch
- Updates net balance for daily accounting

**Formulas:**
```
Return Amount = Quantity Returned × (Unit Price - Discount Per Unit)
Adjusted Expected = Expected Amount - Return Amount
Adjusted Actual = Actual Sales Amount - Return Amount
Net Balance = Adjusted Actual - Adjusted Expected
```

### Using the Balance Report
1. Go to **Dispatch ? Balance Report**
2. Select dispatcher and date
3. Review the dispatch balance table showing:
   - Expected amount (what dispatcher was expected to collect)
   - Actual sales amount (what was recorded as sold)
   - Returns (quantity and amount)
   - Adjusted expected and actual (after returns)
   - Net balance (shortage or excess)

### Interpreting Net Balance
- **Positive (+)**: Dispatcher collected more than expected
- **Negative (-)**: Dispatcher collected less than expected (shortage)
- **Zero (0)**: Perfect balance

### Daily Accounting Reconciliation
Use the balance report to:
1. Reconcile dispatcher's daily cash/transfer collections
2. Identify discrepancies that need investigation
3. Calculate total returns to be restocked
4. Adjust expected targets based on return patterns
5. Generate accurate daily financial reports

### Accounting Workflow
1. **Morning**: Dispatcher receives products (dispatch)
2. **During Day**: Sales are recorded from dispatched items
3. **Evening**: Returns are recorded for unsold products
4. **End of Day**: Balance report is generated
5. **Reconciliation**: Net balance is used to balance accounts
6. **Restocking**: Good condition returns are added to warehouse

## Features

### Automatic Inventory Tracking
- Warehouse stock decreases when dispatch is created
- Dispatch stock decreases when sale is recorded
- Complete audit trail from warehouse to sale

### Validation
- Cannot sell more than dispatched quantity
- Cannot record sales without selecting a dispatch
- Only shipped/delivered dispatches are available for sale

### Reporting
- View which dispatches have pending sales
- Track sales progress per dispatch
- Monitor dispatcher performance
- Reconcile expected vs actual sales

### Status Tracking
- Dispatch status: pending ? processing ? shipped ? delivered
- Sales status: pending ? in_progress ? completed
- Real-time availability status per item

## Integration Points

### Existing Pages to Update
1. **Sidebar Navigation**: Update sales link to point to receipt_dispatch.php
2. **Dashboard**: Update to show dispatched items available for sale
3. **Reports**: Add dispatch-based sales reports

### Optional Enhancements
1. Add barcode scanning for dispatched items
2. Implement bulk dispatch status updates
3. Create mobile app for dispatchers
4. Add SMS notifications when dispatch is delivered
5. Implement return tracking from point of sale

## Troubleshooting

### Migration Issues
**Problem**: Migration script fails
- Check database connection
- Verify user has ALTER TABLE privileges
- Check for conflicting triggers or constraints

### No Dispatches Available
**Problem**: No dispatches show in sales dropdown
- Ensure dispatch status is 'shipped' or 'delivered'
- Check dispatch_type is 'sale'
- Verify dispatch has items with quantity_available > 0

### Quantity Validation Errors
**Problem**: "Insufficient dispatched quantity available"
- Check actual quantity in dispatch_items
- Verify no other sales have been recorded
- Check for concurrent sales attempts

### Status Not Updating
**Problem**: Sales status doesn't update after sale
- Verify triggers are working
- Check stored procedures exist
- Review error logs

## Rollback Plan

If you need to revert to the old system:

1. Backup current database
2. Drop new tables:
   ```sql
   DROP TABLE IF EXISTS dispatch_sales_tracking;
   ```
3. Remove new columns:
   ```sql
   ALTER TABLE dispatch_items DROP COLUMN quantity_sold;
   ALTER TABLE dispatch_items DROP COLUMN quantity_available;
   ALTER TABLE dispatches DROP COLUMN delivered_at;
   ALTER TABLE dispatches DROP COLUMN sales_status;
   ALTER TABLE sales DROP COLUMN dispatch_id;
   ALTER TABLE sales DROP COLUMN dispatch_number;
   ```
4. Drop views and stored procedures
5. Restore old sales/receipt.php as the main sales page

## Best Practices

1. **Always mark dispatches as delivered** before recording sales
2. **Regular reconciliation** of dispatched vs sold quantities
3. **Train dispatchers** on the new workflow
4. **Monitor dispatch status** to ensure timely delivery
5. **Review sales status** to identify slow-moving inventory

## Security Considerations

- Sales can only be recorded from authenticated dispatches
- Quantity validation prevents overselling
- Complete audit trail of all transactions
- User tracking for all sales and dispatches

## Performance Notes

- Views are optimized with proper indexes
- Stored procedures reduce query complexity
- Generated columns improve calculation performance
- Indexes on frequently queried columns

## Future Enhancements

Potential improvements:
- Multi-location dispatch tracking
- Automatic status updates based on GPS
- Real-time inventory synchronization
- Predictive analytics for dispatch planning
- Integration with accounting systems for automatic reconciliation


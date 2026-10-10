# Price History System - Setup Guide

## Overview
The Price History System allows you to track all price changes for products in the Merrylay Enterprises. This provides an audit trail for pricing decisions and helps analyze price trends over time.

## Features
- **Automatic Logging**: Price changes are automatically logged when you edit a product
- **Change Reasons**: You can provide a reason for price changes (e.g., supplier price increase, seasonal adjustment)
- **Detailed History**: View complete history of all price changes including who made the change and when
- **Filtering**: Filter price history by price type (cost, selling, crate) and date range
- **Visual Indicators**: Color-coded indicators show price increases (green) and decreases (red)

## Installation Steps

### 1. Run the Database Migration
Execute the SQL migration file to create the price history table and related objects:

```bash
# Using MySQL command line
mysql -u root -p merrylay_system < database/price_history.sql

# Or using phpMyAdmin
# Open phpMyAdmin, select merrylay_system database, import database/price_history.sql
```

### 2. Verify Installation
Check that the price_history table was created successfully:

```sql
SHOW TABLES LIKE 'price_history';
```

## How to Use

### Editing Product Prices
1. Go to **Products ? View Products**
2. Click the **Edit** button on any product
3. Change the cost price, selling price, or crate price
4. **Important**: Fill in the **"Price Change Reason"** field to explain why prices are being changed
5. Click **Update Product**
6. The price change will be automatically logged to the history

### Viewing Price History
1. Go to **Products ? View Products**
2. Click the purple **Price** button (history icon) on any product card
   - OR edit a product and click **View Price History** button
3. You will see:
   - Current product information
   - Complete price change history table
   - Filter options to narrow down results

### Filtering Price History
On the price history page, you can filter by:
- **Price Type**: Cost Price, Selling Price, or Crate Price
- **Date Range**: From and To dates
- Click **Filter** to apply filters
- Click **Clear Filters** to reset

## Database Schema

### price_history Table
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| product_id | INT | Reference to products table |
| price_type | ENUM | 'cost_price', 'selling_price', or 'crate_price' |
| old_price | DECIMAL(10,2) | Previous price value |
| new_price | DECIMAL(10,2) | New price value |
| price_difference | DECIMAL(10,2) | Calculated difference (new - old) |
| percentage_change | DECIMAL(10,2) | Calculated percentage change |
| changed_by | INT | Reference to users table (who made the change) |
| change_reason | TEXT | Explanation for the price change |
| change_date | TIMESTAMP | When the change was made |

## Files Modified/Created

### New Files
- `database/price_history.sql` - Database migration file
- `products/price_history.php` - Price history view page

### Modified Files
- `products/edit.php` - Added price change logging and reason field
- `products/view.php` - Added "View Price History" button

## API/Database Functions

### Stored Procedures
- `sp_get_product_price_history(product_id)` - Get price history for a product
- `sp_add_price_change(...)` - Manually add a price change record

### Views
- `price_history_details` - View with user details for price history

## Troubleshooting

### Price history not recording
1. Ensure the price_history table exists: `SHOW TABLES LIKE 'price_history';`
2. If missing, run the migration file again
3. Check database error logs for any issues

### Can't see price history button
1. Clear your browser cache
2. Ensure you're logged in with appropriate permissions
3. Check that the price_history.php file exists in the products folder

### Price changes not logging automatically
The system uses application-level logging in edit.php. If you need database triggers, uncomment the trigger section in price_history.sql.

## Best Practices

1. **Always provide a reason**: When changing prices, explain why (e.g., "Supplier increased cost by 15%")
2. **Review history regularly**: Check price history to understand pricing trends
3. **Use filters**: Narrow down history to specific date ranges or price types for better analysis
4. **Monitor price changes**: Look for unusual patterns in price changes that might indicate errors

## Security Notes
- Price history includes user tracking (who made changes)
- All price changes are logged with timestamps
- The system requires user authentication to view/edit price history

## Future Enhancements
Possible improvements:
- Export price history to CSV/Excel
- Price change approval workflow
- Automated price change notifications
- Price trend charts and analytics
- Bulk price update with history tracking


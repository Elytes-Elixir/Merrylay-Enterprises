# Dispatch System - Database Setup Instructions

## Before Using the Dispatch System

You need to run the database enhancements to add the new tables and fields. Follow these steps:

### Step 1: Locate the Database Enhancement File

The file is located at: `database/dispatch_enhancements.sql`

### Step 2: Run the SQL Script

**Option A: Using phpMyAdmin (Easiest)**

1. Open phpMyAdmin in your browser:
   - URL: `http://localhost/phpmyadmin`
   - Select the `merrylay_system` database

2. Click "SQL" tab at the top

3. Copy the contents of `database/dispatch_enhancements.sql`

4. Paste into the SQL editor

5. Click "Go" button to execute

**Option B: Using MySQL Command Line**

```bash
mysql -u root -p merrylay_system < "c:\xampp\htdocs\merrylay_system\database\dispatch_enhancements.sql"
```

**Option C: Using Windows CMD**

1. Open Command Prompt and navigate to MySQL bin directory:
```bash
cd c:\xampp\mysql\bin
```

2. Run MySQL command:
```bash
mysql -u root merrylay_system < "c:\xampp\htdocs\merrylay_system\database\dispatch_enhancements.sql"
```

(Press Enter when prompted for password if using default XAMPP setup)

### Step 3: Verify Installation

After running the SQL script, verify the changes were applied:

1. In phpMyAdmin, select the `merrylay_system` database
2. Look for `dispatch_returns` table (should be listed)
3. Check `dispatches` table structure - should have these new columns:
   - `dispatcher_name`
   - `expected_amount`
   - `actual_sales_amount`
   - `balance_amount`
   - `dispatch_date`

## New Database Tables & Fields

### Enhanced `dispatches` Table

**New Columns:**
```sql
dispatcher_name VARCHAR(100) NOT NULL -- Name of the dispatcher
expected_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 -- Expected sales revenue
actual_sales_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 -- Actual sales recorded
balance_amount DECIMAL(10,2) GENERATED -- Auto-calculated: actual - expected
dispatch_date DATE NOT NULL DEFAULT CURDATE() -- Date of dispatch
```

### New `dispatch_returns` Table

```sql
CREATE TABLE dispatch_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_returned INT NOT NULL DEFAULT 0,
    return_reason VARCHAR(255),
    condition ENUM('good', 'damaged', 'expired', 'unsaleable') DEFAULT 'good',
    notes TEXT,
    recorded_by INT NOT NULL,
    return_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
);
```

### New Views Created

1. **daily_dispatch_summary** - Summary of dispatches by date and dispatcher
2. **dispatch_detail_view** - Comprehensive dispatch information with calculated fields

## Troubleshooting

### Issue: "Unknown column 'dispatcher_name'" error

**Solution:**
- Run the database enhancements SQL file again
- Verify in phpMyAdmin that the columns exist

### Issue: "Table 'dispatch_returns' doesn't exist" error

**Solution:**
- Ensure you ran the complete dispatch_enhancements.sql file
- Check that the SQL executed without errors

### Issue: SQL File Won't Upload/Run

**Solution:**
1. Verify file permissions
2. Try copying SQL content and pasting directly in phpMyAdmin
3. Run line by line if individual queries fail

## After Installation

Once the database changes are applied:

1. ✅ The dispatch system pages will work correctly
2. ✅ You can start creating new dispatches
3. ✅ All balance calculations will work
4. ✅ You can record returns and track inventory

## Backup Before Running

It's recommended to backup your database before running enhancement scripts:

**In phpMyAdmin:**
1. Select the `merrylay_system` database
2. Click "Export" tab
3. Click "Go" to download backup

**Save this backup file in a safe location!**

## Questions?

If you encounter issues:
1. Check the browser console for errors
2. Look at server error logs in `logs/` directory (if available)
3. Verify the SQL syntax in the dispatch_enhancements.sql file
4. Ensure database user has proper permissions

---

**Last Updated:** 2024
**Version:** 1.0

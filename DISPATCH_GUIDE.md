# Daily Dispatch Recording System - User Guide

## Overview

The Daily Dispatch Recording System allows your team to efficiently manage daily dispatches, track product movement, record returns, and reconcile sales with expected amounts for comprehensive business accountability.

## Key Features

✅ **Daily Dispatch Summary** - View all dispatches for any given day  
✅ **Expected vs Actual Tracking** - Compare expected sales with actual amounts  
✅ **Return Management** - Record returned products with condition and reason  
✅ **Balance Reconciliation** - Automatic calculation of dispatch balance  
✅ **Quantity Verification** - Track loaded, returned, and net sold quantities  
✅ **Complete Audit Trail** - Full history of all dispatch activities  

## Getting Started

### 1. Creating a New Dispatch

**Path:** `/dispatch/create.php`

**Steps:**
1. Click "New Dispatch" button
2. Fill in required information:
   - **Dispatcher Name** - Name of the person making the dispatch
   - **Dispatch Date** - Date the goods are being loaded
   - **Expected Amount** - Expected sales revenue from this dispatch (₦)
   - **Destination** - Where the goods are going
   - **Recipient Name** - Person receiving the goods
3. Select items to load:
   - Choose product from dropdown
   - Enter quantity
   - Optionally add batch number and expiry date
   - Click "Add Item" for multiple products
4. Optional information:
   - Dispatch Type (Sale, Transfer, Return, Sample)
   - Tracking Number and Carrier
   - Estimated Delivery Date
   - Additional Notes
5. Click "Create Dispatch"

### 2. Viewing Daily Dispatch Records

**Path:** `/dispatch/daily.php`

**Features:**
- **Filter by Date** - Select any date to view dispatches
- **Filter by Dispatcher** - See dispatches from specific dispatcher
- **Summary Dashboard** showing:
  - Total number of dispatches
  - Total expected amount
  - Total actual sales
  - Overall balance
  - Total quantities loaded and returned

**Table Information:**
- Dispatch number and dispatcher name
- Expected vs actual sales amounts
- Balance calculation (Actual - Expected)
- Quantities loaded and returned
- Current status
- Quick action buttons

### 3. Viewing Dispatch Details

**Path:** `/dispatch/dispatch_detail.php?id=[dispatch_id]`

**Shows:**
- Complete dispatch information
- Supervisor name
- Financial summary with balance
- List of all items loaded with details:
  - Product name
  - Quantity
  - Unit type
  - Unit price
  - Batch number
  - Expiry date
- List of returned products:
  - Product name
  - Quantity returned
  - Condition (Good, Damaged, Expired, Unsaleable)
  - Return reason
  - Who recorded the return
  - When it was recorded

**Actions:**
- Update actual sales amount
- Record returns
- Edit dispatch details

### 4. Recording Returns

**Path:** `/dispatch/record_return.php?dispatch_id=[dispatch_id]`

**Steps:**
1. Select the product that was returned
2. Enter quantity returned
3. Select condition:
   - **Good** - Products in good condition
   - **Damaged** - Damaged during dispatch
   - **Expired** - Products past expiry date
   - **Unsaleable** - Cannot be sold for other reasons
4. Enter return reason (optional):
   - e.g., "Customer returned", "Didn't sell", "Quality issue"
5. Add notes if needed
6. Click "Record Return"

**Result:**
- Return is recorded in the system
- Product quantity is automatically restored to inventory
- Return appears in dispatch history

### 5. Editing Dispatch Details

**Path:** `/dispatch/edit_dispatch.php?id=[dispatch_id]`

**Can Edit:**
- Dispatcher name
- Dispatch date
- Expected amount
- Destination and recipient
- Dispatch type, tracking number, carrier
- Estimated delivery date
- Status (Pending, Processing, Shipped, Delivered, Cancelled, Returned)
- Notes

**Restrictions:**
- Cannot edit items already loaded (create new dispatch if needed)
- Status cannot be changed to "Delivered" with pending returns

## Understanding the Balance

### Balance Calculation

```
Balance = Actual Sales Amount - Expected Amount
```

**Positive Balance:** ✅ Dispatcher sold more than expected (good performance)  
**Negative Balance:** ⚠️ Dispatcher sold less than expected (investigate)  
**Zero Balance:** ✓ Dispatcher matched expectations perfectly

### Example

- **Expected Amount:** ₦50,000
- **Actual Sales:** ₦55,000
- **Balance:** +₦5,000 (Overage - positive performance)

---

- **Expected Amount:** ₦50,000
- **Actual Sales:** ₦45,000
- **Balance:** -₦5,000 (Shortfall - needs investigation)

## Workflow Example

### Daily Process

1. **Morning:** Supervisor creates dispatch with dispatcher details and expected amount
   - Loads 100 units of Product A at ₦5,000 expected revenue
   - Loads 50 units of Product B at expected revenue

2. **During Day:** Dispatcher sells products to customers

3. **End of Day:** Dispatcher returns with:
   - 10 units of Product A (not sold)
   - 95 units actually sold
   - ₦5,200 actual sales

4. **Recording:** Supervisor enters dispatch details:
   - Records return of 5 units (some were damaged)
   - Updates actual sales to ₦5,200
   - System shows balance of +₦200

5. **Verification:** View daily summary
   - Shows all dispatches for the day
   - Totals expected vs actual
   - Identifies any discrepancies

## Tips for Accurate Records

### Best Practices

1. **Create dispatch immediately** when loading products
2. **Record expected amount** based on similar past sales or agreed targets
3. **Record returns promptly** at end of day
4. **Include detailed notes** for unusual situations:
   - Market conditions affecting sales
   - Stock issues
   - Delivery delays
5. **Update actual sales** within 24 hours
6. **Mark condition accurately** for returns (helps identify problems)

### Troubleshooting

**Q: Dispatch not appearing in daily view?**
- Check the date filter matches dispatch date
- Verify dispatcher name spelling

**Q: Can't record return - product not in list?**
- Only shows products loaded in that dispatch
- Check if dispatch_id is correct

**Q: Balance doesn't match calculation?**
- Ensure actual sales amount is updated
- Check no additional returns were recorded

## Reports Available

The system tracks:
- Daily dispatch summaries
- Dispatcher performance (balance vs expected)
- Product return patterns and reasons
- Inventory movements
- Sales trends

## Data Security

- All dispatches linked to user who created them
- Returns tracked with user who recorded them
- Complete audit trail with timestamps
- Status tracking for accountability

## Questions?

Refer to the system documentation or contact your system administrator for additional help.

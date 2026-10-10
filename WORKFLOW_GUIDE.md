# Merrylay Enterprises - Complete Workflow Procedure

This document outlines the complete workflow procedures, user roles, and tasks for the Merrylay Enterprises.

## Table of Contents
1. [User Roles and Permissions](#user-roles-and-permissions)
2. [Daily Workflow](#daily-workflow)
3. [Dispatch Workflow](#dispatch-workflow)
4. [Sales Workflow](#sales-workflow)
5. [Returns Workflow](#returns-workflow)
6. [Credit Settlements Workflow](#credit-settlements-workflow)
7. [End of Day Workflow](#end-of-day-workflow)
8. [Inventory Management Workflow](#inventory-management-workflow)
9. [Accounting Workflow](#accounting-workflow)
10. [Reporting Workflow](#reporting-workflow)

---

## User Roles and Permissions

### 1. System Administrator
**Primary Responsibilities:**
- System configuration and maintenance
- User account management
- Database backups and maintenance
- Security management
- System monitoring

**Tasks:**
- Create and manage user accounts
- Assign roles and permissions
- Configure system settings
- Monitor system performance
- Perform database backups
- Troubleshoot system issues
- Update software components
- Manage network configurations

**Access:**
- Full system access
- All configuration panels
- User management
- System logs
- Database tools

---

### 2. Manager
**Primary Responsibilities:**
- Overall business operations oversight
- Financial reporting and analysis
- Staff supervision
- Strategic decision making

**Tasks:**
- Review daily sales reports
- Monitor inventory levels
- Approve large transactions
- Review dispatch performance
- Analyze financial reports
- Manage customer accounts
- Oversee staff performance
- Make strategic business decisions

**Access:**
- Dashboard and reports
- Sales and dispatch records
- Inventory management
- Financial reports
- Staff performance metrics
- Customer management

---

### 3. Dispatcher
**Primary Responsibilities:**
- Product dispatch to sales points
- Recording actual sales amounts
- Collecting customer payments
- Recording returns
- Collecting outstanding payments

**Tasks:**
- Create dispatch records
- Record actual sales amounts
- Record customer payments
- Record product returns
- Record credit settlements
- Manage dispatch inventory
- Report delivery status
- Balance dispatch accounts

**Access:**
- Dispatch creation
- Record sales
- Record returns
- Credit settlements
- Dispatch balance report
- Customer payment recording

---

### 4. Sales Staff
**Primary Responsibilities:**
- Product sales to customers
- Customer service
- Cash handling
- Sales recording

**Tasks:**
- Process sales transactions
- Handle customer inquiries
- Manage customer payments
- Record sales receipts
- Handle returns (if authorized)
- Maintain sales area
- Balance cash register

**Access:**
- Sales receipt creation
- Customer lookup
- Product search
- Sales history
- Current inventory view

---

### 5. Inventory Manager
**Primary Responsibilities:**
- Stock management
- Product catalog maintenance
- Supplier coordination
- Stock level monitoring

**Tasks:**
- Add new products
- Update product information
- Manage stock levels
- Process restock orders
- Monitor low stock alerts
- Update pricing
- Manage product categories
- Generate inventory reports

**Access:**
- Product management
- Stock management
- Restock processing
- Inventory reports
- Price updates
- Category management

---

### 6. Accountant
**Primary Responsibilities:**
- Financial record keeping
- Bank deposits
- Expense tracking
- Financial reporting

**Tasks:**
- Record bank deposits
- Track expenses
- Balance accounts
- Generate financial reports
- Reconcile transactions
- Manage outstanding payments
- Prepare tax documents
- Audit financial records

**Access:**
- Bank deposit recording
- Expense tracking
- Balance sheets
- Profit/loss reports
- Outstanding payment management
- Financial reports

---

## Daily Workflow

### Morning Routine (Before Opening)

**Step 1: System Check**
- [ ] Verify system is online and accessible
- [ ] Check database connectivity
- [ ] Review overnight sync status
- [ ] Check for system updates

**Step 2: Inventory Check**
- [ ] Review current stock levels
- [ ] Check low stock alerts
- [ ] Verify product availability
- [ ] Note any discrepancies

**Step 3: Financial Check**
- [ ] Review previous day's closing balance
- [ ] Check bank deposit status
- [ ] Verify cash on hand
- [ ] Review outstanding payments

### During Business Hours

**Step 1: Dispatch Operations**
- [ ] Create dispatches for sales points
- [ ] Assign dispatchers
- [ ] Track dispatch status
- [ ] Monitor delivery progress

**Step 2: Sales Operations**
- [ ] Process sales transactions
- [ ] Record customer payments
- [ ] Handle returns as needed
- [ ] Monitor sales performance

**Step 3: Inventory Management**
- [ ] Monitor stock levels
- [ ] Process restock orders
- [ ] Update product information
- [ ] Manage product categories

**Step 4: Financial Operations**
- [ ] Record bank deposits
- [ ] Track expenses
- [ ] Manage outstanding payments
- [ ] Reconcile transactions

### Evening Routine (Closing)

**Step 1: End of Day Processing**
- [ ] Run end-of-day report
- [ ] Verify all transactions recorded
- [ ] Balance cash registers
- [ ] Review dispatch balances

**Step 2: Data Backup**
- [ ] Perform database backup
- [ ] Verify backup integrity
- [ ] Store backup securely
- [ ] Document backup status

**Step 3: System Shutdown**
- [ ] Close all open sessions
- [ ] Verify no pending transactions
- [ ] Review system logs
- [ ] Prepare for next day

---

## Dispatch Workflow

### 1. Create Dispatch

**Role:** Dispatcher
**Prerequisites:** 
- User logged in
- Products available in stock
- Dispatcher assigned

**Steps:**
1. Navigate to Dispatch ? Create Dispatch
2. Select dispatcher name
3. Choose dispatch type (sale, transfer, etc.)
4. Enter destination and recipient information
5. Add products to dispatch:
   - Select product
   - Enter quantity
   - Verify unit price
   - Add discount if applicable
6. Enter shipping details:
   - Tracking number (if available)
   - Carrier information
   - Estimated delivery date
7. Add notes if needed
8. Review dispatch summary
9. Submit dispatch record
10. Print dispatch manifest (optional)

**Expected Outcome:**
- Dispatch record created with unique dispatch number
- Product quantities deducted from inventory
- Dispatcher assigned to dispatch
- Dispatch status set to "pending"

**Validation:**
- Product quantity must be available
- Dispatcher must be assigned
- Required fields must be completed

---

### 2. Record Actual Sales

**Role:** Dispatcher
**Prerequisites:**
- Dispatch created
- Products delivered
- Sales completed

**Steps:**
1. Navigate to Dispatch ? Record Sales
2. Select dispatcher
3. Select dispatch from list
4. Enter actual sales amount
5. Record payment method
6. Add notes if needed
7. Submit sales record

**Expected Outcome:**
- Dispatch actual sales amount updated
- Payment recorded
- Dispatch status updated

**Validation:**
- Dispatch must exist
- Sales amount must be positive
- Payment method must be selected

---

### 3. Record Returns

**Role:** Dispatcher
**Prerequisites:**
- Dispatch delivered
- Products returned

**Steps:**
1. Navigate to Dispatch ? Record Returns
2. Select dispatcher
3. Select dispatch from list
4. For each returned product:
   - Select product
   - Enter quantity returned
   - Select return condition
   - Enter return reason
   - Add notes
5. Submit return record

**Expected Outcome:**
- Return record created
- Product quantities updated
- Return amount calculated
- Dispatch balance adjusted

**Validation:**
- Dispatch must exist
- Product must be in dispatch
- Return quantity must be valid

---

### 4. Record Credit Settlements

**Role:** Dispatcher
**Prerequisites:**
- Outstanding customer debt
- Payment collected

**Steps:**
1. Navigate to Dispatch ? Credit Settlements
2. Select dispatcher
3. Select dispatch from list
4. Enter customer information:
   - Customer name
   - Customer ID (if existing)
5. Enter payment details:
   - Amount collected
   - Payment method
   - Reference number
6. Add notes if needed
7. Submit settlement record

**Expected Outcome:**
- Credit settlement recorded
- Dispatch actual sales amount updated
- Customer account updated
- Outstanding payment reduced

**Validation:**
- Dispatch must exist
- Amount must be positive
- Customer information required

---

### 5. Balance Dispatch

**Role:** Dispatcher/Manager
**Prerequisites:**
- Dispatch completed
- All sales recorded
- All returns recorded
- All settlements recorded

**Steps:**
1. Navigate to Dispatch ? Balance Report
2. Select dispatcher
3. Select date
4. Review dispatch balances:
   - Expected amount
   - Actual sales amount
   - Returns amount
   - Credit settlements
   - Net balance
5. Investigate discrepancies
6. Resolve issues
7. Confirm balance

**Expected Outcome:**
- Dispatch balanced
- Discrepancies resolved
- Financial records accurate

**Validation:**
- All transactions recorded
- Balance within acceptable range
- Issues documented

---

## Sales Workflow

### 1. Create Sale

**Role:** Sales Staff
**Prerequisites:**
- User logged in
- Dispatch available (for dispatch-based sales)
- Products in stock

**Steps:**
1. Navigate to Sales ? Sales Receipt
2. Select dispatch (required for dispatch-based sales)
3. Add customer information:
   - Select existing customer or create new
   - Enter customer details
4. Add products to sale:
   - Select product from dispatch
   - Enter quantity
   - Verify price
   - Apply discount if applicable
5. Review sale summary:
   - Subtotal
   - Discount amount
   - Tax amount
   - Total amount
6. Select payment method
7. Enter payment amount
8. Calculate change
9. Submit sale
10. Print receipt

**Expected Outcome:**
- Sale record created with unique receipt number
- Product quantities deducted from dispatch
- Customer account updated
- Payment recorded
- Receipt generated

**Validation:**
- Dispatch must be available
- Product quantity must be available in dispatch
- Payment amount must cover total

---

### 2. Process Return

**Role:** Sales Staff/Manager
**Prerequisites:**
- Original sale exists
- Return authorized
- Product returned

**Steps:**
1. Locate original sale
2. Verify return authorization
3. Inspect returned product
4. Record return details:
   - Product
   - Quantity
   - Condition
   - Reason
5. Calculate refund amount
6. Process refund
7. Update inventory
8. Document return

**Expected Outcome:**
- Return record created
- Inventory updated
- Customer account credited
- Refund processed

**Validation:**
- Original sale must exist
- Return must be authorized
- Product condition verified

---

## Returns Workflow

### 1. Record Product Returns

**Role:** Dispatcher
**Prerequisites:**
- Dispatch delivered
- Products returned from customer

**Steps:**
1. Navigate to Dispatch ? Record Returns
2. Select dispatcher
3. Select dispatch
4. For each returned product:
   - Select product
   - Enter quantity
   - Select condition (good, damaged, expired)
   - Enter return reason
   - Add notes
5. Submit return record

**Expected Outcome:**
- Return record created
- Product quantities updated
- Return amount calculated
- Dispatch balance adjusted

**Validation:**
- Dispatch must exist
- Product must be in dispatch
- Return quantity valid

---

### 2. Process Return to Inventory

**Role:** Inventory Manager
**Prerequisites:**
- Return recorded
- Product condition verified

**Steps:**
1. Review return record
2. Verify product condition
3. Update inventory:
   - Good condition: Add to available stock
   - Damaged: Mark as damaged
   - Expired: Mark for disposal
4. Update product status
5. Document processing

**Expected Outcome:**
- Inventory updated
- Product status updated
- Return processed

**Validation:**
- Return record must exist
- Condition verified
- Inventory updated correctly

---

## Credit Settlements Workflow

### 1. Record Outstanding Payment Collection

**Role:** Dispatcher
**Prerequisites:**
- Outstanding customer debt
- Payment collected

**Steps:**
1. Navigate to Dispatch ? Credit Settlements
2. Select dispatcher
3. Select dispatch
4. Enter customer information
5. Enter payment details:
   - Amount collected
   - Payment method
   - Reference number
6. Add notes
7. Submit settlement

**Expected Outcome:**
- Settlement recorded
- Dispatch balance updated
- Customer account updated
- Outstanding debt reduced

**Validation:**
- Dispatch must exist
- Amount positive
- Customer information required

---

### 2. Reconcile Outstanding Payments

**Role:** Accountant/Manager
**Prerequisites:**
- End of period
- All settlements recorded

**Steps:**
1. Review outstanding payment report
2. Verify all settlements recorded
3. Reconcile with customer accounts
4. Identify discrepancies
5. Resolve issues
6. Update records
7. Generate reconciliation report

**Expected Outcome:**
- Outstanding payments reconciled
- Customer accounts updated
- Discrepancies resolved
- Report generated

**Validation:**
- All settlements recorded
- Accounts balanced
- Issues documented

---

## End of Day Workflow

### 1. Run End of Day Report

**Role:** Manager/Accountant
**Prerequisites:**
- All transactions recorded
- Business day complete

**Steps:**
1. Navigate to Admin ? End of Day
2. Select date
3. Review daily summary:
   - Total dispatches
   - Total sales
   - Total returns
   - Total credit settlements
   - Cash position
   - Bank deposits
   - Outstanding payments
   - Net variance
4. Investigate discrepancies
5. Resolve issues
6. Confirm end of day

**Expected Outcome:**
- End of day report generated
- Daily totals calculated
- Discrepancies identified
- Financial position verified

**Validation:**
- All transactions recorded
- Totals accurate
- Discrepancies resolved

---

### 2. Close Business Day

**Role:** Manager
**Prerequisites:**
- End of day report completed
- All issues resolved

**Steps:**
1. Verify end of day report
2. Confirm all transactions
3. Balance cash registers
4. Secure cash
5. Close systems
6. Lock premises
7. Document closing

**Expected Outcome:**
- Business day closed
- Cash secured
- Systems closed
- Documentation complete

**Validation:**
- End of day confirmed
- Cash balanced
- Systems closed

---

## Inventory Management Workflow

### 1. Add New Product

**Role:** Inventory Manager
**Prerequisites:**
- Product information available
- Manager approval (if required)

**Steps:**
1. Navigate to Products ? Add Product
2. Enter product details:
   - Product name
   - Description
   - Category
   - Unit of measure
   - Cost price
   - Selling price
   - Crate price
   - Initial quantity
   - Reorder level
3. Upload product image (optional)
4. Submit product

**Expected Outcome:**
- Product record created
- Inventory updated
- Product available for sale

**Validation:**
- Required fields completed
- Prices valid
- Quantity valid

---

### 2. Update Product Information

**Role:** Inventory Manager
**Prerequisites:**
- Product exists
- Changes approved

**Steps:**
1. Navigate to Products ? Edit Product
2. Select product
3. Update product information:
   - Name
   - Description
   - Category
   - Prices
   - Reorder level
4. Submit changes
5. Document changes

**Expected Outcome:**
- Product information updated
- Changes logged
- Inventory updated

**Validation:**
- Product exists
- Changes valid
- Permissions granted

---

### 3. Process Restock

**Role:** Inventory Manager
**Prerequisites:**
- Low stock alert
- Supplier order placed
- Stock received

**Steps:**
1. Navigate to Purchases ? Add Restock
2. Select supplier
3. Add products:
   - Select product
   - Enter quantity
   - Enter cost
   - Enter batch number
   - Enter expiry date
4. Review restock summary
5. Submit restock
6. Update inventory

**Expected Outcome:**
- Restock record created
- Inventory updated
- Stock levels increased
- Cost recorded

**Validation:**
- Supplier exists
- Products valid
- Quantities positive

---

### 4. Monitor Stock Levels

**Role:** Inventory Manager
**Prerequisites:**
- System access
- Regular monitoring schedule

**Steps:**
1. Navigate to Stock ? Stock Management
2. Review stock levels:
   - Current quantity
   - Reorder level
   - Low stock alerts
3. Identify products needing restock
4. Generate restock orders
5. Monitor stock movements
6. Generate inventory reports

**Expected Outcome:**
- Stock levels monitored
- Low stock identified
- Restock orders generated
- Reports available

**Validation:**
- Stock data accurate
- Alerts configured
- Reports generated

---

## Accounting Workflow

### 1. Record Bank Deposit

**Role:** Accountant
**Prerequisites:**
- Cash collected
- Bank deposit prepared

**Steps:**
1. Navigate to Dispatch ? Bank Deposits
2. Enter deposit details:
   - Date
   - Amount
   - Bank
   - Account number
   - Reference number
3. Attach deposit slip (optional)
4. Submit deposit

**Expected Outcome:**
- Deposit record created
- Cash position updated
- Bank account updated
- Transaction recorded

**Validation:**
- Amount positive
- Bank information valid
- Reference number unique

---

### 2. Track Expenses

**Role:** Accountant
**Prerequisites:**
- Expense incurred
- Receipt available

**Steps:**
1. Navigate to Accounts ? Expenses
2. Enter expense details:
   - Date
   - Category
   - Amount
   - Description
   - Receipt reference
3. Attach receipt (optional)
4. Submit expense

**Expected Outcome:**
- Expense record created
- Financial records updated
- Budget tracked
- Report generated

**Validation:**
- Amount positive
- Category valid
- Description provided

---

### 3. Generate Financial Reports

**Role:** Accountant/Manager
**Prerequisites:**
- Reporting period complete
- All transactions recorded

**Steps:**
1. Navigate to Accounts ? Reports
2. Select report type:
   - Balance Sheet
   - Profit/Loss Statement
   - Cash Flow Statement
   - Expense Report
3. Select date range
4. Generate report
5. Review report
6. Export/print as needed

**Expected Outcome:**
- Financial report generated
- Data accurate
- Report available for review

**Validation:**
- Date range valid
- All transactions recorded
- Report complete

---

### 4. Reconcile Accounts

**Role:** Accountant
**Prerequisites:**
- End of period
- All transactions recorded

**Steps:**
1. Review bank statements
2. Compare with system records
3. Identify discrepancies
4. Investigate differences
5. Make corrections
6. Document reconciliation
7. Generate reconciliation report

**Expected Outcome:**
- Accounts reconciled
- Discrepancies resolved
- Records accurate
- Report generated

**Validation:**
- All transactions reviewed
- Discrepancies resolved
- Documentation complete

---

## Reporting Workflow

### 1. Generate Daily Report

**Role:** Manager
**Prerequisites:**
- End of day complete
- All transactions recorded

**Steps:**
1. Navigate to Reports ? Daily Report
2. Select date
3. Generate report
4. Review:
   - Sales summary
   - Dispatch summary
   - Returns summary
   - Financial summary
5. Export/print as needed

**Expected Outcome:**
- Daily report generated
- Data accurate
- Report available

**Validation:**
- Date valid
- All transactions recorded
- Report complete

---

### 2. Generate Monthly Report

**Role:** Manager
**Prerequisites:**
- Month complete
- All transactions recorded

**Steps:**
1. Navigate to Reports ? Monthly Report
2. Select month/year
3. Generate report
4. Review:
   - Monthly sales
   - Monthly dispatches
   - Monthly returns
   - Financial performance
   - Staff performance
5. Export/print as needed

**Expected Outcome:**
- Monthly report generated
- Data accurate
- Report available

**Validation:**
- Month valid
- All transactions recorded
- Report complete

---

### 3. Generate Custom Report

**Role:** Manager
**Prerequisites:**
- Report parameters defined

**Steps:**
1. Navigate to Reports ? Custom Report
2. Select report type
3. Set parameters:
   - Date range
   - Filters
   - Grouping
4. Generate report
5. Review results
6. Export/print as needed

**Expected Outcome:**
- Custom report generated
- Data accurate
- Report available

**Validation:**
- Parameters valid
- Data available
- Report complete

---

## Workflow Best Practices

### 1. Data Entry
- Always verify data before submission
- Use dropdowns when available
- Check for duplicates
- Document unusual transactions

### 2. Security
- Log out when not in use
- Use strong passwords
- Protect sensitive information
- Report security issues

### 3. Accuracy
- Double-check calculations
- Verify quantities
- Confirm totals
- Review before submission

### 4. Communication
- Notify team of issues
- Document problems
- Share important updates
- Follow escalation procedures

### 5. Backup
- Perform regular backups
- Test backup integrity
- Store backups securely
- Document backup procedures

---

## Troubleshooting

### Common Issues

**Issue: Cannot create dispatch**
- Solution: Verify product availability
- Solution: Check user permissions
- Solution: Verify dispatcher assigned

**Issue: Sales not recording**
- Solution: Check dispatch availability
- Solution: Verify product quantity
- Solution: Check network connection

**Issue: Balance mismatch**
- Solution: Review all transactions
- Solution: Check for missing records
- Solution: Verify calculations

**Issue: System slow**
- Solution: Check network connection
- Solution: Clear browser cache
- Solution: Contact administrator

---

## Support and Escalation

### Level 1 Support
- Basic troubleshooting
- User guidance
- Common issues resolution

### Level 2 Support
- Advanced troubleshooting
- System configuration
- Data correction

### Level 3 Support
- System administration
- Database issues
- Security incidents

---

## Document Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-04-26 | System | Initial release |

---

## Appendix

### A. Quick Reference Cards
- [Dispatch Quick Reference](#dispatch-workflow)
- [Sales Quick Reference](#sales-workflow)
- [Returns Quick Reference](#returns-workflow)

### B. Forms and Templates
- [Dispatch Form Template](#)
- [Sales Receipt Template](#)
- [Return Form Template](#)

### C. Contact Information
- System Administrator: [Contact]
- IT Support: [Contact]
- Manager: [Contact]

---

**End of Document**


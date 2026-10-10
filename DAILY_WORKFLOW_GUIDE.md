# Merrylay System - Daily Workflow Guide

## Overview
This guide provides step-by-step instructions for daily operations in the Merrylay System, designed for beverage distribution businesses. It covers all user roles and time periods throughout a typical business day.

## Table of Contents
1. [User Roles & Daily Responsibilities](#user-roles--daily-responsibilities)
2. [Morning Routine (Before Opening)](#morning-routine-before-opening)
3. [Daily Operations (Business Hours)](#daily-operations-business-hours)
4. [End of Day Procedures](#end-of-day-procedures)
5. [Troubleshooting & Best Practices](#troubleshooting--best-practices)

---

## User Roles & Daily Responsibilities

### 🏢 System Administrator
**Daily Focus:** System health and user support
- [ ] Check system status and database connectivity
- [ ] Review overnight backups and sync status
- [ ] Monitor system performance
- [ ] Handle user access issues
- [ ] Address system errors or alerts

**Key Pages:** `/admin/index.php`, `/admin/backup.php`

---

### 👨‍💼 Manager
**Daily Focus:** Oversight and financial reporting
- [ ] Review previous day's performance reports
- [ ] Monitor dispatch operations and performance
- [ ] Approve large transactions if needed
- [ ] Handle customer complaints or issues
- [ ] Review inventory levels and reorder needs

**Key Pages:** `/dashboard/index.php`, `/accounts/balance_sheet.php`, `/accounts/profit_loss.php`

---

### 🚚 Dispatcher (Driver/Salesperson)
**Daily Focus:** Product delivery and sales
- [ ] Check assigned dispatches for the day
- [ ] Load products from depot
- [ ] Deliver to customers and record sales
- [ ] Handle returns and exchanges
- [ ] Collect payments (cash, transfer, credit settlements)
- [ ] Record end-of-day activities

**Key Pages:** `/dispatch/daily/record.php`, `/dispatch/trips/index.php`

---

### 💰 Cashier/Accountant
**Daily Focus:** Financial recording and reconciliation
- [ ] Record bank deposits
- [ ] Process customer payments
- [ ] Track expenses
- [ ] Reconcile daily accounts
- [ ] Generate financial reports

**Key Pages:** `/cashier/daily.php`, `/dispatch/bank-deposits.php`, `/dispatch/customer-payments.php`

---

### 📦 Inventory Manager
**Daily Focus:** Stock management and supplies
- [ ] Check stock levels and low-stock alerts
- [ ] Process restock orders
- [ ] Update product information and pricing
- [ ] Manage returned products
- [ ] Generate inventory reports

**Key Pages:** `/stock/manage.php`, `/products/add.php`, `/purchases/add.php`

---

## Morning Routine (Before Opening)

### Step 1: System Check (Administrator/Manager)
**Time:** 30 minutes before opening

**Actions:**
1. **Login to System**
   - Navigate to: `http://localhost/merrylay_system/auth/login.php`
   - Enter credentials and verify dashboard loads

2. **Check System Health**
   - Go to: `/dashboard/index.php`
   - Verify all widgets load correctly
   - Check for any error messages or alerts

3. **Review Overnight Operations**
   - Check backup status: `/admin/backup.php`
   - Review any failed processes
   - Verify data sync completed successfully

### Step 2: Inventory Preparation (Inventory Manager)
**Time:** 45 minutes before opening

**Actions:**
1. **Check Stock Levels**
   - Go to: `/stock/manage.php`
   - Review current inventory
   - Note any low-stock items needing immediate attention

2. **Prepare Products for Dispatch**
   - Check product quality and expiry dates
   - Organize products for easy loading
   - Update any pricing changes

3. **Process Urgent Restocks**
   - Go to: `/purchases/add.php`
   - Record any emergency supplies received
   - Update stock levels immediately

### Step 3: Daily Planning (Manager)
**Time:** 30 minutes before opening

**Actions:**
1. **Review Previous Day's Performance**
   - Go to: `/admin/end-of-day.php`
   - Select yesterday's date
   - Review sales totals, dispatch performance, and issues

2. **Plan Today's Dispatches**
   - Review customer orders and requests
   - Assign dispatchers to routes
   - Set expected sales targets

3. **Staff Briefing**
   - Communicate daily targets to dispatchers
   - Address any issues from previous day
   - Distribute route assignments

---

## Daily Operations (Business Hours)

### 🚐 Dispatch Operations

#### Creating New Dispatches (Manager/Supervisor)
**When:** As needed throughout the day

**Steps:**
1. **Navigate to Dispatch Creation**
   - Go to: `/dispatch/credit/add.php` or `/dispatch/trips/index.php`
   - Click "New Trip (Dispatch)"

2. **Enter Dispatch Details**
   - **Dispatcher Name:** Select from dropdown
   - **Dispatch Date:** Today's date (auto-filled)
   - **Expected Amount:** Target sales revenue (₦)
   - **Destination:** Route or area
   - **Recipient Name:** Contact person

3. **Add Products to Load**
   - Select product from dropdown
   - Enter quantity to load
   - Verify unit price
   - Click "Add Item" for multiple products
   - Review total expected revenue

4. **Additional Information (Optional)**
   - Dispatch Type (Sale, Transfer, Return, Sample)
   - Tracking Number (if applicable)
   - Estimated Delivery Date
   - Special Notes

5. **Create Dispatch**
   - Review all information
   - Click "Create Dispatch"
   - Print dispatch manifest (optional)

#### Dispatcher Daily Activities (End of Shift)
**When:** When dispatcher returns from route

**Steps:**
1. **Navigate to Daily Recording**
   - Go to: `/dispatch/daily/record.php`
   - Select your name from driver dropdown
   - Select the date (defaults to today)
   - Choose your trip from the dispatch list

2. **Record Financial Activities**
   - **Cash Collected:** Enter total cash received
   - **Transfer Amount:** Enter bank transfer amounts
   - **Credit Sales:** Enter credit sales amount
   - **Credit Customer Name:** Customer name for credit sales
   - **Credit Settlements:** Previous debt payments collected
   - **Expenses:** Any expenses incurred during trip
   - **Damaged Products:** Value of damaged goods
   - **Discounts:** Any discounts given

3. **Record Product Returns**
   - For each returned product:
     - Select product from dropdown
     - Enter quantity returned
     - System automatically calculates return value
     - Add notes about return reason if needed

4. **Submit Activities**
   - Review all entered information
   - Click "Record Activity"
   - System will:
     - Update dispatch balances
     - Restore returned products to inventory
     - Create/update customer credit records
     - Generate activity summary

### 💳 Sales Operations

#### Processing Sales (Sales Staff)
**When:** Throughout business hours

**Steps:**
1. **Navigate to Sales Receipt**
   - Go to: `/sales/receipt.php`
   - Select dispatch (required for dispatch-based sales)

2. **Add Customer Information**
   - Select existing customer or create new
   - Enter customer details and contact information

3. **Add Products to Sale**
   - Select product from dispatch inventory
   - Enter quantity sold
   - Verify price (auto-calculated)
   - Apply discount if authorized

4. **Process Payment**
   - Select payment method (Cash, Transfer, Credit)
   - Enter payment amount
   - Calculate change (for cash payments)
   - Generate receipt

5. **Complete Sale**
   - Click "Process Sale"
   - Print receipt for customer
   - Update inventory automatically

### 💰 Financial Operations

#### Recording Bank Deposits (Cashier/Accountant)
**When:** Daily or as deposits are made

**Steps:**
1. **Navigate to Bank Deposits**
   - Go to: `/dispatch/bank-deposits.php`

2. **Enter Deposit Details**
   - **Date:** Deposit date
   - **Amount:** Total deposit amount
   - **Bank:** Bank name
   - **Account Number:** Account details
   - **Reference Number:** Deposit slip reference

3. **Attach Documentation (Optional)**
   - Upload deposit slip image if available

4. **Submit Deposit**
   - Review information
   - Click "Record Deposit"
   - System updates cash position

#### Recording Customer Payments (Cashier)
**When:** As payments are received

**Steps:**
1. **Navigate to Customer Payments**
   - Go to: `/dispatch/customer-payments.php`

2. **Enter Payment Details**
   - **Dispatcher Name:** Who collected payment
   - **Payment Date:** Date received
   - **Payment Method:** Cash, Transfer, etc.
   - **Amount:** Payment amount
   - **Notes:** Payment details/references

3. **Submit Payment**
   - Click "Record Payment"
   - System updates customer accounts

### 📦 Inventory Management

#### Monitoring Stock Levels (Inventory Manager)
**When:** Throughout the day

**Steps:**
1. **Check Current Stock**
   - Go to: `/stock/manage.php`
   - Review all product quantities
   - Note items at or below reorder level

2. **Process Restocks**
   - Go to: `/purchases/add.php`
   - Select supplier
   - Add products received:
     - Product name
     - Quantity received
     - Cost price
     - Batch number
     - Expiry date
   - Submit restock record

3. **Update Product Information**
   - Go to: `/products/edit.php`
   - Select product to update
   - Modify prices, descriptions, or other details
   - Save changes

---

## End of Day Procedures

### Step 1: Data Collection (All Staff)
**Time:** 30 minutes before closing

**Dispatcher Tasks:**
- [ ] Complete daily activity recording
- [ ] Submit all returns and exchanges
- [ ] Record all financial activities
- [ ] Submit any missing documentation

**Cashier Tasks:**
- [ ] Record all bank deposits
- [ ] Process all customer payments
- [ ] Update expense records
- [ ] Prepare cash reconciliation

### Step 2: Daily Reports (Manager)
**Time:** 15-20 minutes

**Generate End of Day Report:**
1. **Navigate to End of Day**
   - Go to: `/admin/end-of-day.php`
   - Select today's date

2. **Review Daily Summary**
   - **Total Dispatches:** Number of trips completed
   - **Total Expected Sales:** Combined targets
   - **Total Actual Sales:** Revenue generated
   - **Total Returns:** Products and value returned
   - **Cash Position:** Cash on hand
   - **Bank Deposits:** Amounts deposited
   - **Outstanding Payments:** Credit balances
   - **Net Variance:** Difference between expected and actual

3. **Investigate Discrepancies**
   - Click on any variance to see details
   - Review dispatcher performance
   - Identify issues needing attention

4. **Confirm End of Day**
   - Resolve all identified issues
   - Click "Confirm End of Day"
   - Generate final report

### Step 3: Financial Reconciliation (Accountant/Cashier)
**Time:** 20-30 minutes

**Steps:**
1. **Balance Cash Registers**
   - Count physical cash
   - Compare with system records
   - Investigate any differences

2. **Reconcile Bank Transactions**
   - Match deposits with bank records
   - Verify transfer amounts
   - Document any discrepancies

3. **Update Financial Records**
   - Go to: `/accounts/balance_sheet.php`
   - Review daily financial position
   - Update any missing entries

4. **Generate Daily Financial Report**
   - Go to: `/accounts/profit_loss.php`
   - Select today's date
   - Review profit/loss for the day
   - Export report if needed

### Step 4: System Backup (Administrator)
**Time:** 10-15 minutes

**Steps:**
1. **Perform Database Backup**
   - Go to: `/admin/backup.php`
   - Click "Create Backup"
   - Wait for backup completion
   - Verify backup integrity

2. **System Maintenance**
   - Clear temporary files
   - Check system logs
   - Address any errors

3. **Security Check**
   - Review user access logs
   - Check for any security alerts
   - Update security settings if needed

### Step 5: Closing Procedures (Manager)
**Time:** 10 minutes

**Steps:**
1. **Final Review**
   - Verify all transactions recorded
   - Confirm all dispatches closed
   - Check no pending activities

2. **Secure Assets**
   - Secure cash in safe
   - Lock premises
   - Set alarms

3. **System Shutdown**
   - Close all user sessions
   - Log out administrative accounts
   - Power down non-essential systems

---

## Troubleshooting & Best Practices

### 🚨 Common Issues & Solutions

#### Issue: Cannot Create Dispatch
**Possible Causes:**
- Product out of stock
- User permissions insufficient
- Required fields missing

**Solutions:**
1. Check stock levels first: `/stock/manage.php`
2. Verify user has dispatcher permissions
3. Ensure all required fields are completed

#### Issue: Balance Doesn't Match Expected
**Possible Causes:**
- Missing sales records
- Incorrect return entries
- Unrecorded expenses

**Solutions:**
1. Review all dispatch activities: `/dispatch/daily/record.php`
2. Check for missing returns or sales
3. Verify expense records are complete

#### Issue: System Running Slow
**Possible Causes:**
- Large database needing optimization
- Network connectivity issues
- Browser cache problems

**Solutions:**
1. Clear browser cache
2. Check network connection
3. Contact administrator for database optimization

#### Issue: Cannot Find Customer in System
**Possible Causes:**
- Customer not yet registered
- Name spelling difference
- Customer marked inactive

**Solutions:**
1. Try different name spellings
2. Create new customer record
3. Check inactive customer list

### ✅ Best Practices

#### For Dispatchers:
- **Record activities immediately** upon return
- **Include detailed notes** for unusual situations
- **Verify product quantities** before leaving depot
- **Take photos** of damaged products for documentation
- **Get customer signatures** for credit sales when possible

#### For Cashiers:
- **Count cash twice** before recording
- **Document all expenses** with receipts
- **Reconcile accounts daily** without fail
- **Report discrepancies immediately**
- **Keep backup records** of important transactions

#### For Managers:
- **Review daily reports** before closing
- **Address issues promptly** to prevent escalation
- **Communicate clearly** with all staff
- **Maintain accurate records** for audit purposes
- **Train staff regularly** on system updates

#### For Inventory Managers:
- **Monitor stock levels** continuously
- **Process returns quickly** to maintain accuracy
- **Update pricing promptly** when changes occur
- **Maintain clean storage** for product quality
- **Track expiry dates** to minimize waste

### 📞 Support & Escalation

#### Level 1 Support (Immediate Issues)
- **Contact:** Store Manager
- **Issues:** Login problems, basic system errors, transaction questions
- **Response Time:** Immediate during business hours

#### Level 2 Support (Technical Issues)
- **Contact:** System Administrator
- **Issues:** System crashes, data corruption, network problems
- **Response Time:** Within 2 hours

#### Level 3 Support (Emergency Issues)
- **Contact:** IT Department/External Support
- **Issues:** Security breaches, major system failures
- **Response Time:** Immediate, 24/7 availability

### 📋 Daily Checklist Templates

#### Dispatcher Daily Checklist
```
☐ Load products from depot
☐ Verify quantities against dispatch manifest
☐ Complete all deliveries
☐ Record all sales (cash, transfer, credit)
☐ Document all returns with reasons
☐ Collect outstanding payments
☐ Record daily expenses
☐ Submit end-of-day activities
☐ Report any issues to supervisor
```

#### Cashier Daily Checklist
```
☐ Count opening cash float
☐ Process all customer payments
☐ Record all bank deposits
☐ Track daily expenses
☐ Reconcile cash at end of day
☐ Generate daily financial report
☐ Secure cash in safe
☐ Report any discrepancies
```

#### Manager Daily Checklist
```
☐ Review previous day's reports
☐ Assign dispatchers and routes
☐ Monitor operations throughout day
☐ Handle customer complaints
☐ Review inventory levels
☐ Generate end-of-day report
☐ Address any discrepancies
☐ Secure premises at closing
```

---

## Quick Reference URLs

| Function | URL | Description |
|----------|-----|-------------|
| **Dashboard** | `/dashboard/index.php` | Main system overview |
| **Login** | `/auth/login.php` | System access |
| **Daily Recording** | `/dispatch/daily/record.php` | Dispatcher activities |
| **Create Dispatch** | `/dispatch/credit/add.php` | New trip creation |
| **Sales Receipt** | `/sales/receipt.php` | Process sales |
| **Bank Deposits** | `/dispatch/bank-deposits.php` | Record deposits |
| **Customer Payments** | `/dispatch/customer-payments.php` | Payment recording |
| **Stock Management** | `/stock/manage.php` | Inventory control |
| **End of Day** | `/admin/end-of-day.php` | Daily reports |
| **User Management** | `/users/manage.php` | User accounts |

---

## Contact Information

### System Support
- **System Administrator:** [Admin Contact]
- **IT Help Desk:** [IT Support Contact]
- **Emergency Support:** [Emergency Contact]

### Business Contacts
- **Store Manager:** [Manager Contact]
- **Accounting Department:** [Accounting Contact]
- **Inventory Manager:** [Inventory Contact]

---

**Document Version:** 1.0  
**Last Updated:** 2026-05-12  
**Next Review Date:** 2026-06-12

---

*This guide should be reviewed monthly and updated as system features change or business processes evolve.*

# Performance Optimization Deployment Checklist

**Date:** May 23, 2026  
**Version:** 2.0  
**Status:** Ready for Deployment

---

## Fresh-Install Bootstrap

This checklist's performance-deployment and maintenance steps below apply only to an existing installation; they are not part of portable packaging or fresh installation. For a fresh installation, copy the project and complete these steps in order. Do not import historical or live dumps, or run repair, reset, migration, or maintenance scripts. The schema and reference seed contain no business records.

- [ ] Copy or clone the project files to the target computer.
- [ ] Configure the local environment with a new database name and credentials.
- [ ] Create an empty database.
- [ ] Import `database/schema.sql`.
- [ ] Import `database/reference_seed.sql` (permission definitions only; safe to rerun).
- [ ] Run `php scripts/create_admin.php --create-admin` from an interactive CLI terminal and create the administrator with a strong password.
- [ ] Log in, complete normal application setup, and create other user accounts.
- [ ] Initialize stock only through the approved stock initialization workflow.

The schema and reference seed include no users, passwords, products, drivers, vehicles, customers, sales, dispatches, creditors, expenses, stock records, or historical business data. Do not use the performance-deployment steps below as a substitute for this fresh-install bootstrap.

---

## Pre-Deployment Checklist

### 1. Backup Database ✅ REQUIRED
```bash
# Create full database backup
php create_full_backup.php

# Verify backup exists
ls -la backups/
```

**Status:** ⏳ Pending (must complete before deployment)

---

### 2. Review Changes ✅ REQUIRED
- [ ] Review `PERFORMANCE_OPTIMIZATION_REPORT.md`
- [ ] Review `SECURITY_VERIFICATION_REPORT.md`
- [ ] Understand all changes being made
- [ ] Confirm rollback plan is understood

**Status:** ⏳ Pending

---

## Deployment Steps

### Step 1: Apply Database Indexes ✅ CRITICAL
```bash
# Run the optimization script
mysql -u root -p merrylay_system < database/performance_optimization_phase1.sql

# Verify indexes were created
mysql -u root -p merrylay_system -e "SHOW INDEX FROM dispatches;"
mysql -u root -p merrylay_system -e "SHOW INDEX FROM daily_activities;"
mysql -u root -p merrylay_system -e "SHOW INDEX FROM shop_sales;"
```

**Expected Output:** 20+ new indexes created  
**Rollback:** See Rollback Section below

**Status:** ⏳ Pending

---

### Step 2: Update Apache Configuration ✅ RECOMMENDED
```bash
# Backup current Apache config
copy C:\xampp\apache\conf\httpd.conf C:\xampp\apache\conf\httpd.conf.backup

# Copy performance settings
copy docker\apache\performance-httpd.conf C:\xampp\apache\conf\extra\httpd-vhosts.conf

# OR add settings directly to httpd.conf
# Manual review required
```

**Status:** ⏳ Pending (optional but recommended)

---

### Step 3: Update PHP Configuration ✅ RECOMMENDED
```bash
# Backup current PHP config
copy C:\xampp\php\php.ini C:\xampp\php\php.ini.backup

# Copy performance settings
copy docker\php\performance-php.ini C:\xampp\php\php.ini
```

**Status:** ⏳ Pending (optional but recommended)

---

### Step 4: Update MySQL Configuration ✅ RECOMMENDED
```bash
# Backup current MySQL config
copy C:\xampp\mysql\bin\my.ini C:\xampp\mysql\bin\my.ini.backup

# Copy performance settings
copy docker\mysql\performance-my.cnf C:\xampp\mysql\bin\my.ini
```

**Status:** ⏳ Pending (optional but recommended)

---

### Step 5: Restart Services ✅ REQUIRED
```bash
# Stop Apache
# Stop MySQL
# Stop PHP-FPM (if using)

# Start Apache
# Start MySQL
# Start PHP-FPM (if using)
```

**Status:** ⏳ Pending

---

### Step 6: Verify Deployment ✅ REQUIRED

#### Database Verification
```bash
# Check indexes
mysql -u root -p merrylay_system -e "SHOW INDEX FROM dispatches WHERE Key_name LIKE 'idx_%';"
mysql -u root -p merrylay_system -e "SHOW INDEX FROM daily_activities WHERE Key_name LIKE 'idx_%';"

# Expected: Multiple new indexes starting with idx_
```

#### Application Verification
- [ ] Dashboard loads without errors
- [ ] Dashboard load time < 3 seconds
- [ ] Reports page loads without errors
- [ ] Reports page load time < 4 seconds
- [ ] Dispatch accounting page loads without errors
- [ ] Stock statistics update every 30 seconds (not 10)
- [ ] No JavaScript errors in browser console
- [ ] No PHP errors in error logs

#### Performance Verification
- [ ] Dashboard loads in 2-3 seconds (was 5-8 seconds)
- [ ] Reports load in 3-4 seconds (was 8-12 seconds)
- [ ] AJAX polling every 30 seconds (was 10 seconds)
- [ ] CSS loads asynchronously (check network tab)
- [ ] JavaScript loads with defer (check network tab)

**Status:** ⏳ Pending

---

## Rollback Plan

### If Database Issues Occur
```bash
# Drop all new indexes
mysql -u root -p merrylay_system < database/rollback_indexes.sql

# OR manually drop indexes
mysql -u root -p merrylay_system -e "DROP INDEX idx_dispatches_date_range ON dispatches;"
# Repeat for all idx_ indexes
```

### If Configuration Issues Occur
```bash
# Restore Apache config
copy C:\xampp\apache\conf\httpd.conf.backup C:\xampp\apache\conf\httpd.conf

# Restore PHP config
copy C:\xampp\php\php.ini.backup C:\xampp\php\php.ini

# Restore MySQL config
copy C:\xampp\mysql\bin\my.ini.backup C:\xampp\mysql\bin\my.ini

# Restart services
```

### If Code Issues Occur
```bash
# Use git to revert code changes
git checkout HEAD~1 -- dashboard/index.php
git checkout HEAD~1 -- reports/daily.php
git checkout HEAD~1 -- dispatch/daily-accounting.php
git checkout HEAD~1 -- components/header.php
git checkout HEAD~1 -- components/footer.php
```

---

## Post-Deployment Monitoring

### Week 1 Monitoring Tasks
- [ ] Monitor dashboard load times daily
- [ ] Monitor reports page load times daily
- [ ] Check slow query log: `C:\xampp\mysql\data\slow-query.log`
- [ ] Check PHP error log: `C:\xampp\php\logs\php_error.log`
- [ ] Check Apache error log: `C:\xampp\apache\logs\error.log`
- [ ] Monitor server CPU usage during peak hours
- [ ] Test with 5+ concurrent users
- [ ] Verify multi-user performance

### Performance Targets
- Dashboard load time: < 3 seconds ✅
- Reports load time: < 4 seconds ✅
- Database queries: < 100ms for indexed queries ✅
- AJAX polling: Every 30 seconds ✅
- Concurrent users: 5-10 users ✅

### If Performance Targets Not Met
1. Review slow query log
2. Check if indexes are being used (EXPLAIN queries)
3. Verify caching is working
4. Check server resources (CPU, RAM, disk I/O)
5. Consider additional optimizations from Phase 6-8

---

## Known Issues & Limitations

### Current Limitations
1. **44 files still use DATE() function** - These were not optimized in Phase 1
   - Impact: Medium (these are less frequently accessed files)
   - Fix: Can be optimized in future phase
   - Priority: Low

2. **44 files still use SELECT *** - These were not optimized in Phase 1
   - Impact: Low (these are less frequently accessed files)
   - Fix: Can be optimized in future phase
   - Priority: Low

3. **No Redis caching** - Still using file-based caching
   - Impact: Low for current user base (5-10 users)
   - Fix: Can implement Redis if scaling beyond 10 users
   - Priority: Low

4. **No service layer** - Database operations still inline
   - Impact: Low (code maintainability, not performance)
   - Fix: Can implement in future refactoring
   - Priority: Low

---

## Support Contacts

### If Issues Occur
1. Check logs first (see Monitoring section above)
2. Review rollback plan
3. Refer to `PERFORMANCE_OPTIMIZATION_REPORT.md`
4. Refer to `SECURITY_VERIFICATION_REPORT.md`

### Emergency Rollback
If critical issues occur:
1. Stop all services
2. Restore database from backup
3. Restore configuration files
4. Revert code changes using git
5. Restart services
6. Verify system is working

---

## Sign-Off

**Deployment Date:** _______________  
**Deployed By:** _______________  
**Database Backup Verified:** ☐  
**Configuration Backups Created:** ☐  
**Rollback Plan Understood:** ☐  
**Post-Deployment Monitoring Scheduled:** ☐  

**Deployment Status:** 
- [ ] Successful
- [ ] Partial Success (document issues)
- [ ] Failed (initiate rollback)

---

**Checklist Version:** 2.0  
**Last Updated:** May 23, 2026

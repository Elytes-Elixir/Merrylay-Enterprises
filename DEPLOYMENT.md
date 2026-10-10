# Merrylay Enterprises - Deployment Guide

## Overview

This guide provides comprehensive instructions for deploying the Merrylay Enterprises Inventory & Sales Management System in production environments with optimized performance, caching, and security.

## Table of Contents

1. [System Requirements](#system-requirements)
2. [Quick Start (Docker)](#quick-start-docker)
3. [Manual Deployment](#manual-deployment)
4. [Performance Optimization](#performance-optimization)
5. [Security Configuration](#security-configuration)
6. [Monitoring & Maintenance](#monitoring--maintenance)
7. [Troubleshooting](#troubleshooting)

## System Requirements

### Minimum Requirements
- **CPU**: 2 cores
- **RAM**: 4GB
- **Storage**: 20GB SSD
- **OS**: Linux (Ubuntu 20.04+, CentOS 8+) or Docker-enabled system

### Recommended Requirements
- **CPU**: 4+ cores
- **RAM**: 8GB+
- **Storage**: 50GB+ SSD
- **Network**: 1Gbps connection

### Software Dependencies
- Docker & Docker Compose (for containerized deployment)
- PHP 8.2+ with extensions: PDO, MySQL, Redis, GD, Zip, BCMath, OPcache
- MySQL 8.0+ or MariaDB 10.6+
- Redis 6.0+ (for caching)
- Nginx 1.18+ (web server)

## Quick Start (Docker)

### 1. Clone the Repository
```bash
git clone https://github.com/your-org/Merrylay Enterprises-system.git
cd Merrylay Enterprises-system
```

### 2. Environment Configuration
```bash
cp .env.example .env
# Edit .env with your configuration
```

### 3. Create and prepare the fresh database with Docker Compose

```bash
# Copy .env.example to .env and set DB_NAME, DB_USER, DB_PASSWORD,
# and MYSQL_ROOT_PASSWORD before starting the database.
docker-compose up -d mysql phpmyadmin
```

On a fresh Docker volume, Compose creates an empty database using `DB_NAME`. In phpMyAdmin at `http://localhost:8080`, select that database and import `database/schema.sql`, followed by `database/reference_seed.sql`. Only those approved files are used in this bootstrap; other SQL files are not auto-imported.

Then start the application services and create the administrator:

```bash
docker-compose up -d
docker-compose exec app php scripts/create_admin.php --create-admin
```

```bash
# View logs
docker-compose logs -f

# Check service status
docker-compose ps
```

### Fresh-install bootstrap order

Copy or clone the project files to the target computer. Do not import historical or live database dumps, and do not run repair, reset, migration, or maintenance scripts as part of a fresh installation.

1. Configure the local environment, including an explicit `DB_NAME` that is not the protected `merrylay_system`.
2. Create an empty database for that name.
3. Import `database/schema.sql` into the empty database.
4. Import `database/reference_seed.sql` into the same database.
5. Run `php scripts/create_admin.php --create-admin` from an interactive terminal and create the administrator.
6. Log in, complete normal application setup, and create other user accounts.
7. Initialize stock through the approved stock initialization workflow.

The schema and reference seed contain no business records. The seed contains only permission definitions and is idempotent.

For Docker deployments, prepare `.env` and import the approved files through phpMyAdmin as described above. Do not create a default account or pass a password on the command line.

## Manual Deployment

### 1. Web Server Setup (Nginx)

Install Nginx and configure for Merrylay Enterprises:

```nginx
# /etc/nginx/sites-available/Merrylay Enterprises
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/Merrylay Enterprises-system;
    index index.php;

    # Include security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;

    # Enable gzip compression
    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript;

    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Static asset caching
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Block sensitive files
    location ~ /\. {
        deny all;
    }
}
```

### 2. PHP Configuration

Optimize PHP-FPM configuration:

```ini
# /etc/php/8.2/fpm/php.ini
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 50M
post_max_size = 50M

# OPcache settings
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=10000
opcache.revalidate_freq=0
```

### 3. Fresh database bootstrap

Configure the local environment first, including an explicit database name other than the protected `merrylay_system`. Create an empty database using that name and configure a database user with access to it. Then import the approved schema and reference seed, in that order:

```sql
CREATE DATABASE your_new_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'merrylay_user'@'localhost' IDENTIFIED BY 'choose-a-strong-database-password';
GRANT ALL PRIVILEGES ON your_new_database.* TO 'merrylay_user'@'localhost';
FLUSH PRIVILEGES;
```

```bash
mysql -u merrylay_user -p your_new_database < database/schema.sql
mysql -u merrylay_user -p your_new_database < database/reference_seed.sql
php scripts/create_admin.php --create-admin
```

The schema and reference seed contain no business records. The idempotent reference seed contains permission definitions only. The administrator command prompts interactively and creates only the requested administrator; it has no default password and must not be run as a web page.

After logging in, complete normal application setup and initialize stock only through the approved stock initialization workflow.

### 4. Redis Configuration

Install and configure Redis:

```bash
# Install Redis
sudo apt-get install redis-server

# Configure Redis
sudo nano /etc/redis/redis.conf
```

Key Redis settings:
```
maxmemory 256mb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000
```

### 5. Application Configuration

Set installation-specific values in the ignored `.env` file (copy `.env.example` first):

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_NAME=merrylay_new_installation
DB_USER=merrylay_user
DB_PASSWORD=replace-with-a-strong-password
APP_BASE_URL=https://your-domain.example/merrylay_system
APP_ENV=production
LOG_PATH=logs/php_errors.log
CACHE_PATH=cache
REDIS_HOST=localhost
REDIS_PORT=6379
```

Alternatively, provide the same values as environment variables or return them from the ignored `config/local.php`. Use the installation's public base URL, including its application subpath. File caching remains the default; configure `CACHE_TYPE=redis` through the environment or `config/local.php` when Redis is available.

## Performance Optimization

### 1. Database Optimization

Create recommended indexes:
```sql
-- Products table indexes
CREATE INDEX idx_products_category ON products(category);
CREATE INDEX idx_products_quantity ON products(quantity);
CREATE INDEX idx_products_name ON products(name);

-- Sales table indexes
CREATE INDEX idx_sales_created_at ON sales(created_at);
CREATE INDEX idx_sales_customer_id ON sales(customer_id);
CREATE INDEX idx_sales_status ON sales(status);

-- Dispatch table indexes
CREATE INDEX idx_dispatches_created_at ON dispatches(created_at);
CREATE INDEX idx_dispatches_status ON dispatches(status);
```

### 2. Caching Strategy

The system implements multi-level caching:

- **APCu/Redis**: Database query results
- **Output Caching**: Full page caching (2-10 minutes)
- **Browser Caching**: Static assets (1 year)

Configure cache settings:
```php
// config/cache.php
define('CACHE_ENABLED', true);
define('CACHE_DEFAULT_TTL', 300); // 5 minutes
define('CACHE_TYPE', 'redis'); // 'apcu', 'redis', or 'file'
```

### 3. Asset Optimization

Minify and combine assets:
```bash
# Install Node.js dependencies
npm install

# Build optimized assets
npm run build
```

### 4. PHP OPcache Configuration

Enable and tune OPcache:
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=10000
opcache.revalidate_freq=0
opcache.validate_timestamps=0
```

## Security Configuration

### 1. File Permissions

Set proper file permissions:
```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/Merrylay Enterprises-system

# Set permissions
sudo chmod -R 755 /var/www/Merrylay Enterprises-system
sudo chmod -R 777 /var/www/Merrylay Enterprises-system/cache
sudo chmod -R 777 /var/www/Merrylay Enterprises-system/storage
sudo chmod -R 777 /var/www/Merrylay Enterprises-system/uploads
```

### 2. SSL/TLS Configuration

Enable HTTPS with Let's Encrypt:
```bash
# Install Certbot
sudo apt-get install certbot python3-certbot-nginx

# Obtain SSL certificate
sudo certbot --nginx -d your-domain.com
```

### 3. Security Headers

Configure security headers in Nginx:
```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "no-referrer-when-downgrade" always;
add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;
```

### 4. Database Security

- Use strong passwords
- Limit database user privileges
- Enable MySQL query logging
- Regular security updates

## Monitoring & Maintenance

### 1. Performance Monitoring

Monitor key metrics:
- Page load times
- Database query performance
- Memory usage
- Cache hit rates

Access performance stats:
```php
// Add to any page for performance stats
$stats = performance_get_stats();
error_log("Page load: {$stats['total_time']}ms, Queries: {$stats['query_count']}");
```

### 2. Log Monitoring

Monitor application logs:
```bash
# Application logs
tail -f storage/logs/app.log

# PHP error logs
tail -f /var/log/php_errors.log

# Nginx logs
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# MySQL slow query log
tail -f /var/log/mysql/slow.log
```

### 3. Database Maintenance

Regular maintenance tasks:
```sql
-- Optimize tables monthly
OPTIMIZE TABLE products, sales, dispatches, customers;

-- Analyze tables weekly
ANALYZE TABLE products, sales, dispatches, customers;

-- Check table status
SHOW TABLE STATUS;
```

### 4. Cache Management

Manage Redis cache:
```bash
# Monitor Redis
redis-cli monitor

# Clear cache if needed
redis-cli FLUSHDB

# Check memory usage
redis-cli info memory
```

### 5. Backup Strategy

Automated backups:
```bash
#!/bin/bash
# backup.sh
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u merrylay_user -p merrylay_system > backups/db_$DATE.sql
tar -czf backups/files_$DATE.tar.gz uploads/ storage/
```

Schedule with cron:
```bash
# Daily at 2 AM
0 2 * * * /path/to/backup.sh
```

## Troubleshooting

### Common Issues

#### 1. Slow Page Loads
- Check slow query log
- Verify cache is working
- Monitor memory usage
- Check OPcache status

#### 2. Database Connection Errors
- Verify database credentials
- Check MySQL service status
- Review connection limits
- Check network connectivity

#### 3. Cache Issues
- Verify Redis is running
- Check cache configuration
- Clear corrupted cache
- Monitor cache hit rates

#### 4. File Permission Errors
- Verify ownership
- Check directory permissions
- Review SELinux/AppArmor settings

### Performance Debugging

Enable performance monitoring:
```php
// config/performance.php
define('PERFORMANCE_MONITORING', true);
```

Check performance stats:
```php
$stats = performance_get_stats();
print_r($stats);
```

### Health Checks

Create health check endpoint:
```php
// health.php
<?php
header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'services' => [
        'database' => testDatabaseConnection(),
        'cache' => testCacheConnection(),
        'storage' => testStorageWrite()
    ]
];

echo json_encode($health);
?>
```

## Scaling Considerations

### Horizontal Scaling
- Use load balancer (Nginx, HAProxy)
- Deploy multiple application instances
- Implement session clustering
- Use Redis for shared cache

### Database Scaling
- Read replicas for read-heavy operations
- Database sharding for large datasets
- Connection pooling optimization

### CDN Integration
- Serve static assets via CDN
- Configure proper cache headers
- Optimize image delivery

## Support

For deployment issues:
1. Check logs for error messages
2. Verify all services are running
3. Test database and cache connections
4. Review configuration files
5. Consult troubleshooting section

## Updates & Maintenance

### Application Updates
1. Backup current version
2. Update code files
3. Run database migrations
4. Clear cache
5. Test functionality

### Security Updates
- Regular system updates
- PHP version updates
- Database security patches
- Dependency vulnerability scans

---

**Note**: This deployment guide assumes a production environment. For development, refer to the development setup documentation.

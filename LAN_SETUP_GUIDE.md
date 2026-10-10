# LAN Setup Guide for Merrylay Enterprises

This guide will help you configure the Merrylay Enterprises for access from other computers on your local area network (LAN).

## Prerequisites
- XAMPP installed on the server computer
- All computers connected to the same network
- The server computer's LAN IP address or hostname

## Step 1: Configure Apache for LAN Access

### 1.1 Find Your Server's IP Address
- Windows: Open Command Prompt and run `ipconfig`
- Look for the server's "IPv4 Address"

### 1.2 Configure Apache Virtual Host
Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
    <Directory "C:/xampp/htdocs">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 1.3 Update Apache Configuration
Edit `C:\xampp\apache\conf\httpd.conf`:
- Uncomment `LoadModule vhost_alias_module modules/mod_vhost_alias.so`
- Uncomment `Include conf/extra/httpd-vhosts.conf`
- Change `Listen 80` to `Listen 0.0.0.0:80`

### 1.4 Restart Apache
- Open XAMPP Control Panel
- Stop Apache
- Start Apache

## Step 2: Configure MySQL for Network Access

### 2.1 Edit MySQL Configuration
Edit `C:\xampp\mysql\bin\my.ini`:

```ini
[mysqld]
bind-address = 0.0.0.0
max_connections = 200
```

### 2.2 Restart MySQL
- Open XAMPP Control Panel
- Stop MySQL
- Start MySQL

### 2.3 Create Remote User (Optional)
If you need direct MySQL access from other computers:

```sql
CREATE USER 'merrylay_user'@'%' IDENTIFIED BY 'your_database_password';
GRANT ALL PRIVILEGES ON merrylay_system.* TO 'merrylay_user'@'%';
FLUSH PRIVILEGES;
```

## Step 3: Configure Windows Firewall

### 3.1 Allow Apache Through Firewall
- Open Windows Firewall with Advanced Security
- Click "Inbound Rules" ? "New Rule"
- Select "Port" ? "TCP" ? "Specific local ports: 80"
- Allow the connection
- Name it "Apache HTTP Server"

### 3.2 Allow MySQL Through Firewall (Optional)
- Same process as above
- Port: 3306
- Name it "MySQL Server"

## Step 4: Configure the Application

Copy `.env.example` to `.env` and set the database connection for the new installation. Use an explicitly created empty database; do not use the protected `merrylay_system` database:

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_NAME=merrylay_new_installation
DB_USER=your_database_user
DB_PASSWORD=your_database_password
APP_BASE_URL=/merrylay_system
```

Follow the fresh-install sequence in `SETUP_GUIDE.md`: import only `database/schema.sql` and `database/reference_seed.sql`. Do not import historical or live dumps or run repair, reset, migration, or maintenance scripts.

The root-relative `APP_BASE_URL` keeps links on the origin used by each browser and preserves the `/merrylay_system` application path. Use an absolute URL there if the application is published at a fixed HTTPS hostname.

## Step 5: Access from Other Computers

### 5.1 From Client Computers
Open a web browser and navigate to:
```
http://<server-ip>/merrylay_system
```

Replace `<server-ip>` with the LAN address or hostname for the server.

### 5.2 Test Connection
- Try accessing the login page
- Test login with valid credentials
- Test basic operations (view products, create dispatch)

## Step 6: Performance Optimizations

### 6.1 Enable PHP OPcache
Edit `C:\xampp\php\php.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
```

### 6.2 Optimize MySQL
Edit `C:\xampp\mysql\bin\my.ini`:
```ini
[mysqld]
innodb_buffer_pool_size=256M
innodb_log_file_size=64M
query_cache_size=32M
query_cache_type=1
```

### 6.3 Enable Gzip Compression
Add to `.htaccess` in project root:
```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>
```

## Step 7: Security Considerations

### 7.1 Use HTTPS (Recommended)
- Install SSL certificate
- Configure Apache for HTTPS
- Set `APP_BASE_URL` to the HTTPS URL, including `/merrylay_system`

### 7.2 Network Isolation
- Consider using a dedicated network for the system
- Implement VLAN if available
- Use firewall rules to restrict access

### 7.3 Regular Backups
- Schedule regular database backups
- Backup configuration files
- Test restore procedures

## Troubleshooting

### Cannot Access from Other Computers
1. Check firewall settings
2. Verify Apache is listening on 0.0.0.0:80
3. Ensure all computers are on the same network
4. Test connectivity with `ping <server-ip>` from a client computer

### Slow Performance
1. Check network bandwidth
2. Optimize database queries
3. Enable caching
4. Consider using wired connections

### Session Issues
1. Check session configuration
2. Verify cookie settings
3. Clear browser cache and cookies
4. Check PHP session path permissions

## Network Performance Tips

1. **Use Wired Connections**: Ethernet is faster and more reliable than Wi-Fi
2. **Optimize Images**: Compress images for faster loading
3. **Minimize External Resources**: Use local copies of CSS/JS libraries
4. **Enable Caching**: Configure browser caching for static assets
5. **Monitor Network**: Use network monitoring tools to identify bottlenecks

## Maintenance

### Regular Tasks
- Monitor server logs for errors
- Check disk space usage
- Review network performance
- Update software components
- Test backup and restore procedures

### Monitoring
- Use XAMPP's built-in monitoring tools
- Check Apache access logs
- Monitor MySQL performance
- Track network bandwidth usage

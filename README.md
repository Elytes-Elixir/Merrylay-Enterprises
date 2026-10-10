# Merrylay Enterprises - Professional Inventory & Sales Management

A modern, professional inventory and sales management system built with PHP, MySQL, and modern web technologies. This system is designed for small to medium businesses looking for a comprehensive solution to manage their inventory, sales, and business operations.

## ?? Features

### Core Functionality
- **Inventory Management**: Complete product tracking with categories, stock levels, and reorder points
- **Sales Management**: Point-of-sale system with receipt generation and customer management
- **Dispatch Management**: Track product dispatches and deliveries
- **Reporting & Analytics**: Comprehensive reports with charts and business insights
- **User Management**: Secure authentication with role-based access control

### Professional Features
- **Modern Dashboard**: Real-time analytics with interactive charts
- **Responsive Design**: Works seamlessly on desktop, tablet, and mobile devices
- **Dark Mode Support**: Automatic dark mode based on system preferences
- **Advanced Search**: Global search across products, sales, and reports
- **Real-time Notifications**: Stock alerts and system notifications
- **Data Export**: Export reports to CSV, PDF, and Excel formats
- **Barcode Support**: Generate and scan barcodes for products
- **Multi-currency Support**: Handle transactions in multiple currencies

### Security & Performance
- **SQL Injection Protection**: Prepared statements and input validation
- **CSRF Protection**: Cross-site request forgery prevention
- **Rate Limiting**: Brute force attack prevention
- **Secure Password Hashing**: Argon2ID algorithm for password storage
- **Session Security**: Secure session management with timeout
- **Performance Optimization**: Efficient queries and caching mechanisms

## ??? Technology Stack

### Backend
- **PHP 8.0+**: Modern PHP with enhanced features
- **MySQL 8.0+**: Reliable database with full-text search
- **PDO**: Database abstraction layer with prepared statements

### Frontend
- **HTML5 & CSS3**: Modern web standards
- **Bootstrap 5**: Responsive UI framework
- **Font Awesome 6**: Professional icon library
- **Chart.js**: Interactive data visualization
- **Custom Design System**: Comprehensive CSS architecture

### Development Tools
- **Composer**: PHP dependency management
- **ESLint**: JavaScript code quality
- **Prettier**: Code formatting
- **Git**: Version control

## ?? System Requirements

### Minimum Requirements
- **PHP**: 8.0 or higher
- **MySQL**: 8.0 or higher (or MariaDB 10.5+)
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **PHP Extensions**: 
  - PDO, PDO_MySQL
  - GD (for image processing)
  - OpenSSL
  - Mbstring
  - JSON
  - Curl

### Recommended Requirements
- **RAM**: 4GB or higher
- **Storage**: 10GB available space
- **PHP Memory Limit**: 256M or higher
- **Upload Max Filesize**: 20M or higher

## Installation

Do not import a historical or live database dump, and do not run repair, reset, migration, or maintenance scripts as part of a fresh installation.

### 1. Copy the project

Copy or clone the project files to the target computer.

### 2. Configure the local environment

Copy `.env.example` to `.env` (or configure the ignored `config/local.php`) and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `APP_BASE_URL`. Set `DB_NAME` to a new database name other than the protected `merrylay_system`.

### 3. Create an empty database

Create the database named by `DB_NAME` with the `utf8mb4` character set. Create and grant a database user as appropriate for the deployment environment.

### 4. Import the approved schema

```bash
mysql -u merrylay_user -p your_new_database < database/schema.sql
```

### 5. Import the reference seed

```bash
mysql -u merrylay_user -p your_new_database < database/reference_seed.sql
```

The schema and reference seed contain no business records. The seed contains only permission definitions and is idempotent; it does not create users, role grants, categories, or configurable organization settings.

### 6. Create the administrator

From the project directory, run this command in an interactive terminal:

```bash
php scripts/create_admin.php --create-admin
```

Enter the administrator's name, username, and a strong password when prompted. There is no default account or password. This command is CLI-only and is never run automatically during normal page loads.

### 7. Log in and create other users

Open the application URL, log in as the administrator, configure organization settings, and create the other user accounts through user management.

### 8. Initialize stock

Initialize stock only through the approved stock initialization workflow.

### Configure the web server

#### Apache (.htaccess)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php/$1 [L]

# Security headers
Header always set X-Frame-Options DENY
Header always set X-Content-Type-Options nosniff
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

#### Nginx
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/Merrylay Enterprises-system;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Security headers
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

## ?? Usage Guide

### Dashboard Overview
The dashboard provides a comprehensive overview of your business:
- **Real-time Statistics**: Today's sales, revenue, and inventory status
- **Interactive Charts**: Sales trends and inventory analytics
- **Recent Activities**: Latest sales, dispatches, and system events
- **Quick Actions**: Easy access to common tasks

### Managing Products
1. **Add Products**: Navigate to Products ? Add Product
2. **View Inventory**: Navigate to Products ? View Products
3. **Search & Filter**: Use the search bar and filters to find products
4. **Stock Management**: Update quantities and set reorder levels

### Processing Sales
1. **New Sale**: Navigate to Sales ? New Sale
2. **Add Products**: Scan or search for products
3. **Customer Info**: Add customer details or select existing customer
4. **Payment**: Choose payment method and complete transaction
5. **Receipt**: Automatic receipt generation and printing options

### Managing Dispatches
1. **Create Dispatch**: Navigate to Dispatch ? Create Dispatch
2. **Select Products**: Choose products to dispatch
3. **Delivery Info**: Add delivery details and tracking information
4. **Track Status**: Monitor dispatch status and delivery updates

### Generating Reports
1. **Sales Reports**: Navigate to Reports ? Sales Reports
2. **Inventory Reports**: Navigate to Reports ? Inventory Reports
3. **Custom Reports**: Create custom reports with specific parameters
4. **Export Options**: Export reports in various formats

## ?? Configuration

### Security Settings
Edit `config/security.php` to configure:
- Password requirements
- Session timeout
- Rate limiting
- CSRF token lifetime

### Email Configuration
Configure email settings in `config/config.php`:
```php
define('SMTP_HOST', 'smtp.yourdomain.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your_email@yourdomain.com');
define('SMTP_PASSWORD', 'your_email_password');
```

### Backup Configuration
Set up automated backups in `config/backup.php`:
```php
define('BACKUP_ENABLED', true);
define('BACKUP_SCHEDULE', 'daily'); // daily, weekly, monthly
define('BACKUP_RETENTION', 30); // days
```

## ?? Customization

### Branding
Update the design system in `assets/css/design-system.css`:
```css
:root {
  --brand-primary: #your-color;
  --brand-secondary: #your-secondary-color;
  --brand-accent: #your-accent-color;
}
```

### Logo & Favicon
Replace the following files:
- `assets/logo.png` - Main logo
- `assets/favicon.ico` - Favicon
- `assets/apple-touch-icon.png` - Apple touch icon

### Custom Modules
Create custom modules in the `modules/` directory:
1. Create module folder
2. Add controller, model, and view files
3. Register module in `config/modules.php`

## ?? Security Best Practices

### Production Deployment
1. **Environment Variables**: Use environment variables for sensitive data
2. **File Permissions**: Restrict file permissions on production
3. **HTTPS**: Enable SSL/TLS encryption
4. **Firewall**: Configure web application firewall
5. **Regular Updates**: Keep PHP and dependencies updated

### Monitoring & Logging
- Monitor application logs in `logs/` directory
- Set up error notifications
- Monitor database performance
- Track user activities

## ?? Performance Optimization

### Database Optimization
- Use indexes for frequently queried columns
- Optimize slow queries
- Regular database maintenance
- Consider read replicas for high traffic

### Caching
- Enable PHP OPcache
- Use Redis for session storage
- Implement application-level caching
- Cache frequently accessed data

### Frontend Optimization
- Minify CSS and JavaScript files
- Optimize images
- Enable Gzip compression
- Use CDN for static assets

## ?? Troubleshooting

### Common Issues

#### Blank White Screen
- Check PHP error logs: `tail -f /var/log/php_errors.log`
- Verify file permissions
- Check syntax errors in PHP files

#### Database Connection Issues
- Verify database credentials
- Check database server status
- Test connection from command line

#### Performance Issues
- Enable query logging
- Check server resources
- Optimize database queries
- Enable caching

### Error Codes
- **401**: Unauthorized access
- **403**: Forbidden - insufficient permissions
- **404**: Page not found
- **500**: Internal server error
- **503**: Service unavailable

## ?? Support

### Documentation
- [User Manual](docs/user-manual.md)
- [Developer Guide](docs/developer-guide.md)
- [API Documentation](docs/api-documentation.md)

### Community
- [GitHub Issues](https://github.com/yourusername/Merrylay Enterprises-system/issues)
- [Discord Community](https://discord.gg/Merrylay Enterprises)
- [Forum](https://forum.Merrylay Enterprises.com)

### Professional Support
- Email: support@Merrylay Enterprises.com
- Phone: +234 800 000 0000
- Business Hours: Mon-Fri 9AM-5PM WAT

## ?? Contributing

We welcome contributions! Please read our [Contributing Guidelines](CONTRIBUTING.md) before submitting pull requests.

### Development Setup
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## ?? License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## ?? Acknowledgments

- [Bootstrap](https://getbootstrap.com/) - UI framework
- [Font Awesome](https://fontawesome.com/) - Icon library
- [Chart.js](https://www.chartjs.org/) - Charting library
- [Inter Font](https://rsms.me/inter/) - Typography

## ?? Roadmap

### Version 2.0 (Planned)
- [ ] Mobile app (React Native)
- [ ] Advanced reporting with AI insights
- [ ] Multi-store management
- [ ] API-first architecture
- [ ] Real-time synchronization
- [ ] Advanced inventory forecasting

### Version 1.5 (In Development)
- [ ] Barcode scanning integration
- [ ] Advanced user permissions
- [ ] Custom report builder
- [ ] Email notifications
- [ ] Data import/export wizard

---

**Merrylay Enterprises** - Empowering businesses with professional inventory and sales management solutions.

Made with ?? by the Merrylay Enterprises Team

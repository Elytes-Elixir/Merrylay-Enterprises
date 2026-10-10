# Offline/Online Capability Guide

## Overview
The Merrylay System is designed to work seamlessly both offline and online, with graceful degradation when internet connectivity is unavailable.

## Key Features

### 1. Offline Detection
- **JavaScript Handler**: `assets/js/offline-handler.js` automatically detects network status
- **Visual Indicators**: Shows notifications when connection is lost or restored
- **Body Classes**: Adds `.offline-mode` class to body when offline for styling adjustments

### 2. Asset Loading Strategy
- **CDN with Fallback**: Primary assets loaded from CDN for performance
- **Font Fallbacks**: System fonts used when Google Fonts unavailable
- **Inline Fallback Styles**: Basic styling injected if CDN resources fail to load

### 3. Network Configuration
- **Auto IP Detection**: Automatically detects server IP for LAN access
- **Session Management**: Configured for both local and network access
- **Connection Timeouts**: 30-second timeout for network operations
- **Retry Logic**: Database connections retry 3 times with 0.5s delay

### 4. Database Connection
- **Connection Retries**: 3 automatic retries on connection failure
- **Timeout Handling**: Graceful error messages on persistent failures
- **Persistent Connections**: Configurable (currently enabled for performance)
- **Buffered Queries**: Enabled for better performance

## How It Works

### Online Mode
- All CDN resources load normally
- Google Fonts display
- Full functionality available
- Charts and advanced features work

### Offline Mode
- System detects network loss
- Shows notification: "You are offline. Some features may be limited."
- Falls back to system fonts
- Basic styling injected via inline CSS
- Database operations continue (MySQL is local)
- Core functionality remains available

### LAN Access
- System auto-detects server IP
- Works on local network without internet
- Same functionality as offline mode
- Accessible from other devices on network

## Configuration

### Network Settings (`config/network.php`)
```php
// Manual IP override (if needed)
$server_ip = '192.168.1.100';

// Session timeout (24 hours)
ini_set('session.gc_maxlifetime', 86400);

// Connection timeout (30 seconds)
ini_set('default_socket_timeout', 30);
```

### Database Settings (`config/db.php`)
```php
// Connection retries
define('DB_CONNECTION_RETRIES', 3);

// Connection timeout
define('DB_CONNECTION_TIMEOUT', 30);

// Persistent connections
define('DB_PERSISTENT', true);
```

### Asset Settings (`config/assets.php`)
```php
// Use CDN assets
define('USE_CDN_ASSETS', true);

// Use Google Fonts
define('USE_GOOGLE_FONTS', true);

// Fallback font family
define('FALLBACK_FONT_FAMILY', 'system-ui, -apple-system, "Segoe UI", Roboto...');
```

## Testing Offline/Online Functionality

### Test Offline Mode
1. Open system in browser
2. Disconnect internet
3. Observe offline notification
4. Verify core functionality still works
5. Reconnect internet
6. Observe "back online" notification

### Test LAN Access
1. Ensure server is running
2. Find server IP (check network settings)
3. Access from another device: `http://[SERVER_IP]/merrylay_system`
4. Verify full functionality

### Test Database Resilience
1. Stop MySQL server
2. Try accessing system
3. Observe error message after 3 retries
4. Start MySQL server
5. Refresh page - system should work

## Files Modified for Offline Support

### Configuration Files
- `config/network.php` - Network settings and LAN access
- `config/assets.php` - Asset loading configuration
- `config/db.php` - Database connection with retry logic
- `init.php` - Initialization with offline handler

### JavaScript Files
- `assets/js/offline-handler.js` - NEW: Offline detection and fallback

### PHP Files (Offline Handler Added)
- `dashboard/index.php`
- `auth/login.php`
- `auth/settings.php`
- `users/permissions.php`
- `users/manage.php`

## Limitations in Offline Mode

### Not Available Offline
- Google Fonts (falls back to system fonts)
- CDN-based JavaScript libraries if not cached
- External API calls (if any)
- Real-time updates requiring internet

### Available Offline
- All database operations
- User authentication (sessions)
- Product management
- Stock management
- Sales recording
- Dispatch operations
- Reports generation
- All core business functionality

## Best Practices

1. **Test Regularly**: Periodically test offline functionality
2. **Monitor Logs**: Check error logs for connection issues
3. **Update Configuration**: Adjust timeouts based on network conditions
4. **Cache Assets**: Browser caching helps offline performance
5. **Database Backup**: Ensure regular backups even when offline

## Troubleshooting

### System Won't Load Offline
- Check if MySQL is running
- Verify database connection settings
- Check error logs in `logs/` directory
- Ensure PHP is configured correctly

### LAN Access Not Working
- Verify server IP is correct
- Check firewall settings
- Ensure both devices on same network
- Verify XAMPP/Apache is accessible from network

### Styles Missing Offline
- CDN resources not cached
- Browser blocking external resources
- Check browser console for errors
- Inline fallback styles should activate automatically

### Database Connection Issues
- Verify MySQL is running
- Check connection credentials in `config/db.php`
- Ensure database exists
- Check for port conflicts

## Future Enhancements

1. **Service Worker**: Implement for true offline-first capability
2. **Local Asset Copies**: Download and serve Bootstrap/FontAwesome locally
3. **Offline Queue**: Queue operations to sync when online
4. **Data Sync**: Sync data across multiple offline instances
5. **Progressive Web App**: Convert to PWA for better offline support

## Support

For issues with offline/online functionality:
1. Check this guide first
2. Review error logs
3. Test database connectivity
4. Verify network configuration
5. Contact system administrator

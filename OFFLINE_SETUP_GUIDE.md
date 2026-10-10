# Offline/Online Setup Guide for Merrylay Enterprises

This guide will help you configure the Merrylay Enterprises to work fully offline and online.

## Overview

The system is designed to work in both offline and online modes:
- **Online Mode**: Uses CDN resources for faster loading, syncs data when available
- **Offline Mode**: Uses local resources, stores data locally, syncs when back online

## Step 1: Download External Resources

### 1.1 Download Bootstrap 5.3.0
Download from: https://getbootstrap.com/docs/5.3/getting-started/download/

Save to:
- `assets/vendor/bootstrap/bootstrap.min.css`
- `assets/vendor/bootstrap/bootstrap.bundle.min.js`

### 1.2 Download Font Awesome 6.4.0
Download from: https://fontawesome.com/download

Save to:
- `assets/vendor/fontawesome/css/all.min.css`
- `assets/vendor/fontawesome/webfonts/*` (all font files)

### 1.3 Download jQuery 3.7.0
Download from: https://jquery.com/download/

Save to:
- `assets/vendor/jquery/jquery.min.js`

### 1.4 Download Inter Font
Download from: https://fonts.google.com/specimen/Inter

Save to:
- `assets/fonts/inter/*` (all font files)

## Step 2: Configure Resource Loading

The system automatically detects if local resources are available and uses them with CDN fallback.

### 2.1 Resource Loading Priority
1. Local resources (if available)
2. CDN resources (if online)
3. Graceful degradation (if offline)

### 2.2 Test Resource Loading
Access the system and check browser console for resource loading status.

## Step 3: Offline Detection

The system automatically detects offline status and:
- Shows offline indicator in UI
- Stores data locally
- Enables offline functionality
- Syncs data when back online

## Step 4: Service Worker (Optional)

For full offline capability, register the service worker:

```javascript
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js')
        .then(reg => console.log('Service Worker registered'))
        .catch(err => console.log('Service Worker registration failed'));
}
```

## Step 5: Data Synchronization

### 5.1 Online Sync
When back online, the system automatically:
- Syncs stored local data
- Updates server records
- Resolves conflicts

### 5.2 Conflict Resolution
- Server data takes priority for critical records
- Local data preserved for user preferences
- Manual resolution for conflicting records

## Step 6: Testing Offline Mode

### 6.1 Test Procedure
1. Load the system while online
2. Disconnect network
3. Navigate through pages
4. Test data entry
5. Reconnect network
6. Verify data sync

### 6.2 Expected Behavior
- Pages load without errors
- Data can be entered offline
- Offline indicator shows
- Data syncs when back online

## Troubleshooting

### Resources Not Loading Offline
1. Verify local files are in correct locations
2. Check file permissions
3. Clear browser cache
4. Check browser console for errors

### Data Not Syncing
1. Check network connection
2. Verify service worker is registered
3. Check browser storage limits
4. Review sync logs

### Performance Issues Offline
1. Optimize local resource sizes
2. Enable compression
3. Use efficient data storage
4. Minimize unnecessary requests

## Best Practices

1. **Regular Updates**: Keep local resources updated
2. **Testing**: Regularly test offline functionality
3. **Monitoring**: Monitor sync status and errors
4. **Backup**: Backup local data regularly
5. **Documentation**: Document any custom configurations

## Maintenance

### Regular Tasks
- Update local resources when new versions are available
- Test offline functionality after updates
- Monitor storage usage
- Review sync logs for errors
- Backup configuration files

### Monitoring
- Check browser console for errors
- Monitor network requests
- Track sync success/failure rates
- Review storage usage
- Monitor performance metrics


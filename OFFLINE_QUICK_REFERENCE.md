# Offline System - Quick Reference

## Global APIs

### Check Online Status
```javascript
if (window.offlineAPI.isOnline()) {
    // Online
} else {
    // Offline
}
```

### Queue a Request
```javascript
const result = await window.syncManager.queueRequest(
    '/api/endpoint',
    'POST',
    { data: 'value' }
);
```

### Get Queue Stats
```javascript
const stats = await window.syncManager.getQueueStats();
// {pending: 2, failed: 0, total: 2, isSyncing: false, isOnline: true}
```

### Cache Data
```javascript
await window.offlineDB.cacheData('key', data, 'api', 3600000);
```

### Get Cached Data
```javascript
const data = await window.offlineDB.getCachedData('key');
```

### Retry Failed Items
```javascript
await window.syncManager.retryFailedItems();
```

### Show Notification
```javascript
window.offlineAPI.showNotification('Message', 'success'); // info|warning|error
```

---

## Events

```javascript
// Connection changed
document.addEventListener('offline-status-change', (e) => {
    console.log('Online:', e.detail.isOnline);
});

// Sync completed
document.addEventListener('offline-sync-complete', (e) => {
    console.log(`Synced ${e.detail.success}, failed ${e.detail.failed}`);
});

// Sync status changed
document.addEventListener('offline-sync-status', (e) => {
    console.log('Syncing:', e.detail.syncing);
});

// Queue updated
document.addEventListener('offline-queue-update', (e) => {
    console.log('Queue updated at:', e.detail.timestamp);
});
```

---

## Configuration

Edit `config/offline.php`:

```php
// Cache TTL (milliseconds)
OFFLINE_CACHE_CONFIG['ttl']['api_sales'] = 300000; // 5 min

// Sync interval
OFFLINE_SYNC_CONFIG['sync_interval'] = 30000; // 30 sec

// Max retries
OFFLINE_SYNC_CONFIG['max_retries'] = 3;
```

---

## Common Patterns

### Form with Offline Support
```javascript
document.getElementById('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const result = await window.syncManager.queueRequest(
        '/api/form',
        'POST',
        new FormData(e.target)
    );
    
    if (result.success) {
        alert('Submitted!');
    } else if (result.queued) {
        alert('Queued - will sync when online');
    }
});
```

### With Optimistic UI
```javascript
// Update UI immediately
updateUI(data);

// Queue for server sync
await window.syncManager.queueRequest('/api/update', 'POST', data);

// Listen for completion
document.addEventListener('offline-sync-complete', (e) => {
    if (e.detail.failed === 0) {
        showSuccess('All changes synced');
    }
});
```

### Check Before Critical Action
```javascript
if (!window.offlineAPI.isOnline()) {
    alert('This requires internet connection');
    return;
}

// Proceed with action
```

---

## Debugging

### View Queue
```javascript
const queue = await window.offlineDB.getQueue();
console.table(queue);
```

### View Cache Stats
```javascript
const stats = await window.offlineDB.getStats();
console.log('Queue size:', stats.queueSize);
console.log('Cache items:', stats.cachedItems);
```

### Check Service Worker
```javascript
navigator.serviceWorker.getRegistrations().then(regs => {
    console.log('Service Workers:', regs);
    regs.forEach(reg => console.log('State:', reg.active?.state));
});
```

### Clear All Offline Data
```javascript
await window.offlineDB.clearQueue();
await window.offlineDB.clearExpiredCache();
caches.keys().then(names => {
    names.forEach(name => caches.delete(name));
});
```

---

## Performance Tips

1. **Cache API Responses**: Use `cacheResponse()` for read-only data
2. **Optimize TTLs**: Shorter for frequently changing, longer for static
3. **Batch Operations**: Queue multiple requests before sync
4. **Monitor Queue**: Check queue size regularly
5. **Test Offline**: Regularly test offline functionality

---

## Checklist for New Features

- [ ] Use `queueRequest()` for API calls that need offline support
- [ ] Cache read-only responses with `cacheData()`
- [ ] Set appropriate TTL values
- [ ] Test offline mode with DevTools
- [ ] Verify sync on reconnect
- [ ] Handle errors gracefully
- [ ] Update documentation

---

## File Locations

| Feature | File |
|---------|------|
| Service Worker | `/sw.js` |
| Offline DB | `/assets/js/offline-database.js` |
| Sync Manager | `/assets/js/offline-sync-manager.js` |
| Assets Loader | `/assets/js/offline-assets-loader.js` |
| Main Handler | `/assets/js/offline-handler.js` |
| Config | `/config/offline.php` |

---

## Browser Console Commands

```javascript
// Test offline mode
window.offlineAPI.isOnline()

// See sync manager
window.syncManager

// See database
window.offlineDB

// See assets loader  
window.assetsLoader

// Manual sync
await window.syncManager.syncNow()

// Queue stats
await window.syncManager.getQueueStats()

// DB stats
await window.offlineDB.getStats()

// Clear everything
caches.keys().then(n => n.forEach(name => caches.delete(name)))
```

---

## Keyboard Shortcuts (DevTools)

```
F12              - Open DevTools
Ctrl+Shift+Del   - Clear Storage (including offline data)
Network Tab      - Check "Offline" to simulate offline mode
Application Tab  - View Service Workers, IndexedDB, Cache Storage
```

---

**See full documentation**: `OFFLINE_OPTIMIZATION_COMPLETE.md`

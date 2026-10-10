/**
 * Service Worker for Merrylay Enterprises
 * Provides comprehensive offline capability with intelligent caching,
 * background sync, and data synchronization
 */

const CACHE_NAME = 'merrylay-v2';
const RUNTIME_CACHE = 'merrylay-runtime-v2';
const API_CACHE = 'merrylay-api-v2';

// Base path derived from the service worker location.
const BASE_PATH = self.location.pathname.replace(/\/sw\.js$/, '/');
const OFFLINE_URL = `${BASE_PATH}offline.html`;

// Static assets to cache on install (core resources)
const ESSENTIAL_URLS = [
    './offline.html',
    './assets/css/unified-design-system.css',
    './assets/js/offline-handler.js',
    './assets/js/offline-database.js',
    './assets/js/offline-sync-manager.js'
];

// HTML pages to cache (lazy loaded)
const CACHEABLE_HTML = [
    './',
    './index.php',
    './dashboard/index.php',
    './auth/login.php'
];

// Install event - aggressively cache essential resources
self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            try {
                const cache = await caches.open(CACHE_NAME);
                
                // Cache essential resources first
                await cache.addAll(ESSENTIAL_URLS);
                
                // Try to cache HTML pages (non-blocking)
                Promise.allSettled(
                    CACHEABLE_HTML.map(url => cache.add(url))
                ).catch(e => console.warn('Partial HTML cache failure:', e));
                
                console.log('[SW] Installation complete - offline mode enabled');
                self.skipWaiting();
            } catch (error) {
                console.error('[SW] Installation failed:', error);
            }
        })()
    );
});

// Activate event - clean up old caches and claim clients
self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            try {
                const cacheNames = await caches.keys();
                await Promise.all(
                    cacheNames
                        .filter(name => name !== CACHE_NAME && name !== RUNTIME_CACHE && name !== API_CACHE)
                        .map(name => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
                self.clients.claim();
                console.log('[SW] Activation complete');
            } catch (error) {
                console.error('[SW] Activation failed:', error);
            }
        })()
    );
});

// Fetch event - intelligent caching strategy
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Network-first for API calls (with offline fallback)
    if (url.pathname.includes('/api/')) {
        return event.respondWith(networkFirstStrategy(request));
    }

    // Cache-first for assets (CSS, JS, fonts, images)
    if (isAssetRequest(request)) {
        return event.respondWith(cacheFirstStrategy(request));
    }

    // Network-first with cache fallback for HTML pages
    if (request.mode === 'navigate') {
        return event.respondWith(networkFirstWithFallback(request));
    }

    // Default: network-first with offline fallback
    return event.respondWith(networkFirstWithFallback(request));
});

// Strategy: Network First (try network, fallback to cache)
async function networkFirstStrategy(request) {
    try {
        const response = await fetch(request);
        
        if (response && response.status === 200) {
            // Cache successful responses
            const cache = await caches.open(API_CACHE);
            cache.put(request, response.clone());
        }
        
        return response;
    } catch (error) {
        console.log('[SW] Network failed, trying cache:', request.url);
        return caches.match(request) || createOfflineResponse();
    }
}

// Strategy: Cache First (use cache, fallback to network)
async function cacheFirstStrategy(request) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }
    
    try {
        const response = await fetch(request);
        if (response && response.status === 200) {
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        console.log('[SW] Asset offline fallback:', request.url);
        return createAssetFallback(request);
    }
}

// Strategy: Network First with Fallback
async function networkFirstWithFallback(request) {
    try {
        const response = await fetch(request);
        
        if (response && response.status === 200) {
            const cache = await caches.open(
                request.mode === 'navigate' ? CACHE_NAME : RUNTIME_CACHE
            );
            cache.put(request, response.clone());
        }
        
        return response;
    } catch (error) {
        console.log('[SW] Network failed, checking cache:', request.url);
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }
        
        // Navigation request - return offline page
        if (request.mode === 'navigate') {
            return caches.match(OFFLINE_URL);
        }
        
        return createOfflineResponse();
    }
}

// Helper: Check if request is for static assets
function isAssetRequest(request) {
    const url = new URL(request.url);
    return /\.(js|css|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico)$/.test(url.pathname);
}

// Helper: Create offline fallback response
function createOfflineResponse() {
    return new Response(
        JSON.stringify({
            status: 'offline',
            message: 'You are currently offline. Please check your connection.',
            timestamp: new Date().toISOString()
        }),
        {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'application/json' }
        }
    );
}

// Helper: Create asset fallback (CSS/JS/etc)
function createAssetFallback(request) {
    const url = new URL(request.url);
    
    if (url.pathname.endsWith('.css')) {
        return new Response('/* Offline CSS Fallback */', {
            headers: { 'Content-Type': 'text/css' }
        });
    }
    
    if (url.pathname.endsWith('.js')) {
        return new Response('console.log("Offline - JS not available");', {
            headers: { 'Content-Type': 'application/javascript' }
        });
    }
    
    // Transparent 1x1 pixel for images
    const base64Pixel = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    const binaryString = atob(base64Pixel);
    const pixelArray = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
        pixelArray[i] = binaryString.charCodeAt(i);
    }

    return new Response(pixelArray, {
        headers: { 'Content-Type': 'image/gif' }
    });
}

// Background Sync event
self.addEventListener('sync', (event) => {
    if (event.tag.startsWith('sync-')) {
        event.waitUntil(performSync(event.tag));
    }
});

// Perform background sync
async function performSync(tag) {
    try {
        console.log('[SW] Starting background sync:', tag);
        
        // Get pending data from IndexedDB
        const pendingData = await getOfflineQueue();
        
        if (!pendingData || pendingData.length === 0) {
            console.log('[SW] No pending data to sync');
            return;
        }
        
        // Attempt to sync all pending items
        const results = await Promise.allSettled(
            pendingData.map(item => syncQueueItem(item))
        );
        
        console.log('[SW] Sync results:', results);
    } catch (error) {
        console.error('[SW] Background sync failed:', error);
        throw error; // Retry later
    }
}

// Sync a single queue item
async function syncQueueItem(item) {
    const response = await fetch(item.url, {
        method: item.method || 'POST',
        headers: item.headers || { 'Content-Type': 'application/json' },
        body: item.body ? JSON.stringify(item.body) : undefined
    });
    
    if (!response.ok) {
        throw new Error(`Sync failed: ${response.status}`);
    }
    
    // Remove from queue on success
    await removeFromOfflineQueue(item.id);
    return response.json();
}

// Get offline queue from IndexedDB
async function getOfflineQueue() {
    return new Promise((resolve) => {
        const request = indexedDB.open('MerrlayDB', 1);
        request.onsuccess = (e) => {
            try {
                const db = e.target.result;
                const store = db.transaction(['offlineQueue'], 'readonly').objectStore('offlineQueue');
                const result = store.getAll();
                result.onsuccess = () => resolve(result.result);
            } catch (error) {
                console.warn('[SW] Could not read queue:', error);
                resolve([]);
            }
        };
        request.onerror = () => resolve([]);
    });
}

// Remove item from offline queue
async function removeFromOfflineQueue(id) {
    return new Promise((resolve) => {
        const request = indexedDB.open('MerrlayDB', 1);
        request.onsuccess = (e) => {
            try {
                const db = e.target.result;
                const store = db.transaction(['offlineQueue'], 'readwrite').objectStore('offlineQueue');
                store.delete(id);
                resolve();
            } catch (error) {
                console.warn('[SW] Could not remove from queue:', error);
                resolve();
            }
        };
        request.onerror = () => resolve();
    });
}

// Push notification event
self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};
    const options = {
        body: data.message || 'New notification',
        icon: '/assets/images/icon-192x192.png',
        badge: '/assets/images/badge-72x72.png',
        tag: 'merrylay-notification',
        requireInteraction: data.requireInteraction || false
    };
    
    event.waitUntil(
        self.registration.showNotification('Merrylay Enterprises', options)
    );
});

// Notification click event
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window' }).then((clientList) => {
            // Check if window already open
            for (let client of clientList) {
                if (client.url === '/' && 'focus' in client) {
                    return client.focus();
                }
            }
            // Open new window if not found
            if (clients.openWindow) {
                return clients.openWindow('/dashboard/index.php');
            }
        })
    );
});

/**
 * Offline Detection and Graceful Degradation
 * Comprehensive offline mode handler with sync management
 */

(function() {
    'use strict';

    // State tracking
    const state = {
        isOnline: navigator.onLine,
        syncManager: null,
        offlineDB: null,
        assetsLoader: null,
        notificationTimeout: null,
        uiInitialized: false
    };

    /**
     * Check if online
     */
    function isOnline() {
        return navigator.onLine;
    }

    /**
     * Initialize offline support
     */
    function initOfflineSupport() {
        console.log('[OfflineHandler] Initializing offline support');

        // Initialize offline database
        initDatabase();

        // Initialize sync manager
        initSyncManager();

        // Initialize assets loader
        initAssetsLoader();

        // Initialize UI elements
        initUIElements();

        // Add online/offline listeners
        addNetworkListeners();

        // Update initial state
        updateOfflineUI();

        state.uiInitialized = true;
    }

    /**
     * Initialize offline database
     */
    function initDatabase() {
        if (window.offlineDB) {
            state.offlineDB = window.offlineDB;
            console.log('[OfflineHandler] Offline database ready');
        }
    }

    /**
     * Initialize sync manager
     */
    function initSyncManager() {
        if (window.syncManager) {
            state.syncManager = window.syncManager;
            
            // Listen for sync events
            document.addEventListener('offline-status-change', (e) => {
                updateOfflineUI();
                showNotification(
                    e.detail.isOnline 
                        ? 'You are back online!' 
                        : 'You are offline. Changes will sync when online.',
                    e.detail.isOnline ? 'success' : 'warning'
                );
            });

            document.addEventListener('offline-sync-complete', (e) => {
                if (e.detail.success > 0) {
                    showNotification(
                        `Synced ${e.detail.success} items successfully!`,
                        'success'
                    );
                }
                if (e.detail.failed > 0) {
                    showNotification(
                        `Failed to sync ${e.detail.failed} items. Will retry.`,
                        'warning'
                    );
                }
            });

            console.log('[OfflineHandler] Sync manager ready');
        }
    }

    /**
     * Initialize assets loader
     */
    function initAssetsLoader() {
        if (window.assetsLoader) {
            state.assetsLoader = window.assetsLoader;
            console.log('[OfflineHandler] Assets loader ready');
        }
    }

    /**
     * Add online/offline event listeners
     */
    function addNetworkListeners() {
        window.addEventListener('online', function() {
            console.log('[OfflineHandler] Connection restored');
            state.isOnline = true;
            document.body.classList.remove('offline-mode');
            showNotification('You are back online! Syncing data...', 'success');
            
            // Trigger sync when coming back online
            if (state.syncManager) {
                state.syncManager.syncNow().catch(error => {
                    console.error('[OfflineHandler] Sync error:', error);
                });
            }
        });

        window.addEventListener('offline', function() {
            console.log('[OfflineHandler] Connection lost');
            state.isOnline = false;
            document.body.classList.add('offline-mode');
            showNotification('You are offline. Changes will be synced when online.', 'warning');
        });
    }

    /**
     * Initialize UI elements for offline mode
     */
    function initUIElements() {
        // Create offline indicator if it doesn't exist
        if (!document.getElementById('offline-indicator')) {
            const indicator = document.createElement('div');
            indicator.id = 'offline-indicator';
            indicator.className = 'offline-indicator';
            indicator.innerHTML = `
                <div class="offline-indicator-content">
                    <span class="offline-indicator-icon">📡</span>
                    <span class="offline-indicator-text">Offline</span>
                    <span class="offline-indicator-close">&times;</span>
                </div>
            `;
            document.body.appendChild(indicator);

            // Close button handler
            indicator.querySelector('.offline-indicator-close').addEventListener('click', () => {
                indicator.classList.remove('show');
            });
        }

        // Create notification container if it doesn't exist
        if (!document.getElementById('offline-notification-container')) {
            const container = document.createElement('div');
            container.id = 'offline-notification-container';
            container.className = 'offline-notification-container';
            document.body.appendChild(container);
        }

        // Create sync status if it doesn't exist
        if (!document.getElementById('offline-sync-status')) {
            const status = document.createElement('div');
            status.id = 'offline-sync-status';
            status.className = 'offline-sync-status';
            document.body.appendChild(status);
        }
    }

    /**
     * Update offline UI
     */
    function updateOfflineUI() {
        const indicator = document.getElementById('offline-indicator');
        if (!indicator) return;

        if (!state.isOnline) {
            indicator.classList.add('show');
        } else {
            indicator.classList.remove('show');
        }
    }

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        const container = document.getElementById('offline-notification-container');
        if (!container) return;

        // Clear existing timeout
        if (state.notificationTimeout) {
            clearTimeout(state.notificationTimeout);
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `offline-notification offline-notification-${type}`;
        notification.textContent = message;

        container.innerHTML = '';
        container.appendChild(notification);

        // Auto-hide after 5 seconds
        state.notificationTimeout = setTimeout(() => {
            if (container.contains(notification)) {
                container.removeChild(notification);
            }
        }, 5000);
    }

    /**
     * Update sync status display
     */
    async function updateSyncStatus() {
        const statusEl = document.getElementById('offline-sync-status');
        if (!statusEl || !state.syncManager) return;

        const stats = await state.syncManager.getQueueStats();
        
        if (stats.total === 0) {
            statusEl.style.display = 'none';
            return;
        }

        statusEl.innerHTML = `
            <div class="sync-status-content">
                <span class="sync-status-pending">${stats.pending} pending</span>
                ${stats.failed > 0 ? `<span class="sync-status-failed">${stats.failed} failed</span>` : ''}
                ${stats.isSyncing ? '<span class="sync-status-syncing">⟳ Syncing...</span>' : ''}
            </div>
        `;
        statusEl.style.display = 'block';
    }

    /**
     * Wrap fetch with offline queue support
     */
    function wrapFetch() {
        const originalFetch = window.fetch;
        
        window.fetch = function(...args) {
            const [resource, config] = args;
            const url = typeof resource === 'string' ? resource : resource.url;

            // Only intercept API calls
            if (!url.includes('/api/')) {
                return originalFetch.apply(this, args);
            }

            // If online, use normal fetch
            if (state.isOnline) {
                return originalFetch.apply(this, args)
                    .then(response => {
                        // Cache successful responses
                        if (response.ok && state.syncManager) {
                            const key = `cache_${url}`;
                            state.syncManager.cacheResponse(key, response.clone(), 3600000);
                        }
                        return response;
                    })
                    .catch(error => {
                        // If offline, try cache
                        if (!state.isOnline && state.syncManager) {
                            const key = `cache_${url}`;
                            return state.syncManager.getCachedResponse(key)
                                .then(data => {
                                    if (data) {
                                        return new Response(JSON.stringify(data), {
                                            status: 200,
                                            headers: { 'Content-Type': 'application/json' }
                                        });
                                    }
                                    throw error;
                                });
                        }
                        throw error;
                    });
            }

            // If offline, try cache first
            if (state.syncManager) {
                const key = `cache_${url}`;
                return state.syncManager.getCachedResponse(key)
                    .then(data => {
                        if (data) {
                            return new Response(JSON.stringify(data), {
                                status: 200,
                                headers: { 'Content-Type': 'application/json' }
                            });
                        }
                        return originalFetch.apply(this, args);
                    });
            }

            return originalFetch.apply(this, args);
        };
    }

    /**
     * Fallback for failed CDN loads
     */
    function setupCDNFallback() {
        window.addEventListener('error', function(e) {
            if (e.target.tagName === 'LINK' || e.target.tagName === 'SCRIPT') {
                const src = e.target.src || e.target.href;
                if (src && (src.includes('cdn') || src.includes('googleapis') || src.includes('fonts'))) {
                    console.warn('[OfflineHandler] CDN resource failed:', src);
                    
                    if (e.target.tagName === 'LINK') {
                        injectFallbackStyles();
                    }
                }
            }
        }, true);
    }

    /**
     * Inject fallback styles for offline
     */
    function injectFallbackStyles() {
        if (!document.getElementById('offline-fallback-styles')) {
            const style = document.createElement('style');
            style.id = 'offline-fallback-styles';
            style.textContent = `
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
                }

                .offline-indicator {
                    display: none;
                    position: fixed;
                    top: 10px;
                    right: 10px;
                    background: #f59e0b;
                    color: white;
                    padding: 12px 16px;
                    border-radius: 6px;
                    z-index: 9999;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    font-size: 14px;
                    font-weight: 600;
                }

                .offline-indicator.show {
                    display: block;
                    animation: slideIn 0.3s ease-out;
                }

                @keyframes slideIn {
                    from {
                        transform: translateX(400px);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }

                .offline-indicator-content {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .offline-indicator-close {
                    cursor: pointer;
                    font-size: 18px;
                    line-height: 1;
                    margin-left: 8px;
                    opacity: 0.8;
                    transition: opacity 0.2s;
                }

                .offline-indicator-close:hover {
                    opacity: 1;
                }

                .offline-notification-container {
                    position: fixed;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    z-index: 9998;
                    max-width: 500px;
                    width: 90%;
                }

                .offline-notification {
                    padding: 12px 16px;
                    border-radius: 6px;
                    color: white;
                    font-weight: 500;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    animation: slideDown 0.3s ease-out;
                }

                @keyframes slideDown {
                    from {
                        transform: translateY(-100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateY(0);
                        opacity: 1;
                    }
                }

                .offline-notification-info {
                    background: #3b82f6;
                }

                .offline-notification-success {
                    background: #10b981;
                }

                .offline-notification-warning {
                    background: #f59e0b;
                }

                .offline-notification-error {
                    background: #ef4444;
                }

                .offline-sync-status {
                    display: none;
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: white;
                    border: 2px solid #e5e7eb;
                    border-radius: 8px;
                    padding: 12px 16px;
                    z-index: 9997;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    font-size: 13px;
                }

                .sync-status-content {
                    display: flex;
                    gap: 12px;
                    align-items: center;
                }

                .sync-status-pending {
                    background: #dbeafe;
                    color: #1e40af;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-weight: 600;
                }

                .sync-status-failed {
                    background: #fee2e2;
                    color: #991b1b;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-weight: 600;
                }

                .sync-status-syncing {
                    color: #f59e0b;
                    font-weight: 600;
                }

                .offline-mode {
                    opacity: 0.95;
                }
            `;
            document.head.appendChild(style);
        }
    }

    /**
     * Initialize when DOM is ready
     */
    function initialize() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                initOfflineSupport();
                wrapFetch();
                setupCDNFallback();
                injectFallbackStyles();
            });
        } else {
            initOfflineSupport();
            wrapFetch();
            setupCDNFallback();
            injectFallbackStyles();
        }
    }

    // Start initialization
    initialize();

    // Expose API
    window.offlineAPI = {
        isOnline,
        getSyncManager: () => state.syncManager,
        getDatabase: () => state.offlineDB,
        getAssetsLoader: () => state.assetsLoader,
        updateSyncStatus,
        showNotification
    };

})();

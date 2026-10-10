/**
 * Offline Sync Manager
 * Handles queuing, retry logic, and synchronization of offline data
 */

class OfflineSyncManager {
    constructor() {
        this.isOnline = navigator.onLine;
        this.isSyncing = false;
        this.syncInterval = 30000; // Attempt sync every 30 seconds
        this.maxRetries = 3;
        this.retryDelay = 5000; // Start with 5 second delay
        this.db = window.offlineDB;
        
        this.initEventListeners();
        this.startSyncTimer();
    }

    /**
     * Initialize online/offline event listeners
     */
    initEventListeners() {
        window.addEventListener('online', () => {
            console.log('[SyncManager] Connection restored');
            this.isOnline = true;
            this.notifyOnlineStatus(true);
            this.syncNow(); // Immediately attempt sync
        });

        window.addEventListener('offline', () => {
            console.log('[SyncManager] Connection lost');
            this.isOnline = false;
            this.notifyOnlineStatus(false);
        });
    }

    /**
     * Start periodic sync timer
     */
    startSyncTimer() {
        setInterval(() => {
            if (this.isOnline && !this.isSyncing) {
                this.syncNow().catch(error => {
                    console.error('[SyncManager] Sync error:', error);
                });
            }
        }, this.syncInterval);
    }

    /**
     * Queue a request for offline/online execution
     */
    async queueRequest(endpoint, method = 'POST', data = null, options = {}) {
        try {
            const headers = {
                'Content-Type': 'application/json',
                ...options.headers
            };

            if (!this.isOnline) {
                // Save to offline queue
                const id = await this.db.addToQueue(endpoint, method, data, headers);
                this.notifyQueueUpdate();
                console.log('[SyncManager] Request queued for offline:', id);
                return {
                    success: false,
                    queued: true,
                    id,
                    message: 'Request queued (offline mode)'
                };
            }

            // Try to send immediately if online
            try {
                const response = await fetch(endpoint, {
                    method,
                    headers,
                    body: data ? JSON.stringify(data) : undefined
                });

                if (response.ok) {
                    return {
                        success: true,
                        status: response.status,
                        data: await response.json().catch(() => null)
                    };
                } else {
                    // Queue for retry if server error
                    if (response.status >= 500) {
                        const id = await this.db.addToQueue(endpoint, method, data, headers);
                        this.notifyQueueUpdate();
                        return {
                            success: false,
                            queued: true,
                            id,
                            status: response.status,
                            message: 'Request queued (server error)'
                        };
                    }
                    return {
                        success: false,
                        status: response.status,
                        error: response.statusText
                    };
                }
            } catch (error) {
                // Network error - queue for retry
                const id = await this.db.addToQueue(endpoint, method, data, headers);
                this.notifyQueueUpdate();
                console.log('[SyncManager] Request queued due to network error:', error);
                return {
                    success: false,
                    queued: true,
                    id,
                    message: 'Request queued (network error)'
                };
            }
        } catch (error) {
            console.error('[SyncManager] Queue request failed:', error);
            return {
                success: false,
                error: error.message
            };
        }
    }

    /**
     * Sync all queued requests
     */
    async syncNow() {
        if (this.isSyncing) {
            console.log('[SyncManager] Sync already in progress');
            return;
        }

        if (!this.isOnline) {
            console.log('[SyncManager] Cannot sync - offline');
            return;
        }

        this.isSyncing = true;
        this.notifySyncStatus(true);

        try {
            const queue = await this.db.getQueue();
            
            if (queue.length === 0) {
                console.log('[SyncManager] Queue is empty');
                this.isSyncing = false;
                this.notifySyncStatus(false);
                return;
            }

            console.log('[SyncManager] Syncing', queue.length, 'items');

            let successCount = 0;
            let failureCount = 0;

            for (const item of queue) {
                try {
                    await this.syncQueueItem(item);
                    successCount++;
                } catch (error) {
                    failureCount++;
                    console.error('[SyncManager] Failed to sync item', item.id, ':', error);
                }
            }

            console.log('[SyncManager] Sync complete:', {
                success: successCount,
                failed: failureCount,
                total: queue.length
            });

            this.notifySyncComplete(successCount, failureCount);
        } catch (error) {
            console.error('[SyncManager] Sync failed:', error);
        } finally {
            this.isSyncing = false;
            this.notifySyncStatus(false);
        }
    }

    /**
     * Sync a single queue item with retry logic
     */
    async syncQueueItem(item, retryCount = 0) {
        try {
            console.log('[SyncManager] Syncing item', item.id, 'attempt', retryCount + 1);

            const response = await Promise.race([
                fetch(item.endpoint, {
                    method: item.method,
                    headers: item.headers,
                    body: item.data ? JSON.stringify(item.data) : undefined
                }),
                new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('Request timeout')), 30000)
                )
            ]);

            if (response.ok) {
                await this.db.removeFromQueue(item.id);
                console.log('[SyncManager] Item synced successfully:', item.id);
                return { success: true, itemId: item.id };
            } else {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
        } catch (error) {
            console.warn('[SyncManager] Sync attempt failed:', error.message);

            // Update attempt counter
            await this.db.updateQueueStatus(item.id, 'pending', error.message);

            // Retry with exponential backoff
            if (retryCount < this.maxRetries) {
                const delay = this.retryDelay * Math.pow(2, retryCount);
                console.log('[SyncManager] Retrying in', delay, 'ms');
                await new Promise(resolve => setTimeout(resolve, delay));
                return this.syncQueueItem(item, retryCount + 1);
            } else {
                // Mark as failed after max retries
                await this.db.updateQueueStatus(item.id, 'failed', 'Max retries exceeded');
                throw new Error(`Failed after ${this.maxRetries} retries`);
            }
        }
    }

    /**
     * Cache API response for offline access
     */
    async cacheResponse(key, response, ttl = 3600000) {
        try {
            const data = response instanceof Response ? await response.json() : response;
            await this.db.cacheData(key, data, 'api', ttl);
            console.log('[SyncManager] Response cached:', key);
        } catch (error) {
            console.error('[SyncManager] Failed to cache response:', error);
        }
    }

    /**
     * Get cached response
     */
    async getCachedResponse(key) {
        try {
            const data = await this.db.getCachedData(key);
            if (data) {
                console.log('[SyncManager] Using cached response:', key);
            }
            return data;
        } catch (error) {
            console.error('[SyncManager] Failed to get cached response:', error);
            return null;
        }
    }

    /**
     * Clear old queue items (older than 7 days)
     */
    async clearOldQueueItems(maxAge = 7 * 24 * 60 * 60 * 1000) {
        try {
            const queue = await this.db.getQueue();
            const now = Date.now();
            const oldItems = queue.filter(item => now - item.timestamp > maxAge);

            for (const item of oldItems) {
                await this.db.removeFromQueue(item.id);
            }

            console.log('[SyncManager] Cleared', oldItems.length, 'old queue items');
        } catch (error) {
            console.error('[SyncManager] Failed to clear old items:', error);
        }
    }

    /**
     * Get queue statistics
     */
    async getQueueStats() {
        try {
            const queue = await this.db.getQueue();
            return {
                pending: queue.filter(i => i.status === 'pending').length,
                failed: queue.filter(i => i.status === 'failed').length,
                total: queue.length,
                isSyncing: this.isSyncing,
                isOnline: this.isOnline
            };
        } catch (error) {
            console.error('[SyncManager] Failed to get stats:', error);
            return {
                pending: 0,
                failed: 0,
                total: 0,
                isSyncing: this.isSyncing,
                isOnline: this.isOnline
            };
        }
    }

    /**
     * Notify listeners of online status change
     */
    notifyOnlineStatus(isOnline) {
        const event = new CustomEvent('offline-status-change', {
            detail: { isOnline }
        });
        document.dispatchEvent(event);
    }

    /**
     * Notify listeners of queue update
     */
    notifyQueueUpdate() {
        const event = new CustomEvent('offline-queue-update', {
            detail: { timestamp: Date.now() }
        });
        document.dispatchEvent(event);
    }

    /**
     * Notify listeners of sync status
     */
    notifySyncStatus(syncing) {
        const event = new CustomEvent('offline-sync-status', {
            detail: { syncing }
        });
        document.dispatchEvent(event);
    }

    /**
     * Notify listeners of sync completion
     */
    notifySyncComplete(successCount, failureCount) {
        const event = new CustomEvent('offline-sync-complete', {
            detail: {
                success: successCount,
                failed: failureCount,
                timestamp: Date.now()
            }
        });
        document.dispatchEvent(event);
    }

    /**
     * Force retry of failed items
     */
    async retryFailedItems() {
        try {
            const queue = await this.db.getQueue();
            const failed = queue.filter(i => i.status === 'failed');

            for (const item of failed) {
                await this.db.updateQueueStatus(item.id, 'pending', null);
            }

            console.log('[SyncManager] Marked', failed.length, 'failed items for retry');
            
            if (this.isOnline) {
                await this.syncNow();
            }
        } catch (error) {
            console.error('[SyncManager] Failed to retry failed items:', error);
        }
    }
}

// Global singleton
const syncManager = new OfflineSyncManager();

// Expose to window
if (typeof window !== 'undefined') {
    window.syncManager = syncManager;
}

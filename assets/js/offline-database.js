/**
 * Offline Database Manager
 * Manages IndexedDB for offline data persistence and sync
 */

class OfflineDatabase {
    constructor(dbName = 'MerrlayDB', version = 1) {
        this.dbName = dbName;
        this.version = version;
        this.db = null;
        this.initPromise = this.init();
    }

    /**
     * Initialize IndexedDB
     */
    init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.version);

            request.onerror = () => {
                console.error('[OfflineDB] Failed to open database:', request.error);
                reject(request.error);
            };

            request.onsuccess = (event) => {
                this.db = event.target.result;
                console.log('[OfflineDB] Database opened successfully');
                resolve(this.db);
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                console.log('[OfflineDB] Upgrading database schema');

                // Offline queue store - for pending transactions
                if (!db.objectStoreNames.contains('offlineQueue')) {
                    const queueStore = db.createObjectStore('offlineQueue', { keyPath: 'id', autoIncrement: true });
                    queueStore.createIndex('timestamp', 'timestamp');
                    queueStore.createIndex('endpoint', 'endpoint');
                    console.log('[OfflineDB] Created offlineQueue store');
                }

                // Cache store - for offline data
                if (!db.objectStoreNames.contains('cachedData')) {
                    const cacheStore = db.createObjectStore('cachedData', { keyPath: 'key' });
                    cacheStore.createIndex('timestamp', 'timestamp');
                    cacheStore.createIndex('type', 'type');
                    console.log('[OfflineDB] Created cachedData store');
                }

                // Sync metadata store
                if (!db.objectStoreNames.contains('syncMetadata')) {
                    db.createObjectStore('syncMetadata', { keyPath: 'key' });
                    console.log('[OfflineDB] Created syncMetadata store');
                }
            };
        });
    }

    /**
     * Add item to offline queue
     */
    async addToQueue(endpoint, method = 'POST', data = null, headers = {}) {
        await this.initPromise;

        const queueItem = {
            endpoint,
            method,
            data,
            headers,
            timestamp: Date.now(),
            status: 'pending',
            retries: 0,
            maxRetries: 3
        };

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['offlineQueue'], 'readwrite');
            const store = transaction.objectStore('offlineQueue');
            const request = store.add(queueItem);

            request.onsuccess = () => {
                console.log('[OfflineDB] Item added to queue:', request.result);
                resolve(request.result);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to add to queue:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get all pending queue items
     */
    async getQueue() {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['offlineQueue'], 'readonly');
            const store = transaction.objectStore('offlineQueue');
            const request = store.getAll();

            request.onsuccess = () => {
                resolve(request.result);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to get queue:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Remove item from queue by ID
     */
    async removeFromQueue(id) {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['offlineQueue'], 'readwrite');
            const store = transaction.objectStore('offlineQueue');
            const request = store.delete(id);

            request.onsuccess = () => {
                console.log('[OfflineDB] Item removed from queue:', id);
                resolve(true);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to remove from queue:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Update queue item status
     */
    async updateQueueStatus(id, status, error = null) {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['offlineQueue'], 'readwrite');
            const store = transaction.objectStore('offlineQueue');
            const getRequest = store.get(id);

            getRequest.onsuccess = () => {
                const item = getRequest.result;
                if (item) {
                    item.status = status;
                    if (error) item.lastError = error;
                    item.lastAttempt = Date.now();

                    const updateRequest = store.put(item);
                    updateRequest.onsuccess = () => resolve(true);
                    updateRequest.onerror = () => reject(updateRequest.error);
                } else {
                    resolve(false);
                }
            };

            getRequest.onerror = () => reject(getRequest.error);
        });
    }

    /**
     * Clear entire queue
     */
    async clearQueue() {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['offlineQueue'], 'readwrite');
            const store = transaction.objectStore('offlineQueue');
            const request = store.clear();

            request.onsuccess = () => {
                console.log('[OfflineDB] Queue cleared');
                resolve(true);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to clear queue:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Cache data for offline access
     */
    async cacheData(key, data, type = 'general', ttl = null) {
        await this.initPromise;

        const cacheEntry = {
            key,
            data,
            type,
            timestamp: Date.now(),
            expiresAt: ttl ? Date.now() + ttl : null
        };

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['cachedData'], 'readwrite');
            const store = transaction.objectStore('cachedData');
            const request = store.put(cacheEntry);

            request.onsuccess = () => {
                console.log('[OfflineDB] Data cached:', key);
                resolve(cacheEntry);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to cache data:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Retrieve cached data
     */
    async getCachedData(key) {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['cachedData'], 'readonly');
            const store = transaction.objectStore('cachedData');
            const request = store.get(key);

            request.onsuccess = () => {
                const entry = request.result;
                if (!entry) {
                    resolve(null);
                    return;
                }

                // Check if expired
                if (entry.expiresAt && entry.expiresAt < Date.now()) {
                    this.removeCachedData(key);
                    resolve(null);
                    return;
                }

                resolve(entry.data);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to get cached data:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Remove cached data
     */
    async removeCachedData(key) {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['cachedData'], 'readwrite');
            const store = transaction.objectStore('cachedData');
            const request = store.delete(key);

            request.onsuccess = () => {
                console.log('[OfflineDB] Cached data removed:', key);
                resolve(true);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to remove cached data:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get all cached data by type
     */
    async getCachedDataByType(type) {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['cachedData'], 'readonly');
            const store = transaction.objectStore('cachedData');
            const index = store.index('type');
            const request = index.getAll(type);

            request.onsuccess = () => {
                resolve(request.result);
            };

            request.onerror = () => {
                console.error('[OfflineDB] Failed to get cached data by type:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Clear expired cache entries
     */
    async clearExpiredCache() {
        await this.initPromise;

        const now = Date.now();
        const allEntries = await new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['cachedData'], 'readonly');
            const store = transaction.objectStore('cachedData');
            const request = store.getAll();

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        const expired = allEntries.filter(entry => entry.expiresAt && entry.expiresAt < now);

        return Promise.all(
            expired.map(entry => this.removeCachedData(entry.key))
        );
    }

    /**
     * Set sync metadata
     */
    async setSyncMetadata(key, value) {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['syncMetadata'], 'readwrite');
            const store = transaction.objectStore('syncMetadata');
            const request = store.put({ key, value, timestamp: Date.now() });

            request.onsuccess = () => resolve(true);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get sync metadata
     */
    async getSyncMetadata(key) {
        await this.initPromise;

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['syncMetadata'], 'readonly');
            const store = transaction.objectStore('syncMetadata');
            const request = store.get(key);

            request.onsuccess = () => {
                resolve(request.result ? request.result.value : null);
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get database size stats
     */
    async getStats() {
        await this.initPromise;

        return {
            queueSize: await this.getQueueSize(),
            cachedItems: await this.getCachedItemCount(),
            oldestQueueItem: await this.getOldestQueueItem()
        };
    }

    /**
     * Get number of items in queue
     */
    async getQueueSize() {
        await this.initPromise;

        return new Promise((resolve) => {
            const transaction = this.db.transaction(['offlineQueue'], 'readonly');
            const store = transaction.objectStore('offlineQueue');
            const request = store.count();

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => resolve(0);
        });
    }

    /**
     * Get cached items count
     */
    async getCachedItemCount() {
        await this.initPromise;

        return new Promise((resolve) => {
            const transaction = this.db.transaction(['cachedData'], 'readonly');
            const store = transaction.objectStore('cachedData');
            const request = store.count();

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => resolve(0);
        });
    }

    /**
     * Get oldest queue item
     */
    async getOldestQueueItem() {
        await this.initPromise;

        return new Promise((resolve) => {
            const transaction = this.db.transaction(['offlineQueue'], 'readonly');
            const store = transaction.objectStore('offlineQueue');
            const index = store.index('timestamp');
            const request = index.getAll();

            request.onsuccess = () => {
                const items = request.result;
                if (items.length === 0) {
                    resolve(null);
                } else {
                    resolve(items[0]);
                }
            };

            request.onerror = () => resolve(null);
        });
    }
}

// Global singleton instance
const offlineDB = new OfflineDatabase('MerrlayDB', 1);

// Expose to window
if (typeof window !== 'undefined') {
    window.offlineDB = offlineDB;
}

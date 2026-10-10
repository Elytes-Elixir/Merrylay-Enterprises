/**
 * Data Preloader for Offline-First App
 * 
 * Preloads essential data for offline access
 */

class DataPreloader {
    constructor() {
        this.preloadConfig = {
            products: { endpoint: '/merrylay_system/api/data/products.php', ttl: 3600000 }, // 1 hour
            users: { endpoint: '/merrylay_system/api/data/users.php', ttl: 3600000 }, // 1 hour
            categories: { endpoint: '/merrylay_system/api/data/categories.php', ttl: 86400000 }, // 24 hours
            settings: { endpoint: '/merrylay_system/api/data/settings.php', ttl: 86400000 }, // 24 hours
            dispatchers: { endpoint: '/merrylay_system/api/data/dispatchers.php', ttl: 3600000 } // 1 hour
        };
        this.isPreloading = false;
        this.preloadQueue = [];
    }

    /**
     * Initialize data preloader
     */
    async init() {
        console.log('[DataPreloader] Initializing...');
        
        // Check if we need to preload data
        const lastPreload = await this.getLastPreloadTime();
        const preloadInterval = 3600000; // 1 hour
        
        if (!lastPreload || (Date.now() - lastPreload) > preloadInterval) {
            console.log('[DataPreloader] Data needs preloading');
            await this.preloadAllData();
        } else {
            console.log('[DataPreloader] Data is fresh, skipping preload');
        }
    }

    /**
     * Preload all essential data
     */
    async preloadAllData() {
        if (this.isPreloading) {
            console.log('[DataPreloader] Already preloading, skipping');
            return;
        }

        this.isPreloading = true;
        console.log('[DataPreloader] Starting data preload...');

        try {
            // Get auth header
            const authHeader = await this.getAuthHeader();
            if (!authHeader) {
                console.log('[DataPreloader] No auth header available, skipping preload');
                return;
            }

            // Preload each data type
            const preloadPromises = Object.entries(this.preloadConfig).map(([key, config]) => 
                this.preloadData(key, config, authHeader)
            );

            await Promise.allSettled(preloadPromises);
            
            // Update last preload time
            await this.setLastPreloadTime(Date.now());
            
            console.log('[DataPreloader] Data preload completed');
        } catch (error) {
            console.error('[DataPreloader] Preload failed:', error);
        } finally {
            this.isPreloading = false;
        }
    }

    /**
     * Preload specific data type
     */
    async preloadData(key, config, authHeader) {
        try {
            console.log(`[DataPreloader] Preloading ${key}...`);
            
            const response = await fetch(config.endpoint, {
                headers: {
                    'Authorization': authHeader,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`Failed to fetch ${key}: ${response.status}`);
            }

            const data = await response.json();
            
            // Cache in IndexedDB
            if (window.offlineDB) {
                await window.offlineDB.cacheData(key, data, 'reference', config.ttl);
                console.log(`[DataPreloader] ${key} cached successfully`);
            }
        } catch (error) {
            console.error(`[DataPreloader] Failed to preload ${key}:`, error);
        }
    }

    /**
     * Get cached data
     */
    async getCachedData(key) {
        try {
            if (window.offlineDB) {
                const data = await window.offlineDB.getCachedData(key);
                if (data) {
                    console.log(`[DataPreloader] Retrieved cached ${key}`);
                    return data;
                }
            }
            return null;
        } catch (error) {
            console.error(`[DataPreloader] Failed to get cached ${key}:`, error);
            return null;
        }
    }

    /**
     * Get products from cache
     */
    async getProducts() {
        return await this.getCachedData('products');
    }

    /**
     * Get users from cache
     */
    async getUsers() {
        return await this.getCachedData('users');
    }

    /**
     * Get categories from cache
     */
    async getCategories() {
        return await this.getCachedData('categories');
    }

    /**
     * Get settings from cache
     */
    async getSettings() {
        return await this.getCachedData('settings');
    }

    /**
     * Get dispatchers from cache
     */
    async getDispatchers() {
        return await this.getCachedData('dispatchers');
    }

    /**
     * Refresh specific data type
     */
    async refreshData(key) {
        if (!this.preloadConfig[key]) {
            console.error(`[DataPreloader] Unknown data type: ${key}`);
            return;
        }

        const authHeader = await this.getAuthHeader();
        if (authHeader) {
            await this.preloadData(key, this.preloadConfig[key], authHeader);
        }
    }

    /**
     * Get auth header
     */
    async getAuthHeader() {
        if (window.authManager) {
            return await window.authManager.getAuthHeader();
        }
        return null;
    }

    /**
     * Get last preload time
     */
    async getLastPreloadTime() {
        try {
            if (window.offlineDB) {
                const metadata = await window.offlineDB.getSyncMetadata('last_preload_time');
                return metadata ? parseInt(metadata) : null;
            }
        } catch (error) {
            console.error('[DataPreloader] Failed to get last preload time:', error);
        }
        return null;
    }

    /**
     * Set last preload time
     */
    async setLastPreloadTime(timestamp) {
        try {
            if (window.offlineDB) {
                await window.offlineDB.setSyncMetadata('last_preload_time', timestamp.toString());
            }
        } catch (error) {
            console.error('[DataPreloader] Failed to set last preload time:', error);
        }
    }

    /**
     * Clear all cached data
     */
    async clearCache() {
        try {
            if (window.offlineDB) {
                const keys = Object.keys(this.preloadConfig);
                for (const key of keys) {
                    await window.offlineDB.removeCachedData(key);
                }
                console.log('[DataPreloader] Cache cleared');
            }
        } catch (error) {
            console.error('[DataPreloader] Failed to clear cache:', error);
        }
    }

    /**
     * Trigger preload on login
     */
    async onLogin() {
        console.log('[DataPreloader] Login detected, triggering preload...');
        await this.preloadAllData();
    }
}

// Global singleton instance
const dataPreloader = new DataPreloader();

// Expose to window
if (typeof window !== 'undefined') {
    window.dataPreloader = dataPreloader;
}

// Auto-initialize when DOM is ready
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        // Wait for auth manager to be ready
        setTimeout(() => {
            dataPreloader.init();
        }, 1000);
    });
}

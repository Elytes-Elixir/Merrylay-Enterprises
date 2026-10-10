/**
 * Data Sync Manager for Merrylay Enterprises Enterprises
 * Handles data synchronization between offline and online modes
 */

const DataSync = {
    // Storage keys
    STORAGE_KEYS: {
        PENDING_SALES: 'merrylay_pending_sales',
        PENDING_DISPATCHES: 'merrylay_pending_dispatches',
        PENDING_RETURNS: 'merrylay_pending_returns',
        PENDING_CREDIT_SETTLEMENTS: 'merrylay_pending_credit_settlements',
        LAST_SYNC: 'merrylay_last_sync'
    },

    // Initialize
    init: function() {
        this.setupEventListeners();
        this.checkPendingSync();
        console.log('Data Sync Manager initialized');
    },

    // Setup event listeners
    setupEventListeners: function() {
        window.addEventListener('online', () => {
            console.log('Back online - checking for pending sync');
            this.syncPendingData();
        });

        window.addEventListener('offline', () => {
            console.log('Gone offline - data will be stored locally');
        });
    },

    // Store data locally
    storePendingData: function(type, data) {
        const key = this.STORAGE_KEYS[type];
        if (!key) {
            console.error('Invalid data type:', type);
            return false;
        }

        try {
            let pendingData = JSON.parse(localStorage.getItem(key) || '[]');
            pendingData.push({
                data: data,
                timestamp: new Date().toISOString()
            });
            localStorage.setItem(key, JSON.stringify(pendingData));
            console.log('Stored pending data:', type);
            return true;
        } catch (error) {
            console.error('Error storing pending data:', error);
            return false;
        }
    },

    // Get pending data
    getPendingData: function(type) {
        const key = this.STORAGE_KEYS[type];
        if (!key) {
            console.error('Invalid data type:', type);
            return [];
        }

        try {
            return JSON.parse(localStorage.getItem(key) || '[]');
        } catch (error) {
            console.error('Error getting pending data:', error);
            return [];
        }
    },

    // Check for pending sync
    checkPendingSync: function() {
        if (navigator.onLine) {
            this.syncPendingData();
        }
    },

    // Sync pending data
    syncPendingData: async function() {
        const types = [
            'PENDING_SALES',
            'PENDING_DISPATCHES',
            'PENDING_RETURNS',
            'PENDING_CREDIT_SETTLEMENTS'
        ];

        for (const type of types) {
            const pendingData = this.getPendingData(type);
            if (pendingData.length > 0) {
                console.log('Syncing pending data:', type, pendingData.length, 'items');
                await this.syncData(type, pendingData);
            }
        }

        // Update last sync time
        localStorage.setItem(this.STORAGE_KEYS.LAST_SYNC, new Date().toISOString());
    },

    // Sync data to server
    syncData: async function(type, data) {
        try {
            // Map storage key to API endpoint
            const endpoints = {
                'PENDING_SALES': '/merrylay_system/api/sync/sales.php',
                'PENDING_DISPATCHES': '/merrylay_system/api/sync/dispatches.php',
                'PENDING_RETURNS': '/merrylay_system/api/sync/returns.php',
                'PENDING_CREDIT_SETTLEMENTS': '/merrylay_system/api/sync/credit_settlements.php'
            };

            const endpoint = endpoints[type];
            if (!endpoint) {
                console.error('No endpoint for type:', type);
                return;
            }

            // Send data to server
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ data: data })
            });

            if (response.ok) {
                // Clear pending data on success
                const key = this.STORAGE_KEYS[type];
                localStorage.removeItem(key);
                console.log('Sync successful for:', type);
                
                // Show success notification
                this.showNotification('Data synced successfully', 'success');
            } else {
                console.error('Sync failed for:', type, response.status);
                this.showNotification('Sync failed - data will retry', 'error');
            }
        } catch (error) {
            console.error('Error syncing data:', error);
            this.showNotification('Sync error - will retry later', 'error');
        }
    },

    // Show notification
    showNotification: function(message, type) {
        // Create notification element
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            z-index: 10000;
            font-size: 14px;
            font-weight: 500;
            background: ${type === 'success' ? '#10b981' : '#dc3545'};
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            animation: slideIn 0.3s ease-out;
        `;
        notification.innerHTML = message;

        // Add animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);

        document.body.appendChild(notification);

        // Remove after 5 seconds
        setTimeout(() => {
            notification.style.animation = 'slideIn 0.3s ease-out reverse';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    },

    // Get sync status
    getSyncStatus: function() {
        const lastSync = localStorage.getItem(this.STORAGE_KEYS.LAST_SYNC);
        const pendingCounts = {
            sales: this.getPendingData('PENDING_SALES').length,
            dispatches: this.getPendingData('PENDING_DISPATCHES').length,
            returns: this.getPendingData('PENDING_RETURNS').length,
            credit_settlements: this.getPendingData('PENDING_CREDIT_SETTLEMENTS').length
        };

        return {
            lastSync: lastSync ? new Date(lastSync) : null,
            pending: pendingCounts,
            totalPending: Object.values(pendingCounts).reduce((a, b) => a + b, 0)
        };
    },

    // Clear all pending data
    clearPendingData: function() {
        Object.values(this.STORAGE_KEYS).forEach(key => {
            if (key !== this.STORAGE_KEYS.LAST_SYNC) {
                localStorage.removeItem(key);
            }
        });
        console.log('All pending data cleared');
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    DataSync.init();
});

/**
 * Conflict Resolution Manager for Offline-First App
 * 
 * Handles detection and resolution of data conflicts during synchronization
 */

class ConflictResolver {
    constructor() {
        this.conflicts = [];
        this.resolutionStrategies = {
            'last_write_wins': this.lastWriteWins.bind(this),
            'manual': this.manualResolution.bind(this),
            'merge': this.mergeResolution.bind(this)
        };
    }

    /**
     * Detect conflicts between local and server data
     */
    async detectConflicts(localData, serverData, recordType) {
        const conflicts = [];

        for (const localRecord of localData) {
            const serverRecord = serverData.find(s => 
                this.getRecordId(s) === this.getRecordId(localRecord)
            );

            if (serverRecord) {
                // Check if both have been modified since last sync
                if (this.isModified(localRecord, serverRecord)) {
                    conflicts.push({
                        type: recordType,
                        local: localRecord,
                        server: serverRecord,
                        strategy: this.determineStrategy(recordType)
                    });
                }
            }
        }

        return conflicts;
    }

    /**
     * Get unique record ID
     */
    getRecordId(record) {
        if (record.id) return record.id;
        if (record.receipt_number) return record.receipt_number;
        if (record.dispatch_number) return record.dispatch_number;
        return null;
    }

    /**
     * Check if record has been modified
     */
    isModified(localRecord, serverRecord) {
        const localTimestamp = localRecord.updated_at || localRecord.created_at;
        const serverTimestamp = serverRecord.updated_at || serverRecord.created_at;

        if (!localTimestamp || !serverTimestamp) {
            return false;
        }

        const localTime = new Date(localTimestamp).getTime();
        const serverTime = new Date(serverTimestamp).getTime();

        // If local timestamp is newer than server, it was modified locally
        return localTime > serverTime;
    }

    /**
     * Determine resolution strategy based on record type
     */
    determineStrategy(recordType) {
        const strategies = {
            'sales': 'last_write_wins',
            'dispatches': 'last_write_wins',
            'returns': 'last_write_wins',
            'credit_settlements': 'last_write_wins',
            'products': 'manual',
            'users': 'manual'
        };

        return strategies[recordType] || 'last_write_wins';
    }

    /**
     * Resolve conflicts using determined strategy
     */
    async resolveConflict(conflict) {
        const strategy = this.resolutionStrategies[conflict.strategy];
        
        if (!strategy) {
            console.error(`[ConflictResolver] Unknown strategy: ${conflict.strategy}`);
            return null;
        }

        return await strategy(conflict);
    }

    /**
     * Last-write-wins strategy
     */
    lastWriteWins(conflict) {
        const localTime = new Date(conflict.local.updated_at || conflict.local.created_at).getTime();
        const serverTime = new Date(conflict.server.updated_at || conflict.server.created_at).getTime();

        // Return the record with the most recent timestamp
        const winner = localTime > serverTime ? conflict.local : conflict.server;
        
        console.log(`[ConflictResolver] Last-write-wins: ${conflict.type} - ${this.getRecordId(winner)}`);
        
        return {
            resolved: true,
            data: winner,
            strategy: 'last_write_wins',
            winner: localTime > serverTime ? 'local' : 'server'
        };
    }

    /**
     * Manual resolution strategy
     */
    manualResolution(conflict) {
        console.log(`[ConflictResolver] Manual resolution required: ${conflict.type} - ${this.getRecordId(conflict.local)}`);
        
        // Store conflict for manual resolution
        this.conflicts.push(conflict);
        
        // Dispatch event for UI to handle
        const event = new CustomEvent('conflict-detected', {
            detail: conflict
        });
        document.dispatchEvent(event);
        
        return {
            resolved: false,
            requiresManual: true,
            conflict: conflict
        };
    }

    /**
     * Merge resolution strategy (for future implementation)
     */
    mergeResolution(conflict) {
        console.log(`[ConflictResolver] Merge resolution: ${conflict.type} - ${this.getRecordId(conflict.local)}`);
        
        // Simple merge - combine non-conflicting fields
        const merged = { ...conflict.server };
        
        // Override with local values for specific fields
        const localOverrides = ['notes', 'description'];
        localOverrides.forEach(field => {
            if (conflict.local[field]) {
                merged[field] = conflict.local[field];
            }
        });
        
        return {
            resolved: true,
            data: merged,
            strategy: 'merge'
        };
    }

    /**
     * Get pending conflicts
     */
    getPendingConflicts() {
        return this.conflicts;
    }

    /**
     * Resolve conflict manually with user choice
     */
    async resolveManually(conflictIndex, choice) {
        if (conflictIndex < 0 || conflictIndex >= this.conflicts.length) {
            return false;
        }

        const conflict = this.conflicts[conflictIndex];
        const resolvedData = choice === 'local' ? conflict.local : conflict.server;

        // Remove from pending conflicts
        this.conflicts.splice(conflictIndex, 1);

        return {
            resolved: true,
            data: resolvedData,
            strategy: 'manual',
            winner: choice
        };
    }

    /**
     * Clear all pending conflicts
     */
    clearConflicts() {
        this.conflicts = [];
    }

    /**
     * Check for conflicts before sync
     */
    async preSyncCheck(localData, recordType) {
        try {
            // Fetch server data for comparison
            const authHeader = await this.getAuthHeader();
            if (!authHeader) {
                console.log('[ConflictResolver] No auth header, skipping conflict check');
                return { hasConflicts: false };
            }

            const endpoint = `/merrylay_system/api/data/${recordType}.php`;
            const response = await fetch(endpoint, {
                headers: {
                    'Authorization': authHeader,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                console.log('[ConflictResolver] Failed to fetch server data for conflict check');
                return { hasConflicts: false };
            }

            const serverData = await response.json();
            const conflicts = await this.detectConflicts(localData, serverData.data, recordType);

            return {
                hasConflicts: conflicts.length > 0,
                conflicts: conflicts
            };
        } catch (error) {
            console.error('[ConflictResolver] Pre-sync check failed:', error);
            return { hasConflicts: false };
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
}

// Global singleton instance
const conflictResolver = new ConflictResolver();

// Expose to window
if (typeof window !== 'undefined') {
    window.conflictResolver = conflictResolver;
}

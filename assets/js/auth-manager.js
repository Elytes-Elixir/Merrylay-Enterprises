/**
 * Authentication Manager for Offline-First App
 * 
 * Handles JWT token storage, retrieval, and refresh for offline authentication
 */

class AuthManager {
    constructor() {
        this.tokens = null;
        this.refreshPromise = null;
        this.init();
    }

    /**
     * Initialize authentication manager
     */
    async init() {
        try {
            // Load tokens from IndexedDB
            if (window.offlineDB) {
                this.tokens = await window.offlineDB.getCachedData('auth_tokens');
                if (this.tokens) {
                    console.log('[AuthManager] Tokens loaded from IndexedDB');
                    // Check if access token needs refresh
                    if (this.isAccessTokenExpired()) {
                        await this.refreshAccessToken();
                    }
                }
            }
        } catch (error) {
            console.error('[AuthManager] Failed to initialize:', error);
        }
    }

    /**
     * Store authentication tokens
     */
    async storeTokens(tokens) {
        try {
            this.tokens = tokens;
            if (window.offlineDB) {
                await window.offlineDB.cacheData('auth_tokens', tokens, 'auth', 604800000);
                console.log('[AuthManager] Tokens stored in IndexedDB');
            }
            // Also store in localStorage as backup
            localStorage.setItem('auth_tokens', JSON.stringify(tokens));
        } catch (error) {
            console.error('[AuthManager] Failed to store tokens:', error);
        }
    }

    /**
     * Get access token
     */
    getAccessToken() {
        return this.tokens?.access_token || null;
    }

    /**
     * Get refresh token
     */
    getRefreshToken() {
        return this.tokens?.refresh_token || null;
    }

    /**
     * Get current user data
     */
    getCurrentUser() {
        if (!this.tokens) {
            return null;
        }
        return {
            user_id: this.tokens.user_id,
            username: this.tokens.username,
            full_name: this.tokens.full_name,
            role: this.tokens.role
        };
    }

    /**
     * Check if user is authenticated
     */
    isAuthenticated() {
        return this.tokens !== null && !this.isAccessTokenExpired();
    }

    /**
     * Check if access token is expired
     */
    isAccessTokenExpired() {
        if (!this.tokens || !this.tokens.expires_at) {
            return true;
        }
        return Date.now() >= this.tokens.expires_at;
    }

    /**
     * Refresh access token
     */
    async refreshAccessToken() {
        // Prevent multiple simultaneous refresh attempts
        if (this.refreshPromise) {
            return this.refreshPromise;
        }

        this.refreshPromise = this.performTokenRefresh();
        
        try {
            await this.refreshPromise;
        } finally {
            this.refreshPromise = null;
        }
    }

    /**
     * Perform token refresh API call
     */
    async performTokenRefresh() {
        try {
            const refreshToken = this.getRefreshToken();
            if (!refreshToken) {
                throw new Error('No refresh token available');
            }

            const response = await fetch(this.getAppUrl('api/auth/refresh.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    refresh_token: refreshToken
                })
            });

            if (!response.ok) {
                throw new Error('Token refresh failed');
            }

            const data = await response.json();
            
            if (data.success && data.tokens) {
                await this.storeTokens(data.tokens);
                console.log('[AuthManager] Access token refreshed successfully');
                return data.tokens;
            } else {
                throw new Error('Invalid refresh response');
            }
        } catch (error) {
            console.error('[AuthManager] Token refresh failed:', error);
            // Clear tokens on refresh failure
            await this.logout();
            throw error;
        }
    }

    /**
     * Get authorization header for API requests
     */
    async getAuthHeader() {
        // Check if token needs refresh
        if (this.isAccessTokenExpired()) {
            try {
                await this.refreshAccessToken();
            } catch (error) {
                // If refresh fails, user needs to re-login
                return null;
            }
        }

        const token = this.getAccessToken();
        if (token) {
            return `Bearer ${token}`;
        }
        return null;
    }

    getAppUrl(path) {
        return new URL(path, document.baseURI).href;
    }

    /**
     * Make authenticated API request
     */
    async authenticatedFetch(url, options = {}) {
        // Get auth header
        const authHeader = await this.getAuthHeader();
        
        if (!authHeader) {
            // Redirect to login if not authenticated
            window.location.href = this.getAppUrl('auth/login.php');
            return null;
        }

        // Add authorization header
        const headers = {
            ...options.headers,
            'Authorization': authHeader
        };

        try {
            const response = await fetch(url, {
                ...options,
                headers
            });

            // Handle 401 Unauthorized - token might be expired
            if (response.status === 401) {
                try {
                    await this.refreshAccessToken();
                    const newAuthHeader = await this.getAuthHeader();
                    if (newAuthHeader) {
                        // Retry with new token
                        return await fetch(url, {
                            ...options,
                            headers: {
                                ...options.headers,
                                'Authorization': newAuthHeader
                            }
                        });
                    }
                } catch (refreshError) {
                    // Refresh failed, redirect to login
                    window.location.href = this.getAppUrl('auth/login.php');
                    return null;
                }
            }

            return response;
        } catch (error) {
            console.error('[AuthManager] Authenticated fetch failed:', error);
            throw error;
        }
    }

    /**
     * Logout user
     */
    async logout() {
        try {
            // Clear tokens from IndexedDB
            if (window.offlineDB) {
                await window.offlineDB.removeCachedData('auth_tokens');
            }
            // Clear from localStorage
            localStorage.removeItem('auth_tokens');
            // Clear in-memory tokens
            this.tokens = null;
            
            console.log('[AuthManager] User logged out');
        } catch (error) {
            console.error('[AuthManager] Logout failed:', error);
        }
    }

    /**
     * Check authentication status and redirect if needed
     */
    async requireAuth() {
        if (!this.isAuthenticated()) {
            // Try to load from localStorage as fallback
            const localTokens = localStorage.getItem('auth_tokens');
            if (localTokens) {
                try {
                    const parsed = JSON.parse(localTokens);
                    await this.storeTokens(parsed);
                    if (this.isAuthenticated()) {
                        return true;
                    }
                } catch (error) {
                    console.error('[AuthManager] Failed to load tokens from localStorage:', error);
                }
            }
            
            // Redirect to login if not authenticated
            window.location.href = this.getAppUrl('auth/login.php');
            return false;
        }
        return true;
    }
}

// Global singleton instance
const authManager = new AuthManager();

// Expose to window
if (typeof window !== 'undefined') {
    window.authManager = authManager;
}

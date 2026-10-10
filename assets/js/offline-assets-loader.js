/**
 * Offline Assets Loader
 * Intelligently loads assets with fallback support for offline mode
 */

class OfflineAssetsLoader {
    constructor() {
        this.loadedAssets = new Map();
        this.failedAssets = new Set();
        this.isOnline = navigator.onLine;
        this.offlineTimeout = 5000; // 5 second timeout for offline detection
        
        this.initEventListeners();
    }

    /**
     * Initialize online/offline event listeners
     */
    initEventListeners() {
        window.addEventListener('online', () => {
            console.log('[AssetsLoader] Connection restored');
            this.isOnline = true;
            this.retryFailedAssets();
        });

        window.addEventListener('offline', () => {
            console.log('[AssetsLoader] Connection lost');
            this.isOnline = false;
        });
    }

    /**
     * Load CSS file with fallback
     */
    loadCSS(url, fallbackId = null) {
        return new Promise((resolve, reject) => {
            // Check if already loaded
            if (this.loadedAssets.has(url)) {
                resolve(this.loadedAssets.get(url));
                return;
            }

            if (this.failedAssets.has(url)) {
                console.warn('[AssetsLoader] CSS previously failed:', url);
                if (fallbackId) {
                    this.injectFallbackStyles(fallbackId);
                }
                resolve(null);
                return;
            }

            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = url;
            link.id = fallbackId || `css-${Date.now()}`;

            const timeout = setTimeout(() => {
                if (!this.isOnline) {
                    console.warn('[AssetsLoader] CSS load timeout (offline):', url);
                    this.failedAssets.add(url);
                    if (fallbackId) {
                        this.injectFallbackStyles(fallbackId);
                    }
                    resolve(null);
                }
            }, this.offlineTimeout);

            link.onload = () => {
                clearTimeout(timeout);
                this.loadedAssets.set(url, link);
                console.log('[AssetsLoader] CSS loaded:', url);
                resolve(link);
            };

            link.onerror = () => {
                clearTimeout(timeout);
                console.error('[AssetsLoader] CSS load failed:', url);
                this.failedAssets.add(url);
                if (fallbackId) {
                    this.injectFallbackStyles(fallbackId);
                }
                resolve(null);
            };

            document.head.appendChild(link);
        });
    }

    /**
     * Load JavaScript file with fallback
     */
    loadJS(url, attributes = {}) {
        return new Promise((resolve, reject) => {
            // Check if already loaded
            if (this.loadedAssets.has(url)) {
                resolve(this.loadedAssets.get(url));
                return;
            }

            if (this.failedAssets.has(url)) {
                console.warn('[AssetsLoader] JS previously failed:', url);
                resolve(null);
                return;
            }

            const script = document.createElement('script');
            script.src = url;
            script.async = attributes.async !== false;
            script.defer = attributes.defer === true;
            script.type = 'text/javascript';

            Object.keys(attributes).forEach(key => {
                if (key !== 'async' && key !== 'defer') {
                    script.setAttribute(key, attributes[key]);
                }
            });

            const timeout = setTimeout(() => {
                if (!this.isOnline) {
                    console.warn('[AssetsLoader] JS load timeout (offline):', url);
                    this.failedAssets.add(url);
                    resolve(null);
                }
            }, this.offlineTimeout);

            script.onload = () => {
                clearTimeout(timeout);
                this.loadedAssets.set(url, script);
                console.log('[AssetsLoader] JS loaded:', url);
                resolve(script);
            };

            script.onerror = () => {
                clearTimeout(timeout);
                console.error('[AssetsLoader] JS load failed:', url);
                this.failedAssets.add(url);
                resolve(null);
            };

            document.body.appendChild(script);
        });
    }

    /**
     * Load multiple assets in parallel
     */
    async loadMultiple(assets) {
        const results = await Promise.allSettled(
            assets.map(asset => {
                if (asset.type === 'css') {
                    return this.loadCSS(asset.url, asset.fallbackId);
                } else if (asset.type === 'js') {
                    return this.loadJS(asset.url, asset.attributes);
                } else {
                    return Promise.reject(new Error('Unknown asset type: ' + asset.type));
                }
            })
        );

        return results.map((result, index) => ({
            asset: assets[index],
            success: result.status === 'fulfilled',
            value: result.status === 'fulfilled' ? result.value : null,
            error: result.status === 'rejected' ? result.reason : null
        }));
    }

    /**
     * Inject fallback styles when CSS fails
     */
    injectFallbackStyles(fallbackId) {
        if (document.getElementById(fallbackId + '-fallback')) {
            return; // Already injected
        }

        const style = document.createElement('style');
        style.id = fallbackId + '-fallback';
        style.textContent = this.getFallbackCSS();
        document.head.appendChild(style);
        console.log('[AssetsLoader] Injected fallback styles:', fallbackId);
    }

    /**
     * Get minimal fallback CSS
     */
    getFallbackCSS() {
        return `
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                background-color: #f5f5f5;
                color: #333;
                line-height: 1.5;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 20px;
            }

            .btn {
                display: inline-block;
                padding: 8px 16px;
                margin: 4px;
                background-color: #667eea;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                text-decoration: none;
                transition: background-color 0.2s;
            }

            .btn:hover {
                background-color: #5568d3;
            }

            .btn-secondary {
                background-color: #6c757d;
            }

            .btn-secondary:hover {
                background-color: #5a6268;
            }

            .btn-danger {
                background-color: #dc3545;
            }

            .btn-danger:hover {
                background-color: #c82333;
            }

            .alert {
                padding: 12px 16px;
                margin-bottom: 16px;
                border-radius: 4px;
                border-left: 4px solid #ccc;
            }

            .alert-info {
                background-color: #d1ecf1;
                border-color: #0c5460;
                color: #0c5460;
            }

            .alert-success {
                background-color: #d4edda;
                border-color: #155724;
                color: #155724;
            }

            .alert-warning {
                background-color: #fff3cd;
                border-color: #856404;
                color: #856404;
            }

            .alert-danger {
                background-color: #f8d7da;
                border-color: #721c24;
                color: #721c24;
            }

            .card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 16px;
                margin-bottom: 16px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }

            .table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 16px;
            }

            .table th {
                background-color: #f8f9fa;
                border-bottom: 2px solid #dee2e6;
                padding: 12px;
                text-align: left;
                font-weight: 600;
            }

            .table td {
                border-bottom: 1px solid #dee2e6;
                padding: 12px;
            }

            .table tbody tr:hover {
                background-color: #f5f5f5;
            }

            .form-group {
                margin-bottom: 16px;
            }

            .form-label {
                display: block;
                margin-bottom: 6px;
                font-weight: 500;
            }

            .form-control {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                font-size: 14px;
                font-family: inherit;
            }

            .form-control:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }

            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                width: 250px;
                height: 100vh;
                background-color: #2d3748;
                color: white;
                overflow-y: auto;
                z-index: 100;
            }

            .main-content {
                margin-left: 250px;
                padding: 20px;
            }

            .nav-link {
                display: block;
                padding: 12px 16px;
                color: #cbd5e0;
                text-decoration: none;
                border-left: 3px solid transparent;
                transition: all 0.2s;
            }

            .nav-link:hover,
            .nav-link.active {
                background-color: #1a202c;
                border-left-color: #667eea;
                color: white;
            }

            @media (max-width: 768px) {
                .sidebar {
                    width: 100%;
                    height: auto;
                }

                .main-content {
                    margin-left: 0;
                }
            }

            .offline-mode {
                opacity: 0.9;
            }

            .offline-mode::before {
                content: "📡 Offline Mode";
                position: fixed;
                top: 10px;
                right: 10px;
                background: #f59e0b;
                color: white;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
                z-index: 9999;
            }
        `;
    }

    /**
     * Retry loading failed assets
     */
    async retryFailedAssets() {
        const failed = Array.from(this.failedAssets);
        if (failed.length === 0) return;

        console.log('[AssetsLoader] Retrying', failed.length, 'failed assets');

        for (const url of failed) {
            this.failedAssets.delete(url);
            
            if (url.endsWith('.css')) {
                await this.loadCSS(url);
            } else if (url.endsWith('.js')) {
                await this.loadJS(url);
            }
        }
    }

    /**
     * Get loading statistics
     */
    getStats() {
        return {
            loaded: this.loadedAssets.size,
            failed: this.failedAssets.size,
            isOnline: this.isOnline
        };
    }

    /**
     * Preload critical assets for better offline experience
     */
    async preloadCriticalAssets() {
        const criticalAssets = [
            { type: 'css', url: '/merrylay_system/assets/css/unified-design-system.css' },
            { type: 'js', url: '/merrylay_system/assets/js/offline-handler.js' },
            { type: 'js', url: '/merrylay_system/assets/js/offline-database.js' },
            { type: 'js', url: '/merrylay_system/assets/js/offline-sync-manager.js' }
        ];

        return this.loadMultiple(criticalAssets);
    }
}

// Global singleton
const assetsLoader = new OfflineAssetsLoader();

// Expose to window
if (typeof window !== 'undefined') {
    window.assetsLoader = assetsLoader;
}

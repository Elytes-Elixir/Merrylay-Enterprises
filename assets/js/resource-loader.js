/**
 * Resource Loader for Merrylay Enterprises Enterprises
 * Handles local/CDN fallback and offline detection
 */

const ResourceLoader = {
    // Resource configuration
    resources: {
        bootstrap: {
            css: {
                local: '../assets/vendor/bootstrap/bootstrap.min.css',
                cdn: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'
            },
            js: {
                local: '../assets/vendor/bootstrap/bootstrap.bundle.min.js',
                cdn: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'
            }
        },
        fontawesome: {
            css: {
                local: '../assets/vendor/fontawesome/css/all.min.css',
                cdn: 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
            }
        },
        jquery: {
            js: {
                local: '../assets/vendor/jquery/jquery.min.js',
                cdn: 'https://code.jquery.com/jquery-3.7.0.min.js'
            }
        },
        fonts: {
            inter: {
                local: '../assets/fonts/inter/inter.css',
                cdn: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'
            }
        }
    },

    // Offline detection
    isOnline: navigator.onLine,
    
    // Load resource with fallback
    loadResource: function(type, resource, callback) {
        const config = this.resources[resource];
        if (!config) {
            console.error('Resource not found:', resource);
            return;
        }

        const resourceConfig = config[type];
        if (!resourceConfig) {
            console.error('Resource type not found:', type, resource);
            return;
        }

        // Try local first
        this.testResource(resourceConfig.local, (localExists) => {
            if (localExists) {
                console.log('Using local resource:', resourceConfig.local);
                callback(resourceConfig.local, true);
            } else if (this.isOnline) {
                console.log('Using CDN resource:', resourceConfig.cdn);
                callback(resourceConfig.cdn, false);
            } else {
                console.error('No resource available for:', resource);
                callback(null, false);
            }
        });
    },

    // Test if resource exists
    testResource: function(url, callback) {
        const xhr = new XMLHttpRequest();
        xhr.open('HEAD', url, true);
        xhr.onload = function() {
            callback(xhr.status >= 200 && xhr.status < 400);
        };
        xhr.onerror = function() {
            callback(false);
        };
        xhr.send();
    },

    // Load CSS
    loadCSS: function(resource, callback) {
        this.loadResource('css', resource, (url, isLocal) => {
            if (url) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = url;
                link.onload = () => callback && callback(true);
                link.onerror = () => callback && callback(false);
                document.head.appendChild(link);
            } else {
                callback && callback(false);
            }
        });
    },

    // Load JS
    loadJS: function(resource, callback) {
        this.loadResource('js', resource, (url, isLocal) => {
            if (url) {
                const script = document.createElement('script');
                script.src = url;
                script.onload = () => callback && callback(true);
                script.onerror = () => callback && callback(false);
                document.head.appendChild(script);
            } else {
                callback && callback(false);
            }
        });
    },

    // Load all resources
    loadAll: function(callback) {
        const resources = [
            { type: 'css', resource: 'bootstrap' },
            { type: 'js', resource: 'bootstrap' },
            { type: 'css', resource: 'fontawesome' },
            { type: 'js', resource: 'jquery' },
            { type: 'css', resource: 'fonts' }
        ];

        let loaded = 0;
        const total = resources.length;

        resources.forEach(({ type, resource }) => {
            if (type === 'css') {
                this.loadCSS(resource, (success) => {
                    loaded++;
                    if (loaded === total) callback && callback();
                });
            } else {
                this.loadJS(resource, (success) => {
                    loaded++;
                    if (loaded === total) callback && callback();
                });
            }
        });
    },

    // Initialize offline detection
    initOfflineDetection: function() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.updateOfflineIndicator();
            console.log('System is online');
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.updateOfflineIndicator();
            console.log('System is offline');
        });

        this.updateOfflineIndicator();
    },

    // Update offline indicator
    updateOfflineIndicator: function() {
        let indicator = document.getElementById('offline-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'offline-indicator';
            indicator.style.cssText = `
                position: fixed;
                top: 10px;
                right: 10px;
                padding: 10px 15px;
                border-radius: 5px;
                z-index: 9999;
                font-size: 14px;
                font-weight: 600;
                display: none;
            `;
            document.body.appendChild(indicator);
        }

        if (!this.isOnline) {
            indicator.style.display = 'block';
            indicator.style.backgroundColor = '#dc3545';
            indicator.style.color = 'white';
            indicator.innerHTML = '<i class="fas fa-wifi"></i> Offline Mode';
        } else {
            indicator.style.display = 'none';
        }
    },

    // Initialize
    init: function() {
        this.initOfflineDetection();
        console.log('Resource Loader initialized');
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    ResourceLoader.init();
});

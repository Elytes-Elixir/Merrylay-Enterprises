<?php
/**
 * Footer Component for Merrylay Enterprises
 * Includes scripts and offline support initialization
 */
?>

    <!-- Bootstrap Bundle JS - Local vendor file (works offline and on isolated networks) -->
    <?php
    $appBaseUrl = defined('BASE_URL') ? BASE_URL : '';
    if (empty($appBaseUrl)) {
        $appBaseUrl = '/merrylay_system';
    }
    ?>
    <script src="<?php echo $appBaseUrl . '/assets/vendor/bootstrap/bootstrap.bundle.min.js'; ?>" defer></script>
    
    <!-- OPTIMIZED: Offline Support Handler - Deferred -->
    <script src="<?php echo BASE_URL . '/assets/js/offline-handler.js'; ?>" defer></script>
    
    <!-- Service Worker Registration -->
    <script>
        // Register service worker for offline support
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('<?php echo BASE_URL . '/sw.js'; ?>', { scope: '<?php echo BASE_URL . '/'; ?>' })
                    .then((registration) => {
                        console.log('[App] Service Worker registered successfully:', registration);
                        
                        // Check for updates periodically
                        setInterval(() => {
                            registration.update();
                        }, 60000); // Check every minute
                    })
                    .catch((error) => {
                        console.warn('[App] Service Worker registration failed:', error);
                    });
            });
        } else {
            console.warn('[App] Service Workers not supported in this browser');
        }

        // Periodically clean up old offline data
        if (window.offlineDB) {
            setInterval(async () => {
                try {
                    await window.offlineDB.clearExpiredCache();
                } catch (error) {
                    console.error('[App] Failed to cleanup cache:', error);
                }
            }, 3600000); // Every hour
        }
    </script>
    
    <?php if (isset($pageSpecificScripts)): ?>
    <?php echo $pageSpecificScripts; ?>
    <?php endif; ?>

</body>
</html>

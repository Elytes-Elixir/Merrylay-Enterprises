<?php
/**
 * Asset Configuration for Merrylay Enterprises
 * 
 * Handles asset URLs with offline/online support
 */

// Use CDN with offline fallback
define('USE_CDN_ASSETS', true);

// Font configuration
define('USE_GOOGLE_FONTS', true);
define('FALLBACK_FONT_FAMILY', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif');

// CSS Assets - Use local vendor files (works offline and on isolated networks)
function getBootstrapCSS() {
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '/merrylay_system';
    return $baseUrl . '/assets/vendor/bootstrap/bootstrap.min.css';
}

function getFontAwesomeCSS() {
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '/merrylay_system';
    return $baseUrl . '/assets/vendor/fontawesome/css/all.min.css';
}

function getGoogleFonts() {
    // Use system fonts instead of Google Fonts (faster and no external dependency)
    return '';
}

// JS Assets - Use local vendor files (works offline and on isolated networks)
function getBootstrapJS() {
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '/merrylay_system';
    return $baseUrl . '/assets/vendor/bootstrap/bootstrap.bundle.min.js';
}

function getChartJS() {
    return 'https://cdn.jsdelivr.net/npm/chart.js';
}

// Custom CSS
function getUnifiedDesignCSS() {
    return '../assets/css/unified-design-system.css';
}

function getCleanDashboardCSS() {
    return '../assets/css/clean-dashboard.css';
}

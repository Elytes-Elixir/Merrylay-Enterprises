<?php
/**
 * Initialize the application session with security settings applied first.
 */

if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}

require_once __DIR__ . '/network.php';
require_once __DIR__ . '/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

<?php
/**
 * Trip Management - Redirect to Simplified System
 */

require_once __DIR__ . '/../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Redirect to simplified trip management
header('Location: trips/index.php');
exit();

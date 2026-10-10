<?php
/**
 * Daily Finance Records Page - CONSOLIDATED
 *
 * This page redirects to dispatch/daily/index.php
 */
require_once __DIR__ . '/../config/session.php';
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
header("Location: ../dispatch/daily/index.php");
exit();

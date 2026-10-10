<?php
/**
 * Logout Script for Merrylay Enterprises
 * 
 * Handles user logout and session destruction
 */

// Initialize secure session settings before accessing the session.
require_once '../init.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit('Logout must be submitted using the provided form.');
    }

    $csrfToken = SecurityUtils::generateCSRFToken();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirm Logout - Merrylay Enterprises</title>
        <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    </head>
    <body class="container py-5">
        <main class="mx-auto" style="max-width: 32rem">
            <h1 class="h3">Confirm logout</h1>
            <p>Are you sure you want to end your session?</p>
            <form method="POST" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-danger">Log out</button>
                <a href="../dashboard/index.php" class="btn btn-secondary">Cancel</a>
            </form>
        </main>
    </body>
    </html>
    <?php
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!is_string($csrfToken) || !SecurityUtils::consumeCSRFToken($csrfToken)) {
    error_log('Rejected logout submission: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
    http_response_code(403);
    exit('Your logout form has expired. Return to the application and try again.');
}

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>


<?php
/**
 * Login Page for Merrylay Enterprises
 * 
 * Handles user authentication and session management
 */

// Initialize session security before starting the login session.
require_once '../init.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard/index.php');
    exit();
}

// Include database configuration
require_once '../config/db.php';
require_once '../config/jwt.php';
if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../config/security.php';

// Initialize variables
$error = '';
$username = '';
$csrfValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($csrfToken) && SecurityUtils::consumeCSRFToken($csrfToken);
    if (!$csrfValid) {
        error_log('Rejected login submission: invalid CSRF token');
        $error = 'Your form has expired. Please try again.';
    }
}

// Load company name from settings
$company_name = 'Merrylay Enterprises';
try {
    $stmt = executeQuery("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
    $setting = $stmt->fetch();
    if ($setting) {
        $company_name = $setting['setting_value'];
    }
} catch (PDOException $e) {
    // Use default if settings table doesn't exist or error occurs
    $company_name = 'Merrylay Enterprises';
}

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValid) {
    // Get and sanitize input
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = 'Invalid username format. Use 3-20 characters (letters, numbers, underscore).';
    } else {
        try {
            // Query database for user
            $query = "SELECT id, username, password, name, role FROM users WHERE username = ?";
            $stmt = executeQuery($query, [$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Login successful - set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                
                // Generate JWT tokens for offline access
                $jwt = getJWTManager();
                $tokens = $jwt->generateUserTokens(
                    $user['id'],
                    $user['username'],
                    $user['role'],
                    $user['name']
                );
                
                // Store tokens in session for API access
                $_SESSION['access_token'] = $tokens['access_token'];
                $_SESSION['refresh_token'] = $tokens['refresh_token'];
                $_SESSION['token_expires'] = time() + $tokens['expires_in'];
                
                // Redirect to dashboard with tokens
                header('Location: ../dashboard/index.php');
                exit();
            } else {
                $error = 'Invalid username or password.';
            }
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'Login failed. Please try again later.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($company_name); ?></title>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Text:wght@600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(255, 255, 255, 0.05) 0%, transparent 30%);
            pointer-events: none;
        }
        
        /* Drink product images */
        .drink-product {
            position: absolute;
            opacity: 0.4;
            pointer-events: none;
            filter: drop-shadow(0 4px 15px rgba(0, 0, 0, 0.2));
            transition: transform 0.3s ease;
        }
        
        .drink-product:hover {
            opacity: 0.6;
            transform: scale(1.05);
        }
        
        .product-1 {
            top: 5%;
            left: 2%;
            width: 120px;
            height: auto;
        }
        
        .product-2 {
            top: 50%;
            right: 3%;
            width: 100px;
            height: auto;
        }
        
        .product-3 {
            bottom: 8%;
            left: 8%;
            width: 90px;
            height: auto;
        }
        
        .product-4 {
            top: 15%;
            right: 8%;
            width: 110px;
            height: auto;
        }
        
        .product-5 {
            bottom: 20%;
            right: 18%;
            width: 95px;
            height: auto;
        }
        
        .product-6 {
            top: 35%;
            left: 5%;
            width: 85px;
            height: auto;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .login-header {
            background: #1e293b;
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 2rem;
        }
        .form-control:focus {
            border-color: #1e293b;
            box-shadow: 0 0 0 0.2rem rgba(30, 41, 59, 0.25);
        }
        .btn-login {
            background: #1e293b;
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 41, 59, 0.4);
        }
        .login-header h2 {
            font-family: 'Albertus MT', 'Crimson Text', serif;
            font-weight: 900;
            font-size: 2.5rem;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="login-card">
                    <div class="login-header text-center">
                        <i class="fas fa-store fa-3x mb-3"></i>
                        <h2><?php echo htmlspecialchars($company_name); ?></h2>
                        <p class="mb-0">Inventory and Sales Management System</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <i class="fas fa-user me-2"></i>Username
                                </label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo htmlspecialchars($username); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock me-2"></i>Password
                                </label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember">
                                <label class="form-check-label" for="remember">
                                    Remember me
                                </label>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-login">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-3">
                            <small class="text-muted">
                                Forgot your password? Contact administrator
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/offline-handler.js"></script>
    <script>
    // Store JWT tokens in IndexedDB for offline access
    <?php if (isset($_SESSION['access_token'])): ?>
    (function() {
        // Store tokens when page loads after successful login
        window.addEventListener('load', async function() {
            try {
                if (window.offlineDB) {
                    await window.offlineDB.cacheData('auth_tokens', {
                        access_token: '<?php echo addslashes($_SESSION['access_token']); ?>',
                        refresh_token: '<?php echo addslashes($_SESSION['refresh_token']); ?>',
                        expires_at: <?php echo $_SESSION['token_expires']; ?>,
                        user_id: <?php echo $_SESSION['user_id']; ?>,
                        username: '<?php echo addslashes($_SESSION['username']); ?>',
                        full_name: '<?php echo addslashes($_SESSION['full_name']); ?>',
                        role: '<?php echo addslashes($_SESSION['role']); ?>'
                    }, 'auth', 604800000); // 7 days TTL
                    console.log('[Auth] Tokens stored in IndexedDB for offline access');
                }
            } catch (error) {
                console.error('[Auth] Failed to store tokens:', error);
            }
        });
    })();
    <?php endif; ?>
    </script>
</body>
</html>

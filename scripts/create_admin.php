<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This command is available only from the CLI.\n");
}

if ($argc !== 2 || $argv[1] !== '--create-admin') {
    fwrite(STDERR, "Usage: php scripts/create_admin.php --create-admin\n");
    exit(2);
}

function promptLine($prompt)
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Unable to read interactive input.');
    }
    return rtrim($value, "\r\n");
}

function promptSecret()
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $command = "powershell.exe -NoProfile -Command \"\$secure = Read-Host 'Administrator password' -AsSecureString; \$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR(\$secure); try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR(\$pointer) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR(\$pointer) }\"";
        $password = shell_exec($command);
        if (!is_string($password)) {
            throw new RuntimeException('Secure password prompt is unavailable.');
        }
        return rtrim($password, "\r\n");
    }

    if (!function_exists('stream_isatty') || !stream_isatty(STDIN) || !function_exists('shell_exec')) {
        throw new RuntimeException('A secure interactive terminal is required for password input.');
    }

    $terminalMode = shell_exec('stty -g 2>/dev/null');
    if (!is_string($terminalMode) || !preg_match('/\A[0-9A-Za-z:;._-]+\s*\z/', $terminalMode)) {
        throw new RuntimeException('Unable to configure secure terminal input.');
    }

    $terminalMode = trim($terminalMode);
    fwrite(STDOUT, 'Administrator password: ');
    if (shell_exec('stty -echo 2>/dev/null') === null) {
        throw new RuntimeException('Unable to hide password input.');
    }

    try {
        $password = fgets(STDIN);
    } finally {
        shell_exec('stty ' . escapeshellarg($terminalMode) . ' 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    if ($password === false) {
        throw new RuntimeException('Unable to read password input.');
    }
    return rtrim($password, "\r\n");
}

function fail($message, $exitCode = 1)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

try {
    require_once __DIR__ . '/../config/environment.php';

    $databaseName = app_config_value('DB_NAME');
    if (!is_string($databaseName) || !preg_match('/\A[A-Za-z0-9_]+\z/', $databaseName)) {
        fail('Set a valid DB_NAME in the local environment before running this command.');
    }
    if (strcasecmp($databaseName, 'merrylay_system') === 0) {
        fail('Refusing to create an administrator in the protected merrylay_system database.');
    }

    $name = trim(promptLine('Administrator name: '));
    $username = trim(promptLine('Administrator username: '));
    $password = promptSecret();
    $confirmation = promptSecret();

    if (!preg_match('/\A.{1,100}\z/us', $name) || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        fail('Name is required and must not exceed 100 characters.');
    }
    if (!preg_match('/\A[a-zA-Z0-9_]{3,20}\z/', $username)) {
        fail('Username must be 3-20 characters using letters, numbers, and underscores.');
    }
    if (
        strlen($password) < 8
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)
    ) {
        fail('Password must be at least 8 characters and include uppercase, lowercase, a number, and a supported special character.');
    }
    if (!hash_equals($password, $confirmation)) {
        fail('Passwords do not match.');
    }

    $databaseHost = app_config_value('DB_HOST', 'localhost');
    $databasePort = app_config_port('DB_PORT', 3306);
    $databaseUser = app_config_value('DB_USER', 'root');
    $databasePassword = app_config_value('DB_PASSWORD', app_config_value('DB_PASS', ''));

    if (!is_string($databaseHost) || $databaseHost === '' || !is_string($databaseUser) || $databaseUser === '') {
        fail('Database host and user must be configured.');
    }

    $dsn = 'mysql:host=' . $databaseHost . ';port=' . $databasePort . ';dbname=' . $databaseName . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $databaseUser, $databasePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false
    ]);

    $lockName = 'merrylay_create_admin_' . substr(hash('sha256', $databaseName), 0, 32);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        fail('Unable to acquire the administrator creation lock.');
    }

    try {
        $pdo->beginTransaction();
        $existingUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1 FOR UPDATE');
        $existingUser->execute([$username]);
        if ($existingUser->fetch()) {
            $pdo->rollBack();
            fail('That username already exists.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        $insertUser = $pdo->prepare(
            'INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, ?)'
        );
        $insertUser->execute([$name, $username, $passwordHash, 'admin']);
        $pdo->commit();
        fwrite(STDOUT, "Administrator account created successfully.\n");
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        try {
            $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $releaseLock->execute([$lockName]);
        } catch (Throwable $releaseError) {
            error_log('Unable to release administrator creation lock (' . get_class($releaseError) . ', code ' . (string) $releaseError->getCode() . ').');
        }
    }
} catch (Throwable $error) {
    error_log('Administrator creation failed (' . get_class($error) . ', code ' . (string) $error->getCode() . ').');
    fail('Administrator creation failed. Check the PHP error log for technical details.');
}

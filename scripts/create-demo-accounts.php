<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== '--create') {
    fwrite(STDERR, "Usage: php scripts/create-demo-accounts.php --create\n");
    exit(2);
}
if ((getenv('ADMITFLOW_APP_ENV') ?: '') !== 'local') {
    fwrite(STDERR, "Demo account provisioning is allowed only with ADMITFLOW_APP_ENV=local.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/SecurityPolicy.php';
require_once dirname(__DIR__) . '/app/DemoAccountCredentials.php';
require_once dirname(__DIR__) . '/app/AuditLog.php';

$database = null;
$temporaryConfig = null;
$configCreated = false;
try {
    $projectRoot = realpath(dirname(__DIR__));
    $publicRoot = realpath(dirname(__DIR__) . '/public');
    $configRoot = realpath(dirname(__DIR__) . '/config');
    $configPath = DemoAccountCredentials::localPath();
    $configParent = realpath(dirname($configPath));
    if ($projectRoot === false || $publicRoot === false || $configRoot === false
        || $configParent === false || strcasecmp(rtrim($configParent, '\\/'), rtrim($configRoot, '\\/')) !== 0
        || str_starts_with($configRoot, $publicRoot . DIRECTORY_SEPARATOR)
        || !is_file($configRoot . DIRECTORY_SEPARATOR . '.htaccess')) {
        throw new RuntimeException('Private demo credential storage is not protected.');
    }
    if (file_exists($configPath)) {
        throw new RuntimeException('The local demo credential file already exists. No accounts were changed.');
    }

    $config = Database::loadConfiguration(Database::environment(), dirname(__DIR__) . '/config/database.local.php');
    if ($config['host'] !== '127.0.0.1' || (int) $config['port'] !== 3307 || $config['database'] !== 'admitflow') {
        throw new RuntimeException('The configured database target is not 127.0.0.1:3307/admitflow. No accounts were changed.');
    }
    Database::validateCredentials($config, 'local');
    $database = Database::connection();
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || $database->query('SELECT DATABASE()')->fetchColumn() !== 'admitflow') {
        throw new RuntimeException('Demo provisioning requires the approved MariaDB database.');
    }

    $requiredColumns = ['id', 'full_name', 'email', 'password_hash', 'must_change_password', 'role', 'account_status'];
    $userColumns = $database->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredColumns as $column) {
        if (!in_array($column, $userColumns, true)) throw new RuntimeException('The current users schema is not compatible.');
    }
    $profileColumns = $database->query('SHOW COLUMNS FROM student_profiles')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('user_id', $profileColumns, true)) throw new RuntimeException('The current student profile schema is not compatible.');

    $identities = DemoAccountCredentials::IDENTITIES;
    $placeholders = implode(', ', array_fill(0, count($identities), '?'));
    $existing = $database->prepare('SELECT COUNT(*) FROM users WHERE email IN (' . $placeholders . ')');
    $existing->execute(array_column($identities, 'email'));
    if ((int) $existing->fetchColumn() !== 0) {
        throw new RuntimeException('At least one proposed demo username already exists. No accounts were changed.');
    }

    $passwords = [];
    foreach ($identities as $key => $identity) {
        do {
            $password = SecurityPolicy::generatePassword(['name' => $identity['name'], 'email' => $identity['email']]);
        } while (in_array($password, $passwords, true));
        $passwords[$key] = $password;
    }

    $temporaryConfig = tempnam($configRoot, '.demo-accounts-');
    if ($temporaryConfig === false) throw new RuntimeException('Could not prepare private demo credential storage.');
    $configContents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($passwords, true) . ";\n";
    if (file_put_contents($temporaryConfig, $configContents, LOCK_EX) !== strlen($configContents) || !chmod($temporaryConfig, 0600)) {
        throw new RuntimeException('Could not write private demo credential storage.');
    }

    $database->beginTransaction();
    $insertUser = $database->prepare('INSERT INTO users (full_name, email, password_hash, must_change_password, role, account_status) VALUES (:name, :email, :password_hash, 0, :role, \'active\')');
    $userIds = [];
    foreach ($identities as $key => $identity) {
        $insertUser->execute([
            'name' => $identity['name'],
            'email' => $identity['email'],
            'password_hash' => password_hash(SecurityPolicy::normalizePassword($passwords[$key]), PASSWORD_DEFAULT),
            'role' => $identity['role'],
        ]);
        $userIds[$key] = (int) $database->lastInsertId();
        AuditLog::record($database, null, 'demo.account_created', 'user', $userIds[$key], 'success', ['role' => $identity['role']], 'notice', 'Demo account provisioning');
    }

    $profile = $database->prepare('INSERT INTO student_profiles (user_id) VALUES (:user_id)');
    $profile->execute(['user_id' => $userIds['student']]);

    if (file_exists($configPath) || !rename($temporaryConfig, $configPath)) {
        throw new RuntimeException('Could not safely activate private demo credentials.');
    }
    $temporaryConfig = null;
    $configCreated = true;
    $database->commit();

    fwrite(STDOUT, "Three demo accounts created. Public demo credentials (displayed once):\n");
    foreach ($identities as $key => $identity) {
        printf("%s\nUsername: %s\nPassword: %s\n\n", $identity['label'], $identity['email'], $passwords[$key]);
    }
} catch (Throwable $error) {
    if ($database instanceof PDO && $database->inTransaction()) $database->rollBack();
    if ($temporaryConfig !== null && is_file($temporaryConfig)) unlink($temporaryConfig);
    if ($configCreated && is_file(DemoAccountCredentials::localPath())) unlink(DemoAccountCredentials::localPath());
    fwrite(STDERR, 'Demo account creation failed (' . $error::class . '). No credentials were printed. Check the target, schema, and whether the usernames are unused.' . PHP_EOL);
    exit(1);
}
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== '--verify-existing') {
    fwrite(STDERR, "Usage: php scripts/create-demo-accounts.php --verify-existing\n");
    fwrite(STDERR, "This command only verifies existing demo accounts; it never creates or changes accounts.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/DemoAccountCredentials.php';

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

    $database = Database::connection();
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Existing demo account verification requires the configured MySQL-compatible database.');
    }
    $accounts = DemoAccountCredentials::forLogin($database);
    if (count($accounts) !== count(DemoAccountCredentials::IDENTITIES)) {
        throw new RuntimeException('Demo mode is disabled, the expected database does not match, or existing account credentials/roles do not match. No data was changed.');
    }

    fwrite(STDOUT, "Verified existing demo accounts and saved credential configuration. No accounts or records were changed.\n");
    foreach (DemoAccountCredentials::IDENTITIES as $identity) {
        printf("Verified role: %s\n", $identity['label']);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Existing demo verification failed (' . $error::class . '). No data was changed.' . PHP_EOL);
    exit(1);
}

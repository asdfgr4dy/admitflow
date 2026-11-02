<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/AutomatedBackupRunner.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/BackupRunnerPreflight.php';

if (!function_exists('request_ip')) {
    function request_ip(): ?string
    {
        return null;
    }
}

$projectRoot = dirname(__DIR__);
$environment = BackupRunnerPreflight::environment();
$historyPath = $projectRoot . '/storage/logs/automated-backup-history.jsonl';
try {
    $configuration = Database::loadConfiguration($environment, $projectRoot . '/config/database.local.php');
    BackupRunnerPreflight::validateRunnerConfiguration($configuration, $environment, $projectRoot);
} catch (Throwable) {
    AutomatedBackupRunner::recordConfigurationFailure($historyPath, 'Runner opt-in, project root, database approval, or account configuration failed preflight.');
    fwrite(STDERR, "Backup runner configuration was refused by preflight.\n");
    exit(AutomatedBackupRunner::EXIT_CONFIGURATION);
}

$schedule = strtolower(trim((string) ($argv[1] ?? '')));
if (!in_array($schedule, ['daily', 'weekly'], true)) {
    fwrite(STDERR, "Usage: php scripts/backup-runner.php daily|weekly [--source=manual|scheduled]\n");
    exit(2);
}
$source = 'manual';
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--source=')) {
        $source = substr($argument, strlen('--source='));
    } else {
        fwrite(STDERR, "Unknown backup runner argument.\n");
        exit(2);
    }
}
if (!in_array($source, ['manual', 'scheduled'], true)) {
    fwrite(STDERR, "Backup source must be manual or scheduled.\n");
    exit(2);
}

require $projectRoot . '/app/BackupIntegrity.php';
require $projectRoot . '/app/BackupService.php';

try {
    $database = Database::connection();
    BackupRunnerPreflight::verifyConnection($database, $configuration);
    $result = AutomatedBackupRunner::execute(
        $schedule,
        $source,
        $projectRoot . '/storage/backups/automated-backup.lock',
        $historyPath,
        static fn (): PDO => $database,
        static fn (PDO $database, array $actor): int => BackupService::createDatabase($database, $actor),
        static fn (PDO $database, array $actor): int => BackupService::createFiles($database, $actor)
    );
} catch (Throwable $error) {
    AutomatedBackupRunner::recordConfigurationFailure($historyPath, 'Runner could not initialize safely (' . $error::class . ').');
    fwrite(STDERR, "Backup runner could not initialize safely.\n");
    exit(AutomatedBackupRunner::EXIT_RUNNER_FAILURE);
}

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
exit((int) $result['exit_code']);

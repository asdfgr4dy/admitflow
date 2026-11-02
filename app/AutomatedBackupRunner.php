<?php
declare(strict_types=1);

final class AutomatedBackupRunner
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_CONFIGURATION = 2;
    public const EXIT_LOCKED = 3;
    public const EXIT_BACKUP_FAILURE = 4;
    public const EXIT_RUNNER_FAILURE = 5;

    public static function execute(
        string $schedule,
        string $source,
        string $lockPath,
        string $historyPath,
        callable $connectionFactory,
        callable $databaseBackup,
        callable $fileBackup,
        ?string $executionId = null
    ): array {
        if (!in_array($schedule, ['daily', 'weekly'], true)) throw new InvalidArgumentException('Schedule must be daily or weekly.');
        if (!in_array($source, ['manual', 'scheduled'], true)) throw new InvalidArgumentException('Source must be manual or scheduled.');

        $executionId ??= bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/', $executionId)) throw new InvalidArgumentException('Execution identifier is invalid.');
        self::preparePrivateDirectory(dirname($lockPath));
        self::preparePrivateDirectory(dirname($historyPath));
        if (is_link($lockPath)) throw new RuntimeException('Backup lock file cannot be a symbolic link.');

        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new RuntimeException('Backup lock file could not be opened.');
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            self::appendHistory($historyPath, [
                'event' => 'skipped',
                'execution_id' => $executionId,
                'schedule' => $schedule,
                'source' => $source,
                'result' => 'locked',
                'started_at' => gmdate(DATE_ATOM),
                'completed_at' => gmdate(DATE_ATOM),
                'duration_ms' => 0,
                'failure_reason' => 'Another backup execution holds the lock.',
            ]);
            return ['exit_code' => self::EXIT_LOCKED, 'execution_id' => $executionId, 'result' => 'locked'];
        }

        $startedAt = gmdate(DATE_ATOM);
        $startedClock = microtime(true);
        $components = ['database' => ['status' => 'not_run'], 'files' => ['status' => 'not_run']];
        try {
            self::appendHistory($historyPath, [
                'event' => 'started',
                'execution_id' => $executionId,
                'schedule' => $schedule,
                'source' => $source,
                'started_at' => $startedAt,
            ]);

            try {
                $database = $connectionFactory();
                if (!$database instanceof PDO) throw new RuntimeException('Database connection factory returned an invalid connection.');
                $administratorQuery = $database->query("SELECT id, full_name FROM users WHERE role = 'admin' AND account_status = 'active' ORDER BY id LIMIT 1");
                $administrator = $administratorQuery->fetch(PDO::FETCH_ASSOC);
                if (!is_array($administrator)) throw new RuntimeException('An active administrator account is required for backup audit records.');
                $actor = ['id' => (int) $administrator['id'], 'full_name' => (string) $administrator['full_name'], 'role' => 'admin'];
            } catch (Throwable $error) {
                $components['database'] = ['status' => 'failed', 'failure_reason' => 'Database setup failed (' . $error::class . ').'];
                $components['files'] = ['status' => 'not_run', 'failure_reason' => 'Database setup failed before component backups began.'];
                return self::finish($historyPath, $executionId, $schedule, $source, $startedAt, $startedClock, $components);
            }

            foreach (['database' => $databaseBackup, 'files' => $fileBackup] as $component => $backup) {
                try {
                    $backupId = $backup($database, $actor);
                    $componentResult = ['status' => 'success', 'backup_id' => is_int($backupId) ? $backupId : null];
                    if (is_int($backupId) && $backupId > 0) {
                        try {
                            $metadataQuery = $database->prepare('SELECT backup_type, file_size_bytes, sha256, verification_status, created_at FROM backups WHERE id = :id');
                            $metadataQuery->execute(['id' => $backupId]);
                            $metadata = $metadataQuery->fetch(PDO::FETCH_ASSOC);
                            if (is_array($metadata)) $componentResult['backup'] = $metadata;
                        } catch (Throwable) {
                        }
                    }
                    $components[$component] = $componentResult;
                } catch (Throwable $error) {
                    $components[$component] = ['status' => 'failed', 'failure_reason' => ucfirst($component) . ' backup failed (' . $error::class . ').'];
                }
            }

            return self::finish($historyPath, $executionId, $schedule, $source, $startedAt, $startedClock, $components);
        } catch (Throwable $error) {
            $result = [
                'exit_code' => self::EXIT_RUNNER_FAILURE,
                'execution_id' => $executionId,
                'result' => 'failed',
                'failure_reason' => 'Backup runner failed (' . $error::class . ').',
            ];
            try {
                self::appendHistory($historyPath, [
                    'event' => 'runner_failed',
                    'execution_id' => $executionId,
                    'schedule' => $schedule,
                    'source' => $source,
                    'started_at' => $startedAt,
                    'completed_at' => gmdate(DATE_ATOM),
                    'duration_ms' => (int) round((microtime(true) - $startedClock) * 1000),
                    'result' => 'failed',
                    'failure_reason' => $result['failure_reason'],
                ]);
            } catch (Throwable) {
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function finish(string $historyPath, string $executionId, string $schedule, string $source, string $startedAt, float $startedClock, array $components): array
    {
        $success = $components['database']['status'] === 'success' && $components['files']['status'] === 'success';
        $result = [
            'exit_code' => $success ? self::EXIT_SUCCESS : self::EXIT_BACKUP_FAILURE,
            'execution_id' => $executionId,
            'result' => $success ? 'success' : 'failed',
            'components' => $components,
        ];
        self::appendHistory($historyPath, [
            'event' => 'completed',
            'execution_id' => $executionId,
            'schedule' => $schedule,
            'source' => $source,
            'started_at' => $startedAt,
            'completed_at' => gmdate(DATE_ATOM),
            'duration_ms' => (int) round((microtime(true) - $startedClock) * 1000),
            'result' => $result['result'],
            'components' => $components,
        ]);
        return $result;
    }

    private static function preparePrivateDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Backup runner storage directory is unavailable.');
        }
        $resolvedDirectory = realpath($directory);
        $publicRoot = realpath(dirname(__DIR__) . '/public');
        if ($resolvedDirectory === false || $publicRoot === false
            || strcasecmp(rtrim($resolvedDirectory, DIRECTORY_SEPARATOR), rtrim($publicRoot, DIRECTORY_SEPARATOR)) === 0
            || str_starts_with(strtolower($resolvedDirectory . DIRECTORY_SEPARATOR), strtolower(rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Backup runner storage must remain outside the public web root.');
        }
        @chmod($resolvedDirectory, 0700);
    }

    private static function appendHistory(string $historyPath, array $event): void
    {
        if (is_link($historyPath)) throw new RuntimeException('Backup run history cannot be a symbolic link.');
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($line)) throw new RuntimeException('Backup run history event could not be serialized.');
        $handle = @fopen($historyPath, 'ab');
        if ($handle === false) throw new RuntimeException('Backup run history could not be opened.');
        @chmod($historyPath, 0600);
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $line . PHP_EOL) === false || !fflush($handle)) {
                throw new RuntimeException('Backup run history could not be written.');
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    public static function recentHistory(string $historyPath, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100 || is_link($historyPath) || !is_file($historyPath) || !is_readable($historyPath)) return [];
        $size = filesize($historyPath);
        if (!is_int($size) || $size < 1) return [];
        $handle = fopen($historyPath, 'rb');
        if ($handle === false) return [];
        $offset = max(0, $size - 1048576);
        if ($offset > 0) fseek($handle, $offset);
        $contents = stream_get_contents($handle);
        fclose($handle);
        if (!is_string($contents)) return [];
        $lines = preg_split('/\r?\n/', $contents, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($lines)) return [];
        $records = [];
        foreach (array_slice($lines, -($limit * 2)) as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && ($record['event'] ?? '') !== 'started') $records[] = $record;
        }
        return array_slice(array_reverse($records), 0, $limit);
    }

    public static function recordConfigurationFailure(string $historyPath, string $reason): void
    {
        try {
            self::preparePrivateDirectory(dirname($historyPath));
            self::appendHistory($historyPath, [
                'event' => 'rejected',
                'execution_id' => bin2hex(random_bytes(16)),
                'schedule' => 'unknown',
                'source' => 'unknown',
                'started_at' => gmdate(DATE_ATOM),
                'completed_at' => gmdate(DATE_ATOM),
                'duration_ms' => 0,
                'result' => 'failed',
                'failure_reason' => $reason,
            ]);
        } catch (Throwable) {
        }
    }
}

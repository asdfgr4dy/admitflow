<?php
declare(strict_types=1);

final class BackupService
{
    private const ARCHIVE_PATTERN = '/\\Aadmitflow-full-\\d{8}T\\d{6}Z-[a-f0-9]{8}\\.tar\\.gz\\z/';

    public static function configuration(): array
    {
        $root = dirname(__DIR__);
        $defaults = [
            'directory' => $root . '/storage/backups',
            'tar_binary' => 'tar',
            'mysqldump_binary' => 'mysqldump',
            'mysql_binary' => 'mysql',
            'retention_count' => 8,
        ];
        $examplePath = $root . '/config/backup.example.php';
        $localPath = $root . '/config/backup.local.php';
        foreach ([$examplePath, $localPath] as $path) {
            if (!is_file($path)) continue;
            $values = require $path;
            if (!is_array($values)) throw new RuntimeException('Backup configuration must return an array.');
            $defaults = array_replace($defaults, $values);
        }
        foreach ([
            'ADMITFLOW_BACKUP_DIRECTORY' => 'directory',
            'ADMITFLOW_TAR_PATH' => 'tar_binary',
            'ADMITFLOW_MYSQLDUMP_PATH' => 'mysqldump_binary',
            'ADMITFLOW_MYSQL_PATH' => 'mysql_binary',
            'ADMITFLOW_BACKUP_RETENTION' => 'retention_count',
        ] as $environmentKey => $configKey) {
            $value = getenv($environmentKey);
            if ($value !== false && $value !== '') $defaults[$configKey] = $value;
        }
        $defaults['directory'] = (string) $defaults['directory'];
        foreach (['tar_binary', 'mysqldump_binary', 'mysql_binary'] as $binaryKey) {
            if (trim((string) $defaults[$binaryKey]) === '') throw new RuntimeException('Backup command paths must not be empty.');
            $defaults[$binaryKey] = (string) $defaults[$binaryKey];
        }
        $retention = filter_var($defaults['retention_count'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if ($retention === false) throw new RuntimeException('Backup retention must be between 1 and 365 archives.');
        $defaults['retention_count'] = $retention;

        return $defaults;
    }

    public static function list(PDO $database, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $database->query('SELECT id, backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, created_at, verified_at, expires_at, failure_message FROM backups ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(PDO $database, ?array $actor = null, ?int $protectBackupId = null): array
    {
        self::assertMySql($database);
        $config = self::configuration();
        $backupDirectory = self::ensurePrivateDirectory($config['directory']);
        $root = dirname(__DIR__);
        $databaseConfig = Database::loadConfiguration(Database::environment(), $root . '/config/database.local.php');
        if ((string) $database->query('SELECT DATABASE()')->fetchColumn() !== (string) $databaseConfig['database']) {
            throw new RuntimeException('The connected database does not match the configured database.');
        }
        if ($database->inTransaction()) throw new RuntimeException('Backups cannot run inside an application transaction.');

        $lock = self::acquireLock($backupDirectory, false);
        $workDirectory = $backupDirectory . DIRECTORY_SEPARATOR . '.work-' . bin2hex(random_bytes(12));
        $storageName = 'admitflow-full-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.tar.gz';
        $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . $storageName;
        $recordId = null;
        try {
            if (!mkdir($workDirectory, 0700)) throw new RuntimeException('Could not create a private backup work directory.');
            $optionFile = self::writeClientOptions($workDirectory, $databaseConfig);
            $databaseDump = $workDirectory . DIRECTORY_SEPARATOR . 'database.sql';
            self::runCommand([
                $config['mysqldump_binary'],
                '--defaults-file=' . $optionFile,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--events',
                '--hex-blob',
                '--add-drop-table',
                '--skip-lock-tables',
                '--no-tablespaces',
                '--ignore-table=' . $databaseConfig['database'] . '.backups',
                '--ignore-table=' . $databaseConfig['database'] . '.audit_logs',
                (string) $databaseConfig['database'],
            ], null, $workDirectory, $databaseDump);
            if (!is_file($databaseDump) || filesize($databaseDump) < 1) throw new RuntimeException('Database export produced no data.');

            $fileEntries = self::fileManifest($root);
            self::copySystemFiles($root, $workDirectory . DIRECTORY_SEPARATOR . 'system');
            $manifest = [
                'format_version' => 1,
                'application' => 'AdmitFlow',
                'backup_type' => 'full',
                'database' => (string) $databaseConfig['database'],
                'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'contents' => [
                    'database.sql' => ['size' => filesize($databaseDump), 'sha256' => hash_file('sha256', $databaseDump)],
                    'system' => ['files' => array_values(self::listRegularFiles($workDirectory . DIRECTORY_SEPARATOR . 'system', 'system'))],
                    'storage/uploads' => $fileEntries['storage/uploads'],
                    'storage/avatars' => $fileEntries['storage/avatars'],
                ],
            ];
            $manifestPath = $workDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($manifestPath, $manifestJson . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Could not write the backup manifest.');

            self::runCommand([
                $config['tar_binary'], '-czf', $archivePath,
                '-C', $workDirectory, 'database.sql', 'manifest.json', 'system',
                '-C', $root, 'storage/uploads', 'storage/avatars',
            ], null, $workDirectory);
            $archiveSize = filesize($archivePath);
            if (!is_int($archiveSize) || $archiveSize < 1) throw new RuntimeException('Backup archive is empty.');
            $archiveHash = hash_file('sha256', $archivePath);
            if (!is_string($archiveHash)) throw new RuntimeException('Could not calculate the backup archive checksum.');
            self::verifyPayload($archivePath, $config, $root);

            $insert = $database->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, verified_at) VALUES (\'full\', :storage_name, :file_size, :sha256, \'verified\', :created_by, UTC_TIMESTAMP())');
            $insert->execute([
                'storage_name' => $storageName,
                'file_size' => $archiveSize,
                'sha256' => $archiveHash,
                'created_by' => isset($actor['id']) ? (int) $actor['id'] : null,
            ]);
            $recordId = (int) $database->lastInsertId();
            try {
                AuditLog::record($database, $actor, 'backup.created', 'backup', $recordId, 'success', ['backup_type' => 'full', 'file_size_bytes' => $archiveSize, 'scheduled' => $actor === null], 'notice', $actor === null ? 'Scheduled Backup' : null);
            } catch (Throwable $auditError) {
                error_log('[admitflow backup] Success audit could not be recorded (' . $auditError::class . ').');
            }
            try {
                self::prune($database, $backupDirectory, $config['retention_count'], $recordId, $protectBackupId);
            } catch (Throwable $retentionError) {
                error_log('[admitflow backup] Retention cleanup failed (' . $retentionError::class . ').');
            }

            return $database->query('SELECT id, backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, created_at, verified_at, expires_at, failure_message FROM backups WHERE id = ' . $recordId)->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            if ($recordId === null && is_file($archivePath)) unlink($archivePath);
            if ($recordId === null) {
                try {
                    $failure = $database->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, verification_status, created_by, failure_message) VALUES (\'full\', :storage_name, 0, \'failed\', :created_by, :failure_message)');
                    $failure->execute([
                        'storage_name' => $storageName,
                        'created_by' => isset($actor['id']) ? (int) $actor['id'] : null,
                        'failure_message' => mb_substr($error->getMessage(), 0, 500),
                    ]);
                    AuditLog::record($database, $actor, 'backup.failed', 'backup', (int) $database->lastInsertId(), 'failure', ['reason' => 'backup_operation_failed'], 'warning', $actor === null ? 'Scheduled Backup' : null);
                } catch (Throwable) {
                }
            }
            throw $error;
        } finally {
            self::removeTree($workDirectory);
            self::releaseLock($lock);
        }
    }

    public static function verify(PDO $database, array $actor, int $backupId): array
    {
        self::assertAdmin($actor);
        $backup = self::find($database, $backupId);
        if ($backup === null) throw new DomainException('That backup could not be found.');
        $config = self::configuration();
        $directory = self::ensurePrivateDirectory($config['directory']);
        $path = self::archivePath($directory, (string) $backup['storage_name']);
        try {
            if (!is_file($path) || (int) filesize($path) !== (int) $backup['file_size_bytes']) throw new RuntimeException('Backup file is missing or its size has changed.');
            $actualHash = hash_file('sha256', $path);
            if (!is_string($actualHash) || !is_string($backup['sha256']) || !hash_equals((string) $backup['sha256'], $actualHash)) throw new RuntimeException('Backup checksum verification failed.');
            $databaseConfig = Database::loadConfiguration(Database::environment(), dirname(__DIR__) . '/config/database.local.php');
            self::verifyPayload($path, $config, dirname(__DIR__));
            $update = $database->prepare('UPDATE backups SET verification_status = \'verified\', verified_at = UTC_TIMESTAMP(), failure_message = NULL WHERE id = :id');
            $update->execute(['id' => $backupId]);
            AuditLog::record($database, $actor, 'backup.verified', 'backup', $backupId, 'success', [], 'notice');
            return ['ok' => true, 'message' => 'Backup integrity verified.'];
        } catch (Throwable $error) {
            $update = $database->prepare('UPDATE backups SET verification_status = \'failed\', failure_message = :failure_message WHERE id = :id');
            $update->execute(['failure_message' => mb_substr($error->getMessage(), 0, 500), 'id' => $backupId]);
            AuditLog::record($database, $actor, 'backup.verification_failed', 'backup', $backupId, 'failure', ['reason' => 'verification_failed'], 'warning');
            throw new DomainException('Backup verification failed. ' . $error->getMessage(), 0, $error);
        }
    }

    public static function restore(PDO $database, array $actor, int $backupId, string $confirmation): array
    {
        self::assertAdmin($actor);
        if ($confirmation !== 'RESTORE') throw new DomainException('Type RESTORE exactly to confirm data recovery.');
        $backup = self::find($database, $backupId);
        if ($backup === null) throw new DomainException('That backup could not be found.');
        if ((string) $backup['backup_type'] !== 'full') throw new DomainException('Only full database-and-files backups can be restored.');
        self::verify($database, $actor, $backupId);

        $selected = self::archiveFile($database, $backupId);
        $safetyBackup = self::create($database, $actor, $backupId);
        $config = self::configuration();
        $backupDirectory = self::ensurePrivateDirectory($config['directory']);
        $root = dirname(__DIR__);
        $databaseConfig = Database::loadConfiguration(Database::environment(), $root . '/config/database.local.php');
        $lock = self::acquireLock($backupDirectory, false);
        $workDirectory = $backupDirectory . DIRECTORY_SEPARATOR . '.restore-' . bin2hex(random_bytes(12));
        $maintenancePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.maintenance.lock';
        $maintenanceEnabled = false;
        $databaseRestoreStarted = false;
        $directorySwaps = [];
        $rollbackFailed = false;
        try {
            if (!mkdir($workDirectory, 0700)) throw new RuntimeException('Could not create a private restore work directory.');
            $selectedStage = self::extractArchive($selected['path'], $config, $root);
            $safety = self::archiveFile($database, (int) $safetyBackup['id']);
            $safetyStage = self::extractArchive($safety['path'], $config, $root);
            $optionFile = self::writeClientOptions($workDirectory, $databaseConfig);
            if (file_put_contents($maintenancePath, 'System restore in progress.' . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Could not enable maintenance mode for restore.');
            $maintenanceEnabled = true;

            $databaseRestoreStarted = true;
            self::runCommand([
                $config['mysql_binary'],
                '--defaults-file=' . $optionFile,
                '--database=' . $databaseConfig['database'],
            ], $selectedStage . DIRECTORY_SEPARATOR . 'database.sql', $workDirectory);
            self::swapRuntimeFiles($selectedStage, $root, $directorySwaps);

            $actorQuery = $database->prepare('SELECT id, full_name, role FROM users WHERE id = :id');
            $actorQuery->execute(['id' => (int) $actor['id']]);
            $currentActor = $actorQuery->fetch(PDO::FETCH_ASSOC);
            AuditLog::record($database, is_array($currentActor) ? $currentActor : null, 'backup.restored', 'backup', $backupId, 'success', [
                'safety_backup_id' => (int) $safetyBackup['id'],
                'restored_database_and_files' => true,
            ], 'critical', is_array($currentActor) ? null : 'System Restore');

            if (is_file($maintenancePath) && !unlink($maintenancePath)) throw new RuntimeException('Could not disable maintenance mode after restore.');
            $maintenanceEnabled = false;
            self::discardOldRuntimeFiles($directorySwaps);
            return ['backup' => $backup, 'safety_backup' => $safetyBackup];
        } catch (Throwable $error) {
            if (!self::rollbackRuntimeFiles($directorySwaps)) $rollbackFailed = true;
            if ($databaseRestoreStarted) {
                try {
                    $safety = self::archiveFile($database, (int) $safetyBackup['id']);
                    $safetyStage = isset($safetyStage) ? $safetyStage : self::extractArchive($safety['path'], $config, $root);
                    $rollbackOptions = self::writeClientOptions($workDirectory, $databaseConfig);
                    self::runCommand([
                        $config['mysql_binary'],
                        '--defaults-file=' . $rollbackOptions,
                        '--database=' . $databaseConfig['database'],
                    ], $safetyStage . DIRECTORY_SEPARATOR . 'database.sql', $workDirectory);
                } catch (Throwable $rollbackError) {
                    $rollbackFailed = true;
                    error_log('[admitflow backup] Database rollback failed (' . $rollbackError::class . ').');
                }
            }
            try {
                AuditLog::record($database, $actor, 'backup.restore_failed', 'backup', $backupId, 'failure', ['safety_backup_id' => (int) $safetyBackup['id'], 'rollback_failed' => $rollbackFailed], 'critical');
            } catch (Throwable $auditError) {
                error_log('[admitflow backup] Restore failure audit could not be recorded (' . $auditError::class . ').');
            }
            if (!$rollbackFailed && is_file($maintenancePath) && !unlink($maintenancePath)) $rollbackFailed = true;
            $maintenanceEnabled = $rollbackFailed;
            throw new DomainException($rollbackFailed
                ? 'Restore failed and automatic rollback was unsuccessful. Maintenance mode remains enabled; use the safety backup to recover.'
                : 'Restore failed. The application was rolled back to its pre-restore backup.', 0, $error);
        } finally {
            if (!$maintenanceEnabled && is_file($maintenancePath)) unlink($maintenancePath);
            if (isset($selectedStage)) self::removeTree($selectedStage);
            if (isset($safetyStage)) self::removeTree($safetyStage);
            self::removeTree($workDirectory);
            self::releaseLock($lock);
        }
    }

    public static function archiveFile(PDO $database, int $backupId): array
    {
        $backup = self::find($database, $backupId);
        if ($backup === null) throw new DomainException('That backup could not be found.');
        if ((string) $backup['verification_status'] !== 'verified') throw new DomainException('Only verified backups can be downloaded.');
        $config = self::configuration();
        $directory = self::ensurePrivateDirectory($config['directory']);
        $path = self::archivePath($directory, (string) $backup['storage_name']);
        if (!is_file($path) || !is_string($backup['sha256']) || !hash_equals((string) $backup['sha256'], (string) hash_file('sha256', $path))) {
            throw new DomainException('The backup file is unavailable or has changed; verify it before downloading.');
        }
        return ['path' => $path, 'name' => (string) $backup['storage_name'], 'size' => (int) $backup['file_size_bytes']];
    }

    public static function adminCanManage(array $actor): bool
    {
        return ($actor['role'] ?? '') === 'admin';
    }

    private static function verifyPayload(string $archivePath, array $config, string $root): void
    {
        $stage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-backup-verify-' . bin2hex(random_bytes(12));
        if (!mkdir($stage, 0700)) throw new RuntimeException('Could not create a private verification directory.');
        try {
            $members = self::runCommand([$config['tar_binary'], '-tzf', $archivePath], null, $stage);
            $verbose = self::runCommand([$config['tar_binary'], '-tvzf', $archivePath], null, $stage);
            $memberNames = array_values(array_filter(array_map([self::class, 'normalizeMemberName'], preg_split('/\r?\n/', trim($members)) ?: []), static fn (string $name): bool => $name !== ''));
            if ($memberNames === [] || count($memberNames) !== count(array_unique($memberNames))) throw new RuntimeException('Archive contains missing or duplicate entries.');
            foreach ($memberNames as $name) {
                if ($name === 'database.sql' || $name === 'manifest.json' || $name === 'system' || $name === 'storage/uploads' || $name === 'storage/avatars') continue;
                if (!str_starts_with($name, 'system/') && !str_starts_with($name, 'storage/uploads/') && !str_starts_with($name, 'storage/avatars/')) throw new RuntimeException('Archive contains an unexpected path.');
            }
            foreach (preg_split('/\r?\n/', trim($verbose)) ?: [] as $entry) {
                if ($entry === '') continue;
                $type = $entry[0];
                if (!in_array($type, ['-', 'd'], true)) throw new RuntimeException('Archive contains a non-regular file entry.');
            }
            if (!in_array('database.sql', $memberNames, true) || !in_array('manifest.json', $memberNames, true)) throw new RuntimeException('Archive is missing its database dump or manifest.');
            self::runCommand([$config['tar_binary'], '-xzf', $archivePath, '-C', $stage], null, $stage);
            self::assertNoSymlinks($stage);
            $manifestPath = $stage . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
            if (($manifest['format_version'] ?? null) !== 1 || ($manifest['application'] ?? '') !== 'AdmitFlow' || ($manifest['backup_type'] ?? '') !== 'full' || !preg_match('/\A[A-Za-z0-9_]+\z/', (string) ($manifest['database'] ?? '')) || !is_array($manifest['contents'] ?? null)) {
                throw new RuntimeException('Backup manifest is incompatible with this installation.');
            }
            self::verifyManifestFiles($stage, $manifest['contents']);
        } finally {
            self::removeTree($stage);
        }
    }

    private static function verifyManifestFiles(string $root, array $contents): void
    {
        $expected = ['database.sql' => true, 'system' => true, 'storage/uploads' => true, 'storage/avatars' => true];
        if (array_diff_key($expected, $contents) !== []) throw new RuntimeException('Backup manifest is incomplete.');
        foreach ($contents as $relativePath => $entry) {
            if (!isset($expected[$relativePath]) || !is_array($entry)) throw new RuntimeException('Backup manifest contains an unexpected path.');
            if ($relativePath === 'database.sql') {
                $path = $root . DIRECTORY_SEPARATOR . 'database.sql';
                if (!is_file($path) || (int) filesize($path) !== (int) ($entry['size'] ?? -1) || !hash_equals((string) ($entry['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                    throw new RuntimeException('Database dump does not match the manifest.');
                }
                continue;
            }
            $listed = $entry['files'] ?? null;
            if (!is_array($listed)) throw new RuntimeException('File manifest is invalid.');
            $actualFiles = self::listRegularFiles($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $relativePath);
            if (count($listed) !== count($actualFiles)) throw new RuntimeException('File backup does not match the manifest.');
            foreach ($listed as $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null) || !isset($actualFiles[$file['path']])) throw new RuntimeException('File backup is missing a manifest entry.');
                $actual = $actualFiles[$file['path']];
                if ((int) $actual['size'] !== (int) ($file['size'] ?? -1) || !hash_equals((string) ($file['sha256'] ?? ''), (string) $actual['sha256'])) throw new RuntimeException('A backed-up file does not match the manifest.');
            }
        }
    }

    private static function extractArchive(string $archivePath, array $config, string $root): string
    {
        self::verifyPayload($archivePath, $config, $root);
        $stage = dirname($archivePath) . DIRECTORY_SEPARATOR . '.restore-stage-' . bin2hex(random_bytes(12));
        if (!mkdir($stage, 0700)) throw new RuntimeException('Could not create a private restore staging directory.');
        try {
            self::runCommand([$config['tar_binary'], '-xzf', $archivePath, '-C', $stage], null, $stage);
            self::assertNoSymlinks($stage);
            return $stage;
        } catch (Throwable $error) {
            self::removeTree($stage);
            throw $error;
        }
    }

    private static function swapRuntimeFiles(string $stage, string $root, array &$swaps): void
    {
        $nonce = bin2hex(random_bytes(8));
        foreach (['storage/uploads', 'storage/avatars'] as $relativePath) {
            $source = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_dir($source)) throw new RuntimeException('Backup is missing a required runtime file directory.');
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $new = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target) . '.restore-' . $nonce;
            $old = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target) . '.previous-' . $nonce;
            $index = count($swaps);
            $swaps[] = ['target' => $target, 'new' => $new, 'old' => $old, 'moved_old' => false, 'activated' => false];
            try {
                self::copyDirectory($source, $new, true);
            } catch (Throwable $error) {
                self::removeTree($new);
                throw $error;
            }
            if (file_exists($target)) {
                if (!rename($target, $old)) throw new RuntimeException('Could not preserve the current runtime file directory.');
                $swaps[$index]['moved_old'] = true;
            }
            if (!rename($new, $target)) throw new RuntimeException('Could not install restored runtime files.');
            $swaps[$index]['activated'] = true;
        }
    }

    private static function copyDirectory(string $source, string $destination, bool $privateFiles = false): void
    {
        if (!mkdir($destination, 0700)) throw new RuntimeException('Could not create a runtime file staging directory.');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new RuntimeException('Symbolic links are not allowed in runtime file backups.');
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0700)) throw new RuntimeException('Could not create a restored runtime subdirectory.');
            } elseif ($item->isFile()) {
                if (!copy($item->getPathname(), $target) || ($privateFiles && !chmod($target, 0600))) {
                    throw new RuntimeException('Could not copy a restored runtime file.');
                }
            }
        }
    }

    private static function rollbackRuntimeFiles(array $swaps): bool
    {
        $ok = true;
        foreach (array_reverse($swaps) as $swap) {
            try {
                if (!empty($swap['activated']) && file_exists($swap['target'])) {
                    $failedPath = $swap['new'] . '.failed';
                    if (!@rename($swap['target'], $failedPath)) $ok = false;
                    elseif (is_dir($failedPath)) self::removeTree($failedPath);
                    else @unlink($failedPath);
                }
                if (!empty($swap['moved_old']) && file_exists($swap['old']) && !@rename($swap['old'], $swap['target'])) $ok = false;
                if (file_exists($swap['new'])) self::removeTree($swap['new']);
            } catch (Throwable $error) {
                $ok = false;
                error_log('[admitflow backup] Runtime file rollback failed (' . $error::class . ').');
            }
        }
        return $ok;
    }

    private static function discardOldRuntimeFiles(array $swaps): void
    {
        foreach ($swaps as $swap) {
            if (file_exists($swap['old'])) {
                try {
                    self::removeTree($swap['old']);
                } catch (Throwable $error) {
                    error_log('[admitflow backup] Previous runtime files could not be removed (' . $error::class . ').');
                }
            }
        }
    }

    private static function fileManifest(string $root): array
    {
        return [
            'storage/uploads' => ['files' => array_values(self::listRegularFiles($root . '/storage/uploads', 'storage/uploads'))],
            'storage/avatars' => ['files' => array_values(self::listRegularFiles($root . '/storage/avatars', 'storage/avatars'))],
        ];
    }

    private static function copySystemFiles(string $root, string $destination): void
    {
        if (!mkdir($destination, 0700)) throw new RuntimeException('Could not create a system-file backup staging directory.');
        foreach (['app', 'public', 'resources', 'database', 'scripts'] as $directory) {
            $source = $root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($source)) throw new RuntimeException('A required system-file directory is missing: ' . $directory);
            self::copyDirectory($source, $destination . DIRECTORY_SEPARATOR . $directory);
        }
        $configDirectory = $destination . DIRECTORY_SEPARATOR . 'config';
        if (!mkdir($configDirectory, 0700)) throw new RuntimeException('Could not stage example configuration files.');
        foreach (['database.example.php', 'backup.example.php'] as $configFile) {
            $source = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $configFile;
            if (is_file($source) && !copy($source, $configDirectory . DIRECTORY_SEPARATOR . $configFile)) throw new RuntimeException('Could not stage an example configuration file.');
        }
        foreach (['composer.json', 'composer.lock', 'README.md', 'readme1.md'] as $rootFile) {
            $source = $root . DIRECTORY_SEPARATOR . $rootFile;
            if (is_file($source) && !copy($source, $destination . DIRECTORY_SEPARATOR . $rootFile)) throw new RuntimeException('Could not stage a system manifest file.');
        }
    }

    private static function listRegularFiles(string $directory, string $relativeRoot): array
    {
        $files = [];
        if (!is_dir($directory)) return $files;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new RuntimeException('Symbolic links are not allowed in backup data directories.');
            if (!$item->isFile()) continue;
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen(dirname($directory)) + 1));
            if (!preg_match('~\A[a-zA-Z0-9_./-]+\z~', $relative) || str_contains($relative, '../')) throw new RuntimeException('A backup data file has an unsafe path.');
            $files[$relative] = ['path' => $relative, 'size' => $item->getSize(), 'sha256' => hash_file('sha256', $item->getPathname())];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function assertNoSymlinks(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new RuntimeException('Archive extraction produced a symbolic link.');
        }
    }

    private static function normalizeMemberName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        while (str_starts_with($name, './')) $name = substr($name, 2);
        if ($name === '' || str_starts_with($name, '/') || preg_match('/\A[A-Za-z]:/', $name) || str_contains($name, '../') || $name === '..' || str_contains($name, "\0")) {
            throw new RuntimeException('Archive contains an unsafe path.');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.') throw new RuntimeException('Archive contains an unsafe path.');
        }
        return rtrim($name, '/');
    }

    private static function writeClientOptions(string $directory, array $config): string
    {
        $escape = static fn (string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $path = $directory . DIRECTORY_SEPARATOR . 'mysql-client.cnf';
        $contents = "[client]\n"
            . 'host="' . $escape((string) $config['host']) . "\"\n"
            . 'port=' . (int) $config['port'] . "\n"
            . 'user="' . $escape((string) $config['username']) . "\"\n"
            . 'password="' . $escape((string) $config['password']) . "\"\n"
            . 'default-character-set="' . $escape((string) $config['charset']) . "\"\n";
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) throw new RuntimeException('Could not create a private database client configuration.');
        @chmod($path, 0600);
        return $path;
    }

    private static function runCommand(array $command, ?string $inputFile, string $workDirectory, ?string $outputFile = null): string
    {
        $stdoutPath = $outputFile ?? $workDirectory . DIRECTORY_SEPARATOR . 'command.stdout';
        $stderrPath = $workDirectory . DIRECTORY_SEPARATOR . 'command.stderr';
        $stdinPath = $inputFile ?? (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        $stdout = fopen($stdoutPath, 'wb');
        $stderr = fopen($stderrPath, 'wb');
        if (!is_resource($stdout) || !is_resource($stderr)) throw new RuntimeException('Could not prepare backup command output files.');
        $process = proc_open($command, [0 => ['file', $stdinPath, 'rb'], 1 => $stdout, 2 => $stderr], $pipes, $workDirectory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            fclose($stdout);
            fclose($stderr);
            throw new RuntimeException('Could not start a required backup command.');
        }
        fclose($stdout);
        fclose($stderr);
        $exitCode = proc_close($process);
        $errorOutput = is_file($stderrPath) ? trim((string) file_get_contents($stderrPath)) : '';
        if ($exitCode !== 0) throw new RuntimeException('Backup command failed (exit ' . $exitCode . '). ' . mb_substr($errorOutput, 0, 700));
        return is_file($stdoutPath) ? (string) file_get_contents($stdoutPath) : '';
    }

    private static function prune(PDO $database, string $directory, int $retention, int $keepId, ?int $protectId = null): void
    {
        $query = $database->prepare('SELECT id, storage_name FROM backups WHERE backup_type = \'full\' AND verification_status = \'verified\' ORDER BY created_at DESC, id DESC');
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $deleteIds = [];
        foreach (array_slice($rows, $retention) as $row) {
            if ((int) $row['id'] === $keepId || (int) $row['id'] === $protectId) continue;
            $name = (string) $row['storage_name'];
            $database->prepare('DELETE FROM backups WHERE id = :id')->execute(['id' => (int) $row['id']]);
            $deleteIds[] = $name;
        }
        foreach ($deleteIds as $name) {
            if (preg_match(self::ARCHIVE_PATTERN, $name) !== 1) continue;
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) unlink($path);
        }
    }

    private static function find(PDO $database, int $backupId): ?array
    {
        if ($backupId < 1) return null;
        $statement = $database->prepare('SELECT id, backup_type, storage_name, file_size_bytes, sha256, verification_status FROM backups WHERE id = :id');
        $statement->execute(['id' => $backupId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function archivePath(string $directory, string $name): string
    {
        if (preg_match(self::ARCHIVE_PATTERN, $name) !== 1) throw new DomainException('Backup storage name is invalid.');
        return $directory . DIRECTORY_SEPARATOR . $name;
    }

    private static function ensurePrivateDirectory(string $path): string
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Private backup storage is unavailable.');
        $resolved = realpath($path);
        $public = realpath(dirname(__DIR__) . '/public');
        if ($resolved === false || $public === false || str_starts_with(strtolower($resolved . DIRECTORY_SEPARATOR), strtolower($public . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Backup storage must be outside the public web root.');
        }
        if (!is_writable($resolved)) throw new RuntimeException('Private backup storage is not writable.');
        return $resolved;
    }

    private static function acquireLock(string $directory, bool $blocking)
    {
        $handle = fopen($directory . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
        if (!is_resource($handle) || !flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
            if (is_resource($handle)) fclose($handle);
            throw new DomainException('Another backup operation is already running.');
        }
        return $handle;
    }

    private static function releaseLock($handle): void
    {
        if (!is_resource($handle)) return;
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function assertMySql(PDO $database): void
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Backups require a MySQL-compatible database.');
    }

    private static function assertAdmin(array $actor): void
    {
        if (!self::adminCanManage($actor)) throw new DomainException('Administrator access is required to manage system backups.');
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
            else unlink($item->getPathname());
        }
        rmdir($directory);
    }
}

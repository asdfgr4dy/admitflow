<?php
declare(strict_types=1);

final class BackupService
{
    public static function logRequestEvent(array $event, ?string $logPath = null): void
    {
        $allowed = ['request_id', 'type', 'stage', 'event', 'started_at', 'elapsed_seconds', 'exception_class', 'diagnostic', 'fatal_type', 'fatal_line', 'output_committed'];
        $record = array_intersect_key($event, array_flip($allowed));
        if (isset($record['diagnostic'])) {
            $record['diagnostic'] = self::sanitizeDiagnostic((string) $record['diagnostic'], []);
        }
        $record['logged_at'] = gmdate(DATE_ATOM);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($line)) {
            error_log('[admitflow backup] Could not serialize backup diagnostic event.');
            return;
        }

        $defaultLog = dirname(__DIR__) . '/storage/logs/backup-diagnostics.log';
        $path = $logPath ?? $defaultLog;
        $logDirectory = realpath(dirname($path));
        $privateLogRoot = realpath(dirname(__DIR__) . '/storage/logs');
        if ($logPath === null && ($logDirectory === false || $privateLogRoot === false || $logDirectory !== $privateLogRoot)) {
            error_log('[admitflow backup] Private diagnostic log directory is unavailable.');
            return;
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            error_log('[admitflow backup] Private diagnostic log could not be opened.');
            return;
        }
        @chmod($path, 0600);
        if (flock($handle, LOCK_EX)) {
            fwrite($handle, $line . PHP_EOL);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    private static function markStage(string $stage, string $type, ?string $outputPath = null, ?bool $outputCommitted = null): void
    {
        $context = $GLOBALS['admitflow_backup_context'] ?? null;
        if (!is_array($context)) return;
        $context['stage'] = $stage;
        $context['type'] = $type;
        if ($outputPath !== null) $context['output_path'] = $outputPath;
        if ($outputCommitted !== null) $context['output_committed'] = $outputCommitted;
        $GLOBALS['admitflow_backup_context'] = $context;
    }

    public static function history(PDO $database, array $input = []): array
    {
        $where = ['1 = 1'];
        $parameters = [];
        $type = trim((string) ($input['backup_type'] ?? ''));
        if ($type !== '' && !in_array($type, ['database', 'files', 'full'], true)) throw new DomainException('Choose a valid backup type.');
        if ($type !== '') {
            $where[] = 'b.backup_type = :backup_type';
            $parameters['backup_type'] = $type;
        }
        $verification = trim((string) ($input['verification_status'] ?? ''));
        if ($verification !== '' && !in_array($verification, ['pending', 'verified', 'failed'], true)) throw new DomainException('Choose a valid backup verification status.');
        if ($verification !== '') {
            $where[] = 'b.verification_status = :verification_status';
            $parameters['verification_status'] = $verification;
        }
        $search = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($search) > 180) throw new DomainException('Keep backup search under 180 characters.');
        if ($search !== '') {
            $where[] = '(b.storage_name LIKE :search_name OR creator.full_name LIKE :search_creator)';
            $parameters['search_name'] = '%' . $search . '%';
            $parameters['search_creator'] = '%' . $search . '%';
        }
        $dateFilters = [];
        foreach (['start_date' => 'start', 'end_date' => 'end'] as $key => $boundary) {
            $value = trim((string) ($input[$key] ?? ''));
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) throw new DomainException('Enter valid backup dates using year-month-day format.');
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $where[] = 'b.created_at ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $parameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $dateFilters[$key] = $value;
            }
        }
        if (isset($dateFilters['start_date'], $dateFilters['end_date']) && $dateFilters['start_date'] > $dateFilters['end_date']) throw new DomainException('The backup start date must not be later than its end date.');
        $whereSql = implode(' AND ', $where);
        $from = ' FROM backups b LEFT JOIN users creator ON creator.id = b.created_by ';
        $count = $database->prepare('SELECT COUNT(*)' . $from . 'WHERE ' . $whereSql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pagination = Pagination::make($total, $input, 'page', 'per_page', 20);
        $statement = $database->prepare('SELECT b.id, b.backup_type, b.storage_name, b.file_size_bytes, b.sha256, b.verification_status, b.created_at, b.verified_at, b.failure_message, creator.full_name AS creator_name' . $from . 'WHERE ' . $whereSql . ' ORDER BY b.created_at DESC, b.id DESC LIMIT ' . $pagination['page_size'] . ' OFFSET ' . $pagination['offset']);
        $statement->execute($parameters);
        $filters = ['q' => $search, 'backup_type' => $type, 'verification_status' => $verification, 'start_date' => $dateFilters['start_date'] ?? '', 'end_date' => $dateFilters['end_date'] ?? '', 'date_range' => (string) ($input['date_range'] ?? '')];
        $pagination['base_url'] = '/staff/backups';
        $pagination['query'] = array_filter($filters, static fn (string $value): bool => $value !== '');

        return ['rows' => $statement->fetchAll(), 'pagination' => $pagination, 'filters' => $filters];
    }

    public static function createDatabase(PDO $database, array $actor, ?callable $dumpCreator = null, ?string $storageRoot = null): int
    {
        self::markStage('storage-root', 'database');
        $backupRoot = self::backupRoot($storageRoot);
        $name = 'database-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.sql';
        $target = $backupRoot . DIRECTORY_SEPARATOR . $name;
        self::markStage('create-output', 'database', $target, false);
        $targetHandle = @fopen($target, 'x+b');
        if ($targetHandle === false) {
            error_log('[admitflow backup] Database dump target creation failed (exclusive file creation).');
            self::recordFailure($database, $actor, 'backup.database_creation_failed');
            throw new RuntimeException('Could not create a unique private backup file.');
        }
        chmod($target, 0600);
        fclose($targetHandle);
        $stderr = '';
        $exitCode = null;
        $config = [];
        try {
            self::markStage('utility-discovery-and-configuration', 'database');
            if ($dumpCreator !== null) {
                self::markStage('dump-process-execution', 'database');
                $result = $dumpCreator($target);
                if (is_array($result)) {
                    $exitCode = (int) ($result['exit_code'] ?? 1);
                    $stderr = (string) ($result['stderr'] ?? '');
                } else {
                    $exitCode = (int) $result;
                }
            } else {
                $config = self::databaseConfig();
                $dumpTool = getenv('ADMITFLOW_MYSQLDUMP') ?: 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
                if (!is_file($dumpTool)) {
                    error_log('[admitflow backup] Database dump executable discovery failed (configured/default utility not found).');
                    throw new RuntimeException('The database dump utility was not found. Configure ADMITFLOW_MYSQLDUMP with a compatible executable.');
                }

                self::markStage('dump-process-execution', 'database');
                $stderrPath = tempnam($backupRoot, '.mysqldump-error-');
                if ($stderrPath === false) throw new RuntimeException('Could not prepare private dump diagnostics.');
                chmod($stderrPath, 0600);
                $command = [$dumpTool, '--host=' . $config['host'], '--port=' . (string) $config['port'], '--user=' . $config['username'], '--default-character-set=utf8mb4', '--single-transaction', '--routines', '--triggers', '--skip-lock-tables', $config['database']];
                $environment = getenv() ?: [];
                if ($config['password'] !== '') $environment['MYSQL_PWD'] = $config['password'];
                $descriptor = [0 => ['pipe', 'r'], 1 => ['file', $target, 'wb'], 2 => ['file', $stderrPath, 'wb']];
                try {
                    $process = @proc_open($command, $descriptor, $pipes, dirname(__DIR__), $environment);
                    if (!is_resource($process)) {
                        error_log('[admitflow backup] Database dump process could not be started.');
                        throw new RuntimeException('Could not start the database backup process.');
                    }
                    fclose($pipes[0]);
                    $exitCode = proc_close($process);
                    $stderr = self::readDiagnostic($stderrPath, $config);
                } finally {
                    if (is_file($stderrPath)) unlink($stderrPath);
                }
            }
        } catch (Throwable $error) {
            if (is_file($target)) unlink($target);
            self::recordFailure($database, $actor, 'backup.database_creation_failed');
            $diagnostic = self::sanitizeDiagnostic($error->getMessage(), $config);
            error_log('[admitflow backup] Database dump preparation failed (' . $error::class . ')' . ($diagnostic !== '' ? ': ' . $diagnostic : '.'));
            if ($error instanceof RuntimeException) throw $error;
            throw new RuntimeException('Database backup preparation failed before verification.');
        }

        if ($exitCode !== 0) {
            $stderr = self::sanitizeDiagnostic($stderr, $config);
            error_log('[admitflow backup] Database dump process failed (exit=' . (string) $exitCode . ')' . ($stderr !== '' ? ': ' . $stderr : '.'));
            if (is_file($target)) unlink($target);
            self::recordFailure($database, $actor, 'backup.database_creation_failed');
            throw new RuntimeException('Database dump process failed. Review server diagnostics; no backup was recorded.');
        }
        clearstatcache(true, $target);
        self::markStage('output-size-validation', 'database');
        $size = is_file($target) ? filesize($target) : false;
        if (!is_int($size) || $size < 1024) {
            error_log('[admitflow backup] Database dump output was missing or incomplete (bytes=' . (is_int($size) ? (string) $size : 'unavailable') . ').');
            if (is_file($target)) unlink($target);
            self::recordFailure($database, $actor, 'backup.database_creation_failed');
            throw new RuntimeException('Database dump output was missing or incomplete. No backup was recorded.');
        }

        chmod($target, 0600);
        self::markStage('checksum-calculation', 'database');
        $checksum = hash_file('sha256', $target);
        if (!is_string($checksum)) {
            error_log('[admitflow backup] SHA-256 calculation failed for completed dump.');
            if (is_file($target)) unlink($target);
            self::recordFailure($database, $actor, 'backup.database_checksum_failed');
            throw new RuntimeException('Backup checksum could not be calculated. No backup was recorded.');
        }
        self::markStage('integrity-verification', 'database');
        $verifyStatus = BackupIntegrity::databaseDumpHasExpectedStructure($target) ? 'verified' : 'failed';
        if ($verifyStatus !== 'verified') {
            error_log('[admitflow backup] Database dump structure verification failed (bytes=' . $size . ', checksum=' . substr($checksum, 0, 12) . ').');
        }

        $backupRecorded = false;
        try {
            self::markStage('metadata-insert', 'database');
            $database->beginTransaction();
            $insert = $database->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, verified_at, failure_message) VALUES (\'database\', :name, :size, :sha256, :status, :user_id, :verified_at, :failure)');
            $insert->execute(['name' => $name, 'size' => $size, 'sha256' => $checksum, 'status' => $verifyStatus, 'user_id' => $actor['id'], 'verified_at' => $verifyStatus === 'verified' ? gmdate('Y-m-d H:i:s') : null, 'failure' => $verifyStatus === 'verified' ? null : 'Dump content did not pass structural verification.']);
            $backupId = (int) $database->lastInsertId();
            self::markStage('audit-insert', 'database');
            self::audit($database, $actor, $verifyStatus === 'verified' ? 'backup.created' : 'backup.creation_verification_failed', $backupId, $verifyStatus === 'verified' ? 'success' : 'failure');
            self::markStage('metadata-commit', 'database');
            $database->commit();
            $backupRecorded = true;
            self::markStage('metadata-committed', 'database', null, true);
            if ($verifyStatus !== 'verified') {
                throw new RuntimeException('Backup file was created but its verification failed. Do not use it for restoration.');
            }
            return $backupId;
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            if (!$backupRecorded && is_file($target)) {
                unlink($target);
            }
            if (!$backupRecorded) {
                error_log('[admitflow backup] Backup metadata transaction failed (' . $error::class . ').');
                self::recordFailure($database, $actor, 'backup.database_metadata_failed');
            }
            throw $error;
        }
    }

    private static function readDiagnostic(string $path, array $configuration): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return '';
        $diagnostic = (string) fread($handle, 4096);
        fclose($handle);
        return self::sanitizeDiagnostic($diagnostic, $configuration);
    }

    private static function sanitizeDiagnostic(string $diagnostic, array $configuration): string
    {
        foreach (['password', 'username', 'database', 'host'] as $key) {
            $secret = (string) ($configuration[$key] ?? '');
            if ($secret !== '') $diagnostic = str_replace($secret, '[redacted]', $diagnostic);
        }
        $diagnostic = preg_replace('/\b(password|passwd|pwd)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $diagnostic) ?? '';
        $diagnostic = preg_replace('/\b(using password:)\s*(?:yes|no)/i', '$1 [redacted]', $diagnostic) ?? '';
        $diagnostic = preg_replace('/(?:[A-Za-z]:[\\\\\/][^\s"\']+|\/(?:[^\s"\']+\/)*[^\s"\']+)/', '[path]', $diagnostic) ?? '';
        $diagnostic = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $diagnostic) ?? '';
        return mb_substr(trim(preg_replace('/\s+/', ' ', $diagnostic) ?? ''), 0, 500);
    }

    public static function createFiles(
        PDO $database,
        array $actor,
        ?string $projectRoot = null,
        ?string $uploadsRoot = null,
        ?string $storageRoot = null,
        ?callable $archiveWriter = null
    ): int {
        self::markStage('utility-discovery-and-source-validation', 'files');
        if (!class_exists(PharData::class)) {
            error_log('[admitflow backup] File backup failed at archive utility discovery (PharData unavailable).');
            self::recordFailure($database, $actor, 'backup.files_archive_unavailable');
            throw new RuntimeException('The PHP PharData extension is not available for file archiving.');
        }
        $projectRoot = realpath($projectRoot ?? dirname(__DIR__));
        $uploadsRoot = realpath($uploadsRoot ?? dirname(__DIR__) . '/storage/uploads');
        $backupRoot = self::backupRoot($storageRoot);
        if ($projectRoot === false || !is_dir($projectRoot) || !is_readable($projectRoot)) {
            error_log('[admitflow backup] File backup failed at application source validation.');
            self::recordFailure($database, $actor, 'backup.files_source_unavailable');
            throw new RuntimeException('Application file source is unavailable.');
        }
        if ($uploadsRoot === false || !is_dir($uploadsRoot) || !is_readable($uploadsRoot)) {
            error_log('[admitflow backup] File backup failed at upload source validation.');
            self::recordFailure($database, $actor, 'backup.files_uploads_unavailable');
            throw new RuntimeException('Uploaded-file source is unavailable.');
        }

        $name = 'files-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.tar';
        $archivePath = $backupRoot . DIRECTORY_SEPARATOR . $name;
        self::markStage('create-output', 'files', $archivePath, false);
        $archiveRecorded = false;
        $stage = 'archive creation';
        self::markStage('archive-creation', 'files');
        try {
            $counts = $archiveWriter !== null
                ? $archiveWriter($archivePath, $projectRoot, $uploadsRoot)
                : self::writeFilesArchive($archivePath, $projectRoot, $uploadsRoot);
            if (!is_file($archivePath) || !is_readable($archivePath)) {
                throw new RuntimeException('Archive writer did not produce a readable file.');
            }
            chmod($archivePath, 0600);
            clearstatcache(true, $archivePath);
            $size = filesize($archivePath);
            if (!is_int($size) || $size < 512) {
                throw new RuntimeException('File archive is empty or incomplete.');
            }

            $stage = 'checksum calculation';
            $stage = 'checksum calculation';
            self::markStage('checksum-calculation', 'files');
            $checksum = hash_file('sha256', $archivePath);
            if (!is_string($checksum)) {
                throw new RuntimeException('File archive checksum calculation failed.');
            }
            $stage = 'archive integrity verification';
            self::markStage('integrity-verification', 'files');
            $isVerified = BackupIntegrity::tarHasSafeStructure($archivePath)
                && BackupIntegrity::checksumMatches($archivePath, $checksum);
            if (!$isVerified) {
                error_log('[admitflow backup] File archive verification failed (bytes=' . $size . ', checksum=' . substr($checksum, 0, 12) . ').');
            }

            $stage = 'metadata insertion';
            self::markStage('metadata-insert', 'files');
            $database->beginTransaction();
            $insert = $database->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, verified_at, failure_message) VALUES (\'files\', :name, :size, :sha256, :status, :user_id, :verified_at, :failure)');
            $insert->execute(['name' => $name, 'size' => $size, 'sha256' => $checksum, 'status' => $isVerified ? 'verified' : 'failed', 'user_id' => $actor['id'], 'verified_at' => $isVerified ? gmdate('Y-m-d H:i:s') : null, 'failure' => $isVerified ? null : 'File archive did not pass structure/checksum verification.']);
            $backupId = (int) $database->lastInsertId();
            self::markStage('audit-insert', 'files');
            self::audit($database, $actor, $isVerified ? 'backup.files_created' : 'backup.file_creation_verification_failed', $backupId, $isVerified ? 'success' : 'failure');
            self::markStage('metadata-commit', 'files');
            $database->commit();
            $archiveRecorded = true;
            self::markStage('metadata-committed', 'files', null, true);
            if (!$isVerified) throw new RuntimeException('File archive was created but verification failed.');
            error_log('[admitflow backup] File archive verified (files=' . (int) ($counts['files'] ?? 0) . ', uploaded=' . (int) ($counts['uploads'] ?? 0) . ', bytes=' . $size . ').');
            self::markStage('completed', 'files');
            return $backupId;
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            if (!$archiveRecorded && is_file($archivePath)) unlink($archivePath);
            if (!$archiveRecorded) {
                error_log('[admitflow backup] File backup failed at ' . $stage . ' (' . $error::class . ')' . ($error->getMessage() !== '' ? ': ' . self::sanitizeDiagnostic($error->getMessage(), []) : '.'));
                self::recordFailure($database, $actor, 'backup.files_creation_failed');
            }
            throw $error;
        }
    }

    private static function writeFilesArchive(string $archivePath, string $projectRoot, string $uploadsRoot): array
    {
        $archiveFiles = [];
        $counts = ['files' => 0, 'uploads' => 0];
        foreach (['app', 'public', 'resources', 'database', 'scripts'] as $directory) {
            $path = $projectRoot . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path) || !is_readable($path)) {
                throw new RuntimeException('A required application file source is unavailable.');
            }
            $counts['files'] += self::collectArchiveTree($archiveFiles, $path, 'application/' . $directory, 'application');
        }
        $vendorRoot = $projectRoot . DIRECTORY_SEPARATOR . 'vendor';
        if (is_dir($vendorRoot)) {
            if (!is_readable($vendorRoot)) throw new RuntimeException('The installed dependency directory is unreadable.');
            $counts['files'] += self::collectArchiveTree($archiveFiles, $vendorRoot, 'application/vendor', 'application');
        }
        foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'README.md'] as $file) {
            $path = $projectRoot . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                if (!is_readable($path)) throw new RuntimeException('A required application manifest is unreadable.');
                $archiveFiles['application/' . $file] = $path;
                $counts['files']++;
            }
        }
        $safeExampleConfig = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.example.php';
        if (is_file($safeExampleConfig)) {
            if (!is_readable($safeExampleConfig)) throw new RuntimeException('The example database configuration is unreadable.');
            $archiveFiles['application/config/database.example.php'] = $safeExampleConfig;
            $counts['files']++;
        }
        $counts['uploads'] = self::collectArchiveTree($archiveFiles, $uploadsRoot, 'uploads', 'uploads');
        if ($archiveFiles === []) throw new RuntimeException('No eligible application or uploaded files were found.');
        $archive = new PharData($archivePath);
        $archive->buildFromIterator(new ArrayIterator($archiveFiles));
        unset($archive);
        return $counts;
    }

    private static function collectArchiveTree(array &$archiveFiles, string $sourceRoot, string $archivePrefix, string $kind): int
    {
        $sourceRoot = realpath($sourceRoot);
        if ($sourceRoot === false || !is_dir($sourceRoot) || !is_readable($sourceRoot)) {
            throw new RuntimeException('A file archive source directory is unavailable.');
        }
        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) throw new RuntimeException('Symbolic links are not allowed in file backup sources.');
            if ($file->isDir()) continue;
            if (!$file->isFile() || !$file->isReadable()) throw new RuntimeException('A file backup source is unreadable or unsupported.');
            $name = strtolower($file->getFilename());
            $relative = substr($file->getPathname(), strlen($sourceRoot) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
                throw new RuntimeException('An unsafe relative path was found in the file backup source.');
            }
            $parts = explode('/', $relative);
            $excludedDirectories = ['.git', 'node_modules', 'cache', 'caches', 'tmp', 'temp', 'logs', 'backups', '__pycache__'];
            if (array_intersect(array_map('strtolower', array_slice($parts, 0, -1)), $excludedDirectories) !== []) continue;
            if (preg_match('/(^|\/)(?:\.env(?:\.[^\/]*)?|database\.local\.php|auth\.json|credentials?\.json)$/i', $relative)) continue;
            if (in_array(strtolower($name), ['.gitignore', '.htaccess'], true) && $kind === 'uploads') continue;
            if ($kind === 'uploads' && (str_starts_with($name, '.') || preg_match('/\.(?:tmp|temp|part|cache)$/i', $name))) continue;
            $archiveFiles[$archivePrefix . '/' . $relative] = $file->getPathname();
            $count++;
        }
        return $count;
    }

    public static function verify(PDO $database, array $actor, int $backupId): bool
    {
        $statement = $database->prepare('SELECT storage_name, backup_type, sha256 FROM backups WHERE id = :id');
        $statement->execute(['id' => $backupId]);
        $backup = $statement->fetch();
        if (!is_array($backup)) {
            throw new DomainException('That backup record could not be found.');
        }
        $path = self::storedBackupPath(self::backupRoot(), (string) $backup['storage_name']);
        $validHash = $path !== null && BackupIntegrity::checksumMatches($path, (string) $backup['sha256']);
        $validStructure = $validHash && ($backup['backup_type'] === 'database' ? BackupIntegrity::databaseDumpHasExpectedStructure($path) : BackupIntegrity::tarHasSafeStructure($path));

        $database->beginTransaction();
        try {
            $update = $database->prepare('UPDATE backups SET verification_status = :status, verified_at = :verified_at, failure_message = :failure WHERE id = :id');
            $update->execute(['status' => $validStructure ? 'verified' : 'failed', 'verified_at' => $validStructure ? gmdate('Y-m-d H:i:s') : null, 'failure' => $validStructure ? null : 'Checksum or structural verification failed. Restore has not been tested.', 'id' => $backupId]);
            self::audit($database, $actor, $validStructure ? 'backup.verified' : 'backup.verification_failed', $backupId, $validStructure ? 'success' : 'failure');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }

        return $validStructure;
    }

    public static function restoreDatabase(
        PDO $database,
        array $actor,
        int $backupId,
        string $confirmation,
        ?callable $restoreExecutor = null,
        ?callable $safetyBackupCreator = null,
        ?string $storageRoot = null
    ): int {
        if (($actor['role'] ?? '') !== 'admin') {
            throw new DomainException('Administrator access is required to restore a database backup.');
        }
        if (!hash_equals('RESTORE ' . $backupId, trim($confirmation))) {
            throw new DomainException('Type RESTORE ' . $backupId . ' to confirm this database replacement.');
        }

        $root = self::backupRoot($storageRoot);
        $statement = $database->prepare('SELECT id, backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, created_at FROM backups WHERE id = :id');
        $statement->execute(['id' => $backupId]);
        $backup = $statement->fetch();
        if (!is_array($backup) || $backup['backup_type'] !== 'database') {
            throw new DomainException('Choose an existing database backup. File archives cannot be restored as a database.');
        }
        if ($backup['verification_status'] !== 'verified') {
            throw new DomainException('Only a verified database backup can be restored.');
        }
        $backupPath = self::storedBackupPath($root, (string) $backup['storage_name']);
        if ($backupPath === null || !BackupIntegrity::checksumMatches($backupPath, (string) $backup['sha256']) || !BackupIntegrity::databaseDumpHasExpectedStructure($backupPath)) {
            throw new DomainException('The selected database backup failed checksum or format validation.');
        }

        $safetyBackupId = $safetyBackupCreator !== null
            ? (int) $safetyBackupCreator($database, $actor, $root)
            : self::createDatabase($database, $actor);
        if ($safetyBackupId < 1) {
            throw new RuntimeException('The required safety backup was not created. Restore was not started.');
        }
        $safetyQuery = $database->prepare('SELECT storage_name, file_size_bytes, sha256, verification_status FROM backups WHERE id = :id AND backup_type = \'database\'');
        $safetyQuery->execute(['id' => $safetyBackupId]);
        $safetyBackup = $safetyQuery->fetch();
        if (!is_array($safetyBackup) || $safetyBackup['verification_status'] !== 'verified') {
            throw new RuntimeException('The safety backup could not be verified. Restore was not started.');
        }
        $safetyPath = self::storedBackupPath($root, (string) $safetyBackup['storage_name']);
        if ($safetyPath === null || !BackupIntegrity::checksumMatches($safetyPath, (string) $safetyBackup['sha256']) || !BackupIntegrity::databaseDumpHasExpectedStructure($safetyPath)) {
            throw new RuntimeException('The safety backup failed validation. Restore was not started.');
        }

        try {
            if ($restoreExecutor !== null) {
                $restoreExecutor($backupPath, $database);
            } else {
                self::runMysqlRestore($backupPath, $root);
            }
        } catch (Throwable $error) {
            try {
                self::ensureBackupHistory($database, $actor, $safetyBackup);
            } catch (Throwable) {
                error_log('[admitflow backup] Safety backup history could not be restored after import failure.');
            }
            self::restoreFailureAudit($database, $actor, $backupId);
            throw new RuntimeException('Database restore failed. The database may be partially restored; safety backup #' . $safetyBackupId . ' remains in private storage.', 0, $error);
        }

    self::ensureBackupHistory($database, $actor, $backup);
    self::ensureBackupHistory($database, $actor, $safetyBackup);
    self::restoreAudit($database, $actor, 'backup.restored', $backupId, 'success');
        return $safetyBackupId;
    }

    public static function safeRestoreInstructions(): string
    {
        return 'Only verified database dumps from the private backup inventory can be selected. The server rechecks the stored checksum and expected dump structure, creates and verifies a safety backup, then imports the selected dump with the configured MySQL client. Stop application writes and confirm the target environment before restoring. A checksum and structure check does not prove that a dump is restorable; test backups in an isolated database first. A failed import can leave a partial restore, so recovery from the retained safety backup requires a separate explicit restore.';
    }

    private static function backupRoot(?string $override = null): string
    {
        $path = $override ?? dirname(__DIR__) . '/storage/backups';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Private backup storage is not available.');
        }
        $realPath = realpath($path);
        if ($realPath === false || str_starts_with($realPath, realpath(dirname(__DIR__) . '/public') ?: '')) {
            throw new RuntimeException('Backup storage must remain outside the public web root.');
        }
        return $realPath;
    }

    private static function storedBackupPath(string $root, string $storageName): ?string
    {
        if (!preg_match('/^(?:database-\d{8}-\d{6}-[a-f0-9]{12}\.sql|files-\d{8}-\d{6}-[a-f0-9]{12}\.tar)$/', $storageName)) {
            return null;
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . $storageName);
        return $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) && is_readable($path)
            ? $path
            : null;
    }

    private static function runMysqlRestore(string $backupPath, string $backupRoot): void
    {
        $config = self::databaseConfig();
        $configuredClient = getenv('ADMITFLOW_MYSQL');
        $mysql = is_string($configuredClient) && $configuredClient !== ''
            ? $configuredClient
            : (is_file('C:\\xampp\\mysql\\bin\\mysql.exe') ? 'C:\\xampp\\mysql\\bin\\mysql.exe' : 'mysql');
        if (str_contains($mysql, DIRECTORY_SEPARATOR) && !is_file($mysql)) {
            throw new RuntimeException('The configured MySQL client was not found.');
        }

        $stderrPath = tempnam($backupRoot, '.restore-error-');
        if ($stderrPath === false) throw new RuntimeException('Could not prepare restore process output.');
        chmod($stderrPath, 0600);
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $command = [$mysql, '--host=' . $config['host'], '--port=' . (string) $config['port'], '--user=' . $config['username'], '--default-character-set=utf8mb4', '--binary-mode', $config['database']];
        $environment = getenv() ?: [];
        if ($config['password'] !== '') $environment['MYSQL_PWD'] = $config['password'];
        $descriptors = [0 => ['file', $backupPath, 'r'], 1 => ['file', $nullDevice, 'ab'], 2 => ['file', $stderrPath, 'wb']];
        try {
            $process = @proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);
            if (!is_resource($process)) throw new RuntimeException('Could not start the MySQL restore process.');
            $exitCode = proc_close($process);
            if ($exitCode !== 0) throw new RuntimeException('The MySQL restore process returned a failure status.');
        } finally {
            if (is_file($stderrPath)) unlink($stderrPath);
        }
    }

    private static function ensureBackupHistory(PDO $database, array $actor, array $backup): void
    {
        $exists = $database->prepare('SELECT COUNT(*) FROM backups WHERE storage_name = :storage_name');
        $exists->execute(['storage_name' => $backup['storage_name']]);
        if ((int) $exists->fetchColumn() > 0) return;

        $creatorId = $backup['created_by'] ?? $actor['id'];
        $creator = $database->prepare('SELECT id FROM users WHERE id = :id');
        $creator->execute(['id' => $creatorId]);
        $createdBy = $creator->fetchColumn();
        $insert = $database->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, verified_at) VALUES (\'database\', :name, :size, :sha256, \'verified\', :created_by, :verified_at)');
        $insert->execute(['name' => $backup['storage_name'], 'size' => $backup['file_size_bytes'], 'sha256' => $backup['sha256'], 'created_by' => $createdBy === false ? null : $createdBy, 'verified_at' => $backup['created_at'] ?? gmdate('Y-m-d H:i:s')]);
    }

    private static function restoreFailureAudit(PDO $database, array $actor, int $backupId): void
    {
        try {
            self::restoreAudit($database, $actor, 'backup.restore_failed', $backupId, 'failure');
        } catch (Throwable) {
            error_log('[admitflow backup] Restore failure audit event could not be recorded.');
        }
    }

    private static function restoreAudit(PDO $database, array $actor, string $action, int $backupId, string $outcome): void
    {
        $userQuery = $database->prepare('SELECT id FROM users WHERE id = :id');
        $userQuery->execute(['id' => $actor['id']]);
        $userId = $userQuery->fetchColumn();
        $statement = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address) VALUES (:user_id, :actor, :action, \'backup\', :backup_id, :outcome, :severity, :ip)');
        $statement->execute([
            'user_id' => $userId === false ? null : $userId,
            'actor' => mb_substr((string) ($actor['full_name'] ?? 'Administrator'), 0, 160),
            'action' => $action,
            'backup_id' => $backupId,
            'outcome' => $outcome,
            'severity' => 'critical',
            'ip' => request_ip(),
        ]);
    }

    private static function databaseConfig(): array
    {
        $localPath = dirname(__DIR__) . '/config/database.local.php';
        $config = is_file($localPath) ? require $localPath : [];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $value = getenv('ADMITFLOW_DB_' . strtoupper($key));
            if ($value !== false) $config[$key] = $value;
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (!array_key_exists($key, $config)) throw new RuntimeException('Database backup configuration is incomplete.');
        }
        Database::validateCredentials($config);
        return $config;
    }

    private static function audit(PDO $database, array $actor, string $action, int $backupId, string $outcome = 'success'): void
    {
        $statement = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address) VALUES (:user_id, :actor, :action, \'backup\', :backup_id, :outcome, :severity, :ip)');
        $statement->execute(['user_id' => $actor['id'], 'actor' => mb_substr($actor['full_name'], 0, 160), 'action' => $action, 'backup_id' => $backupId, 'outcome' => $outcome, 'severity' => $outcome === 'success' ? 'notice' : 'warning', 'ip' => request_ip()]);
    }

    private static function recordFailure(PDO $database, array $actor, string $action): void
    {
        try {
            $statement = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, outcome, severity, ip_address) VALUES (:user_id, :actor, :action, \'backup\', \'failure\', \'warning\', :ip)');
            $statement->execute(['user_id' => $actor['id'], 'actor' => mb_substr($actor['full_name'], 0, 160), 'action' => $action, 'ip' => request_ip()]);
        } catch (Throwable) {
            error_log('[admitflow backup] Backup failure audit event could not be recorded.');
        }
    }
}
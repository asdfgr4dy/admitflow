<?php
declare(strict_types=1);

final class BackupService
{
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

    public static function createDatabase(PDO $database, array $actor): int
    {
        $backupRoot = self::backupRoot();
        $name = 'database-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.sql';
        $target = $backupRoot . DIRECTORY_SEPARATOR . $name;
        $config = self::databaseConfig();
        $dumpTool = getenv('ADMITFLOW_MYSQLDUMP') ?: 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!is_file($dumpTool)) {
            throw new RuntimeException('The local mysqldump tool was not found. Set ADMITFLOW_MYSQLDUMP to its executable path.');
        }

        $command = [$dumpTool, '--host=' . $config['host'], '--port=' . (string) $config['port'], '--user=' . $config['username'], '--default-character-set=utf8mb4', '--single-transaction', '--routines', '--triggers', '--skip-lock-tables', $config['database']];
        $environment = getenv() ?: [];
        if ($config['password'] !== '') {
            $environment['MYSQL_PWD'] = $config['password'];
        }
        $descriptor = [0 => ['pipe', 'r'], 1 => ['file', $target, 'wb'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, dirname(__DIR__), $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the database backup process.');
        }
        fclose($pipes[0]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_file($target) || filesize($target) < 1024) {
            if (is_file($target)) unlink($target);
            error_log('[admitflow backup] Database dump process failed or produced an incomplete file.');
            self::recordFailure($database, $actor, 'backup.database_creation_failed');
            throw new RuntimeException('Database backup failed or produced an incomplete dump. No backup was recorded.');
        }
        chmod($target, 0600);

        $backupRecorded = false;
        try {
            $checksum = hash_file('sha256', $target);
            $size = filesize($target);
            $verifyStatus = BackupIntegrity::databaseDumpHasExpectedStructure($target) ? 'verified' : 'failed';
            $database->beginTransaction();
            $insert = $database->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, verified_at, failure_message) VALUES (\'database\', :name, :size, :sha256, :status, :user_id, :verified_at, :failure)');
            $insert->execute(['name' => $name, 'size' => $size, 'sha256' => $checksum, 'status' => $verifyStatus, 'user_id' => $actor['id'], 'verified_at' => $verifyStatus === 'verified' ? gmdate('Y-m-d H:i:s') : null, 'failure' => $verifyStatus === 'verified' ? null : 'Dump content did not pass structural verification.']);
            $backupId = (int) $database->lastInsertId();
            self::audit($database, $actor, $verifyStatus === 'verified' ? 'backup.created' : 'backup.creation_verification_failed', $backupId, $verifyStatus === 'verified' ? 'success' : 'failure');
            $database->commit();
            $backupRecorded = true;
            if ($verifyStatus !== 'verified') {
                throw new RuntimeException('Backup file was created but its verification failed. Do not use it for restoration.');
            }
            return $backupId;
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            if (!$backupRecorded && is_file($target)) {
                unlink($target);
            }
            throw $error;
        }
    }

    public static function createFiles(PDO $database, array $actor): int
    {
        if (!class_exists(PharData::class)) {
            throw new RuntimeException('The PHP PharData extension is not available for file archiving.');
        }
        $backupRoot = self::backupRoot();
        $name = 'files-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.tar';
        $archivePath = $backupRoot . DIRECTORY_SEPARATOR . $name;
        $uploads = dirname(__DIR__) . '/storage/uploads';
        $backupRecorded = false;
        try {
            $archive = new PharData($archivePath);
            if (is_dir($uploads)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile() && !str_contains($file->getFilename(), "\0")) {
                        $relativePath = 'uploads/' . substr($file->getPathname(), strlen($uploads) + 1);
                        $archive->addFile($file->getPathname(), $relativePath);
                    }
                }
            }
            unset($archive);
            chmod($archivePath, 0600);

            $checksum = hash_file('sha256', $archivePath);
            $size = filesize($archivePath);
            $isVerified = BackupIntegrity::tarHasSafeStructure($archivePath);
            $database->beginTransaction();
            $insert = $database->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by, verified_at, failure_message) VALUES (\'files\', :name, :size, :sha256, :status, :user_id, :verified_at, :failure)');
            $insert->execute(['name' => $name, 'size' => $size, 'sha256' => $checksum, 'status' => $isVerified ? 'verified' : 'failed', 'user_id' => $actor['id'], 'verified_at' => $isVerified ? gmdate('Y-m-d H:i:s') : null, 'failure' => $isVerified ? null : 'File archive did not pass structural verification.']);
            $backupId = (int) $database->lastInsertId();
            self::audit($database, $actor, $isVerified ? 'backup.files_created' : 'backup.file_creation_verification_failed', $backupId, $isVerified ? 'success' : 'failure');
            $database->commit();
            $backupRecorded = true;
            if (!$isVerified) throw new RuntimeException('File archive was created but verification failed.');
            return $backupId;
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            if (!$backupRecorded && is_file($archivePath)) {
                unlink($archivePath);
            }
            throw $error;
        }
    }

    public static function verify(PDO $database, array $actor, int $backupId): bool
    {
        $statement = $database->prepare('SELECT storage_name, backup_type, sha256 FROM backups WHERE id = :id');
        $statement->execute(['id' => $backupId]);
        $backup = $statement->fetch();
        if (!is_array($backup)) {
            throw new DomainException('That backup record could not be found.');
        }
        $root = realpath(self::backupRoot());
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . basename($backup['storage_name']));
        $validPath = $root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR);
        $validHash = $validPath && BackupIntegrity::checksumMatches($path, (string) $backup['sha256']);
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

    public static function safeRestoreInstructions(): string
    {
        return 'This screen checks the stored checksum and expected file structure only; it does not prove that restore will work. Restore is manual: create a fresh safety backup, stop application writes, and have a database administrator test the dump in an isolated temporary database before approving any import. Never restore directly over production without a verified safety copy and explicit approval.';
    }

    private static function backupRoot(): string
    {
        $path = dirname(__DIR__) . '/storage/backups';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Private backup storage is not available.');
        }
        $realPath = realpath($path);
        if ($realPath === false || str_starts_with($realPath, realpath(dirname(__DIR__) . '/public') ?: '')) {
            throw new RuntimeException('Backup storage must remain outside the public web root.');
        }
        return $realPath;
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
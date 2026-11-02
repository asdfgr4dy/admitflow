<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/BackupIntegrity.php';
require dirname(__DIR__) . '/app/FileArchiveRestorer.php';
require dirname(__DIR__) . '/app/BackupService.php';

if (!function_exists('request_ip')) {
    function request_ip(): ?string
    {
        return null;
    }
}

$testCount = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$testCount, &$failures): void {
    $testCount++;
    if (!$condition) $failures[] = $message;
};

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-file-restore-' . bin2hex(random_bytes(8));
$projectRoot = $testRoot . DIRECTORY_SEPARATOR . 'project';
$uploadsRoot = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
$workingRoot = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $child) {
        $removeTree($child->getPathname());
    }
    @rmdir($path);
};
$makeTarHeader = static function (string $name, string $type, string $linkName = ''): string {
    $header = str_repeat("\0", 512);
    $header = substr_replace($header, str_pad($name, 100, "\0"), 0, 100);
    $header = substr_replace($header, sprintf("%07o\0", 0644), 100, 8);
    $header = substr_replace($header, sprintf("%07o\0", 0), 108, 8);
    $header = substr_replace($header, sprintf("%07o\0", 0), 116, 8);
    $header = substr_replace($header, sprintf("%011o\0", $type === '0' ? 1 : 0), 124, 12);
    $header = substr_replace($header, sprintf("%011o\0", time()), 136, 12);
    $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
    $header[156] = $type;
    $header = substr_replace($header, str_pad($linkName, 100, "\0"), 157, 100);
    $header = substr_replace($header, "ustar\0", 257, 6);
    $header = substr_replace($header, '00', 263, 2);
    $checksum = array_sum(array_map('ord', str_split($header)));
    return substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
};

try {
    foreach ([$projectRoot . DIRECTORY_SEPARATOR . 'app', $uploadsRoot . DIRECTORY_SEPARATOR . 'documents', $workingRoot] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Fixture directory could not be created.');
    }

    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service.php', 'before-app');
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'README.md', 'before-readme');
    file_put_contents($uploadsRoot . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'existing.pdf', 'before-upload');
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'unrelated.txt', 'leave-this-alone');

    $archivePath = $workingRoot . DIRECTORY_SEPARATOR . 'fixture.tar';
    $archive = new PharData($archivePath);
    $archive->addFromString('application/app/service.php', 'after-app');
    $archive->addFromString('application/README.md', 'after-readme');
    $archive->addFromString('uploads/documents/existing.pdf', 'after-upload');
    $archive->addFromString('uploads/documents/new.pdf', 'new-upload');
    $archive->addFromString('avatars/avatar.png', 'new-avatar');
    unset($archive);

    $safetyBackupCalls = 0;
    $finalizeCalls = 0;
    $result = FileArchiveRestorer::restore(
        $archivePath,
        $projectRoot,
        $uploadsRoot,
        $workingRoot,
        static function () use (&$safetyBackupCalls, $projectRoot, $uploadsRoot): int {
            $safetyBackupCalls++;
            if (file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service.php') !== 'before-app'
                || file_get_contents($uploadsRoot . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'existing.pdf') !== 'before-upload') {
                throw new RuntimeException('Safety backup callback ran after destination changes.');
            }
            return 71;
        },
        static function (int $backupId) use (&$finalizeCalls): void {
            if ($backupId !== 71) throw new RuntimeException('Unexpected safety backup ID.');
            $finalizeCalls++;
        }
    );

    $assert($result['safety_backup_id'] === 71 && $result['files_restored'] === 5 && $safetyBackupCalls === 1 && $finalizeCalls === 1, 'restore stages entries, requests a safety backup first, then finalizes once');
    $assert(file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service.php') === 'after-app' && file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'README.md') === 'after-readme', 'application files and a project-root manifest are restored');
    $assert(file_get_contents($uploadsRoot . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'existing.pdf') === 'after-upload' && file_get_contents($uploadsRoot . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'new.pdf') === 'new-upload' && file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . 'avatar.png') === 'new-avatar', 'uploaded documents and private avatars are restored');
    $assert(file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'unrelated.txt') === 'leave-this-alone', 'files not present in the archive are left untouched');

    $rollbackArchivePath = $workingRoot . DIRECTORY_SEPARATOR . 'rollback.tar';
    $rollbackArchive = new PharData($rollbackArchivePath);
    $rollbackArchive->addFromString('application/app/service.php', 'must-rollback');
    $rollbackArchive->addFromString('application/README.md', 'must-rollback-readme');
    unset($rollbackArchive);
    $rollbackReported = false;
    try {
        FileArchiveRestorer::restore(
            $rollbackArchivePath,
            $projectRoot,
            $uploadsRoot,
            $workingRoot,
            static fn (): int => 72,
            static function (): void { throw new RuntimeException('synthetic finalization failure'); }
        );
    } catch (RuntimeException $error) {
        $rollbackReported = str_contains($error->getMessage(), 'prior files were restored') && str_contains($error->getMessage(), 'safety backup #72');
    }
    $assert($rollbackReported && file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service.php') === 'after-app' && file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'README.md') === 'after-readme', 'finalization failure rolls back replaced files and retains the safety backup');

    $unsafeArchivePath = $workingRoot . DIRECTORY_SEPARATOR . 'unsafe.tar';
    $unsafeArchive = new PharData($unsafeArchivePath);
    $unsafeArchive->addFromString('unapproved/escape.txt', 'must-not-write');
    unset($unsafeArchive);
    $unsafeSafetyBackupCalls = 0;
    $unsafeArchiveRejected = false;
    try {
        FileArchiveRestorer::restore(
            $unsafeArchivePath,
            $projectRoot,
            $uploadsRoot,
            $workingRoot,
            static function () use (&$unsafeSafetyBackupCalls): int { $unsafeSafetyBackupCalls++; return 73; },
            static function (): void {}
        );
    } catch (RuntimeException) {
        $unsafeArchiveRejected = true;
    }
    $assert($unsafeArchiveRejected && $unsafeSafetyBackupCalls === 0 && !file_exists($projectRoot . DIRECTORY_SEPARATOR . 'escape.txt'), 'unsupported archive roots are rejected before the safety-backup callback or destination writes');

    $linkArchivePath = $workingRoot . DIRECTORY_SEPARATOR . 'links.tar';
    $regularHeader = $makeTarHeader('uploads/documents/regular.txt', '0');
    $hardLinkHeader = $makeTarHeader('uploads/documents/hardlink.txt', '1', 'uploads/documents/regular.txt');
    $symbolicLinkHeader = $makeTarHeader('uploads/documents/symlink.txt', '2', 'uploads/documents/regular.txt');
    $tarBytes = $regularHeader . 'x' . str_repeat("\0", 511)
        . $hardLinkHeader
        . $symbolicLinkHeader
        . str_repeat("\0", 1024);
    file_put_contents($linkArchivePath, $tarBytes);
    $linkSafetyBackupCalls = 0;
    $archiveLinksRejected = false;
    try {
        FileArchiveRestorer::restore(
            $linkArchivePath,
            $projectRoot,
            $uploadsRoot,
            $workingRoot,
            static function () use (&$linkSafetyBackupCalls): int { $linkSafetyBackupCalls++; return 74; },
            static function (): void {}
        );
    } catch (RuntimeException) {
        $archiveLinksRejected = true;
    }
    $assert($archiveLinksRejected && $linkSafetyBackupCalls === 0 && !file_exists($uploadsRoot . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'hardlink.txt') && !file_exists($uploadsRoot . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'symlink.txt'), 'hard-link and symbolic-link TAR members are rejected before safety backup or destination writes');

    $traversalArchivePath = $workingRoot . DIRECTORY_SEPARATOR . 'traversal.tar';
    $traversalHeader = $makeTarHeader('../escape.txt', '0');
    file_put_contents($traversalArchivePath, $traversalHeader . 'x' . str_repeat("\0", 511) . str_repeat("\0", 1024));
    $traversalSafetyBackupCalls = 0;
    $traversalRejected = false;
    try {
        FileArchiveRestorer::restore(
            $traversalArchivePath,
            $projectRoot,
            $uploadsRoot,
            $workingRoot,
            static function () use (&$traversalSafetyBackupCalls): int { $traversalSafetyBackupCalls++; return 75; },
            static function (): void {}
        );
    } catch (RuntimeException) {
        $traversalRejected = true;
    }
    $assert($traversalRejected && $traversalSafetyBackupCalls === 0 && !file_exists($testRoot . DIRECTORY_SEPARATOR . 'escape.txt'), 'parent-directory traversal entries are rejected before safety backup or writes outside the project');

    $deviceArchivePath = $workingRoot . DIRECTORY_SEPARATOR . 'device.tar';
    $deviceHeader = $makeTarHeader('uploads/documents/device', '3');
    file_put_contents($deviceArchivePath, $deviceHeader . str_repeat("\0", 1024));
    $deviceRejected = false;
    try {
        FileArchiveRestorer::restore($deviceArchivePath, $projectRoot, $uploadsRoot, $workingRoot, static fn (): int => 76, static function (): void {});
    } catch (RuntimeException) {
        $deviceRejected = true;
    }
    $assert($deviceRejected, 'device-node TAR members are rejected');

    foreach (['public/assets', 'resources', 'database', 'scripts'] as $requiredSource) {
        if (!mkdir($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requiredSource), 0700, true)
            && !is_dir($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requiredSource))) {
            throw new RuntimeException('Backup source fixture could not be created.');
        }
    }
    $restoreDatabase = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $restoreDatabase->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL)');
    $restoreDatabase->exec('CREATE TABLE backups (id INTEGER PRIMARY KEY AUTOINCREMENT, backup_type TEXT NOT NULL, storage_name TEXT NOT NULL UNIQUE, file_size_bytes INTEGER NOT NULL, sha256 TEXT, verification_status TEXT NOT NULL, created_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, verified_at TEXT NULL, failure_message TEXT NULL)');
    $restoreDatabase->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NULL, entity_id INTEGER NULL, outcome TEXT NOT NULL, severity TEXT NOT NULL, ip_address TEXT NULL)');
    $restoreDatabase->exec("INSERT INTO users (id, full_name) VALUES (9, 'File Restore Administrator')");
    $restoreActor = ['id' => 9, 'full_name' => 'File Restore Administrator', 'role' => 'admin'];

    $inventoryArchiveName = 'files-20261101-010101-' . str_repeat('a', 12) . '.tar';
    $inventoryArchivePath = $workingRoot . DIRECTORY_SEPARATOR . $inventoryArchiveName;
    $inventoryArchive = new PharData($inventoryArchivePath);
    $inventoryArchive->addFromString('application/app/service.php', 'selected-app');
    $inventoryArchive->addFromString('uploads/documents/existing.pdf', 'selected-upload');
    unset($inventoryArchive);
    $restoreDatabase->prepare('INSERT INTO backups (backup_type, storage_name, file_size_bytes, sha256, verification_status, created_by) VALUES (\'files\', :name, :size, :sha256, \'verified\', :user_id)')->execute([
        'name' => $inventoryArchiveName,
        'size' => filesize($inventoryArchivePath),
        'sha256' => hash_file('sha256', $inventoryArchivePath),
        'user_id' => $restoreActor['id'],
    ]);
    $inventoryBackupId = (int) $restoreDatabase->lastInsertId();
    $inventoryCountBeforeRejects = (int) $restoreDatabase->query('SELECT COUNT(*) FROM backups')->fetchColumn();
    $nonAdminRestoreRejected = false;
    try {
        BackupService::restoreFiles($restoreDatabase, ['role' => 'reviewer'], $inventoryBackupId, 'RESTORE FILES ' . $inventoryBackupId, $projectRoot, $uploadsRoot, $workingRoot);
    } catch (DomainException) {
        $nonAdminRestoreRejected = true;
    }
    $wrongConfirmationRejected = false;
    try {
        BackupService::restoreFiles($restoreDatabase, $restoreActor, $inventoryBackupId, 'RESTORE FILES 999', $projectRoot, $uploadsRoot, $workingRoot);
    } catch (DomainException) {
        $wrongConfirmationRejected = true;
    }
    $assert($nonAdminRestoreRejected && $wrongConfirmationRejected && (int) $restoreDatabase->query('SELECT COUNT(*) FROM backups')->fetchColumn() === $inventoryCountBeforeRejects, 'file restore rejects non-admin and incorrect confirmation before making a safety backup');

    $safetyBackupId = BackupService::restoreFiles(
        $restoreDatabase,
        $restoreActor,
        $inventoryBackupId,
        'RESTORE FILES ' . $inventoryBackupId,
        $projectRoot,
        $uploadsRoot,
        $workingRoot
    );
    $safetyBackup = $restoreDatabase->prepare('SELECT verification_status, backup_type FROM backups WHERE id = :id');
    $safetyBackup->execute(['id' => $safetyBackupId]);
    $safetyRecord = $safetyBackup->fetch();
    $restoreAuditCount = (int) $restoreDatabase->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'backup.files_restored' AND entity_id = " . $inventoryBackupId . " AND outcome = 'success'")->fetchColumn();
    $assert(is_array($safetyRecord) && $safetyRecord['backup_type'] === 'files' && $safetyRecord['verification_status'] === 'verified' && $restoreAuditCount === 1, 'service restore creates a verified file safety archive and records a success audit');
    $assert(file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service.php') === 'selected-app' && file_get_contents($uploadsRoot . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'existing.pdf') === 'selected-upload' && file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'unrelated.txt') === 'leave-this-alone', 'service restore applies the selected inventory archive without deleting unrelated files');

    $restoreDatabase->exec("CREATE TRIGGER fail_file_restore_success BEFORE INSERT ON audit_logs WHEN NEW.action = 'backup.files_restored' BEGIN SELECT RAISE(ABORT, 'synthetic success-audit failure'); END");
    $auditFailureRollbackReported = false;
    try {
        BackupService::restoreFiles(
            $restoreDatabase,
            $restoreActor,
            $inventoryBackupId,
            'RESTORE FILES ' . $inventoryBackupId,
            $projectRoot,
            $uploadsRoot,
            $workingRoot
        );
    } catch (RuntimeException $error) {
        $auditFailureRollbackReported = str_contains($error->getMessage(), 'prior files were restored');
    }
    $restoreDatabase->exec('DROP TRIGGER fail_file_restore_success');
    $failedAuditCount = (int) $restoreDatabase->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'backup.files_restore_failed' AND entity_id = " . $inventoryBackupId . " AND outcome = 'failure'")->fetchColumn();
    $assert($auditFailureRollbackReported && file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service.php') === 'selected-app' && $failedAuditCount === 1, 'success-audit failure rolls back replaced files and records a failure audit while retaining a safety archive');

    $routeSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
    $viewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/backups.php');
    $assert(is_string($routeSource) && str_contains($routeSource, "if (\$path === '/staff/backup/restore-files')") && str_contains($routeSource, "\$user['role'] !== 'admin'") && str_contains($routeSource, 'verify_csrf();') && is_string($viewSource) && str_contains($viewSource, 'method="post" action="/staff/backup/restore-files"') && str_contains($viewSource, 'name="_csrf"') && str_contains($viewSource, 'RESTORE FILES '), 'file restore route remains admin-only, CSRF protected, and requires explicit typed confirmation');
} finally {
    $removeTree($testRoot);
}

if ($failures !== []) {
    fwrite(STDERR, 'File restore tests: ' . $testCount . ' assertions, ' . count($failures) . ' failures' . PHP_EOL);
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'File restore tests: ' . $testCount . ' assertions, 0 failures' . PHP_EOL);
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This integration test is CLI-only.\n");
    exit(2);
}
if (getenv('ADMITFLOW_RUN_MARIADB_RESTORE_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set ADMITFLOW_RUN_MARIADB_RESTORE_INTEGRATION=1 to opt into the disposable-database restore test.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/BackupIntegrity.php';
require dirname(__DIR__) . '/app/BackupService.php';

$expectedDatabase = 'admitflow_restore_test';
$configuration = [
    'host' => (string) (getenv('ADMITFLOW_DB_HOST') ?: ''),
    'port' => (string) (getenv('ADMITFLOW_DB_PORT') ?: ''),
    'database' => (string) (getenv('ADMITFLOW_DB_DATABASE') ?: ''),
    'username' => (string) (getenv('ADMITFLOW_DB_USERNAME') ?: ''),
    'password' => (string) (getenv('ADMITFLOW_DB_PASSWORD') ?: ''),
    'charset' => (string) (getenv('ADMITFLOW_DB_CHARSET') ?: ''),
];
if ($configuration['host'] !== '127.0.0.1'
    || $configuration['port'] !== '3306'
    || $configuration['database'] !== $expectedDatabase
    || $configuration['username'] === ''
    || strcasecmp($configuration['username'], 'root') === 0
    || $configuration['password'] === ''
    || $configuration['charset'] !== 'utf8mb4'
    || getenv('ADMITFLOW_RESTORE_APPROVED_DATABASE') !== $expectedDatabase) {
    fwrite(STDERR, "Refusing integration test: explicit target must be 127.0.0.1:3306/admitflow_restore_test with a dedicated non-root user and matching restore approval.\n");
    exit(2);
}

try {
    $database = Database::connection();
    BackupService::assertRestoreDestination($database);
    $identity = $database->query('SELECT DATABASE(), CURRENT_USER()')->fetch(PDO::FETCH_NUM);
    if (!is_array($identity) || $identity[0] !== $expectedDatabase) {
        throw new RuntimeException('Connected PDO identity does not name the disposable database.');
    }

    $requiredTables = ['users', 'backups', 'audit_logs', 'system_settings'];
    foreach ($requiredTables as $table) {
        $check = $database->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $check->execute(['table' => $table]);
        if ((int) $check->fetchColumn() !== 1) throw new RuntimeException('Disposable database is not initialized with the required schema.');
    }
    $adminQuery = $database->prepare('SELECT id, full_name FROM users WHERE email = :email AND role = \'admin\' AND account_status = \'active\'');
    $adminQuery->execute(['email' => 'restore-admin@example.test']);
    $admin = $adminQuery->fetch();
    if (!is_array($admin)) throw new RuntimeException('Expected test administrator is missing.');
    $actor = ['id' => (int) $admin['id'], 'full_name' => (string) $admin['full_name'], 'role' => 'admin'];

    $markerKey = 'restore_integration_' . bin2hex(random_bytes(8));
    $markerBefore = 'before-' . bin2hex(random_bytes(8));
    $markerAfter = 'after-' . bin2hex(random_bytes(8));
    $markerInsert = $database->prepare('INSERT INTO system_settings (setting_key, setting_value, value_type, description, is_public, updated_by) VALUES (:key, :value, \'string\', \'Disposable restore integration fixture\', 0, :user_id)');
    $markerInsert->execute(['key' => $markerKey, 'value' => $markerBefore, 'user_id' => $actor['id']]);

    $selectedBackupId = BackupService::createDatabase($database, $actor);
    $selectedBackupQuery = $database->prepare('SELECT storage_name, file_size_bytes, sha256, verification_status FROM backups WHERE id = :id');
    $selectedBackupQuery->execute(['id' => $selectedBackupId]);
    $selectedBackup = $selectedBackupQuery->fetch();
    if (!is_array($selectedBackup) || $selectedBackup['verification_status'] !== 'verified') {
        throw new RuntimeException('Selected integration backup was not recorded as verified.');
    }

    $reviewerEmail = 'restore-reviewer-' . bin2hex(random_bytes(8)) . '@example.test';
    $reviewerInsert = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :password_hash, \'reviewer\', \'active\')');
    $reviewerInsert->execute(['name' => 'Disposable Restore Reviewer', 'email' => $reviewerEmail, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
    $database->prepare('UPDATE system_settings SET setting_value = :value WHERE setting_key = :key')->execute(['value' => $markerAfter, 'key' => $markerKey]);

    $safetyBackupId = BackupService::restoreDatabase($database, $actor, $selectedBackupId, 'RESTORE ' . $selectedBackupId);

    $afterIdentity = $database->query('SELECT DATABASE(), CURRENT_USER()')->fetch(PDO::FETCH_NUM);
    if (!is_array($afterIdentity) || $afterIdentity[0] !== $expectedDatabase || explode('@', (string) $afterIdentity[1], 2)[0] !== $configuration['username']) {
        throw new RuntimeException('Post-restore PDO identity no longer matches the disposable target.');
    }
    $adminCheck = $database->prepare('SELECT role FROM users WHERE email = :email');
    $adminCheck->execute(['email' => 'restore-admin@example.test']);
    if ($adminCheck->fetchColumn() !== 'admin') throw new RuntimeException('Administrator was not preserved by the restore.');
    $reviewerCheck = $database->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
    $reviewerCheck->execute(['email' => $reviewerEmail]);
    if ((int) $reviewerCheck->fetchColumn() !== 0) throw new RuntimeException('Post-backup reviewer unexpectedly remains after restore.');
    $markerCheck = $database->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key');
    $markerCheck->execute(['key' => $markerKey]);
    if ($markerCheck->fetchColumn() !== $markerBefore) throw new RuntimeException('Restored marker does not match the pre-backup state.');

    foreach ([$selectedBackupId, $safetyBackupId] as $requiredId) {
        $history = $database->prepare('SELECT storage_name, file_size_bytes, sha256, verification_status FROM backups WHERE id = :id AND backup_type = \'database\'');
        $history->execute(['id' => $requiredId]);
        $record = $history->fetch();
        if (!is_array($record) || $record['verification_status'] !== 'verified') throw new RuntimeException('Selected or safety backup history is missing after restore.');
        $backupPath = dirname(__DIR__) . '/storage/backups/' . basename((string) $record['storage_name']);
        if (!is_file($backupPath)
            || filesize($backupPath) !== (int) $record['file_size_bytes']
            || !BackupIntegrity::checksumMatches($backupPath, (string) $record['sha256'])
            || !BackupIntegrity::databaseDumpHasExpectedStructure($backupPath)
            || !BackupIntegrity::databaseDumpTargetsOnlySchema($backupPath, $expectedDatabase)) {
            throw new RuntimeException('Selected or safety backup file failed independent post-restore checks.');
        }
    }
    $audit = $database->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = \'backup.restored\' AND entity_type = \'backup\' AND entity_id = :id AND outcome = \'success\'');
    $audit->execute(['id' => $selectedBackupId]);
    if ((int) $audit->fetchColumn() !== 1) throw new RuntimeException('Restore success audit event was not independently found.');

    printf("PASS: database=%s user=%s selected_backup_id=%d safety_backup_id=%d reviewer_removed=true marker_restored=true\n", $afterIdentity[0], $afterIdentity[1], $selectedBackupId, $safetyBackupId);
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
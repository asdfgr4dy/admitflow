<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/BackupService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE backups (id INTEGER PRIMARY KEY, backup_type TEXT, storage_name TEXT, file_size_bytes INTEGER, sha256 TEXT, verification_status TEXT, created_by INTEGER, created_at TEXT, verified_at TEXT, expires_at TEXT, failure_message TEXT)');
$database->exec("INSERT INTO backups VALUES (1,'full','admitflow-full-20261106T010000Z-1234abcd.tar.gz',1200,'a','verified',1,'2026-11-06 01:00:00','2026-11-06 01:00:01',NULL,NULL),(2,'full','admitflow-full-20261106T020000Z-5678abcd.tar.gz',0,NULL,'failed',NULL,'2026-11-06 02:00:00',NULL,NULL,'Command failed')");

$rows = BackupService::list($database);
$assert(count($rows) === 2 && (int) $rows[0]['id'] === 2 && $rows[0]['verification_status'] === 'failed', 'backup history returns newest records with verification status');
$assert(BackupService::adminCanManage(['id' => 1, 'role' => 'admin']), 'administrator can manage backups');
$assert(!BackupService::adminCanManage(['id' => 2, 'role' => 'reviewer']) && !BackupService::adminCanManage(['id' => 3, 'role' => 'student']), 'reviewers and students cannot manage backups');

$reviewerBlocked = false;
try {
    BackupService::verify($database, ['id' => 2, 'role' => 'reviewer'], 1);
} catch (DomainException) {
    $reviewerBlocked = true;
}
$assert($reviewerBlocked, 'reviewer cannot verify a backup through the service');

$studentBlocked = false;
try {
    BackupService::restore($database, ['id' => 3, 'role' => 'student'], 1, 'RESTORE');
} catch (DomainException) {
    $studentBlocked = true;
}
$assert($studentBlocked, 'student cannot restore a backup through the service');

$confirmationRequired = false;
try {
    BackupService::restore($database, ['id' => 1, 'role' => 'admin'], 1, 'restore');
} catch (DomainException $error) {
    $confirmationRequired = str_contains($error->getMessage(), 'RESTORE');
}
$assert($confirmationRequired, 'restore requires the exact uppercase confirmation phrase before looking up or changing data');

$sourceDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-backup-source-' . bin2hex(random_bytes(6));
$targetDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-backup-target-' . bin2hex(random_bytes(6));
mkdir($sourceDirectory, 0700);
file_put_contents($sourceDirectory . DIRECTORY_SEPARATOR . 'private-document.pdf', 'private fixture');
$copyDirectory = new ReflectionMethod(BackupService::class, 'copyDirectory');
$copyDirectory->invoke(null, $sourceDirectory, $targetDirectory, true);
$restoredFile = $targetDirectory . DIRECTORY_SEPARATOR . 'private-document.pdf';
$assert(file_get_contents($restoredFile) === 'private fixture', 'restore staging copies runtime file contents');
if (DIRECTORY_SEPARATOR !== '\\') {
    $assert((fileperms($restoredFile) & 0777) === 0600, 'restore staging keeps runtime files owner-only');
}
unlink($restoredFile);
rmdir($targetDirectory);
unlink($sourceDirectory . DIRECTORY_SEPARATOR . 'private-document.pdf');
rmdir($sourceDirectory);

fwrite(STDOUT, 'Backup service tests passed: ' . $assertions . " assertions, 0 failures.\n");

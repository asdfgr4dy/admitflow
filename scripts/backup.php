<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/AuditLog.php';
require dirname(__DIR__) . '/app/BackupService.php';

try {
    $backup = BackupService::create(Database::connection());
    printf(
        "Verified full backup created: %s (%d bytes, id %d).\n",
        $backup['storage_name'],
        (int) $backup['file_size_bytes'],
        (int) $backup['id']
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Full backup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

<?php
$formatBackupSize = static function (int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    foreach ($units as $unit) {
        $size /= 1024;
        if ($size < 1024 || $unit === 'TB') return number_format($size, 1) . ' ' . $unit;
    }
    return $bytes . ' B';
};
$manilaTime = static function (?string $value): string {
    if ($value === null || $value === '') return '—';
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A') . ' Asia/Manila';
};
?>
<section class="staff-page staff-backups-page container-xl">
    <div class="staff-heading">
        <div><p class="eyebrow">Administrator tools · Disaster recovery</p><h1>Backup and Restore.</h1><p>Verified database and private application-file recovery points.</p></div>
        <a href="/admin" class="btn btn-outline-navy"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a>
    </div>

    <section class="backup-operation-panel" aria-labelledby="backup-create-title">
        <div class="section-heading"><div><p class="eyebrow">Full backup</p><h2 id="backup-create-title">Database and application files</h2></div><i class="bi bi-database-check" aria-hidden="true"></i></div>
        <p>Each archive contains the database, application source and schema, private uploaded documents, and avatars. It excludes local credentials, dependencies, logs, and prior backups. A restore replaces the database and uploaded files; deploy the matching source release separately.</p>
        <form method="post" action="/admin/backups/create" data-submit-once>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <button class="btn btn-navy" type="submit"><i class="bi bi-cloud-arrow-down" aria-hidden="true"></i> Create full backup</button>
        </form>
    </section>

    <section class="backup-operation-panel" aria-labelledby="backup-schedule-title">
        <div class="section-heading"><div><p class="eyebrow">Weekly automation</p><h2 id="backup-schedule-title">Schedule the CLI runner</h2></div><i class="bi bi-calendar-week" aria-hidden="true"></i></div>
        <p>Configure the hosting scheduler to run this command weekly. The scheduler belongs to the server environment and is not created by the web app.</p>
        <pre class="backup-command"><code>php scripts/backup.php</code></pre>
        <p class="form-hint mb-0">Set the scheduler working directory to the project root and provide the same protected database and backup configuration used by the web app. The latest <?= (int) $backupConfiguration['retention_count'] ?> verified full backups are retained by default.</p>
    </section>

    <section class="staff-section backup-history-section" aria-labelledby="backup-history-title">
        <div class="section-heading"><div><p class="eyebrow">Integrity checked</p><h2 id="backup-history-title">Backup history</h2></div><span class="section-count"><?= number_format(count($backups)) ?> records</span></div>
        <?php if ($backups === []): ?>
            <div class="academic-empty-state"><i class="bi bi-database" aria-hidden="true"></i><h3>No backups yet</h3><p>Create a full backup here or configure the weekly scheduler.</p></div>
        <?php else: ?>
            <div class="table-responsive staff-table-wrap academic-table-wrap">
                <table class="table staff-table academic-table backup-history-table">
                    <caption class="visually-hidden">System backup history and available recovery actions</caption>
                    <thead><tr><th scope="col">Created</th><th scope="col">Archive</th><th scope="col">Size</th><th scope="col">Verification</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= e($manilaTime((string) $backup['created_at'])) ?></td>
                            <td><strong><?= e($backup['storage_name']) ?></strong><?php if (!empty($backup['failure_message'])): ?><small class="backup-failure-message"><?= e($backup['failure_message']) ?></small><?php endif; ?></td>
                            <td><?= e($formatBackupSize((int) $backup['file_size_bytes'])) ?></td>
                            <td><span class="backup-verification-status is-<?= e($backup['verification_status']) ?>"><?= e(ucfirst((string) $backup['verification_status'])) ?></span><?php if (!empty($backup['verified_at'])): ?><small><?= e($manilaTime((string) $backup['verified_at'])) ?></small><?php endif; ?></td>
                            <td><div class="backup-row-actions">
                                <?php if ($backup['verification_status'] === 'verified'): ?>
                                    <a class="btn btn-sm btn-outline-navy" href="/admin/backups/download?id=<?= (int) $backup['id'] ?>" aria-label="Download <?= e($backup['storage_name']) ?>" title="Download backup"><i class="bi bi-download" aria-hidden="true"></i></a>
                                <?php endif; ?>
                                <form method="post" action="/admin/backups/verify" data-submit-once><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="backup_id" value="<?= (int) $backup['id'] ?>"><button class="btn btn-sm btn-outline-navy" type="submit"><i class="bi bi-shield-check" aria-hidden="true"></i><span class="visually-hidden">Verify backup</span></button></form>
                                <?php if ($backup['verification_status'] === 'verified'): ?>
                                    <form method="post" action="/admin/backups/restore" class="backup-restore-form" data-submit-once data-confirm="Restore this database and its uploaded files? Current application data will be replaced; an automatic safety backup is created first.">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="backup_id" value="<?= (int) $backup['id'] ?>">
                                        <label class="visually-hidden" for="restore-confirm-<?= (int) $backup['id'] ?>">Type RESTORE to confirm recovery</label><input class="form-control form-control-sm" id="restore-confirm-<?= (int) $backup['id'] ?>" name="confirmation" type="text" autocomplete="off" placeholder="Type RESTORE" required pattern="RESTORE" aria-label="Type RESTORE to confirm restoring <?= e($backup['storage_name']) ?>">
                                        <button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><span class="visually-hidden">Restore this backup</span></button>
                                    </form>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <aside class="backup-restore-warning" role="note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p><strong>Restore replaces current data.</strong> The app verifies the archive and creates a fresh safety backup before importing. Keep backups off the web server when possible, and test recovery in a separate environment before relying on it for production disaster recovery.</p></aside>
</section>

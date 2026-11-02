<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/AuditLog.php';
require dirname(__DIR__) . '/app/AvatarService.php';
require dirname(__DIR__) . '/app/StaffAccountService.php';

function request_ip(): ?string
{
    return '192.0.2.30';
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};
$makeDatabase = static function (array $users = [[1, 'Fixture Admin', 'admin@example.test', 'admin', 'active'], [3, 'Fixture Reviewer', 'reviewer@example.test', 'reviewer', 'active'], [22, 'Removable User', 'removable@example.test', 'reviewer', 'active']]): PDO {
    $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL, account_status TEXT NOT NULL, password_hash TEXT NOT NULL DEFAULT "hash")');
    foreach ([
        'student_profiles' => 'user_id INTEGER PRIMARY KEY',
        'applications' => 'id INTEGER PRIMARY KEY, student_id INTEGER',
        'application_documents' => 'id INTEGER PRIMARY KEY, uploaded_by INTEGER',
        'document_reviews' => 'id INTEGER PRIMARY KEY, reviewer_id INTEGER',
        'reviewer_assignments' => 'id INTEGER PRIMARY KEY, reviewer_id INTEGER, assigned_by INTEGER',
        'screening_notes' => 'id INTEGER PRIMARY KEY, author_id INTEGER',
        'interviews' => 'id INTEGER PRIMARY KEY, interviewer_id INTEGER, created_by INTEGER',
        'enrollment_records' => 'id INTEGER PRIMARY KEY, prepared_by INTEGER',
        'announcements' => 'id INTEGER PRIMARY KEY, created_by INTEGER',
        'notifications' => 'id INTEGER PRIMARY KEY, user_id INTEGER',
        'password_reset_tokens' => 'id INTEGER PRIMARY KEY, user_id INTEGER',
    ] as $table => $columns) $database->exec('CREATE TABLE ' . $table . ' (' . $columns . ')');
    $database->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY, changed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL)');
    $database->exec('CREATE TABLE backups (id INTEGER PRIMARY KEY, created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL)');
    $database->exec('CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL)');
    $database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NULL, entity_id INTEGER NULL, outcome TEXT NOT NULL, severity TEXT NOT NULL, ip_address TEXT NULL, details TEXT NULL, created_at TEXT NOT NULL)');
    $insert = $database->prepare('INSERT INTO users (id, full_name, email, role, account_status) VALUES (:id, :name, :email, :role, :status)');
    foreach ($users as [$id, $name, $email, $role, $status]) $insert->execute(['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role, 'status' => $status]);
    return $database;
};
$admin = ['id' => 1, 'full_name' => 'Fixture Admin', 'role' => 'admin'];
$targetId = 22;

$database = $makeDatabase();
$database->exec('INSERT INTO student_profiles VALUES (22)');
$database->exec('INSERT INTO notifications VALUES (1, 22)');
$database->exec('INSERT INTO password_reset_tokens VALUES (1, 22)');
StaffAccountService::delete($database, $admin, $targetId);
$deletedAudit = $database->query("SELECT user_id, action, details, actor_label, created_at FROM audit_logs WHERE action='user.account_deleted'")->fetch();
$deletedDetails = json_decode((string) $deletedAudit['details'], true);
$assert((int) $database->query('SELECT COUNT(*) FROM users WHERE id=22')->fetchColumn() === 0 && (int) $database->query('SELECT COUNT(*) FROM student_profiles WHERE user_id=22')->fetchColumn() === 0 && (int) $database->query('SELECT COUNT(*) FROM notifications WHERE user_id=22')->fetchColumn() === 0 && (int) $database->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=22')->fetchColumn() === 0, 'eligible account deletion removes only the account-owned profile, notification, and reset-token rows');
$assert((int) $deletedAudit['user_id'] === 1 && $deletedAudit['actor_label'] === 'Fixture Admin' && $deletedAudit['action'] === 'user.account_deleted' && $deletedAudit['created_at'] !== '' && $deletedDetails['deleted_account']['email'] === 'removable@example.test', 'successful deletion is audited once with acting admin and safe deleted-account summary');

$avatarUserId = random_int(100000000, 200000000);
$avatarDatabase = $makeDatabase([[1, 'Fixture Admin', 'admin@example.test', 'admin', 'active'], [$avatarUserId, 'Avatar Fixture', 'avatar@example.test', 'reviewer', 'active']]);
$avatarRoot = dirname(__DIR__) . '/storage/avatars';
$createdAvatarRoot = !is_dir($avatarRoot);
if ($createdAvatarRoot) mkdir($avatarRoot, 0700, true);
$avatarName = 'avatar-' . $avatarUserId . '-' . bin2hex(random_bytes(12)) . '.png';
$avatarPath = $avatarRoot . DIRECTORY_SEPARATOR . $avatarName;
$avatarPointer = $avatarRoot . DIRECTORY_SEPARATOR . 'user-' . $avatarUserId . '.current';
try {
    file_put_contents($avatarPath, 'synthetic avatar fixture');
    file_put_contents($avatarPointer, $avatarName);
    StaffAccountService::delete($avatarDatabase, $admin, $avatarUserId);
    $assert((int) $avatarDatabase->query('SELECT COUNT(*) FROM users WHERE id=' . $avatarUserId)->fetchColumn() === 0 && !file_exists($avatarPath) && !file_exists($avatarPointer), 'eligible deletion removes the account avatar file and pointer');
} finally {
    foreach (glob($avatarPath . '.delete-*') ?: [] as $stagedPath) @unlink($stagedPath);
    foreach (glob($avatarPointer . '.delete-*') ?: [] as $stagedPath) @unlink($stagedPath);
    if (is_file($avatarPath)) unlink($avatarPath);
    if (is_file($avatarPointer)) unlink($avatarPointer);
    if ($createdAvatarRoot && is_dir($avatarRoot)) @rmdir($avatarRoot);
}

$blockers = [
    'applications' => 'INSERT INTO applications VALUES (1, 22)',
    'application_documents' => 'INSERT INTO application_documents VALUES (1, 22)',
    'document_reviews' => 'INSERT INTO document_reviews VALUES (1, 22)',
    'reviewer_assignments reviewer_id' => 'INSERT INTO reviewer_assignments VALUES (1, 22, 1)',
    'reviewer_assignments assigned_by' => 'INSERT INTO reviewer_assignments VALUES (1, 1, 22)',
    'screening_notes' => 'INSERT INTO screening_notes VALUES (1, 22)',
    'interviews interviewer_id' => 'INSERT INTO interviews VALUES (1, 22, 1)',
    'interviews created_by' => 'INSERT INTO interviews VALUES (1, 1, 22)',
    'enrollment_records' => 'INSERT INTO enrollment_records VALUES (1, 22)',
    'announcements' => 'INSERT INTO announcements VALUES (1, 22)',
];
foreach ($blockers as $label => $sql) {
    $blockedDatabase = $makeDatabase();
    $blockedDatabase->exec($sql);
    $blocked = false;
    try {
        StaffAccountService::delete($blockedDatabase, $admin, $targetId);
    } catch (DomainException) {
        $blocked = true;
    }
    $auditCount = (int) $blockedDatabase->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.account_delete_blocked' AND outcome='warning'")->fetchColumn();
    $assert($blocked && (int) $blockedDatabase->query('SELECT COUNT(*) FROM users WHERE id=22')->fetchColumn() === 1 && $auditCount === 1, 'blocking history prevents deletion and audits the attempt: ' . $label);
}

$auditOnlyDatabase = $makeDatabase();
$auditOnlyDatabase->exec("INSERT INTO audit_logs (user_id, actor_label, action, entity_type, outcome, severity, created_at) VALUES (22, 'Removable User', 'historic.event', 'user', 'success', 'notice', '2026-01-01 00:00:00')");
StaffAccountService::delete($auditOnlyDatabase, $admin, $targetId);
$assert((int) $auditOnlyDatabase->query('SELECT COUNT(*) FROM users WHERE id=22')->fetchColumn() === 0 && (int) $auditOnlyDatabase->query("SELECT COUNT(*) FROM audit_logs WHERE action='historic.event'")->fetchColumn() === 1, 'audit history alone does not block deletion and remains preserved');

$nonBlockingDatabase = $makeDatabase();
$nonBlockingDatabase->exec('INSERT INTO application_status_history VALUES (1, 22)');
$nonBlockingDatabase->exec('INSERT INTO backups VALUES (1, 22)');
$nonBlockingDatabase->exec("INSERT INTO system_settings VALUES ('fixture.setting', 22)");
StaffAccountService::delete($nonBlockingDatabase, $admin, $targetId);
$assert((int) $nonBlockingDatabase->query('SELECT COUNT(*) FROM application_status_history')->fetchColumn() === 1 && $nonBlockingDatabase->query('SELECT changed_by FROM application_status_history')->fetchColumn() === null && $nonBlockingDatabase->query('SELECT created_by FROM backups')->fetchColumn() === null && $nonBlockingDatabase->query('SELECT updated_by FROM system_settings')->fetchColumn() === null, 'non-blocking status, backup, and settings history survives with deleted actor references set to null');

$selfDatabase = $makeDatabase();
$selfBlocked = false;
try {
    StaffAccountService::delete($selfDatabase, $admin, 1);
} catch (DomainException) {
    $selfBlocked = true;
}
$assert($selfBlocked && (int) $selfDatabase->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.account_delete_blocked'")->fetchColumn() === 1, 'administrator self-deletion is refused and audited');

$nonAdminDatabase = $makeDatabase();
$nonAdminBlocked = false;
try {
    StaffAccountService::delete($nonAdminDatabase, ['id' => 3, 'full_name' => 'Reviewer', 'role' => 'reviewer'], $targetId);
} catch (DomainException) {
    $nonAdminBlocked = true;
}
$assert($nonAdminBlocked && (int) $nonAdminDatabase->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.account_delete_blocked'")->fetchColumn() === 1, 'non-admin account deletion is refused and audited');

$lastAdminDatabase = $makeDatabase([[1, 'Inactive Admin', 'inactive@example.test', 'admin', 'inactive'], [23, 'Only Active Admin', 'only-active@example.test', 'admin', 'active']]);
$lastAdminBlocked = false;
try {
    StaffAccountService::delete($lastAdminDatabase, ['id' => 1, 'full_name' => 'Inactive Admin', 'role' => 'admin'], 23);
} catch (DomainException) {
    $lastAdminBlocked = true;
}
$assert($lastAdminBlocked && (int) $lastAdminDatabase->query('SELECT COUNT(*) FROM users WHERE id=23')->fetchColumn() === 1, 'last active administrator cannot be deleted');

$rollbackDatabase = $makeDatabase();
$rollbackDatabase->exec('INSERT INTO student_profiles VALUES (22)');
$rollbackDatabase->exec("CREATE TRIGGER fail_account_delete BEFORE DELETE ON users WHEN OLD.id=22 BEGIN SELECT RAISE(ABORT, 'forced delete failure'); END");
$rollbackFailed = false;
try {
    StaffAccountService::delete($rollbackDatabase, $admin, $targetId);
} catch (Throwable) {
    $rollbackFailed = true;
}
$assert($rollbackFailed && (int) $rollbackDatabase->query('SELECT COUNT(*) FROM users WHERE id=22')->fetchColumn() === 1 && (int) $rollbackDatabase->query('SELECT COUNT(*) FROM student_profiles WHERE user_id=22')->fetchColumn() === 1 && (int) $rollbackDatabase->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.account_delete_failed'")->fetchColumn() === 1, 'forced delete failure rolls back removable rows and records one failure audit');

fwrite(STDOUT, 'Account deletion tests passed: ' . $assertions . PHP_EOL);
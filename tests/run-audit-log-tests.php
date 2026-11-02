<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/AuditLog.php';

function request_ip(): ?string
{
    return '192.0.2.10';
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER NULL, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NULL, entity_id INTEGER NULL, outcome TEXT NOT NULL, severity TEXT NOT NULL, ip_address TEXT NULL, request_id TEXT NULL, details TEXT NULL, created_at TEXT NOT NULL)');
$_SERVER['HTTP_X_REQUEST_ID'] = '123e4567-e89b-42d3-a456-426614174000';
$database->beginTransaction();
AuditLog::record($database, null, 'auth.login_failed', 'user', null, 'failure', ['attempt' => 'rejected', 'date_of_birth' => '2000-01-01', 'nested' => ['reset_token' => 'secret', 'role' => 'reviewer']]);
$assert($database->inTransaction(), 'audit helper does not take ownership of an open transaction');
$database->commit();
$row = $database->query('SELECT * FROM audit_logs')->fetch();
$details = json_decode((string) $row['details'], true);
$assert($row['actor_label'] === 'Anonymous' && $row['user_id'] === null && $row['outcome'] === 'failure' && $row['severity'] === 'warning', 'anonymous events use a safe actor and preserve outcome metadata without inventing a user id');
$assert($row['ip_address'] === '192.0.2.10' && $row['request_id'] === $_SERVER['HTTP_X_REQUEST_ID'] && gmdate('Y-m-d H:i:s') >= $row['created_at'], 'audit event records request metadata and UTC timestamp');
$assert($details === ['attempt' => 'rejected', 'nested' => ['role' => 'reviewer']] && !str_contains((string) $row['details'], '2000-01-01') && !str_contains((string) $row['details'], 'secret'), 'audit details redact residential birth-date and reset-token values recursively');
$viewUser = ['id' => 7, 'full_name' => 'Fixture Reviewer', 'role' => 'reviewer'];
$firstView = AuditLog::recordOncePerWindow($database, $viewUser, 'application.viewed', 'application', 88, 600);
$secondView = AuditLog::recordOncePerWindow($database, $viewUser, 'application.viewed', 'application', 88, 600);
$viewCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.viewed' AND user_id=7 AND entity_id=88")->fetchColumn();
$assert($firstView && !$secondView && $viewCount === 1, 'application detail views de-duplicate by user and application within the window');
$database->exec("UPDATE audit_logs SET created_at = '2026-01-01 00:00:00' WHERE action='application.viewed' AND user_id=7 AND entity_id=88");
$laterView = AuditLog::recordOncePerWindow($database, $viewUser, 'application.viewed', 'application', 88, 600);
$otherViewer = ['id' => 8, 'full_name' => 'Another Reviewer', 'role' => 'reviewer'];
$otherUserView = AuditLog::recordOncePerWindow($database, $otherViewer, 'application.viewed', 'application', 88, 600);
$viewCountAfterWindowAndOtherUser = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.viewed' AND entity_id=88")->fetchColumn();
$assert($laterView && $otherUserView && $viewCountAfterWindowAndOtherUser === 3, 'a new detail view after ten minutes and another user each produce one independent audit row');

$reducedDatabase = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$reducedDatabase->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER NULL, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NULL, entity_id INTEGER NULL, outcome TEXT NOT NULL, severity TEXT NOT NULL, ip_address TEXT NULL)');
AuditLog::record($reducedDatabase, ['id' => 7, 'full_name' => 'Fixture Admin'], 'user.updated', 'user', 9, 'success');
$assert((int) $reducedDatabase->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1, 'audit helper supports isolated schemas without optional metadata columns');

fwrite(STDOUT, 'Audit log tests passed: ' . $assertions . PHP_EOL);
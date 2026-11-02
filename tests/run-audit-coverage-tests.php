<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/AuditLog.php';
require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/app/ApplicationService.php';
require dirname(__DIR__) . '/app/BackupIntegrity.php';
require dirname(__DIR__) . '/app/BackupService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, email TEXT NOT NULL DEFAULT "", password_hash TEXT NOT NULL DEFAULT "fixture", role TEXT NOT NULL, account_status TEXT NOT NULL, last_login_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE student_profiles (user_id INTEGER PRIMARY KEY, mobile_number TEXT, date_of_birth TEXT, address_line TEXT, municipality TEXT, province TEXT, emergency_contact_name TEXT, emergency_contact_number TEXT, privacy_consent_at TEXT)');
$database->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY AUTOINCREMENT, reference_number TEXT, student_id INTEGER NOT NULL, academic_program_id INTEGER, academic_year_id INTEGER, preferred_campus TEXT, previous_school TEXT, senior_high_strand TEXT, applicant_profile_snapshot TEXT, status TEXT NOT NULL DEFAULT "draft", submitted_at TEXT, deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, reviewer_id INTEGER, assigned_by INTEGER, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, unassigned_at TEXT)');
$database->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, from_status TEXT, to_status TEXT, changed_by INTEGER, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, code TEXT, name TEXT, description TEXT, is_active INTEGER DEFAULT 1)');
$database->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT, starts_on TEXT, ends_on TEXT, application_deadline TEXT, is_open INTEGER DEFAULT 1)');
$database->exec('CREATE TABLE admission_requirements (id INTEGER PRIMARY KEY, code TEXT, name TEXT, instructions TEXT, academic_program_id INTEGER, academic_year_id INTEGER, allowed_extensions TEXT, max_file_size_bytes INTEGER, is_required INTEGER, is_active INTEGER, sort_order INTEGER DEFAULT 0)');
$database->exec('CREATE TABLE application_documents (id INTEGER PRIMARY KEY, application_id INTEGER, requirement_id INTEGER, version INTEGER DEFAULT 1, storage_name TEXT, original_name TEXT, detected_mime_type TEXT, file_size_bytes INTEGER, sha256 TEXT, is_current INTEGER, review_status TEXT, uploaded_by INTEGER, uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE document_reviews (id INTEGER PRIMARY KEY, document_id INTEGER, reviewer_id INTEGER, decision TEXT, feedback TEXT, reviewed_at TEXT)');
$database->exec('CREATE TABLE interviews (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, interviewer_id INTEGER, starts_at_utc TEXT, ends_at_utc TEXT, instructions TEXT, status TEXT, notes TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE screening_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, author_id INTEGER, note TEXT, visibility TEXT DEFAULT "staff_only", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE enrollment_records (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER UNIQUE, status TEXT, checklist TEXT, prepared_by INTEGER, completed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, title TEXT, message TEXT, related_type TEXT, related_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE backups (id INTEGER PRIMARY KEY AUTOINCREMENT, backup_type TEXT, storage_name TEXT UNIQUE, file_size_bytes INTEGER, sha256 TEXT, verification_status TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, verified_at TEXT, failure_message TEXT)');
$database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NULL, entity_id INTEGER NULL, outcome TEXT NOT NULL, severity TEXT NOT NULL, ip_address TEXT NULL, request_id TEXT NULL, details TEXT NULL, created_at TEXT NOT NULL)');
$database->exec("INSERT INTO users (id, full_name, email, role, account_status) VALUES (1, 'Coverage Admin', 'admin@coverage.test', 'admin', 'active'), (2, 'Coverage Student', 'student@coverage.test', 'student', 'active'), (3, 'Coverage Reviewer', 'reviewer@coverage.test', 'reviewer', 'active'), (4, 'Draft Applicant', 'draft@coverage.test', 'student', 'active')");
$database->exec('INSERT INTO student_profiles (user_id) VALUES (2), (4)');
$database->exec("INSERT INTO academic_programs (id, code, name, is_active) VALUES (1, 'COV', 'Coverage Program', 1)");
$database->exec("INSERT INTO academic_years (id, label, starts_on, ends_on, is_open) VALUES (1, 'Coverage Year', '2026-01-01', '2027-01-01', 1)");
$database->exec("INSERT INTO applications (id, student_id, academic_program_id, academic_year_id, status, submitted_at) VALUES (40, 2, 1, 1, 'submitted', UTC_TIMESTAMP()), (41, 2, 1, 1, 'interview_scheduled', UTC_TIMESTAMP()), (42, 2, 1, 1, 'accepted', UTC_TIMESTAMP())");
$admin = ['id' => 1, 'full_name' => 'Coverage Admin', 'role' => 'admin'];
$reviewer = ['id' => 3, 'full_name' => 'Coverage Reviewer', 'role' => 'reviewer'];
$backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-audit-coverage-' . bin2hex(random_bytes(6));
mkdir($backupRoot, 0700, true);
$requiredDumpTables = ['users','student_profiles','academic_years','academic_programs','applications','admission_requirements','application_documents','document_reviews','reviewer_assignments','screening_notes','interviews','enrollment_records','announcements','notifications','application_status_history','audit_logs','password_reset_tokens','login_attempts','backups','system_settings'];
$dumpWriter = static function (string $target) use ($requiredDumpTables): int {
    $dump = "-- MySQL dump 10.13\n";
    foreach ($requiredDumpTables as $table) $dump .= 'CREATE TABLE `' . $table . "` (id INTEGER);\n";
    $dump .= "-- Dump completed on 2026-11-02 00:00:00\n-- fixture padding " . str_repeat('x', 1200) . "\n";
    file_put_contents($target, $dump);
    return 0;
};

$cases = [
    'application.archived' => static function () use ($database, $admin): void {
        StaffService::archiveApplication($database, $admin, 40);
    },
    'application.restored' => static function () use ($database, $admin): void {
        StaffService::restoreApplication($database, $admin, 40);
    },
    'report.exported' => static function () use ($database, $admin): void {
        StaffService::recordReportExport($database, $admin, ['status' => 'submitted'], 1);
    },
    'report.print_preview_opened' => static function () use ($database, $reviewer): void {
        StaffService::recordReportPrintPreview($database, $reviewer, ['status' => 'submitted'], 1);
    },
    'application.viewed' => static function () use ($database, $reviewer): void {
        AuditLog::recordOncePerWindow($database, $reviewer, 'application.viewed', 'application', 40, 600);
    },
    'application.created' => static function () use ($database): void {
        $result = ApplicationService::save($database, 4, [], [], false);
        if (!$result['ok']) throw new RuntimeException('Draft creation fixture was rejected.');
    },
    'application.draft_updated' => static function () use ($database): void {
        $result = ApplicationService::save($database, 4, [], [], false);
        if (!$result['ok']) throw new RuntimeException('Draft update fixture was rejected.');
    },
    'application.note_added' => static function () use ($database, $reviewer): void {
        StaffService::addNote($database, $reviewer, 40, 'Synthetic service fixture note');
    },
    'application.reviewer_assigned' => static function () use ($database, $admin): void {
        StaffService::assignReviewer($database, $admin, 40, 3);
    },
    'interview.completed' => static function () use ($database, $reviewer): void {
        $database->exec("INSERT INTO interviews (id, application_id, interviewer_id, starts_at_utc, ends_at_utc, status, created_by) VALUES (50, 41, 3, '2026-11-01 01:00:00', '2026-11-01 02:00:00', 'scheduled', 1)");
        StaffService::updateInterview($database, $reviewer, 41, 50, 'completed', 'Synthetic completion');
    },
    'enrollment.checklist_updated' => static function () use ($database, $admin): void {
        StaffService::updateEnrollmentChecklist($database, $admin, 42, ['offer_confirmed', 'documents_checked', 'instructions_sent', 'registration_ready']);
    },
    'backup.created' => static function () use ($database, $admin, $dumpWriter, $backupRoot): void {
        BackupService::createDatabase($database, $admin, $dumpWriter, $backupRoot);
    },
];

foreach ($cases as $action => $operation) {
    $before = (int) $database->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $operation();
    $rows = $database->query('SELECT actor_label, action, outcome, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
    $assert((int) $database->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $before + 1, $action . ' writes exactly one event');
    $assert(is_array($rows) && $rows['action'] === $action && trim($rows['actor_label']) !== '' && $rows['created_at'] !== '' && $rows['outcome'] === 'success', $action . ' event has expected action, actor, timestamp, and success outcome');
    $details = (string) ($rows['details'] ?? '');
    $assert(!preg_match('/password|token|address|date.?of.?birth|secret|2000-01-01/i', $details), $action . ' details contain no credential or residential data');
}

$beforeBlocked = (int) $database->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$blocked = false;
try {
    StaffService::archiveApplication($database, $reviewer, 40);
} catch (DomainException) {
    $blocked = true;
}
$blockedCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.archive_blocked' AND outcome='warning'")->fetchColumn();
$successCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.archived' AND outcome='success'")->fetchColumn();
$assert($blocked && (int) $database->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $beforeBlocked + 1 && $blockedCount === 1 && $successCount === 1, 'refused reviewer archive records a warning and never adds a misleading success event');

$invalidNoteRejected = false;
$successNotesBefore = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.note_added'")->fetchColumn();
try {
    StaffService::addNote($database, $reviewer, 40, '');
} catch (DomainException) {
    $invalidNoteRejected = true;
}
$assert($invalidNoteRejected && (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.note_added'")->fetchColumn() === $successNotesBefore, 'invalid application note does not produce a misleading success event');

$database->exec("UPDATE audit_logs SET created_at = '2020-01-01 00:00:00' WHERE action='application.viewed'");
$viewBeforeExpiry = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.viewed'")->fetchColumn();
AuditLog::recordOncePerWindow($database, $reviewer, 'application.viewed', 'application', 40, 600);
$assert((int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.viewed'")->fetchColumn() === $viewBeforeExpiry + 1, 'detail-view event is eligible again after the ten-minute window');

foreach (glob($backupRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $backupPath) if (is_file($backupPath)) unlink($backupPath);
@rmdir($backupRoot);

fwrite(STDOUT, 'Audit coverage tests passed: ' . $assertions . PHP_EOL);

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/StatusTransitionPolicy.php';
require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';

function request_ip(): ?string
{
    return '192.0.2.90';
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return 'task6b-fixture-csrf';
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL, account_status TEXT NOT NULL)');
$database->exec('CREATE TABLE student_profiles (user_id INTEGER PRIMARY KEY, mobile_number TEXT, date_of_birth TEXT, address_line TEXT, municipality TEXT, province TEXT, emergency_contact_name TEXT, emergency_contact_number TEXT)');
$database->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$database->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT NOT NULL, starts_on TEXT, ends_on TEXT, is_open INTEGER DEFAULT 1)');
$database->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, reference_number TEXT, student_id INTEGER NOT NULL, academic_program_id INTEGER, academic_year_id INTEGER, preferred_campus TEXT, previous_school TEXT, senior_high_strand TEXT, applicant_profile_snapshot TEXT, status TEXT NOT NULL, submitted_at TEXT, deleted_at TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER NOT NULL, reviewer_id INTEGER NOT NULL, assigned_by INTEGER NOT NULL, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, unassigned_at TEXT)');
$database->exec('CREATE TABLE admission_requirements (id INTEGER PRIMARY KEY, name TEXT, instructions TEXT, is_active INTEGER, is_required INTEGER, academic_program_id INTEGER, academic_year_id INTEGER, sort_order INTEGER DEFAULT 0)');
$database->exec('CREATE TABLE application_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, requirement_id INTEGER, version INTEGER DEFAULT 1, storage_name TEXT, original_name TEXT, detected_mime_type TEXT, file_size_bytes INTEGER DEFAULT 0, sha256 TEXT, review_status TEXT, uploaded_by INTEGER, uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP, is_current INTEGER DEFAULT 1)');
$database->exec('CREATE TABLE document_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, document_id INTEGER, reviewer_id INTEGER, decision TEXT, feedback TEXT, reviewed_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE screening_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, author_id INTEGER, note TEXT, visibility TEXT DEFAULT \'staff_only\', created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, from_status TEXT, to_status TEXT, changed_by INTEGER, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE interviews (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, interviewer_id INTEGER, starts_at_utc TEXT, ends_at_utc TEXT, instructions TEXT, status TEXT DEFAULT \'scheduled\', notes TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE enrollment_records (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, status TEXT, checklist TEXT, prepared_by INTEGER, completed_at TEXT)');
$database->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, title TEXT, message TEXT, related_type TEXT, related_id INTEGER)');
$database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT, entity_id INTEGER, outcome TEXT NOT NULL, severity TEXT, ip_address TEXT, request_id TEXT, details TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$database->exec("INSERT INTO users VALUES (1,'Fixture Admin','admin@example.test','admin','active'),(2,'Reviewer A','a@example.test','reviewer','active'),(3,'Reviewer B','b@example.test','reviewer','active'),(4,'Fixture Student','student@example.test','student','active'),(5,'Inactive Reviewer','inactive@example.test','reviewer','inactive')");
$database->exec("INSERT INTO academic_programs VALUES (1,'Fixture Program')");
$database->exec("INSERT INTO academic_years VALUES (1,'Fixture Year','2026-01-01','2027-01-01',1)");
$insertApplication = $database->prepare('INSERT INTO applications (id,reference_number,student_id,academic_program_id,academic_year_id,status,submitted_at,deleted_at) VALUES (:id,:reference,4,1,1,:status,:submitted,:deleted)');
foreach ([
    [11, 'AF-T6B-11', 'in_screening', null],
    [12, 'AF-T6B-12', 'in_screening', null],
    [13, 'AF-T6B-13', 'in_screening', null],
    [14, 'AF-T6B-14', 'submitted', null],
    [15, 'AF-T6B-15', 'submitted', '2026-11-01 00:00:00'],
    [16, 'AF-T6B-16', 'declined', null],
    [17, 'AF-T6B-17', 'in_screening', null],
    [18, 'AF-T6B-18', 'in_screening', null],
    [19, 'AF-T6B-19', 'in_screening', null],
    [20, 'AF-T6B-20', 'in_screening', null],
] as [$id, $reference, $status, $deleted]) {
    $insertApplication->execute(['id' => $id, 'reference' => $reference, 'status' => $status, 'submitted' => gmdate('Y-m-d H:i:s'), 'deleted' => $deleted]);
}
$assignment = $database->prepare('INSERT INTO reviewer_assignments (application_id, reviewer_id, assigned_by) VALUES (:application_id,:reviewer_id,1)');
foreach ([[12,2],[13,3],[15,3],[17,2],[18,2]] as [$applicationId,$reviewerId]) $assignment->execute(['application_id' => $applicationId, 'reviewer_id' => $reviewerId]);
$database->exec('INSERT INTO admission_requirements VALUES (1,\'Transcript\',\'\',1,1,NULL,NULL,1)');
$database->exec("INSERT INTO application_documents (id,application_id,requirement_id,storage_name,original_name,detected_mime_type,file_size_bytes,sha256,review_status,uploaded_by,is_current) VALUES (101,12,1,'fixture-a.pdf','reviewer-a.pdf','application/pdf',8,'" . hash('sha256', 'fixture-a') . "','pending',4,1),(102,13,1,'fixture-b.pdf','reviewer-b.pdf','application/pdf',8,'" . hash('sha256', 'fixture-b') . "','pending',4,1),(103,15,1,'fixture-archived.pdf','archived.pdf','application/pdf',8,'" . hash('sha256', 'fixture-archived') . "','pending',4,1)");

$reviewerA = ['id' => 2, 'full_name' => 'Reviewer A', 'role' => 'reviewer'];
$reviewerB = ['id' => 3, 'full_name' => 'Reviewer B', 'role' => 'reviewer'];
$admin = ['id' => 1, 'full_name' => 'Fixture Admin', 'role' => 'admin'];
$queueOptions = StaffService::filters($database, $admin);
$assert(array_map('intval', array_column($queueOptions['reviewers'], 'id')) === [2,3] && array_map('intval', array_column($queueOptions['administrators'], 'id')) === [1], 'queue and interviewer option lists contain only active users of the allowed roles');

$claim = StaffService::claimApplication($database, $reviewerA, 11);
$claimConflict = StaffService::claimApplication($database, $reviewerB, 11);
$activeClaim = $database->prepare('SELECT reviewer_id FROM reviewer_assignments WHERE application_id = 11 AND unassigned_at IS NULL');
$activeClaim->execute();
$claimAudits = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.claimed' AND outcome='success'")->fetchColumn();
$assert($claim['ok'] && (int) $activeClaim->fetchColumn() === 2 && $claimAudits === 1, 'reviewer claims an unassigned application with one active row and one success audit');
$assert(!$claimConflict['ok'] && $claimConflict['message'] === 'This application was just claimed by another reviewer.' && $claimAudits === 1, 'competing claim returns the safe conflict and does not log another success');
$archivedClaim = StaffService::claimApplication($database, $reviewerA, 15);
$ineligibleClaim = StaffService::claimApplication($database, $reviewerA, 16);
$unauthorizedClaim = false;
try { StaffService::claimApplication($database, ['id' => 4, 'role' => 'student'], 14); } catch (DomainException) { $unauthorizedClaim = true; }
$adminClaimDenied = false;
try { StaffService::claimApplication($database, $admin, 14); } catch (DomainException) { $adminClaimDenied = true; }
$assert(!$archivedClaim['ok'] && !$ineligibleClaim['ok'] && $unauthorizedClaim && $adminClaimDenied, 'archived, non-claimable, student, and admin claims are refused');

$reviewerAssignedDetail = StaffService::detail($database, $reviewerA, 11);
$reviewerUnassignedDetail = StaffService::detail($database, $reviewerA, 14);
$reviewerOtherDetail = StaffService::detail($database, $reviewerA, 13);
$assert($reviewerAssignedDetail !== null && !empty($reviewerAssignedDetail['has_active_assignment']) && $reviewerUnassignedDetail !== null && empty($reviewerUnassignedDetail['has_active_assignment']) && $reviewerOtherDetail === null, 'reviewer direct-ID detail access permits assigned/unassigned records, identifies claimable unassigned state, and hides another reviewer’s record');
$assert(StaffService::detail($database, $admin, 13) !== null && StaffService::detail($database, $reviewerB, 15, false, [], false, true) === null && StaffService::detail($database, $admin, 15, false, [], false, true) !== null, 'admin may open another reviewer’s active detail and archived detail remains admin-only');
$assert(StaffService::authorizedDocument($database, $reviewerB, 102) !== null && StaffService::authorizedDocument($database, $reviewerA, 102) === null && StaffService::authorizedDocument($database, $reviewerB, 103, false, true) === null, 'document direct-ID access follows reviewer scope and denies archived documents');

StaffService::assignReviewer($database, $admin, 12, 3);
$assignmentHistory = $database->query('SELECT reviewer_id, unassigned_at FROM reviewer_assignments WHERE application_id=12 ORDER BY id')->fetchAll();
$assignmentAuditCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.reviewer_assigned' AND entity_id=12 AND outcome='success'")->fetchColumn();
$assert(count($assignmentHistory) === 2 && $assignmentHistory[0]['unassigned_at'] !== null && (int) $assignmentHistory[1]['reviewer_id'] === 3 && $assignmentAuditCount === 1, 'administrator reassignment preserves history and leaves exactly one active assignment');

$manila = new DateTimeZone('Asia/Manila');
$start = (new DateTimeImmutable('+5 days', $manila))->setTime(10, 0)->format('Y-m-d\\TH:i');
$end = (new DateTimeImmutable('+5 days', $manila))->setTime(11, 0)->format('Y-m-d\\TH:i');
$laterStart = (new DateTimeImmutable('+6 days', $manila))->setTime(10, 0)->format('Y-m-d\\TH:i');
$laterEnd = (new DateTimeImmutable('+6 days', $manila))->setTime(11, 0)->format('Y-m-d\\TH:i');
$adminStart = (new DateTimeImmutable('+7 days', $manila))->setTime(10, 0)->format('Y-m-d\\TH:i');
$adminEnd = (new DateTimeImmutable('+7 days', $manila))->setTime(11, 0)->format('Y-m-d\\TH:i');
StaffService::scheduleInterview($database, $reviewerA, 17, 2, $start, $end, 'Assigned reviewer interview');
$unrelatedInterviewerRejected = false;
try { StaffService::scheduleInterview($database, $reviewerA, 18, 3, $laterStart, $laterEnd, 'Unrelated reviewer'); } catch (DomainException) { $unrelatedInterviewerRejected = true; }
$unassignedInterviewerRejected = false;
try { StaffService::scheduleInterview($database, $reviewerA, 19, 2, $laterStart, $laterEnd, 'Unassigned app'); } catch (DomainException) { $unassignedInterviewerRejected = true; }
$adminInterviewCountBefore = (int) $database->query('SELECT COUNT(*) FROM interviews WHERE application_id=19')->fetchColumn();
StaffService::scheduleInterview($database, $admin, 19, 1, $laterStart, $laterEnd, 'Admin interviewer');
$adminInterviewCountAfter = (int) $database->query('SELECT COUNT(*) FROM interviews WHERE application_id=19')->fetchColumn();
$assert($unrelatedInterviewerRejected && $unassignedInterviewerRejected && $adminInterviewCountBefore === 0 && $adminInterviewCountAfter === 1, 'unrelated reviewers and unassigned reviewer scheduling are denied while an administrator may interview');
$database->exec('INSERT INTO reviewer_assignments (application_id, reviewer_id, assigned_by) VALUES (20, 2, 1)');
$interviewConflictRejected = false;
try { StaffService::scheduleInterview($database, $reviewerA, 20, 2, $start, $end, 'Conflicting appointment'); } catch (DomainException) { $interviewConflictRejected = true; }
$assert($interviewConflictRejected && (int) $database->query('SELECT COUNT(*) FROM interviews WHERE application_id=20')->fetchColumn() === 0, 'interviewer conflict detection remains enforced for the assigned interviewer');
$scheduledInterviewId = (int) $database->query('SELECT id FROM interviews WHERE application_id=17')->fetchColumn();
$rescheduleRejected = false;
try { StaffService::rescheduleInterview($database, $reviewerA, 17, $scheduledInterviewId, 3, $laterStart, $laterEnd, 'Unrelated reviewer reschedule'); } catch (DomainException) { $rescheduleRejected = true; }
$adminRescheduleSucceeded = false;
try { StaffService::rescheduleInterview($database, $admin, 17, $scheduledInterviewId, 1, $adminStart, $adminEnd, 'Administrator reschedule'); $adminRescheduleSucceeded = true; } catch (DomainException) {}
$assert($rescheduleRejected && $adminRescheduleSucceeded && (int) $database->query('SELECT interviewer_id FROM interviews WHERE id=' . $scheduledInterviewId)->fetchColumn() === 1, 'reschedule blocks unrelated reviewer and allows active administrator');
$assert((int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='interview.scheduled' AND outcome='success'")->fetchColumn() === 2 && (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='interview.rescheduled' AND outcome='success'")->fetchColumn() === 1, 'successful interview schedule/reschedule operations each write one existing audit action');

$adminQueue = StaffService::applications($database, $admin, ['assigned_to' => '3', 'archived' => 'active', 'status' => 'in_screening']);
$adminAssignedIds = array_map('intval', array_column($adminQueue['rows'], 'id'));
sort($adminAssignedIds);
$adminArchivedAssigned = StaffService::applications($database, $admin, ['assigned_to' => '3', 'archived' => 'archived']);
$adminArchivedAssignedIds = array_map('intval', array_column($adminArchivedAssigned['rows'], 'id'));
$adminUnassigned = StaffService::applications($database, $admin, ['assigned_to' => 'unassigned']);
$adminUnassignedIds = array_map('intval', array_column($adminUnassigned['rows'], 'id'));
sort($adminUnassignedIds);
$reviewerCraftedFilter = StaffService::applications($database, $reviewerA, ['assigned_to' => '3', 'reviewer_id' => '3']);
$reviewerIds = array_map('intval', array_column($reviewerCraftedFilter['rows'], 'id'));
$assert($adminAssignedIds === [12,13] && $adminQueue['filters']['assigned_to'] === '3', 'admin Assigned to filter combines with status and active/archive selection');
$assert($adminArchivedAssignedIds === [15], 'Assigned to filter combines with the admin-only Archived filter');
$assert($adminUnassignedIds === [14,16,19], 'admin can filter unassigned active applications across statuses');
$assert(!in_array(13, $reviewerIds, true) && in_array(11, $reviewerIds, true) && !isset($reviewerCraftedFilter['filters']['assigned_to']) && !isset($reviewerCraftedFilter['filters']['reviewer_id']), 'crafted reviewer Assigned to parameters are ignored and reviewer scope remains assigned-or-unassigned');
$inactiveFilterRejected = false;
try { StaffService::applications($database, $admin, ['assigned_to' => '5']); } catch (DomainException) { $inactiveFilterRejected = true; }
$assert($inactiveFilterRejected, 'Assigned to filter rejects inactive reviewer IDs');

$statusLabels = ['submitted' => 'Submitted', 'in_screening' => 'In screening', 'documents_incomplete' => 'Documents incomplete', 'interview_scheduled' => 'Interview scheduled', 'accepted' => 'Accepted', 'declined' => 'Declined', 'enrolled' => 'Enrolled'];
$programs = [['id' => 1, 'name' => 'Fixture Program']];
$years = [['id' => 1, 'label' => 'Fixture Year']];
$reviewers = [['id' => 2, 'full_name' => 'Reviewer A'], ['id' => 3, 'full_name' => 'Reviewer B']];
$administrators = [['id' => 1, 'full_name' => 'Fixture Admin']];
$renderApplications = static function (array $viewer, array $applicationResult) use ($statusLabels, $programs, $years, $reviewers, $administrators): string {
    $user = $viewer;
    $applications = $applicationResult;
    $applicationQueuePath = $user['role'] === 'admin' ? '/admin/applications' : '/staff/applications';
    $overviewPath = $user['role'] === 'admin' ? '/admin' : '/staff';
    ob_start();
    require dirname(__DIR__) . '/resources/views/staff/applications.php';
    return (string) ob_get_clean();
};
$adminQueueMarkup = $renderApplications($admin, $adminQueue);
$studentQueueMarkup = $renderApplications(['id' => 99, 'role' => 'student'], $reviewerCraftedFilter);
$adminPaginationFixture = $adminQueue;
$adminPaginationFixture['pagination'] = Pagination::make(25, ['per_page' => 10]);
$adminPagedQueueMarkup = $renderApplications($admin, $adminPaginationFixture);
$defaultAdminQueueMarkup = $renderApplications($admin, StaffService::applications($database, $admin, []));
$reviewerQueueMarkup = $renderApplications($reviewerA, $reviewerCraftedFilter);
$singleQueue = StaffService::applications($database, $reviewerA, ['status' => 'declined']);
$singleQueueMarkup = $renderApplications($reviewerA, $singleQueue);
$assert(str_contains($adminQueueMarkup, 'name="assigned_to"') && str_contains($adminQueueMarkup, 'data-clear-filter="assigned_to"') && str_contains($adminQueueMarkup, 'class="btn btn-navy application-more-filters"') && str_contains($adminQueueMarkup, 'class="application-advanced-count"'), 'admin queue renders selected Assigned to chip and active More Filters styling');
$assert(!str_contains($defaultAdminQueueMarkup, 'action="/staff/application/claim"') && !str_contains($studentQueueMarkup, 'action="/staff/application/claim"'), 'administrators and students never see reviewer Claim actions');
$pagedMarkup = html_entity_decode($adminPagedQueueMarkup, ENT_QUOTES | ENT_HTML5);
$assert(str_contains($pagedMarkup, 'assigned_to=3') && str_contains($pagedMarkup, 'archived=active') && str_contains($defaultAdminQueueMarkup, 'application-advanced-count is-empty'), 'pagination preserves Assigned to and Archived filters and hides the badge at zero');
$assert(!str_contains($reviewerQueueMarkup, 'name="assigned_to"') && str_contains($reviewerQueueMarkup, 'Showing applications assigned to you and unassigned applications.'), 'reviewer queue hides Assigned to and displays reviewer scope note');
$assert(str_contains($reviewerQueueMarkup, 'action="/staff/application/claim"') && str_contains($reviewerQueueMarkup, 'name="_csrf"') && str_contains($reviewerQueueMarkup, 'data-confirm="Claim this unassigned application for your review?"'), 'eligible reviewer queue rows render a CSRF-protected confirmed Claim action');
$assert(str_contains($reviewerQueueMarkup, 'class="application-row-actions"') && str_contains($reviewerQueueMarkup, 'class="btn btn-sm btn-amber"') && str_contains($reviewerQueueMarkup, 'class="btn btn-sm btn-outline-navy" href="/staff/application/') && str_contains($reviewerQueueMarkup, '>Review <i'), 'reviewer queue groups Claim and Review controls in one action container');
$assert(str_contains($singleQueueMarkup, 'class="application-row-actions"') && !str_contains($singleQueueMarkup, 'action="/staff/application/claim"') && str_contains($singleQueueMarkup, '>Review <i'), 'rows without an eligible Claim action still render Review in the shared action group');
$assert(!str_contains($adminQueueMarkup, 'Showing applications assigned to you') && preg_match('/>1 record</', $singleQueueMarkup) === 1 && preg_match('/>1 records</', $singleQueueMarkup) !== 1, 'admin hides reviewer scope note and queue count uses singular record');
$assert(!preg_match('/<script\b|<style\b|\son[a-z]+\s*=/i', $adminQueueMarkup . $reviewerQueueMarkup), 'Task 6B queue markup adds no inline scripts, styles, or event handlers');
$frontController = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert(str_contains($frontController, "\$path === '/staff/application/claim'") && str_contains($frontController, 'StaffService::claimApplication') && str_contains($frontController, 'verify_csrf();') && str_contains($frontController, 'StaffService::authorizedDocument'), 'front controller wires the CSRF-guarded claim service and existing reviewer-scoped document service routes');

fwrite(STDOUT, 'Task 6B isolated tests: ' . $assertions . " assertions, 0 failures.\n");

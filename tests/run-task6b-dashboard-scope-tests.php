<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/AuditLog.php';
require dirname(__DIR__) . '/app/StaffService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, status TEXT NOT NULL, deleted_at TEXT NULL, submitted_at TEXT NULL, academic_year_id INTEGER NULL, academic_program_id INTEGER NULL)');
$database->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY, application_id INTEGER NOT NULL, reviewer_id INTEGER NOT NULL, unassigned_at TEXT NULL)');
$database->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY, application_id INTEGER NOT NULL, to_status TEXT NOT NULL, created_at TEXT NOT NULL)');
$database->exec('CREATE TABLE interviews (id INTEGER PRIMARY KEY, application_id INTEGER NOT NULL, status TEXT NOT NULL, starts_at_utc TEXT NOT NULL)');
$database->exec('CREATE TABLE enrollment_records (id INTEGER PRIMARY KEY, application_id INTEGER NOT NULL, status TEXT NOT NULL)');
$database->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT NOT NULL, starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, application_deadline TEXT NULL, is_open INTEGER NOT NULL)');
$database->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

$monthStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('first day of this month')->setTime(0, 0);
$submission = static fn (int $hoursAgo): string => $monthStart->add(new DateInterval('P2D'))->format('Y-m-d') . ' 08:00:00';
$historyAt = static fn (string $submitted, int $hours): string => (new DateTimeImmutable($submitted, new DateTimeZone('UTC')))->add(new DateInterval('PT' . $hours . 'H'))->format('Y-m-d H:i:s');
$database->exec("INSERT INTO academic_programs VALUES (1,'Program A'),(2,'Program B')");
$database->exec("INSERT INTO academic_years VALUES (1,'Open Intake','2026-01-01','2026-12-31',NULL,1)");
$insert = $database->prepare('INSERT INTO applications (id,status,deleted_at,submitted_at,academic_year_id,academic_program_id) VALUES (:id,:status,:deleted,:submitted,1,:program)');
$fixtures = [
    [1, 'accepted', null, $submission(0), 1],
    [2, 'enrolled', null, $submission(0), 2],
    [3, 'documents_incomplete', null, $submission(0), 1],
    [4, 'accepted', '2026-11-01 00:00:00', $submission(0), 2],
    [5, 'submitted', null, $submission(0), 2],
    [6, 'draft', null, null, 1],
    [7, 'interview_scheduled', null, $submission(0), 1],
    [8, 'interview_scheduled', null, $submission(0), 2],
    [9, 'accepted', null, $submission(0), 2],
    [10, 'interview_scheduled', null, $submission(0), 1],
    [11, 'in_screening', null, $submission(0), 2],
];
foreach ($fixtures as [$id, $status, $deleted, $submitted, $program]) {
    $insert->execute(['id' => $id, 'status' => $status, 'deleted' => $deleted, 'submitted' => $submitted, 'program' => $program]);
}
$database->exec('INSERT INTO reviewer_assignments VALUES (1,1,3,NULL),(2,2,4,NULL),(3,4,3,NULL),(4,5,3,NULL),(5,7,3,NULL),(6,8,4,NULL),(7,11,4,NULL)');
$historyRows = [];
foreach ([1 => 2, 2 => 10, 3 => 4, 4 => 8, 5 => 6, 7 => 8, 8 => 12, 9 => 3, 10 => 5, 11 => 7] as $applicationId => $hours) {
    $historyRows[] = '(' . $applicationId . ',\'in_screening\',' . $database->quote($historyAt($submission(0), $hours)) . ')';
}
$database->exec('INSERT INTO application_status_history (application_id,to_status,created_at) VALUES ' . implode(',', $historyRows));
$future = (new DateTimeImmutable('+3 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$database->exec("INSERT INTO interviews VALUES (1,7,'scheduled','{$future}'),(2,8,'scheduled','{$future}'),(3,10,'scheduled','{$future}'),(4,4,'scheduled','{$future}')");
$database->exec("INSERT INTO enrollment_records VALUES (1,1,'in_progress'),(2,2,'complete'),(3,3,'pending'),(4,4,'complete'),(5,9,'pending')");

$reviewer = ['id' => 3, 'role' => 'reviewer'];
$otherReviewer = ['id' => 4, 'role' => 'reviewer'];
$admin = ['id' => 9, 'role' => 'admin'];
$reviewerMetrics = StaffService::dashboard($database, $reviewer);
$otherMetrics = StaffService::dashboard($database, $otherReviewer);
$adminMetrics = StaffService::dashboard($database, $admin);
$craftedReviewerMetrics = StaffService::dashboard($database, $reviewer + ['reviewer_id' => 4, 'assigned_to' => '4']);
$expectedReviewerMetrics = ['total' => 6, 'awaiting' => 1, 'accepted' => 2, 'enrolled' => 0, 'incomplete' => 1, 'acceptance_rate' => 33.3, 'average_review_hours' => 4.7, 'upcoming_interviews' => 2, 'enrollment_pending' => 2, 'enrollment_in_progress' => 1, 'enrollment_complete' => 0];
$expectedAdminMetrics = ['total' => 9, 'awaiting' => 2, 'accepted' => 2, 'enrolled' => 1, 'incomplete' => 1, 'acceptance_rate' => 22.2, 'average_review_hours' => 6.3, 'upcoming_interviews' => 3, 'enrollment_pending' => 2, 'enrollment_in_progress' => 1, 'enrollment_complete' => 1];
foreach ($expectedReviewerMetrics as $key => $expected) {
    $actual = $reviewerMetrics[$key];
    if (in_array($key, ['average_review_hours'], true)) $actual = $actual === null ? null : (float) $actual;
    $assert($actual === $expected, 'Reviewer A scoped dashboard metric ' . $key . ' matches assigned plus unassigned active applications');
}
foreach ($expectedAdminMetrics as $key => $expected) {
    $actual = $adminMetrics[$key];
    if ($key === 'average_review_hours') $actual = $actual === null ? null : (float) $actual;
    $assert($actual === $expected, 'Administrator dashboard metric ' . $key . ' includes all active non-draft applications');
}
$assert($otherMetrics['total'] === 6 && $otherMetrics['upcoming_interviews'] === 2, 'Reviewer B metrics include that reviewer’s assignments plus unassigned claimable records');
$assert($craftedReviewerMetrics === $reviewerMetrics, 'forged reviewer filter fields cannot change service-derived dashboard scope');

$reviewerCharts = StaffService::charts($database, $reviewer);
$craftedReviewerCharts = StaffService::charts($database, $reviewer + ['reviewer_id' => 4, 'assigned_to' => '4']);
$adminCharts = StaffService::charts($database, $admin);
$currentMonth = gmdate('Y-m');
$currentReviewerMonth = array_values(array_filter($reviewerCharts['monthly'], static fn (array $row): bool => $row['month_key'] === $currentMonth))[0];
$currentReviewerReviewMonth = array_values(array_filter($reviewerCharts['submitted_reviewed'], static fn (array $row): bool => $row['month_key'] === $currentMonth))[0];
$currentReviewerReviewTime = array_values(array_filter($reviewerCharts['review_time'], static fn (array $row): bool => $row['month_key'] === $currentMonth))[0];
$currentAdminMonth = array_values(array_filter($adminCharts['monthly'], static fn (array $row): bool => $row['month_key'] === $currentMonth))[0];
$currentAdminReviewTime = array_values(array_filter($adminCharts['review_time'], static fn (array $row): bool => $row['month_key'] === $currentMonth))[0];
$reviewerStatuses = array_column($reviewerCharts['statuses'], 'total', 'status');
$adminStatuses = array_column($adminCharts['statuses'], 'total', 'status');
$reviewerPrograms = array_column($reviewerCharts['programs'], 'total', 'label');
$adminPrograms = array_column($adminCharts['programs'], 'total', 'label');
$reviewerStages = array_column($reviewerCharts['intake_progress']['stages'], 'total', 'label');
$adminStages = array_column($adminCharts['intake_progress']['stages'], 'total', 'label');
$assert((int) $currentReviewerMonth['total'] === 6 && (int) $currentReviewerReviewMonth['submitted'] === 6 && (int) $currentReviewerReviewMonth['reviewed'] === 6 && (float) $currentReviewerReviewTime['average_hours'] === 4.7, 'Reviewer A monthly submitted/reviewed and average-time series exclude Reviewer B and archived records');
$assert((int) $currentAdminMonth['total'] === 9 && (float) $currentAdminReviewTime['average_hours'] === 6.3, 'Administrator monthly submitted and average-time series include all active records only');
$assert($reviewerStatuses === ['accepted' => 2, 'documents_incomplete' => 1, 'interview_scheduled' => 2, 'submitted' => 1] && $adminStatuses === ['accepted' => 2, 'documents_incomplete' => 1, 'enrolled' => 1, 'in_screening' => 1, 'interview_scheduled' => 3, 'submitted' => 1], 'status chart counts are scoped for Reviewer A and global-active for administrators');
$assert($reviewerPrograms === ['Program A' => 4, 'Program B' => 2] && $adminPrograms === ['Program B' => 5, 'Program A' => 4], 'program chart counts are scoped for Reviewer A and global-active for administrators');
$assert($reviewerStages === ['Submitted' => 1, 'Screening' => 1, 'Interview' => 2, 'Accepted' => 2, 'Declined' => 0, 'Enrolled' => 0] && $adminStages === ['Submitted' => 1, 'Screening' => 2, 'Interview' => 3, 'Accepted' => 2, 'Declined' => 0, 'Enrolled' => 1], 'intake stages exclude Reviewer B-only and archived records while administrator stages include active applications');
$assert($craftedReviewerCharts['submitted_reviewed'] === $reviewerCharts['submitted_reviewed'] && $craftedReviewerCharts['statuses'] === $reviewerCharts['statuses'] && $craftedReviewerCharts['intake_progress'] === $reviewerCharts['intake_progress'], 'forged reviewer filter fields cannot widen dashboard chart queries');
fwrite(STDOUT, 'Task 6B dashboard scope tests: ' . $assertions . " assertions, 0 failures.\n");
    
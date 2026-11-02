<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return 'fixture-token';
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT, account_status TEXT)');
$database->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, name TEXT)');
$database->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT, starts_on TEXT, ends_on TEXT, is_open INTEGER)');
$database->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, reference_number TEXT, student_id INTEGER, academic_program_id INTEGER, academic_year_id INTEGER, status TEXT, submitted_at TEXT, deleted_at TEXT)');
$database->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY, application_id INTEGER, reviewer_id INTEGER, unassigned_at TEXT)');
$database->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY, application_id INTEGER, from_status TEXT, to_status TEXT, changed_by INTEGER, created_at TEXT)');
$database->exec('CREATE TABLE admission_requirements (id INTEGER PRIMARY KEY, is_active INTEGER, is_required INTEGER, academic_program_id INTEGER, academic_year_id INTEGER)');
$database->exec('CREATE TABLE application_documents (id INTEGER PRIMARY KEY, application_id INTEGER, requirement_id INTEGER, is_current INTEGER, review_status TEXT)');
$database->exec('CREATE TABLE document_reviews (id INTEGER PRIMARY KEY, document_id INTEGER, reviewer_id INTEGER, decision TEXT, reviewed_at TEXT)');
$database->exec('CREATE TABLE interviews (id INTEGER PRIMARY KEY, application_id INTEGER, status TEXT, starts_at_utc TEXT, updated_at TEXT)');
$database->exec('CREATE TABLE screening_notes (id INTEGER PRIMARY KEY, application_id INTEGER, created_at TEXT)');
$database->exec("INSERT INTO users VALUES (1, 'Admin Fixture', 'admin', 'active'), (2, 'Student Fixture', 'student', 'active'), (3, 'Reviewer One', 'reviewer', 'active'), (4, 'Reviewer Two', 'reviewer', 'active'), (5, 'Inactive Reviewer', 'reviewer', 'inactive')");
$database->exec("INSERT INTO academic_programs VALUES (1, 'Fixture Program')");
$manila = new DateTimeZone('Asia/Manila');
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $manila);
$today = $now->format('Y-m-d');
$yearStart = $now->sub(new DateInterval('P30D'))->format('Y-m-d');
$yearEnd = $now->add(new DateInterval('P30D'))->format('Y-m-d');
$database->prepare('INSERT INTO academic_years VALUES (1, :label, :start, :end, 1)')->execute(['label' => 'Configured Intake', 'start' => $yearStart, 'end' => $yearEnd]);
$database->exec('INSERT INTO admission_requirements VALUES (1, 1, 1, NULL, NULL)');

$fixtures = [
    1 => ['in_screening', 1],
    2 => ['interview_scheduled', 1],
    3 => ['accepted', 1],
    4 => ['declined', 1],
    5 => ['submitted', 0],
    6 => ['enrolled', 1],
    7 => ['declined', 0],
    8 => ['in_screening', 0],
];
$insertApplication = $database->prepare('INSERT INTO applications VALUES (:id, :reference, 2, 1, 1, :status, :submitted, NULL)');
$insertHistory = $database->prepare('INSERT INTO application_status_history VALUES (NULL, :application_id, :from_status, :to_status, :actor, :created_at)');
$applicationSubmitted = [];
$historyId = 0;
foreach ($fixtures as $id => [$status, $hasHistory]) {
    $submittedManila = in_array($id, [4, 5, 7, 8], true) ? $now->sub(new DateInterval('P15D')) : $now->sub(new DateInterval('P2D'));
    $submittedUtc = $submittedManila->setTime(10, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
    $applicationSubmitted[$id] = $submittedUtc;
    $insertApplication->execute(['id' => $id, 'reference' => 'AF-TEST-' . $id, 'status' => $status, 'submitted' => $submittedUtc]);
    if ($hasHistory) {
        $statuses = match ($status) {
            'in_screening' => ['submitted', 'in_screening'],
            'interview_scheduled' => ['submitted', 'in_screening', 'interview_scheduled'],
            'accepted' => ['submitted', 'in_screening', 'accepted'],
            'declined' => ['submitted', 'in_screening', 'declined'],
            'enrolled' => ['submitted', 'in_screening', 'interview_scheduled', 'accepted', 'enrolled'],
            default => ['submitted'],
        };
        $fromStatus = 'draft';
        foreach ($statuses as $index => $toStatus) {
            $changedAt = (new DateTimeImmutable($submittedUtc, $utc))->add(new DateInterval('PT' . ($index === 0 ? 0 : $index + 1) . 'H'))->format('Y-m-d H:i:s');
            $insertHistory->execute(['application_id' => $id, 'from_status' => $fromStatus, 'to_status' => $toStatus, 'actor' => $id === 1 || $id === 2 || $id === 4 || $id === 6 ? 3 : null, 'created_at' => $changedAt]);
            $fromStatus = $toStatus;
            $historyId++;
        }
    }
}
$database->exec('INSERT INTO reviewer_assignments VALUES (1, 1, 3, NULL), (2, 8, 4, NULL), (3, 5, 5, NULL)');
$database->exec("INSERT INTO application_documents VALUES (1, 2, 1, 1, 'verified'), (2, 8, 1, 1, 'verified')");
$documentReviewAt = (new DateTimeImmutable($applicationSubmitted[2], $utc))->add(new DateInterval('PT1H'))->format('Y-m-d H:i:s');
$database->prepare('INSERT INTO document_reviews VALUES (1, 1, 3, :decision, :reviewed_at)')->execute(['decision' => 'verified', 'reviewed_at' => $documentReviewAt]);
$database->prepare('INSERT INTO document_reviews VALUES (2, 2, 3, :decision, :reviewed_at)')->execute(['decision' => 'verified', 'reviewed_at' => (new DateTimeImmutable($applicationSubmitted[8], $utc))->add(new DateInterval('PT2H'))->format('Y-m-d H:i:s')]);
$weekStart = $now->modify('monday this week')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
$database->prepare('INSERT INTO interviews VALUES (1, 2, \'scheduled\', :starts, :updated)')->execute(['starts' => (new DateTimeImmutable($weekStart, $utc))->add(new DateInterval('P2D'))->format('Y-m-d H:i:s'), 'updated' => (new DateTimeImmutable($applicationSubmitted[2], $utc))->add(new DateInterval('PT3H'))->format('Y-m-d H:i:s')]);

$admin = ['id' => 1, 'role' => 'admin', 'full_name' => 'Admin Fixture'];
$report = StaffService::applications($database, $admin, [], true, true);
$assert($report['total'] === 8 && count($report['summary']['funnel']) === 5, 'global report returns all authorized applications and five funnel stages');
$assert($report['summary']['reviewed'] === 6, 'reviewed count requires recorded screening history or an actual document review');
$assert((float) $report['summary']['average_review_hours'] === 1.8, 'average review time uses the first qualifying status or document-review timestamp');
$assert(array_column($report['summary']['funnel'], 'count') === [8, 6, 3, 2, 1], 'funnel counts are cumulative and do not infer legacy progression');
$assert($report['summary']['attention']['unassigned'] === 2 && $report['summary']['attention']['interviews_week'] === 1, 'attention counts use active review statuses, active assignment, and Manila calendar week data');
$assert($report['summary']['attention']['idle'] === 2, 'idle count uses only status, note, interview, and submission timestamps');
$assert($report['summary']['documents_verified'] === 2 && $report['summary']['documents_required'] === 8, 'readiness counts verified required documents, not uploaded files');
$screeningDrilldown = StaffService::applications($database, $admin, ['drilldown' => 'stage:screening'], true, true);
$assert($screeningDrilldown['total'] === 8 && $screeningDrilldown['table_total'] === 6 && count($screeningDrilldown['rows']) === 6, 'stage drill-down narrows only the table and preserves global summary totals');
$idleDrilldown = StaffService::applications($database, $admin, ['drilldown' => 'attention:idle'], true, true);
$assert($idleDrilldown['table_total'] === 2, 'idle drill-down uses distinct prepared parameters and returns the actionable rows');
$reviewerReport = StaffService::applications($database, ['id' => 3, 'role' => 'reviewer'], [], true, true);
$assert($reviewerReport['total'] === 6 && !in_array(8, array_column($reviewerReport['rows'], 'id'), true) && !in_array(5, array_column($reviewerReport['rows'], 'id'), true), 'reviewer report preserves existing scope for other and inactive assignments');
$database->exec("UPDATE reviewer_assignments SET unassigned_at = '2026-10-01 00:00:00' WHERE application_id = 8");
$historicalReport = StaffService::applications($database, $admin, [], true, true);
$historicalRow = array_values(array_filter($historicalReport['rows'], static fn (array $row): bool => (int) $row['id'] === 8))[0];
$assert($historicalRow['reviewer_name'] === 'Reviewer One' && (int) $historicalRow['reviewer_is_historical'] === 1, 'removed reviewer remains attributed through a recorded document review');
$termReport = StaffService::applications($database, $admin, ['date_range' => 'term'], true, true);
$assert($termReport['filters']['start_date'] === $yearStart && $termReport['filters']['end_date'] === $yearEnd, 'This Term uses configured open academic-year boundaries');
$last7Report = StaffService::applications($database, $admin, ['date_range' => 'last7'], true, true);
$assert($last7Report['filters']['start_date'] === (new DateTimeImmutable('today', $manila))->sub(new DateInterval('P6D'))->format('Y-m-d') && $last7Report['filters']['end_date'] === $today, 'Last 7 Days uses seven inclusive Manila calendar dates');
$invalidDateRange = false;
try {
    StaffService::applications($database, $admin, ['start_date' => $today, 'end_date' => $yearStart], true, true);
} catch (DomainException) {
    $invalidDateRange = true;
}
$assert($invalidDateRange, 'custom date ranges reject an end date earlier than the start date');

$programs = [['id' => 1, 'name' => 'Fixture Program']];
$years = [['id' => 1, 'label' => 'Configured Intake']];
$reviewers = [['id' => 3, 'full_name' => 'Reviewer One']];
ob_start();
require dirname(__DIR__) . '/resources/views/staff/reports.php';
$reportMarkup = (string) ob_get_clean();
$assert(substr_count($reportMarkup, '<article>') === 4 && str_contains($reportMarkup, 'This Term') && str_contains($reportMarkup, 'Export PDF') && str_contains($reportMarkup, 'Filtered by') === false, 'reports view renders exactly four cards and the new range and PDF controls');
$assert(str_contains($reportMarkup, '/staff/application/1') && str_contains($reportMarkup, 'Documents Incomplete') && str_contains($reportMarkup, 'data-auto-filter-form'), 'report table links to authorized review and retains shared filter hooks');
$filterOrder = array_map(static fn (string $needle): int|false => strpos($reportMarkup, $needle), ['id="q"', 'id="status"', 'id="program_id"', 'id="year_id"', 'id="report_date_preset"', 'data-advanced-toggle']);
$assert(!in_array(false, $filterOrder, true) && $filterOrder[0] < $filterOrder[1] && $filterOrder[1] < $filterOrder[2] && $filterOrder[2] < $filterOrder[3] && $filterOrder[3] < $filterOrder[4] && $filterOrder[4] < $filterOrder[5], 'primary report controls render in the requested Search, Status, Program, Academic Year, Date Range, More Filters order');
$assert(str_contains($reportMarkup, 'placeholder="Applicant, reference number, or program"') && str_contains($reportMarkup, 'report-date-hint" aria-hidden="true"') && !str_contains($reportMarkup, 'Reset Filters') && !str_contains($reportMarkup, 'Clear all filters') && str_contains($reportMarkup, 'action="/staff/reports.csv"') && str_contains($reportMarkup, 'action="/staff/reports.pdf"'), 'default filter view keeps term helper reserved but hidden, hides inactive reset actions, and retains both export contracts');
$defaultReportFilters = $report['filters'];
$report['filters'] = array_merge($report['filters'], ['date_range' => 'term', 'start_date' => $yearStart, 'end_date' => $yearEnd]);
ob_start();
require dirname(__DIR__) . '/resources/views/staff/reports.php';
$termReportMarkup = (string) ob_get_clean();
$assert(str_contains($termReportMarkup, 'report-date-hint is-visible" aria-hidden="false"') && str_contains($termReportMarkup, 'This term uses the configured open academic-year dates.'), 'This Term alone exposes the reserved helper line');
$report['filters'] = $defaultReportFilters;
$report['filters'] = array_merge($report['filters'], ['q' => 'Fixture applicant', 'status' => 'enrolled', 'program_id' => '1', 'year_id' => '1', 'date_range' => 'last30', 'start_date' => '2026-10-04', 'end_date' => '2026-11-02', 'reviewer_id' => '3', 'documents' => 'incomplete', 'drilldown' => 'stage:screening']);
ob_start();
require dirname(__DIR__) . '/resources/views/staff/reports.php';
$activeReportMarkup = (string) ob_get_clean();
$report['filters'] = $defaultReportFilters;
$assert(substr_count($activeReportMarkup, 'Reset Filters') === 1 && !str_contains($activeReportMarkup, 'Clear all filters') && str_contains($activeReportMarkup, '2 active advanced filters') && str_contains($activeReportMarkup, 'data-clear-filter="reviewer_id"') && str_contains($activeReportMarkup, 'data-clear-filter="documents"') && str_contains($activeReportMarkup, 'data-clear-filter="drilldown"') && str_contains($activeReportMarkup, '/staff/reports?per_page=20#report-results'), 'active report filters display one reset beside chips, keep individual chip removal, and retain the page-size reset URL');
$reportCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$assert(str_contains($reportCss, 'grid-template-columns: minmax(250px, 2.2fr) repeat(4, minmax(125px, 1fr)) auto') && str_contains($reportCss, '@media (min-width: 640px) and (max-width: 1199.98px)') && str_contains($reportCss, '@media (max-width: 639.98px)') && str_contains($reportCss, 'height: 32px') && str_contains($reportCss, '.report-primary-filters .filter-field.search-field, .staff-reports-page .report-primary-filters .filter-actions { grid-column: 1 / -1; }'), 'Reports CSS defines compact controls and desktop, tablet, and two-column mobile layouts');

$dateRangeLabels = ['' => 'Any date', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'term' => 'This Term', 'custom' => 'Custom Range'];
ob_start();
require dirname(__DIR__) . '/resources/views/staff/reports-pdf.php';
$pdfHtml = (string) ob_get_clean();
$options = new Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', false);
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($pdfHtml, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$pdfBytes = $dompdf->output();
$assert(str_starts_with($pdfBytes, '%PDF-') && !str_contains($pdfHtml, 'date_of_birth') && !str_contains($pdfHtml, 'address_line'), 'PDF renderer creates a PDF and the export template omits residential and birth-date data');

fwrite(STDOUT, 'Report tests passed: ' . $assertions . PHP_EOL);
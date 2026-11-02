<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/app/ApplicationService.php';
require dirname(__DIR__) . '/app/ReportExport.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return 'fixture-token';
}

function request_ip(): ?string
{
    return '192.0.2.77';
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
$database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor_label TEXT, action TEXT, entity_type TEXT, outcome TEXT, severity TEXT, ip_address TEXT, details TEXT)');
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
$assert($reviewerReport['total'] === 6 && !in_array(8, array_column($reviewerReport['rows'], 'id'), true) && !in_array(5, array_column($reviewerReport['rows'], 'id'), true) && array_column($reviewerReport['summary']['funnel'], 'count') === [6, 5, 3, 2, 1], 'reviewer report scopes both rows and cumulative funnel counts to assigned or unassigned applications');
$reviewerPrintReport = StaffService::applications($database, ['id' => 3, 'role' => 'reviewer'], ['view' => 'print'], true, false);
$assert($reviewerPrintReport['total'] === 6 && count($reviewerPrintReport['rows']) === 6 && !in_array(8, array_column($reviewerPrintReport['rows'], 'id'), true), 'print-preview row mode preserves reviewer scope and returns the full matching set within the existing 5,000-row cap');
$emptyReport = StaffService::applications($database, $admin, ['q' => 'no fixture can match this search'], true, false);
$assert($emptyReport['total'] === 0 && array_column($emptyReport['summary']['funnel'], 'count') === [0, 0, 0, 0, 0], 'empty filtered report returns zero-safe funnel metrics');
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
$invalidReportFilter = false;
try {
    StaffService::applications($database, $admin, ['date_range' => 'invented'], true, true);
} catch (DomainException) {
    $invalidReportFilter = true;
}
$assert($invalidReportFilter, 'report filter parsing rejects unsupported date presets');
$invalidReportDrilldown = false;
try {
    StaffService::applications($database, $admin, ['drilldown' => 'stage:invented'], true, true);
} catch (DomainException) {
    $invalidReportDrilldown = true;
}
$assert($invalidReportDrilldown, 'report filter parsing rejects unsupported funnel drilldowns');

$programs = [['id' => 1, 'name' => 'Fixture Program']];
$years = [['id' => 1, 'label' => 'Configured Intake']];
$reviewers = [['id' => 3, 'full_name' => 'Reviewer One']];
$reportPreviewModal = true;
ob_start();
require dirname(__DIR__) . '/resources/views/staff/reports.php';
$reportMarkup = (string) ob_get_clean();
$assert(substr_count($reportMarkup, '<article>') === 4 && str_contains($reportMarkup, 'This Term') && str_contains($reportMarkup, 'Export PDF') && str_contains($reportMarkup, 'Filtered by') === false, 'reports view renders exactly four cards and the new range and PDF controls');
$assert(str_contains($reportMarkup, '/staff/application/1') && str_contains($reportMarkup, 'Documents Incomplete') && str_contains($reportMarkup, 'data-auto-filter-form'), 'report table links to authorized review and retains shared filter hooks');
$assert(str_contains($reportMarkup, 'report-screen-table') && substr_count($reportMarkup, '<col class="report-col-') === 7 && str_contains($reportMarkup, 'Applications with missing documents') && str_contains($reportMarkup, 'Status: documents incomplete'), 'on-screen report merges program and year into a seven-column table and distinguishes the document and workflow measures');
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
$assert(substr_count($activeReportMarkup, 'Clear all filters') === 1 && !str_contains($activeReportMarkup, 'Reset Filters') && str_contains($activeReportMarkup, '2 active advanced filters') && str_contains($activeReportMarkup, 'data-clear-filter="reviewer_id"') && str_contains($activeReportMarkup, 'data-clear-filter="documents"') && str_contains($activeReportMarkup, 'data-clear-filter="drilldown"') && str_contains($activeReportMarkup, '/staff/reports?per_page=20#report-results'), 'active report filters display one shared-partial clear action, preserve individual chip removal, and retain the page-size reset URL');
$reportHeaderMarkup = substr($reportMarkup, strpos($reportMarkup, '<div class="staff-heading'), strpos($reportMarkup, '<div class="report-print-heading') - strpos($reportMarkup, '<div class="staff-heading'));
$assert(substr_count($reportHeaderMarkup, 'Print preview') === 1 && substr_count($reportHeaderMarkup, 'data-print-preview-trigger') === 1 && str_contains($reportHeaderMarkup, 'href="/staff"') && preg_match('/\sdata-print(?:\s|=|>)/', $reportHeaderMarkup) === 0 && !str_contains($reportHeaderMarkup, 'target="_blank"'), 'Reports header has one in-page Print preview button next to dashboard navigation and no whole-page print wiring');
$assert(str_contains($reportMarkup, 'id="printPreviewModal"') && str_contains($reportMarkup, 'aria-labelledby="printPreviewModalTitle"') && str_contains($reportMarkup, 'id="printPreviewModalTitle">Print preview</h2>') && str_contains($reportMarkup, 'modal-xl modal-dialog-centered modal-fullscreen-sm-down') && str_contains($reportMarkup, 'data-preview-print') && str_contains($reportMarkup, 'data-preview-signatures') && str_contains($reportMarkup, 'data-pdf-orientation') && str_contains($reportMarkup, 'Include signature lines') && str_contains($reportMarkup, 'In the print dialog, turn off \'Headers and footers\' for a clean report.') && str_contains($reportMarkup, 'title="Printable document preview"') && str_contains($reportMarkup, 'data-preview-loading') && str_contains($reportMarkup, 'data-preview-error') && str_contains($reportMarkup, 'data-preview-retry'), 'preview toolbar includes signature and orientation controls plus the print-dialog tip');
$previewUrlMatch = [];
preg_match('/data-preview-url="([^"]+)"/', $activeReportMarkup, $previewUrlMatch);
$previewQuery = [];
parse_str((string) parse_url(htmlspecialchars_decode((string) ($previewUrlMatch[1] ?? ''), ENT_QUOTES), PHP_URL_QUERY), $previewQuery);
$assert(($previewQuery['q'] ?? '') === 'Fixture applicant' && ($previewQuery['status'] ?? '') === 'enrolled' && ($previewQuery['reviewer_id'] ?? '') === '3' && ($previewQuery['documents'] ?? '') === 'incomplete' && ($previewQuery['view'] ?? '') === 'print' && ($previewQuery['embed'] ?? '') === '1', 'iframe URL preserves the active report filters and adds only print and embed mode flags');
$previewFilteredReport = StaffService::applications($database, ['id' => 3, 'role' => 'reviewer'], ['status' => 'in_screening', 'date_range' => 'last30'], true, false);
$report = $previewFilteredReport;
$reportPrintView = true;
$user = ['id' => 3, 'role' => 'reviewer', 'full_name' => 'Reviewer One'];
ob_start();
require dirname(__DIR__) . '/resources/views/staff/reports-print.php';
$printMarkup = (string) ob_get_clean();
$previewIds = array_map('intval', array_column($previewFilteredReport['rows'], 'id'));
$assert(str_contains($printMarkup, 'Applications report') && str_contains($printMarkup, 'AdmitFlow · Admission Office') && str_contains($printMarkup, 'Generated ') && str_contains($printMarkup, 'Asia/Manila') && str_contains($printMarkup, 'Generated by Reviewer One (reviewer)') && str_contains($printMarkup, 'Applied filters') && str_contains($printMarkup, 'Application status: In Screening') && str_contains($printMarkup, 'AF-TEST-1') && str_contains($printMarkup, 'Total records: 2') && str_contains($printMarkup, 'data-print-preview-sheet') && !str_contains($printMarkup, 'address_line') && !str_contains($printMarkup, 'date_of_birth') && !str_contains($printMarkup, '<button') && !str_contains($printMarkup, 'pagination-bar') && !str_contains($printMarkup, '<script') && $previewIds === [1, 8], 'print sheet includes attribution and applied filters, stays role-scoped, and omits sensitive fields, controls, and pagination');
$assert(substr_count($printMarkup, '<col class="report-col-') === 7 && !str_contains($printMarkup, 'Needs Attention') && str_contains($printMarkup, 'Status: documents incomplete') && str_contains($printMarkup, 'Applications with missing documents') && str_contains($printMarkup, 'Confidential · For authorized Admission Office use only'), 'print sheet uses seven columns, omits the attention card, distinguishes status and document counts, and includes confidentiality text');
StaffService::recordReportPrintPreview($database, ['id' => 3, 'role' => 'reviewer', 'full_name' => 'Reviewer One'], $previewFilteredReport['filters'], count($previewFilteredReport['rows']));
$previewAudit = $database->query("SELECT user_id, action, details FROM audit_logs WHERE action = 'report.print_preview_opened'")->fetch();
$previewAuditDetails = json_decode((string) ($previewAudit['details'] ?? ''), true);
$assert((int) $previewAudit['user_id'] === 3 && $previewAuditDetails['row_count'] === 2 && $previewAuditDetails['filters']['status'] === 'in_screening', 'print preview audit records actor, role-scoped row count, and active filters');
StaffService::recordReportPrintPreview($database, ['id' => 3, 'role' => 'reviewer', 'full_name' => 'Reviewer One'], $previewFilteredReport['filters'], count($previewFilteredReport['rows']));
$assert((int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='report.print_preview_opened'")->fetchColumn() === 2, 'each separate embedded print-preview render writes exactly one preview audit event');
$largePrintReport = $previewFilteredReport;
$largePrintReport['rows'] = array_fill(0, 1002, $previewFilteredReport['rows'][0]);
$largePrintReport['table_total'] = 1002;
$report = $largePrintReport;
$includeSignatureLines = true;
ob_start();
require dirname(__DIR__) . '/resources/views/staff/reports-print.php';
$largePrintMarkup = (string) ob_get_clean();
$assert(substr_count($largePrintMarkup, 'class="report-cell-reference"') === 1000 && str_contains($largePrintMarkup, 'Total records: 1,002') && str_contains($largePrintMarkup, 'Showing the first 1,000 of 1,002 records. Use Download CSV for the full list.') && str_contains($largePrintMarkup, 'Prepared by') && str_contains($largePrintMarkup, 'Reviewed by') && str_contains($largePrintMarkup, '>Date</span>'), 'print view caps the table at 1,000, reports the full total, and includes signature lines when enabled');
$includeSignatureLines = false;
$report = $previewFilteredReport;
$reportRouteSource = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert(str_contains($reportRouteSource, 'StaffService::recordReportPrintPreview($database, $user, $report[\'filters\']') && str_contains($reportRouteSource, "\$printView = (string) (\$_GET['view'] ?? '') === 'print';") && str_contains($reportRouteSource, "unset(\$reportInput['view'], \$reportInput['embed']);") && str_contains($reportRouteSource, "if (\$method === 'GET' && \$printView && (string) (\$_GET['embed'] ?? '') === '1')") && str_contains($reportRouteSource, "header('X-Frame-Options: SAMEORIGIN');") && str_contains($reportRouteSource, "header('X-Frame-Options: DENY');") && str_contains($reportRouteSource, "ReportExport::pdf(\$report, \$filterOptions, \$user") && str_contains($reportRouteSource, "(string) (\$_POST['orientation'] ?? 'landscape')" ) && str_contains($reportRouteSource, "(string) (\$_POST['include_signature_lines'] ?? '') === '1'"), 'authorized routes preserve preview audit and framing while passing staff identity, PDF orientation, and signature choice');
$assert(str_contains($reportRouteSource, "frame-src 'self' blob:") && !str_contains($reportRouteSource, 'frame-ancestors *'), 'global Content-Security-Policy retains same-origin frame sources without allowing external framing');
$layoutSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/layout.php');
$assert(str_contains($layoutSource, 'if (!empty($standalonePrintView))') && str_contains($layoutSource, '<body class="print-preview-document') && str_contains($layoutSource, 'require $contentView;') && str_contains($layoutSource, 'else: ?>'), 'direct and embedded print requests render a standalone shared-stylesheet page without the application shell');
$reportJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$assert(str_contains($reportJs, 'previewFrame.contentWindow.focus();') && str_contains($reportJs, 'previewFrame.contentWindow.print();') && str_contains($reportJs, 'button.dataset.previewOrientation') && str_contains($reportJs, "querySelector('[data-print-preview-sheet]')") && str_contains($reportJs, "classList.toggle('print-landscape'") && str_contains($reportJs, 'pdfOrientationField.value = orientation') && str_contains($reportJs, "classList.toggle('include-signature-lines'") && str_contains($reportJs, 'signatureBlock.hidden = !include') && str_contains($reportJs, 'pdfSignatureField.value = signatureToggle.checked ? \'1\' : \'0\'') && str_contains($reportJs, "opener.focus({ preventScroll: true })") && str_contains($reportJs, 'setTimeout(setPreviewError, 20000)') && !str_contains($reportJs, "querySelectorAll('[data-print]')"), 'shared modal sets orientation on the iframe sheet wrapper and syncs PDF/signature choices');
$assert(str_contains($reportJs, "button.setAttribute('aria-pressed', selected ? 'true' : 'false')") && str_contains($reportMarkup, 'aria-pressed="false">Portrait') && str_contains($reportMarkup, 'aria-pressed="true">Landscape') && str_contains($reportJs, 'admitflow-report-orientation') && str_contains($reportJs, "event.key === 'ArrowRight'"), 'shared orientation controls default to accessible Landscape, persist the session choice, and support keyboard selection');
$reportCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$assert(str_contains($reportCss, 'grid-template-columns: minmax(250px, 2.2fr) repeat(4, minmax(125px, 1fr)) auto') && str_contains($reportCss, '@media (min-width: 640px) and (max-width: 1199.98px)') && str_contains($reportCss, '@media (max-width: 639.98px)') && str_contains($reportCss, 'grid-template-columns: repeat(2, minmax(0, 1fr))') && str_contains($reportCss, 'height: 32px') && !str_contains($reportCss, '.report-primary-filters .filter-field.search-field, .staff-reports-page .report-primary-filters .filter-actions { grid-column: 1 / -1; }'), 'Reports CSS defines compact controls and desktop, tablet, and two-column mobile layouts');
$assert(str_contains($reportCss, '@page {') && str_contains($reportCss, 'size: A4 portrait;') && str_contains($reportCss, '@page report-portrait {') && str_contains($reportCss, '@page report-landscape {') && str_contains($reportCss, 'size: A4 landscape;') && str_contains($reportCss, 'margin: 12mm;') && str_contains($reportCss, '@bottom-left') && str_contains($reportCss, '@bottom-right') && str_contains($reportCss, 'Confidential, for authorized staff only') && str_contains($reportCss, 'counter(pages)') && str_contains($reportCss, '.report-preview-page { page: report-portrait; }') && str_contains($reportCss, '.report-preview-page.print-landscape, .document-print-page.print-landscape { page: report-landscape; }') && preg_match('/@page\s*\{\s*size:\s*A4\s+landscape/i', $reportCss) === 0 && !str_contains($reportCss, 'body.report-print-document.print-landscape { page: report-landscape; }') && str_contains($reportCss, '.report-preview-table .report-col-reference { width: 16%; }') && str_contains($reportCss, '.report-preview-table .report-col-status { width: 14%; }') && str_contains($reportCss, '.report-preview-table .report-cell-reference { font-size: 8pt; font-variant-numeric: tabular-nums; white-space: nowrap; }') && str_contains($reportCss, '.report-preview-table .report-cell-applicant, .report-preview-table .report-cell-program { white-space: normal; }') && str_contains($reportCss, 'overflow-wrap: normal; word-break: normal; hyphens: none;') && str_contains($reportCss, '.report-preview-table .report-cell-status { white-space: nowrap; font-size: 6.5pt; }') && str_contains($reportCss, 'body.include-signature-lines .report-preview-signatures { display: grid; }'), 'shared stylesheet uses A4 portrait default, report-specific portrait/landscape wrappers, repeating footers, and no global landscape page');

$studentPortalSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/student/portal.php');
$studentPrintViewSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/student/print-document.php');
$studentPrintContentSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/student/print-document-content.php');
$assert(substr_count($studentPortalSource, 'data-print-preview-trigger') === 3 && str_contains($studentPortalSource, '/application/print?preview=1') && str_contains($studentPortalSource, '/application/acceptance-letter?preview=1') && str_contains($studentPortalSource, '/application/enrollment-confirmation?preview=1') && str_contains($studentPortalSource, "require dirname(__DIR__) . '/partials/print-preview-modal.php'") && !str_contains($studentPortalSource, 'data-print>'), 'all three existing student portal print actions use the shared modal and existing owner-authorized document routes');
$assert(str_contains($studentPrintViewSource, 'data-print-preview-trigger') && str_contains($studentPrintViewSource, '$isPrintPreview') && str_contains($studentPrintViewSource, 'print-document-content.php') && str_contains($studentPrintContentSource, 'data-print-preview-sheet') && str_contains($studentPrintContentSource, 'Applicant information') && str_contains($studentPrintContentSource, 'Required documents') && str_contains($studentPrintContentSource, 'acceptance-letter-copy'), 'student preview iframe reuses the original printable content for summary, acceptance, and enrollment documents');
$studentPrintableFixture = ['reference_number' => 'AF-STUDENT-PRINT', 'program_name' => 'Fixture Program', 'academic_year_label' => 'Configured Intake', 'status' => 'enrolled', 'submitted_at' => '2026-10-01 02:00:00', 'accepted_at' => '2026-10-15 02:00:00', 'applicant' => ['full_name' => 'Student Fixture', 'email' => 'student@example.test', 'mobile_number' => '09170000000', 'address_line' => '10 Fixture Road', 'municipality' => 'Test City', 'province' => 'Test Province'], 'documents' => [['name' => 'Transcript', 'review_status' => 'verified', 'uploaded_at' => '2026-10-02 02:00:00']], 'enrollment' => ['completed_at' => '2026-10-20 02:00:00']];
$studentPrintContents = [];
foreach ([['summary', 'Application summary', '/application/print?preview=1'], ['acceptance', 'Acceptance letter', '/application/acceptance-letter?preview=1'], ['enrollment', 'Enrollment confirmation', '/application/enrollment-confirmation?preview=1']] as [$documentType, $documentTitle, $documentPreviewUrl]) {
    $application = $studentPrintableFixture;
    $applicant = $studentPrintableFixture['applicant'];
    $available = true;
    $generatedAt = new DateTimeImmutable('now', $manila);
    $printDocumentPreviewView = true;
    ob_start();
    require dirname(__DIR__) . '/resources/views/student/print-document.php';
    $studentPrintContents[$documentType] = (string) ob_get_clean();
}
$assert(str_contains($studentPrintContents['summary'], 'Applicant information') && str_contains($studentPrintContents['summary'], '10 Fixture Road') && str_contains($studentPrintContents['summary'], 'Transcript') && str_contains($studentPrintContents['acceptance'], 'We are pleased to confirm that your application has been accepted.') && str_contains($studentPrintContents['acceptance'], 'AF-STUDENT-PRINT') && str_contains($studentPrintContents['enrollment'], 'This confirms that enrollment for the application below is recorded as complete.') && str_contains($studentPrintContents['enrollment'], 'Enrollment completed') && substr_count(implode('', $studentPrintContents), 'data-print-preview-sheet') === 3 && !str_contains(implode('', $studentPrintContents), 'data-print-preview-trigger'), 'all existing student print documents retain their original content in shell-free preview mode');
$studentRoutePosition = strpos($reportRouteSource, "if (in_array(\$path, ['/application/print', '/application/acceptance-letter', '/application/enrollment-confirmation'], true))");
$studentRouteBlock = $studentRoutePosition === false ? '' : substr($reportRouteSource, $studentRoutePosition, strpos($reportRouteSource, "if (\$path === '/portal')", $studentRoutePosition) - $studentRoutePosition);
$assert(strpos($studentRouteBlock, '$user = require_student();') < strpos($studentRouteBlock, 'header(\'X-Frame-Options: SAMEORIGIN\');') && str_contains($studentRouteBlock, "if (\$method === 'GET' && \$printPreviewView)") && str_contains($studentRouteBlock, 'ApplicationService::printableSummary($database, (int) $user[\'id\'])') && str_contains($studentRouteBlock, "'standalonePrintView' => \$printPreviewView") && str_contains($studentRouteBlock, 'ApplicationService::canPrintAcceptance($application)') && str_contains($studentRouteBlock, 'ApplicationService::canPrintEnrollment($application)'), 'student iframe previews retain authenticated GET-only framing, ownership-scoped data, availability rules, and the existing routes');

$dateRangeLabels = ['' => 'Any date', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'term' => 'This Term', 'custom' => 'Custom Range'];
$reportExportSource = (string) file_get_contents(dirname(__DIR__) . '/app/ReportExport.php');
$pdfTemplateSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/reports-pdf.php');
$pdfBytes = ReportExport::pdf($report, ['programs' => $programs, 'years' => $years, 'reviewers' => $reviewers], $user);
if (($pdfArtifactPath = getenv('ADMITFLOW_REPORT_PDF_ARTIFACT')) !== false && $pdfArtifactPath !== '') file_put_contents($pdfArtifactPath, $pdfBytes);
$signaturePdfBytes = ReportExport::pdf($report, ['programs' => $programs, 'years' => $years, 'reviewers' => $reviewers], $user, true);
$portraitPdfBytes = ReportExport::pdf($report, ['programs' => $programs, 'years' => $years, 'reviewers' => $reviewers], $user, false, 'portrait');
$invalidOrientationPdfBytes = ReportExport::pdf($report, ['programs' => $programs, 'years' => $years, 'reviewers' => $reviewers], $user, false, 'sideways');
$pdfPageCount = preg_match_all('~/Type\s+/Page\b~', $pdfBytes);
$pdfPageSize = static function (string $bytes): array {
    $match = [];
    preg_match('/MediaBox\s+\[0(?:\.000)?\s+0(?:\.000)?\s+([\d.]+)\s+([\d.]+)\]/', $bytes, $match);
    return [(float) ($match[1] ?? 0), (float) ($match[2] ?? 0)];
};
$landscapePdfSize = $pdfPageSize($pdfBytes);
$portraitPdfSize = $pdfPageSize($portraitPdfBytes);
$invalidPdfSize = $pdfPageSize($invalidOrientationPdfBytes);
$assert(str_contains($reportExportSource, "\$dompdf->setPaper('A4', \$reportOrientation);") && str_contains($reportExportSource, "in_array(\$orientation, ['portrait', 'landscape'], true)") && str_contains($reportExportSource, "'/resources/views/staff/reports-pdf.php'") && str_contains($reportExportSource, "'/public/assets/css/app.css'") && str_contains($reportExportSource, 'Confidential, for authorized staff only') && str_contains($reportExportSource, 'Page {PAGE_NUM} of {PAGE_COUNT}'), 'PDF export allowlists explicit orientation, sets A4 paper accordingly, and keeps its page footer');
$printTemplateSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/reports-print.php');
$assert(str_contains($pdfTemplateSource, '<title>Applications report</title>') && str_contains($pdfTemplateSource, 'report-pdf-document') && str_contains($pdfTemplateSource, "require __DIR__ . '/reports-print.php'") && !str_contains($pdfTemplateSource, '@page') && !str_contains($pdfTemplateSource, 'print-landscape') && str_contains($printMarkup, 'Generated by Reviewer One (reviewer)') && str_contains($printMarkup, 'Total records: 2') && str_contains($largePrintMarkup, 'Showing the first 1,000 of 1,002 records.') && str_contains($largePrintMarkup, 'Prepared by') && str_contains($largePrintMarkup, 'Reviewed by') && str_contains($printTemplateSource, 'Applications report') && str_contains($printTemplateSource, 'Generated by') && str_contains($printTemplateSource, 'Total records:') && str_contains($printTemplateSource, 'Showing the first 1,000 of') && str_contains($printTemplateSource, 'report-preview-funnel-table') && str_contains($printTemplateSource, 'Applications with missing documents') && str_contains($printTemplateSource, 'This report excludes residential addresses, dates of birth, and authentication information.') && str_contains($printTemplateSource, 'report-preview-confidentiality') && str_contains($printTemplateSource, 'report-pdf-caption') && !str_contains($printTemplateSource, 'style=') && !str_contains($pdfTemplateSource, 'Needs Attention') && !str_contains($pdfTemplateSource, '<button') && !str_contains($pdfTemplateSource, 'pagination-bar') && !str_contains($pdfTemplateSource, 'address_line') && !str_contains($pdfTemplateSource, 'date_of_birth'), 'PDF wrapper reuses the privacy-safe print structure and leaves orientation to explicit paper settings and wrapper class');
$assert(str_starts_with($pdfBytes, '%PDF-') && str_starts_with($signaturePdfBytes, '%PDF-') && str_starts_with($portraitPdfBytes, '%PDF-') && str_starts_with($invalidOrientationPdfBytes, '%PDF-') && !str_contains($printMarkup, 'date_of_birth') && !str_contains($printMarkup, 'address_line'), 'Dompdf produces default, signature-enabled, portrait, and invalid-value PDFs from the privacy-safe shared sheet');
$assert($pdfPageCount === 1 && $landscapePdfSize[0] > $landscapePdfSize[1] && $portraitPdfSize[1] > $portraitPdfSize[0] && $invalidPdfSize[0] > $invalidPdfSize[1], 'Dompdf orientation sizes/page count: ' . json_encode(['default' => $landscapePdfSize, 'portrait' => $portraitPdfSize, 'invalid' => $invalidPdfSize, 'pages' => $pdfPageCount]));

$database->exec("INSERT INTO applications (id, reference_number, student_id, academic_program_id, academic_year_id, status, submitted_at, deleted_at) VALUES (98, 'AF-ARCHIVE-ACTIVE', 2, 1, 1, 'submitted', '2026-10-25 02:00:00', NULL), (99, 'AF-ARCHIVE-OLD', 2, 1, 1, 'submitted', '2026-10-24 02:00:00', '2026-10-30 00:00:00')");
$database->exec("INSERT INTO reviewer_assignments VALUES (900, 98, 3, '2026-10-26 00:00:00')");
$database->exec("INSERT INTO application_status_history VALUES (900, 98, 'submitted', 'in_screening', 3, '2026-10-26 00:00:00')");
$database->exec("INSERT INTO application_documents VALUES (900, 98, 1, 0, 'verified')");
$database->exec("INSERT INTO document_reviews VALUES (900, 900, 3, 'verified', '2026-10-26 00:00:00')");
$database->exec("INSERT INTO interviews VALUES (900, 98, 'completed', '2026-10-27 00:00:00', '2026-10-27 00:00:00')");
$database->exec("INSERT INTO screening_notes VALUES (900, 98, '2026-10-26 00:00:00')");
$database->exec('CREATE TABLE enrollment_records (id INTEGER PRIMARY KEY, application_id INTEGER, status TEXT)');
$database->exec('ALTER TABLE academic_years ADD COLUMN application_deadline TEXT');
$database->exec('ALTER TABLE applications ADD COLUMN updated_at TEXT');
$database->exec("UPDATE applications SET updated_at = submitted_at");
$activeQueue = StaffService::applications($database, $admin, ['per_page' => 100]);
$archivedQueue = StaffService::applications($database, $admin, ['archived' => 'archived', 'per_page' => 100]);
$craftedReviewerArchive = StaffService::applications($database, ['id' => 3, 'role' => 'reviewer'], ['archived' => 'archived', 'per_page' => 100]);
$archivedReport = StaffService::applications($database, $admin, ['archived' => 'archived'], true, true);
$assert($activeQueue['total'] === 9 && $archivedQueue['total'] === 1 && (int) $archivedQueue['rows'][0]['id'] === 99 && $archivedQueue['filters']['archived'] === 'archived', 'administrator queue filter separates active and archived applications in service results and returned URL filters');
$assert($craftedReviewerArchive['total'] === 8 && !in_array(99, array_map('intval', array_column($craftedReviewerArchive['rows'], 'id')), true) && !isset($craftedReviewerArchive['filters']['archived']) && $archivedReport['total'] === 9 && !in_array(99, array_map('intval', array_column($archivedReport['rows'], 'id')), true), 'reviewer queue remains active-only despite crafted archive filters and reports/print-export data excludes archived rows');
$archiveDenied = false;
try {
    StaffService::archiveApplication($database, ['id' => 3, 'role' => 'reviewer', 'full_name' => 'Reviewer One'], 98);
} catch (DomainException) {
    $archiveDenied = true;
}
$assert($archiveDenied && $database->query('SELECT deleted_at FROM applications WHERE id=98')->fetchColumn() === null && (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.archive_blocked' AND user_id=3")->fetchColumn() === 1, 'non-admin archive attempts are denied and audited without changing the application');
$database->exec("CREATE TRIGGER fail_archive_update BEFORE UPDATE OF deleted_at ON applications WHEN OLD.id=98 AND NEW.deleted_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'synthetic archive failure'); END");
$archiveFailureCaptured = false;
try {
    StaffService::archiveApplication($database, $admin, 98);
} catch (Throwable) {
    $archiveFailureCaptured = true;
}
$database->exec('DROP TRIGGER fail_archive_update');
$assert($archiveFailureCaptured && $database->query('SELECT deleted_at FROM applications WHERE id=98')->fetchColumn() === null && (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.archive_failed' AND outcome='failure'")->fetchColumn() === 1, 'unexpected archive failure rolls back and records one sanitized failure audit');
StaffService::archiveApplication($database, $admin, 98);
$afterArchive = StaffService::applications($database, $admin, ['per_page' => 100]);
$archiveAudit = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.archived'")->fetchColumn();
$relatedHistoryRetained = (int) $database->query('SELECT COUNT(*) FROM reviewer_assignments WHERE id=900 AND application_id=98')->fetchColumn() === 1 && (int) $database->query('SELECT COUNT(*) FROM application_status_history WHERE id=900 AND application_id=98')->fetchColumn() === 1 && (int) $database->query('SELECT COUNT(*) FROM application_documents WHERE id=900 AND application_id=98')->fetchColumn() === 1 && (int) $database->query('SELECT COUNT(*) FROM document_reviews WHERE id=900 AND document_id=900')->fetchColumn() === 1 && (int) $database->query('SELECT COUNT(*) FROM interviews WHERE id=900 AND application_id=98')->fetchColumn() === 1 && (int) $database->query('SELECT COUNT(*) FROM screening_notes WHERE id=900 AND application_id=98')->fetchColumn() === 1;
$assert($afterArchive['total'] === 8 && $database->query('SELECT deleted_at FROM applications WHERE id=98')->fetchColumn() !== null && $archiveAudit === 1 && $relatedHistoryRetained, 'admin archive sets deleted_at, hides the application from active queue, retains its documents and review history, and writes one audit event');
$database->exec("CREATE TRIGGER fail_restore_update BEFORE UPDATE OF deleted_at ON applications WHEN OLD.id=98 AND NEW.deleted_at IS NULL BEGIN SELECT RAISE(ABORT, 'synthetic restore failure'); END");
$restoreFailureCaptured = false;
try {
    StaffService::restoreApplication($database, $admin, 98);
} catch (Throwable) {
    $restoreFailureCaptured = true;
}
$database->exec('DROP TRIGGER fail_restore_update');
$assert($restoreFailureCaptured && $database->query('SELECT deleted_at FROM applications WHERE id=98')->fetchColumn() !== null && (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.restore_failed' AND outcome='failure'")->fetchColumn() === 1, 'unexpected restore failure leaves the application archived and records one sanitized failure audit');
StaffService::restoreApplication($database, $admin, 98);
$afterRestore = StaffService::applications($database, $admin, ['per_page' => 100]);
$restoreAudit = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.restored'")->fetchColumn();
$assert($afterRestore['total'] === 9 && $database->query('SELECT deleted_at FROM applications WHERE id=98')->fetchColumn() === null && $restoreAudit === 1, 'admin restore clears deleted_at, returns the row to active queue, and writes one audit event');
$dashboardWithArchive = StaffService::dashboard($database, $admin);
$chartsWithArchive = StaffService::charts($database, $admin);
$assert($dashboardWithArchive['total'] === 9 && array_sum(array_column($chartsWithArchive['statuses'], 'total')) === 9, 'dashboard counts and charts exclude the archived row while retaining every active application');
$database->exec("INSERT INTO users (id, full_name, role, account_status) VALUES (10, 'Archive Fixture Student', 'student', 'active')");
$database->exec("INSERT INTO applications (id, reference_number, student_id, academic_program_id, academic_year_id, status, submitted_at, deleted_at) VALUES (77, 'AF-STUDENT-ARCHIVE', 10, 1, 1, 'submitted', '2026-10-23 02:00:00', NULL)");
$assert(ApplicationService::current($database, 10) !== null, 'student current application lookup returns an active application before archive');
StaffService::archiveApplication($database, $admin, 77);
$assert(ApplicationService::current($database, 10) === null, 'student portal application lookup excludes archived applications');
StaffService::restoreApplication($database, $admin, 77);

fwrite(STDOUT, 'Report tests passed: ' . $assertions . PHP_EOL);
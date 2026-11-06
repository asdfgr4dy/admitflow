<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Pagination.php';
require_once dirname(__DIR__) . '/app/StaffService.php';

$fixtureUser = [];

function take_flash(): ?array
{
    return null;
}

function current_user(): ?array
{
    global $fixtureUser;
    return $fixtureUser;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_path_for_user(array $user): string
{
    return $user['role'] === 'admin' ? '/admin' : '/staff';
}

function csrf_token(): string
{
    return 'fixture-token';
}

$contentView = dirname(__DIR__) . '/resources/views/errors/not-found.php';
$title = 'Navigation fixture';
$notificationFeed = ['items' => [], 'unread_count' => 0];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$fixtureUser = ['id' => 1, 'full_name' => 'Fixture Admin', 'role' => 'admin'];
$_SERVER['REQUEST_URI'] = '/staff/users/staff';
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$adminMarkup = (string) ob_get_clean();
$assert(str_contains($adminMarkup, 'href="/admin/archive"') && str_contains($adminMarkup, '>Archive</span>'), 'administrator sidebar exposes the dedicated Archive destination');
$notificationLayoutSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/layout.php');
$notificationViewSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/student/notifications.php');
$notificationStyles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$assert(str_contains($notificationLayoutSource, "href=\"<?= e(\$notice['href']) ?>\"") && !str_contains($notificationLayoutSource, 'href="/notifications#<?= e($noticeAnchor) ?>"'), 'bell dropdown title follows each notification service destination instead of routing all notices to Notifications');
$assert(str_contains($notificationLayoutSource, '<h2><a href="/notifications">Notifications</a></h2>') && str_contains($notificationLayoutSource, '<span class="notification-dropdown-message"><?= e($notice[\'message\']) ?></span>') && !str_contains($notificationLayoutSource, 'href="/notifications#<?= e($noticeAnchor) ?>"'), 'dropdown heading links to full Notifications page and dropdown messages remain plain text');
$assert(str_contains($notificationViewSource, 'data-notification-link') && str_contains($notificationViewSource, "<h2><a href=\"<?= e(\$notification['href']) ?>\"") && str_contains($notificationViewSource, '<p><?= e($notification[\'message\']) ?></p>') && !str_contains($notificationViewSource, 'notification-message-link'), 'full Notifications page keeps titles clickable while rendering messages as normal text');
$assert(str_contains($notificationStyles, '.notification-content a:focus-visible') && str_contains($notificationStyles, '.notification-dropdown-title:focus-visible') && str_contains($notificationStyles, '.notification-dropdown-heading h2 a:focus-visible'), 'notification titles and header Notifications link retain visible keyboard focus styling');
$_SERVER['REQUEST_URI'] = '/staff/application/2';
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$applicationDetailLayoutMarkup = (string) ob_get_clean();
$assert(str_contains($applicationDetailLayoutMarkup, 'class="app-sidebar-link is-active" href="/admin/applications"'), 'application notification destination activates Applications in administrator sidebar');
$_SERVER['REQUEST_URI'] = '/staff/users/staff';

foreach ([
    '/staff/users/administrators',
    '/staff/users/staff',
    '/staff/users/students',
    '/staff/academic/programs',
    '/staff/academic/years',
    '/staff/academic/requirements',
] as $href) {
    $assert(str_contains($adminMarkup, 'href="' . $href . '"'), 'admin sidebar contains ' . $href);
}
$assert(str_contains($adminMarkup, 'data-sidebar-group="user-administration" data-active-group="true"') && str_contains($adminMarkup, 'aria-expanded="true"'), 'the active account group is expanded on initial render');
$assert(str_contains($adminMarkup, 'class="app-sidebar-sublink is-active" href="/staff/users/staff" aria-current="page"'), 'the selected submenu is highlighted server-side');
$routeSource = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
foreach (['/staff/academic/programs', '/staff/academic/years', '/staff/academic/requirements', '/staff/users/administrators', '/staff/users/staff', '/staff/users/students'] as $route) {
    $assert(str_contains($routeSource, "'" . $route . "'"), 'controller defines dedicated route ' . $route);
}
$assert(str_contains($routeSource, 'StaffService::academicSettings($database, $user, $_GET, $academicPageScopes[$path])') && str_contains($routeSource, '$rolesToLoad = [$roleFilter];'), 'academic reads and user reads are scoped to one selected category per dedicated route');

$fixtureUser = ['id' => 2, 'full_name' => 'Fixture Reviewer', 'role' => 'reviewer'];
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$reviewerMarkup = (string) ob_get_clean();
$assert(!str_contains($reviewerMarkup, 'User Administration') && !str_contains($reviewerMarkup, 'Academic Settings') && !str_contains($reviewerMarkup, 'href="/admin/archive"'), 'reviewers do not receive administrator navigation groups or Archive');
$assert(!str_contains($reviewerMarkup, 'href="/portal/archive"'), 'reviewers do not receive student archive navigation');
$fixtureUser = ['id' => 2, 'full_name' => 'Fixture Student', 'role' => 'student'];
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$studentMarkup = (string) ob_get_clean();
$assert(!str_contains($studentMarkup, 'href="/admin/archive"'), 'students do not receive Archive navigation');
$assert(!str_contains($studentMarkup, 'href="/portal/archive"') && !str_contains($studentMarkup, '>Archived drafts</span>'), 'student sidebar no longer exposes archive navigation');
$assert(!str_contains($adminMarkup, 'href="/portal/archive"'), 'administrators do not receive the student archive page');
$helpNavigationMarkups = ['admin' => $adminMarkup, 'reviewer' => $reviewerMarkup, 'student' => $studentMarkup];
foreach ($helpNavigationMarkups as $role => $markup) {
    $assert(!str_contains($markup, 'data-sidebar-group="help"') && !str_contains($markup, '>Help</span>') && !str_contains($markup, 'Help center'), $role . ' workspace does not show Help navigation');
}
$_SERVER['REQUEST_URI'] = '/help/product-requirements';
foreach ([
    ['id' => 1, 'full_name' => 'Fixture Admin', 'role' => 'admin'],
    ['id' => 2, 'full_name' => 'Fixture Reviewer', 'role' => 'reviewer'],
    ['id' => 3, 'full_name' => 'Fixture Student', 'role' => 'student'],
] as $fixtureUser) {
    ob_start();
    require dirname(__DIR__) . '/resources/views/layout.php';
    $helpPageNavigation = (string) ob_get_clean();
    $assert(!str_contains($helpPageNavigation, 'data-sidebar-group="help"') && !str_contains($helpPageNavigation, '>Help</span>'), $fixtureUser['role'] . ' workspace does not show Help navigation on documentation pages');
}
$fixtureUser = null;
$_SERVER['REQUEST_URI'] = '/';
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$landingHelpMarkup = (string) ob_get_clean();
$assert(str_contains($landingHelpMarkup, '>Help</a>') && str_contains($landingHelpMarkup, 'href="/help/product-requirements"') && str_contains($landingHelpMarkup, 'href="/help/technical-requirements"'), 'landing page exposes both documentation pages through Help');
$_SERVER['REQUEST_URI'] = '/help/product-requirements';
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$documentPageMarkup = (string) ob_get_clean();
$assert(!str_contains($documentPageMarkup, '>Help</a>') && !str_contains($documentPageMarkup, 'Technical Requirements</a>'), 'public documentation pages do not show the Help menu');
$prdMarkup = '';
ob_start();
require dirname(__DIR__) . '/resources/views/public/product-requirements.php';
$prdMarkup = (string) ob_get_clean();
$assert(str_contains($prdMarkup, '<h1 id="prd-title">Product Requirements Document</h1>') && str_contains($prdMarkup, 'id="overview"') && str_contains($prdMarkup, 'id="users"') && str_contains($prdMarkup, 'id="scope"') && str_contains($prdMarkup, 'id="requirements"') && str_contains($prdMarkup, 'id="workflows"') && str_contains($prdMarkup, 'id="quality"') && str_contains($prdMarkup, 'id="constraints"') && str_contains($prdMarkup, 'id="acceptance"'), 'Product Requirements view renders the document title and every linked section');
$routeSource = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$prdStyles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$assert(str_contains($routeSource, "if (\$path === '/help/product-requirements')") && str_contains($routeSource, "render_page('public/product-requirements'") && str_contains($prdStyles, '.prd-layout { display: grid;') && str_contains($prdStyles, '.prd-toc a:focus-visible'), 'the PRD route is registered and its responsive layout includes keyboard focus styling');
$technicalMarkup = '';
ob_start();
require dirname(__DIR__) . '/resources/views/public/technical-requirements.php';
$technicalMarkup = (string) ob_get_clean();
$assert(str_contains($technicalMarkup, '<h1 id="technical-title">Technical Requirements Document</h1>') && str_contains($technicalMarkup, 'PHP 8.2 or newer') && str_contains($technicalMarkup, 'id="runtime"') && str_contains($technicalMarkup, 'id="architecture"') && str_contains($technicalMarkup, 'id="dependencies"') && str_contains($technicalMarkup, 'id="configuration"') && str_contains($technicalMarkup, 'id="data-storage"') && str_contains($technicalMarkup, 'id="security"') && str_contains($technicalMarkup, 'id="operations"') && str_contains($technicalMarkup, 'id="acceptance"'), 'Technical Requirements view renders the supported baseline and all linked sections');
$assert(str_contains($routeSource, "if (\$path === '/help/technical-requirements')") && str_contains($routeSource, "render_page('public/technical-requirements'"), 'Technical Requirements has a dedicated public route');

$user = ['id' => 1, 'full_name' => 'Fixture Admin', 'role' => 'admin'];
$programs = [['id' => 4, 'code' => 'ENG', 'name' => 'Engineering', 'description' => 'Engineering program details', 'is_active' => 1, 'application_count' => 3, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-02 00:00:00']];
$years = [['id' => 5, 'label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'application_deadline' => null, 'is_open' => 1, 'application_count' => 3, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-02 00:00:00']];
$requirements = [['id' => 6, 'code' => 'TRANSCRIPT', 'name' => 'Transcript', 'instructions' => 'Official record', 'academic_program_id' => null, 'academic_year_id' => null, 'program_name' => null, 'year_label' => null, 'allowed_extensions' => 'pdf', 'max_file_size_bytes' => 10485760, 'is_required' => 1, 'is_active' => 1, 'submitted_document_count' => 2, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-02 00:00:00']];
$programOptions = $programs;
$yearOptions = $years;
$filters = array_fill_keys(['program_q', 'program_state', 'year_q', 'year_state', 'requirement_q', 'requirement_state', 'required', 'requirement_program', 'requirement_year'], '');
$createFormValues = [];
$createFormError = '';
$createFormType = '';
$academicMarkup = [];
foreach (['programs', 'years', 'requirements'] as $managementType) {
    ob_start();
    require dirname(__DIR__) . '/resources/views/staff/academic-management.php';
    $academicMarkup[$managementType] = (string) ob_get_clean();
}
$assert(str_contains($academicMarkup['programs'], '<h1>Programs</h1>') && str_contains($academicMarkup['programs'], 'Engineering program details') && !str_contains($academicMarkup['programs'], 'AY 2026-2027') && !str_contains($academicMarkup['programs'], 'Official record'), 'Programs page shows program records only');
$assert(str_contains($academicMarkup['programs'], 'data-filter-target="academic-records"') && str_contains($academicMarkup['programs'], 'id="academic-records"'), 'academic search targets its own results section');
$assert(str_contains($academicMarkup['programs'], 'class="application-filters"') && str_contains($academicMarkup['years'], 'class="application-filters"') && str_contains($academicMarkup['requirements'], 'class="application-filters"') && str_contains($academicMarkup['programs'], 'application-primary-filters'), 'all Academic Settings pages reuse the shared application filter interface');
$assert(str_contains($academicMarkup['years'], '<h1>Academic Years</h1>') && str_contains($academicMarkup['years'], 'AY 2026-2027') && !str_contains($academicMarkup['years'], 'Engineering program details') && !str_contains($academicMarkup['years'], 'Official record'), 'Academic Years page shows academic-year records only');
$assert(str_contains($academicMarkup['requirements'], '<h1>Requirements</h1>') && str_contains($academicMarkup['requirements'], 'Official record') && !str_contains($academicMarkup['requirements'], 'Engineering program details') && !str_contains($academicMarkup['requirements'], 'AY 2026-2027</strong>'), 'Requirements page shows requirement records only');
$assert(substr_count($academicMarkup['programs'], 'data-form-drawer') === 1 && substr_count($academicMarkup['years'], 'data-form-drawer') === 1 && substr_count($academicMarkup['requirements'], 'data-form-drawer') === 1 && str_contains($academicMarkup['programs'], 'admitflow-form-drawer academic-drawer'), 'each academic page invokes one extracted shared right-side Add/Edit drawer');
$assert(str_contains($academicMarkup['programs'], 'aria-sort=') && str_contains($academicMarkup['years'], 'aria-sort=') && str_contains($academicMarkup['requirements'], 'aria-sort=') && str_contains($academicMarkup['programs'], 'data-page-size-select="per_page"'), 'all academic tables expose sortable headers and shared pagination controls');
$assert(str_contains($academicMarkup['programs'], 'dropdown academic-row-actions') && str_contains($academicMarkup['years'], 'dropdown academic-row-actions') && str_contains($academicMarkup['requirements'], 'dropdown academic-row-actions') && str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js'), '.academic-row-actions [data-bs-toggle="dropdown"]'), 'Programs, Academic Years, and Requirements row menus use the fixed-strategy dropdown selector hook');
$applicationQueueSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/application-queue.php');
$dropdownPageSources = [
    'Academic Management' => (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/academic-management.php'),
    'Users' => (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/users.php'),
    'Announcements' => (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/announcements.php'),
    'Audit Log' => (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/audit.php'),
    'Application Queue' => $applicationQueueSource,
];
$rowDropdownsHaveHook = true;
foreach ($dropdownPageSources as $viewName => $viewSource) {
    if (str_contains($viewSource, 'class="dropdown')) $rowDropdownsHaveHook = $rowDropdownsHaveHook && str_contains($viewSource, 'academic-row-actions') && str_contains($viewSource, 'data-bs-toggle="dropdown"');
}
$assert($rowDropdownsHaveHook && str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css'), '.staff-table-wrap .dropdown-menu { z-index: 1035; }'), 'table dropdown inventory covers Academic Management, Users, Announcements, Audit Log, and Application Queue; any row dropdown gets the fixed hook and menu layer');
$assert(!str_contains($academicMarkup['programs'], 'academic-row-editor') && !str_contains($academicMarkup['years'], 'academic-row-editor') && !str_contains($academicMarkup['requirements'], 'academic-row-editor') && !str_contains($academicMarkup['programs'], 'View / Edit'), 'academic rows do not expand inline');
$assert(str_contains($academicMarkup['programs'], 'Description') && str_contains($academicMarkup['programs'], 'Applications') && str_contains($academicMarkup['programs'], 'data-character-count="description"') && str_contains($academicMarkup['years'], 'Current Year') && str_contains($academicMarkup['requirements'], 'Accepted file types'), 'page-specific fields and academic status controls render');
$assert(str_contains($academicMarkup['programs'], 'action="/staff/program/save"') && str_contains($academicMarkup['programs'], 'name="_csrf"') && str_contains($academicMarkup['programs'], 'name="program_id" value="4"') && str_contains($academicMarkup['programs'], 'name="is_active" value="0"') && str_contains($academicMarkup['programs'], 'data-confirm="This program has 3 applications.'), 'program Deactivate posts the complete existing record directly with CSRF and impact confirmation');
$assert(str_contains($academicMarkup['years'], 'action="/staff/year/save"') && str_contains($academicMarkup['years'], 'name="year_id" value="5"') && str_contains($academicMarkup['years'], 'name="is_open" value="0"') && str_contains($academicMarkup['requirements'], 'action="/staff/requirement/save"') && str_contains($academicMarkup['requirements'], 'name="requirement_id" value="6"') && str_contains($academicMarkup['requirements'], 'name="is_active" value="0"'), 'year and requirement status actions post their full record payloads to existing save routes');
$assert(!str_contains($academicMarkup['programs'] . $academicMarkup['years'] . $academicMarkup['requirements'], 'data-academic-status'), 'academic status actions no longer invoke the edit drawer handler');
$filters = array_merge($filters, ['program_q' => 'Engineering', 'program_state' => 'inactive']);
ob_start();
require dirname(__DIR__) . '/resources/views/staff/academic-management.php';
$filteredProgramMarkup = (string) ob_get_clean();
$assert(str_contains($filteredProgramMarkup, 'Search: Engineering') && str_contains($filteredProgramMarkup, 'Status: Inactive') && str_contains($filteredProgramMarkup, 'application-filter-chip') && str_contains($filteredProgramMarkup, 'Reset Filters') && !str_contains($filteredProgramMarkup, 'Clear all'), 'active academic filters use removable chips and one standardized Reset Filters action');
$queueFilterSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/application-queue.php');
$assert(str_contains($queueFilterSource, 'class="application-filters"') && str_contains($queueFilterSource, 'application-primary-filters') && str_contains($queueFilterSource, 'data-advanced-toggle') && str_contains($queueFilterSource, "require dirname(__DIR__) . '/partials/pagination.php'"), 'Application Queue keeps its original shared filter and pagination controls');
$queueResponsiveCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$assert(str_contains($queueResponsiveCss, '.admin-application-page .academic-table-wrap { contain: paint; }') && str_contains($queueResponsiveCss, '@media (min-width: 992px) and (max-width: 1199.98px)') && str_contains($queueResponsiveCss, '.admin-application-page .application-primary-filters .search-field, .admin-application-page .application-primary-actions'), 'admin and reviewer queues contain table scrolling and reflow filters on tablet/laptop widths');
$assert(str_contains($queueFilterSource, 'academic-table-wrap') && str_contains($queueFilterSource, 'staff-table academic-table') && str_contains((string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/applications.php'), 'academic-management-card'), 'Application Queue reuses the shared page container and compact responsive table');
$reportViewSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/reports.php');
$assert(str_contains($reportViewSource, 'academic-page-heading') && str_contains($reportViewSource, 'academic-table-wrap') && str_contains($reportViewSource, '/staff/reports.csv') && str_contains($reportViewSource, 'data-print-preview-trigger') && str_contains($reportViewSource, 'print-preview-modal.php') && preg_match('/\sdata-print(?:\s|=|>)/', $reportViewSource) === 0, 'Reports keeps shared styling and CSV export while printing through the shared in-page preview modal');
$assert(!str_contains($adminMarkup, 'Backups') && !str_contains($reviewerMarkup, 'Backups') && !str_contains($routeSource, "'/staff/backups'") && !str_contains($routeSource, "'/staff/backup/"), 'all roles lack Backup/Restore navigation and direct backup paths have no registered handlers');
$userFilterSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/users.php');
$assert(str_contains($userFilterSource, 'class="filter-bar user-filter-bar"') && str_contains($userFilterSource, 'data-auto-filter-form') && str_contains($userFilterSource, 'active-filter-chips.php'), 'User Administration retains its existing shared filter and active-chip components');
$assert(str_contains($userFilterSource, 'action="/staff/user/delete"') && str_contains($userFilterSource, 'data-submit-once') && str_contains($userFilterSource, 'Delete this account permanently? This is allowed only when the account has no admission history. Accounts with history must be deactivated instead.') && str_contains($userFilterSource, 'Deactivate this account?'), 'account deletion and deactivation use CSRF-protected one-shot forms with explicit impact confirmations');
$applicationQueueSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/application-queue.php');
$assert(!str_contains($applicationQueueSource, 'name="archived"') && !str_contains($applicationQueueSource, 'Archived applications') && str_contains($applicationQueueSource, "'/staff/application/archive'") && str_contains($applicationQueueSource, 'data-confirm='), 'Applications has no archived filter and retains the administrator archive action for active rows');
$queueRenderContext = static function (array $queueUser, array $queueFilters): string {
    $user = $queueUser;
    if (($user['role'] ?? '') !== 'admin') unset($queueFilters['archived']);
    $applicationQueuePath = '/admin/applications';
    $statusLabels = ['submitted' => 'Submitted'];
    $programs = [];
    $years = [];
    $reviewers = [];
    $query = $queueFilters + ['per_page' => 10];
    $pagination = Pagination::make(25, ['per_page' => 10]);
    $pagination['base_url'] = $applicationQueuePath;
    $pagination['query'] = $query;
    $applications = ['rows' => [['id' => 88, 'full_name' => 'Archived Applicant', 'reference_number' => 'AF-ARCH-88', 'program_name' => null, 'year_label' => null, 'submitted_at' => null, 'uploaded_documents' => 0, 'required_documents' => 0, 'reviewer_name' => null, 'status' => 'submitted', 'assigned_reviewer_id' => null]], 'total' => 1, 'filters' => $queueFilters, 'pagination' => $pagination];
    ob_start();
    require dirname(__DIR__) . '/resources/views/staff/application-queue.php';
    return (string) ob_get_clean();
};
$adminActiveQueueMarkup = $queueRenderContext(['id' => 1, 'role' => 'admin'], ['q' => '']);
$reviewerActiveQueueMarkup = $queueRenderContext(['id' => 3, 'role' => 'reviewer'], ['q' => '']);
$adminArchivedQueueMarkup = $queueRenderContext(['id' => 1, 'role' => 'admin'], ['archived' => 'archived']);
$assert(str_contains($adminActiveQueueMarkup, 'action="/staff/application/archive"') && !str_contains($adminActiveQueueMarkup, 'name="archived"') && !str_contains($adminActiveQueueMarkup, 'archived=archived'), 'admin queue keeps active-row archive action and has no archive filter or archived links');
$assert(str_contains($adminActiveQueueMarkup, 'href="/staff/application/88">Review') && str_contains($adminArchivedQueueMarkup, 'action="/staff/application/restore"') && str_contains($adminArchivedQueueMarkup, 'href="/staff/application/88?archived=1">Review'), 'admin queue exposes Review beside Archive/Restore and preserves archived detail access');
$assert(!str_contains($reviewerActiveQueueMarkup, 'action="/staff/application/archive"') && !str_contains($reviewerActiveQueueMarkup, 'name="archived"') && !str_contains($reviewerActiveQueueMarkup, 'href="/admin/archive"'), 'reviewer queue does not expose Archive navigation, filters, or archive actions');
$archiveFilters = ['q' => 'AF-ARCH-99', 'status' => 'submitted'];
$archiveApplications = ['rows' => [['id' => 99, 'full_name' => 'Archived Applicant', 'reference_number' => 'AF-ARCH-99', 'program_name' => 'Engineering', 'previous_status' => 'submitted', 'archived_at' => '2026-10-30 00:00:00']], 'total' => 21, 'filters' => $archiveFilters, 'pagination' => Pagination::make(21, ['per_page' => 10]) + ['base_url' => '/admin/archive', 'query' => $archiveFilters, 'target' => 'archive-records']];
$archiveViewSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/archive.php');
ob_start();
require dirname(__DIR__) . '/resources/views/staff/archive.php';
$archiveMarkup = (string) ob_get_clean();
$assert(str_contains($archiveMarkup, '<h1>Archive</h1>') && str_contains($archiveMarkup, 'Archived Applicant') && str_contains($archiveMarkup, 'AF-ARCH-99') && str_contains($archiveMarkup, 'Engineering') && str_contains($archiveMarkup, 'Previous status') && str_contains($archiveMarkup, 'Oct 30, 2026') && str_contains($archiveMarkup, 'name="q"') && str_contains($archiveMarkup, 'name="status"') && str_contains($archiveMarkup, 'value="submitted" selected') && str_contains($archiveMarkup, 'Search: AF-ARCH-99') && str_contains($archiveMarkup, 'Previous status: Submitted') && str_contains($archiveMarkup, 'data-clear-filter="q"') && str_contains($archiveMarkup, 'data-clear-filter="status"') && str_contains($archiveMarkup, 'href="/admin/archive?per_page=10#archive-records"') && str_contains($archiveMarkup, 'q=AF-ARCH-99&amp;status=submitted&amp;per_page=10&amp;page=2#archive-records') && str_contains($archiveMarkup, 'action="/admin/archive/restore"') && str_contains($archiveMarkup, 'name="_csrf"') && str_contains($archiveViewSource, "require dirname(__DIR__) . '/partials/active-filter-chips.php'"), 'Archive reuses shared applied-filter chips, individual removal, Reset Filters, filter-preserving pagination, and protected restore');
$archiveApplications = ['rows' => [], 'total' => 0, 'filters' => $archiveFilters, 'pagination' => Pagination::make(0, ['per_page' => 10]) + ['base_url' => '/admin/archive', 'query' => $archiveFilters, 'target' => 'archive-records']];
ob_start();
require dirname(__DIR__) . '/resources/views/staff/archive.php';
$emptyArchiveMarkup = (string) ob_get_clean();
$assert(str_contains($emptyArchiveMarkup, 'No archived applications match these filters.') && str_contains($emptyArchiveMarkup, 'Reset Filters') && str_contains($emptyArchiveMarkup, 'data-clear-filter="q"') && str_contains($emptyArchiveMarkup, 'data-clear-filter="status"'), 'Archive view shows a clear no-match state and shared applied-filter reset/removal controls');
$archiveFilterScript = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$assert(str_contains($archiveMarkup, 'data-auto-filter-form') && str_contains($archiveMarkup, 'data-filter-change') && str_contains($archiveFilterScript, "const pageKeys = ['page', ...(form.dataset.pageParams || '').split(',').filter(Boolean)]") && str_contains($archiveFilterScript, 'pageKeys.forEach(key => params.delete(key))'), 'changing Archive filters uses the shared auto-filter behavior and resets pagination to page one');
$detailViewSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/application-detail.php');
$applicationDetailCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$assert(str_contains($detailViewSource, 'class="detail-heading-status-actions"') && str_contains($applicationDetailCss, '.staff-review-page .detail-heading-status-actions { display: flex;') && str_contains($applicationDetailCss, 'align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px;') && str_contains($applicationDetailCss, '.staff-review-page .detail-heading { flex-direction: row; flex-wrap: wrap; }'), 'Application Review keeps status and admin action in a right-aligned responsive flex group');
$assert(str_contains($detailViewSource, "action=\"<?= \$isArchivedApplication ? '/staff/application/restore' : '/staff/application/archive' ?>\"") && str_contains($detailViewSource, 'name="_csrf"') && str_contains($detailViewSource, 'data-submit-once') && str_contains($detailViewSource, 'data-confirm="<?= $isArchivedApplication ?'), 'application detail exposes CSRF-protected admin archive/restore forms with the shared confirmation hook');
$assert(str_contains($routeSource, "\$includeArchived = \$user['role'] === 'admin' && (string) (\$_GET['archived'] ?? '') === '1'") && str_contains($routeSource, '$includeArchived);') && str_contains($routeSource, "AuditLog::recordOncePerWindow(\$database, \$user, 'application.viewed'"), 'detail route grants archived lookup only from explicit admin mode and records eligible views');
$assert(str_contains($routeSource, "if (\$path === '/admin/archive')") && str_contains($routeSource, "\$user['role'] !== 'admin'") && str_contains($routeSource, 'StaffService::archivedApplications') && str_contains($routeSource, "'/admin/archive/restore'") && str_contains($routeSource, 'verify_csrf();') && str_contains($routeSource, 'http_response_code(403);') && str_contains($routeSource, 'StaffService::archiveApplication') && str_contains($routeSource, 'StaffService::restoreApplication'), 'Archive routes enforce administrator access, return 403 for other roles, and protect restore with CSRF');
$changedViews = [
    $dropdownPageSources['Academic Management'],
    $dropdownPageSources['Users'],
    $dropdownPageSources['Announcements'],
    $dropdownPageSources['Audit Log'],
    $dropdownPageSources['Application Queue'],
    (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/users.php'),
    $detailViewSource,
];
$inlineMarkupFound = false;
foreach ($changedViews as $viewSource) {
    $inlineMarkupFound = $inlineMarkupFound || preg_match('/<script\b|<style\b|\son[a-z]+\s*=/i', $viewSource) === 1;
}
$assert(!$inlineMarkupFound, 'changed views add no inline scripts, style tags, or inline event handlers');
$managementType = 'programs';
$createFormType = 'program';
$createFormValues = ['program_id' => '4', 'code' => 'ENGX', 'name' => 'Engineering Extension', 'description' => 'Retained description', 'is_active' => '1'];
$createFormError = 'That program name is already used by another program.';
ob_start();
require dirname(__DIR__) . '/resources/views/staff/academic-management.php';
$failedProgramEditMarkup = (string) ob_get_clean();
$assert(str_contains($failedProgramEditMarkup, 'data-open="true" data-editing="true"') && str_contains($failedProgramEditMarkup, 'value="Engineering Extension"') && str_contains($failedProgramEditMarkup, 'value="ENGX"') && str_contains($failedProgramEditMarkup, 'class="form-control is-invalid"'), 'failed program edit reopens its drawer with submitted values and field validation');

$accountsByRole = ['admin' => [], 'reviewer' => [], 'student' => []];
$accountTotals = ['admin' => 0, 'reviewer' => 0, 'student' => 0];
$userCategories = [['key' => 'reviewer', 'id' => 'staff-accounts', 'title' => 'Staff accounts']];
$accountCategory = 'reviewer';
$paginationByRole = ['admin' => ['page_size' => 20], 'reviewer' => ['page_size' => 20], 'student' => ['page_size' => 20]];
$userFilters = ['q' => '', 'account_status' => '', 'role' => '', 'start_date' => '', 'end_date' => '', 'date_range' => ''];
$roleFilter = '';
$staffSetupLink = null;
$staffCreateFormOpen = false;
$staffCreateFormValues = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    ob_start();
    require dirname(__DIR__) . '/resources/views/staff/users.php';
    $usersMarkup = (string) ob_get_clean();
} finally {
    restore_error_handler();
}
$assert(str_contains($usersMarkup, '<h1>Staff Accounts</h1>') && str_contains($usersMarkup, 'Create Authorized Staff') && str_contains($usersMarkup, 'data-drawer-add="#accountEditDrawer"') && str_contains($usersMarkup, 'data-form-drawer') && str_contains($usersMarkup, 'action="/staff/user/save"') && !str_contains($usersMarkup, 'Administrator accounts') && !str_contains($usersMarkup, 'Student accounts'), 'Staff Accounts uses the shared drawer and retains the secure existing creation flow');

$userCategories = [['key' => 'admin', 'id' => 'admin-accounts', 'title' => 'Administrator accounts']];
$accountCategory = 'admin';
ob_start();
require dirname(__DIR__) . '/resources/views/staff/users.php';
$adminAccountsMarkup = (string) ob_get_clean();
$assert(str_contains($adminAccountsMarkup, '<h1>Administrator Accounts</h1>') && str_contains($adminAccountsMarkup, 'data-form-drawer') && !str_contains($adminAccountsMarkup, 'Create Authorized Staff') && !str_contains($adminAccountsMarkup, 'Student accounts'), 'Administrator Accounts uses the shared edit drawer and has no unauthorized create form');

$userCategories = [['key' => 'student', 'id' => 'student-accounts', 'title' => 'Student accounts']];
$accountCategory = 'student';
ob_start();
require dirname(__DIR__) . '/resources/views/staff/users.php';
$studentAccountsMarkup = (string) ob_get_clean();
$assert(str_contains($studentAccountsMarkup, '<h1>Student Accounts</h1>') && str_contains($studentAccountsMarkup, 'name="application_status"') && str_contains($studentAccountsMarkup, 'data-form-drawer') && str_contains($studentAccountsMarkup, 'value="student"') && !str_contains($studentAccountsMarkup, 'Create Authorized Staff') && !str_contains($studentAccountsMarkup, 'Administrator accounts') && !str_contains($studentAccountsMarkup, 'Staff accounts.'), 'Student Accounts preserves its filter and role restriction while using the shared edit drawer');
$usersViewSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/users.php');
$assert(str_contains($usersMarkup, 'academic-management-card') && str_contains($usersMarkup, 'academic-empty-state') && str_contains($usersViewSource, 'staff-table academic-table'), 'User Administration reuses the shared management card, compact table, and empty state');
$assert(str_contains($usersViewSource, 'Applicant ID') && str_contains($usersViewSource, 'View account') && str_contains($usersViewSource, 'View application') && str_contains($usersViewSource, 'mobile_number') && !str_contains($usersViewSource, 'password_hash'), 'student account details expose supported profile/application data without authentication secrets');
$assert(str_contains($routeSource, "if (\$user['role'] !== 'admin')") && str_contains($routeSource, "'application_status' => \$applicationStatusFilter"), 'dedicated account routes retain administrator authorization and the server-side student status filter');
$assert(str_contains($usersMarkup, 'data-confirm-edit="Apply this role and account-status change?"') && !str_contains($usersMarkup, 'name="password_hash"') && !str_contains($usersMarkup, 'name="token"'), 'account edit drawer retains the consequential-change confirmation and exposes no credential fields');
$userCategories = [['key' => 'reviewer', 'id' => 'staff-accounts', 'title' => 'Staff accounts']];
$accountCategory = 'reviewer';
$accountsByRole['reviewer'] = [['id' => 14, 'full_name' => 'Fixture Reviewer', 'email' => 'reviewer@example.test', 'role' => 'reviewer', 'account_status' => 'active', 'last_login_at' => null, 'created_at' => '2025-01-01 00:00:00']];
$accountTotals['reviewer'] = 1;
ob_start();
require dirname(__DIR__) . '/resources/views/staff/users.php';
$populatedUsersMarkup = (string) ob_get_clean();
$assert(str_contains($populatedUsersMarkup, 'data-drawer-edit="#accountEditDrawer"') && str_contains($populatedUsersMarkup, '&quot;user_id&quot;:14') && str_contains($populatedUsersMarkup, '&quot;role&quot;:&quot;reviewer&quot;'), 'account Edit control carries the selected record into the shared drawer without changing its save contract');
$assert(str_contains($populatedUsersMarkup, 'action="/staff/user/delete"') && str_contains($populatedUsersMarkup, 'data-confirm="Delete this account permanently? This is allowed only when the account has no admission history. Accounts with history must be deactivated instead."') && str_contains($populatedUsersMarkup, 'data-confirm="Deactivate this account? The account and its history will be retained."') && str_contains($populatedUsersMarkup, 'name="_csrf"'), 'administrator account rows render CSRF-protected Delete and Deactivate actions with distinct impact confirmations');
$user = ['id' => 3, 'full_name' => 'Fixture Reviewer', 'role' => 'reviewer'];
ob_start();
require dirname(__DIR__) . '/resources/views/staff/users.php';
$nonAdminUserMarkup = (string) ob_get_clean();
$assert(!str_contains($nonAdminUserMarkup, 'action="/staff/user/delete"') && !str_contains($nonAdminUserMarkup, 'Delete this account permanently?'), 'non-admin account views never render a Delete action');
$user = ['id' => 1, 'full_name' => 'Fixture Admin', 'role' => 'admin'];

$announcements = [['id' => 21, 'title' => 'Fixture announcement', 'body' => 'Details for students', 'audience' => 'students', 'starts_at' => '2026-11-05 01:00:00', 'ends_at' => null, 'is_published' => 1, 'state' => 'scheduled', 'creator_name' => 'Fixture Admin', 'updated_at' => '2026-11-01 10:00:00']];
$selectedState = '';
$activeAudience = '';
$activeSearch = '';
$activeStartDate = '';
$activeEndDate = '';
$activeDatePreset = '';
$formValues = [];
$formErrors = [];
$stateCounts = ['draft' => 0, 'scheduled' => 1, 'published' => 0, 'expired' => 0];
$pagination = Pagination::make(1, []);
ob_start();
require dirname(__DIR__) . '/resources/views/staff/announcements.php';
$announcementMarkup = (string) ob_get_clean();
$assert(str_contains($announcementMarkup, 'data-drawer-add="#announcementFormDrawer"') && str_contains($announcementMarkup, 'data-drawer-edit="#announcementFormDrawer"') && str_contains($announcementMarkup, 'data-form-drawer') && str_contains($announcementMarkup, 'data-announcement-form'), 'Announcement Center Add and Edit invoke the same shared drawer component');
$assert(str_contains($announcementMarkup, 'action="/staff/announcement/save"') && str_contains($announcementMarkup, 'name="publication_mode"') && str_contains($announcementMarkup, 'name="starts_at"') && str_contains($announcementMarkup, 'name="ends_at"') && str_contains($announcementMarkup, 'name="is_published"') && str_contains($announcementMarkup, 'name="return_state"'), 'announcement drawer retains the existing save route, scheduling fields, publication values, and filter return fields');
$assert(str_contains($announcementMarkup, 'name="q"') && str_contains($announcementMarkup, 'name="audience"') && str_contains($announcementMarkup, 'name="state"') && str_contains($announcementMarkup, 'data-date-preset') && str_contains($announcementMarkup, 'data-advanced-toggle'), 'Announcement Center filter fields and existing advanced date interaction remain present');

$academicDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$academicDb->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, code TEXT, name TEXT, description TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$academicDb->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT, starts_on TEXT, ends_on TEXT, application_deadline TEXT, is_open INTEGER, created_at TEXT, updated_at TEXT)');
$academicDb->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, academic_program_id INTEGER, academic_year_id INTEGER, deleted_at TEXT)');
$academicDb->exec('CREATE TABLE admission_requirements (id INTEGER PRIMARY KEY, code TEXT, name TEXT, instructions TEXT, academic_program_id INTEGER, academic_year_id INTEGER, allowed_extensions TEXT, max_file_size_bytes INTEGER, is_required INTEGER, is_active INTEGER, sort_order INTEGER, created_at TEXT, updated_at TEXT)');
$academicDb->exec('CREATE TABLE application_documents (id INTEGER PRIMARY KEY, requirement_id INTEGER)');
$academicDb->exec("INSERT INTO academic_programs VALUES (1, 'ARCH', 'Architecture', 'Architecture description', 1, '2025-01-01', '2025-02-01'), (2, 'NURS', 'Nursing', 'Nursing description', 0, '2025-01-01', '2025-02-02'), (3, 'ENG', 'Engineering', 'Engineering description', 1, '2025-01-01', '2025-02-03')");
$academicDb->exec("INSERT INTO academic_years VALUES (1, 'AY 2025-2026', '2025-08-01', '2026-05-31', NULL, 0, '2025-01-01', '2025-02-01'), (2, 'AY 2026-2027', '2026-08-01', '2027-05-31', NULL, 1, '2025-01-01', '2025-02-02')");
$academicDb->exec('INSERT INTO applications (id, academic_program_id, academic_year_id, deleted_at) VALUES (1, 2, 1, NULL), (2, 2, 2, NULL), (3, 1, 2, NULL), (4, 3, 2, "2025-01-01")');
$academicDb->exec("INSERT INTO admission_requirements VALUES (1, 'TRANSCRIPT', 'Transcript', 'Official transcript', 2, 2, 'pdf', 10485760, 1, 1, 1, '2025-01-01', '2025-02-01'), (2, 'ID', 'Identity document', NULL, NULL, NULL, 'pdf,jpg', 5242880, 0, 1, 2, '2025-01-01', '2025-02-02')");
$academicDb->exec('INSERT INTO application_documents (id, requirement_id) VALUES (1, 1), (2, 1)');
$academicAdmin = ['id' => 1, 'full_name' => 'Fixture Admin', 'role' => 'admin'];
$programPageData = StaffService::academicSettings($academicDb, $academicAdmin, ['program_q' => 'ing', 'sort' => 'applications', 'direction' => 'desc', 'per_page' => 1], 'programs');
$activeProgramPageData = StaffService::academicSettings($academicDb, $academicAdmin, ['program_state' => 'active'], 'programs');
$yearPageData = StaffService::academicSettings($academicDb, $academicAdmin, ['sort' => 'applications', 'direction' => 'desc', 'per_page' => 1], 'years');
$openYearPageData = StaffService::academicSettings($academicDb, $academicAdmin, ['year_state' => 'open'], 'years');
$requirementPageData = StaffService::academicSettings($academicDb, $academicAdmin, ['required' => 'required'], 'requirements');
$assert($programPageData['pagination']['total'] === 2 && $programPageData['totalRecordCount'] === 3 && (int) $programPageData['programs'][0]['id'] === 2 && (int) $programPageData['programs'][0]['application_count'] === 2, 'program route returns filtered totals, inactive-program application counts, safe ordering, and one-row pagination');
$assert($activeProgramPageData['pagination']['total'] === 2 && $activeProgramPageData['totalRecordCount'] === 3, 'program active filter updates the filtered count while retaining the all-record total');
$assert($yearPageData['pagination']['total'] === 2 && (int) $yearPageData['years'][0]['id'] === 2 && (int) $yearPageData['years'][0]['application_count'] === 2, 'academic year route sorts and counts actual linked, non-deleted applications');
$assert($openYearPageData['pagination']['total'] === 1 && $openYearPageData['totalRecordCount'] === 2, 'academic year open filter returns a filtered count separately from all years');
$inactiveProgramOptions = array_filter($requirementPageData['programOptions'], static fn (array $option): bool => (int) $option['is_active'] === 0);
$assert($requirementPageData['pagination']['total'] === 1 && (int) $requirementPageData['requirements'][0]['submitted_document_count'] === 2 && count($requirementPageData['programOptions']) === 3 && count($inactiveProgramOptions) === 1, 'requirements route filters, counts submitted documents, and provides status-aware association options');

$auditDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$auditDb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
$auditDb->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER, actor_label TEXT, action TEXT, entity_type TEXT, entity_id INTEGER, outcome TEXT, severity TEXT, ip_address TEXT, details TEXT, created_at TEXT)');
$auditDb->exec("INSERT INTO users VALUES (7, 'admin')");
$auditInsert = $auditDb->prepare('INSERT INTO audit_logs VALUES (:id, 7, :actor, :action, :entity, :entity_id, :outcome, :severity, :ip, :details, :created)');
$auditInsert->execute(['id' => 1, 'actor' => 'Fixture Admin', 'action' => 'program.updated', 'entity' => 'academic_program', 'entity_id' => 3, 'outcome' => 'success', 'severity' => 'notice', 'ip' => '192.0.2.7', 'details' => json_encode(['before' => ['name' => 'Old', 'password_hash' => 'must-not-render'], 'after' => ['name' => 'New', 'nested' => ['access_token' => 'must-not-render']]]), 'created' => gmdate('Y-m-d H:i:s')]);
$auditInsert->execute(['id' => 2, 'actor' => 'Fixture Admin', 'action' => 'application.failed', 'entity' => 'application', 'entity_id' => 9, 'outcome' => 'failure', 'severity' => 'warning', 'ip' => null, 'details' => '{malformed', 'created' => gmdate('Y-m-d H:i:s')]);
$auditPage = StaffService::auditLog($auditDb, ['action' => 'program.updated', 'per_page' => 10]);
$assert($auditPage['summary']['total'] === 1 && $auditPage['summary']['successful'] === 1 && $auditPage['rows'][0]['actor_role'] === 'admin' && $auditPage['rows'][0]['safe_details']['before']['name'] === 'Old' && !isset($auditPage['rows'][0]['safe_details']['before']['password_hash']), 'audit query keeps existing filtering, returns filtered summary/current actor role, and redacts secret JSON keys');
$auditServiceSource = (string) file_get_contents(dirname(__DIR__) . '/app/StaffService.php');
$reservedRoleAlias = 'current' . '_' . 'role';
$assert(str_contains($auditServiceSource, 'users.role AS actor_role') && !str_contains($auditServiceSource, 'users.role AS ' . $reservedRoleAlias), 'audit retrieval aliases users.role as actor_role, not the MariaDB reserved word');
$assert(StaffService::safeAuditDetails('{malformed') === null && !isset($auditPage['rows'][0]['safe_details']['after']['nested']['access_token']), 'audit detail parsing handles malformed JSON and recursively excludes token fields');
$filters = $auditPage['filters'];
$events = $auditPage['rows'];
$pagination = $auditPage['pagination'];
$auditSummary = $auditPage['summary'];
ob_start();
require dirname(__DIR__) . '/resources/views/staff/audit.php';
$auditMarkup = (string) ob_get_clean();
$assert(str_contains($auditMarkup, '<h1>Audit Log</h1>') && str_contains($auditMarkup, 'System Administration · Audit Log') && str_contains($auditMarkup, 'Total audit events') && str_contains($auditMarkup, 'academic-table') && str_contains($auditMarkup, 'data-drawer-open="#auditDetailsDrawer"') && str_contains($auditMarkup, 'data-form-drawer') && !str_contains($auditMarkup, 'must-not-render'), 'Audit Log renders its redesigned summary/table and shared read-only details drawer without secrets');
$auditViewSource = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/audit.php');
$assert(str_contains($auditViewSource, 'name="q"') && str_contains($auditViewSource, 'name="outcome"') && str_contains($auditViewSource, 'name="actor"') && str_contains($auditViewSource, 'name="action"') && str_contains($auditViewSource, 'name="entity_type"') && str_contains($auditViewSource, 'data-advanced-toggle') && str_contains($auditViewSource, "'base_url' => '/staff/audit'"), 'Audit Log retains existing filter names, More Filters control, and pagination route');

echo "Management and audit render tests: {$assertions} assertions, 0 failures.\n";
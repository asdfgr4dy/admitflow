<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/LoginRateLimitPolicy.php';
require dirname(__DIR__) . '/app/StatusTransitionPolicy.php';
require dirname(__DIR__) . '/app/UploadPolicy.php';
require dirname(__DIR__) . '/app/PasswordResetDelivery.php';
require dirname(__DIR__) . '/app/PasswordResetService.php';
require dirname(__DIR__) . '/app/AuthService.php';
require dirname(__DIR__) . '/app/ApplicationService.php';
require dirname(__DIR__) . '/app/AnnouncementService.php';
require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/app/NotificationService.php';
require dirname(__DIR__) . '/app/AccountSettingsService.php';
require dirname(__DIR__) . '/app/StaffAccountService.php';
require dirname(__DIR__) . '/app/AvatarService.php';
require_once dirname(__DIR__) . '/app/Database.php';

$GLOBALS['test_request_ip'] = '192.0.2.10';

$tests = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$tests, &$failures): void {
    $tests++;
    if (!$condition) $failures[] = $message;
};

$assert(request_is_https(['HTTPS' => 'on'], '0', '0'), 'direct HTTPS marks the request secure for session cookies');
$configOverride = Database::loadConfiguration([
    'ADMITFLOW_DB_HOST' => '127.0.0.1',
    'ADMITFLOW_DB_PORT' => '3307',
    'ADMITFLOW_DB_DATABASE' => 'admitflow_fresh',
], dirname(__DIR__) . '/config/database.example.php');
$invalidDatabasePortRejected = false;
try {
    Database::loadConfiguration(['ADMITFLOW_DB_PORT' => '70000'], dirname(__DIR__) . '/config/database.example.php');
} catch (RuntimeException) {
    $invalidDatabasePortRejected = true;
}
$assert($configOverride['host'] === '127.0.0.1' && $configOverride['port'] === 3307 && $configOverride['database'] === 'admitflow_fresh' && $invalidDatabasePortRejected, 'shared database configuration loader applies host/port/schema overrides and rejects invalid ports without connecting');
$assert(request_is_https(['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], '1', '0'), 'trusted proxy HTTPS is honored when proxy trust is enabled');
$assert(!request_is_https(['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], '0', '0'), 'forwarded HTTPS is ignored when proxy trust is disabled');
$assert(!request_is_https(['HTTPS' => 'off', 'HTTP_HOST' => '127.0.0.1:8000'], '0', '0'), 'local HTTP remains non-HTTPS without the force-cookie override');
$assert(request_is_https(['HTTPS' => 'off'], '0', '1'), 'force-secure-cookie setting continues to mark the session cookie secure');

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
$_SERVER['HTTP_FORWARDED'] = 'for=203.0.113.8';
$assert(request_ip() === '192.0.2.10', 'audit IP uses a validated direct REMOTE_ADDR and ignores untrusted forwarding headers');
$serverAddress = $_SERVER['REMOTE_ADDR'];
$_SERVER['REMOTE_ADDR'] = '2001:db8::8';
$assert(request_ip() === '2001:db8::8', 'audit IP accepts a valid direct IPv6 address');
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
$assert(request_ip() === null, 'invalid direct address yields no audit IP rather than a forwarded value');
unset($_SERVER['REMOTE_ADDR']);
$assert(request_ip() === null, 'missing direct address yields a null audit IP rather than a forwarded value');
$_SERVER['REMOTE_ADDR'] = $serverAddress;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: PDO SQLite is required. No database tests were run.\n");
    exit(2);
}

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL, account_status TEXT NOT NULL DEFAULT \'active\', must_change_password INTEGER NOT NULL DEFAULT 0, last_login_at TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE student_profiles (user_id INTEGER PRIMARY KEY, mobile_number TEXT NULL, date_of_birth TEXT NULL, address_line TEXT NULL, municipality TEXT NULL, province TEXT NULL)');
$database->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email_normalized TEXT NOT NULL, ip_address TEXT NULL, was_successful INTEGER NOT NULL, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NULL, entity_id INTEGER NULL, outcome TEXT NOT NULL, severity TEXT NOT NULL, ip_address TEXT NULL, request_id TEXT NULL, details TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$database->exec('ALTER TABLE student_profiles ADD COLUMN privacy_consent_at TEXT NULL');

$insertUser = $database->prepare('INSERT INTO users (full_name,email,password_hash,role,account_status) VALUES (:name,:email,:password_hash,:role,\'active\')');
$insertUser->execute(['name' => 'Fixture One', 'email' => 'one@example.test', 'password_hash' => password_hash('OldPass!2345', PASSWORD_DEFAULT), 'role' => 'student']);
$userId = (int) $database->lastInsertId();
$insertUser->execute(['name' => 'Fixture Two', 'email' => 'two@example.test', 'password_hash' => password_hash('OtherPass!2345', PASSWORD_DEFAULT), 'role' => 'student']);
$userTwoId = (int) $database->lastInsertId();
$insertUser->execute(['name' => 'Fixture Reviewer', 'email' => 'reviewer@example.test', 'password_hash' => password_hash('ReviewerPass!2345', PASSWORD_DEFAULT), 'role' => 'reviewer']);
$reviewerId = (int) $database->lastInsertId();
$insertUser->execute(['name' => 'Fixture Admin', 'email' => 'admin@example.test', 'password_hash' => password_hash('AdminPass!2345', PASSWORD_DEFAULT), 'role' => 'admin']);
$adminId = (int) $database->lastInsertId();
$insertUser->execute(['name' => 'Legacy Account', 'email' => 'legacy@example.test', 'password_hash' => password_hash('Legacy10!!', PASSWORD_DEFAULT), 'role' => 'student']);
$legacyUserId = (int) $database->lastInsertId();
$registrationResult = AuthService::register($database, ['full_name' => 'Audit Registration', 'email' => 'audit.registration@example.test', 'password' => 'Secure!Entry2026', 'password_confirmation' => 'Secure!Entry2026', 'privacy_consent' => '1']);
$registrationAudit = $database->query("SELECT user_id, actor_label, details FROM audit_logs WHERE action='account.registered' ORDER BY id DESC LIMIT 1")->fetch();
$assert($registrationResult['ok'] && (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='account.registered'")->fetchColumn() === 1 && (int) $registrationAudit['user_id'] === (int) $database->query("SELECT id FROM users WHERE email='audit.registration@example.test'")->fetchColumn() && (int) $database->query("SELECT must_change_password FROM users WHERE email='audit.registration@example.test'")->fetchColumn() === 0 && !str_contains((string) $registrationAudit['details'], 'Secure!Entry2026'), 'successful registration creates one safe audit event and leaves forced password change disabled');

$assert(SecurityPolicy::csrfMatches('abc123', 'abc123'), 'valid CSRF token accepted');
$assert(count(SecurityPolicy::passwordErrors('Legacy10!!')) > 0, 'legacy 10-character password is rejected for new password creation');
$assert(!SecurityPolicy::csrfMatches('abc123', 'wrong'), 'invalid CSRF token rejected');
$assert(!SecurityPolicy::csrfMatches('abc123', null), 'missing CSRF token rejected');
$assert(SecurityPolicy::hasRole(['role' => 'admin'], ['admin']), 'authorized role accepted');
$assert(!SecurityPolicy::hasRole(['role' => 'student'], ['admin']), 'unauthorized role rejected');
$assert(AnnouncementService::canManage(['role' => 'admin']) && !AnnouncementService::canManage(['role' => 'reviewer']) && !AnnouncementService::canManage(['role' => 'student']), 'announcement management remains restricted to the existing administrator role');
$paginationFirst = Pagination::make(138, ['page' => '1', 'per_page' => '20']);
$paginationMiddle = Pagination::make(138, ['page' => '3', 'per_page' => '20']);
$paginationLast = Pagination::make(138, ['page' => '99999', 'per_page' => '50']);
$paginationInvalid = Pagination::make(138, ['page' => '-3', 'per_page' => '500']);
$paginationEmpty = Pagination::make(0, ['page' => '2', 'per_page' => '10']);
$assert($paginationFirst['from'] === 1 && $paginationFirst['to'] === 20 && $paginationFirst['page'] === 1 && $paginationMiddle['from'] === 41 && $paginationMiddle['to'] === 60 && $paginationLast['page'] === 3 && $paginationLast['from'] === 101 && $paginationLast['to'] === 138, 'shared pagination computes first, middle, clamped last pages and correct record ranges');
$assert($paginationInvalid['page'] === 1 && $paginationInvalid['page_size'] === 20 && $paginationEmpty['page'] === 1 && $paginationEmpty['from'] === 0 && $paginationEmpty['to'] === 0, 'shared pagination clamps invalid pages/page sizes and supports empty results');
$pagination = array_merge($paginationMiddle, ['page_key' => 'page_student', 'base_url' => '/staff/users', 'query' => ['q' => 'Riley', 'role' => 'student', 'page_student' => 3]]);
ob_start();
require dirname(__DIR__) . '/resources/views/partials/pagination.php';
$paginationMarkup = ob_get_clean();
$assert(str_contains($paginationMarkup, 'q=Riley') && str_contains($paginationMarkup, 'role=student') && str_contains($paginationMarkup, 'page_student=2') && str_contains($paginationMarkup, 'aria-current="page">3'), 'shared pagination preserves search and role filters and emits accessible page navigation');
$assert(SecurityPolicy::ownsRecord($userId, $userId), 'owner can access own record');
$assert(!SecurityPolicy::ownsRecord($userId, $userTwoId), 'student cannot access another student record');
$assert(SecurityPolicy::passwordLengthLimits() === ['min' => 12, 'max' => 64, 'max_bytes' => 72] && !SecurityPolicy::passwordLengthIsValid(str_repeat('a', 11)) && SecurityPolicy::passwordLengthIsValid(str_repeat('a', 12)) && SecurityPolicy::passwordLengthIsValid(str_repeat('a', 64)) && !SecurityPolicy::passwordLengthIsValid(str_repeat('a', 65)) && !SecurityPolicy::passwordLengthIsValid(str_repeat('😀', 19)), 'shared password length policy enforces 12-to-64 Unicode characters and a separate 72-byte maximum');
$assert(portal_path_for_user(null) === '/', 'logged-out visitor logo destination remains public homepage');
$assert(portal_path_for_user(['role' => 'student']) === '/portal', 'student portal destination resolves correctly');
$assert(portal_path_for_user(['role' => 'reviewer']) === '/staff', 'reviewer portal destination resolves correctly');
$assert(portal_path_for_user(['role' => 'admin']) === '/admin', 'administrator portal destination resolves correctly');
$assert(portal_path_for_user(['role' => 'unexpected']) === '/login', 'unknown authenticated role does not get a portal destination');

$contentView = dirname(__DIR__) . '/resources/views/errors/not-found.php';
$title = 'Navigation test';
$_SESSION = [];
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$loggedOutLayout = ob_get_clean();
$assert(str_contains($loggedOutLayout, 'class="navbar-brand" href="/"'), 'logged-out AdmitFlow logo links to public home');
$assert(str_contains($loggedOutLayout, '/assets/js/enhanced-student-admissions-logo-v2.png') && str_contains($loggedOutLayout, 'brand-logo-wordmark'), 'public header selects the full AdmitFlow wordmark asset');
$assert(is_file(dirname(__DIR__) . '/public/assets/js/enhanced-student-admissions-logo-v2.png'), 'public wordmark image asset exists at its referenced path');
$assert(str_contains($loggedOutLayout, 'href="/#programs"') && str_contains($loggedOutLayout, 'href="/#process"') && str_contains($loggedOutLayout, 'href="/#requirements"'), 'logged-out navigation includes public landing page sections');
flash('success', 'Saved <script>unsafe</script>');
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$successToastMarkup = ob_get_clean();
$assert(str_contains($successToastMarkup, 'toast-success') && str_contains($successToastMarkup, 'role="status"') && str_contains($successToastMarkup, 'Saved &lt;script&gt;unsafe&lt;/script&gt;') && !isset($_SESSION['flash']), 'success toast escapes dynamic text, announces status, and consumes the session message once');
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$afterToastRefreshMarkup = ob_get_clean();
$assert(!str_contains($afterToastRefreshMarkup, 'data-toast'), 'consumed toast does not repeat on the next page render');
flash('error', 'Could not save.');
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$errorToastMarkup = ob_get_clean();
$assert(str_contains($errorToastMarkup, 'toast-danger') && str_contains($errorToastMarkup, 'role="alert"') && str_contains($errorToastMarkup, 'data-bs-autohide="false"'), 'error toast uses alert semantics and remains until manually dismissed');
$_SESSION['user'] = ['id' => $userId, 'role' => 'student'];
ob_start();
require dirname(__DIR__) . '/resources/views/layout.php';
$studentLayout = ob_get_clean();
$assert(str_contains($studentLayout, 'class="app-sidebar-brand" href="/portal"'), 'student sidebar logo uses the student portal destination');
$assert(str_contains($studentLayout, 'href="/application"') && str_contains($studentLayout, 'href="/notifications"') && str_contains($studentLayout, 'href="/settings"') && str_contains($studentLayout, 'href="/account/password"') && str_contains($studentLayout, 'action="/logout"'), 'student sidebar preserves application and notification links and exposes Settings with existing Security route');
$assert(str_contains($studentLayout, 'data-profile-menu') && str_contains($studentLayout, 'href="/settings/profile"') && str_contains($studentLayout, 'href="/notifications"') && !str_contains($studentLayout, 'href="/settings/notifications"') && !str_contains($studentLayout, 'href="/settings/system"'), 'student profile menu links to the notification feed and excludes admin-only settings');
$assert(str_contains($studentLayout, '/assets/js/enhanced-logo.png') && str_contains($studentLayout, 'class="app-sidebar-logo"'), 'authenticated sidebar selects the icon-only AdmitFlow asset');
$assert(is_file(dirname(__DIR__) . '/public/assets/js/enhanced-logo.png'), 'authenticated icon image asset exists at its referenced path');
$assert(!str_contains($studentLayout, 'href="/#programs"') && !str_contains($studentLayout, 'href="/#process"') && !str_contains($studentLayout, 'href="/#requirements"') && !str_contains($studentLayout, 'href="/#privacy"'), 'authenticated navigation omits public landing page sections');
$roleDestinations = ['reviewer' => '/staff', 'admin' => '/admin'];
$roleLayouts = [];
foreach ($roleDestinations as $role => $destination) {
    $_SESSION['user'] = ['id' => $role === 'admin' ? $adminId : $reviewerId, 'role' => $role];
    ob_start();
    require dirname(__DIR__) . '/resources/views/layout.php';
    $roleLayout = ob_get_clean();
    $roleLayouts[$role] = $roleLayout;
    $assert(str_contains($roleLayout, 'class="app-sidebar-brand" href="' . $destination . '"'), $role . ' sidebar logo uses its authorized portal destination');
    $assert(!str_contains($roleLayout, 'href="/#programs"') && !str_contains($roleLayout, 'href="/#process"') && !str_contains($roleLayout, 'href="/#requirements"') && !str_contains($roleLayout, 'href="/#privacy"'), $role . ' navigation omits public landing page sections');
}
$reviewerLayout = $roleLayouts['reviewer'] ?? '';
$assert(str_contains($reviewerLayout, 'href="/staff/reports"') && str_contains($reviewerLayout, 'href="/settings"') && !str_contains($reviewerLayout, 'href="/staff/users"') && !str_contains($reviewerLayout, 'href="/settings/system"') && !str_contains($reviewerLayout, 'Backup'), 'reviewer sidebar includes personal Settings and reports but excludes administrator destinations');
$adminLayout = $roleLayouts['admin'] ?? '';
$assert(str_contains($adminLayout, 'href="/staff/users/administrators"') && str_contains($adminLayout, 'href="/staff/users/staff"') && str_contains($adminLayout, 'href="/staff/users/students"') && str_contains($adminLayout, 'href="/staff/academic/programs"') && str_contains($adminLayout, 'href="/staff/academic/years"') && str_contains($adminLayout, 'href="/staff/academic/requirements"') && str_contains($adminLayout, 'href="/staff/audit"') && str_contains($adminLayout, 'href="/admin/backups"'), 'admin sidebar includes permitted management, audit, and Backup/Restore routes');
$assert(str_contains($adminLayout, 'href="/settings/system"') && str_contains($adminLayout, 'href="/settings/profile"') && str_contains($adminLayout, 'href="/account/password"') && str_contains($adminLayout, 'href="/notifications"') && !str_contains($adminLayout, 'href="/settings/notifications"'), 'admin profile menu preserves admin settings and links to the existing notification feed');
$profileScript = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$retiredThemeAttribute = 'data-display-' . 'theme';
$retiredDensityAttribute = 'data-display-' . 'density';
$retiredPreferenceStatus = 'data-preference-' . 'status';
$retiredPreferenceTitle = 'Display ' . 'Preferences';
$retiredBrowserLabel = 'This ' . 'browser';
$assert(is_string($profileScript) && str_contains($profileScript, 'data-profile-toggle') && str_contains($profileScript, "event.key === 'ArrowDown'") && str_contains($profileScript, "event.key === 'Escape'") && str_contains($profileScript, 'profileMenu.contains(event.target)'), 'profile dropdown includes keyboard navigation, Escape, and outside-click handling');
$assert(is_string($profileScript) && str_contains($profileScript, 'const showToast = (type, message)') && str_contains($profileScript, 'body.textContent = message') && str_contains($profileScript, 'window.bootstrap.Toast.getOrCreateInstance(toast).show()') && str_contains($profileScript, "showToast(result.ok ? 'success' : 'danger', result.message)"), 'client toast messages use textContent and follow actual notification endpoint results');
$profileViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/settings/profile.php');
$settingsIndexSource = file_get_contents(dirname(__DIR__) . '/resources/views/settings/index.php');
$passwordSettingsSource = file_get_contents(dirname(__DIR__) . '/resources/views/auth/change-password.php');
$passwordControllerSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$systemSettingsSource = file_get_contents(dirname(__DIR__) . '/resources/views/settings/system.php');
$reportViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/reports.php');
$studentPrintViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/student/print-document.php');
$studentPrintContentSource = file_get_contents(dirname(__DIR__) . '/resources/views/student/print-document-content.php');
$studentPortalSource = file_get_contents(dirname(__DIR__) . '/resources/views/student/portal.php');
$academicSettingsViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/settings.php');
$staffUsersViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/users.php');
$applicationModalScriptSource = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$printStylesSource = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$assert(is_string($passwordControllerSource) && str_contains($passwordControllerSource, 'if (!in_array($user[\'role\'], [\'reviewer\', \'admin\'], true))') && str_contains($passwordControllerSource, 'StaffService::applications($database, $user, $reportInput, true, !$printView)') && str_contains($passwordControllerSource, 'recordReportPrintPreview($database, $user, $report[\'filters\']') && is_string($reportViewSource) && str_contains($reportViewSource, 'data-print-preview-trigger') && str_contains($reportViewSource, 'print-preview-modal.php'), 'reviewers and administrators share the authorized report route and shared print preview action');
$assert(is_string($printStylesSource) && str_contains($printStylesSource, 'size: A4 portrait;') && str_contains($printStylesSource, '@page report-landscape') && str_contains($printStylesSource, 'margin: 12mm;') && str_contains($printStylesSource, '.app-sidebar, .app-sidebar-backdrop, .app-topbar') && str_contains($printStylesSource, 'thead { display: table-header-group; }') && str_contains($printStylesSource, 'tr { break-inside: avoid;'), 'print rules hide application controls, use named A4 pages, and preserve table pagination behavior');
$assert(is_string($studentPrintViewSource) && is_string($studentPrintContentSource) && str_contains($studentPrintContentSource, 'Draft application summary') && str_contains($studentPrintContentSource, 'application has been accepted') && str_contains($studentPrintContentSource, 'recorded as complete') && !str_contains($studentPrintContentSource, 'storage_name') && !str_contains($studentPrintContentSource, 'password_reset') && str_contains($studentPrintViewSource, 'print-document-content.php'), 'shared student document content labels drafts and separate outcomes without private storage or security data');
$assert(is_string($passwordControllerSource) && str_contains($passwordControllerSource, 'require_student()') && str_contains($passwordControllerSource, 'ApplicationService::printableSummary($database, (int) $user[\'id\'])') && str_contains($passwordControllerSource, 'Cache-Control: private, no-store') && str_contains($passwordControllerSource, "header('X-Frame-Options: SAMEORIGIN');") && is_string($studentPortalSource) && str_contains($studentPortalSource, 'data-print-preview-trigger') && str_contains($studentPortalSource, 'data-preview-url="/application/print?preview=1"') && str_contains($studentPortalSource, 'print-preview-modal.php'), 'student printing reuses the shared iframe modal and owner-protected routes');
$assert(is_string($academicSettingsViewSource) && str_contains($academicSettingsViewSource, "'id' => 'createProgramModal'") && str_contains($academicSettingsViewSource, "'id' => 'createYearModal'") && str_contains($academicSettingsViewSource, "'id' => 'createRequirementModal'") && str_contains($academicSettingsViewSource, 'name="_csrf"') && str_contains($academicSettingsViewSource, 'data-submit-once') && str_contains($academicSettingsViewSource, "'action' => '/staff/requirement/save'"), 'academic create forms use Bootstrap modals and preserve CSRF-protected existing form posts');
$assert(is_string($staffUsersViewSource) && str_contains($staffUsersViewSource, 'data-drawer-add="#accountEditDrawer"') && str_contains($staffUsersViewSource, 'action="/staff/user/save"') && str_contains($staffUsersViewSource, 'action="/staff/user/delete"') && str_contains($staffUsersViewSource, 'name="role"') && str_contains($staffUsersViewSource, 'name="_csrf"') && str_contains($staffUsersViewSource, 'data-submit-once') && str_contains($staffUsersViewSource, 'Deactivate') && is_string($applicationModalScriptSource) && str_contains($applicationModalScriptSource, 'data-create-form-modal'), 'staff account drawer and row actions retain role assignment, CSRF, deletion/deactivation, and duplicate-submit prevention');
$assert(is_string($profileViewSource) && str_contains($profileViewSource, 'name="full_name"') && str_contains($profileViewSource, 'Read-only') && !str_contains($profileViewSource, 'password_hash'), 'profile page supports validated display-name edit and keeps email read-only without sensitive data');
$assert(is_string($profileViewSource) && str_contains($profileViewSource, 'profile-settings-grid') && str_contains($profileViewSource, 'data-avatar-preview') && str_contains($profileViewSource, 'data-avatar-input') && str_contains($profileViewSource, 'profile-name-error') && str_contains($profileViewSource, 'href="/account/password"') && str_contains($profileViewSource, 'href="/notifications"') && !str_contains($profileViewSource, $retiredThemeAttribute) && !str_contains($profileViewSource, $retiredDensityAttribute) && !str_contains($profileViewSource, $retiredPreferenceTitle) && !str_contains($profileViewSource, $retiredBrowserLabel), 'profile retains identity/account tools while omitting the retired appearance panel and browser label');
$assert(is_string($profileViewSource) && str_contains($profileViewSource, 'data-profile-card') && str_contains($profileViewSource, 'data-profile-view') && str_contains($profileViewSource, 'data-profile-edit') && str_contains($profileViewSource, 'data-profile-edit-toggle') && str_contains($profileViewSource, 'data-profile-cancel') && str_contains($profileViewSource, 'data-profile-saved-value') && str_contains($profileViewSource, 'Save Changes') && substr_count($profileViewSource, 'action="/settings/profile"') === 1, 'profile uses one card and one form with read-only/edit modes, reversible baseline values, and save/cancel actions');
$renderProfileSettings = static function (array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/resources/views/settings/profile.php';
    return (string) ob_get_clean();
};
$profileFixture = ['profile' => ['full_name' => 'Fixture Student', 'email' => 'fixture@example.test', 'role' => 'student', 'account_status' => 'active', 'created_at' => '2025-01-01 00:00:00', 'mobile_number' => '09171234567', 'date_of_birth' => '2005-05-10', 'address_line' => '10 Test Road', 'municipality' => 'Test City', 'province' => 'Test Province', 'emergency_contact_name' => 'Emergency Contact', 'emergency_contact_number' => '09171234568'], 'applicantProfileValues' => [], 'applicantProfileErrors' => [], 'profileFormName' => 'Fixture Student', 'profileFormError' => null, 'missingApplicantFields' => [], 'showApplicationCompletionPrompt' => false, 'avatarAvailable' => false];
$profileViewModeHtml = $renderProfileSettings($profileFixture);
$assert(substr_count($profileViewModeHtml, 'data-profile-card') === 1 && substr_count($profileViewModeHtml, 'action="/settings/profile"') === 1 && str_contains($profileViewModeHtml, 'data-profile-view') && !str_contains($profileViewModeHtml, 'data-profile-view hidden') && str_contains($profileViewModeHtml, 'data-profile-edit data-submit-once hidden') && str_contains($profileViewModeHtml, 'Fixture Student') && str_contains($profileViewModeHtml, '10 Test Road'), 'profile view mode renders one single card with actual applicant values and only one hidden edit form');
$profileFixture['applicantProfileValues'] = ['full_name' => 'Typed Applicant', 'mobile_number' => 'invalid'];
$profileFixture['applicantProfileErrors'] = ['mobile_number' => 'Enter a valid mobile number.'];
$profileEditModeHtml = $renderProfileSettings($profileFixture);
$assert(str_contains($profileEditModeHtml, 'data-profile-view hidden') && str_contains($profileEditModeHtml, 'data-profile-edit data-submit-once') && !str_contains($profileEditModeHtml, 'data-profile-edit data-submit-once hidden') && str_contains($profileEditModeHtml, 'value="Typed Applicant"') && str_contains($profileEditModeHtml, 'value="invalid"') && str_contains($profileEditModeHtml, 'Enter a valid mobile number.'), 'profile validation failure re-renders the same card in edit mode preserving entered values and field errors');
$assert(is_string($profileScript) && str_contains($profileScript, 'profileEditToggle.addEventListener') && str_contains($profileScript, 'profileCancel.addEventListener') && str_contains($profileScript, 'profileEditToggle.setAttribute') && str_contains($profileScript, 'input.dataset.profileSavedValue') && str_contains($profileScript, 'clearLocalAvatarSelection()') && str_contains($profileScript, 'profileEditToggle.focus()'), 'Edit Profile opens the same card with focus and ARIA state; Cancel restores saved fields and discards pending avatar changes');
$assert(is_string($profileViewSource) && str_contains($profileViewSource, 'id="profile_edit_email"') && str_contains($profileViewSource, 'id="profile_edit_role"') && str_contains($profileViewSource, 'id="profile_edit_status"') && str_contains($profileViewSource, 'id="profile_edit_created"') && !str_contains($profileViewSource, 'name="email"') && !str_contains($profileViewSource, 'name="account_status"'), 'email, role, status, and account creation metadata remain readonly and are not submitted as profile updates');
$assert(str_contains($profileViewSource, 'applicant-profile-form') && str_contains($profileViewSource, 'profile_mobile_number') && str_contains($profileViewSource, 'profile_date_of_birth') && str_contains($profileViewSource, 'profile_address_line') && str_contains($profileViewSource, 'profile_emergency_contact_name'), 'applicant profile form exposes existing contact/address fields needed to satisfy the server-side start gate');
$avatarSaveOutsideDialog = is_string($profileViewSource) && preg_match('/<button[^>]*form="avatar-upload-form"[^>]*data-avatar-save[^>]*>.*?<\/button>/s', $profileViewSource) === 1;
$assert(is_string($profileViewSource) && str_contains($profileViewSource, 'data-avatar-menu-toggle') && str_contains($profileViewSource, 'aria-haspopup="menu"') && str_contains($profileViewSource, 'role="menu"') && str_contains($profileViewSource, 'Upload new photo') && str_contains($profileViewSource, 'data-avatar-preview-open') && str_contains($profileViewSource, 'data-avatar-save') && $avatarSaveOutsideDialog && str_contains($profileViewSource, 'form="avatar-remove-form"') && str_contains($profileViewSource, 'data-confirm="Remove your current profile picture?"') && str_contains($profileViewSource, 'name="_csrf"') && str_contains($profileViewSource, 'accept="image/jpeg,image/png"'), 'avatar popover has a separate save button outside preview and conditional CSRF-protected removal');
$avatarServiceSource = file_get_contents(dirname(__DIR__) . '/app/AvatarService.php');
$assert(is_string($avatarServiceSource) && str_contains($avatarServiceSource, '$previousName = self::currentName($root, $userId)') && str_contains($avatarServiceSource, 'file_put_contents($pointer, $previousName, LOCK_EX)') && str_contains($avatarServiceSource, 'unlink($target)') && str_contains($avatarServiceSource, 'if ($previousName !== null && $previousName !== $name)'), 'failed avatar pointer update restores the saved avatar and only removes the new upload');
$assert(is_string($profileScript) && str_contains($profileScript, 'data-avatar-menu-toggle') && str_contains($profileScript, 'ArrowDown') && str_contains($profileScript, 'ArrowUp') && str_contains($profileScript, "event.key === 'Home'") && str_contains($profileScript, "event.key === 'End'") && str_contains($profileScript, "event.key === 'Escape'") && str_contains($profileScript, 'avatarMenu.contains(event.target)') && str_contains($profileScript, 'avatarInput?.click()') && str_contains($profileScript, 'previewUrl && avatarPreviewDialog?.showModal') && str_contains($profileScript, 'event.submitter'), 'avatar menu supports keyboard movement, Escape/outside dismissal, native picker, menu-triggered preview dialog, and duplicate-submit button state');
$assert(is_string($profileScript) && str_contains($profileScript, 'const discardAvatarPreview = () =>') && str_contains($profileScript, 'avatarInput.value = \'\'') && str_contains($profileScript, 'restoreSavedAvatar()') && str_contains($profileScript, "avatarPreviewDialog?.addEventListener('cancel'") && str_contains($profileScript, 'URL.revokeObjectURL(previewUrl)'), 'cancelled previews clear pending file data and restore the saved avatar/fallback without uploading');
$assert(is_string($profileScript) && str_contains($profileScript, 'URL.createObjectURL(file)') && str_contains($profileScript, 'URL.revokeObjectURL(previewUrl)') && str_contains($profileScript, 'form[data-submit-once]') && str_contains($profileScript, 'Choose a JPEG or PNG image no larger than 2 MB.'), 'avatar preview validates client-side type/size and releases object URLs while forms prevent duplicate submissions');
$assert(str_contains($passwordControllerSource, "\$_SESSION['profile_form_name'] = (string) (\$_POST['full_name'] ?? '')") && str_contains($passwordControllerSource, "\$_SESSION['profile_form_error'] = \$result['message']") && str_contains($passwordControllerSource, "unset(\$_SESSION['profile_form_name'], \$_SESSION['profile_form_error'])"), 'invalid profile names retain submitted input and a field-level server validation message across redirect');
$assert(is_string($settingsIndexSource) && !str_contains($settingsIndexSource, $retiredThemeAttribute) && !str_contains($settingsIndexSource, $retiredDensityAttribute) && !str_contains($settingsIndexSource, $retiredPreferenceStatus) && !str_contains($settingsIndexSource, 'href="/settings/notifications"'), 'Settings landing page contains no removed appearance controls or unsupported notification-preference route');
$assert(is_string($profileScript) && str_contains($profileScript, 'window.localStorage.getItem(storageKey)') && !str_contains($profileScript, $retiredThemeAttribute) && !str_contains($profileScript, $retiredDensityAttribute) && is_string($printStylesSource) && !str_contains($printStylesSource, 'data-resolved-theme') && !str_contains($passwordControllerSource, 'data-settings-user-id'), 'sidebar expansion may persist while removed appearance preferences have no persistence handler or theme layout hooks');
$assert(is_string($passwordSettingsSource) && str_contains($passwordSettingsSource, 'security-settings-page') && str_contains($passwordSettingsSource, 'Security Settings') && str_contains($passwordSettingsSource, 'Protect your AdmitFlow account') && str_contains($passwordSettingsSource, 'security-settings-grid') && str_contains($passwordSettingsSource, 'name="_csrf"') && str_contains($passwordSettingsSource, 'action="/account/password"') && str_contains($passwordSettingsSource, 'name="current_password"') && str_contains($passwordSettingsSource, 'name="new_password"') && str_contains($passwordSettingsSource, 'name="password_confirmation"') && str_contains($passwordSettingsSource, 'Update Password') && str_contains($passwordSettingsSource, 'Recent sign-in attempts') && str_contains($passwordSettingsSource, 'not a list of active sessions') && str_contains($passwordSettingsSource, 'data-password-strength') && str_contains($passwordSettingsSource, 'data-password-toggle') && str_contains($passwordSettingsSource, 'data-submit-once') && is_string($passwordControllerSource) && str_contains($passwordControllerSource, 'AuthService::changePassword') && str_contains($passwordControllerSource, 'SecurityPolicy::passwordLengthLimits()'), 'Security Settings has a distinct responsive layout while preserving the CSRF-protected password flow and truthful sign-in history');
$assert(is_string($profileScript) && str_contains($profileScript, 'new TextEncoder().encode(normalizedValue).length <= 72') && str_contains($profileScript, 'data-password-checklist') && str_contains($profileScript, 'data-password-confirmation'), 'shared password checklist checks the normalized server byte ceiling and password confirmation');
$assert(passwordChangeRequiredRouteAllowed('/account/password', 'GET') && passwordChangeRequiredRouteAllowed('/account/password', 'POST') && passwordChangeRequiredRouteAllowed('/logout', 'POST') && !passwordChangeRequiredRouteAllowed('/account/password', 'HEAD') && !passwordChangeRequiredRouteAllowed('/logout', 'GET') && !passwordChangeRequiredRouteAllowed('/portal', 'GET') && !passwordChangeRequiredRouteAllowed('/staff/reports', 'GET'), 'forced password change allows only password GET/POST and logout POST among authenticated routes');
$assert(is_string($systemSettingsSource) && str_contains($systemSettingsSource, 'href="/staff/academic/programs"') && str_contains($systemSettingsSource, 'href="/staff/academic/years"') && str_contains($systemSettingsSource, 'href="/staff/academic/requirements"') && str_contains($systemSettingsSource, 'href="/staff/users/administrators"') && str_contains($systemSettingsSource, 'href="/staff/users/staff"') && str_contains($systemSettingsSource, 'href="/staff/users/students"') && str_contains($systemSettingsSource, 'href="/staff/audit"') && str_contains($systemSettingsSource, 'href="/admin/backups"') && str_contains($systemSettingsSource, '$systemInfo[\'version\']') && str_contains($systemSettingsSource, '$systemInfo[\'mail\'][\'status\']') && str_contains($systemSettingsSource, 'does not enable email notifications') && str_contains($systemSettingsSource, 'Admissions configuration') && str_contains($systemSettingsSource, 'Access and data protection'), 'System Settings links to the permitted administration, audit, mail, and backup tools');
$database->exec('UPDATE users SET must_change_password = 1 WHERE id=' . $userId);
$originalProfileName = (string) $database->query('SELECT full_name FROM users WHERE id=' . $userId)->fetchColumn();
$invalidProfileUpdate = AccountSettingsService::updateProfileName($database, ['id' => $userId, 'full_name' => $originalProfileName, 'role' => 'student'], 'Invalid123');
$assert(!$invalidProfileUpdate['ok'] && $database->query('SELECT full_name FROM users WHERE id=' . $userId)->fetchColumn() === $originalProfileName, 'invalid profile update preserves existing account name');
$validProfileUpdate = AccountSettingsService::updateProfileName($database, ['id' => $userId, 'full_name' => $originalProfileName, 'role' => 'student'], 'Fixture Student Updated');
$assert($validProfileUpdate['ok'] && $database->query('SELECT full_name FROM users WHERE id=' . $userId)->fetchColumn() === 'Fixture Student Updated' && (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='account.profile_updated' AND entity_id=" . $userId)->fetchColumn() === 1 && (int) $database->query('SELECT must_change_password FROM users WHERE id=' . $userId)->fetchColumn() === 1, 'valid self-profile name update is persisted and audited without clearing the forced-change flag');
$profileAuditIp = $database->query("SELECT ip_address FROM audit_logs WHERE action='account.profile_updated' AND entity_id=" . $userId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
$assert($profileAuditIp === '192.0.2.10', 'account profile-change audit event stores the direct client IP');
$assert((string) $database->query('SELECT full_name FROM users WHERE id=' . $userTwoId)->fetchColumn() === 'Fixture Two', "profile update cannot alter another user's account");
$staffInviteToken = '';
$staffAccount = StaffAccountService::create($database, ['id' => $adminId, 'full_name' => 'Fixture Admin', 'role' => 'admin'], 'Fixture New Reviewer', 'new.reviewer@example.test', 'reviewer', static function (string $email, string $name, string $token, bool $resend) use (&$staffInviteToken): array {
    $staffInviteToken = $token;
    return ['ok' => $email === 'new.reviewer@example.test' && $name === 'Fixture New Reviewer' && !$resend];
});
$staffFlagQuery = $database->prepare('SELECT must_change_password FROM users WHERE id = :user_id');
$staffFlagQuery->execute(['user_id' => $staffAccount['user_id']]);
$assert((int) $staffFlagQuery->fetchColumn() === 1, 'administrator-created account starts with a forced password change');
$staffCreationAuditIp = $database->query("SELECT ip_address FROM audit_logs WHERE action='user.staff_account_created' ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert($staffCreationAuditIp === '192.0.2.10', 'staff account creation audit event stores the direct client IP');
$staffTokenQuery = $database->prepare('SELECT token_hash FROM password_reset_tokens WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
$staffTokenQuery->execute(['user_id' => $staffAccount['user_id']]);
$staffTokenHash = (string) $staffTokenQuery->fetchColumn();
$staffTokenExpiryQuery = $database->prepare('SELECT expires_at FROM password_reset_tokens WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
$staffTokenExpiryQuery->execute(['user_id' => $staffAccount['user_id']]);
$staffExpiry = strtotime((string) $staffTokenExpiryQuery->fetchColumn() . ' UTC');
$assert($staffAccount['invitation']['ok'] && preg_match('/^[a-f0-9]{64}$/', $staffInviteToken) === 1 && hash_equals(hash('sha256', $staffInviteToken), $staffTokenHash) && $staffExpiry >= time() + 29 * 60 && $staffExpiry <= time() + 31 * 60 && !array_key_exists('password', $staffAccount) && !array_key_exists('setup_path', $staffAccount), 'staff invitation is delivered to its registered address, stores only a 30-minute token hash, and returns neither password nor setup URL');
$invitationAuditDetails = $database->query("SELECT details FROM audit_logs WHERE action LIKE 'user.staff_invitation_%' ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
$assert($invitationAuditDetails !== [] && !str_contains(implode(' ', array_map('strval', $invitationAuditDetails)), $staffInviteToken), 'invitation audit events do not contain plaintext tokens or setup URLs');
$failedCreation = StaffAccountService::create($database, ['id' => $adminId, 'full_name' => 'Fixture Admin', 'role' => 'admin'], 'Fixture Mail Failure', 'failed.reviewer@example.test', 'reviewer', static fn (): array => ['ok' => false, 'message' => 'SMTP unavailable.']);
$failedCreationState = $database->prepare('SELECT account_status, must_change_password FROM users WHERE id = :user_id');
$failedCreationState->execute(['user_id' => $failedCreation['user_id']]);
$failedCreationRow = $failedCreationState->fetch();
$assert(!$failedCreation['invitation']['ok'] && is_array($failedCreationRow) && $failedCreationRow['account_status'] === 'active' && (int) $failedCreationRow['must_change_password'] === 1 && (int) $database->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=' . (int) $failedCreation['user_id'] . ' AND used_at IS NULL')->fetchColumn() === 0, 'SMTP failure leaves the newly created staff account intact and invalidates the undelivered invitation');
$unauthorizedInvitation = false;
try {
    StaffAccountService::sendInvitation($database, ['id' => $reviewerId, 'full_name' => 'Fixture Reviewer', 'role' => 'reviewer'], $staffAccount['user_id'], true, static fn (): array => ['ok' => true]);
} catch (DomainException) {
    $unauthorizedInvitation = true;
}
$assert($unauthorizedInvitation, 'reviewer cannot create or resend staff invitations');
$failedResendToken = '';
$failedResend = StaffAccountService::sendInvitation($database, ['id' => $adminId, 'full_name' => 'Fixture Admin', 'role' => 'admin'], $staffAccount['user_id'], true, static function (string $email, string $name, string $token, bool $resend) use (&$failedResendToken): array {
    $failedResendToken = $token;
    return ['ok' => false, 'message' => 'Mock SMTP failure.'];
});
$failedTokenCheck = $database->prepare('SELECT used_at FROM password_reset_tokens WHERE user_id = :user_id AND token_hash = :token_hash');
$failedTokenCheck->execute(['user_id' => $staffAccount['user_id'], 'token_hash' => hash('sha256', $failedResendToken)]);
$assert(!$failedResend['ok'] && $failedTokenCheck->fetchColumn() !== false && $database->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=' . (int) $staffAccount['user_id'] . ' AND used_at IS NULL')->fetchColumn() == 0, 'failed invitation resend invalidates its token and leaves no active invitation token');
$staffInviteToken = '';
$resentInvitation = StaffAccountService::sendInvitation($database, ['id' => $adminId, 'full_name' => 'Fixture Admin', 'role' => 'admin'], $staffAccount['user_id'], true, static function (string $email, string $name, string $token, bool $resend) use (&$staffInviteToken): array {
    $staffInviteToken = $token;
    return ['ok' => $email === 'new.reviewer@example.test' && $resend];
});
$assert($resentInvitation['ok'], 'administrator can resend the invitation to the account email');
$rateLimitedInvitation = StaffAccountService::sendInvitation($database, ['id' => $adminId, 'full_name' => 'Fixture Admin', 'role' => 'admin'], $staffAccount['user_id'], true, static fn (): array => ['ok' => true]);
$assert(!$rateLimitedInvitation['ok'] && $rateLimitedInvitation['reason'] === 'rate_limited' && (int) $database->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=' . (int) $staffAccount['user_id'] . ' AND used_at IS NULL')->fetchColumn() === 1, 'invitation resend is capped and never creates multiple active tokens');
$staffPasswordSet = PasswordResetService::reset($database, $staffInviteToken, 'FirstLoginPass123', 'FirstLoginPass123');
$staffPasswordReplay = PasswordResetService::reset($database, $staffInviteToken, 'AnotherPass123', 'AnotherPass123');
$staffPasswordQuery = $database->prepare('SELECT password_hash, role, account_status FROM users WHERE id = :user_id');
$staffPasswordQuery->execute(['user_id' => $staffAccount['user_id']]);
$staffFlagQuery->execute(['user_id' => $staffAccount['user_id']]);
$staffPasswordRow = $staffPasswordQuery->fetch();
$assert($staffPasswordSet['ok'] && password_verify('FirstLoginPass123', (string) $staffPasswordRow['password_hash']) && $staffPasswordRow['role'] === 'reviewer' && $staffPasswordRow['account_status'] === 'active' && (int) $staffFlagQuery->fetchColumn() === 0 && !$staffPasswordReplay['ok'], 'staff member can set an initial password once while role and account status remain unchanged');
$staffAccountViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/users.php');
$assert(is_string($staffAccountViewSource) && str_contains($staffAccountViewSource, '$userCategories') && str_contains($staffAccountViewSource, 'name="role"') && str_contains($staffAccountViewSource, 'name="account_status"') && str_contains($staffAccountViewSource, 'name="q"') && str_contains($staffAccountViewSource, 'An invitation email is sent after account creation') && str_contains($staffAccountViewSource, 'action="/staff/user/invitation/resend"') && str_contains($staffAccountViewSource, "'confirm_edit' => 'Apply this role and account-status change?'"), 'user administration exposes role/status/search filters, automatic email invitation and resend controls, and existing access-change confirmation');
$_SESSION = [];
$frontController = file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert(is_string($frontController) && str_contains($frontController, '/admin/backups/create') && str_contains($frontController, '/admin/backups/verify') && str_contains($frontController, '/admin/backups/restore') && str_contains($frontController, 'BackupService::restore'), 'system Backup/Restore handlers are registered behind admin route checks');
$assert(substr_count($frontController, 'AccountSettingsService::applicationStartGate($database, (int) $user[\'id\'])') === 2 && str_contains($frontController, 'if ($existingApplication === null)') && str_contains($frontController, "redirect('/settings/profile?complete_application=1')"), 'both GET and POST new-application routes share a server-side profile gate while existing applications bypass it');
$assert(is_string($frontController) && str_contains($frontController, "if (\$path === '/')") && str_contains($frontController, 'redirect(portal_path_for_user($user))'), 'public home route redirects authenticated users while leaving logged-out visitors on the public page');
$forgotPasswordViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/auth/forgot-password.php');
$resetPasswordViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/auth/reset-password.php');
$assert(is_string($forgotPasswordViewSource) && str_contains($forgotPasswordViewSource, 'action="/forgot-password"') && str_contains($frontController, "if (\$path === '/forgot-password')") && str_contains($frontController, 'PasswordResetService::request($database, (string) ($_POST[\'email\'] ?? \'\'))') && str_contains($frontController, "redirect('/forgot-password')"), 'forgot-password form POST is handled and returns the same generic confirmation route');
$assert(is_string($resetPasswordViewSource) && str_contains($frontController, "if (\$path === '/reset-password')") && str_contains($frontController, "\$_GET['token']") && str_contains($resetPasswordViewSource, 'action="/reset-password"') && str_contains($resetPasswordViewSource, 'name="token"'), 'reset link GET route opens the token-bearing form and its POST submits to the same route');
$localResetUrl = PasswordResetDelivery::buildResetUrl('http://127.0.0.1:8000', str_repeat('b', 64), true);
$assert(is_string($localResetUrl) && parse_url($localResetUrl, PHP_URL_PATH) === '/reset-password' && str_contains($frontController, "if (\$path === '/reset-password')"), 'local reset URL targets the registered front-controller route');
$assert(str_contains($frontController, "if (\$path === '/logout')") && str_contains($frontController, "redirect('/');"), 'existing logout route returns to public home after destroying the session');
$assert(str_contains($frontController, "if (\$path === '/settings/notifications')") && str_contains($frontController, "redirect('/notifications');"), 'retired Notification Preferences route redirects authenticated users to the existing feed');
$assert(str_contains($frontController, "AvatarService::store((int) \$user['id']") && str_contains($frontController, "AvatarService::remove((int) \$user['id']") && str_contains($frontController, "header('Cache-Control: private, no-store')") && str_contains($frontController, "header('X-Content-Type-Options: nosniff')"), 'avatar upload, removal, and image delivery use authenticated ownership and private response headers');
$assert(str_contains($profileViewSource, 'enctype="multipart/form-data"') && str_contains($profileViewSource, 'name="_csrf"') && str_contains($profileViewSource, 'accept="image/jpeg,image/png"') && str_contains($profileViewSource, 'strtoupper(mb_substr'), 'profile avatar form is CSRF-protected and preserves the initial-letter fallback');
$assert(str_contains($frontController, "AuthService::loginHistory(\$database, (string) \$user['email'], \$_GET)") && str_contains($passwordSettingsSource, 'Recent sign-in attempts') && str_contains($passwordSettingsSource, 'not a list of active sessions'), 'Security page shows paginated account-scoped sign-in records without presenting them as active sessions');
$assert(str_contains($frontController, "header('Cache-Control: no-store')"), 'responses prevent cached authenticated pages from persisting across logout');
$assert(str_contains($frontController, "if (\$path === '/settings/system')") && str_contains($frontController, "if (\$user['role'] !== 'admin')"), 'System Settings has explicit server-side administrator authorization');
$assert(str_contains($frontController, "echo json_encode(['ok' => \$actionSucceeded") && str_contains($frontController, "http_response_code(404)") && str_contains($frontController, 'flash($actionSucceeded ? \'success\' : \'warning\''), 'notification actions return truthful status and never emit success feedback for failed updates');
$assert(str_contains($frontController, "'mail' => MailService::configurationStatus()") && str_contains($systemSettingsSource, 'SMTP test recipient') && str_contains($frontController, "if (\$path === '/settings/system/mail-test')") && str_contains($frontController, "if (\$path === '/staff/user/invitation/resend')") && strpos($frontController, 'verify_csrf();') < strpos($frontController, "if (\$path === '/settings/system/mail-test')") && str_contains($frontController, "if ((\$user['role'] ?? '') !== 'admin')"), 'System Settings exposes safe SMTP readiness and a CSRF/admin-only test action; staff invitations have a dedicated resend action');
$assert(str_contains($frontController, "if (\$path === '/settings/profile')") && str_contains($frontController, 'AccountSettingsService::updateProfileName') && str_contains($frontController, 'AccountSettingsService::profile'), 'profile route is authenticated and updates only the current account through the account service');
$assert(str_contains($frontController, "if (\$path === '/settings/system')") && str_contains($frontController, "if (\$user['role'] !== 'admin')"), 'System Settings has explicit server-side administrator authorization');
$assert(str_contains($frontController, "#^/document/(\\d+)/preview$") && str_contains($frontController, 'authorizedCurrentDocument'), 'student preview route uses the owner-scoped current-document service');
$assert(str_contains($frontController, "#^/staff/document/(\\d+)/preview$") && str_contains($frontController, "Content-Disposition: inline") && str_contains($frontController, "Cache-Control: private, no-store") && str_contains($frontController, "X-Content-Type-Options: nosniff"), 'document preview endpoint is authenticated inline, private-cache, and MIME-sniff protected');
$assert(str_contains($frontController, "img-src 'self' data: blob:; frame-src 'self' blob:"), 'CSP permits only explicit blob image/frame sources needed for fetched previews');
$reviewView = file_get_contents(dirname(__DIR__) . '/resources/views/staff/application-detail.php');
$assert(is_string($reviewView) && str_contains($reviewView, 'data-quick-view') && str_contains($reviewView, 'bi-eye') && str_contains($reviewView, 'data-document-preview') && str_contains($reviewView, 'disabled aria-disabled="true"'), 'staff review view provides preview modal/action and disables preview when no upload exists');
$assert(str_contains($reviewView, 'action="/staff/document/review"') && str_contains($reviewView, 'href="/staff/document/'), 'document preview preserves existing download and review actions');
$previewScript = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$assert(is_string($previewScript) && str_contains($previewScript, 'data-preview-close') && str_contains($previewScript, "event.key === 'Escape'") && str_contains($previewScript, 'URL.revokeObjectURL'), 'preview dialog supports close/Escape and releases temporary preview URLs');

$programs = [['name' => 'Fixture Program', 'code' => 'FIX']];
$requirements = [['name' => 'Fixture Requirement', 'instructions' => 'Fixture instructions']];
$announcements = [['id' => 1, 'title' => 'Fixture Update', 'body' => 'Fixture announcement']];
$academicYear = ['label' => 'Fixture Intake', 'application_deadline' => null];
ob_start();
require dirname(__DIR__) . '/resources/views/public/home.php';
$homeMarkup = ob_get_clean();
$assert(str_contains($homeMarkup, 'id="programs"') && str_contains($homeMarkup, 'id="process"') && str_contains($homeMarkup, 'id="requirements"') && str_contains($homeMarkup, 'id="privacy"'), 'public homepage includes all established section anchors');
$assert(str_contains($homeMarkup, 'href="/register"') && str_contains($homeMarkup, 'href="/login"'), 'public homepage preserves apply and login destinations');
$assert(str_contains($homeMarkup, 'Fixture Program') && str_contains($homeMarkup, 'Fixture Requirement') && str_contains($homeMarkup, 'Fixture Update') && str_contains($homeMarkup, 'Fixture Intake'), 'public homepage renders supplied database content without fixed catalog values');

$renderStaffDashboard = static function (array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/resources/views/staff/dashboard.php';
    return (string) ob_get_clean();
};
$dashboardData = [
    'statusLabels' => ['submitted' => 'Submitted'],
    'filter' => static fn (string $key): string => '',
    'user' => ['id' => 5, 'full_name' => 'Fixture Admin', 'role' => 'admin'],
    'adminDashboard' => true,
    'metrics' => ['total'=>0,'awaiting'=>0,'accepted'=>0,'enrolled'=>0,'incomplete'=>0,'acceptance_rate'=>0,'average_review_hours'=>null,'upcoming_interviews'=>0,'enrollment_in_progress'=>0,'enrollment_complete'=>0],
    'applications' => ['rows'=>[],'filters'=>[],'page'=>1,'page_size'=>20],
    'charts' => ['monthly'=>[],'statuses'=>[],'programs'=>[],'submitted_reviewed'=>[],'intake_progress'=>['intake'=>null,'stages'=>[]],'review_time'=>[]],
    'announcements' => [['id' => 11, 'title' => 'Staff Fixture Notice', 'body' => 'A current operations update.', 'audience' => 'staff']],
    'programs' => [],
    'years' => [],
    'reviewers' => [],
];
$adminDashboardMarkup = $renderStaffDashboard($dashboardData);
$assert(str_contains($adminDashboardMarkup, 'class="is-active" href="/admin"') && str_contains($adminDashboardMarkup, 'href="/admin/applications"') && str_contains($adminDashboardMarkup, 'href="/staff/announcements"') && str_contains($adminDashboardMarkup, 'href="/staff/users/administrators"') && str_contains($adminDashboardMarkup, 'href="/staff/academic/programs"') && str_contains($adminDashboardMarkup, 'href="/staff/audit"') && str_contains($adminDashboardMarkup, 'Staff Fixture Notice'), 'admin dashboard retains its application and operations content');
$dashboardData['user'] = ['id'=>3,'full_name'=>'Fixture Reviewer','role'=>'reviewer'];
$dashboardData['adminDashboard'] = false;
$reviewerDashboardMarkup = $renderStaffDashboard($dashboardData);
$assert(str_contains($reviewerDashboardMarkup, 'class="is-active" href="/staff"') && str_contains($reviewerDashboardMarkup, 'href="/staff/applications"') && !str_contains($reviewerDashboardMarkup, 'href="/staff/users"') && !str_contains($reviewerDashboardMarkup, 'href="/admin/backups"') && !str_contains($reviewerDashboardMarkup, 'href="/staff/announcements"') && str_contains($reviewerDashboardMarkup, 'href="/announcements"') && str_contains($reviewerDashboardMarkup, 'Staff Fixture Notice'), 'reviewer dashboard retains queue and reader links without Admin-only management');
$assert(substr_count($adminDashboardMarkup, '<canvas data-chart=') === 6 && str_contains($adminDashboardMarkup, 'data-chart="monthly"') && str_contains($adminDashboardMarkup, 'data-chart="statuses"') && str_contains($adminDashboardMarkup, 'data-chart="programs"') && str_contains($adminDashboardMarkup, 'data-chart="submitted-reviewed"') && str_contains($adminDashboardMarkup, 'data-chart="intake-progress"') && str_contains($adminDashboardMarkup, 'data-chart="review-time"'), 'dashboard renders exactly the six required chart canvases');
$chartRendererSource = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$assert(is_string($chartRendererSource) && str_contains($chartRendererSource, "kind === 'submitted-reviewed'") && str_contains($chartRendererSource, "kind === 'intake-progress'") && str_contains($chartRendererSource, "kind === 'review-time'"), 'shared Chart.js renderer handles each newly added chart type');
$adminViewContracts = [
    'settings.php' => 'admin-settings-page',
    'users.php' => 'admin-users-page',
    'audit.php' => 'admin-audit-page',
];
$adminViewsStayOnDashboard = true;
foreach ($adminViewContracts as $viewFile => $adminClass) {
    $adminViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/' . $viewFile);
    $adminViewsStayOnDashboard = $adminViewsStayOnDashboard && is_string($adminViewSource)
        && str_contains($adminViewSource, $adminClass)
        && str_contains($adminViewSource, 'href="/admin" class="btn btn-outline-navy">Back to dashboard');
}
$assert($adminViewsStayOnDashboard, 'admin settings, users, and audit pages stay on /admin and use scoped presentation hooks');

$user = ['id' => $userId, 'full_name' => 'Fixture Student'];
$application = ['id' => 40, 'status' => 'documents_incomplete', 'reference_number' => 'AF-TEST-40', 'program_name' => 'Fixture Program', 'academic_year_label' => 'Fixture Intake', 'submitted_at' => '2026-10-01 12:00:00'];
$documents = [['requirement_id' => 8, 'document_id' => 15, 'name' => 'Fixture Requirement', 'review_status' => 'action_required', 'uploaded_at' => '2026-10-01 12:00:00', 'feedback' => 'Please upload a clearer copy.']];
$interviews = [['status' => 'scheduled', 'starts_at_utc' => '2026-10-30 02:00:00', 'ends_at_utc' => '2026-10-30 03:00:00', 'interviewer_name' => 'Reviewer Fixture', 'instructions' => 'Join at the scheduled time.']];
$announcements = [['id' => 1, 'title' => 'Fixture Notice', 'body' => 'Fixture student update']];
$notificationFeed = ['items' => []];
ob_start();
require dirname(__DIR__) . '/resources/views/student/portal.php';
$studentPortalMarkup = ob_get_clean();
$assert(str_contains($studentPortalMarkup, 'role="progressbar"') && str_contains($studentPortalMarkup, 'Documents need attention'), 'student dashboard renders accessible application progress and current status');
$assert(str_contains($studentPortalMarkup, 'aria-label="Latest student announcement"') && str_contains($studentPortalMarkup, 'Read full announcement') && str_contains($studentPortalMarkup, 'href="/announcements"'), 'student dashboard places an active announcement banner near the top with detail and all-announcement links');
$assert(str_contains($studentPortalMarkup, 'action="/document/replace"') && str_contains($studentPortalMarkup, 'name="_csrf"') && str_contains($studentPortalMarkup, 'href="/document/15"'), 'student dashboard preserves CSRF-protected replacement and owner-checked download actions');
$assert(str_contains($studentPortalMarkup, 'data-quick-view') && str_contains($studentPortalMarkup, 'data-preview-url="/document/15/preview"') && str_contains($studentPortalMarkup, 'data-document-preview'), 'student portal renders Quick View controls and preview dialog alongside download');
$assert(str_contains($studentPortalMarkup, 'Your interviews') && str_contains($studentPortalMarkup, 'Reviewer Fixture') && str_contains($studentPortalMarkup, 'Fixture Notice'), 'student dashboard displays supplied interview and update data');
$application['status'] = 'enrolled';
$notificationFeed = ['items' => [['id' => null, 'key' => 'student:interview:9', 'derived' => true, 'read_at' => null, 'title' => 'Upcoming interview', 'message' => 'Your interview is scheduled within the next 7 days.', 'href' => '/portal#student-interviews-title']]];
ob_start();
require dirname(__DIR__) . '/resources/views/student/portal.php';
$studentPortalWithUpdateMarkup = ob_get_clean();
$assert(str_contains($studentPortalWithUpdateMarkup, '<h2 id="next-step-title">Upcoming interview</h2>') && str_contains($studentPortalWithUpdateMarkup, 'View update') && str_contains($studentPortalWithUpdateMarkup, 'data-derived-key="student:interview:9"'), 'enrolled student portal shows actionable current interview update instead of generic next-step copy');
$applicationView = file_get_contents(dirname(__DIR__) . '/resources/views/student/application.php');
$notificationsView = file_get_contents(dirname(__DIR__) . '/resources/views/student/notifications.php');
$assert(is_string($applicationView) && str_contains($applicationView, 'action="/application/save"') && str_contains($applicationView, 'id="application-form"') && str_contains($applicationView, 'student-application'), 'student application wizard retains save route and has scoped design hook');
$assert(is_string($applicationView) && str_contains($applicationView, 'Your saved profile information has been filled in automatically where available') && str_contains($applicationView, 'href="/settings/profile"') && str_contains($applicationView, '$applicationInitialValues'), 'application wizard explains profile autofill and links to Account Settings');
$assert(str_contains($profileViewSource, 'href="/application">Start your application') && str_contains($profileViewSource, 'showApplicationCompletionPrompt'), 'completed profile prompt offers a direct application link while incomplete profile shows missing fields');
$profileSnapshotMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261026_application_profile_snapshot.sql');
$assert(is_string($profileSnapshotMigration) && str_contains($profileSnapshotMigration, 'ADD COLUMN applicant_profile_snapshot JSON NULL') && str_contains($profileSnapshotMigration, "WHERE a.status = 'draft'") && str_contains($profileSnapshotMigration, 'a.deleted_at IS NULL'), 'snapshot migration is additive and backfills only active drafts');
$migrationDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$migrationDb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT)');
$migrationDb->exec('CREATE TABLE student_profiles (user_id INTEGER PRIMARY KEY, mobile_number TEXT, date_of_birth TEXT, address_line TEXT, municipality TEXT, province TEXT, emergency_contact_name TEXT, emergency_contact_number TEXT)');
$migrationDb->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, student_id INTEGER, status TEXT, deleted_at TEXT)');
$migrationDb->exec("INSERT INTO users VALUES (1,'Migrated Draft','student'),(2,'Submitted Record','student')");
$migrationDb->exec("INSERT INTO student_profiles VALUES (1,'09171234567','2005-05-10','4 Snapshot Road','Test Town','Test Province','Contact Person','09171234568'),(2,'09179999999','2004-04-04','9 Submitted Road','Other Town','Other Province','Other Contact','09179999998')");
$migrationDb->exec("INSERT INTO applications VALUES (10,1,'draft',NULL),(11,2,'submitted',NULL),(12,1,'draft','2025-01-01')");
$migrationDb->exec('ALTER TABLE applications ADD COLUMN applicant_profile_snapshot TEXT NULL');
$migrationDb->exec("UPDATE applications AS a SET applicant_profile_snapshot = (SELECT json_object('full_name', u.full_name, 'mobile_number', sp.mobile_number, 'date_of_birth', sp.date_of_birth, 'address_line', sp.address_line, 'municipality', sp.municipality, 'province', sp.province, 'emergency_contact_name', sp.emergency_contact_name, 'emergency_contact_number', sp.emergency_contact_number) FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.id = a.student_id AND u.role = 'student') WHERE a.status = 'draft' AND a.deleted_at IS NULL AND a.applicant_profile_snapshot IS NULL");
$assert(is_string($profileSnapshotMigration) && $migrationDb->query('SELECT applicant_profile_snapshot FROM applications WHERE id=10')->fetchColumn() !== null && $migrationDb->query('SELECT applicant_profile_snapshot FROM applications WHERE id=11')->fetchColumn() === null && $migrationDb->query('SELECT applicant_profile_snapshot FROM applications WHERE id=12')->fetchColumn() === null, 'approved snapshot backfill scope changes only active drafts, not submitted or deleted applications');
$assert(is_string($notificationsView) && str_contains($notificationsView, '/notifications/read') && str_contains($notificationsView, '/notifications/dismiss-derived') && str_contains($notificationsView, 'student-notifications'), 'notifications page retains persistent-read and derived-dismiss routes with scoped design hook');

$unsafeProductionDatabaseConfig = false;
try {
    Database::validateCredentials(['username' => 'root', 'password' => ''], 'production');
} catch (RuntimeException) {
    $unsafeProductionDatabaseConfig = true;
}
$assert($unsafeProductionDatabaseConfig, 'root with blank password is rejected outside local mode');
$localDatabaseConfigAccepted = true;
try {
    Database::validateCredentials(['username' => 'root', 'password' => ''], 'local');
} catch (RuntimeException) {
    $localDatabaseConfigAccepted = false;
}
$assert($localDatabaseConfigAccepted, 'local XAMPP credentials remain supported in explicit local mode');
$assert(StatusTransitionPolicy::allows('submitted', 'in_screening', 'reviewer'), 'submitted application can enter screening');
$assert(!StatusTransitionPolicy::allows('submitted', 'accepted', 'reviewer'), 'invalid direct submitted-to-accepted transition is rejected');
$assert(!StatusTransitionPolicy::allows('accepted', 'enrolled', 'reviewer'), 'reviewer cannot mark accepted application enrolled');
$assert(StatusTransitionPolicy::allows('accepted', 'enrolled', 'admin'), 'admin may enroll only from accepted');
$assert(ApplicationService::canPrintAcceptance(['status' => 'accepted']) && ApplicationService::canPrintAcceptance(['status' => 'enrolled']) && !ApplicationService::canPrintAcceptance(['status' => 'in_screening']) && !ApplicationService::canPrintAcceptance(['status' => 'interview_scheduled']), 'acceptance letter gate permits only accepted or enrolled application states');
$assert(!ApplicationService::canPrintEnrollment(['status' => 'accepted', 'enrollment' => ['status' => 'complete', 'completed_at' => '2026-10-22 10:00:00']]) && !ApplicationService::canPrintEnrollment(['status' => 'enrolled', 'enrollment' => ['status' => 'complete', 'completed_at' => null]]) && ApplicationService::canPrintEnrollment(['status' => 'enrolled', 'enrollment' => ['status' => 'complete', 'completed_at' => '2026-10-22 10:00:00']]) && !ApplicationService::canPrintEnrollment(['status' => 'enrolled', 'enrollment' => ['status' => 'in_progress', 'completed_at' => null]]), 'enrollment confirmation requires enrolled status and a completed persisted enrollment record');
$transitionMatrix = [
    'submitted' => ['in_screening', 'documents_incomplete'],
    'in_screening' => ['documents_incomplete', 'interview_scheduled', 'accepted', 'declined'],
    'documents_incomplete' => ['in_screening', 'interview_scheduled'],
    'interview_scheduled' => ['in_screening', 'accepted', 'declined'],
    'accepted' => ['enrolled'],
    'declined' => [],
    'enrolled' => [],
];
$workflowStatuses = ['draft', 'submitted', 'in_screening', 'documents_incomplete', 'interview_scheduled', 'accepted', 'declined', 'enrolled'];
foreach ($workflowStatuses as $fromStatus) {
    foreach ($workflowStatuses as $toStatus) {
        foreach (['reviewer', 'admin'] as $role) {
            $expected = in_array($toStatus, $transitionMatrix[$fromStatus] ?? [], true) && ($toStatus !== 'enrolled' || $role === 'admin');
            $assert(StatusTransitionPolicy::allows($fromStatus, $toStatus, $role) === $expected, "transition matrix: {$role} {$fromStatus} -> {$toStatus}");
        }
    }
}
foreach (array_merge($workflowStatuses, ['unknown']) as $status) {
    $assert(StatusTransitionPolicy::isKnownStatus($status) === ($status !== 'draft' && $status !== 'unknown'), "known transition target: {$status}");
}
$assert(StatusTransitionPolicy::targets('accepted', 'reviewer') === [], 'reviewer transition selector hides administrator-only enrollment');
$assert(StatusTransitionPolicy::targets('accepted', 'admin') === ['enrolled'], 'administrator transition selector includes enrollment');
$assert(StatusTransitionPolicy::targets('submitted', 'reviewer') === ['in_screening', 'documents_incomplete'], 'staff transition selector is sourced from shared policy');
$assert(UploadPolicy::accepts('pdf', 'application/pdf', 100, ['pdf', 'jpg'], 1000), 'allowed PDF within limit accepted');
$assert(!UploadPolicy::accepts('php', 'application/x-php', 100, ['pdf', 'jpg'], 1000), 'executable extension rejected');
$assert(!UploadPolicy::accepts('jpg', 'text/plain', 100, ['jpg'], 1000), 'MIME mismatch rejected');
$assert(!UploadPolicy::accepts('pdf', 'application/pdf', 1001, ['pdf'], 1000), 'oversized upload rejected');
$avatarFixture = tempnam(sys_get_temp_dir(), 'admitflow-avatar-');
file_put_contents($avatarFixture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pJcAAAAASUVORK5CYII=', true));
$validAvatar = AvatarService::inspectUpload($avatarFixture, 'profile.png');
$assert(is_array($validAvatar) && $validAvatar['mime_type'] === 'image/png' && $validAvatar['extension'] === 'png', 'avatar validates actual PNG content and matching extension');
$assert(AvatarService::inspectUpload($avatarFixture, 'profile.jpg') === null && AvatarService::inspectUpload($avatarFixture, 'profile.svg') === null, 'avatar rejects mismatched and unsupported extensions');
file_put_contents($avatarFixture, str_repeat('x', AvatarService::MAX_BYTES + 1));
$assert(AvatarService::inspectUpload($avatarFixture, 'large.png') === null, 'avatar rejects files above the configured size limit');
try {
    AvatarService::store($userId, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $avatarFixture, 'name' => 'fake.png']);
    $avatarRejectsNonUpload = false;
} catch (DomainException) {
    $avatarRejectsNonUpload = true;
}
$assert($avatarRejectsNonUpload, 'avatar store rejects a local file that was not received as an HTTP upload');
unlink($avatarFixture);
$assert(PasswordResetDelivery::isValidApplicationUrl('https://admitflow.example.test', false), 'HTTPS reset URL is accepted for non-local delivery');
$assert(!PasswordResetDelivery::isValidApplicationUrl('http://admitflow.example.test', false), 'HTTP reset URL is rejected for non-local delivery');
$assert(PasswordResetDelivery::isValidApplicationUrl('http://127.0.0.1:8000', true), 'HTTP localhost reset URL is accepted only when local mode allows it');
$assert(!PasswordResetDelivery::isValidApplicationUrl('http://admitflow.example.test', true), 'local HTTP reset URL is limited to loopback hosts');
$assert(!PasswordResetDelivery::isValidApplicationUrl('https://user:pass@example.test', false), 'reset URL with embedded credentials is rejected');
$configuredResetToken = str_repeat('a', 64);
$assert(PasswordResetDelivery::buildResetUrl('https://admissions.example.test/', $configuredResetToken, false) === 'https://admissions.example.test/reset-password?token=' . $configuredResetToken, 'reset link is constructed from the configured HTTPS base URL');
$assert(PasswordResetDelivery::buildResetUrl('https://admissions.example.test', 'not-a-token', false) === null, 'reset link builder rejects malformed token values');
$assert(PasswordResetDelivery::shouldUseLocalFallback(true, false, true), 'explicit local development fallback is enabled when opted in');
$assert(!PasswordResetDelivery::shouldUseLocalFallback(true, false, false), 'local fallback remains disabled by default');
$assert(!PasswordResetDelivery::shouldUseLocalFallback(false, false, true), 'production cannot use local-file fallback');
$assert(!PasswordResetDelivery::shouldUseLocalFallback(true, true, true), 'local fallback is never used when SMTP is enabled');
$mailConfigPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-mail-config-' . bin2hex(random_bytes(16)) . '.php';
$mailStatusWithoutPassword = MailService::configurationStatus([
    'ADMITFLOW_MAIL_ENABLED' => '1',
    'ADMITFLOW_MAIL_PASSWORD' => '',
], $mailConfigPath);
$assert(!$mailStatusWithoutPassword['configured'] && str_contains($mailStatusWithoutPassword['message'], 'ADMITFLOW_MAIL_PASSWORD'), 'SMTP delivery is not configured without a password');

$profileGateDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$profileGateDb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL, account_status TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$profileGateDb->exec('CREATE TABLE student_profiles (user_id INTEGER PRIMARY KEY, mobile_number TEXT, date_of_birth TEXT, address_line TEXT, municipality TEXT, province TEXT, emergency_contact_name TEXT, emergency_contact_number TEXT, privacy_consent_at TEXT)');
$profileGateDb->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor_label TEXT, action TEXT, entity_type TEXT, entity_id INTEGER, outcome TEXT, severity TEXT, ip_address TEXT, details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$profileGateDb->exec("INSERT INTO users (id,full_name,email,role,account_status) VALUES (1,'Gate Applicant','gate@example.test','student','active'),(2,'Other Applicant','other@example.test','student','active')");
$profileGateDb->exec('INSERT INTO student_profiles (user_id) VALUES (1),(2)');
$gateBlocked = AccountSettingsService::applicationStartGate($profileGateDb, 1);
$assert(!$gateBlocked['allowed'] && isset($gateBlocked['missing']['mobile_number'], $gateBlocked['missing']['address_line']), 'server profile gate blocks incomplete applicant and returns missing labels');
$gateValidProfile = ['full_name' => 'Gate Applicant', 'mobile_number' => '09171234567', 'date_of_birth' => '2005-05-10', 'address_line' => '11 Main Road, Barangay Uno', 'municipality' => 'Test City', 'province' => 'Test Province', 'emergency_contact_name' => 'Emergency Person', 'emergency_contact_number' => '09171234568'];
$gateSave = AccountSettingsService::updateApplicantProfile($profileGateDb, ['id' => 1, 'role' => 'student', 'full_name' => 'Gate Applicant'], $gateValidProfile);
$gateProfileAuditCount = (int) $profileGateDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='account.applicant_profile_updated' AND entity_id=1")->fetchColumn();
$assert($gateSave['ok'] && AccountSettingsService::applicationStartGate($profileGateDb, 1)['allowed'] && $gateProfileAuditCount === 1, 'applicant can complete own profile, pass server gate, and create one audit event');
$otherBefore = (string) $profileGateDb->query('SELECT full_name FROM users WHERE id=2')->fetchColumn();
$foreignProfileSave = AccountSettingsService::updateApplicantProfile($profileGateDb, ['id' => 1, 'role' => 'student', 'full_name' => 'Gate Applicant'], array_merge($gateValidProfile, ['full_name' => 'Unauthorized Change']));
$assert($foreignProfileSave['ok'] && (string) $profileGateDb->query('SELECT full_name FROM users WHERE id=2')->fetchColumn() === $otherBefore, 'authenticated profile save updates only its supplied actor id and does not alter another applicant');
$invalidGateSave = AccountSettingsService::updateApplicantProfile($profileGateDb, ['id' => 1, 'role' => 'student', 'full_name' => 'Gate Applicant'], array_merge($gateValidProfile, ['mobile_number' => 'bad-number']));
$assert(!$invalidGateSave['ok'] && (string) $profileGateDb->query('SELECT mobile_number FROM student_profiles WHERE user_id=1')->fetchColumn() === '09171234567', 'invalid account profile values preserve the previously saved fields');

$workflowDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$workflowDb->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
$workflowDb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL, account_status TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE student_profiles (user_id INTEGER PRIMARY KEY, mobile_number TEXT, date_of_birth TEXT, address_line TEXT, municipality TEXT, province TEXT, emergency_contact_name TEXT, emergency_contact_number TEXT, privacy_consent_at TEXT)');
$workflowDb->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, code TEXT, name TEXT, description TEXT NULL, is_active INTEGER NOT NULL, created_at TEXT, updated_at TEXT)');
$workflowDb->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT, starts_on TEXT, ends_on TEXT NULL, application_deadline TEXT NULL, is_open INTEGER NOT NULL, created_at TEXT, updated_at TEXT)');
$workflowDb->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY AUTOINCREMENT, reference_number TEXT UNIQUE, student_id INTEGER NOT NULL, academic_program_id INTEGER, academic_year_id INTEGER, preferred_campus TEXT, previous_school TEXT, senior_high_strand TEXT, applicant_profile_snapshot TEXT NULL, status TEXT NOT NULL DEFAULT \'draft\', submitted_at TEXT, deleted_at TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE admission_requirements (id INTEGER PRIMARY KEY, code TEXT, name TEXT, instructions TEXT, academic_program_id INTEGER, academic_year_id INTEGER, allowed_extensions TEXT, max_file_size_bytes INTEGER, is_active INTEGER, is_required INTEGER, sort_order INTEGER, created_at TEXT, updated_at TEXT)');
$workflowDb->exec('CREATE TABLE application_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, requirement_id INTEGER, version INTEGER, storage_name TEXT, original_name TEXT, detected_mime_type TEXT, file_size_bytes INTEGER, sha256 TEXT, review_status TEXT DEFAULT \'pending\', uploaded_by INTEGER, uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP, is_current INTEGER DEFAULT 1)');
$workflowDb->exec('CREATE TABLE document_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, document_id INTEGER, reviewer_id INTEGER, decision TEXT, feedback TEXT, reviewed_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, reviewer_id INTEGER, assigned_by INTEGER, unassigned_at TEXT)');
$workflowDb->exec('CREATE TABLE screening_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, author_id INTEGER, note TEXT, visibility TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE interviews (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, interviewer_id INTEGER, starts_at_utc TEXT, ends_at_utc TEXT, instructions TEXT, status TEXT DEFAULT \'scheduled\', notes TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE enrollment_records (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER UNIQUE, status TEXT, checklist TEXT, prepared_by INTEGER, completed_at TEXT)');
$workflowDb->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, title TEXT, message TEXT, related_type TEXT, related_id INTEGER, read_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL, audience TEXT NOT NULL, starts_at TEXT NULL, ends_at TEXT NULL, is_published INTEGER NOT NULL DEFAULT 0, is_sample INTEGER NOT NULL DEFAULT 0, created_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER, from_status TEXT, to_status TEXT, changed_by INTEGER, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor_label TEXT, action TEXT, entity_type TEXT, entity_id INTEGER, outcome TEXT, severity TEXT, ip_address TEXT, details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$workflowDb->exec("INSERT INTO users (id,full_name,email,role,account_status) VALUES (1,'Draft Student','draft@example.test','student','active'),(2,'Review Student','review@example.test','student','active'),(3,'Reviewer One','reviewer-one@example.test','reviewer','active'),(4,'Reviewer Two','reviewer-two@example.test','reviewer','active'),(5,'Administrator','admin@example.test','admin','active')");
$workflowDb->exec("INSERT INTO student_profiles (user_id) VALUES (1),(2)");
$workflowDb->exec("UPDATE student_profiles SET mobile_number='09171234567', date_of_birth='2005-05-10', address_line='12 Sample Road, Barangay Uno', municipality='Sample Town', province='Sample Province', emergency_contact_name='Taylor Student', emergency_contact_number='09171234568' WHERE user_id=1");
$workflowDb->exec("INSERT INTO academic_programs (id,code,name,is_active) VALUES (1,'TEST','Test Program',1),(2,'CLOSED','Closed Program',0)");
$workflowDb->exec("INSERT INTO academic_years (id,label,starts_on,application_deadline,is_open) VALUES (1,'AY Test','2026-01-01',NULL,1),(2,'AY Closed','2025-01-01',NULL,0)");
$incompleteGate = AccountSettingsService::applicationStartGate($workflowDb, 2);
$assert(!$incompleteGate['allowed'] && isset($incompleteGate['missing']['mobile_number']) && isset($incompleteGate['missing']['address_line']), 'incomplete applicant profile is blocked by the shared server gate with field labels');
$gateProfileInput = ['full_name' => 'Review Student', 'mobile_number' => '09171234569', 'date_of_birth' => '2004-08-12', 'address_line' => '22 Applicant Road, Barangay Dos', 'municipality' => 'Sample City', 'province' => 'Sample Province', 'emergency_contact_name' => 'Alex Contact', 'emergency_contact_number' => '09171234570'];
$gateProfileSave = AccountSettingsService::updateApplicantProfile($workflowDb, ['id' => 2, 'role' => 'student', 'full_name' => 'Review Student'], $gateProfileInput);
$assert($gateProfileSave['ok'] && AccountSettingsService::applicationStartGate($workflowDb, 2)['allowed'], 'applicant can complete profile fields in account settings and pass the server gate');
$unauthorizedProfileSave = AccountSettingsService::updateApplicantProfile($workflowDb, ['id' => 2, 'role' => 'reviewer', 'full_name' => 'Reviewer Two'], $gateProfileInput);
$assert(!$unauthorizedProfileSave['ok'] && $workflowDb->query('SELECT full_name FROM users WHERE id=2')->fetchColumn() === 'Review Student', 'non-applicant role cannot update applicant profile fields');
$draftInput = ['full_name' => 'Casey Student'];
$draftResult = ApplicationService::save($workflowDb, 1, $draftInput, [], false);
$assert($draftResult['ok'], 'valid minimal draft saves');
$draftApplicationId = (int) $workflowDb->query('SELECT id FROM applications WHERE student_id=1 AND status=\'draft\' ORDER BY id DESC LIMIT 1')->fetchColumn();
$assert($workflowDb->query('SELECT status FROM applications WHERE id=' . $draftApplicationId)->fetchColumn() === 'draft', 'saved application remains a draft');
$boundaryInput = array_merge($draftInput, ['full_name' => str_repeat('A', 160), 'address_line' => str_repeat('B', 255), 'academic_program_id' => '1', 'academic_year_id' => '1']);
$assert(ApplicationService::save($workflowDb, 1, $boundaryInput, [], false)['ok'], 'maximum name and address lengths are accepted');
$savedName = $workflowDb->query('SELECT full_name FROM users WHERE id=1')->fetchColumn();
$tooLongName = array_merge($boundaryInput, ['full_name' => str_repeat('A', 161)]);
$assert(!ApplicationService::save($workflowDb, 1, $tooLongName, [], false)['ok'], 'name over the field boundary is rejected');
$assert($workflowDb->query('SELECT full_name FROM users WHERE id=1')->fetchColumn() === $savedName, 'invalid draft validation preserves previously saved data');
$assert(!ApplicationService::save($workflowDb, 1, array_merge($boundaryInput, ['address_line' => str_repeat('B', 256)]), [], false)['ok'], 'address over the field boundary is rejected');
$assert(!ApplicationService::save($workflowDb, 1, array_merge($boundaryInput, ['mobile_number' => 'call-me-maybe']), [], false)['ok'], 'invalid contact number format is rejected');
$assert(!ApplicationService::save($workflowDb, 1, array_merge($boundaryInput, ['academic_program_id' => '1x']), [], false)['ok'], 'malformed program identifier is rejected');
$assert(!ApplicationService::save($workflowDb, 1, array_merge($boundaryInput, ['academic_program_id' => '2']), [], false)['ok'], 'inactive program identifier is rejected');
$assert(!ApplicationService::save($workflowDb, 1, array_merge($boundaryInput, ['academic_year_id' => '2']), [], false)['ok'], 'closed academic year identifier is rejected');

$validSubmission = [
    'full_name' => 'Casey Student',
    'mobile_number' => '+639171234567',
    'date_of_birth' => '2005-05-10',
    'address_line' => '12 Sample Road',
    'municipality' => 'Sample Town',
    'province' => 'Sample Province',
    'emergency_contact_name' => 'Taylor Student',
    'emergency_contact_number' => '09171234568',
    'academic_program_id' => '1',
    'academic_year_id' => '1',
    'preferred_campus' => 'Main',
    'previous_school' => 'Sample High School',
    'senior_high_strand' => 'STEM',
    'privacy_consent' => '1',
];
$completeApplicantProfile = array_intersect_key($validSubmission, array_flip(array_keys(AccountSettingsService::applicantProfileFields())));
$assert(AccountSettingsService::missingApplicantProfileFields($completeApplicantProfile) === [], 'complete applicant profile passes the server completion check without avatar or consent requirements');
$incompleteApplicantProfile = $completeApplicantProfile;
unset($incompleteApplicantProfile['address_line'], $incompleteApplicantProfile['mobile_number']);
$missingApplicantFields = AccountSettingsService::missingApplicantProfileFields($incompleteApplicantProfile);
$assert(($missingApplicantFields['address_line'] ?? '') === 'House number, street, and barangay' && ($missingApplicantFields['mobile_number'] ?? '') === 'Mobile number' && !isset($missingApplicantFields['privacy_consent']), 'completion gate reports only required existing applicant profile fields');
$profileSnapshot = ApplicationService::applicantProfileSnapshot($completeApplicantProfile);
$assert($profileSnapshot['full_name'] === 'Casey Student' && $profileSnapshot['mobile_number'] === '+639171234567' && $profileSnapshot['address_line'] === '12 Sample Road' && $profileSnapshot['municipality'] === 'Sample Town' && $profileSnapshot['province'] === 'Sample Province', 'applicant field mapping preserves full name, phone, street, municipality, and province without fabricating address parts');
$emptyProfileSnapshot = ApplicationService::applicantProfileSnapshot(['full_name' => '', 'mobile_number' => null]);
$assert($emptyProfileSnapshot['full_name'] === '' && $emptyProfileSnapshot['mobile_number'] === '' && !isset($emptyProfileSnapshot['barangay']), 'empty applicant profile values remain empty and no unsupported address components are created');
$newApplicationDefaults = ApplicationService::applicantFormValues($completeApplicantProfile, null);
$assert($newApplicationDefaults['full_name'] === 'Casey Student' && $newApplicationDefaults['mobile_number'] === '+639171234567' && $newApplicationDefaults['address_line'] === '12 Sample Road' && $newApplicationDefaults['academic_program_id'] === '', 'new application defaults map current profile data and leave unrelated academic fields blank');
$savedDraftValues = ApplicationService::applicantFormValues(['full_name' => 'New Name', 'mobile_number' => '09999999999'], ['status' => 'draft', 'applicant_profile_snapshot' => json_encode($profileSnapshot), 'academic_program_id' => 2, 'academic_year_id' => 4, 'preferred_campus' => 'Old Campus']);
$assert($savedDraftValues['full_name'] === 'Casey Student' && $savedDraftValues['mobile_number'] === '+639171234567' && $savedDraftValues['academic_program_id'] === '2' && $savedDraftValues['preferred_campus'] === 'Old Campus', 'existing draft snapshot and academic choices take precedence over newer profile values');
$validationOverrideValues = ApplicationService::applicantFormValues([], ['status' => 'draft', 'applicant_profile_snapshot' => json_encode($profileSnapshot)], ['full_name' => 'Applicant Edit']);
$assert($validationOverrideValues['full_name'] === 'Applicant Edit' && $validationOverrideValues['mobile_number'] === '+639171234567', 'validation-posted values override snapshots without changing untouched draft values');
$workflowDb->exec("INSERT INTO users (id,full_name,email,role,account_status) VALUES (6,'Wizard Student','wizard@example.test','student','active')");
$workflowDb->exec('INSERT INTO student_profiles (user_id) VALUES (6)');
$workflowDb->exec("UPDATE sqlite_sequence SET seq=100 WHERE name='applications'");
$assert(!ApplicationService::canProgressToStep(1, 0) && !ApplicationService::canProgressToStep(2, 0) && !ApplicationService::canSubmitAtStep(2), 'server wizard policy blocks forged future-step requests and premature submission');
$assert(ApplicationService::canProgressToStep(0, 0) && ApplicationService::canProgressToStep(2, 2) && ApplicationService::canSubmitAtStep(3) && ApplicationService::visibleWizardStep(3, 1) === 1, 'wizard policy permits reached steps and clamps forged review URLs');
$missingStepInput = $validSubmission;
unset($missingStepInput['mobile_number']);
$missingStepResult = ApplicationService::save($workflowDb, 6, $missingStepInput, [], false, 0);
$assert(!$missingStepResult['ok'] && $missingStepResult['step'] === 0 && isset($missingStepResult['errors']['mobile_number']), 'applicant step rejects a missing required mobile number with a field error');
$invalidStepInput = array_merge($validSubmission, ['mobile_number' => '12345']);
$invalidStepResult = ApplicationService::save($workflowDb, 6, $invalidStepInput, [], false, 0);
$assert(!$invalidStepResult['ok'] && isset($invalidStepResult['errors']['mobile_number']), 'applicant step rejects a malformed Philippine mobile number');
$stepZero = ApplicationService::save($workflowDb, 6, $validSubmission, [], false, 0);
$assert($stepZero['ok'] && $stepZero['step'] === 1, 'valid applicant data saves and advances to Academics');
$stepApplicationId = (int) $workflowDb->query('SELECT id FROM applications WHERE student_id=6')->fetchColumn();
$snapshotBeforeProfileEdit = ApplicationService::decodeApplicantProfileSnapshot($workflowDb->query('SELECT applicant_profile_snapshot FROM applications WHERE id=' . $stepApplicationId)->fetchColumn());
$assert($snapshotBeforeProfileEdit === $profileSnapshot, 'new draft stores the exact validated applicant profile values as its snapshot');
$workflowDb->exec("UPDATE student_profiles SET mobile_number='09179999999', address_line='New Profile Road' WHERE user_id=6");
$snapshotAfterProfileEdit = ApplicationService::decodeApplicantProfileSnapshot($workflowDb->query('SELECT applicant_profile_snapshot FROM applications WHERE id=' . $stepApplicationId)->fetchColumn());
$assert($snapshotAfterProfileEdit === $snapshotBeforeProfileEdit && $snapshotAfterProfileEdit['address_line'] === '12 Sample Road', 'changing the current profile does not mutate an existing draft snapshot');
$workflowDb->exec("UPDATE student_profiles SET mobile_number='09171234567', address_line='12 Sample Road' WHERE user_id=6");
$missingAcademic = $validSubmission;
unset($missingAcademic['previous_school']);
$stepOneFailure = ApplicationService::save($workflowDb, 6, $missingAcademic, [], false, 1);
$assert(!$stepOneFailure['ok'] && $stepOneFailure['step'] === 1 && isset($stepOneFailure['errors']['previous_school']), 'academics step rejects missing required academic fields');
$stepOne = ApplicationService::save($workflowDb, 6, $validSubmission, [], false, 1);
$assert($stepOne['ok'] && $stepOne['step'] === 2, 'valid academic details save and advance to Documents');
$workflowDb->exec("INSERT INTO admission_requirements (id,code,name,allowed_extensions,max_file_size_bytes,is_active,is_required,sort_order) VALUES (2,'WIZARD','Wizard required document','pdf,jpg,png',10485760,1,1,1)");
$missingDocumentStep = ApplicationService::save($workflowDb, 6, $validSubmission, [], false, 2);
$assert(!$missingDocumentStep['ok'] && $missingDocumentStep['step'] === 2 && str_contains($missingDocumentStep['message'], 'Wizard required document'), 'documents step rejects missing required documents by name');
$missingDocumentSubmit = ApplicationService::save($workflowDb, 6, $validSubmission, [], true);
$assert(!$missingDocumentSubmit['ok'] && $missingDocumentSubmit['step'] === 2 && str_contains($missingDocumentSubmit['message'], 'Wizard required document'), 'direct final submission is blocked server-side while a required document is missing');
$workflowDb->exec("INSERT INTO application_documents (id,application_id,requirement_id,version,storage_name,original_name,detected_mime_type,file_size_bytes,sha256,review_status,uploaded_by,is_current) VALUES (50,{$stepApplicationId},2,1,'wizard.pdf','wizard.pdf','application/pdf',100,'hash','pending',6,1)");
$stepTwo = ApplicationService::save($workflowDb, 6, $validSubmission, [], false, 2);
$assert($stepTwo['ok'] && $stepTwo['step'] === 3, 'uploaded pending document satisfies upload requirement without being presented as staff-verified');
$wizardSubmit = ApplicationService::save($workflowDb, 6, $validSubmission, [], true);
$assert($wizardSubmit['ok'] && $workflowDb->query('SELECT status FROM applications WHERE id=' . $stepApplicationId)->fetchColumn() === 'submitted', 'complete validated wizard submits successfully');
$wizardDuplicate = ApplicationService::save($workflowDb, 6, $validSubmission, [], true);
$assert(!$wizardDuplicate['ok'], 'wizard rejects duplicate final submission');
$workflowDb->exec('DELETE FROM application_status_history WHERE application_id=' . $stepApplicationId);
$workflowDb->exec('DELETE FROM notifications WHERE related_id=' . $stepApplicationId);
$workflowDb->exec('DELETE FROM audit_logs WHERE entity_id=' . $stepApplicationId);
$workflowDb->exec('DELETE FROM application_documents WHERE application_id=' . $stepApplicationId);
$workflowDb->exec('DELETE FROM applications WHERE id=' . $stepApplicationId);
$workflowDb->exec('DELETE FROM admission_requirements WHERE id=2');
$workflowDb->exec('DELETE FROM student_profiles WHERE user_id=6');
$workflowDb->exec('DELETE FROM users WHERE id=6');
$submission = ApplicationService::save($workflowDb, 1, $validSubmission, [], true);
$assert($submission['ok'], 'valid application can be submitted');
$submissionAuditIp = $workflowDb->query("SELECT ip_address FROM audit_logs WHERE action='application.submitted' ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert($submissionAuditIp === '192.0.2.10', 'application submission audit event stores the direct client IP');
$submissionCounts = $workflowDb->query('SELECT (SELECT COUNT(*) FROM applications WHERE student_id=1) AS applications, (SELECT COUNT(*) FROM application_status_history WHERE application_id=' . $draftApplicationId . ') AS history, (SELECT COUNT(*) FROM notifications WHERE user_id=1) AS notifications')->fetch();
$assert($submissionCounts['applications'] === 1 && $submissionCounts['history'] === 1 && $submissionCounts['notifications'] === 1, 'successful submission writes one application, history event, and notification');
$duplicateSubmission = ApplicationService::save($workflowDb, 1, $validSubmission, [], true);
$afterDuplicate = $workflowDb->query('SELECT (SELECT COUNT(*) FROM application_status_history WHERE application_id=' . $draftApplicationId . ') AS history, (SELECT COUNT(*) FROM notifications WHERE user_id=1) AS notifications')->fetch();
$assert(!$duplicateSubmission['ok'] && $afterDuplicate['history'] === 1 && $afterDuplicate['notifications'] === 1, 'repeated submission is rejected without duplicate events');
$invalidSubmit = ApplicationService::save($workflowDb, 2, ['full_name' => 'Incomplete'], [], true);
$assert(!$invalidSubmit['ok'] && (int) $workflowDb->query('SELECT COUNT(*) FROM applications WHERE student_id=2')->fetchColumn() === 0, 'invalid submission does not create an application');

$workflowDb->exec("INSERT INTO applications (id,student_id,academic_program_id,academic_year_id,status,submitted_at) VALUES (2,2,1,1,'submitted',UTC_TIMESTAMP()),(3,2,1,1,'submitted',UTC_TIMESTAMP())");
$exactStudentApplication = ApplicationService::current($workflowDb, 2, 3);
$unauthorizedStudentApplication = ApplicationService::current($workflowDb, 1, 3);
$assert((int) ($exactStudentApplication['id'] ?? 0) === 3 && $unauthorizedStudentApplication === null, 'optional exact-application lookup selects only the signed-in student owned record');
$workflowDb->exec("INSERT INTO admission_requirements (id,code,name,instructions,allowed_extensions,max_file_size_bytes,is_active,is_required,sort_order) VALUES (1,'ID','Identity document','Upload a scan','pdf,jpg,png',10485760,1,1,1)");
$workflowDb->exec("INSERT INTO application_documents (id,application_id,requirement_id,version,storage_name,original_name,detected_mime_type,file_size_bytes,sha256,review_status,uploaded_by,is_current) VALUES (1,2,1,1,'old.pdf','old.pdf','application/pdf',100,'hash','pending',2,1)");
$assert(is_array(ApplicationService::authorizedCurrentDocument($workflowDb, 2, 1)) && ApplicationService::authorizedCurrentDocument($workflowDb, 1, 1) === null, 'students can preview their own current document but cannot access another student document');
$workflowDb->exec('INSERT INTO reviewer_assignments (application_id,reviewer_id,assigned_by) VALUES (2,3,5),(3,4,5)');
$insertRoleNotification = $workflowDb->prepare('INSERT INTO notifications (user_id,type,title,message,related_type,related_id) VALUES (:user_id,:type,:title,:message,:related_type,:related_id)');
$insertRoleNotification->execute(['user_id' => 2, 'type' => 'application_status', 'title' => 'Application updated', 'message' => 'A status changed.', 'related_type' => 'application', 'related_id' => 2]);
$studentNotificationId = (int) $workflowDb->lastInsertId();
$insertRoleNotification->execute(['user_id' => 3, 'type' => 'review_assignment', 'title' => 'Work assigned', 'message' => 'Review is ready.', 'related_type' => 'application', 'related_id' => 2]);
$reviewerNotificationId = (int) $workflowDb->lastInsertId();
$insertRoleNotification->execute(['user_id' => 5, 'type' => 'system_notice', 'title' => 'System notice', 'message' => 'System notice recorded.', 'related_type' => 'system', 'related_id' => null]);
$adminNotificationId = (int) $workflowDb->lastInsertId();
$notificationRowsBeforeDerivedLoads = (int) $workflowDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$studentNotificationFeed = NotificationService::feed($workflowDb, ['id' => 2, 'role' => 'student']);
$reviewerNotificationFeed = NotificationService::feed($workflowDb, ['id' => 3, 'role' => 'reviewer']);
$adminNotificationFeed = NotificationService::feed($workflowDb, ['id' => 5, 'role' => 'admin']);
$studentNotification = array_values(array_filter($studentNotificationFeed['items'], static fn (array $item): bool => $item['id'] === $studentNotificationId))[0] ?? [];
$reviewerNotification = array_values(array_filter($reviewerNotificationFeed['items'], static fn (array $item): bool => $item['id'] === $reviewerNotificationId))[0] ?? [];
$adminNotification = array_values(array_filter($adminNotificationFeed['items'], static fn (array $item): bool => $item['id'] === $adminNotificationId))[0] ?? [];
$assert($studentNotificationFeed['persistent_unread_count'] === 1 && ($studentNotification['href'] ?? '') === '/portal?application_id=2#application-status-title', 'student application notification opens the exact owned active application');
$assert($reviewerNotificationFeed['persistent_unread_count'] === 1 && ($reviewerNotification['href'] ?? '') === '/staff/application/2' && !in_array('/staff/application/3', array_column($reviewerNotificationFeed['items'], 'href'), true), 'reviewer feed links assigned work and does not expose another reviewerâ€™s application');
$assert($adminNotificationFeed['persistent_unread_count'] === 1 && ($adminNotification['href'] ?? '') === '/admin', 'administrator feed routes an unrelated system notice to the admin portal');
$submittedStudentFeed = NotificationService::feed($workflowDb, ['id' => 1, 'role' => 'student']);
$submittedStudentNotice = array_values(array_filter($submittedStudentFeed['items'], static fn (array $item): bool => ($item['key'] ?? null) === null && str_contains((string) $item['title'], 'Application submitted')))[0] ?? [];
$assert(($submittedStudentNotice['href'] ?? '') === '/portal?application_id=' . $draftApplicationId . '#application-status-title', 'application-submitted notification retains its exact application destination');
$assert(!NotificationService::markRead($workflowDb, ['id' => 1, 'role' => 'student'], $studentNotificationId) && (int) $workflowDb->query('SELECT COUNT(*) FROM notifications WHERE id=' . $studentNotificationId . ' AND read_at IS NULL')->fetchColumn() === 1, 'user cannot mark another accountâ€™s notification as read');
$assert(NotificationService::markRead($workflowDb, ['id' => 2, 'role' => 'student'], $studentNotificationId) && (int) $workflowDb->query('SELECT COUNT(*) FROM notifications WHERE id=' . $studentNotificationId . ' AND read_at IS NULL')->fetchColumn() === 0, 'authenticated owner can mark one notification as read');
$assert(NotificationService::markAllRead($workflowDb, ['id' => 3, 'role' => 'reviewer']) === 1 && (int) $workflowDb->query('SELECT COUNT(*) FROM notifications WHERE id=' . $reviewerNotificationId . ' AND read_at IS NULL')->fetchColumn() === 0 && (int) $workflowDb->query('SELECT COUNT(*) FROM notifications WHERE id=' . $adminNotificationId . ' AND read_at IS NULL')->fetchColumn() === 1, 'mark all read is limited to the authenticated account');
$insertRoleNotification->execute(['user_id' => 2, 'type' => 'missing_documents_test', 'title' => 'Missing documents', 'message' => 'A document needs attention.', 'related_type' => 'application_document', 'related_id' => 1]);
$documentNotificationFeed = NotificationService::feed($workflowDb, ['id' => 2, 'role' => 'student']);
$documentNotification = array_values(array_filter($documentNotificationFeed['items'], static fn (array $item): bool => ($item['title'] ?? '') === 'Missing documents'))[0] ?? [];
$assert(($documentNotification['href'] ?? '') === '/portal?application_id=2#required-documents-title', 'missing-document notification opens the related owned application and its document section');
$insertRoleNotification->execute(['user_id' => 2, 'type' => 'account_update', 'title' => 'Account updated', 'message' => 'Review your profile.', 'related_type' => 'profile', 'related_id' => 2]);
$accountNotificationFeed = NotificationService::feed($workflowDb, ['id' => 2, 'role' => 'student']);
$accountNotification = array_values(array_filter($accountNotificationFeed['items'], static fn (array $item): bool => ($item['title'] ?? '') === 'Account updated'))[0] ?? [];
$assert(($accountNotification['href'] ?? '') === '/settings/profile', 'account/profile notification opens the profile settings page');
$insertRoleNotification->execute(['user_id' => 2, 'type' => 'application_status', 'title' => 'Invalid application reference', 'message' => 'Invalid target.', 'related_type' => 'application', 'related_id' => 99999]);
$invalidDestinationFeed = NotificationService::feed($workflowDb, ['id' => 2, 'role' => 'student']);
$invalidDestination = array_values(array_filter($invalidDestinationFeed['items'], static fn (array $item): bool => ($item['title'] ?? '') === 'Invalid application reference'))[0] ?? [];
$assert(($invalidDestination['href'] ?? '') === '/portal', 'invalid or unauthorized application references safely fall back to the signed-in student portal');
$notificationFixtureInsert = $workflowDb->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id, created_at) VALUES (2, \'application_status\', :title, \'Fixture message\', \'application\', 2, :created_at)');
for ($notificationIndex = 0; $notificationIndex < 21; $notificationIndex++) {
    $notificationFixtureInsert->execute(['title' => 'Page fixture ' . $notificationIndex, 'created_at' => sprintf('2026-10-%02d 10:00:00', 1 + ($notificationIndex % 20))]);
}
$studentFeedPageOne = NotificationService::feedPage($workflowDb, ['id' => 2, 'role' => 'student'], [], ['per_page' => 10]);
$studentFeedPageTwo = NotificationService::feedPage($workflowDb, ['id' => 2, 'role' => 'student'], [], ['per_page' => 10, 'page' => 2]);
$filteredStudentFeed = NotificationService::feedPage($workflowDb, ['id' => 2, 'role' => 'student'], [], ['q' => 'Application updated', 'status' => 'read', 'per_page' => 10]);
$crossAccountNotificationSearch = NotificationService::feedPage($workflowDb, ['id' => 2, 'role' => 'student'], [], ['q' => 'Work assigned']);
$invalidNotificationReadFilter = false;
try {
    NotificationService::feedPage($workflowDb, ['id' => 2, 'role' => 'student'], [], ['status' => 'archived']);
} catch (DomainException) {
    $invalidNotificationReadFilter = true;
}
$studentPageOneIds = array_column(array_values(array_filter($studentFeedPageOne['items'], static fn (array $item): bool => empty($item['derived']))), 'id');
$studentPageTwoIds = array_column(array_values(array_filter($studentFeedPageTwo['items'], static fn (array $item): bool => empty($item['derived']))), 'id');
$allStudentPageIds = array_merge($studentPageOneIds, $studentPageTwoIds);
$assert($studentFeedPageOne['pagination']['total'] > 20 && $studentFeedPageTwo['pagination']['page'] === 2 && $studentPageOneIds !== [] && $studentPageTwoIds !== [] && array_intersect($studentPageOneIds, $studentPageTwoIds) === [], 'notification pages advance without repeating persistent records and include derived current alerts in totals');
$assert($filteredStudentFeed['pagination']['total'] === 1 && count($filteredStudentFeed['items']) === 1 && $filteredStudentFeed['items'][0]['title'] === 'Application updated' && $filteredStudentFeed['pagination']['query']['status'] === 'read' && $crossAccountNotificationSearch['pagination']['total'] === 0 && $invalidNotificationReadFilter, 'notification search and read-state filters remain account-scoped and parameterized with valid status handling');
$ownerNotificationCheck = $workflowDb->prepare('SELECT COUNT(*) FROM notifications WHERE id = :id AND user_id = 2');
$notificationOwnerScoped = true;
foreach ($allStudentPageIds as $notificationId) {
    $ownerNotificationCheck->execute(['id' => $notificationId]);
    $notificationOwnerScoped = $notificationOwnerScoped && (int) $ownerNotificationCheck->fetchColumn() === 1;
}
$assert($notificationOwnerScoped, 'student notification pagination returns only rows owned by the authenticated user');
$reviewerOne = ['id' => 3, 'full_name' => 'Reviewer One', 'role' => 'reviewer'];
$reviewerTwo = ['id' => 4, 'full_name' => 'Reviewer Two', 'role' => 'reviewer'];
$adminUser = ['id' => 5, 'full_name' => 'Administrator', 'role' => 'admin'];
$filteredAcademicSettings = StaffService::academicSettings($workflowDb, $adminUser, ['program_q' => 'TEST', 'program_state' => 'active', 'year_q' => 'AY Test', 'year_state' => 'open', 'requirement_q' => 'Identity', 'requirement_state' => 'active', 'required' => 'required', 'requirement_program' => '1', 'requirement_year' => '1']);
$reviewerAcademicSettingsDenied = false;
try {
    StaffService::academicSettings($workflowDb, $reviewerOne, []);
} catch (DomainException) {
    $reviewerAcademicSettingsDenied = true;
}
$assert(array_column($filteredAcademicSettings['programs'], 'id') === [1] && array_column($filteredAcademicSettings['years'], 'id') === [1] && array_column($filteredAcademicSettings['requirements'], 'id') === [1] && count($filteredAcademicSettings['programOptions']) >= count($filteredAcademicSettings['programs']) && $reviewerAcademicSettingsDenied, 'academic settings filters server-side across actual fields, retain full association options, and enforce administrator access');
$notificationRowsBeforeDerivedLoads = (int) $workflowDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$announcementInsert = $workflowDb->prepare('INSERT INTO announcements (id, title, body, audience, starts_at, ends_at, is_published, created_by) VALUES (:id, :title, :body, :audience, :starts_at, :ends_at, :published, :created_by)');
$noticeNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$announcementRows = [
    [1, 'Public notice', 'Public message', 'public', null, null, 1],
    [2, 'Student notice', '<script>alert(1)</script>\nStudent message', 'students', null, null, 1],
    [3, 'Staff notice', 'Staff message', 'staff', null, null, 1],
    [4, 'Shared notice', 'Shared message', 'all', null, null, 1],
    [5, 'Draft notice', 'Draft message', 'students', null, null, 0],
    [6, 'Scheduled notice', 'Future message', 'students', $noticeNow->modify('+2 days')->format('Y-m-d H:i:s'), $noticeNow->modify('+3 days')->format('Y-m-d H:i:s'), 1],
    [7, 'Expired notice', 'Past message', 'students', null, $noticeNow->modify('-2 days')->format('Y-m-d H:i:s'), 1],
];
foreach ($announcementRows as [$id, $title, $body, $audience, $startsAt, $endsAt, $published]) {
    $announcementInsert->execute(['id' => $id, 'title' => $title, 'body' => $body, 'audience' => $audience, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'published' => $published, 'created_by' => 5]);
}
$publicNotices = AnnouncementService::activeForUser($workflowDb, null);
$studentNotices = AnnouncementService::activeForUser($workflowDb, ['id' => 1, 'role' => 'student']);
$staffNotices = AnnouncementService::activeForUser($workflowDb, $reviewerOne);
$adminNotices = AnnouncementService::activeForUser($workflowDb, $adminUser);
$assert(array_column($publicNotices, 'id') === [4, 1] && array_column($studentNotices, 'id') === [4, 2] && array_column($staffNotices, 'id') === [4, 3] && count($adminNotices) === 4, 'active announcements are filtered to public, student, staff, and administrator audiences without draft, scheduled, or expired leakage');
$searchedStudentReader = AnnouncementService::activePage($workflowDb, ['id' => 1, 'role' => 'student'], ['q' => 'Student notice', 'per_page' => 10]);
$hiddenAudienceReaderSearch = AnnouncementService::activePage($workflowDb, ['id' => 1, 'role' => 'student'], ['q' => 'Staff notice']);
$assert(array_column($searchedStudentReader['rows'], 'id') === [2] && $searchedStudentReader['pagination']['query'] === ['q' => 'Student notice'] && $hiddenAudienceReaderSearch['pagination']['total'] === 0, 'announcement reader search preserves existing audience authorization and filtered pagination');
$announcementReaderSource = file_get_contents(dirname(__DIR__) . '/resources/views/announcements/index.php');
$assert(is_string($announcementReaderSource) && str_contains($announcementReaderSource, 'data-auto-filter-form') && str_contains($announcementReaderSource, 'data-filter-search') && str_contains($announcementReaderSource, 'activeFilterChips'), 'audience-scoped announcement reader renders the shared automatic search and chip pattern');
$announcementPage = AnnouncementService::managementList($workflowDb);
$announcementStates = array_column($announcementPage['rows'], 'state', 'id');
$publishedAnnouncementPage = AnnouncementService::managementList($workflowDb, 'published');
$scheduledAnnouncementPage = AnnouncementService::managementList($workflowDb, 'scheduled');
$studentAudienceAnnouncementPage = AnnouncementService::managementList($workflowDb, '', ['audience' => 'students']);
$assert(($announcementStates[5] ?? '') === 'draft' && ($announcementStates[6] ?? '') === 'scheduled' && ($announcementStates[7] ?? '') === 'expired' && $publishedAnnouncementPage['total'] === 4 && $scheduledAnnouncementPage['total'] === 1, 'management center derives draft, scheduled, published, and expired filters from the existing flag and dates');
$assert($studentAudienceAnnouncementPage['total'] === 4 && $studentAudienceAnnouncementPage['pagination']['query'] === ['audience' => 'students'], 'announcement audience filter is prepared, counted accurately, and preserved in page metadata');
$searchedAnnouncementPage = AnnouncementService::managementList($workflowDb, '', ['q' => 'Student notice', 'audience' => 'students']);
$assert(array_column($searchedAnnouncementPage['rows'], 'id') === [2] && $searchedAnnouncementPage['pagination']['query'] === ['audience' => 'students', 'q' => 'Student notice'], 'announcement search matches title/body server-side and preserves search plus audience during pagination');
$scheduledNoticeDate = (new DateTimeImmutable($announcementRows[5][4], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
$scheduledDateAnnouncementPage = AnnouncementService::managementList($workflowDb, '', ['start_date' => $scheduledNoticeDate, 'end_date' => $scheduledNoticeDate]);
$invalidAnnouncementDatesRejected = false;
try {
    AnnouncementService::managementList($workflowDb, '', ['start_date' => '2026-10-27', 'end_date' => '2026-10-26']);
} catch (DomainException) {
    $invalidAnnouncementDatesRejected = true;
}
$assert(array_column($scheduledDateAnnouncementPage['rows'], 'id') === [6] && $scheduledDateAnnouncementPage['pagination']['query']['date_range'] === 'custom' && $invalidAnnouncementDatesRejected, 'announcement date filters target scheduled start/creation dates using Manila days and reject reversed ranges');
$assert($announcementPage['counts'] === ['draft' => 1, 'scheduled' => 1, 'published' => 4, 'expired' => 1] && array_sum($studentAudienceAnnouncementPage['counts']) === 4, 'announcement state tab counts derive from database states and respect the selected audience filter');
$requiredAnnouncementValidation = AnnouncementService::validateSaveInput(['title' => '', 'body' => '', 'audience' => 'students', 'publication_mode' => 'publish'], true);
$assert(!$requiredAnnouncementValidation['ok'] && isset($requiredAnnouncementValidation['errors']['title'], $requiredAnnouncementValidation['errors']['body']), 'new announcements require a title and message with field-level validation errors');
$publishNowValidation = AnnouncementService::validateSaveInput(['title' => 'Immediate update', 'body' => 'Visible after saving.', 'audience' => 'all', 'publication_mode' => 'publish', 'starts_at' => '2099-01-01T10:00'], true, new DateTimeImmutable('2026-10-26 00:00:00', new DateTimeZone('UTC')));
$assert($publishNowValidation['ok'] && $publishNowValidation['values']['is_published'] === 1 && $publishNowValidation['values']['starts_at'] === null && $publishNowValidation['values']['ends_at'] === null, 'publish-now validates and persists immediate publication instead of honoring a hidden schedule value');
$scheduleNow = new DateTimeImmutable('2026-10-26 00:00:00', new DateTimeZone('UTC'));
$scheduledAnnouncementValidation = AnnouncementService::validateSaveInput(['title' => 'Scheduled update', 'body' => 'Future notice.', 'audience' => 'students', 'publication_mode' => 'schedule', 'starts_at' => '2026-10-27T10:00'], true, $scheduleNow);
$missingScheduleValidation = AnnouncementService::validateSaveInput(['title' => 'Missing schedule', 'body' => 'Choose a time.', 'audience' => 'students', 'publication_mode' => 'schedule'], true, $scheduleNow);
$pastScheduleValidation = AnnouncementService::validateSaveInput(['title' => 'Past schedule', 'body' => 'Not allowed.', 'audience' => 'students', 'publication_mode' => 'schedule', 'starts_at' => '2026-10-25T10:00'], true, $scheduleNow);
$invalidCalendarSchedule = AnnouncementService::validateSaveInput(['title' => 'Invalid date', 'body' => 'Not a date.', 'audience' => 'students', 'publication_mode' => 'schedule', 'starts_at' => '2026-02-30T10:00'], true, $scheduleNow);
$assert($scheduledAnnouncementValidation['ok'] && $scheduledAnnouncementValidation['values']['is_published'] === 1 && $scheduledAnnouncementValidation['values']['starts_at'] === '2026-10-27 02:00:00', 'scheduled announcement requires a future Manila time and converts it to the correct UTC value');
$assert(!$missingScheduleValidation['ok'] && isset($missingScheduleValidation['errors']['starts_at']) && !$pastScheduleValidation['ok'] && isset($pastScheduleValidation['errors']['starts_at']) && !$invalidCalendarSchedule['ok'], 'missing, past, and invalid-calendar schedule values are rejected server-side');
$editDraftValidation = AnnouncementService::validateSaveInput(['title' => 'Edited draft', 'body' => 'Still a draft.', 'audience' => 'staff', 'is_published' => '0'], false, $scheduleNow);
$assert($editDraftValidation['ok'] && $editDraftValidation['values']['is_published'] === 0, 'existing edit workflow continues to preserve draft publication state');
$announcementViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/announcements.php');
$assert(is_string($announcementViewSource) && str_contains($announcementViewSource, 'Create Announcement') && str_contains($announcementViewSource, 'data-drawer-add="#announcementFormDrawer"') && str_contains($announcementViewSource, 'data-announcement-mode') && str_contains($announcementViewSource, 'Schedule for later') && str_contains($announcementViewSource, 'data-confirm="Delete this announcement?'), 'Announcement Center renders accessible create drawer modes and retains destructive-action confirmation');
$announcements = $announcementPage['rows'];
$pagination = $announcementPage['pagination'];
$activeState = '';
$activeAudience = '';
$activeSearch = '';
$stateCounts = $announcementPage['counts'];
$formValues = ['title' => 'Retained update', 'body' => 'Keep this typed message.', 'audience' => 'students', 'publication_mode' => 'schedule', 'starts_at' => '2026-10-27T10:00'];
$formErrors = ['starts_at' => 'The scheduled date and time must be in the future.'];
ob_start();
require dirname(__DIR__) . '/resources/views/staff/announcements.php';
$announcementManagementMarkup = ob_get_clean();
$assert(str_contains($announcementManagementMarkup, 'All <span>7</span>') && str_contains($announcementManagementMarkup, 'Published <span>4</span>') && str_contains($announcementManagementMarkup, 'Retained update') && str_contains($announcementManagementMarkup, 'Keep this typed message.') && str_contains($announcementManagementMarkup, 'data-open="true"') && str_contains($announcementManagementMarkup, 'The scheduled date and time must be in the future.') && str_contains($announcementManagementMarkup, 'aria-current="page"'), 'announcement list renders accurate counts and reopens the modal with submitted values and field errors');
$announcementPaginationStart = (int) $workflowDb->query('SELECT MAX(id) FROM announcements')->fetchColumn();
$extraAnnouncement = $workflowDb->prepare('INSERT INTO announcements (title, body, audience, is_published, created_by) VALUES (:title, \'fixture paging\', \'all\', 1, 5)');
for ($announcementIndex = 0; $announcementIndex < 11; $announcementIndex++) {
    $extraAnnouncement->execute(['title' => 'Page fixture ' . $announcementIndex]);
}
$publishedAnnouncementSecondPage = AnnouncementService::managementList($workflowDb, 'published', ['per_page' => 10, 'page' => 2]);
$assert($publishedAnnouncementSecondPage['total'] === 15 && $publishedAnnouncementSecondPage['pagination']['from'] === 11 && count($publishedAnnouncementSecondPage['rows']) === 5 && $publishedAnnouncementSecondPage['pagination']['query'] === ['state' => 'published'], 'announcement state filters are applied before pagination and retained in page metadata');
$activeAnnouncementSecondPage = AnnouncementService::activePage($workflowDb, $adminUser, ['per_page' => 10, 'page' => 2]);
$assert($activeAnnouncementSecondPage['pagination']['total'] === 15 && $activeAnnouncementSecondPage['pagination']['from'] === 11 && count($activeAnnouncementSecondPage['rows']) === 5, 'reader pagination counts and pages only currently active announcements for the authenticated role audience');
$workflowDb->exec('DELETE FROM announcements WHERE id > ' . $announcementPaginationStart);
$invalidAnnouncementFilterRejected = false;
try {
    AnnouncementService::managementList($workflowDb, 'urgent');
} catch (DomainException) {
    $invalidAnnouncementFilterRejected = true;
}
$assert($invalidAnnouncementFilterRejected, 'unknown derived announcement filter is rejected');
$assert(AnnouncementService::visibleAnnouncement($workflowDb, 3, null) === null && AnnouncementService::visibleAnnouncement($workflowDb, 3, ['role' => 'student']) === null && AnnouncementService::visibleAnnouncement($workflowDb, 2, $reviewerOne) === null && is_array(AnnouncementService::visibleAnnouncement($workflowDb, 3, $reviewerOne)) && is_array(AnnouncementService::visibleAnnouncement($workflowDb, 2, $adminUser)), 'full announcement detail applies audience checks while administrators can preview all audiences');
$user = ['id' => 1, 'role' => 'student'];
$announcement = AnnouncementService::visibleAnnouncement($workflowDb, 2, $user);
$title = 'Announcement detail test';
ob_start();
require dirname(__DIR__) . '/resources/views/announcements/detail.php';
$announcementDetailMarkup = ob_get_clean();
$assert(str_contains($announcementDetailMarkup, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($announcementDetailMarkup, '<script>alert(1)</script>'), 'full announcement detail escapes stored message content before rendering');
$csrfDeleteForm = file_get_contents(dirname(__DIR__) . '/resources/views/staff/announcements.php');
$announcementControllerSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$announcementNavSource = file_get_contents(dirname(__DIR__) . '/resources/views/layout.php');
$assert(is_string($announcementControllerSource) && str_contains($announcementControllerSource, "if (\$path === '/staff/announcements')") && str_contains($announcementControllerSource, 'AnnouncementService::canManage($user)') && str_contains($announcementControllerSource, "if (\$path === '/staff/announcement/delete')") && str_contains($announcementControllerSource, 'verify_csrf()') && str_contains($adminLayout, 'href="/staff/announcements"'), 'Announcement Center has a server-side Admin check, shared POST CSRF enforcement, and Admin navigation');
$assert(is_string($csrfDeleteForm) && str_contains($csrfDeleteForm, 'action="/staff/announcement/delete"') && str_contains($csrfDeleteForm, 'name="_csrf"') && str_contains($csrfDeleteForm, 'data-confirm="Delete this announcement?'), 'delete UI requires confirmation and includes the existing CSRF token');
$unauthorizedAnnouncementDelete = false;
try {
    AnnouncementService::delete($workflowDb, $reviewerOne, 2);
} catch (DomainException) {
    $unauthorizedAnnouncementDelete = true;
}
$announcementNotificationsBeforeDelete = (int) $workflowDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$announcementAuditBeforeDelete = (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='announcement.deleted'")->fetchColumn();
$assert($unauthorizedAnnouncementDelete && (int) $workflowDb->query('SELECT COUNT(*) FROM announcements WHERE id=2')->fetchColumn() === 1, 'Reviewer cannot delete an announcement by posting its ID');
AnnouncementService::delete($workflowDb, $adminUser, 2);
$assert(AnnouncementService::visibleAnnouncement($workflowDb, 2, $adminUser) === null && (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='announcement.deleted' AND entity_id=2")->fetchColumn() === $announcementAuditBeforeDelete + 1 && (int) $workflowDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $announcementNotificationsBeforeDelete, 'Admin deletion removes subsequent detail visibility, records a minimal audit event, and preserves notifications');
$reviewerOneFeed = NotificationService::feed($workflowDb, $reviewerOne);
$reviewerTwoFeed = NotificationService::feed($workflowDb, $reviewerTwo);
$reviewerOneAlerts = array_values(array_filter($reviewerOneFeed['items'], static fn (array $item): bool => !empty($item['derived'])));
$reviewerTwoAlerts = array_values(array_filter($reviewerTwoFeed['items'], static fn (array $item): bool => !empty($item['derived'])));
$reviewerOneKeys = array_column($reviewerOneAlerts, 'key');
$reviewerOneReloadedAlerts = array_values(array_filter(NotificationService::feed($workflowDb, $reviewerOne)['items'], static fn (array $item): bool => !empty($item['derived'])));
$reviewerOneKeysReloaded = array_column($reviewerOneReloadedAlerts, 'key');
$reviewerOneDerivedUnread = count(array_filter($reviewerOneAlerts, static fn (array $item): bool => empty($item['read_at'])));
sort($reviewerOneKeys);
sort($reviewerOneKeysReloaded);
$assignedAlertCount = static fn (array $alerts): int => count(array_filter($alerts, static fn (array $item): bool => preg_match('/^reviewer:assigned-submitted:/', $item['key']) === 1));
$assert($assignedAlertCount($reviewerOneAlerts) === 1 && $assignedAlertCount($reviewerTwoAlerts) === 1 && str_contains(implode(' ', array_column($reviewerOneAlerts, 'message')), '1 assigned submitted application'), 'reviewer derived counts are scoped to assigned and unassigned visible queue records');
$assert($reviewerOneKeys === $reviewerOneKeysReloaded && (int) $workflowDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $notificationRowsBeforeDerivedLoads, 'derived reviewer alerts remain stable across loads and do not create persistent duplicates');
$assert($reviewerOneFeed['unread_count'] === $reviewerOneFeed['persistent_unread_count'] + $reviewerOneDerivedUnread, 'unread badge count combines persistent unread records and active derived conditions');
$queueOne = StaffService::applications($workflowDb, $reviewerOne, []);
$queueTwo = StaffService::applications($workflowDb, $reviewerTwo, []);
$queueOneIds = array_map(static fn (array $row): int => (int) $row['id'], $queueOne['rows']);
$queueTwoIds = array_map(static fn (array $row): int => (int) $row['id'], $queueTwo['rows']);
$assert(in_array($draftApplicationId, $queueOneIds, true) && in_array(2, $queueOneIds, true) && !in_array(3, $queueOneIds, true), 'reviewer queue includes unassigned and own assigned work only');
$assert(in_array($draftApplicationId, $queueTwoIds, true) && in_array(3, $queueTwoIds, true) && !in_array(2, $queueTwoIds, true), 'multiple reviewers receive distinct assigned work and common unassigned work');
$combinedApplicationFilters = StaffService::applications($workflowDb, $reviewerOne, ['q' => 'Review Student', 'status' => 'submitted', 'program_id' => '1', 'year_id' => '1', 'start_date' => '2000-01-01', 'end_date' => '2100-12-31', 'reviewer_id' => '3', 'documents' => 'incomplete', 'per_page' => '10', 'page' => '2']);
$combinedApplicationIds = array_map(static fn (array $row): int => (int) $row['id'], $combinedApplicationFilters['rows']);
$assert($combinedApplicationIds === [2] && $combinedApplicationFilters['pagination']['total'] === 1 && $combinedApplicationFilters['pagination']['page_size'] === 10, 'search, status, program, year, submitted-date range, assigned reviewer, and document completion combine under reviewer scope');
$adminAssignedFilter = StaffService::applications($workflowDb, $adminUser, ['reviewer_id' => '4', 'status' => 'submitted']);
$adminAssignedIds = array_map(static fn (array $row): int => (int) $row['id'], $adminAssignedFilter['rows']);
$assert(in_array(3, $adminAssignedIds, true) && !in_array(2, $adminAssignedIds, true), 'administrator reviewer filter uses active reviewer assignments across the full authorized queue');
$emptyApplicationSearch = StaffService::applications($workflowDb, $reviewerOne, ['q' => 'no application can match this phrase']);
$injectionApplicationSearch = StaffService::applications($workflowDb, $reviewerOne, ['q' => "' OR 1=1 --"]);
$assert($emptyApplicationSearch['total'] === 0 && $emptyApplicationSearch['rows'] === [] && $injectionApplicationSearch['total'] === 0, 'empty search results are valid and SQL-looking search text remains a bound parameter');
$invalidApplicationRangeRejected = false;
try {
    StaffService::applications($workflowDb, $reviewerOne, ['start_date' => '2026-10-27', 'end_date' => '2026-10-26']);
} catch (DomainException) {
    $invalidApplicationRangeRejected = true;
}
$assert($invalidApplicationRangeRejected, 'application submitted-date filter rejects a start date later than its end date');
$boundaryApplications = $workflowDb->prepare('INSERT INTO applications (id, student_id, status, submitted_at) VALUES (:id, 1, \'submitted\', :submitted_at)');
foreach ([[9001, '2026-10-25 16:01:00'], [9002, '2026-10-26 15:59:00'], [9003, '2026-10-26 16:00:00']] as [$boundaryId, $submittedAtUtc]) {
    $boundaryApplications->execute(['id' => $boundaryId, 'submitted_at' => $submittedAtUtc]);
}
$manilaDateApplications = StaffService::applications($workflowDb, $adminUser, ['start_date' => '2026-10-26', 'end_date' => '2026-10-26']);
$manilaDateIds = array_map(static fn (array $row): int => (int) $row['id'], $manilaDateApplications['rows']);
$workflowDb->exec('DELETE FROM applications WHERE id IN (9001, 9002, 9003)');
$assert(in_array(9001, $manilaDateIds, true) && in_array(9002, $manilaDateIds, true) && !in_array(9003, $manilaDateIds, true), 'submitted date filters use inclusive Asia/Manila calendar days over UTC-stored submission timestamps');
$renderApplicationQueue = static function (array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/resources/views/staff/application-queue.php';
    return (string) ob_get_clean();
};
$applicationQueueFilters = ['q' => 'Review Student', 'status' => 'submitted', 'program_id' => '1', 'year_id' => '1', 'start_date' => '2026-10-20', 'end_date' => '2026-10-26', 'date_range' => 'custom', 'reviewer_id' => '3', 'documents' => 'incomplete', 'per_page' => '10', 'page' => '2'];
$applicationQueueMarkup = $renderApplicationQueue(['applicationQueuePath' => '/staff/applications', 'applications' => ['rows' => [['id' => 2, 'full_name' => 'Review Student', 'reference_number' => 'AF-TEST-2', 'program_name' => 'Test Program', 'year_label' => 'AY Test', 'submitted_at' => '2026-10-26 10:00:00', 'uploaded_documents' => 0, 'required_documents' => 1, 'reviewer_name' => 'Reviewer One', 'status' => 'submitted']], 'filters' => $applicationQueueFilters, 'pagination' => Pagination::make(27, $applicationQueueFilters)], 'statusLabels' => ['submitted' => 'Submitted', 'in_screening' => 'In screening', 'documents_incomplete' => 'Documents incomplete', 'interview_scheduled' => 'Interview scheduled', 'accepted' => 'Accepted', 'declined' => 'Declined', 'enrolled' => 'Enrolled'], 'programs' => [['id' => 1, 'name' => 'Test Program']], 'years' => [['id' => 1, 'label' => 'AY Test']], 'reviewers' => [['id' => 3, 'full_name' => 'Reviewer One']]]);
$assert(str_contains($applicationQueueMarkup, 'More Filters') && str_contains($applicationQueueMarkup, 'aria-expanded="true"') && str_contains($applicationQueueMarkup, 'Advanced Filters') && str_contains($applicationQueueMarkup, 'Last 7 days') && str_contains($applicationQueueMarkup, 'Custom range') && str_contains($applicationQueueMarkup, 'aria-label="Remove Date: Custom range filter"'), 'application queue renders accessible advanced filters and removable chips from actual selected values');
$assert(!str_contains($applicationQueueMarkup, 'Apply filters') && str_contains($applicationQueueMarkup, 'name="per_page" value="10"') && str_contains($applicationQueueMarkup, 'reviewer_id=3') && str_contains($applicationQueueMarkup, 'documents=incomplete') && str_contains($applicationQueueMarkup, 'start_date=2026-10-20') && str_contains($applicationQueueMarkup, 'per_page=10&amp;page=3'), 'application queue omits manual apply and pagination URLs preserve filters and selected page size');
$emptyApplicationQueueMarkup = $renderApplicationQueue(['applicationQueuePath' => '/staff/applications', 'applications' => ['rows' => [], 'filters' => ['q' => 'missing'], 'pagination' => Pagination::make(0, [])], 'statusLabels' => ['submitted' => 'Submitted'], 'programs' => [], 'years' => [], 'reviewers' => []]);
$applicationFilterScript = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$assert(str_contains($emptyApplicationQueueMarkup, 'No applications match these filters') && is_string($applicationFilterScript) && str_contains($applicationFilterScript, 'setTimeout(submitFilters, 350)') && str_contains($applicationFilterScript, "form[data-auto-filter-form]") && str_contains($applicationFilterScript, 'window.location.assign') && str_contains($applicationFilterScript, 'data-clear-filter') && str_contains($applicationFilterScript, 'Asia/Manila'), 'empty queue has a helpful state and shared JavaScript wires debounced GET updates, chip removal, and Manila date presets');
$sharedFilterViews = [
    file_get_contents(dirname(__DIR__) . '/resources/views/staff/application-queue.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/staff/settings.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/staff/users.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/staff/announcements.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/announcements/index.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/staff/audit.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/staff/reports.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/student/notifications.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/auth/change-password.php'),
    file_get_contents(dirname(__DIR__) . '/resources/views/staff/application-detail.php'),
];
$sharedFilterContract = true;
foreach ($sharedFilterViews as $filterViewSource) {
    $sharedFilterContract = $sharedFilterContract && is_string($filterViewSource) && str_contains($filterViewSource, 'data-auto-filter-form') && str_contains($filterViewSource, 'data-filter-search');
}
$assert($sharedFilterContract && str_contains($sharedFilterViews[5], 'data-advanced-panel') && str_contains($sharedFilterViews[6], 'data-advanced-panel') && str_contains($sharedFilterViews[7], 'data-filter-change'), 'relevant queue, settings, user, announcement, audit, report, notification, sign-in, and application history views share filtering interactions with page-specific controls');
$assert(StaffService::detail($workflowDb, $reviewerOne, 2) !== null && StaffService::detail($workflowDb, $reviewerTwo, 2) === null, 'direct application ID access follows reviewer assignment scope');
$assert(StaffService::detail($workflowDb, ['id' => 1, 'role' => 'student'], $draftApplicationId) === null, 'student cannot use staff detail service');
$recordAuthorizedDetailView = static function (PDO $database, array $viewer, int $applicationId): bool {
    $applicationDetail = StaffService::detail($database, $viewer, $applicationId);
    if ($applicationDetail === null) return false;
    return AuditLog::recordOncePerWindow($database, $viewer, 'application.viewed', 'application', $applicationId, 600);
};
$viewAuditStart = (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.viewed' AND entity_id=2")->fetchColumn();
$firstAuthorizedView = $recordAuthorizedDetailView($workflowDb, $reviewerOne, 2);
$refreshAuthorizedView = $recordAuthorizedDetailView($workflowDb, $reviewerOne, 2);
$workflowDb->prepare("UPDATE audit_logs SET created_at = :created_at WHERE action='application.viewed' AND user_id=3 AND entity_id=2")->execute(['created_at' => gmdate('Y-m-d H:i:s', time() - 601)]);
$viewAfterWindow = $recordAuthorizedDetailView($workflowDb, $reviewerOne, 2);
$separateUserView = $recordAuthorizedDetailView($workflowDb, $adminUser, 2);
$deniedReviewerView = $recordAuthorizedDetailView($workflowDb, $reviewerTwo, 2);
$viewAuditRows = $workflowDb->query("SELECT user_id, actor_label, action, outcome, created_at, details FROM audit_logs WHERE action='application.viewed' AND entity_id=2 ORDER BY id")->fetchAll();
$assert($firstAuthorizedView && !$refreshAuthorizedView && $viewAfterWindow && $separateUserView && !$deniedReviewerView && count($viewAuditRows) === $viewAuditStart + 3 && (int) $viewAuditRows[count($viewAuditRows) - 1]['user_id'] === (int) $adminUser['id'] && $viewAuditRows[count($viewAuditRows) - 1]['actor_label'] !== '' && $viewAuditRows[count($viewAuditRows) - 1]['created_at'] !== '' && $viewAuditRows[count($viewAuditRows) - 1]['outcome'] === 'success', 'authorized detail views audit once per user/application/window, later visits and other users log, and denied reviewer access has no success event');
$archiveDetailViewer = static function (array $viewer, ?array $applicationDetail): string {
    $user = $viewer;
    $application = $applicationDetail ?? [];
    $statusLabels = ['submitted' => 'Submitted', 'in_screening' => 'In screening', 'documents_incomplete' => 'Documents incomplete', 'interview_scheduled' => 'Interview scheduled', 'accepted' => 'Accepted', 'declined' => 'Declined', 'enrolled' => 'Enrolled'];
    $reviewers = [];
    ob_start();
    require dirname(__DIR__) . '/resources/views/staff/application-detail.php';
    return (string) ob_get_clean();
};
$activeAdminDetail = StaffService::detail($workflowDb, $adminUser, 2);
$activeAdminDetailMarkup = $archiveDetailViewer($adminUser, $activeAdminDetail);
$activeReviewerDetail = StaffService::detail($workflowDb, $reviewerOne, 2);
$activeReviewerDetailMarkup = $archiveDetailViewer($reviewerOne, $activeReviewerDetail);
$assert(str_contains($activeAdminDetailMarkup, 'class="detail-heading-status-actions"') && str_contains($activeAdminDetailMarkup, 'action="/staff/application/archive"') && str_contains($activeAdminDetailMarkup, 'Archive this application? It will be hidden from active queues') && str_contains($activeAdminDetailMarkup, 'name="_csrf"') && !str_contains($activeReviewerDetailMarkup, 'action="/staff/application/archive"') && !str_contains($activeReviewerDetailMarkup, 'action="/staff/application/restore"'), 'active detail render shows grouped CSRF-confirmed Archive to admins and no archive controls to reviewers');
$workflowDb->exec('UPDATE applications SET deleted_at = UTC_TIMESTAMP() WHERE id=2');
$archivedAdminDetail = StaffService::detail($workflowDb, $adminUser, 2, false, [], true, true);
$archivedReviewerDetail = StaffService::detail($workflowDb, $reviewerOne, 2, false, [], true, true);
$adminArchivedDetailMarkup = $archiveDetailViewer($adminUser, $archivedAdminDetail);
$reviewerArchivedDetailMarkup = $archivedReviewerDetail === null ? '' : $archiveDetailViewer($reviewerOne, $archivedReviewerDetail);
$workflowDb->exec('UPDATE applications SET deleted_at = NULL WHERE id=2');
$assert(is_array($archivedAdminDetail) && !empty($archivedAdminDetail['is_archived']) && str_contains($adminArchivedDetailMarkup, 'class="detail-heading-status-actions"') && str_contains($adminArchivedDetailMarkup, 'action="/staff/application/restore"') && str_contains($adminArchivedDetailMarkup, 'Restore this application to active queues') && $archivedReviewerDetail === null && $reviewerArchivedDetailMarkup === '', 'administrator can render grouped Restore on archived detail while a reviewer cannot load it even with archived mode requested');
$assert(StaffService::authorizedDocument($workflowDb, ['id' => 1, 'role' => 'student'], 1) === null, 'student cannot use staff document authorization service');
$assignmentDenied = false;
try {
    StaffService::assignReviewer($workflowDb, $reviewerOne, $draftApplicationId, 4);
} catch (DomainException) {
    $assignmentDenied = true;
}
$assert($assignmentDenied, 'reviewer cannot change reviewer assignments');

$reviewedDocument = StaffService::authorizedDocument($workflowDb, $reviewerOne, 1);
$assert(is_array($reviewedDocument), 'assigned reviewer can authorize current document');
$assert(StaffService::authorizedDocument($workflowDb, $reviewerTwo, 1) === null, 'reviewer cannot preview a document assigned to another reviewer');
$assert(StaffService::authorizedDocument($workflowDb, ['id' => 1, 'role' => 'student'], 1) === null, 'student cannot preview staff documents');
$assert(StaffService::recordDocumentPreviewAccess($workflowDb, $reviewerOne, 1) && (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='document.previewed' AND entity_id=1")->fetchColumn() === 1, 'authorized preview access is audit-logged without document contents');
$assert(!StaffService::recordDocumentPreviewAccess($workflowDb, $reviewerTwo, 1) && (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='document.previewed' AND entity_id=1")->fetchColumn() === 1, 'unauthorized preview attempts are not granted or logged as successful');
$assert(StaffService::documentPreviewContentType('ab0123456789abcdef0123456789abcdef0123456789abcdef.pdf', 'application/pdf') === 'application/pdf' && StaffService::documentPreviewContentType('ab0123456789abcdef0123456789abcdef0123456789abcdef.jpeg', 'image/jpeg') === 'image/jpeg' && StaffService::documentPreviewContentType('ab0123456789abcdef0123456789abcdef0123456789abcdef.png', 'image/png') === 'image/png', 'PDF and supported image previews map to their verified content types');
$assert(StaffService::documentPreviewContentType('ab0123456789abcdef0123456789abcdef0123456789abcdef.pdf', 'text/plain') === null && StaffService::documentPreviewContentType('file.php', 'application/x-httpd-php') === null, 'preview rejects mismatched MIME and unsupported executable content');
$previewHash = hash('sha256', 'verified fixture bytes');
$assert(StaffService::documentPreviewIntegrityMatches(22, 22, $previewHash, $previewHash) && !StaffService::documentPreviewIntegrityMatches(0, 0, $previewHash, $previewHash) && !StaffService::documentPreviewIntegrityMatches(22, 21, $previewHash, $previewHash) && !StaffService::documentPreviewIntegrityMatches(22, 22, str_repeat('0', 64), $previewHash), 'preview integrity rejects empty, size-mismatched, and corrupted files');
$previewPdfPath = tempnam(sys_get_temp_dir(), 'admitflow-preview-');
$previewImagePath = tempnam(sys_get_temp_dir(), 'admitflow-preview-');
file_put_contents($previewPdfPath, "%PDF-1.4\nfixture\n%%EOF\n");
file_put_contents($previewImagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pJcAAAAASUVORK5CYII=', true));
$assert(StaffService::documentPreviewContentLooksValid($previewPdfPath, 'application/pdf') && StaffService::documentPreviewContentLooksValid($previewImagePath, 'image/png'), 'preview accepts structurally decodable PDF and PNG contents');
file_put_contents($previewPdfPath, 'not a PDF');
file_put_contents($previewImagePath, 'corrupted image');
$assert(!StaffService::documentPreviewContentLooksValid($previewPdfPath, 'application/pdf') && !StaffService::documentPreviewContentLooksValid($previewImagePath, 'image/png') && !StaffService::documentPreviewContentLooksValid($previewImagePath . '.missing', 'image/png'), 'preview safely rejects corrupted and unavailable files');
unlink($previewPdfPath);
unlink($previewImagePath);
$rejection = false;
$rejectionError = '';
try {
    StaffService::reviewDocument($workflowDb, $reviewerOne, 1, 'rejected', 'Please provide a clearer scan.');
    $rejection = true;
} catch (Throwable $error) {
    $rejectionError = $error->getMessage();
}
$assert($rejection, 'reviewer can reject a document with feedback (' . $rejectionError . ')');
$assert($workflowDb->query('SELECT status FROM applications WHERE id=2')->fetchColumn() === 'documents_incomplete', 'rejection moves application to documents-incomplete');
$adminLiveFeed = NotificationService::feed($workflowDb, $adminUser);
$attentionAlert = array_values(array_filter($adminLiveFeed['items'], static fn (array $item): bool => ($item['key'] ?? '') === 'admin:applications-attention'))[0] ?? null;
$assert(($attentionAlert['href'] ?? '') === '/admin/applications?status=documents_incomplete', 'Applications Requiring Attention dashboard notification opens the matching active Applications filter');
$dismissedKeys = [];
$dismissResult = is_array($attentionAlert) ? NotificationService::dismissDerived($workflowDb, $adminUser, $attentionAlert['key'], $dismissedKeys) : false;
$dismissedAdminFeed = NotificationService::feed($workflowDb, $adminUser, $dismissedKeys);
$assert(is_array($attentionAlert) && $dismissResult && $dismissedAdminFeed['unread_count'] === $adminLiveFeed['unread_count'] - 1, 'admin can dismiss a derived current-condition alert without inserting or changing database notifications');
$assert($workflowDb->query('SELECT feedback FROM document_reviews WHERE document_id=1')->fetchColumn() === 'Please provide a clearer scan.', 'rejection feedback is retained in review history');
$assert((int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='document_rejected'")->fetchColumn() === 1, 'student receives rejection notification');
$replacementDenied = false;
try {
    ApplicationService::replaceRejectedDocument($workflowDb, 1, 2, 1, ['name' => 'new.pdf', 'tmp_name' => 'not-an-upload', 'type' => 'application/pdf', 'error' => UPLOAD_ERR_OK, 'size' => 100]);
} catch (DomainException) {
    $replacementDenied = true;
}
$replacementFailureAudit = (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='document.replacement_failed' AND entity_id=2 AND outcome='failure'")->fetchColumn();
$assert($replacementDenied && (int) $workflowDb->query('SELECT COUNT(*) FROM application_documents WHERE application_id=2')->fetchColumn() === 1 && (int) $workflowDb->query('SELECT is_current FROM application_documents WHERE id=1')->fetchColumn() === 1, 'replacement failure rolls back without deleting the prior document version');
$assert($replacementFailureAudit === 1, 'failed document replacement records a failure audit after rollback');
$oversizedReplacementMessage = '';
try {
    ApplicationService::replaceRejectedDocument($workflowDb, 1, 2, 1, ['error' => UPLOAD_ERR_INI_SIZE]);
} catch (DomainException $error) {
    $oversizedReplacementMessage = $error->getMessage();
}
$assert($oversizedReplacementMessage === 'The replacement file exceeds the upload size limit.' && (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='document.replacement_failed' AND entity_id=2 AND outcome='failure'")->fetchColumn() === 2, 'PHP upload size errors return a clear message and are failure-audited');
$unauthorizedReplacement = false;
try {
    ApplicationService::replaceRejectedDocument($workflowDb, 1, 3, 1, ['name' => 'new.pdf', 'tmp_name' => 'not-an-upload', 'type' => 'application/pdf', 'error' => UPLOAD_ERR_OK, 'size' => 100]);
} catch (DomainException) {
    $unauthorizedReplacement = true;
}
$assert($unauthorizedReplacement, 'student cannot replace another student application document');

$invalidStatus = false;
try {
    StaffService::changeStatus($workflowDb, $reviewerOne, $draftApplicationId, 'accepted');
} catch (DomainException) {
    $invalidStatus = true;
}
$assert($invalidStatus && $workflowDb->query('SELECT status FROM applications WHERE id=' . $draftApplicationId)->fetchColumn() === 'submitted', 'forbidden status transition preserves current state');
$studentStatusDenied = false;
try {
    StaffService::changeStatus($workflowDb, ['id' => 1, 'full_name' => 'Draft Student', 'role' => 'student'], $draftApplicationId, 'in_screening');
} catch (DomainException) {
    $studentStatusDenied = true;
}
$assert($studentStatusDenied, 'student cannot change application status through the service layer');

$workflowDb->exec("UPDATE application_documents SET is_current=0 WHERE id=1");
$workflowDb->exec("INSERT INTO application_documents (id,application_id,requirement_id,version,storage_name,original_name,detected_mime_type,file_size_bytes,sha256,review_status,uploaded_by,is_current) VALUES (2,2,1,2,'new.pdf','new.pdf','application/pdf',100,'hash2','pending',2,1)");
$workflowDb->exec("UPDATE applications SET status='in_screening' WHERE id=2");
$resolvedAdminFeed = NotificationService::feed($workflowDb, $adminUser, $dismissedKeys);
$assert(!in_array('admin:applications-attention', $resolvedAdminFeed['derived_keys'], true), 'resolved application-attention conditions disappear from the derived notification feed');
$workflowDb->exec("CREATE TRIGGER fail_document_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'simulated document audit failure'); END");
$reviewAuditFailed = false;
try {
    StaffService::reviewDocument($workflowDb, $reviewerOne, 2, 'verified', 'Replacement is clear.');
} catch (Throwable) {
    $reviewAuditFailed = true;
}
$assert($reviewAuditFailed && $workflowDb->query('SELECT review_status FROM application_documents WHERE id=2')->fetchColumn() === 'pending' && (int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='document_verified'")->fetchColumn() === 0, 'review and notification roll back when audit recording fails');
$workflowDb->exec('DROP TRIGGER fail_document_audit');
$unverifiedAcceptance = false;
try {
    StaffService::changeStatus($workflowDb, $reviewerOne, 2, 'accepted');
} catch (DomainException) {
    $unverifiedAcceptance = true;
}
$assert($unverifiedAcceptance && $workflowDb->query('SELECT status FROM applications WHERE id=2')->fetchColumn() === 'in_screening', 'application cannot be accepted while a required document is pending');
$replacementReview = false;
$replacementReviewError = '';
try {
    StaffService::reviewDocument($workflowDb, $reviewerOne, 2, 'verified', 'Replacement is clear.');
    $replacementReview = true;
} catch (Throwable $error) {
    $replacementReviewError = $error->getMessage();
}
$assert($replacementReview && $workflowDb->query('SELECT status FROM applications WHERE id=2')->fetchColumn() === 'in_screening', 'verified replacement returns incomplete application to screening (' . $replacementReviewError . ')');
$reviewAuditIp = $workflowDb->query("SELECT ip_address FROM audit_logs WHERE action='document.reviewed' ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert($reviewAuditIp === '192.0.2.10', 'document review audit event stores the direct client IP');
$historicalDocument = StaffService::authorizedDocument($workflowDb, $reviewerOne, 1, false, true);
$historyDetail = StaffService::detail($workflowDb, $reviewerOne, 2);
$assert(is_array($historicalDocument) && is_array($historyDetail) && count($historyDetail['document_history']) === 1, 'authorized staff can retrieve preserved prior version and its history');
$assert(StaffService::authorizedDocument($workflowDb, $reviewerTwo, 1, false, true) === null, 'unassigned reviewer cannot download another reviewer application history');
$acceptedStatus = false;
$acceptedStatusError = '';
try {
    StaffService::changeStatus($workflowDb, $reviewerOne, 2, 'accepted');
    $acceptedStatus = true;
} catch (Throwable $error) {
    $acceptedStatusError = $error->getMessage();
}
$assert($acceptedStatus && $workflowDb->query('SELECT status FROM applications WHERE id=2')->fetchColumn() === 'accepted', 'fully verified application may be accepted (' . $acceptedStatusError . ')');
$statusAuditIp = $workflowDb->query("SELECT ip_address FROM audit_logs WHERE action='application.status_changed' AND entity_id=2 ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert($statusAuditIp === '192.0.2.10', 'staff application status audit event stores the direct client IP');
$enrollmentBlocked = false;
try {
    StaffService::changeStatus($workflowDb, $adminUser, 2, 'enrolled');
} catch (DomainException) {
    $enrollmentBlocked = true;
}
$assert($enrollmentBlocked && $workflowDb->query('SELECT status FROM applications WHERE id=2')->fetchColumn() === 'accepted', 'enrollment requires completed readiness record');
$workflowDb->exec("UPDATE enrollment_records SET status='complete' WHERE application_id=2");
$enrolledStatus = false;
try {
    StaffService::changeStatus($workflowDb, $adminUser, 2, 'enrolled');
    $enrolledStatus = true;
} catch (Throwable) {
}
$assert($enrolledStatus && $workflowDb->query('SELECT status FROM applications WHERE id=2')->fetchColumn() === 'enrolled', 'administrator may enroll after checklist completion');
$reviewerEnrollmentDenied = false;
try {
    StaffService::changeStatus($workflowDb, $reviewerOne, 2, 'enrolled');
} catch (DomainException) {
    $reviewerEnrollmentDenied = true;
}
$assert($reviewerEnrollmentDenied, 'reviewer cannot perform administrator-only enrollment transition');

$workflowDb->exec("INSERT INTO applications (id,student_id,academic_program_id,academic_year_id,status,submitted_at) VALUES (4,1,1,1,'in_screening',UTC_TIMESTAMP()),(5,2,1,1,'accepted',UTC_TIMESTAMP())");
$workflowDb->exec('INSERT INTO reviewer_assignments (application_id,reviewer_id,assigned_by) VALUES (4,3,5),(5,4,5)');
$workflowDb->exec("INSERT INTO application_documents (id,application_id,requirement_id,version,storage_name,original_name,detected_mime_type,file_size_bytes,sha256,review_status,uploaded_by,is_current) VALUES (3,5,1,1,'enrollment.pdf','enrollment.pdf','application/pdf',100,'verified-hash','verified',2,1)");
$workflowDb->exec("UPDATE student_profiles SET address_line='Owner One Road' WHERE user_id=1");
$workflowDb->exec("UPDATE student_profiles SET address_line='Owner Two Road' WHERE user_id=2");
$studentOnePrint = ApplicationService::printableSummary($workflowDb, 1);
$studentTwoPrint = ApplicationService::printableSummary($workflowDb, 2);
$assert(is_array($studentOnePrint) && is_array($studentTwoPrint) && (int) $studentOnePrint['student_id'] === 1 && (int) $studentTwoPrint['student_id'] === 2 && $studentOnePrint['applicant']['address_line'] === 'Owner One Road' && $studentTwoPrint['applicant']['address_line'] === 'Owner Two Road', 'print summaries derive each application and residential address only from its authenticated student owner');

$statusBeforeInterview = $workflowDb->query('SELECT status FROM applications WHERE id=3')->fetchColumn();
if ($statusBeforeInterview === 'submitted') {
    StaffService::changeStatus($workflowDb, $reviewerTwo, 3, 'in_screening');
}
$manila = new DateTimeZone('Asia/Manila');
$interviewStart = (new DateTimeImmutable('+3 days', $manila))->setTime(10, 0)->format('Y-m-d\\TH:i');
$interviewEnd = (new DateTimeImmutable('+3 days', $manila))->setTime(11, 0)->format('Y-m-d\\TH:i');
$interviewStartLater = (new DateTimeImmutable('+3 days', $manila))->setTime(13, 0)->format('Y-m-d\\TH:i');
$interviewEndLater = (new DateTimeImmutable('+3 days', $manila))->setTime(14, 0)->format('Y-m-d\\TH:i');
$scheduledInterview = false;
try {
    StaffService::scheduleInterview($workflowDb, $reviewerTwo, 3, 4, $interviewStart, $interviewEnd, 'Test meeting instructions');
    $scheduledInterview = true;
} catch (Throwable) {
}
$interviewId = (int) $workflowDb->query("SELECT id FROM interviews WHERE application_id=3 AND status='scheduled' ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert($scheduledInterview && $workflowDb->query('SELECT status FROM applications WHERE id=3')->fetchColumn() === 'interview_scheduled', 'authorized reviewer schedules a future interview and application transitions');
$assert((int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='interview_scheduled'")->fetchColumn() === 1 && (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='interview.scheduled'")->fetchColumn() === 1, 'interview scheduling commits one student notification and audit event');
$studentOwnInterviews = ApplicationService::interviews($workflowDb, 2, 3);
$assert(count($studentOwnInterviews) === 1 && ApplicationService::interviews($workflowDb, 1, 3) === [], 'student interview read is restricted to the application owner');
$unauthorizedSchedule = false;
try {
    StaffService::scheduleInterview($workflowDb, $reviewerOne, 3, 3, $interviewStartLater, $interviewEndLater, 'Unauthorized access test');
} catch (DomainException) {
    $unauthorizedSchedule = true;
}
$assert($unauthorizedSchedule, 'unassigned reviewer cannot schedule an interview by direct application ID');
$invalidInterviewTime = false;
try {
    StaffService::rescheduleInterview($workflowDb, $reviewerTwo, 3, $interviewId, 4, 'not-a-date', $interviewEndLater, 'Invalid date');
} catch (DomainException) {
    $invalidInterviewTime = true;
}
$assert($invalidInterviewTime, 'malformed interview dates are rejected server-side');
$rescheduledInterview = false;
$rescheduleError = '';
try {
    StaffService::rescheduleInterview($workflowDb, $reviewerTwo, 3, $interviewId, 4, $interviewStartLater, $interviewEndLater, 'Updated test instructions');
    $rescheduledInterview = true;
} catch (Throwable $error) {
    $rescheduleError = $error->getMessage();
}
$assert($rescheduledInterview && (int) $workflowDb->query('SELECT interviewer_id FROM interviews WHERE id=' . $interviewId)->fetchColumn() === 4, 'authorized reviewer can reschedule and reassign an active interview (' . $rescheduleError . ')');
$assert((int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='interview_rescheduled'")->fetchColumn() === 1 && (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='interview.rescheduled'")->fetchColumn() === 1, 'rescheduling notifies the applicant and is audit logged');
$unrelatedInterviewerRejected = false;
try {
    StaffService::scheduleInterview($workflowDb, $reviewerOne, 4, 4, $interviewStartLater, $interviewEndLater, 'Unrelated interviewer');
} catch (DomainException) {
    $unrelatedInterviewerRejected = true;
}
$assert($unrelatedInterviewerRejected && (int) $workflowDb->query('SELECT COUNT(*) FROM interviews WHERE application_id=4')->fetchColumn() === 0, 'reviewer cannot select an unrelated interviewer by direct service call');
$completedInterview = false;
$completedError = '';
try {
    StaffService::updateInterview($workflowDb, $reviewerTwo, 3, $interviewId, 'completed', 'Test outcome saved');
    $completedInterview = true;
} catch (Throwable $error) {
    $completedError = $error->getMessage();
}
$assert($completedInterview && $workflowDb->query('SELECT status FROM interviews WHERE id=' . $interviewId)->fetchColumn() === 'completed' && $workflowDb->query('SELECT notes FROM interviews WHERE id=' . $interviewId)->fetchColumn() === 'Test outcome saved', 'interview outcome and notes are saved (' . $completedError . ')');
$assert((int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='interview_completed'")->fetchColumn() === 1, 'interview completion notifies the correct applicant');
$repeatOutcome = false;
try {
    StaffService::updateInterview($workflowDb, $reviewerTwo, 3, $interviewId, 'completed', 'Duplicate');
} catch (DomainException) {
    $repeatOutcome = true;
}
$assert($repeatOutcome && (int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='interview_completed'")->fetchColumn() === 1, 'repeated interview outcome does not duplicate notifications');

$cancelInterview = false;
try {
    StaffService::scheduleInterview($workflowDb, $reviewerOne, 4, 3, $interviewStart, $interviewEnd, 'Cancellation test');
    $cancelInterview = true;
} catch (Throwable) {
}
$cancelInterviewId = (int) $workflowDb->query("SELECT id FROM interviews WHERE application_id=4 AND status='scheduled' ORDER BY id DESC LIMIT 1")->fetchColumn();
$cancelledInterview = false;
$cancelledError = '';
try {
    StaffService::updateInterview($workflowDb, $reviewerOne, 4, $cancelInterviewId, 'cancelled', 'Schedule cancelled');
    $cancelledInterview = true;
} catch (Throwable $error) {
    $cancelledError = $error->getMessage();
}
$assert($cancelInterview && $cancelledInterview && $workflowDb->query('SELECT status FROM applications WHERE id=4')->fetchColumn() === 'in_screening', 'cancelling final scheduled interview returns application to screening (' . $cancelledError . ')');
$assert((int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND type='interview_cancelled'")->fetchColumn() === 1, 'interview cancellation notifies the application owner');
$workflowDb->exec("INSERT INTO applications (id,student_id,academic_program_id,academic_year_id,status,submitted_at) VALUES (6,1,1,1,'in_screening',UTC_TIMESTAMP())");
$workflowDb->exec('INSERT INTO reviewer_assignments (application_id,reviewer_id,assigned_by) VALUES (6,3,5)');
$workflowDb->exec("CREATE TRIGGER fail_interview_audit BEFORE INSERT ON audit_logs WHEN NEW.action='interview.scheduled' BEGIN SELECT RAISE(ABORT, 'simulated interview audit failure'); END");
$interviewAuditRollback = false;
try {
    StaffService::scheduleInterview($workflowDb, $reviewerOne, 6, 3, $interviewStart, $interviewEnd, 'Rollback test');
} catch (Throwable) {
    $interviewAuditRollback = true;
}
$assert($interviewAuditRollback && (int) $workflowDb->query('SELECT COUNT(*) FROM interviews WHERE application_id=6')->fetchColumn() === 0 && $workflowDb->query('SELECT status FROM applications WHERE id=6')->fetchColumn() === 'in_screening', 'interview insert and status update roll back when audit logging fails');
$workflowDb->exec('DROP TRIGGER fail_interview_audit');
$futureInterviewScheduled = false;
try {
    StaffService::scheduleInterview($workflowDb, $reviewerOne, 6, 3, $interviewStartLater, $interviewEndLater, 'Future dashboard fixture');
    $futureInterviewScheduled = true;
} catch (Throwable) {
}
$assert($futureInterviewScheduled, 'future interview fixture is scheduled after rollback test');
$futureInterviewId = (int) $workflowDb->query("SELECT id FROM interviews WHERE application_id=6 AND status='scheduled' ORDER BY id DESC LIMIT 1")->fetchColumn();
$workflowDb->exec("UPDATE applications SET status='accepted' WHERE id=6");
$staleOutcomeDenied = false;
try {
    StaffService::updateInterview($workflowDb, $reviewerOne, 6, $futureInterviewId, 'completed', 'Stale outcome');
} catch (DomainException) {
    $staleOutcomeDenied = true;
}
$assert($staleOutcomeDenied && $workflowDb->query('SELECT status FROM interviews WHERE id=' . $futureInterviewId)->fetchColumn() === 'scheduled', 'interview outcome is blocked after parent application leaves interview processing');
$workflowDb->exec("UPDATE applications SET status='interview_scheduled' WHERE id=6");

$workflowDb->exec("INSERT INTO applications (id,student_id,academic_program_id,academic_year_id,status,submitted_at) VALUES (70,2,1,1,'enrolled',UTC_TIMESTAMP()),(80,2,1,1,'enrolled',UTC_TIMESTAMP())");
$workflowDb->exec('INSERT INTO reviewer_assignments (application_id,reviewer_id,assigned_by) VALUES (70,3,5),(80,3,5)');
$workflowDb->exec("INSERT INTO application_status_history (application_id,from_status,to_status,changed_by,created_at) VALUES (70,'in_screening','interview_scheduled',3,'2026-10-20 10:00:00'),(70,'interview_scheduled','accepted',5,'2026-10-21 10:00:00'),(70,'accepted','enrolled',5,'2026-10-22 10:00:00'),(80,'in_screening','interview_scheduled',3,'2026-10-20 10:00:00'),(80,'interview_scheduled','accepted',5,'2026-10-21 10:00:00'),(80,'accepted','enrolled',5,'2026-10-22 10:00:00')");
$filteredStatusHistoryDetail = StaffService::detail($workflowDb, $adminUser, 70, false, ['history_status' => 'accepted', 'history_start_date' => '2026-10-21', 'history_end_date' => '2026-10-21', 'per_page' => 10]);
$invalidStatusHistoryDate = false;
try {
    StaffService::detail($workflowDb, $adminUser, 70, false, ['history_start_date' => '2026-10-22', 'history_end_date' => '2026-10-21']);
} catch (DomainException) {
    $invalidStatusHistoryDate = true;
}
$assert(count($filteredStatusHistoryDetail['history']) === 1 && $filteredStatusHistoryDetail['history'][0]['to_status'] === 'accepted' && $filteredStatusHistoryDetail['history_pagination']['query']['history_status'] === 'accepted' && $invalidStatusHistoryDate, 'application status history filters by real status and Manila date while preserving history pagination and rejecting reversed ranges');
$workflowDb->exec("INSERT INTO application_documents (id,application_id,requirement_id,version,storage_name,original_name,detected_mime_type,file_size_bytes,sha256,review_status,uploaded_by,uploaded_at,is_current) VALUES (500,70,1,1,'prior.pdf','prior-proof.pdf','application/pdf',100,'prior-hash','rejected',2,'2026-10-20 10:00:00',0)");
$workflowDb->exec("INSERT INTO screening_notes (application_id,author_id,note,visibility,created_at) VALUES (70,3,'Follow up on transfer credits','staff_only','2026-10-20 12:00:00')");
$filteredApplicationHistory = StaffService::detail($workflowDb, $adminUser, 70, false, ['document_history_q' => 'prior-proof', 'document_history_status' => 'rejected', 'document_history_start_date' => '2026-10-20', 'document_history_end_date' => '2026-10-20', 'notes_q' => 'transfer credits', 'notes_start_date' => '2026-10-20', 'notes_end_date' => '2026-10-20']);
$assert(count($filteredApplicationHistory['document_history']) === 1 && $filteredApplicationHistory['document_history'][0]['original_name'] === 'prior-proof.pdf' && count($filteredApplicationHistory['notes']) === 1 && str_contains($filteredApplicationHistory['notes'][0]['note'], 'transfer credits'), 'per-application document version and staff-note histories filter real names, status, text, and Manila dates');
$workflowDb->exec("INSERT INTO interviews (id,application_id,interviewer_id,starts_at_utc,ends_at_utc,instructions,status,created_by,created_at) VALUES (120,70,3,'2026-10-20 10:00:00','2026-10-20 11:00:00','Historical valid schedule','scheduled',3,'2026-10-20 10:00:00'),(121,70,3,'2026-10-20 12:00:00','2026-10-20 13:00:00','Cancelled schedule','cancelled',3,'2026-10-20 12:00:00'),(122,80,3,'2026-10-20 10:00:00','2026-10-20 11:00:00','Other reviewer schedule','scheduled',3,'2026-10-20 10:00:00'),(123,70,3,'2026-10-21 10:00:00','2026-10-21 11:00:00','Invalid outcome test','scheduled',3,'2026-10-21 10:00:00')");
$filteredInterviewHistory = StaffService::detail($workflowDb, $adminUser, 70, false, ['interviews_status' => 'scheduled', 'interviews_q' => 'Historical valid schedule', 'interviews_start_date' => '2026-10-20', 'interviews_end_date' => '2026-10-20']);
$assert(array_column($filteredInterviewHistory['interviews'], 'id') === [120] && $filteredInterviewHistory['interviews_pagination']['total'] === 1, 'interview history filters by actual outcome, interviewer/instructions search, and Manila start date');
$workflowDb->exec("INSERT INTO audit_logs (user_id,actor_label,action,entity_type,entity_id,outcome,severity) VALUES (3,'Reviewer One','interview.scheduled','interview',120,'success','notice'),(3,'Reviewer One','interview.scheduled','interview',121,'success','notice'),(3,'Reviewer One','interview.scheduled','interview',122,'success','notice'),(3,'Reviewer One','interview.scheduled','interview',123,'success','notice')");
$enrolledOutcomeSaved = false;
$enrolledOutcomeError = '';
try {
    StaffService::updateInterview($workflowDb, $reviewerOne, 70, 120, 'completed', 'Interview completed before enrollment was finalized.');
    $enrolledOutcomeSaved = true;
} catch (Throwable $error) {
    $enrolledOutcomeError = $error->getMessage();
}
$assert($enrolledOutcomeSaved && $workflowDb->query('SELECT status FROM interviews WHERE id=120')->fetchColumn() === 'completed' && $workflowDb->query('SELECT status FROM applications WHERE id=70')->fetchColumn() === 'enrolled', 'authorized reviewer can finalize a historically valid scheduled interview after enrollment without changing application status (' . $enrolledOutcomeError . ')');
$assert((int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='interview_completed' AND related_id=120")->fetchColumn() === 1, 'post-enrollment interview completion records one applicant notification');
$enrolledRepeatRejected = false;
try {
    StaffService::updateInterview($workflowDb, $reviewerOne, 70, 120, 'completed', 'Duplicate outcome.');
} catch (DomainException) {
    $enrolledRepeatRejected = true;
}
$assert($enrolledRepeatRejected && (int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='interview_completed' AND related_id=120")->fetchColumn() === 1, 'repeated post-enrollment outcome is rejected without duplicate notification');
$cancelledOutcomeRejected = false;
try {
    StaffService::updateInterview($workflowDb, $reviewerOne, 70, 121, 'completed', 'Cannot complete a cancelled interview.');
} catch (DomainException) {
    $cancelledOutcomeRejected = true;
}
$assert($cancelledOutcomeRejected && $workflowDb->query('SELECT status FROM interviews WHERE id=121')->fetchColumn() === 'cancelled', 'cancelled interviews cannot receive another outcome after enrollment');
$unauthorizedEnrolledOutcome = false;
try {
    StaffService::updateInterview($workflowDb, $reviewerTwo, 80, 122, 'completed', 'Unauthorized outcome.');
} catch (DomainException) {
    $unauthorizedEnrolledOutcome = true;
}
$assert($unauthorizedEnrolledOutcome && $workflowDb->query('SELECT status FROM interviews WHERE id=122')->fetchColumn() === 'scheduled', 'unassigned reviewer cannot finalize another reviewerâ€™s enrolled application interview');
$invalidEnrolledOutcome = false;
try {
    StaffService::updateInterview($workflowDb, $reviewerOne, 70, 123, 'accepted', 'Invalid outcome value.');
} catch (DomainException) {
    $invalidEnrolledOutcome = true;
}
$assert($invalidEnrolledOutcome && $workflowDb->query('SELECT status FROM interviews WHERE id=123')->fetchColumn() === 'scheduled', 'invalid interview outcome values remain rejected for enrolled applications');
$workflowDb->exec('DELETE FROM notifications WHERE related_type = \'interview\' AND related_id IN (120,121,122,123)');
$workflowDb->exec('DELETE FROM audit_logs WHERE entity_type = \'interview\' AND entity_id IN (120,121,122,123)');
$workflowDb->exec('DELETE FROM interviews WHERE id IN (120,121,122,123)');
$workflowDb->exec('DELETE FROM application_status_history WHERE application_id IN (70,80)');
$workflowDb->exec('DELETE FROM reviewer_assignments WHERE application_id IN (70,80)');
$workflowDb->exec('DELETE FROM applications WHERE id IN (70,80)');

$adminChecklist = ['offer_confirmed', 'documents_checked', 'instructions_sent', 'registration_ready'];
$invalidChecklistRejected = false;
try {
    StaffService::updateEnrollmentChecklist($workflowDb, $adminUser, 5, ['made_up_item']);
} catch (DomainException) {
    $invalidChecklistRejected = true;
}
$assert($invalidChecklistRejected && (int) $workflowDb->query('SELECT COUNT(*) FROM enrollment_records WHERE application_id=5')->fetchColumn() === 0, 'unknown enrollment checklist keys are rejected without writes');
$reviewerChecklistDenied = false;
try {
    StaffService::updateEnrollmentChecklist($workflowDb, $reviewerTwo, 5, $adminChecklist);
} catch (DomainException) {
    $reviewerChecklistDenied = true;
}
$assert($reviewerChecklistDenied, 'reviewer cannot prepare enrollment');
$workflowDb->exec("INSERT INTO applications (id,student_id,academic_program_id,academic_year_id,status,submitted_at) VALUES (7,2,1,1,'accepted',UTC_TIMESTAMP())");
$unverifiedEnrollmentDenied = false;
try {
    StaffService::updateEnrollmentChecklist($workflowDb, $adminUser, 7, $adminChecklist);
} catch (DomainException) {
    $unverifiedEnrollmentDenied = true;
}
$assert($unverifiedEnrollmentDenied && (int) $workflowDb->query('SELECT COUNT(*) FROM enrollment_records WHERE application_id=7')->fetchColumn() === 0, 'enrollment checklist is blocked until every required document is verified');
$workflowDb->exec("CREATE TRIGGER fail_enrollment_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'simulated enrollment audit failure'); END");
$enrollmentAuditRollback = false;
try {
    StaffService::updateEnrollmentChecklist($workflowDb, $adminUser, 5, $adminChecklist);
} catch (Throwable) {
    $enrollmentAuditRollback = true;
}
$assert($enrollmentAuditRollback && (int) $workflowDb->query('SELECT COUNT(*) FROM enrollment_records WHERE application_id=5')->fetchColumn() === 0, 'enrollment checklist changes roll back when audit recording fails');
$workflowDb->exec('DROP TRIGGER fail_enrollment_audit');
StaffService::updateEnrollmentChecklist($workflowDb, $adminUser, 5, $adminChecklist);
StaffService::updateEnrollmentChecklist($workflowDb, $adminUser, 5, $adminChecklist);
$assert((int) $workflowDb->query('SELECT COUNT(*) FROM enrollment_records WHERE application_id=5')->fetchColumn() === 1 && $workflowDb->query('SELECT status FROM enrollment_records WHERE application_id=5')->fetchColumn() === 'complete', 'repeated enrollment checklist saves update one uniquely keyed record');
$adminEnrolled = false;
try {
    StaffService::changeStatus($workflowDb, $adminUser, 5, 'enrolled');
    $adminEnrolled = true;
} catch (Throwable) {
}
$assert($adminEnrolled && $workflowDb->query('SELECT status FROM applications WHERE id=5')->fetchColumn() === 'enrolled', 'eligible accepted application can enroll after checklist completion');
$statusNotificationBaseline = (int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='application_status'")->fetchColumn();
$duplicateEnrollmentTransition = false;
try {
    StaffService::changeStatus($workflowDb, $adminUser, 5, 'enrolled');
} catch (DomainException) {
    $duplicateEnrollmentTransition = true;
}
$assert($duplicateEnrollmentTransition && (int) $workflowDb->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='application_status'")->fetchColumn() === $statusNotificationBaseline, 'repeated enrollment transition is rejected without duplicate notification');

$workflowDb->exec("UPDATE applications SET status='interview_scheduled' WHERE id=3");
$reviewerDashboard = StaffService::dashboard($workflowDb, $reviewerOne);
$assert($reviewerDashboard['total'] === 5 && $reviewerDashboard['upcoming_interviews'] === 1, 'reviewer dashboard counts only assigned and unassigned applications and upcoming interviews');
$otherReviewerDashboard = StaffService::dashboard($workflowDb, $reviewerTwo);
$assert($otherReviewerDashboard['upcoming_interviews'] === 0, 'reviewer dashboard excludes interviews for another reviewer assignment');
$adminDashboard = StaffService::dashboard($workflowDb, $adminUser);
$assert($adminDashboard['total'] === 7, 'administrator dashboard includes all non-draft applications');
$studentDashboardDenied = false;
try {
    StaffService::dashboard($workflowDb, ['id' => 1, 'role' => 'student']);
} catch (DomainException) {
    $studentDashboardDenied = true;
}
$assert($studentDashboardDenied, 'student cannot call staff dashboard service');
$chartDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$chartDb->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, status TEXT NOT NULL, submitted_at TEXT NULL, deleted_at TEXT NULL, academic_year_id INTEGER NULL, academic_program_id INTEGER NULL)');
$chartDb->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER NOT NULL, to_status TEXT NOT NULL, created_at TEXT NOT NULL)');
$chartDb->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT NOT NULL, starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, application_deadline TEXT NULL, is_open INTEGER NOT NULL)');
$chartDb->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$chartDb->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER NOT NULL, reviewer_id INTEGER NOT NULL, unassigned_at TEXT NULL)');
$chartDb->exec("INSERT INTO academic_programs (id,name) VALUES (1,'Chart Program')");
$chartDb->exec("INSERT INTO academic_years (id,label,starts_on,ends_on,application_deadline,is_open) VALUES (1,'Open Chart Intake','2026-01-01','2026-12-31','2026-12-01',1)");
$chartCurrentMonthStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('first day of this month')->setTime(0, 0);
$chartDate = static fn (int $monthOffset, int $day, int $hour): string => $chartCurrentMonthStart
    ->modify(($monthOffset >= 0 ? '+' : '') . $monthOffset . ' months')
    ->modify('+' . ($day - 1) . ' days')
    ->setTime($hour, 0)
    ->format('Y-m-d H:i:s');
$insertChartApplication = $chartDb->prepare('INSERT INTO applications (id,status,submitted_at,deleted_at,academic_year_id,academic_program_id) VALUES (:id,:status,:submitted_at,NULL,1,1)');
foreach ([
    [1, 'in_screening', $chartDate(0, 2, 9)],
    [2, 'submitted', $chartDate(0, 4, 9)],
    [3, 'interview_scheduled', $chartDate(-1, 2, 9)],
    [4, 'accepted', $chartDate(-2, 2, 9)],
    [5, 'submitted', $chartDate(0, 3, 9)],
    [6, 'declined', $chartDate(0, 5, 9)],
    [7, 'draft', null],
    [8, 'submitted', $chartDate(0, 6, 9)],
] as [$id, $status, $submittedAt]) {
    $insertChartApplication->execute(['id' => $id, 'status' => $status, 'submitted_at' => $submittedAt]);
}
$chartDb->exec("INSERT INTO reviewer_assignments (application_id,reviewer_id,unassigned_at) VALUES (1,3,NULL),(2,4,NULL),(3,3,NULL),(4,3,NULL),(5,4,NULL),(6,4,NULL),(8,3,NULL)");
$insertChartHistory = $chartDb->prepare('INSERT INTO application_status_history (application_id,to_status,created_at) VALUES (:application_id,:to_status,:created_at)');
foreach ([
    [1, 'in_screening', $chartDate(0, 3, 9)],
    [1, 'in_screening', $chartDate(0, 4, 9)],
    [2, 'in_screening', $chartDate(0, 5, 9)],
    [3, 'in_screening', $chartDate(-1, 4, 9)],
    [4, 'in_screening', $chartDate(-2, 2, 8)],
    [5, 'in_screening', $chartDate(0, 1, 9)],
    [8, 'in_screening', $chartDate(0, 1, 8)],
    [8, 'accepted', $chartDate(0, 7, 9)],
] as [$applicationId, $status, $createdAt]) {
    $insertChartHistory->execute(['application_id' => $applicationId, 'to_status' => $status, 'created_at' => $createdAt]);
}
$adminCharts = StaffService::charts($chartDb, ['id'=>5,'role'=>'admin']);
$assert(count($adminCharts['monthly']) === 12 && $adminCharts['monthly'][11]['month_key'] === gmdate('Y-m') && count(array_filter($adminCharts['monthly'], static fn (array $row): bool => (int) $row['total'] === 0)) >= 9, 'monthly application chart uses a zero-filled trailing 12-month UTC window');
$currentChartMonth = gmdate('Y-m');
$currentSubmittedReviewed = array_values(array_filter($adminCharts['submitted_reviewed'], static fn (array $row): bool => $row['month_key'] === $currentChartMonth))[0] ?? [];
$assert(($currentSubmittedReviewed['submitted'] ?? null) === 5 && ($currentSubmittedReviewed['reviewed'] ?? null) === 4, 'submitted versus reviewed counts use submission dates and first in-screening event once per application');
$currentReviewTime = array_values(array_filter($adminCharts['review_time'], static fn (array $row): bool => $row['month_key'] === $currentChartMonth))[0] ?? [];
$assert(($currentReviewTime['average_hours'] ?? null) === 24.0, 'review-time chart excludes negative submission-to-first-screening intervals');
$intakeByStage = array_column($adminCharts['intake_progress']['stages'], 'total', 'label');
$assert(($adminCharts['intake_progress']['intake']['label'] ?? null) === 'Open Chart Intake' && $intakeByStage === ['Submitted'=>3,'Screening'=>1,'Interview'=>1,'Accepted'=>1,'Declined'=>1,'Enrolled'=>0], 'intake progress reports actual current non-draft status counts without a fabricated target');
$reviewerCharts = StaffService::charts($chartDb, ['id'=>3,'role'=>'reviewer']);
$reviewerCurrentSubmittedReviewed = array_values(array_filter($reviewerCharts['submitted_reviewed'], static fn (array $row): bool => $row['month_key'] === $currentChartMonth))[0] ?? [];
$assert(($reviewerCurrentSubmittedReviewed['submitted'] ?? null) === 2 && ($reviewerCurrentSubmittedReviewed['reviewed'] ?? null) === 2 && array_sum(array_column($reviewerCharts['intake_progress']['stages'], 'total')) === 4, 'reviewer chart series and intake stages respect assigned-or-unassigned application scope');
$chartDb->exec('UPDATE academic_years SET is_open=0 WHERE id=1');
$noOpenIntakeCharts = StaffService::charts($chartDb, ['id'=>5,'role'=>'admin']);
$assert($noOpenIntakeCharts['intake_progress'] === ['intake'=>null,'stages'=>[]], 'intake progress handles unavailable open intake without inventing one');
$chartDb->exec('DELETE FROM application_status_history');
$chartDb->exec('DELETE FROM applications');
$emptyCharts = StaffService::charts($chartDb, ['id'=>5,'role'=>'admin']);
$assert(count($emptyCharts['monthly']) === 12 && array_sum(array_column($emptyCharts['monthly'], 'total')) === 0 && array_sum(array_column($emptyCharts['submitted_reviewed'], 'submitted')) === 0 && array_sum(array_column($emptyCharts['submitted_reviewed'], 'reviewed')) === 0 && $emptyCharts['statuses'] === [] && $emptyCharts['programs'] === [] && count(array_filter($emptyCharts['review_time'], static fn (array $row): bool => $row['average_hours'] !== null)) === 0, 'all charts return empty-safe database-backed series when there are no applications');
$report = StaffService::applications($workflowDb, $reviewerOne, [], true);
$assert($report['total'] === 5 && !$report['truncated'] && $report['summary']['reviewed'] === 2 && isset($report['summary']['programs']['Test Program']), 'report summary counts only scoped applications with an actual recorded review event');
$reportPaginationStartId = (int) $workflowDb->query('SELECT MAX(id) FROM applications')->fetchColumn();
$reportPaginationFixtures = $workflowDb->prepare('INSERT INTO applications (student_id, academic_program_id, academic_year_id, status, submitted_at) VALUES (2, 1, 1, \'submitted\', UTC_TIMESTAMP())');
for ($applicationIndex = 0; $applicationIndex < 15; $applicationIndex++) {
    $reportPaginationFixtures->execute();
}
$reportPageOne = StaffService::applications($workflowDb, $reviewerOne, ['per_page' => 10, 'q' => '%'], true, true);
$reportPageTwo = StaffService::applications($workflowDb, $reviewerOne, ['per_page' => 10, 'page' => 2, 'q' => '%'], true, true);
$assert($reportPageOne['total'] === 20 && count($reportPageOne['rows']) === 10 && count($reportPageTwo['rows']) === 10 && $reportPageOne['pagination']['from'] === 1 && $reportPageTwo['pagination']['from'] === 11 && $reportPageTwo['filters']['q'] === '%' && array_column($reportPageOne['rows'], 'id') !== array_column($reportPageTwo['rows'], 'id'), 'on-screen reports paginate matching authorized results while preserving search and full filtered totals');
$workflowDb->exec('DELETE FROM applications WHERE id > ' . $reportPaginationStartId);
$auditFixtures = $workflowDb->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address, created_at) VALUES (5, :actor, \'pagination.fixture\', \'test\', :entity_id, \'success\', \'info\', NULL, \'2026-10-26 12:00:00\')');
for ($auditIndex = 0; $auditIndex < 23; $auditIndex++) {
    $auditFixtures->execute(['actor' => 'Pagination fixture', 'entity_id' => $auditIndex + 1]);
}
$auditPageOne = StaffService::auditLog($workflowDb, ['action' => 'pagination.fixture', 'per_page' => 10]);
$auditPageTwo = StaffService::auditLog($workflowDb, ['action' => 'pagination.fixture', 'per_page' => 10, 'page' => 2]);
$auditSearchPage = StaffService::auditLog($workflowDb, ['q' => 'pagination.fixture', 'actor' => 'Pagination fixture', 'per_page' => 10]);
$auditDatePage = StaffService::auditLog($workflowDb, ['action' => 'pagination.fixture', 'start_date' => '2026-10-26', 'end_date' => '2026-10-26']);
$invalidAuditDates = false;
try {
    StaffService::auditLog($workflowDb, ['start_date' => '2026-10-27', 'end_date' => '2026-10-26']);
} catch (DomainException) {
    $invalidAuditDates = true;
}
$invalidAuditOutcome = false;
$oversizedAuditSearch = false;
try {
    StaffService::auditLog($workflowDb, ['outcome' => 'invented']);
} catch (DomainException) {
    $invalidAuditOutcome = true;
}
try {
    StaffService::auditLog($workflowDb, ['q' => str_repeat('x', 161)]);
} catch (DomainException) {
    $oversizedAuditSearch = true;
}
$assert($auditPageOne['pagination']['total'] === 23 && count($auditPageOne['rows']) === 10 && count($auditPageTwo['rows']) === 10 && (int) $auditPageOne['rows'][0]['id'] > (int) $auditPageOne['rows'][1]['id'] && $auditPageTwo['filters']['action'] === 'pagination.fixture' && $auditSearchPage['pagination']['total'] === 23, 'audit log applies activity, actor, and text filters before pagination and orders ties deterministically');
$assert($auditDatePage['pagination']['total'] === 23 && $invalidAuditDates, 'audit event date range uses Asia/Manila calendar days and rejects invalid ordering');
$specialAuditActor = "=2+2,\"quoted\"\nline";
$specialAuditIp = "\t@SUM(1,1)";
$specialAuditUpdate = $workflowDb->prepare('UPDATE audit_logs SET actor_label = :actor, ip_address = :ip WHERE action = \'pagination.fixture\' AND entity_id = 1');
$specialAuditUpdate->execute(['actor' => $specialAuditActor, 'ip' => $specialAuditIp]);
$auditCsvFixtures = $workflowDb->prepare('UPDATE audit_logs SET actor_label = :actor, ip_address = :ip WHERE action = \'pagination.fixture\' AND entity_id = :entity_id');
$auditCsvFixtures->execute(['actor' => '+1+1', 'ip' => '192.0.2.18', 'entity_id' => 2]);
$auditCsvFixtures->execute(['actor' => '-1+1', 'ip' => '2001:db8::1', 'entity_id' => 3]);
$auditCsvFixtures->execute(['actor' => '@SUM(A1:A2)', 'ip' => null, 'entity_id' => 4]);
$auditCsvFixtures->execute(['actor' => " \x1C=1+1", 'ip' => null, 'entity_id' => 5]);
$auditCsvFixtures->execute(['actor' => "\t=1+1", 'ip' => null, 'entity_id' => 6]);
$auditCsvFixtures->execute(['actor' => "\r=1+1", 'ip' => null, 'entity_id' => 7]);
$auditCsvFixtures->execute(['actor' => "\n=1+1", 'ip' => null, 'entity_id' => 8]);
$auditCsvFixtures->execute(['actor' => "\u{00A0}=1+1", 'ip' => null, 'entity_id' => 9]);
$auditCsvFixtures->execute(['actor' => 'Ångström, 李', 'ip' => null, 'entity_id' => 10]);
$auditExport = StaffService::auditLogExport($workflowDb, $adminUser, ['action' => 'pagination.fixture', 'page' => 2, 'per_page' => 10]);
$auditExportFiltered = StaffService::auditLogExport($workflowDb, $adminUser, ['q' => 'quoted', 'actor' => 'quoted', 'action' => 'pagination.fixture', 'start_date' => '2026-10-26', 'end_date' => '2026-10-26', 'page' => 2]);
$auditExportEmpty = StaffService::auditLogExport($workflowDb, $adminUser, ['action' => 'does.not.exist']);
$auditExportUnauthorized = false;
try {
    StaffService::auditLogExport($workflowDb, $reviewerOne, []);
} catch (DomainException) {
    $auditExportUnauthorized = true;
}
$auditExportCsv = stream_get_contents($auditExport['stream']);
fclose($auditExport['stream']);
$auditExportFilteredCsv = stream_get_contents($auditExportFiltered['stream']);
fclose($auditExportFiltered['stream']);
$auditExportEmptyCsv = stream_get_contents($auditExportEmpty['stream']);
fclose($auditExportEmpty['stream']);
$csvStream = fopen('php://temp', 'w+b');
fwrite($csvStream, $auditExportCsv);
rewind($csvStream);
$csvHeader = fgetcsv($csvStream, null, ',', '"', '');
$csvRows = [];
while (($csvRow = fgetcsv($csvStream, null, ',', '"', '')) !== false) $csvRows[] = $csvRow;
fclose($csvStream);
$specialCsvRow = array_values(array_filter($csvRows, static fn (array $row): bool => str_contains((string) ($row[2] ?? ''), 'quoted')))[0] ?? [];
$csvRowsByEntity = [];
foreach ($csvRows as $csvRow) $csvRowsByEntity[(int) ($csvRow[5] ?? 0)] = $csvRow;
$emptyCsvStream = fopen('php://temp', 'w+b');
fwrite($emptyCsvStream, $auditExportEmptyCsv);
rewind($emptyCsvStream);
$emptyCsvHeader = fgetcsv($emptyCsvStream, null, ',', '"', '');
$emptyCsvRow = fgetcsv($emptyCsvStream, null, ',', '"', '');
fclose($emptyCsvStream);
$auditEndpointSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$auditExportViewSource = file_get_contents(dirname(__DIR__) . '/resources/views/staff/audit.php');
$assert(str_starts_with($auditExportCsv, "\xEF\xBB\xBF") && count($csvRows) === 23 && count($csvHeader) === 9 && $csvHeader[6] === 'IP Address' && $auditExport['row_count'] === 23, 'audit CSV is UTF-8 BOM encoded and exports all matching rows beyond the requested page with schema-backed headers including recorded IP');
$assert($auditExportFiltered['row_count'] === 1 && $auditExportFiltered['filters']['actor'] === 'quoted' && $auditExportFiltered['filters']['start_date'] === '2026-10-26' && $auditExportFiltered['filters']['end_date'] === '2026-10-26', 'audit CSV applies combined text, actor, activity, and date filters independently of pagination');
$assert(($specialCsvRow[2] ?? null) === "'" . $specialAuditActor && ($specialCsvRow[6] ?? null) === "'" . $specialAuditIp && count($specialCsvRow) === 9, 'audit CSV quotes commas, quotation marks, and line breaks and prefixes formula-triggering actor/IP values');
$assert(($csvRowsByEntity[2][2] ?? null) === "'+1+1" && ($csvRowsByEntity[3][2] ?? null) === "'-1+1" && ($csvRowsByEntity[4][2] ?? null) === "'@SUM(A1:A2)" && ($csvRowsByEntity[5][2] ?? null) === "' \x1C=1+1", 'audit CSV neutralizes plus, minus, at-sign, and control-prefixed formulas in untrusted actor text');
$assert(($csvRowsByEntity[6][2] ?? null) === "'\t=1+1" && ($csvRowsByEntity[7][2] ?? null) === "'\r=1+1" && ($csvRowsByEntity[8][2] ?? null) === "'\n=1+1" && ($csvRowsByEntity[9][2] ?? null) === "'\u{00A0}=1+1", 'audit CSV neutralizes formulas preceded by tab, carriage return, line feed, and Unicode whitespace');
$assert(($csvRowsByEntity[10][2] ?? null) === 'Ångström, 李' && ($csvRowsByEntity[1][1] ?? null) === '5' && ($csvRowsByEntity[1][5] ?? null) === '1', 'audit CSV preserves Unicode and ordinary numeric identifier values');
$assert(($csvRowsByEntity[2][6] ?? null) === '192.0.2.18' && ($csvRowsByEntity[3][6] ?? null) === '2001:db8::1' && ($csvRowsByEntity[4][6] ?? null) === '', 'audit CSV preserves stored IPv4 and IPv6 addresses and represents missing IP values as empty cells');
$assert(count($emptyCsvHeader) === 9 && $emptyCsvRow === false && str_contains($auditExportEmptyCsv, 'Timestamp (UTC)'), 'empty audit export is a valid header-only CSV');
$assert($invalidAuditOutcome && $oversizedAuditSearch && !preg_match('/<\/?(?:html|body|br)|Warning:|Notice:/i', $auditExportCsv), 'audit export rejects invalid/oversized filters and contains no PHP warning or HTML output');
$assert($auditExportUnauthorized && !in_array('details', $csvHeader, true) && !in_array('password', $csvHeader, true) && !in_array('token', $csvHeader, true), 'audit export enforces administrator authorization and excludes details and credential columns');
$auditExportEventsBefore = (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='audit.exported'")->fetchColumn();
StaffService::recordAuditExport($workflowDb, $adminUser);
$auditExportEventsAfter = (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='audit.exported'")->fetchColumn();
$auditExportEventIp = $workflowDb->query("SELECT ip_address FROM audit_logs WHERE action='audit.exported' ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert($auditExportEventsAfter === $auditExportEventsBefore + 1 && $auditExport['row_count'] === 23 && !str_contains($auditExportCsv, 'audit.exported') && $auditExportEventIp === '192.0.2.10', 'audit export records its direct client IP after capture without recursively including the event');
$assert(is_string($auditEndpointSource) && str_contains($auditEndpointSource, "if (\$path === '/staff/audit.csv')") && str_contains($auditEndpointSource, "\$user['role'] !== 'admin'") && str_contains($auditEndpointSource, "header('Content-Type: text/csv; charset=utf-8')") && str_contains($auditEndpointSource, "header('Cache-Control: no-store, private')") && str_contains($auditEndpointSource, 'Content-Disposition: attachment') && is_string($auditExportViewSource) && str_contains($auditExportViewSource, 'action="/staff/audit.csv"') && str_contains($auditExportViewSource, 'name="_csrf"'), 'audit export endpoint checks admin role, returns protected CSV attachment headers, and view submits CSRF-protected filter values');
$largeAuditExportDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$largeAuditExportDb->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER NULL, actor_label TEXT, action TEXT, entity_type TEXT NULL, entity_id INTEGER NULL, outcome TEXT, severity TEXT, ip_address TEXT NULL, created_at TEXT)');
$largeAuditInsert = $largeAuditExportDb->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address, created_at) VALUES (7, :actor, \'batch.fixture\', \'test\', :entity_id, \'success\', \'info\', NULL, \'2026-10-26 12:00:00\')');
for ($largeAuditIndex = 1; $largeAuditIndex <= 1005; $largeAuditIndex++) {
    $largeAuditInsert->execute(['actor' => 'Batch fixture', 'entity_id' => $largeAuditIndex]);
}
$largeAuditExport = StaffService::auditLogExport($largeAuditExportDb, $adminUser, ['action' => 'batch.fixture', 'page' => 100, 'per_page' => 10]);
$assert($largeAuditExport['row_count'] === 1005 && is_resource($largeAuditExport['stream']) && ftell($largeAuditExport['stream']) === 0, 'audit export iterates keyset batches beyond 500 rows and ignores requested pagination');
fclose($largeAuditExport['stream']);
foreach ([['status' => 'invented'], ['start_date' => '2026-02-30'], ['start_date' => '2026-12-01', 'end_date' => '2026-11-01'], ['program_id' => '0'], ['reviewer_id' => '999']] as $badFilter) {
    $filterRejected = false;
    try {
        StaffService::applications($workflowDb, $reviewerOne, $badFilter, true);
    } catch (DomainException) {
        $filterRejected = true;
    }
    $assert($filterRejected, 'invalid report filter is rejected: ' . implode(',', array_keys($badFilter)));
}
$reportExportAuditBefore = (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='report.exported'")->fetchColumn();
StaffService::recordReportExport($workflowDb, $reviewerOne, ['status' => 'in_screening', 'irrelevant' => 'omit'], $report['total']);
$exportAudit = $workflowDb->query("SELECT details FROM audit_logs WHERE action='report.exported' ORDER BY id DESC LIMIT 1")->fetchColumn();
$exportDetails = json_decode((string) $exportAudit, true);
$assert($reportExportAuditBefore === 0 && is_array($exportDetails) && $exportDetails['row_count'] === 5 && isset($exportDetails['filters']['status']) && !isset($exportDetails['filters']['irrelevant']), 'report export audit records row count and only approved filter fields');
$workflowDb->exec("CREATE TRIGGER fail_export_audit BEFORE INSERT ON audit_logs WHEN NEW.action='report.exported' BEGIN SELECT RAISE(ABORT, 'simulated report audit failure'); END");
$exportAuditFailure = false;
try {
    StaffService::recordReportExport($workflowDb, $adminUser, [], 0);
} catch (Throwable) {
    $exportAuditFailure = true;
}
$assert($exportAuditFailure && (int) $workflowDb->query("SELECT COUNT(*) FROM audit_logs WHERE action='report.exported'")->fetchColumn() === 1, 'report audit failure does not leave a second export event');
$workflowDb->exec('DROP TRIGGER fail_export_audit');
$studentReportDenied = false;
try {
    StaffService::applications($workflowDb, ['id' => 1, 'role' => 'student'], [], true);
} catch (DomainException) {
    $studentReportDenied = true;
}
$assert($studentReportDenied, 'student cannot call staff report service');

$workflowDb->exec("CREATE TRIGGER fail_workflow_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'simulated audit failure'); END");
$workflowDb->exec("INSERT INTO users (id,full_name,email,role,account_status) VALUES (6,'Rollback Student','rollback@example.test','student','active')");
$workflowDb->exec('INSERT INTO student_profiles (user_id) VALUES (6)');
$failedDraft = false;
try {
    ApplicationService::save($workflowDb, 6, ['full_name' => 'Failed Draft'], [], false);
} catch (Throwable) {
    $failedDraft = true;
}
$assert($failedDraft && (int) $workflowDb->query('SELECT COUNT(*) FROM applications WHERE student_id=6 AND status=\'draft\'')->fetchColumn() === 0 && $workflowDb->query('SELECT full_name FROM users WHERE id=6')->fetchColumn() === 'Rollback Student', 'database failure rolls back draft and profile writes');
$workflowDb->exec('DROP TRIGGER fail_workflow_audit');

$assert(!LoginRateLimitPolicy::isLimited(4, 29), 'below both rate limits is permitted');
$assert(LoginRateLimitPolicy::remainingCooldown(5, '2026-10-24 11:59:00', new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'))) === 120, 'account threshold produces a timed 180-second cooldown');
$assert(LoginRateLimitPolicy::isLimited(0, 30), 'IP threshold blocks next attempt');
$baseTime = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
$insertAttempt = $database->prepare('INSERT INTO login_attempts (email_normalized,ip_address,was_successful,attempted_at) VALUES (:email,:ip,0,:time)');
for ($index = 0; $index < 5; $index++) {
    $insertAttempt->execute(['email' => 'one@example.test', 'ip' => '192.0.2.10', 'time' => $baseTime->sub(new DateInterval('PT60S'))->format('Y-m-d H:i:s')]);
}
$countsOne = LoginRateLimitPolicy::failureCounts($database, 'one@example.test', '192.0.2.10', $baseTime);
$countsTwoSameIp = LoginRateLimitPolicy::failureCounts($database, 'two@example.test', '192.0.2.10', $baseTime);
$assert($countsOne['account'] === 5 && $countsOne['ip'] === 5 && $countsOne['account_latest'] === $baseTime->sub(new DateInterval('PT60S'))->format('Y-m-d H:i:s'), 'account and IP failures and latest account failure are counted independently');
$assert($countsTwoSameIp['account'] === 0 && !LoginRateLimitPolicy::isLimited($countsTwoSameIp['account'], $countsTwoSameIp['ip']), 'one account threshold does not lock another user on shared IP');
$expiredAttempt = $database->prepare('INSERT INTO login_attempts (email_normalized,ip_address,was_successful,attempted_at) VALUES (:email,:ip,0,:time)');
$expiredAttempt->execute(['email' => 'one@example.test', 'ip' => '192.0.2.10', 'time' => $baseTime->sub(new DateInterval('PT16M'))->format('Y-m-d H:i:s')]);
$countsAfterWindow = LoginRateLimitPolicy::failureCounts($database, 'one@example.test', '192.0.2.10', $baseTime);
$assert($countsAfterWindow['account'] === 5, 'failures older than the rolling 15-minute window do not count');
$countsRecovered = LoginRateLimitPolicy::failureCounts($database, 'one@example.test', '192.0.2.10', $baseTime->add(new DateInterval('PT16M')));
$assert($countsRecovered['account'] === 0 && $countsRecovered['ip'] === 0 && $countsRecovered['account_latest'] === null, 'account and IP counters recover after the rolling window');
$differentIpAttempts = $database->prepare('INSERT INTO login_attempts (email_normalized,ip_address,was_successful,attempted_at) VALUES (:email,:ip,0,:time)');
for ($index = 1; $index <= LoginRateLimitPolicy::ACCOUNT_FAILURE_LIMIT; $index++) {
    $differentIpAttempts->execute(['email' => 'distributed@example.test', 'ip' => '198.51.100.' . $index, 'time' => $baseTime->format('Y-m-d H:i:s')]);
}
$distributedCounts = LoginRateLimitPolicy::failureCounts($database, 'distributed@example.test', '203.0.113.9', $baseTime);
$assert($distributedCounts['account'] === 5 && LoginRateLimitPolicy::remainingCooldown($distributedCounts['account'], $distributedCounts['account_latest'], $baseTime) === 180, 'changing IP does not bypass the per-account cooldown');

$database->exec('DELETE FROM login_attempts');
$cooldownStart = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
for ($attempt = 1; $attempt <= 4; $attempt++) {
    $_SESSION['captcha_answer'] = '5';
    $failedLogin = AuthService::login($database, ['email' => 'one@example.test', 'password' => 'wrong', 'captcha_answer' => '5'], $cooldownStart);
}
$assert($failedLogin['error'] === 'authentication', 'four failed attempts do not start the cooldown');
$_SESSION['captcha_answer'] = '5';
$fifthFailure = AuthService::login($database, ['email' => 'one@example.test', 'password' => 'wrong', 'captcha_answer' => '5'], $cooldownStart);
$assert($fifthFailure['error'] === 'cooldown' && $fifthFailure['cooldown_seconds'] === 180 && $fifthFailure['message'] === 'Too many failed login attempts. Please try again in 3 minutes and 0 seconds.', 'fifth failed attempt starts a 180-second cooldown with a clear message');
$attemptCountDuringCooldown = (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$_SESSION['captcha_answer'] = '5';
$blockedDuringCooldown = AuthService::login($database, ['email' => 'one@example.test', 'password' => 'wrong', 'captcha_answer' => '0'], $cooldownStart->add(new DateInterval('PT179S')));
$attemptCountAfterBlockedRequest = (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$assert($blockedDuringCooldown['error'] === 'cooldown' && $blockedDuringCooldown['cooldown_seconds'] === 1 && $attemptCountAfterBlockedRequest === $attemptCountDuringCooldown, 'active cooldown is server-enforced and takes precedence over CAPTCHA errors');
$result = ['ok' => false, 'error' => 'cooldown', 'message' => $blockedDuringCooldown['message'], 'cooldown_seconds' => 1];
$cooldownSeconds = 1;
$captcha = '2 + 3';
$email = 'one@example.test';
ob_start();
require dirname(__DIR__) . '/resources/views/auth/login.php';
$cooldownView = ob_get_clean();
$assert(str_contains($cooldownView, 'data-login-cooldown="1"') && str_contains($cooldownView, 'data-cooldown-countdown') && str_contains($cooldownView, 'data-cooldown-message'), 'login template renders the cooldown message and countdown in its dedicated alert');
$assert(!str_contains($cooldownView, 'We could not sign you in.'), 'cooldown view does not render the generic authentication error');
$assert(str_contains($cooldownView, 'data-login-submit disabled'), 'login submit is disabled while countdown is active');
$loginController = file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert(is_string($loginController) && str_contains($loginController, "'result' => \$result") && str_contains($loginController, "'cooldownSeconds' => (int) (\$result['cooldown_seconds'] ?? 0)"), 'login controller passes the cooldown result and remaining seconds to the view');
$_SESSION['captcha_answer'] = '5';
$afterCooldown = AuthService::login($database, ['email' => 'one@example.test', 'password' => 'wrong', 'captcha_answer' => '5'], $cooldownStart->add(new DateInterval('PT180S')));
$attemptCountAfterExpiry = (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$assert($afterCooldown['error'] === 'cooldown' && $afterCooldown['cooldown_seconds'] === 180 && $attemptCountAfterExpiry === $attemptCountDuringCooldown + 1, 'cooldown expiry permits another credential attempt and a new failure starts a fresh cooldown');

$_SESSION['captcha_answer'] = '5';
$attemptCountBeforeBadCaptcha = (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$badCaptcha = AuthService::login($database, ['email' => 'two@example.test', 'password' => 'wrong', 'captcha_answer' => '0'], $cooldownStart);
$attemptCountAfterBadCaptcha = (int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$badCaptchaAuditCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.login_failed' AND outcome='failure'")->fetchColumn();
$assert($badCaptcha['error'] === 'captcha' && $attemptCountBeforeBadCaptcha === $attemptCountAfterBadCaptcha && $badCaptchaAuditCount > 0, 'invalid CAPTCHA is distinct, is failure-audited, and does not count as a password failure');

$_SESSION['captcha_answer'] = '5';
$emptyCredentialAuditBefore = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.login_failed'")->fetchColumn();
$emptyCredentialLogin = AuthService::login($database, ['email' => '', 'password' => '', 'captcha_answer' => '5'], $cooldownStart);
$emptyCredentialAuditAfter = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.login_failed'")->fetchColumn();
$assert(!$emptyCredentialLogin['ok'] && $emptyCredentialAuditAfter === $emptyCredentialAuditBefore + 1, 'valid-CAPTCHA login with missing credentials is rejected and creates exactly one failure audit event');

$database->exec('DELETE FROM login_attempts');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
AuthService::newCaptcha();
$legacyExpectedCaptcha = (string) $_SESSION['captcha_answer'];
$legacyLogin = AuthService::login($database, ['email' => 'legacy@example.test', 'password' => 'Legacy10!!', 'captcha_answer' => $legacyExpectedCaptcha]);
$assert($legacyLogin['ok'] && (int) $_SESSION['user']['id'] === $legacyUserId, 'existing account with a 10-character password can still log in');
$sessionIdBeforeLogin = session_id();
$_SESSION['captcha_answer'] = '7';
$successfulLogin = AuthService::login($database, ['email' => 'one@example.test', 'password' => 'OldPass!2345', 'captcha_answer' => '7', 'login_type' => 'staff']);
$assert($successfulLogin['ok'] && session_id() !== $sessionIdBeforeLogin, 'successful login regenerates the session identifier');
$assert($_SESSION['user']['role'] === 'student', 'student role comes from the account record despite a submitted staff login type');
$loginAuditIps = $database->query("SELECT action, ip_address FROM audit_logs WHERE action IN ('auth.login_succeeded', 'auth.login_failed') ORDER BY id")->fetchAll();
$successfulLoginIps = array_values(array_filter($loginAuditIps, static fn (array $row): bool => $row['action'] === 'auth.login_succeeded'));
$failedLoginIps = array_values(array_filter($loginAuditIps, static fn (array $row): bool => $row['action'] === 'auth.login_failed'));
$assert($successfulLoginIps !== [] && $failedLoginIps !== [] && array_unique(array_column($loginAuditIps, 'ip_address')) === ['192.0.2.10'], 'successful and failed login audit events store only the direct client IP');
$_SESSION['captcha_answer'] = '7';
$reviewerLogin = AuthService::login($database, ['email' => 'reviewer@example.test', 'password' => 'ReviewerPass!2345', 'captcha_answer' => '7', 'login_type' => 'student']);
$assert($reviewerLogin['ok'] && $_SESSION['user']['id'] === $reviewerId && $_SESSION['user']['role'] === 'reviewer', 'reviewer can use shared authentication and receives the database role');
$_SESSION['captcha_answer'] = '7';
$adminLogin = AuthService::login($database, ['email' => 'admin@example.test', 'password' => 'AdminPass!2345', 'captcha_answer' => '7']);
$assert($adminLogin['ok'] && $_SESSION['user']['id'] === $adminId && $_SESSION['user']['role'] === 'admin', 'administrator can use shared authentication and receives the database role');
$_SESSION['captcha_answer'] = '7';
$invalidCredentials = AuthService::login($database, ['email' => 'reviewer@example.test', 'password' => 'wrong-password', 'captcha_answer' => '7']);
$assert(!$invalidCredentials['ok'], 'invalid credentials are rejected for shared authentication');

$deliveryCaptured = null;
$delivered = PasswordResetService::request($database, 'one@example.test', static function (string $email, string $name, string $token) use (&$deliveryCaptured): bool {
    $deliveryCaptured = compact('email', 'name', 'token');
    return true;
});
$resetRequestAuditCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.password_reset_requested' AND outcome='success'")->fetchColumn();
$assert($delivered && is_array($deliveryCaptured) && $resetRequestAuditCount === 1, 'reset delivery callback receives generated reset token and one success audit row is recorded');
$stored = $database->query('SELECT token_hash, expires_at, used_at FROM password_reset_tokens ORDER BY id DESC LIMIT 1')->fetch();
$assert(is_array($stored) && hash_equals($stored['token_hash'], hash('sha256', $deliveryCaptured['token'])), 'only the token hash is stored');
$assert($stored['used_at'] === null, 'successfully delivered reset token remains unused');
$database->exec('UPDATE users SET must_change_password = 1 WHERE id=' . $userId);
$hashBeforeRejectedReset = (string) $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
$rejectedPolicyReset = PasswordResetService::reset($database, $deliveryCaptured['token'], 'Fixture', 'Fixture');
$tokenAfterRejectedPolicy = $database->prepare('SELECT used_at FROM password_reset_tokens WHERE token_hash = :hash');
$tokenAfterRejectedPolicy->execute(['hash' => hash('sha256', $deliveryCaptured['token'])]);
$assert(!$rejectedPolicyReset['ok'] && in_array('Password must not contain your name or email address.', $rejectedPolicyReset['errors'] ?? [], true) && $tokenAfterRejectedPolicy->fetchColumn() === null && hash_equals($hashBeforeRejectedReset, (string) $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn()), 'reset validation uses valid-token personal context and preserves its token and stored password hash on failure');
$resetResult = PasswordResetService::reset($database, $deliveryCaptured['token'], 'ChangedPass!234', 'ChangedPass!234');
$assert($resetResult['ok'] && (int) $database->query('SELECT must_change_password FROM users WHERE id=' . $userId)->fetchColumn() === 0, 'valid reset token changes password and clears the forced-change flag');
$resetAuditIp = $database->query("SELECT ip_address FROM audit_logs WHERE action='auth.password_reset' ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert($resetAuditIp === '192.0.2.10', 'successful password-reset audit event stores the direct client IP');
$assert(password_verify('ChangedPass!234', $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn()), 'successful reset stores a password hash');
$database->exec('DELETE FROM login_attempts');
$_SESSION['captcha_answer'] = '7';
$postResetLogin = AuthService::login($database, ['email' => 'one@example.test', 'password' => 'ChangedPass!234', 'captcha_answer' => '7']);
$assert($postResetLogin['ok'] && $_SESSION['user']['id'] === $userId, 'user can sign in with the newly reset password');
$replayResult = PasswordResetService::reset($database, $deliveryCaptured['token'], 'ReplayPass!234', 'ReplayPass!234');
$assert(!$replayResult['ok'], 'used token cannot be reused');
$invalidResult = PasswordResetService::reset($database, str_repeat('0', 64), 'ChangedPass!234', 'ChangedPass!234');
$assert(!$invalidResult['ok'], 'unknown token is rejected');

$expiredToken = bin2hex(random_bytes(32));
$insertToken = $database->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,used_at) VALUES (:user_id,:hash,:expires,NULL)');
$insertToken->execute(['user_id' => $userId, 'hash' => hash('sha256', $expiredToken), 'expires' => gmdate('Y-m-d H:i:s', time() - 60)]);
$expiredResult = PasswordResetService::reset($database, $expiredToken, 'ChangedPass!234', 'ChangedPass!234');
$assert(!$expiredResult['ok'], 'expired token is rejected');

$deliveryFailed = PasswordResetService::request($database, 'two@example.test', static fn (): bool => false);
$failedToken = $database->query('SELECT token_hash,used_at FROM password_reset_tokens WHERE user_id=' . $userTwoId . ' ORDER BY id DESC LIMIT 1')->fetch();
$assert(!$deliveryFailed && is_array($failedToken) && $failedToken['used_at'] !== null, 'failed delivery invalidates the undelivered token');
$resetDeliveries = 0;
$limitedReset = false;
for ($resetRequest = 1; $resetRequest <= PasswordResetService::REQUEST_LIMIT + 1; $resetRequest++) {
    $deliveredReset = PasswordResetService::request($database, 'two@example.test', static function () use (&$resetDeliveries): bool {
        $resetDeliveries++;
        return true;
    });
    if ($resetRequest > PasswordResetService::REQUEST_LIMIT - 1) {
        $limitedReset = $limitedReset || !$deliveredReset;
    }
}
$assert($resetDeliveries === PasswordResetService::REQUEST_LIMIT - 1 && $limitedReset, 'password reset requests are rate limited per account in the configured window');
$userTwoResetRequests = $database->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = :user_id AND created_at >= :window_start');
$userTwoResetRequests->execute(['user_id' => $userTwoId, 'window_start' => gmdate('Y-m-d H:i:s', time() - PasswordResetService::REQUEST_WINDOW_SECONDS)]);
$assert((int) $userTwoResetRequests->fetchColumn() === PasswordResetService::REQUEST_LIMIT, 'blocked password reset request creates no additional token');
$unknownDelivery = PasswordResetService::request($database, 'missing@example.test', static fn (): bool => true);
$assert(!$unknownDelivery, 'unknown account follows the same public success message path without creating a token');

$database->exec('UPDATE users SET must_change_password = 1 WHERE id=' . $userId);
$hashBeforeRejectedChange = (string) $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
$rejectedPolicyChange = AuthService::changePassword($database, ['id' => $userId, 'full_name' => 'Fixture One'], 'ChangedPass!234', 'weak', 'weak');
$assert(!$rejectedPolicyChange['ok'] && (int) $database->query('SELECT must_change_password FROM users WHERE id=' . $userId)->fetchColumn() === 1 && hash_equals($hashBeforeRejectedChange, (string) $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn()), 'failed password policy does not alter the password hash or forced-change flag');
$changeResult = AuthService::changePassword($database, ['id' => $userId, 'full_name' => 'Fixture One'], 'ChangedPass!234', 'Nw!Qx7R#v2Lma', 'Nw!Qx7R#v2Lma');
$changedPasswordAuditCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.password_changed' AND user_id=" . $userId)->fetchColumn();
$assert($changedPasswordAuditCount === 1, 'successful password change is recorded exactly once');
$assert($changeResult['ok'] && (int) $database->query('SELECT must_change_password FROM users WHERE id=' . $userId)->fetchColumn() === 0, 'password change succeeds with the correct current password and clears forced change');
$assert(password_verify('Nw!Qx7R#v2Lma', $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn()), 'password change stores a secure hash');
$changeToken = bin2hex(random_bytes(32));
$insertToken->execute(['user_id' => $userId, 'hash' => hash('sha256', $changeToken), 'expires' => gmdate('Y-m-d H:i:s', time() + 1800)]);
$changeResultAgain = AuthService::changePassword($database, ['id' => $userId, 'full_name' => 'Fixture One'], 'Nw!Qx7R#v2Lma', 'FinalPass!23456', 'FinalPass!23456');
$tokenAfterChange = $database->prepare('SELECT used_at FROM password_reset_tokens WHERE token_hash = :hash');
$tokenAfterChange->execute(['hash' => hash('sha256', $changeToken)]);
$assert($changeResultAgain['ok'] && $tokenAfterChange->fetchColumn() !== null, 'password change invalidates outstanding reset tokens');
$invalidChange = AuthService::changePassword($database, ['id' => $userId, 'full_name' => 'Fixture One'], 'wrong-current', 'BadPass!23456', 'BadPass!23456');
$assert(!$invalidChange['ok'], 'password change rejects an incorrect current password');
$database->exec('DELETE FROM login_attempts');
$historyInsert = $database->prepare('INSERT INTO login_attempts (email_normalized, ip_address, was_successful, attempted_at) VALUES (:email, :ip, :success, :attempted_at)');
$historyInsert->execute(['email' => 'one@example.test', 'ip' => '192.0.2.21', 'success' => 1, 'attempted_at' => '2026-10-25 10:00:00']);
$historyInsert->execute(['email' => 'one@example.test', 'ip' => '192.0.2.22', 'success' => 0, 'attempted_at' => '2026-10-25 11:00:00']);
$historyInsert->execute(['email' => 'two@example.test', 'ip' => '192.0.2.23', 'success' => 0, 'attempted_at' => '2026-10-25 12:00:00']);
$historyInsert->execute(['email' => 'one@example.test', 'ip' => '192.0.2.24', 'success' => 1, 'attempted_at' => '2026-10-20 09:00:00']);
for ($historyIndex = 0; $historyIndex < 20; $historyIndex++) {
    $historyInsert->execute(['email' => 'one@example.test', 'ip' => '192.0.2.' . (30 + $historyIndex), 'success' => 0, 'attempted_at' => '2026-10-20 09:00:00']);
}
$loginHistory = AuthService::loginHistory($database, ' ONE@example.test ', ['per_page' => 10]);
$loginHistorySecondPage = AuthService::loginHistory($database, ' ONE@example.test ', ['per_page' => 10, 'page' => 2]);
$filteredLoginHistory = AuthService::loginHistory($database, ' ONE@example.test ', ['q' => '192.0.2.21', 'outcome' => 'success', 'start_date' => '2026-10-25', 'end_date' => '2026-10-25', 'per_page' => 10]);
$invalidLoginHistoryDates = false;
try {
    AuthService::loginHistory($database, 'one@example.test', ['start_date' => '2026-10-26', 'end_date' => '2026-10-25']);
} catch (DomainException) {
    $invalidLoginHistoryDates = true;
}
$assert(count($loginHistory['rows']) === 10 && (int) $loginHistory['rows'][0]['was_successful'] === 0 && $loginHistory['rows'][0]['ip_address'] === '192.0.2.22' && !array_key_exists('password', $loginHistory['rows'][0]) && $loginHistory['pagination']['total'] === 23, 'login history is normalized, account-scoped, paginated, and contains no password data');
$assert(count($loginHistorySecondPage['rows']) === 10 && $loginHistorySecondPage['pagination']['page'] === 2 && $loginHistorySecondPage['rows'][0]['ip_address'] !== $loginHistory['rows'][0]['ip_address'], 'login history advances to a distinct authenticated-account page');
$assert($filteredLoginHistory['pagination']['total'] === 1 && $filteredLoginHistory['rows'][0]['ip_address'] === '192.0.2.21' && $filteredLoginHistory['pagination']['query']['outcome'] === 'success' && $invalidLoginHistoryDates, 'account sign-in history combines IP, outcome, and Manila date filters and rejects reversed dates');
$assert(AuthService::loginHistory($database, 'invalid-email')['rows'] === [], 'login history rejects malformed account identifiers');

$database->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'simulated audit failure'); END");
$originalHash = $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
$auditFailedChange = false;
try {
    AuthService::changePassword($database, ['id' => $userId, 'full_name' => 'Fixture One'], 'FinalPass!23456', 'RollbackPass!2345', 'RollbackPass!2345');
} catch (Throwable) {
    $auditFailedChange = true;
}
$hashAfterAuditFailure = $database->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
$assert($auditFailedChange && hash_equals($originalHash, $hashAfterAuditFailure), 'password change rolls back if its audit insert fails');

$database->exec('DROP TRIGGER fail_audit');
$_SESSION = ['user' => ['id' => $userId, 'role' => 'student'], 'csrf_token' => 'logout-test'];
$logoutAuditBefore = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.logout' AND user_id=" . $userId)->fetchColumn();
AuthService::logout($database, ['id' => $userId, 'full_name' => 'Fixture One']);
$logoutAuditAfter = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.logout' AND user_id=" . $userId)->fetchColumn();
$assert($logoutAuditAfter === $logoutAuditBefore + 1, 'successful logout writes one audit row');
$database->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'simulated audit failure'); END");
$_SESSION = ['user' => ['id' => $userId, 'role' => 'student'], 'csrf_token' => 'logout-test'];
AuthService::logout($database, ['id' => $userId, 'full_name' => 'Fixture One']);
$assert($_SESSION === [] && session_status() !== PHP_SESSION_ACTIVE, 'logout destroys the session even when the audit write fails');

$workflowDb->exec("INSERT INTO applications (id,student_id,status,deleted_at) VALUES (9901,1,'draft','2026-11-06 00:00:00'),(9902,1,'draft','2026-11-06 00:00:00'),(9903,2,'draft','2026-11-06 00:00:00')");
AuditLog::record($workflowDb, ['id' => 1, 'full_name' => 'Draft Student'], 'application.draft_discarded', 'application', 9901);
AuditLog::record($workflowDb, ['id' => 5, 'full_name' => 'Administrator'], 'application.archived', 'application', 9902);
AuditLog::record($workflowDb, ['id' => 2, 'full_name' => 'Review Student'], 'application.draft_discarded', 'application', 9903);
$adminArchiveRows = StaffService::archivedApplications($workflowDb, ['id' => 5, 'role' => 'admin'], [])['rows'];
$adminArchiveIds = array_map('intval', array_column($adminArchiveRows, 'id'));
$assert(in_array(9901, $adminArchiveIds, true) && in_array(9902, $adminArchiveIds, true) && in_array(9903, $adminArchiveIds, true), 'admin archive includes existing archived records regardless of their historical archive actor');

fwrite(STDOUT, sprintf("Security tests: %d assertions, %d failures\n", $tests, count($failures)));
foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
exit($failures === [] ? 0 : 1);

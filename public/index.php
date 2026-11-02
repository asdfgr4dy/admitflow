<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/AuthService.php';
require dirname(__DIR__) . '/app/ApplicationService.php';
require dirname(__DIR__) . '/app/AnnouncementService.php';
require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/app/ReportExport.php';
require dirname(__DIR__) . '/app/PasswordResetService.php';
require dirname(__DIR__) . '/app/BackupIntegrity.php';
require dirname(__DIR__) . '/app/BackupService.php';
require dirname(__DIR__) . '/app/AutomatedBackupRunner.php';
require dirname(__DIR__) . '/app/NotificationService.php';
require dirname(__DIR__) . '/app/AccountSettingsService.php';
require dirname(__DIR__) . '/app/StaffAccountService.php';
require dirname(__DIR__) . '/app/AvatarService.php';
require dirname(__DIR__) . '/app/LoginRateLimitPolicy.php';
require dirname(__DIR__) . '/app/StatusTransitionPolicy.php';
require dirname(__DIR__) . '/app/UploadPolicy.php';
require_once dirname(__DIR__) . '/app/AuditLog.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; img-src 'self' data: blob:; frame-src 'self' blob:");
header('Cache-Control: no-store');
$requestHost = strtolower((string) parse_url('//' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
if ($isHttps && (getenv('ADMITFLOW_APP_ENV') ?: '') !== 'local' && !in_array($requestHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $database = Database::connection();
    refresh_authenticated_user($database);

    if ($method === 'POST') {
        verify_csrf();

        if ($path === '/register') {
            $result = AuthService::register($database, $_POST);
            if ($result['ok']) {
                flash('success', $result['message']);
                redirect('/login');
            }
            render_page('auth/register', ['title' => 'Create your account', 'result' => $result, 'values' => $_POST]);
            exit;
        }

        if ($path === '/login') {
            $result = AuthService::login($database, $_POST);
            if ($result['ok']) {
                redirect(portal_path_for_user(current_user()));
            }
            render_page('auth/login', [
                'title' => 'Sign in',
                'result' => $result,
                'captcha' => AuthService::newCaptcha(),
                'email' => (string) ($_POST['email'] ?? ''),
                'cooldownSeconds' => (int) ($result['cooldown_seconds'] ?? 0),
            ]);
            exit;
        }

        if ($path === '/forgot-password') {
            PasswordResetService::request($database, (string) ($_POST['email'] ?? ''));
            flash('success', 'If an account exists for that email address, reset instructions will be sent.');
            redirect('/forgot-password');
        }

        if ($path === '/logout') {
            $user = current_user();
            if ($user !== null) {
                AuthService::logout($database, $user);
            }
            redirect('/');
        }

        if ($path === '/application/save') {
            $user = require_student();
            $existingApplication = ApplicationService::current($database, (int) $user['id']);
            if ($existingApplication === null) {
                $profileGate = AccountSettingsService::applicationStartGate($database, (int) $user['id']);
                if (!$profileGate['allowed']) {
                    flash('warning', 'Complete your applicant profile before starting an application. Missing: ' . implode(', ', array_values($profileGate['missing'])) . '.');
                    redirect('/settings/profile?complete_application=1');
                }
            }
            $action = (string) ($_POST['action'] ?? 'save');
            $step = $action === 'step' ? filter_var($_POST['wizard_step'] ?? null, FILTER_VALIDATE_INT) : null;
            $progressKey = 'application_progress_' . (int) $user['id'];
            $furthestStep = max(0, min((int) ($_SESSION[$progressKey] ?? 0), 3));
            if (($action === 'step' && ($step === false || !ApplicationService::canProgressToStep($step, $furthestStep))) || ($action === 'submit' && !ApplicationService::canSubmitAtStep($furthestStep))) {
                $result = ['ok' => false, 'step' => $furthestStep, 'errors' => [], 'message' => 'Complete each application step in order before continuing.'];
            } else {
                $result = ApplicationService::save($database, (int) $user['id'], $_POST, $_FILES['documents'] ?? [], $action === 'submit', $step === false ? -1 : $step);
                if ($result['ok'] && $action === 'step' && is_int($step)) {
                    $_SESSION[$progressKey] = min(3, max($furthestStep, $step + 1));
                }
            }
            $_SESSION['application_errors'] = $result['errors'] ?? [];
            if (!$result['ok']) {
                $_SESSION['application_values'] = array_intersect_key($_POST, array_flip([
                    'full_name', 'mobile_number', 'date_of_birth', 'address_line', 'municipality', 'province',
                    'emergency_contact_name', 'emergency_contact_number', 'academic_program_id', 'academic_year_id',
                    'preferred_campus', 'previous_school', 'senior_high_strand', 'privacy_consent',
                ]));
            } else {
                unset($_SESSION['application_values']);
            }
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            $nextStep = (int) ($result['step'] ?? 0);
            redirect('/application?step=' . max(0, min($nextStep, 3)));
        }

        if ($path === '/reset-password') {
            $result = PasswordResetService::reset($database, (string) ($_POST['token'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''));
            if ($result['ok']) {
                flash('success', $result['message']);
                redirect('/login');
            }
            render_page('auth/reset-password', ['title' => 'Reset password', 'result' => $result, 'token' => (string) ($_POST['token'] ?? '')]);
            exit;
        }

        if ($path === '/account/password') {
            $user = current_user();
            if ($user === null) {
                redirect('/login');
            }
            $result = AuthService::changePassword($database, $user, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''));
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            redirect('/account/password');
        }

        if ($path === '/settings/profile') {
            $user = current_user();
            if ($user === null) {
                redirect('/login');
            }
            $result = $user['role'] === 'student'
                ? AccountSettingsService::updateApplicantProfile($database, $user, $_POST)
                : AccountSettingsService::updateProfileName($database, $user, (string) ($_POST['full_name'] ?? ''));
            if ($result['ok']) {
                $_SESSION['user']['full_name'] = $result['full_name'];
                unset($_SESSION['profile_form_name'], $_SESSION['profile_form_error'], $_SESSION['applicant_profile_form_values'], $_SESSION['applicant_profile_form_errors']);
            } else {
                $_SESSION['profile_form_name'] = (string) ($_POST['full_name'] ?? '');
                $_SESSION['profile_form_error'] = $result['message'];
                if ($user['role'] === 'student') {
                    $_SESSION['applicant_profile_form_values'] = $result['values'] ?? array_intersect_key($_POST, array_flip(array_keys(AccountSettingsService::applicantProfileFields())));
                    $_SESSION['applicant_profile_form_errors'] = $result['errors'] ?? [];
                }
            }
            flash($result['ok'] ? 'success' : 'warning', $result['message']);
            redirect('/settings/profile');
        }

        if ($path === '/settings/avatar') {
            $user = current_user();
            if ($user === null) redirect('/login');
            try {
                if (($_POST['action'] ?? 'upload') === 'remove') {
                    AvatarService::remove((int) $user['id']);
                    AuditLog::record($database, $user, 'account.avatar_removed', 'user', (int) $user['id'], 'success', [], 'notice');
                    flash('success', 'Profile picture removed.');
                } else {
                    AvatarService::store((int) $user['id'], $_FILES['avatar'] ?? []);
                    AuditLog::record($database, $user, 'account.avatar_updated', 'user', (int) $user['id'], 'success', [], 'notice');
                    flash('success', 'Profile picture updated.');
                }
            } catch (DomainException $error) {
                AuditLog::record($database, $user, 'account.avatar_change_failed', 'user', (int) $user['id'], 'failure', ['reason' => $error->getMessage()], 'warning');
                flash('warning', $error->getMessage());
            }
            redirect('/settings/profile');
        }

        if (in_array($path, ['/notifications/read', '/notifications/read-all', '/notifications/dismiss-derived', '/notifications/dismiss-derived-all'], true)) {
            $user = current_user();
            if ($user === null || !in_array($user['role'], ['student', 'reviewer', 'admin'], true)) {
                http_response_code(403);
                exit('Sign in to manage notifications.');
            }
            $dismissed = is_array($_SESSION['dismissed_derived_notifications'] ?? null) ? $_SESSION['dismissed_derived_notifications'] : [];
            $actionSucceeded = false;
            $actionMessage = 'Notification updated.';
            if ($path === '/notifications/read') {
                $actionSucceeded = NotificationService::markRead($database, $user, (int) ($_POST['notification_id'] ?? 0));
                $actionMessage = 'Notification marked as read.';
            } elseif ($path === '/notifications/read-all') {
                NotificationService::markAllRead($database, $user);
                NotificationService::dismissAllDerived($database, $user, $dismissed);
                $actionSucceeded = true;
                $actionMessage = 'Notifications marked as read.';
            } elseif ($path === '/notifications/dismiss-derived') {
                $actionSucceeded = NotificationService::dismissDerived($database, $user, (string) ($_POST['key'] ?? ''), $dismissed);
                $actionMessage = 'Update dismissed.';
            } else {
                NotificationService::dismissAllDerived($database, $user, $dismissed);
                $actionSucceeded = true;
                $actionMessage = 'Updates dismissed.';
            }
            $_SESSION['dismissed_derived_notifications'] = array_values(array_unique($dismissed));
            if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                header('Content-Type: application/json; charset=utf-8');
                if (!$actionSucceeded) {
                    http_response_code(404);
                }
                echo json_encode(['ok' => $actionSucceeded, 'message' => $actionSucceeded ? $actionMessage : 'This notification could not be updated.']);
                exit;
            }
            flash($actionSucceeded ? 'success' : 'warning', $actionSucceeded ? $actionMessage : 'This notification could not be updated.');
            redirect(match ($user['role']) {
                'student' => '/portal',
                'admin' => '/admin',
                default => '/staff',
            });
        }

        if (str_starts_with($path, '/staff/')) {
            $user = current_user();
            if ($user === null) {
                redirect('/login');
            }
            if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
                if ($path === '/staff/user/delete') {
                    AuditLog::record($database, $user, 'user.account_delete_blocked', 'user', (int) ($_POST['user_id'] ?? 0) ?: null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
                } elseif (in_array($path, ['/staff/application/archive', '/staff/application/restore'], true)) {
                    $isRestoreAttempt = $path === '/staff/application/restore';
                    AuditLog::record($database, $user, $isRestoreAttempt ? 'application.restore_blocked' : 'application.archive_blocked', 'application', (int) ($_POST['application_id'] ?? 0) ?: null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
                } elseif ($path === '/staff/announcement/delete') {
                    AuditLog::record($database, $user, 'announcement.delete_blocked', 'announcement', (int) ($_POST['announcement_id'] ?? 0) ?: null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
                } elseif ($path === '/staff/academic-setting/delete') {
                    AuditLog::record($database, $user, 'academic_setting.delete_blocked', 'academic_setting', null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
                }
                http_response_code(403);
                exit('Admissions staff access is required.');
            }
            try {
                if ($path === '/staff/user/delete') {
                    if ($user['role'] !== 'admin') {
                        AuditLog::record($database, $user, 'user.account_delete_blocked', 'user', (int) ($_POST['user_id'] ?? 0) ?: null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
                        throw new DomainException('Administrator access is required to delete accounts.');
                    }
                    $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($targetUserId === false) throw new DomainException('Choose a valid account to delete.');
                    $targetRoleQuery = $database->prepare('SELECT role FROM users WHERE id = :id');
                    $targetRoleQuery->execute(['id' => $targetUserId]);
                    $targetRole = (string) ($targetRoleQuery->fetchColumn() ?: 'reviewer');
                    try {
                        StaffAccountService::delete($database, $user, (int) $targetUserId);
                    } catch (DomainException $error) {
                        flash('warning', $error->getMessage() . ' Use Deactivate instead when history exists.');
                        redirect(match ($targetRole) {
                            'admin' => '/staff/users/administrators',
                            'student' => '/staff/users/students',
                            default => '/staff/users/staff',
                        });
                    }
                    flash('success', 'Account deleted permanently. Its admission and audit history was preserved where applicable.');
                    redirect(match ($targetRole) {
                        'admin' => '/staff/users/administrators',
                        'student' => '/staff/users/students',
                        default => '/staff/users/staff',
                    });
                }
                if ($path === '/staff/application/archive' || $path === '/staff/application/restore') {
                    if ($user['role'] !== 'admin') {
                        $restoreAttempt = $path === '/staff/application/restore';
                        AuditLog::record($database, $user, $restoreAttempt ? 'application.restore_blocked' : 'application.archive_blocked', 'application', (int) ($_POST['application_id'] ?? 0) ?: null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
                        throw new DomainException('Administrator access is required to archive or restore applications.');
                    }
                    $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($applicationId === false) throw new DomainException('Choose a valid application.');
                    $isRestore = $path === '/staff/application/restore';
                    try {
                        if ($isRestore) StaffService::restoreApplication($database, $user, (int) $applicationId);
                        else StaffService::archiveApplication($database, $user, (int) $applicationId);
                    } catch (DomainException $error) {
                        flash('warning', $error->getMessage());
                        redirect('/admin/applications?archived=' . ($isRestore ? 'archived' : 'active'));
                    }
                    flash('success', $isRestore ? 'Application restored to active queues.' : 'Application archived. Its history and documents were retained.');
                    redirect('/admin/applications?archived=active');
                }
                if ($path === '/staff/program/save') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to manage programs.');
                    }
                    $programId = (int) ($_POST['program_id'] ?? 0);
                    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $description = trim((string) ($_POST['description'] ?? ''));
                    if (!preg_match('/^[A-Z0-9_-]{2,12}$/', $code) || $name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 500) {
                        throw new DomainException('Enter a program code of 2 to 12 characters, a name of 100 characters or fewer, and a description of 500 characters or fewer.');
                    }
                    $duplicateName = $database->prepare('SELECT COUNT(*) FROM academic_programs WHERE LOWER(name) = LOWER(:name) AND id <> :id');
                    $duplicateName->execute(['name' => $name, 'id' => $programId]);
                    if ((int) $duplicateName->fetchColumn() > 0) throw new DomainException('That program name is already used by another program.');
                    $previousProgram = null;
                    if ($programId > 0) {
                        $previousQuery = $database->prepare('SELECT code, name, description, is_active FROM academic_programs WHERE id = :id');
                        $previousQuery->execute(['id' => $programId]);
                        $previousProgram = $previousQuery->fetch();
                        if (!is_array($previousProgram)) throw new DomainException('That program could not be found.');
                    }
                    $database->beginTransaction();
                    try {
                        if ($programId > 0) {
                            $save = $database->prepare('UPDATE academic_programs SET code = :code, name = :name, description = :description, is_active = :active WHERE id = :id');
                            $save->execute(['code' => $code, 'name' => $name, 'description' => $description !== '' ? $description : null, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0, 'id' => $programId]);
                            $isActive = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
                            $action = (int) $previousProgram['is_active'] !== $isActive ? ($isActive ? 'program.activated' : 'program.deactivated') : 'program.updated';
                        } else {
                            $save = $database->prepare('INSERT INTO academic_programs (code, name, description, is_active) VALUES (:code, :name, :description, :active)');
                            $save->execute(['code' => $code, 'name' => $name, 'description' => $description !== '' ? $description : null, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0]);
                            $programId = (int) $database->lastInsertId();
                            $action = 'program.created';
                        }
                        AuditLog::record($database, $user, $action, 'academic_program', $programId, 'success', ['before' => $previousProgram, 'after' => ['code' => $code, 'name' => $name, 'is_active' => ($_POST['is_active'] ?? '') === '1']]);
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) {
                            $database->rollBack();
                        }
                        if ($error instanceof PDOException && $error->getCode() === '23000') {
                            throw new DomainException('That program code is already in use.');
                        }
                        throw $error;
                    }
                    flash('success', $action === 'program.activated' ? 'Program activated.' : ($action === 'program.deactivated' ? 'Program deactivated. Existing applications remain unchanged.' : 'Program settings saved.'));
                    redirect('/staff/academic/programs?updated_id=' . $programId);
                }
                if ($path === '/staff/academic-setting/delete') {
                    if ($user['role'] !== 'admin') {
                        AuditLog::record($database, $user, 'academic_setting.delete_blocked', 'academic_setting', null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
                        throw new DomainException('Administrator access is required to delete academic settings.');
                    }
                    $settingType = (string) ($_POST['setting_type'] ?? '');
                    $settingIdField = [
                        'program' => 'program_id',
                        'year' => 'year_id',
                        'requirement' => 'requirement_id',
                    ][$settingType] ?? null;
                    if ($settingIdField === null) {
                        throw new DomainException('Choose a valid academic setting to delete.');
                    }
                    $settingId = filter_var($_POST[$settingIdField] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($settingId === false) {
                        throw new DomainException('Choose a valid academic setting to delete.');
                    }
                    $setting = [
                        'program' => ['table' => 'academic_programs', 'entity' => 'academic_program', 'label' => 'program', 'usage' => 'SELECT (SELECT COUNT(*) FROM applications WHERE academic_program_id = :application_id) + (SELECT COUNT(*) FROM admission_requirements WHERE academic_program_id = :requirement_program_id)', 'usage_parameters' => ['application_id' => $settingId, 'requirement_program_id' => $settingId]],
                        'year' => ['table' => 'academic_years', 'entity' => 'academic_year', 'label' => 'academic year', 'usage' => 'SELECT (SELECT COUNT(*) FROM applications WHERE academic_year_id = :application_year_id) + (SELECT COUNT(*) FROM admission_requirements WHERE academic_year_id = :requirement_year_id)', 'usage_parameters' => ['application_year_id' => $settingId, 'requirement_year_id' => $settingId]],
                        'requirement' => ['table' => 'admission_requirements', 'entity' => 'admission_requirement', 'label' => 'requirement', 'usage' => 'SELECT COUNT(*) FROM application_documents WHERE requirement_id = :document_requirement_id', 'usage_parameters' => ['document_requirement_id' => $settingId]],
                    ][$settingType];
                    $database->beginTransaction();
                    try {
                        $lock = $database->prepare('SELECT id FROM ' . $setting['table'] . ' WHERE id = :id' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                        $lock->execute(['id' => $settingId]);
                        if (!$lock->fetchColumn()) {
                            throw new DomainException('That ' . $setting['label'] . ' no longer exists.');
                        }
                        $usage = $database->prepare($setting['usage']);
                        $usage->execute($setting['usage_parameters']);
                        if ((int) $usage->fetchColumn() > 0) {
                            throw new DomainException('This ' . $setting['label'] . ' is in use and cannot be deleted. Deactivate it instead.');
                        }
                        $delete = $database->prepare('DELETE FROM ' . $setting['table'] . ' WHERE id = :id');
                        $delete->execute(['id' => $settingId]);
                        AuditLog::record($database, $user, $setting['entity'] . '.deleted', $setting['entity'], $settingId, 'success', [], 'notice');
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) {
                            $database->rollBack();
                        }
                        if ($error instanceof DomainException) {
                            AuditLog::record($database, $user, $setting['entity'] . '.delete_blocked', $setting['entity'], $settingId, 'warning', ['reason' => $error->getMessage()], 'warning');
                        }
                        if ($error instanceof PDOException && $error->getCode() === '23000') {
                            throw new DomainException('This ' . $setting['label'] . ' is in use and cannot be deleted. Deactivate it instead.');
                        }
                        throw $error;
                    }
                    flash('success', ucfirst($setting['label']) . ' deleted.');
                    redirect(match ($settingType) {
                        'requirement' => '/staff/academic/requirements',
                        'year' => '/staff/academic/years',
                        default => '/staff/academic/programs',
                    });
                }
                if ($path === '/staff/year/save') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to manage academic years.');
                    }
                    $yearId = (int) ($_POST['year_id'] ?? 0);
                    $label = trim((string) ($_POST['label'] ?? ''));
                    $startsOn = (string) ($_POST['starts_on'] ?? '');
                    $endsOn = (string) ($_POST['ends_on'] ?? '');
                    $deadline = (string) ($_POST['application_deadline'] ?? '');
                    $validDate = static function (string $value): bool {
                        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                        return $date !== false && $date->format('Y-m-d') === $value;
                    };
                    if ($label === '' || mb_strlen($label) > 20 || !$validDate($startsOn) || !$validDate($endsOn) || $endsOn <= $startsOn || ($deadline !== '' && !$validDate($deadline))) {
                        throw new DomainException('Enter a valid label, date range, and optional deadline.');
                    }
                    $previousYear = null;
                    if ($yearId > 0) {
                        $previousQuery = $database->prepare('SELECT label, starts_on, ends_on, application_deadline, is_open FROM academic_years WHERE id = :id');
                        $previousQuery->execute(['id' => $yearId]);
                        $previousYear = $previousQuery->fetch();
                        if (!is_array($previousYear)) throw new DomainException('That academic year could not be found.');
                    }
                    $database->beginTransaction();
                    try {
                        if (($_POST['is_open'] ?? '') === '1') {
                            $database->exec('UPDATE academic_years SET is_open = 0');
                        }
                        if ($yearId > 0) {
                            $save = $database->prepare('UPDATE academic_years SET label = :label, starts_on = :starts_on, ends_on = :ends_on, application_deadline = :deadline, is_open = :is_open WHERE id = :id');
                            $save->execute(['label' => $label, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'deadline' => $deadline ?: null, 'is_open' => ($_POST['is_open'] ?? '') === '1' ? 1 : 0, 'id' => $yearId]);
                            $isOpen = ($_POST['is_open'] ?? '') === '1' ? 1 : 0;
                            $action = (int) $previousYear['is_open'] !== $isOpen ? 'academic_year.current_changed' : 'academic_year.updated';
                        } else {
                            $save = $database->prepare('INSERT INTO academic_years (label, starts_on, ends_on, application_deadline, is_open) VALUES (:label, :starts_on, :ends_on, :deadline, :is_open)');
                            $save->execute(['label' => $label, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'deadline' => $deadline ?: null, 'is_open' => ($_POST['is_open'] ?? '') === '1' ? 1 : 0]);
                            $yearId = (int) $database->lastInsertId();
                            $action = 'academic_year.created';
                        }
                        AuditLog::record($database, $user, $action, 'academic_year', $yearId, 'success', ['before' => $previousYear, 'after' => ['label' => $label, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'application_deadline' => $deadline ?: null, 'is_open' => ($_POST['is_open'] ?? '') === '1']]);
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) $database->rollBack();
                        if ($error instanceof PDOException && $error->getCode() === '23000') {
                            throw new DomainException('That academic year label already exists.');
                        }
                        throw $error;
                    }
                    flash('success', $action === 'academic_year.current_changed' ? 'Academic year and current intake updated. Existing applications keep their assigned year.' : 'Academic year settings saved.');
                    redirect('/staff/academic/years?updated_id=' . $yearId);
                }
                if ($path === '/staff/requirement/save') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to manage admission requirements.');
                    }
                    $requirementId = (int) ($_POST['requirement_id'] ?? 0);
                    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $extensions = strtolower(trim((string) ($_POST['allowed_extensions'] ?? '')));
                    $maxBytes = filter_var($_POST['max_file_size_bytes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 52428800]]);
                    $programId = ($_POST['academic_program_id'] ?? '') !== '' ? filter_var($_POST['academic_program_id'], FILTER_VALIDATE_INT) : null;
                    $yearId = ($_POST['academic_year_id'] ?? '') !== '' ? filter_var($_POST['academic_year_id'], FILTER_VALIDATE_INT) : null;
                    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
                    $extensionList = array_values(array_unique(array_filter(array_map('trim', explode(',', $extensions)))));
                    $instructions = trim((string) ($_POST['instructions'] ?? ''));
                    if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $code) || $name === '' || mb_strlen($name) > 160 || mb_strlen($instructions) > 1000 || $maxBytes === false || $extensionList === [] || array_diff($extensionList, $allowedExtensions) !== []) {
                        throw new DomainException('Enter a valid requirement name, code, file type, and size limit.');
                    }
                    $previousRequirement = null;
                    if ($requirementId > 0) {
                        $previousQuery = $database->prepare('SELECT code, name, instructions, academic_program_id, academic_year_id, allowed_extensions, max_file_size_bytes, is_required, is_active FROM admission_requirements WHERE id = :id');
                        $previousQuery->execute(['id' => $requirementId]);
                        $previousRequirement = $previousQuery->fetch();
                        if (!is_array($previousRequirement)) throw new DomainException('That requirement could not be found.');
                    }
                    if ($programId !== null) {
                        $programCheck = $database->prepare('SELECT is_active FROM academic_programs WHERE id = :id');
                        $programCheck->execute(['id' => $programId]);
                        $programActive = $programCheck->fetchColumn();
                        if ($programActive === false || (!(bool) $programActive && (int) ($previousRequirement['academic_program_id'] ?? 0) !== $programId)) throw new DomainException('Choose an active academic program for this requirement.');
                    }
                    if ($yearId !== null) {
                        $yearCheck = $database->prepare('SELECT is_open FROM academic_years WHERE id = :id');
                        $yearCheck->execute(['id' => $yearId]);
                        $yearOpen = $yearCheck->fetchColumn();
                        if ($yearOpen === false || (!(bool) $yearOpen && (int) ($previousRequirement['academic_year_id'] ?? 0) !== $yearId)) throw new DomainException('Choose an open academic year for this requirement.');
                    }
                    $database->beginTransaction();
                    try {
                        if ($requirementId > 0) {
                            $save = $database->prepare('UPDATE admission_requirements SET code = :code, name = :name, instructions = :instructions, academic_program_id = :program_id, academic_year_id = :year_id, allowed_extensions = :extensions, max_file_size_bytes = :max_bytes, is_required = :required, is_active = :active WHERE id = :id');
                            $save->execute(['code' => $code, 'name' => $name, 'instructions' => $instructions ?: null, 'program_id' => $programId, 'year_id' => $yearId, 'extensions' => implode(',', $extensionList), 'max_bytes' => $maxBytes, 'required' => ($_POST['is_required'] ?? '') === '1' ? 1 : 0, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0, 'id' => $requirementId]);
                            $isActive = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
                            $action = (int) $previousRequirement['is_active'] !== $isActive ? ($isActive ? 'requirement.activated' : 'requirement.deactivated') : 'requirement.updated';
                        } else {
                            $save = $database->prepare('INSERT INTO admission_requirements (code, name, instructions, academic_program_id, academic_year_id, allowed_extensions, max_file_size_bytes, is_required, is_active) VALUES (:code, :name, :instructions, :program_id, :year_id, :extensions, :max_bytes, :required, :active)');
                            $save->execute(['code' => $code, 'name' => $name, 'instructions' => $instructions ?: null, 'program_id' => $programId, 'year_id' => $yearId, 'extensions' => implode(',', $extensionList), 'max_bytes' => $maxBytes, 'required' => ($_POST['is_required'] ?? '') === '1' ? 1 : 0, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0]);
                            $requirementId = (int) $database->lastInsertId();
                            $action = 'requirement.created';
                        }
                        AuditLog::record($database, $user, $action, 'admission_requirement', $requirementId, 'success', ['before' => $previousRequirement, 'after' => ['code' => $code, 'name' => $name, 'academic_program_id' => $programId, 'academic_year_id' => $yearId, 'allowed_extensions' => implode(',', $extensionList), 'max_file_size_bytes' => $maxBytes, 'is_required' => ($_POST['is_required'] ?? '') === '1', 'is_active' => ($_POST['is_active'] ?? '') === '1']]);
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) $database->rollBack();
                        if ($error instanceof PDOException && $error->getCode() === '23000') throw new DomainException('That requirement code already exists in the selected scope.');
                        throw $error;
                    }
                    flash('success', $action === 'requirement.activated' ? 'Requirement activated.' : ($action === 'requirement.deactivated' ? 'Requirement deactivated. Previously submitted documents and reviews are preserved.' : 'Admission requirement saved.'));
                    redirect('/staff/academic/requirements?updated_id=' . $requirementId);
                }
                if ($path === '/staff/announcement/delete') {
                    AnnouncementService::delete($database, $user, (int) ($_POST['announcement_id'] ?? 0));
                    flash('success', 'Announcement deleted.');
                    redirect('/staff/announcements');
                }
                if ($path === '/staff/announcement/save') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to manage announcements.');
                    }
                    $announcementId = (int) ($_POST['announcement_id'] ?? 0);
                    $isNewAnnouncement = $announcementId < 1;
                    $validation = AnnouncementService::validateSaveInput($_POST, $isNewAnnouncement);
                    if (!$validation['ok']) {
                        if ($isNewAnnouncement) {
                            $_SESSION['announcement_form_values'] = $validation['form_values'];
                            $_SESSION['announcement_form_errors'] = $validation['errors'];
                        }
                        throw new DomainException('Please correct the highlighted announcement fields.');
                    }
                    $title = $validation['values']['title'];
                    $body = $validation['values']['body'];
                    $audience = $validation['values']['audience'];
                    $startsUtc = $validation['values']['starts_at'];
                    $endsUtc = $validation['values']['ends_at'];
                    $isPublished = $validation['values']['is_published'];
                    $database->beginTransaction();
                    try {
                        if ($announcementId > 0) {
                            $save = $database->prepare('UPDATE announcements SET title = :title, body = :body, audience = :audience, is_published = :published, starts_at = :starts_at, ends_at = :ends_at WHERE id = :id');
                            $save->execute(['title' => $title, 'body' => $body, 'audience' => $audience, 'published' => $isPublished, 'starts_at' => $startsUtc, 'ends_at' => $endsUtc, 'id' => $announcementId]);
                            $action = 'announcement.updated';
                        } else {
                            $save = $database->prepare('INSERT INTO announcements (title, body, audience, is_published, starts_at, ends_at, created_by) VALUES (:title, :body, :audience, :published, :starts_at, :ends_at, :created_by)');
                            $save->execute(['title' => $title, 'body' => $body, 'audience' => $audience, 'published' => $isPublished, 'starts_at' => $startsUtc, 'ends_at' => $endsUtc, 'created_by' => $user['id']]);
                            $announcementId = (int) $database->lastInsertId();
                            $action = 'announcement.created';
                        }
                        AuditLog::record($database, $user, $action, 'announcement', $announcementId, 'success', ['audience' => $audience, 'is_published' => $isPublished]);
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) $database->rollBack();
                        throw $error;
                    }
                    flash('success', 'Announcement saved.');
                    redirect('/staff/announcements');
                }
                if ($path === '/staff/user/save') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to manage accounts.');
                    }
                    $userId = (int) ($_POST['user_id'] ?? 0);
                    if ($userId === 0) {
                        $createdAccount = StaffAccountService::create($database, $user, (string) ($_POST['full_name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['role'] ?? ''));
                        $_SESSION['staff_setup_link'] = $createdAccount['setup_path'];
                        flash('success', 'Staff account created. Share the one-time password setup link securely within 30 minutes. No email was sent.');
                        redirect('/staff/users/staff');
                    } else {
                        $targetQuery = $database->prepare('SELECT id, role, account_status FROM users WHERE id = :id');
                        $targetQuery->execute(['id' => $userId]);
                        $target = $targetQuery->fetch();
                        if (!is_array($target)) {
                            throw new DomainException('That account could not be found.');
                        }
                        $newRole = (string) ($_POST['role'] ?? '');
                        $newStatus = (string) ($_POST['account_status'] ?? '');
                        if (!in_array($newRole, ['student', 'reviewer', 'admin'], true) || !in_array($newStatus, ['active', 'inactive', 'locked'], true)) {
                            throw new DomainException('Choose a valid account role and status.');
                        }
                        if ((int) $target['id'] === (int) $user['id'] && ($newRole !== 'admin' || $newStatus !== 'active')) {
                            $activeAdmins = (int) $database->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'active'")->fetchColumn();
                            if ($target['role'] === 'admin' && $target['account_status'] === 'active' && $activeAdmins <= 1) {
                                throw new DomainException('You cannot remove the final active administrator privilege from your own account.');
                            }
                        }
                        if ($target['role'] === 'admin' && $target['account_status'] === 'active' && ($newRole !== 'admin' || $newStatus !== 'active')) {
                            $activeAdmins = (int) $database->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'active'")->fetchColumn();
                            if ($activeAdmins <= 1) {
                                throw new DomainException('At least one active administrator account must remain.');
                            }
                        }
                        $database->beginTransaction();
                        $update = $database->prepare('UPDATE users SET role = :role, account_status = :account_status WHERE id = :id');
                        $update->execute(['role' => $newRole, 'account_status' => $newStatus, 'id' => $userId]);
                        if ($newRole === 'student') {
                            $profile = $database->prepare('INSERT IGNORE INTO student_profiles (user_id) VALUES (:user_id)');
                            $profile->execute(['user_id' => $userId]);
                        }
                        $action = 'user.role_or_status_changed';
                    }
                    AuditLog::record($database, $user, $action, 'user', $userId, 'success', ['role' => $newRole, 'account_status' => $newStatus], 'notice');
                    $database->commit();
                    flash('success', 'Account role and status saved.');
                        redirect(match ($newRole) {
                            'admin' => '/staff/users/administrators',
                            'student' => '/staff/users/students',
                            default => '/staff/users/staff',
                        });
                }
                if (in_array($path, ['/staff/backup/database', '/staff/backup/files'], true)) {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to create backups.');
                    }
                    $backupContext = [
                        'request_id' => bin2hex(random_bytes(12)),
                        'type' => $path === '/staff/backup/database' ? 'database' : 'files',
                        'stage' => 'request-authorized',
                        'started_at' => gmdate(DATE_ATOM),
                        'started_clock' => microtime(true),
                        'output_committed' => false,
                    ];
                    $GLOBALS['admitflow_backup_context'] = $backupContext;
                    BackupService::logRequestEvent($backupContext + ['event' => 'started']);
                    register_shutdown_function(static function (): void {
                        $fatal = error_get_last();
                        $context = $GLOBALS['admitflow_backup_context'] ?? null;
                        if (!is_array($context)) return;
                        if (is_array($fatal) && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                            BackupService::logRequestEvent($context + [
                                'event' => 'fatal',
                                'elapsed_seconds' => (int) (microtime(true) - $context['started_clock']),
                                'fatal_type' => $fatal['type'],
                                'fatal_line' => (int) ($fatal['line'] ?? 0),
                            ]);
                            if (empty($context['output_committed']) && isset($context['output_path']) && is_string($context['output_path'])) {
                                $backupRoot = realpath(dirname(__DIR__) . '/storage/backups');
                                $outputPath = $context['output_path'];
                                $outputDirectory = realpath(dirname($outputPath));
                                $expectedPrefix = $context['type'] === 'files' ? 'files-' : 'database-';
                                if ($backupRoot !== false && $outputDirectory === $backupRoot
                                    && preg_match('/^' . preg_quote($expectedPrefix, '/') . '\\d{8}-\\d{6}-[a-f0-9]{12}\\.(?:tar|sql)$/', basename($outputPath))
                                    && is_file($outputPath)) {
                                    $removed = @unlink($outputPath);
                                    BackupService::logRequestEvent($context + ['event' => $removed ? 'fatal-output-cleaned' : 'fatal-output-cleanup-failed']);
                                }
                            }
                            return;
                        }
                        $context['stage'] = 'request-finished';
                    });
                    try {
                        $GLOBALS['admitflow_backup_context']['stage'] = 'service-call';
                        if ($path === '/staff/backup/database') {
                            BackupService::createDatabase($database, $user);
                        } else {
                            BackupService::createFiles($database, $user);
                        }
                        $GLOBALS['admitflow_backup_context']['stage'] = 'service-completed';
                        BackupService::logRequestEvent($GLOBALS['admitflow_backup_context'] + [
                            'event' => 'completed',
                            'elapsed_seconds' => (int) (microtime(true) - $backupContext['started_clock']),
                        ]);
                        flash('success', 'Backup created and verified.');
                    } catch (Throwable $error) {
                        $failureContext = $GLOBALS['admitflow_backup_context'] ?? $backupContext;
                        BackupService::logRequestEvent($failureContext + [
                            'event' => 'failed',
                            'elapsed_seconds' => (int) (microtime(true) - $failureContext['started_clock']),
                            'exception_class' => $error::class,
                            'diagnostic' => $error->getMessage(),
                        ]);
                        flash('warning', 'Backup did not complete or could not be verified. No successful backup is recorded.');
                    } finally {
                        unset($GLOBALS['admitflow_backup_context']);
                    }
                    redirect('/staff/backups');
                }
                if ($path === '/staff/backup/verify') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to verify backups.');
                    }
                    $verified = BackupService::verify($database, $user, (int) ($_POST['backup_id'] ?? 0));
                    flash($verified ? 'success' : 'warning', $verified ? 'Backup checksum and structure verified.' : 'Backup verification failed. Do not restore this file.');
                    redirect('/staff/backups');
                }
                if ($path === '/staff/backup/restore') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to restore database backups.');
                    }
                    $backupId = (int) ($_POST['backup_id'] ?? 0);
                    try {
                        $safetyBackupId = BackupService::restoreDatabase($database, $user, $backupId, (string) ($_POST['confirmation'] ?? ''));
                        flash('success', 'Database backup restored. Safety backup #' . $safetyBackupId . ' is retained in backup history.');
                    } catch (DomainException $error) {
                        throw $error;
                    } catch (Throwable $error) {
                        error_log('[admitflow backup] Database restore failed (' . $error::class . ').');
                        flash('warning', 'Restore could not be completed. Review the server log and the verified safety backup before attempting recovery.');
                    }
                    redirect('/staff/backups');
                }
                if ($path === '/staff/backup/restore-files') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to restore file backups.');
                    }
                    $backupId = (int) ($_POST['backup_id'] ?? 0);
                    try {
                        $safetyBackupId = BackupService::restoreFiles($database, $user, $backupId, (string) ($_POST['confirmation'] ?? ''));
                        flash('success', 'File archive restored. Verified safety backup #' . $safetyBackupId . ' is retained in backup history.');
                    } catch (DomainException $error) {
                        throw $error;
                    } catch (Throwable $error) {
                        error_log('[admitflow backup] File archive restore failed (' . $error::class . ').');
                        flash('warning', 'File restoration failed and may be partial. Review the retained verified safety archive before attempting recovery.');
                    }
                    redirect('/staff/backups');
                }
                if ($path === '/staff/reports.csv') {
                    $report = StaffService::applications($database, $user, $_POST, true);
                    $csvBytes = ReportExport::csv($report);
                    StaffService::recordReportExport($database, $user, $_POST, count($report['rows']));
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Length: ' . strlen($csvBytes));
                    header('Content-Disposition: attachment; filename="admitflow-applications-' . date('Y-m-d') . '.csv"');
                    echo $csvBytes;
                    exit;
                }
                if ($path === '/staff/reports.pdf') {
                    if (!in_array($user['role'] ?? '', ['reviewer', 'admin'], true)) {
                        http_response_code(403);
                        header('Content-Type: text/plain; charset=utf-8');
                        exit('Admissions staff access is required.');
                    }
                    try {
                        $report = StaffService::applications($database, $user, $_POST, true);
                        $filterOptions = StaffService::filters($database, $user);
                        $pdfBytes = ReportExport::pdf($report, $filterOptions, $user, (string) ($_POST['include_signature_lines'] ?? '') === '1', (string) ($_POST['orientation'] ?? 'landscape'));
                        StaffService::recordReportExport($database, $user, $_POST, count($report['rows']));
                        while (ob_get_level() > 0) ob_end_clean();
                        header('Content-Type: application/pdf');
                        header('Content-Length: ' . strlen($pdfBytes));
                        header('Content-Disposition: attachment; filename="admitflow-report-' . date('Y-m-d') . '.pdf"');
                        echo $pdfBytes;
                        exit;
                    } catch (DomainException $error) {
                        if (ob_get_level() > 0) ob_end_clean();
                        http_response_code(422);
                        header('Content-Type: text/plain; charset=utf-8');
                        exit($error->getMessage());
                    } catch (Throwable $error) {
                        if (ob_get_level() > 0) ob_end_clean();
                        error_log('[admitflow report PDF] Export failed (' . $error::class . ').');
                        http_response_code(500);
                        header('Content-Type: text/plain; charset=utf-8');
                        exit('The report PDF could not be prepared.');
                    }
                }
                if ($path === '/staff/audit.csv') {
                    $user = current_user();
                    if ($user === null) {
                        redirect('/login');
                    }
                    if ($user['role'] !== 'admin') {
                        http_response_code(403);
                        header('Content-Type: text/plain; charset=utf-8');
                        exit('Administrator access is required.');
                    }
                    ini_set('display_errors', '0');
                    try {
                        $export = StaffService::auditLogExport($database, $user, $_POST);
                    } catch (DomainException $error) {
                        http_response_code(422);
                        header('Content-Type: text/plain; charset=utf-8');
                        exit($error->getMessage());
                    } catch (Throwable $error) {
                        error_log('[admitflow audit export] preparation failed (' . $error::class . ').');
                        http_response_code(500);
                        header('Content-Type: text/plain; charset=utf-8');
                        exit('The audit export could not be prepared.');
                    }
                    try {
                        StaffService::recordAuditExport($database, $user);
                    } catch (Throwable $error) {
                        if (is_resource($export['stream'])) fclose($export['stream']);
                        error_log('[admitflow audit export] completion event could not be recorded (' . $error::class . ').');
                        http_response_code(500);
                        header('Content-Type: text/plain; charset=utf-8');
                        exit('The audit export could not be completed.');
                    }
                    $startDate = $export['filters']['start_date'];
                    $endDate = $export['filters']['end_date'];
                    $dateSuffix = $startDate !== '' && $endDate !== ''
                        ? $startDate . '-to-' . $endDate
                        : ($startDate !== '' ? 'from-' . $startDate : ($endDate !== '' ? 'through-' . $endDate : date('Y-m-d')));
                    $filename = 'admitflow-audit-log-' . $dateSuffix . '.csv';
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    $streamMetadata = fstat($export['stream']);
                    header('Content-Type: text/csv; charset=UTF-8');
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: no-store, private');
                    if (is_array($streamMetadata) && isset($streamMetadata['size'])) {
                        header('Content-Length: ' . (int) $streamMetadata['size']);
                    }
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    fpassthru($export['stream']);
                    fclose($export['stream']);
                    exit;
                }
                if ($path === '/staff/document/review') {
                    StaffService::reviewDocument($database, $user, (int) ($_POST['document_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($_POST['feedback'] ?? ''));
                    flash('success', 'Document review saved and the student was notified.');
                    redirect('/staff/application/' . (int) ($_POST['application_id'] ?? 0));
                }
                if ($path === '/staff/application/status') {
                    StaffService::changeStatus($database, $user, (int) ($_POST['application_id'] ?? 0), (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
                    flash('success', 'Application status updated and the student was notified.');
                    redirect('/staff/application/' . (int) $_POST['application_id']);
                }
                if ($path === '/staff/application/note') {
                    StaffService::addNote($database, $user, (int) ($_POST['application_id'] ?? 0), (string) ($_POST['note'] ?? ''));
                    flash('success', 'Screening note added.');
                    redirect('/staff/application/' . (int) $_POST['application_id']);
                }
                if ($path === '/staff/application/assign') {
                    StaffService::assignReviewer($database, $user, (int) ($_POST['application_id'] ?? 0), (int) ($_POST['reviewer_id'] ?? 0));
                    flash('success', 'Reviewer assignment saved.');
                    redirect('/staff/application/' . (int) $_POST['application_id']);
                }
                if ($path === '/staff/interview/save') {
                    StaffService::scheduleInterview($database, $user, (int) ($_POST['application_id'] ?? 0), (int) ($_POST['interviewer_id'] ?? 0), (string) ($_POST['starts_at'] ?? ''), (string) ($_POST['ends_at'] ?? ''), (string) ($_POST['instructions'] ?? ''));
                    flash('success', 'Interview scheduled and the student was notified.');
                    redirect('/staff/application/' . (int) $_POST['application_id']);
                }
                if ($path === '/staff/interview/reschedule') {
                    StaffService::rescheduleInterview($database, $user, (int) ($_POST['application_id'] ?? 0), (int) ($_POST['interview_id'] ?? 0), (int) ($_POST['interviewer_id'] ?? 0), (string) ($_POST['starts_at'] ?? ''), (string) ($_POST['ends_at'] ?? ''), (string) ($_POST['instructions'] ?? ''));
                    flash('success', 'Interview rescheduled and the student was notified.');
                    redirect('/staff/application/' . (int) $_POST['application_id']);
                }
                if ($path === '/staff/interview/update') {
                    StaffService::updateInterview($database, $user, (int) ($_POST['application_id'] ?? 0), (int) ($_POST['interview_id'] ?? 0), (string) ($_POST['status'] ?? ''), (string) ($_POST['notes'] ?? ''));
                    flash('success', 'Interview record updated.');
                    redirect('/staff/application/' . (int) $_POST['application_id']);
                }
                if ($path === '/staff/enrollment/checklist') {
                    StaffService::updateEnrollmentChecklist($database, $user, (int) ($_POST['application_id'] ?? 0), is_array($_POST['checklist'] ?? null) ? $_POST['checklist'] : []);
                    flash('success', 'Enrollment checklist saved.');
                    redirect('/staff/application/' . (int) $_POST['application_id']);
                }
            } catch (DomainException $error) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                flash('warning', $error->getMessage());
                if ($path === '/staff/user/save' && (int) ($_POST['user_id'] ?? 0) < 1) {
                    $_SESSION['staff_create_form_open'] = true;
                    $_SESSION['staff_create_form_values'] = array_intersect_key($_POST, array_flip(['full_name', 'email', 'role']));
                }
                if (in_array($path, ['/staff/program/save', '/staff/year/save', '/staff/requirement/save'], true)) {
                    $_SESSION['academic_create_form_type'] = match ($path) {
                        '/staff/year/save' => 'year',
                        '/staff/requirement/save' => 'requirement',
                        default => 'program',
                    };
                    $_SESSION['academic_create_form_values'] = array_intersect_key($_POST, array_flip(['program_id', 'year_id', 'requirement_id', 'code', 'name', 'description', 'label', 'starts_on', 'ends_on', 'application_deadline', 'is_open', 'allowed_extensions', 'max_file_size_bytes', 'academic_program_id', 'academic_year_id', 'instructions', 'is_required', 'is_active']));
                    $_SESSION['academic_create_form_error'] = $error->getMessage();
                }
                if (in_array($path, ['/staff/announcement/save', '/staff/announcement/delete'], true)) {
                    if ($path === '/staff/announcement/save') {
                        $returnQuery = array_filter(['state' => (string) ($_POST['return_state'] ?? ''), 'audience' => (string) ($_POST['return_audience'] ?? ''), 'q' => (string) ($_POST['return_q'] ?? '')], static fn (string $value): bool => $value !== '');
                        if (empty($_SESSION['announcement_form_errors']) && (int) ($_POST['announcement_id'] ?? 0) < 1) {
                            $_SESSION['announcement_form_values'] = ['title' => (string) ($_POST['title'] ?? ''), 'body' => (string) ($_POST['body'] ?? ''), 'audience' => (string) ($_POST['audience'] ?? 'public'), 'publication_mode' => (string) ($_POST['publication_mode'] ?? 'publish'), 'starts_at' => (string) ($_POST['starts_at'] ?? '')];
                            $_SESSION['announcement_form_errors'] = ['form' => $error->getMessage()];
                        }
                        redirect('/staff/announcements' . ($returnQuery === [] ? '' : '?' . http_build_query($returnQuery)));
                    }
                    redirect('/staff/announcements');
                }
                if ($path === '/staff/academic-setting/delete') {
                    redirect(match ((string) ($_POST['setting_type'] ?? '')) {
                        'requirement' => '/staff/academic/requirements',
                        'year' => '/staff/academic/years',
                        default => '/staff/academic/programs',
                    });
                }
                if (in_array($path, ['/staff/program/save', '/staff/year/save', '/staff/requirement/save'], true)) {
                    redirect(match ($path) {
                        '/staff/year/save' => '/staff/academic/years',
                        '/staff/requirement/save' => '/staff/academic/requirements',
                        default => '/staff/academic/programs',
                    });
                }
                if ($path === '/staff/user/save') {
                    redirect(match ((string) ($_POST['role'] ?? 'reviewer')) {
                        'admin' => '/staff/users/administrators',
                        'student' => '/staff/users/students',
                        default => '/staff/users/staff',
                    });
                }
                if ($path === '/staff/backup/restore') {
                    redirect('/staff/backups');
                }
                $applicationId = (int) ($_POST['application_id'] ?? 0);
                if ($applicationId > 0 && in_array($path, ['/staff/interview/save', '/staff/interview/reschedule', '/staff/interview/update', '/staff/enrollment/checklist'], true)) {
                    redirect('/staff/application/' . $applicationId);
                }
                redirect('/staff');
            }
        }

        http_response_code(404);
        render_page('errors/not-found', ['title' => 'Page not found']);
        exit;
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD, POST');
        exit('Method not allowed.');
    }

    if ($path === '/') {
        $user = current_user();
        if ($user !== null) {
            redirect(portal_path_for_user($user));
        }
        $programs = $database->query('SELECT id, code, name FROM academic_programs WHERE is_active = 1 ORDER BY name')->fetchAll();
        $requirements = $database->query('SELECT name, instructions FROM admission_requirements WHERE is_active = 1 AND is_required = 1 ORDER BY sort_order, name')->fetchAll();
        $announcements = AnnouncementService::activeForUser($database, null, 4);
        $academicYear = $database->query('SELECT label, application_deadline FROM academic_years WHERE is_open = 1 ORDER BY starts_on DESC LIMIT 1')->fetch() ?: null;
        render_page('public/home', ['title' => 'Admissions', 'programs' => $programs, 'requirements' => $requirements, 'announcements' => $announcements, 'academicYear' => $academicYear]);
        exit;
    }

    if ($path === '/announcements') {
        $user = current_user();
        try {
            $announcementPage = AnnouncementService::activePage($database, $user, $_GET);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check announcement filters', 'message' => $error->getMessage()]);
            exit;
        }
        render_page('announcements/index', ['title' => 'Announcements', 'user' => $user, 'announcements' => $announcementPage['rows'], 'pagination' => $announcementPage['pagination'], 'announcementFilters' => $announcementPage['filters']]);
        exit;
    }

    if (preg_match('#^/announcement/(\\d+)$#', $path, $match)) {
        $user = current_user();
        $announcement = AnnouncementService::visibleAnnouncement($database, (int) $match[1], $user);
        if ($announcement === null) {
            http_response_code(404);
            render_page('errors/not-found', ['title' => 'Announcement not found']);
            exit;
        }
        render_page('announcements/detail', ['title' => $announcement['title'], 'user' => $user, 'announcement' => $announcement]);
        exit;
    }

    if ($path === '/register') {
        $user = current_user();
        if ($user !== null) {
            redirect(portal_path_for_user($user));
        }
        render_page('auth/register', ['title' => 'Create your account', 'values' => []]);
        exit;
    }

    if ($path === '/forgot-password') {
        render_page('auth/forgot-password', ['title' => 'Forgot password']);
        exit;
    }

    if ($path === '/reset-password') {
        render_page('auth/reset-password', ['title' => 'Reset password', 'token' => (string) ($_GET['token'] ?? '')]);
        exit;
    }

    if ($path === '/account/password') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        try {
            $loginHistory = AuthService::loginHistory($database, (string) $user['email'], $_GET);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check sign-in history filters', 'message' => $error->getMessage()]);
            exit;
        }
        render_page('auth/change-password', ['title' => 'Change password', 'user' => $user, 'passwordRequirements' => SecurityPolicy::passwordLengthLimits(), 'loginHistory' => $loginHistory['rows'], 'loginHistoryPagination' => $loginHistory['pagination'], 'loginHistoryFilters' => $loginHistory['filters']]);
        exit;
    }

    if ($path === '/settings') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        render_page('settings/index', ['title' => 'Settings', 'user' => $user]);
        exit;
    }

    if ($path === '/settings/profile') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        $profile = AccountSettingsService::profile($database, (int) $user['id']);
        if (!is_array($profile)) {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Account unavailable', 'message' => 'This active account profile is not available.']);
            exit;
        }
        $profileFormName = $_SESSION['profile_form_name'] ?? $profile['full_name'];
        $profileFormError = $_SESSION['profile_form_error'] ?? null;
        unset($_SESSION['profile_form_name'], $_SESSION['profile_form_error']);
        $applicantProfileValues = is_array($_SESSION['applicant_profile_form_values'] ?? null) ? $_SESSION['applicant_profile_form_values'] : [];
        $applicantProfileErrors = is_array($_SESSION['applicant_profile_form_errors'] ?? null) ? $_SESSION['applicant_profile_form_errors'] : [];
        unset($_SESSION['applicant_profile_form_values'], $_SESSION['applicant_profile_form_errors']);
        $missingApplicantFields = $user['role'] === 'student' ? AccountSettingsService::missingApplicantProfileFields($profile) : [];
        render_page('settings/profile', ['title' => 'My profile', 'user' => $user, 'profile' => $profile, 'profileFormName' => $profileFormName, 'profileFormError' => $profileFormError, 'applicantProfileValues' => $applicantProfileValues, 'applicantProfileErrors' => $applicantProfileErrors, 'missingApplicantFields' => $missingApplicantFields, 'showApplicationCompletionPrompt' => ($_GET['complete_application'] ?? '') === '1', 'avatarAvailable' => AvatarService::currentPath((int) $user['id']) !== null]);
        exit;
    }

    if ($path === '/settings/avatar') {
        $user = current_user();
        if ($user === null) redirect('/login');
        $avatarPath = AvatarService::currentPath((int) $user['id']);
        $mimeType = $avatarPath === null ? null : AvatarService::currentMimeType($avatarPath);
        if ($avatarPath === null || $mimeType === null) {
            http_response_code(404);
            exit('Profile picture not found.');
        }
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($avatarPath));
        header('Content-Disposition: inline; filename="profile-picture.' . ($mimeType === 'image/png' ? 'png' : 'jpg') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($avatarPath);
        exit;
    }

    if ($path === '/settings/notifications') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        redirect('/notifications');
    }

    if ($path === '/settings/system') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to open System Settings.']);
            exit;
        }
        $environment = (string) (getenv('ADMITFLOW_APP_ENV') ?: '');
        if (!in_array($environment, ['local', 'development', 'staging', 'production', 'test'], true)) {
            $environment = 'Not configured';
        }
        $databaseHealth = 'Unavailable';
        try {
            $databaseHealth = (int) $database->query('SELECT 1')->fetchColumn() === 1 ? 'Connected' : 'Unavailable';
        } catch (Throwable) {
        }
        render_page('settings/system', ['title' => 'System Settings', 'user' => $user, 'systemInfo' => ['name' => 'AdmitFlow', 'office' => 'Admission Office', 'version' => 'Not configured', 'environment' => $environment, 'database' => $databaseHealth, 'password_reset_smtp' => PasswordResetDelivery::hasSmtpConfiguration() ? 'Configured' : 'Not configured', 'timezone' => date_default_timezone_get()]]);
        exit;
    }

    if ($path === '/staff/login') {
        redirect('/login');
    }

    if ($path === '/login') {
        $user = current_user();
        if ($user !== null) {
            redirect(portal_path_for_user($user));
        }
        render_page('auth/login', [
            'title' => 'Sign in',
            'captcha' => AuthService::newCaptcha(),
        ]);
        exit;
    }

    if (in_array($path, ['/application/print', '/application/acceptance-letter', '/application/enrollment-confirmation'], true)) {
        $user = require_student();
        $printPreviewView = (string) ($_GET['preview'] ?? '') === '1';
        if ($method === 'GET' && $printPreviewView) {
            header('X-Frame-Options: SAMEORIGIN');
        }
        $application = ApplicationService::printableSummary($database, (int) $user['id']);
        $documentType = match ($path) {
            '/application/acceptance-letter' => 'acceptance',
            '/application/enrollment-confirmation' => 'enrollment',
            default => 'summary',
        };
        $available = $documentType === 'summary' ? $application !== null : ($application !== null && match ($documentType) {
            'acceptance' => ApplicationService::canPrintAcceptance($application),
            'enrollment' => ApplicationService::canPrintEnrollment($application),
        });
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        render_page('student/print-document', [
            'title' => match ($documentType) {
                'acceptance' => 'Acceptance letter',
                'enrollment' => 'Enrollment confirmation',
                default => 'Application summary',
            },
            'user' => $user,
            'application' => $application,
            'documentType' => $documentType,
            'available' => $available,
            'generatedAt' => new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')),
            'documentPreviewUrl' => $path . '?preview=1',
            'printDocumentPreviewView' => $printPreviewView,
            'standalonePrintView' => $printPreviewView,
        ]);
        exit;
    }

    if ($path === '/portal') {
        $user = require_student();
        $application = ApplicationService::current($database, (int) $user['id']);
        $printableApplication = $application === null ? null : ApplicationService::printableSummary($database, (int) $user['id']);
        $documents = $application === null ? [] : ApplicationService::documents($database, (int) $user['id'], (int) $application['id']);
        $notices = AnnouncementService::activeForUser($database, $user, 3);
        $interviews = $application === null ? [] : ApplicationService::interviews($database, (int) $user['id'], (int) $application['id']);
        render_page('student/portal', ['title' => 'Student portal', 'user' => $user, 'application' => $application, 'printableApplication' => $printableApplication, 'generatedAt' => new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')), 'documents' => $documents, 'announcements' => $notices, 'interviews' => $interviews]);
        exit;
    }

    if ($path === '/admin') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to open the administrator dashboard.']);
            exit;
        }
        $announcements = AnnouncementService::activeForUser($database, $user, 5);
        try {
            $metrics = StaffService::dashboard($database, $user);
            $charts = StaffService::charts($database, $user);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check dashboard filters', 'message' => $error->getMessage()]);
            exit;
        }
        render_page('staff/dashboard', ['title' => 'Administrator dashboard', 'adminDashboard' => true, 'user' => $user, 'metrics' => $metrics, 'charts' => $charts, 'announcements' => $announcements]);
        exit;
    }

    if ($path === '/admin/applications') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to manage the application queue.']);
            exit;
        }
        try {
            $applications = StaffService::applications($database, $user, $_GET);
            $options = StaffService::filters($database, $user);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check application filters', 'message' => $error->getMessage()]);
            exit;
        }
        render_page('staff/applications', ['title' => 'Application Queue', 'user' => $user, 'applications' => $applications] + $options);
        exit;
    }

    if ($path === '/staff/applications') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Admissions staff access required', 'message' => 'Admissions staff access is required to open the application queue.']);
            exit;
        }
        try {
            $applications = StaffService::applications($database, $user, $_GET);
            $options = StaffService::filters($database, $user);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check application filters', 'message' => $error->getMessage()]);
            exit;
        }
        render_page('staff/applications', ['title' => 'Application Queue', 'user' => $user, 'applications' => $applications] + $options);
        exit;
    }

    if ($path === '/notifications') {
        $user = current_user();
        if ($user === null || !in_array($user['role'], ['student', 'reviewer', 'admin'], true)) {
            redirect('/login');
        }
        $dismissed = is_array($_SESSION['dismissed_derived_notifications'] ?? null) ? $_SESSION['dismissed_derived_notifications'] : [];
        try {
            $notificationPage = NotificationService::feedPage($database, $user, $dismissed, $_GET);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check notification filters', 'message' => $error->getMessage()]);
            exit;
        }
        render_page('student/notifications', ['title' => 'Notifications', 'user' => $user, 'notifications' => $notificationPage['items'], 'pagination' => $notificationPage['pagination'], 'notificationFilters' => $notificationPage['filters']]);
        exit;
    }

    if ($path === '/application') {
        $user = require_student();
        $application = ApplicationService::current($database, (int) $user['id']);
        if ($application !== null && $application['status'] !== 'draft') {
            redirect('/portal');
        }
        $profile = AccountSettingsService::profile($database, (int) $user['id']) ?? [];
        if ($application === null) {
            $profileGate = AccountSettingsService::applicationStartGate($database, (int) $user['id']);
            if (!$profileGate['allowed']) {
                flash('warning', 'Complete your applicant profile before starting an application. Missing: ' . implode(', ', array_values($profileGate['missing'])) . '.');
                redirect('/settings/profile?complete_application=1');
            }
        }
        $options = ApplicationService::options($database);
        $documents = $application === null ? [] : ApplicationService::documents($database, (int) $user['id'], (int) $application['id']);
        $applicationErrors = is_array($_SESSION['application_errors'] ?? null) ? $_SESSION['application_errors'] : [];
        $applicationValues = is_array($_SESSION['application_values'] ?? null) ? $_SESSION['application_values'] : [];
        $applicationInitialValues = ApplicationService::applicantFormValues($profile, $application, $applicationValues);
        unset($_SESSION['application_errors']);
        unset($_SESSION['application_values']);
        $applicationStep = filter_var($_GET['step'] ?? 0, FILTER_VALIDATE_INT);
        $furthestStep = max(0, min((int) ($_SESSION['application_progress_' . (int) $user['id']] ?? 0), 3));
        $applicationStep = ApplicationService::visibleWizardStep($applicationStep === false ? 0 : $applicationStep, $furthestStep);
        render_page('student/application', ['title' => 'My application', 'user' => $user, 'application' => $application, 'profile' => $profile, 'applicationInitialValues' => $applicationInitialValues, 'documents' => $documents, 'applicationErrors' => $applicationErrors, 'applicationValues' => $applicationValues, 'applicationStep' => $applicationStep] + $options);
        exit;
    }

    if (preg_match('#^/staff/document/(\d+)/preview$#', $path, $match)) {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
            http_response_code(403);
            exit('Admissions staff access is required.');
        }
        $document = StaffService::authorizedDocument($database, $user, (int) $match[1]);
        if (!is_array($document)) {
            http_response_code(404);
            exit('Document not found.');
        }
        $storageName = (string) ($document['storage_name'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}\.(pdf|jpe?g|png)$/i', $storageName)) {
            http_response_code(404);
            exit('Document not found.');
        }
        $storageRoot = realpath(dirname(__DIR__) . '/storage/uploads');
        $filePath = $storageRoot === false ? false : realpath($storageRoot . DIRECTORY_SEPARATOR . $storageName);
        if ($storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            exit('Document is unavailable.');
        }
        $fileSize = filesize($filePath);
        $actualMime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        $contentType = StaffService::documentPreviewContentType($storageName, $actualMime);
        $expectedSize = (int) ($document['file_size_bytes'] ?? 0);
        $expectedHash = (string) ($document['sha256'] ?? '');
        $actualHash = hash_file('sha256', $filePath);
        if ($contentType === null || !StaffService::documentPreviewIntegrityMatches($fileSize, $expectedSize, $actualHash, $expectedHash) || !StaffService::documentPreviewContentLooksValid($filePath, $contentType)) {
            http_response_code($contentType === null ? 415 : 422);
            header('Content-Type: text/plain; charset=utf-8');
            exit($contentType === null ? 'This document format cannot be previewed. Use the authorized download action.' : 'This document is empty, unavailable, or failed its integrity check.');
        }
        if (!StaffService::recordDocumentPreviewAccess($database, $user, (int) $match[1])) {
            http_response_code(404);
            exit('Document not found.');
        }
        $safeName = rawurlencode(basename((string) ($document['original_name'] ?? 'document')));
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $fileSize);
        header("Content-Disposition: inline; filename=\"document-preview\"; filename*=UTF-8''{$safeName}");
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        readfile($filePath);
        exit;
    }

    if (preg_match('#^/staff/document/(\d+)$#', $path, $match)) {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
            http_response_code(403);
            exit('Admissions staff access is required.');
        }
        $document = StaffService::authorizedDocument($database, $user, (int) $match[1], false, true);
        $storageRoot = realpath(dirname(__DIR__) . '/storage/uploads');
        $filePath = $storageRoot === false ? false : realpath($storageRoot . '/' . basename((string) ($document['storage_name'] ?? '')));
        if (!is_array($document) || $storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            exit('Document not found.');
        }
        header('Content-Type: ' . $document['detected_mime_type']);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($document['original_name'])) . '"');
        AuditLog::record($database, $user, 'document.downloaded', 'application_document', (int) $document['id']);
        readfile($filePath);
        exit;
    }

    if (preg_match('#^/document/(\d+)/preview$#', $path, $match)) {
        $user = require_student();
        $document = ApplicationService::authorizedCurrentDocument($database, (int) $user['id'], (int) $match[1]);
        if (!is_array($document)) {
            http_response_code(404);
            exit('Document not found.');
        }
        $storageName = (string) ($document['storage_name'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}\.(pdf|jpe?g|png)$/i', $storageName)) {
            http_response_code(404);
            exit('Document not found.');
        }
        $storageRoot = realpath(dirname(__DIR__) . '/storage/uploads');
        $filePath = $storageRoot === false ? false : realpath($storageRoot . DIRECTORY_SEPARATOR . $storageName);
        if ($storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            exit('Document is unavailable.');
        }
        $fileSize = filesize($filePath);
        $contentType = StaffService::documentPreviewContentType($storageName, (new finfo(FILEINFO_MIME_TYPE))->file($filePath));
        $actualHash = hash_file('sha256', $filePath);
        if ($contentType === null || !StaffService::documentPreviewIntegrityMatches($fileSize, (int) $document['file_size_bytes'], $actualHash, (string) $document['sha256']) || !StaffService::documentPreviewContentLooksValid($filePath, $contentType)) {
            http_response_code($contentType === null ? 415 : 422);
            header('Content-Type: text/plain; charset=utf-8');
            exit($contentType === null ? 'This document format cannot be previewed. Use the authorized download action.' : 'This document is empty, unavailable, or failed its integrity check.');
        }
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $fileSize);
        header("Content-Disposition: inline; filename=\"document-preview\"; filename*=UTF-8''" . rawurlencode(basename((string) $document['original_name'])));
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        readfile($filePath);
        exit;
    }

    if (preg_match('#^/document/(\d+)$#', $path, $match)) {
        $user = require_student();
        $statement = $database->prepare(
            'SELECT d.id, d.storage_name, d.original_name, d.detected_mime_type FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = :document_id AND d.is_current = 1 AND a.student_id = :student_id AND a.deleted_at IS NULL'
        );
        $statement->execute(['document_id' => (int) $match[1], 'student_id' => $user['id']]);
        $document = $statement->fetch();
        $storageRoot = realpath(dirname(__DIR__) . '/storage/uploads');
        $filePath = $storageRoot === false ? false : realpath($storageRoot . '/' . basename((string) ($document['storage_name'] ?? '')));
        if (!is_array($document) || $storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            exit('Document not found.');
        }
        header('Content-Type: ' . $document['detected_mime_type']);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($document['original_name'])) . '"');
        AuditLog::record($database, $user, 'document.downloaded', 'application_document', (int) $document['id']);
        readfile($filePath);
        exit;
    }

    if (preg_match('#^/staff/application/(\d+)$#', $path, $match)) {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Admissions staff access required', 'message' => 'Admissions staff access is required.']);
            exit;
        }
        try {
            $includeArchived = $user['role'] === 'admin' && (string) ($_GET['archived'] ?? '') === '1';
            $application = StaffService::detail($database, $user, (int) $match[1], false, $_GET, true, $includeArchived);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check application history filters', 'message' => $error->getMessage()]);
            exit;
        }
        if ($application === null) {
            http_response_code(404);
            render_page('errors/not-found', ['title' => 'Application not found']);
            exit;
        }
        AuditLog::recordOncePerWindow($database, $user, 'application.viewed', 'application', (int) $application['id'], 600);
        $options = StaffService::filters($database, $user);
        render_page('staff/application-detail', ['title' => 'Application review', 'user' => $user, 'application' => $application] + $options);
        exit;
    }

    if ($path === '/staff') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Admissions staff access required', 'message' => 'Admissions staff access is required.']);
            exit;
        }
        try {
            $metrics = StaffService::dashboard($database, $user);
            $charts = StaffService::charts($database, $user);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check dashboard filters', 'message' => $error->getMessage()]);
            exit;
        }
        $announcements = AnnouncementService::activeForUser($database, $user, 5);
        render_page('staff/dashboard', ['title' => 'Admissions workspace', 'user' => $user, 'metrics' => $metrics, 'charts' => $charts, 'announcements' => $announcements]);
        exit;
    }

    if ($path === '/staff/charts') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Admissions staff access is required.']);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode(StaffService::charts($database, $user), JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            error_log('[admitflow charts] ' . $error::class . ': chart data unavailable.');
            http_response_code(503);
            echo json_encode(['error' => 'Dashboard data is temporarily unavailable.']);
        }
        exit;
    }

    if ($path === '/staff/reports') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!in_array($user['role'], ['reviewer', 'admin'], true)) {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Admissions staff access required', 'message' => 'Admissions staff access is required.']);
            exit;
        }
        $printView = (string) ($_GET['view'] ?? '') === 'print';
        if ($method === 'GET' && $printView && (string) ($_GET['embed'] ?? '') === '1') {
            header('X-Frame-Options: SAMEORIGIN');
        }
        $reportInput = $_GET;
        unset($reportInput['view'], $reportInput['embed']);
        try {
            $report = StaffService::applications($database, $user, $reportInput, true, !$printView);
            if ($printView) {
                StaffService::recordReportPrintPreview($database, $user, $report['filters'], count($report['rows']));
            }
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check report filters', 'message' => $error->getMessage()]);
            exit;
        }
        $options = StaffService::filters($database, $user);
        render_page($printView ? 'staff/reports-print' : 'staff/reports', ['title' => $printView ? 'Print preview' : 'Reports and print center', 'user' => $user, 'report' => $report, 'counts' => $report['summary']['statuses'], 'reportPrintView' => $printView, 'standalonePrintView' => $printView] + $options);
        exit;
    }

    if ($path === '/staff/audit') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to review the system audit log.']);
            exit;
        }
        $auditPage = StaffService::auditLog($database, $_GET);
        render_page('staff/audit', ['title' => 'Audit Log', 'user' => $user, 'events' => $auditPage['rows'], 'pagination' => $auditPage['pagination'], 'filters' => $auditPage['filters'], 'auditSummary' => $auditPage['summary']]);
        exit;
    }

    if ($path === '/staff/settings') {
        redirect('/staff/academic/programs');
    }

    $academicPageScopes = [
        '/staff/academic/programs' => 'programs',
        '/staff/academic/years' => 'years',
        '/staff/academic/requirements' => 'requirements',
    ];
    if (isset($academicPageScopes[$path])) {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to change system settings.']);
            exit;
        }
        try {
            $settingsLists = StaffService::academicSettings($database, $user, $_GET, $academicPageScopes[$path]);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check academic setting filters', 'message' => $error->getMessage()]);
            exit;
        }
        $createFormType = (string) ($_SESSION['academic_create_form_type'] ?? '');
        $createFormValues = is_array($_SESSION['academic_create_form_values'] ?? null) ? $_SESSION['academic_create_form_values'] : [];
        $createFormError = (string) ($_SESSION['academic_create_form_error'] ?? '');
        unset($_SESSION['academic_create_form_type'], $_SESSION['academic_create_form_values'], $_SESSION['academic_create_form_error']);
        $academicPageTitles = ['programs' => 'Programs', 'years' => 'Academic Years', 'requirements' => 'Requirements'];
        render_page('staff/academic-management', ['title' => $academicPageTitles[$academicPageScopes[$path]], 'user' => $user, 'managementType' => $academicPageScopes[$path], 'createFormType' => $createFormType, 'createFormValues' => $createFormValues, 'createFormError' => $createFormError] + $settingsLists);
        exit;
    }

    if ($path === '/staff/announcements') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if (!AnnouncementService::canManage($user)) {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to manage announcements.']);
            exit;
        }
        $state = trim((string) ($_GET['state'] ?? ''));
        $audience = trim((string) ($_GET['audience'] ?? ''));
        try {
            $announcementPage = AnnouncementService::managementList($database, $state, $_GET);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check announcement filter', 'message' => $error->getMessage()]);
            exit;
        }
        $formValues = is_array($_SESSION['announcement_form_values'] ?? null) ? $_SESSION['announcement_form_values'] : [];
        $formErrors = is_array($_SESSION['announcement_form_errors'] ?? null) ? $_SESSION['announcement_form_errors'] : [];
        unset($_SESSION['announcement_form_values'], $_SESSION['announcement_form_errors']);
        render_page('staff/announcements', ['title' => 'Announcement Center', 'user' => $user, 'announcements' => $announcementPage['rows'], 'pagination' => $announcementPage['pagination'], 'activeState' => $state, 'activeAudience' => $announcementPage['filters']['audience'], 'activeSearch' => $announcementPage['filters']['q'], 'activeStartDate' => $announcementPage['filters']['start_date'], 'activeEndDate' => $announcementPage['filters']['end_date'], 'activeDatePreset' => $announcementPage['filters']['date_range'], 'stateCounts' => $announcementPage['counts'], 'formValues' => $formValues, 'formErrors' => $formErrors]);
        exit;
    }

    if ($path === '/staff/users') {
        redirect('/staff/users/administrators');
    }

    $userPageRoles = [
        '/staff/users/administrators' => 'admin',
        '/staff/users/staff' => 'reviewer',
        '/staff/users/students' => 'student',
    ];
    if (isset($userPageRoles[$path])) {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to manage user accounts.']);
            exit;
        }
        $accountsByRole = ['admin' => [], 'reviewer' => [], 'student' => []];
        $paginationByRole = [];
        $accountTotals = ['admin' => 0, 'reviewer' => 0, 'student' => 0];
        $roleFilter = $userPageRoles[$path];
        $statusFilter = (string) ($_GET['account_status'] ?? '');
        if (!in_array($statusFilter, ['', 'active', 'inactive', 'locked'], true)) {
            $statusFilter = '';
        }
        $applicationStatusFilter = (string) ($_GET['application_status'] ?? '');
        if ($roleFilter !== 'student' || !in_array($applicationStatusFilter, ['', 'draft', 'submitted', 'in_screening', 'documents_incomplete', 'interview_scheduled', 'accepted', 'declined', 'enrolled'], true)) {
            $applicationStatusFilter = '';
        }
        $searchFilter = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($searchFilter) > 160) {
            $searchFilter = mb_substr($searchFilter, 0, 160);
        }
        $startDateFilter = trim((string) ($_GET['start_date'] ?? ''));
        $endDateFilter = trim((string) ($_GET['end_date'] ?? ''));
        $dateRangeFilter = (string) ($_GET['date_range'] ?? '');
        $dateRangeFilter = in_array($dateRangeFilter, ['last7', 'last30', 'custom'], true) ? $dateRangeFilter : '';
        $userDateBounds = [];
        foreach (['start_date' => $startDateFilter, 'end_date' => $endDateFilter] as $dateKey => $dateValue) {
            if ($dateValue === '') continue;
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($parsedDate === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $parsedDate->format('Y-m-d') !== $dateValue) {
                http_response_code(422);
                render_page('errors/not-ready', ['title' => 'Check account date filters', 'message' => 'Enter valid account creation dates.']);
                exit;
            }
            if ($dateKey === 'end_date') $parsedDate = $parsedDate->add(new DateInterval('P1D'));
            $userDateBounds[$dateKey] = $parsedDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($startDateFilter !== '' && $endDateFilter !== '' && $startDateFilter > $endDateFilter) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check account date filters', 'message' => 'The creation start date must not be later than the end date.']);
            exit;
        }
        if ($startDateFilter !== '' || $endDateFilter !== '') $dateRangeFilter = $dateRangeFilter ?: 'custom';
        $userFilters = ['role' => $roleFilter, 'account_status' => $statusFilter, 'application_status' => $applicationStatusFilter, 'q' => $searchFilter, 'start_date' => $startDateFilter, 'end_date' => $endDateFilter, 'date_range' => $dateRangeFilter];
        $rolesToLoad = [$roleFilter];
        foreach ($rolesToLoad as $accountRole) {
            $where = ['role = :role'];
            $parameters = ['role' => $accountRole];
            if ($statusFilter !== '') {
                $where[] = 'account_status = :account_status';
                $parameters['account_status'] = $statusFilter;
            }
            if ($accountRole === 'student' && $applicationStatusFilter !== '') {
                $where[] = 'EXISTS (SELECT 1 FROM applications app_filter WHERE app_filter.student_id = users.id AND app_filter.deleted_at IS NULL AND app_filter.status = :application_status)';
                $parameters['application_status'] = $applicationStatusFilter;
            }
            if ($searchFilter !== '') {
                $where[] = '(full_name LIKE :search_name OR email LIKE :search_email)';
                $parameters['search_name'] = '%' . $searchFilter . '%';
                $parameters['search_email'] = '%' . $searchFilter . '%';
            }
            if (isset($userDateBounds['start_date'])) {
                $where[] = 'created_at >= :created_start';
                $parameters['created_start'] = $userDateBounds['start_date'];
            }
            if (isset($userDateBounds['end_date'])) {
                $where[] = 'created_at < :created_end';
                $parameters['created_end'] = $userDateBounds['end_date'];
            }
            $whereSql = implode(' AND ', $where);
            $countUsers = $database->prepare('SELECT COUNT(*) FROM users WHERE ' . $whereSql);
            $countUsers->execute($parameters);
            $accountTotals[$accountRole] = (int) $countUsers->fetchColumn();
            $pageKey = 'page_' . $accountRole;
            $pagination = Pagination::make($accountTotals[$accountRole], $_GET, $pageKey, 'per_page', 20);
            $applicationStatusSelect = $accountRole === 'student' ? ', (SELECT app.status FROM applications app WHERE app.student_id = users.id AND app.deleted_at IS NULL ORDER BY app.updated_at DESC, app.id DESC LIMIT 1) AS application_status, (SELECT app.reference_number FROM applications app WHERE app.student_id = users.id AND app.deleted_at IS NULL ORDER BY app.updated_at DESC, app.id DESC LIMIT 1) AS applicant_id, (SELECT app.id FROM applications app WHERE app.student_id = users.id AND app.deleted_at IS NULL AND app.status <> \'draft\' ORDER BY app.updated_at DESC, app.id DESC LIMIT 1) AS reviewable_application_id, profile.mobile_number, profile.date_of_birth, profile.address_line, profile.municipality, profile.province' : '';
            $profileJoin = $accountRole === 'student' ? ' LEFT JOIN student_profiles profile ON profile.user_id = users.id' : '';
            $query = $database->prepare('SELECT id, full_name, email, role, account_status, last_login_at, created_at' . $applicationStatusSelect . ' FROM users' . $profileJoin . ' WHERE ' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $pagination['page_size'] . ' OFFSET ' . $pagination['offset']);
            $query->execute($parameters);
            $accountsByRole[$accountRole] = $query->fetchAll();
            $pagination['page_key'] = $pageKey;
            $pagination['base_url'] = $path;
            $pagination['query'] = $userFilters + ['per_page' => $pagination['page_size']];
            $paginationByRole[$accountRole] = $pagination;
        }
        $allUserCategories = [
            ['key' => 'admin', 'id' => 'admin-accounts', 'title' => 'Administrator accounts'],
            ['key' => 'reviewer', 'id' => 'staff-accounts', 'title' => 'Staff accounts'],
            ['key' => 'student', 'id' => 'student-accounts', 'title' => 'Student accounts'],
        ];
        $userCategories = array_values(array_filter($allUserCategories, static fn (array $category): bool => $category['key'] === $roleFilter));
        $staffSetupLink = $_SESSION['staff_setup_link'] ?? null;
        $staffCreateFormOpen = !empty($_SESSION['staff_create_form_open']);
        $staffCreateFormValues = is_array($_SESSION['staff_create_form_values'] ?? null) ? $_SESSION['staff_create_form_values'] : [];
        unset($_SESSION['staff_create_form_open'], $_SESSION['staff_create_form_values']);
        unset($_SESSION['staff_setup_link']);
        $userPageTitles = ['admin' => 'Administrator Accounts', 'reviewer' => 'Staff Accounts', 'student' => 'Student Accounts'];
        render_page('staff/users', ['title' => $userPageTitles[$roleFilter], 'user' => $user, 'accountCategory' => $roleFilter, 'accountsByRole' => $accountsByRole, 'accountTotals' => $accountTotals, 'userCategories' => $userCategories, 'paginationByRole' => $paginationByRole, 'userFilters' => $userFilters, 'staffSetupLink' => $staffSetupLink, 'staffCreateFormOpen' => $staffCreateFormOpen, 'staffCreateFormValues' => $staffCreateFormValues]);
        exit;
    }

    if ($path === '/staff/backups') {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            render_page('errors/not-ready', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to manage backups.']);
            exit;
        }
        try {
            $backupPage = BackupService::history($database, $_GET);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check backup filters', 'message' => $error->getMessage()]);
            exit;
        }
        $automatedBackupRuns = AutomatedBackupRunner::recentHistory(dirname(__DIR__) . '/storage/logs/automated-backup-history.jsonl');
        render_page('staff/backups', ['title' => 'Backups and restore', 'user' => $user, 'backups' => $backupPage['rows'], 'pagination' => $backupPage['pagination'], 'backupFilters' => $backupPage['filters'], 'restoreInstructions' => BackupService::safeRestoreInstructions(), 'automatedBackupRuns' => $automatedBackupRuns]);
        exit;
    }

    if (preg_match('#^/staff/backup/(\d+)/download$#', $path, $match)) {
        $user = current_user();
        if ($user === null) {
            redirect('/login');
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Administrator access is required.');
        }
        $statement = $database->prepare('SELECT storage_name, backup_type, verification_status FROM backups WHERE id = :id');
        $statement->execute(['id' => (int) $match[1]]);
        $backup = $statement->fetch();
        $root = realpath(dirname(__DIR__) . '/storage/backups');
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . basename((string) ($backup['storage_name'] ?? '')));
        if (!is_array($backup) || $backup['verification_status'] !== 'verified' || $root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            exit('Verified backup not found.');
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($backup['storage_name'])) . '"');
        AuditLog::record($database, $user, 'backup.downloaded', 'backup', (int) $match[1], 'success', ['backup_type' => $backup['backup_type']], 'notice');
        readfile($file);
        exit;
    }

    http_response_code(404);
    render_page('errors/not-found', ['title' => 'Page not found']);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    $diagnosticMessage = preg_replace('/\b(password|passwd|token|secret|credential|csrf|session|authorization|cookie)\b\s*([=:])\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1$2[redacted]', $error->getMessage()) ?? '[message unavailable]';
    error_log('[admitflow] request failed (' . $error::class . ') at ' . basename($error->getFile()) . ':' . $error->getLine() . ': ' . mb_substr($diagnosticMessage, 0, 1000));
    http_response_code(500);
    render_page('errors/server-error', ['title' => 'Something went wrong']);
}
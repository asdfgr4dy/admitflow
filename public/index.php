<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/AuthService.php';
require dirname(__DIR__) . '/app/ApplicationService.php';
require dirname(__DIR__) . '/app/AnnouncementService.php';
require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/app/PasswordResetService.php';
require dirname(__DIR__) . '/app/BackupService.php';
require dirname(__DIR__) . '/app/NotificationService.php';
require dirname(__DIR__) . '/app/AccountSettingsService.php';
require dirname(__DIR__) . '/app/StaffAccountService.php';
require dirname(__DIR__) . '/app/AvatarService.php';
require dirname(__DIR__) . '/app/LoginRateLimitPolicy.php';
require dirname(__DIR__) . '/app/StatusTransitionPolicy.php';
require dirname(__DIR__) . '/app/UploadPolicy.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; img-src 'self' data: blob:; frame-src 'self' blob:");
header('Cache-Control: no-store');

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
                    flash('success', 'Profile picture removed.');
                } else {
                    AvatarService::store((int) $user['id'], $_FILES['avatar'] ?? []);
                    flash('success', 'Profile picture updated.');
                }
            } catch (DomainException $error) {
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
                http_response_code(403);
                exit('Admissions staff access is required.');
            }
            try {
                if ($path === '/staff/program/save') {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to manage programs.');
                    }
                    $programId = (int) ($_POST['program_id'] ?? 0);
                    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                    $name = trim((string) ($_POST['name'] ?? ''));
                    if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code) || $name === '' || mb_strlen($name) > 160) {
                        throw new DomainException('Enter a valid program code and name.');
                    }
                    $database->beginTransaction();
                    try {
                        if ($programId > 0) {
                            $save = $database->prepare('UPDATE academic_programs SET code = :code, name = :name, is_active = :active WHERE id = :id');
                            $save->execute(['code' => $code, 'name' => $name, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0, 'id' => $programId]);
                            $action = 'program.updated';
                        } else {
                            $save = $database->prepare('INSERT INTO academic_programs (code, name, is_active) VALUES (:code, :name, :active)');
                            $save->execute(['code' => $code, 'name' => $name, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0]);
                            $programId = (int) $database->lastInsertId();
                            $action = 'program.created';
                        }
                        $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) VALUES (:user_id, :actor, :action, \'academic_program\', :entity_id, \'success\', :ip)');
                        $audit->execute(['user_id' => $user['id'], 'actor' => $user['full_name'], 'action' => $action, 'entity_id' => $programId, 'ip' => request_ip()]);
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
                    flash('success', 'Program settings saved.');
                    redirect('/staff/settings');
                }
                if ($path === '/staff/academic-setting/delete') {
                    if ($user['role'] !== 'admin') {
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
                        $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) VALUES (:user_id, :actor, :action, :entity_type, :entity_id, \'success\', :ip)');
                        $audit->execute(['user_id' => $user['id'], 'actor' => $user['full_name'], 'action' => $setting['entity'] . '.deleted', 'entity_type' => $setting['entity'], 'entity_id' => $settingId, 'ip' => request_ip()]);
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) {
                            $database->rollBack();
                        }
                        if ($error instanceof PDOException && $error->getCode() === '23000') {
                            throw new DomainException('This ' . $setting['label'] . ' is in use and cannot be deleted. Deactivate it instead.');
                        }
                        throw $error;
                    }
                    flash('success', ucfirst($setting['label']) . ' deleted.');
                    redirect('/staff/settings' . ($settingType === 'requirement' ? '#requirements' : ($settingType === 'year' ? '#year-settings' : '#program-settings')));
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
                    $database->beginTransaction();
                    try {
                        if (($_POST['is_open'] ?? '') === '1') {
                            $database->exec('UPDATE academic_years SET is_open = 0');
                        }
                        if ($yearId > 0) {
                            $save = $database->prepare('UPDATE academic_years SET label = :label, starts_on = :starts_on, ends_on = :ends_on, application_deadline = :deadline, is_open = :is_open WHERE id = :id');
                            $save->execute(['label' => $label, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'deadline' => $deadline ?: null, 'is_open' => ($_POST['is_open'] ?? '') === '1' ? 1 : 0, 'id' => $yearId]);
                            $action = 'academic_year.updated';
                        } else {
                            $save = $database->prepare('INSERT INTO academic_years (label, starts_on, ends_on, application_deadline, is_open) VALUES (:label, :starts_on, :ends_on, :deadline, :is_open)');
                            $save->execute(['label' => $label, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'deadline' => $deadline ?: null, 'is_open' => ($_POST['is_open'] ?? '') === '1' ? 1 : 0]);
                            $yearId = (int) $database->lastInsertId();
                            $action = 'academic_year.created';
                        }
                        $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) VALUES (:user_id, :actor, :action, \'academic_year\', :entity_id, \'success\', :ip)');
                        $audit->execute(['user_id' => $user['id'], 'actor' => $user['full_name'], 'action' => $action, 'entity_id' => $yearId, 'ip' => request_ip()]);
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) $database->rollBack();
                        if ($error instanceof PDOException && $error->getCode() === '23000') {
                            throw new DomainException('That academic year label already exists.');
                        }
                        throw $error;
                    }
                    flash('success', 'Academic year settings saved.');
                    redirect('/staff/settings');
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
                    if ($programId !== null && !$database->query('SELECT 1 FROM academic_programs WHERE id = ' . (int) $programId)->fetchColumn()) {
                        throw new DomainException('Choose an existing academic program.');
                    }
                    if ($yearId !== null && !$database->query('SELECT 1 FROM academic_years WHERE id = ' . (int) $yearId)->fetchColumn()) {
                        throw new DomainException('Choose an existing academic year.');
                    }
                    $database->beginTransaction();
                    try {
                        if ($requirementId > 0) {
                            $save = $database->prepare('UPDATE admission_requirements SET code = :code, name = :name, instructions = :instructions, academic_program_id = :program_id, academic_year_id = :year_id, allowed_extensions = :extensions, max_file_size_bytes = :max_bytes, is_required = :required, is_active = :active WHERE id = :id');
                            $save->execute(['code' => $code, 'name' => $name, 'instructions' => $instructions ?: null, 'program_id' => $programId, 'year_id' => $yearId, 'extensions' => implode(',', $extensionList), 'max_bytes' => $maxBytes, 'required' => ($_POST['is_required'] ?? '') === '1' ? 1 : 0, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0, 'id' => $requirementId]);
                            $action = 'requirement.updated';
                        } else {
                            $save = $database->prepare('INSERT INTO admission_requirements (code, name, instructions, academic_program_id, academic_year_id, allowed_extensions, max_file_size_bytes, is_required, is_active) VALUES (:code, :name, :instructions, :program_id, :year_id, :extensions, :max_bytes, :required, :active)');
                            $save->execute(['code' => $code, 'name' => $name, 'instructions' => $instructions ?: null, 'program_id' => $programId, 'year_id' => $yearId, 'extensions' => implode(',', $extensionList), 'max_bytes' => $maxBytes, 'required' => ($_POST['is_required'] ?? '') === '1' ? 1 : 0, 'active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0]);
                            $requirementId = (int) $database->lastInsertId();
                            $action = 'requirement.created';
                        }
                        $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) VALUES (:user_id, :actor, :action, \'admission_requirement\', :entity_id, \'success\', :ip)');
                        $audit->execute(['user_id' => $user['id'], 'actor' => $user['full_name'], 'action' => $action, 'entity_id' => $requirementId, 'ip' => request_ip()]);
                        $database->commit();
                    } catch (Throwable $error) {
                        if ($database->inTransaction()) $database->rollBack();
                        if ($error instanceof PDOException && $error->getCode() === '23000') throw new DomainException('That requirement code already exists in the selected scope.');
                        throw $error;
                    }
                    flash('success', 'Admission requirement saved.');
                    redirect('/staff/settings#requirements');
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
                        $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) VALUES (:user_id, :actor, :action, \'announcement\', :entity_id, \'success\', :ip)');
                        $audit->execute(['user_id' => $user['id'], 'actor' => $user['full_name'], 'action' => $action, 'entity_id' => $announcementId, 'ip' => request_ip()]);
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
                        redirect('/staff/users');
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
                    $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) VALUES (:user_id, :actor, :action, \'user\', :entity_id, \'success\', :ip)');
                    $audit->execute(['user_id' => $user['id'], 'actor' => $user['full_name'], 'action' => $action, 'entity_id' => $userId, 'ip' => request_ip()]);
                    $database->commit();
                    flash('success', 'Account role and status saved.');
                    redirect('/staff/users');
                }
                if (in_array($path, ['/staff/backup/database', '/staff/backup/files'], true)) {
                    if ($user['role'] !== 'admin') {
                        throw new DomainException('Administrator access is required to create backups.');
                    }
                    try {
                        if ($path === '/staff/backup/database') {
                            BackupService::createDatabase($database, $user);
                        } else {
                            BackupService::createFiles($database, $user);
                        }
                        flash('success', 'Backup created and verified.');
                    } catch (Throwable $error) {
                        error_log('[admitflow backup] operation failed (' . $error::class . ').');
                        flash('warning', 'Backup did not complete or could not be verified. No successful backup is recorded.');
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
                if ($path === '/staff/reports.csv') {
                    $report = StaffService::applications($database, $user, $_POST, true);
                    $output = fopen('php://temp/maxmemory:2097152', 'w+b');
                    if ($output === false) {
                        throw new RuntimeException('Could not prepare the report export.');
                    }
                    fwrite($output, "\xEF\xBB\xBF");
                    fputcsv($output, ['Reference', 'Applicant', 'Program', 'Academic year', 'Submitted (UTC)', 'Documents uploaded', 'Required documents', 'Reviewer', 'Status']);
                    if ($report['truncated']) {
                        fputcsv($output, ['NOTICE', 'Only the first 5,000 of ' . $report['total'] . ' matching applications are included. Narrow report filters for a complete export.']);
                    }
                    foreach ($report['rows'] as $row) {
                        $cells = [$row['reference_number'], $row['full_name'], $row['program_name'], $row['year_label'], $row['submitted_at'], $row['uploaded_documents'], $row['required_documents'], $row['reviewer_name'], $row['status']];
                        fputcsv($output, array_map(static function ($value): string {
                            $cell = (string) ($value ?? '');
                            return preg_match('/^[\s]*[=+\-@]/', $cell) ? "'" . $cell : $cell;
                        }, $cells));
                    }
                    $exportSize = ftell($output);
                    rewind($output);
                    StaffService::recordReportExport($database, $user, $_POST, count($report['rows']));
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Length: ' . $exportSize);
                    header('Content-Disposition: attachment; filename="admitflow-applications-' . date('Y-m-d') . '.csv"');
                    fpassthru($output);
                    fclose($output);
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
                if ($path === '/staff/program/save' && (int) ($_POST['program_id'] ?? 0) < 1) {
                    $_SESSION['academic_create_form_type'] = 'program';
                } elseif ($path === '/staff/year/save' && (int) ($_POST['year_id'] ?? 0) < 1) {
                    $_SESSION['academic_create_form_type'] = 'year';
                } elseif ($path === '/staff/requirement/save' && (int) ($_POST['requirement_id'] ?? 0) < 1) {
                    $_SESSION['academic_create_form_type'] = 'requirement';
                } elseif ($path === '/staff/user/save' && (int) ($_POST['user_id'] ?? 0) < 1) {
                    $_SESSION['staff_create_form_open'] = true;
                    $_SESSION['staff_create_form_values'] = array_intersect_key($_POST, array_flip(['full_name', 'email', 'role']));
                }
                if (isset($_SESSION['academic_create_form_type'])) {
                    $_SESSION['academic_create_form_values'] = array_intersect_key($_POST, array_flip(['code', 'name', 'label', 'starts_on', 'ends_on', 'application_deadline', 'is_open', 'allowed_extensions', 'max_file_size_bytes', 'academic_program_id', 'academic_year_id', 'instructions', 'is_required', 'is_active']));
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
                    redirect('/staff/settings' . (($_POST['setting_type'] ?? '') === 'requirement' ? '#requirements' : (($_POST['setting_type'] ?? '') === 'year' ? '#year-settings' : '#program-settings')));
                }
                if (in_array($path, ['/staff/program/save', '/staff/year/save', '/staff/requirement/save'], true)) {
                    redirect('/staff/settings');
                }
                if ($path === '/staff/user/save') {
                    redirect('/staff/users');
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
            'SELECT d.storage_name, d.original_name, d.detected_mime_type FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = :document_id AND d.is_current = 1 AND a.student_id = :student_id AND a.deleted_at IS NULL'
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
            $application = StaffService::detail($database, $user, (int) $match[1], false, $_GET);
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
        try {
            $report = StaffService::applications($database, $user, $_GET, true, true);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check report filters', 'message' => $error->getMessage()]);
            exit;
        }
        $options = StaffService::filters($database, $user);
        render_page('staff/reports', ['title' => 'Reports and print center', 'user' => $user, 'report' => $report, 'counts' => $report['summary']['statuses']] + $options);
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
        render_page('staff/audit', ['title' => 'Audit log', 'user' => $user, 'events' => $auditPage['rows'], 'pagination' => $auditPage['pagination'], 'filters' => $auditPage['filters']]);
        exit;
    }

    if ($path === '/staff/settings') {
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
            $settingsLists = StaffService::academicSettings($database, $user, $_GET);
        } catch (DomainException $error) {
            http_response_code(422);
            render_page('errors/not-ready', ['title' => 'Check academic setting filters', 'message' => $error->getMessage()]);
            exit;
        }
        $createFormType = (string) ($_SESSION['academic_create_form_type'] ?? '');
        $createFormValues = is_array($_SESSION['academic_create_form_values'] ?? null) ? $_SESSION['academic_create_form_values'] : [];
        $createFormError = (string) ($_SESSION['academic_create_form_error'] ?? '');
        unset($_SESSION['academic_create_form_type'], $_SESSION['academic_create_form_values'], $_SESSION['academic_create_form_error']);
        render_page('staff/settings', ['title' => 'Academic settings', 'user' => $user, 'createFormType' => $createFormType, 'createFormValues' => $createFormValues, 'createFormError' => $createFormError] + $settingsLists);
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
        $roleFilter = (string) ($_GET['role'] ?? '');
        if (!in_array($roleFilter, ['', 'admin', 'reviewer', 'student'], true)) {
            $roleFilter = '';
        }
        $statusFilter = (string) ($_GET['account_status'] ?? '');
        if (!in_array($statusFilter, ['', 'active', 'inactive', 'locked'], true)) {
            $statusFilter = '';
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
        $userFilters = ['role' => $roleFilter, 'account_status' => $statusFilter, 'q' => $searchFilter, 'start_date' => $startDateFilter, 'end_date' => $endDateFilter, 'date_range' => $dateRangeFilter];
        $rolesToLoad = $roleFilter === '' ? ['admin', 'reviewer', 'student'] : [$roleFilter];
        foreach ($rolesToLoad as $accountRole) {
            $where = ['role = :role'];
            $parameters = ['role' => $accountRole];
            if ($statusFilter !== '') {
                $where[] = 'account_status = :account_status';
                $parameters['account_status'] = $statusFilter;
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
            $query = $database->prepare('SELECT id, full_name, email, role, account_status, last_login_at, created_at FROM users WHERE ' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $pagination['page_size'] . ' OFFSET ' . $pagination['offset']);
            $query->execute($parameters);
            $accountsByRole[$accountRole] = $query->fetchAll();
            $pagination['page_key'] = $pageKey;
            $pagination['base_url'] = '/staff/users';
            $pagination['query'] = $userFilters + ['per_page' => $pagination['page_size']];
            $paginationByRole[$accountRole] = $pagination;
        }
        $userCategories = array_values(array_filter([
            ['key' => 'admin', 'id' => 'admin-accounts', 'title' => 'Administrator accounts'],
            ['key' => 'reviewer', 'id' => 'staff-accounts', 'title' => 'Staff accounts'],
            ['key' => 'student', 'id' => 'student-accounts', 'title' => 'Student accounts'],
        ], static fn (array $category): bool => $roleFilter === '' || $category['key'] === $roleFilter));
        $staffSetupLink = $_SESSION['staff_setup_link'] ?? null;
        $staffCreateFormOpen = !empty($_SESSION['staff_create_form_open']);
        $staffCreateFormValues = is_array($_SESSION['staff_create_form_values'] ?? null) ? $_SESSION['staff_create_form_values'] : [];
        unset($_SESSION['staff_create_form_open'], $_SESSION['staff_create_form_values']);
        unset($_SESSION['staff_setup_link']);
        render_page('staff/users', ['title' => 'User administration', 'user' => $user, 'accountsByRole' => $accountsByRole, 'accountTotals' => $accountTotals, 'userCategories' => $userCategories, 'paginationByRole' => $paginationByRole, 'userFilters' => $userFilters, 'staffSetupLink' => $staffSetupLink, 'staffCreateFormOpen' => $staffCreateFormOpen, 'staffCreateFormValues' => $staffCreateFormValues]);
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
        render_page('staff/backups', ['title' => 'Backups and restore', 'user' => $user, 'backups' => $backupPage['rows'], 'pagination' => $backupPage['pagination'], 'backupFilters' => $backupPage['filters'], 'restoreInstructions' => BackupService::safeRestoreInstructions()]);
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
        readfile($file);
        exit;
    }

    http_response_code(404);
    render_page('errors/not-found', ['title' => 'Page not found']);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('[admitflow] request failed (' . $error::class . ').');
    http_response_code(500);
    render_page('errors/server-error', ['title' => 'Something went wrong']);
}
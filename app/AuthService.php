<?php
declare(strict_types=1);

final class AuthService
{
    public static function loginHistory(PDO $database, string $email, array $input = []): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['rows' => [], 'pagination' => Pagination::make(0, $input), 'filters' => ['q' => '', 'outcome' => '', 'start_date' => '', 'end_date' => '', 'date_range' => '']];
        }
        $where = ['email_normalized = :email'];
        $parameters = ['email' => $email];
        $search = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($search) > 45) throw new DomainException('Keep the sign-in history search under 45 characters.');
        if ($search !== '') {
            $where[] = 'ip_address LIKE :ip_address';
            $parameters['ip_address'] = '%' . $search . '%';
        }
        $outcome = trim((string) ($input['outcome'] ?? ''));
        if ($outcome !== '' && !in_array($outcome, ['success', 'failure'], true)) throw new DomainException('Choose a valid sign-in outcome.');
        if ($outcome !== '') {
            $where[] = 'was_successful = :was_successful';
            $parameters['was_successful'] = $outcome === 'success' ? 1 : 0;
        }
        $dateFilters = [];
        foreach (['start_date' => 'start', 'end_date' => 'end'] as $key => $boundary) {
            $value = trim((string) ($input[$key] ?? ''));
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) throw new DomainException('Enter valid sign-in dates using year-month-day format.');
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $where[] = 'attempted_at ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $parameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $dateFilters[$key] = $value;
            }
        }
        if (isset($dateFilters['start_date'], $dateFilters['end_date']) && $dateFilters['start_date'] > $dateFilters['end_date']) throw new DomainException('The sign-in start date must not be later than its end date.');
        $whereSql = implode(' AND ', $where);
        $count = $database->prepare('SELECT COUNT(*) FROM login_attempts WHERE ' . $whereSql);
        $count->execute($parameters);
        $pagination = Pagination::make((int) $count->fetchColumn(), $input, 'page', 'per_page', 20);
        $statement = $database->prepare('SELECT was_successful, ip_address, attempted_at FROM login_attempts WHERE ' . $whereSql . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . $pagination['page_size'] . ' OFFSET ' . $pagination['offset']);
        $statement->execute($parameters);
        $filters = ['q' => $search, 'outcome' => $outcome, 'start_date' => $dateFilters['start_date'] ?? '', 'end_date' => $dateFilters['end_date'] ?? '', 'date_range' => (string) ($input['date_range'] ?? '')];
        $pagination['base_url'] = '/account/password';
        $pagination['target'] = 'login-history-results';
        $pagination['query'] = array_filter($filters, static fn (string $value): bool => $value !== '');

        return ['rows' => $statement->fetchAll(), 'pagination' => $pagination, 'filters' => $filters];
    }

    public static function newCaptcha(): string
    {
        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $_SESSION['captcha_answer'] = (string) ($left + $right);

        return sprintf('%d + %d', $left, $right);
    }

    public static function register(PDO $database, array $input): array
    {
        $name = trim((string) ($input['full_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');

        if ($name === '' || mb_strlen($name) > 160) {
            return ['ok' => false, 'message' => 'Enter your full name.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            return ['ok' => false, 'message' => 'Enter a valid email address.'];
        }
        if (!SecurityPolicy::passwordLengthIsValid($password)) {
            return ['ok' => false, 'message' => 'Choose a password between 10 and 200 characters.'];
        }
        if (!hash_equals($password, $confirmation)) {
            return ['ok' => false, 'message' => 'The password confirmation does not match.'];
        }
        if (($input['privacy_consent'] ?? '') !== '1') {
            return ['ok' => false, 'message' => 'Please confirm the privacy notice to create an account.'];
        }

        try {
            $database->beginTransaction();
            $statement = $database->prepare(
                'INSERT INTO users (full_name, email, password_hash, role) VALUES (:name, :email, :password_hash, \'student\')'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $database->lastInsertId();

            $profile = $database->prepare('INSERT INTO student_profiles (user_id, privacy_consent_at) VALUES (:user_id, UTC_TIMESTAMP())');
            $profile->execute(['user_id' => $userId]);

            self::audit($database, $userId, $name, 'account.registered', 'user', $userId);
            $database->commit();

            return ['ok' => true, 'message' => 'Your account is ready. Sign in to start your application.'];
        } catch (PDOException $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            if ($error->getCode() === '23000') {
                return ['ok' => false, 'message' => 'An account with that email address already exists.'];
            }
            throw $error;
        }
    }

    public static function login(PDO $database, array $input, ?DateTimeImmutable $now = null): array
    {
        $current = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $captchaAnswer = trim((string) ($input['captcha_answer'] ?? ''));
        $expectedCaptcha = (string) ($_SESSION['captcha_answer'] ?? '');
        unset($_SESSION['captcha_answer']);

        $ip = request_ip();
        $failures = LoginRateLimitPolicy::failureCounts($database, $email, $ip, $current);
        $accountRemaining = LoginRateLimitPolicy::remainingCooldown($failures['account'], $failures['account_latest'], $current);
        $cooldownSeconds = max($accountRemaining, (int) $failures['ip_remaining']);
        $limited = LoginRateLimitPolicy::isLimited($failures['account'], $failures['ip'], $failures['account_latest'], (int) $failures['ip_remaining'], $current);
        if ($limited) {
            return [
                'ok' => false,
                'error' => 'cooldown',
                'message' => LoginRateLimitPolicy::formatCooldownMessage($cooldownSeconds),
                'cooldown_seconds' => $cooldownSeconds,
            ];
        }

        $captchaValid = $expectedCaptcha !== '' && ctype_digit($captchaAnswer) && hash_equals($expectedCaptcha, $captchaAnswer);
        if (!$captchaValid) {
            return ['ok' => false, 'error' => 'captcha', 'message' => 'The security answer is incorrect. Please solve it and try again.'];
        }

        $user = null;
        if ($email !== '' && $password !== '') {
            $statement = $database->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = :email AND account_status = \'active\' LIMIT 1');
            $statement->execute(['email' => $email]);
            $candidate = $statement->fetch();
            if (is_array($candidate) && password_verify($password, $candidate['password_hash'])) {
                $user = $candidate;
            }
        }

        $countAttempt = $email !== '' && $password !== '';
        if ($countAttempt) {
            $database->beginTransaction();
            try {
            $record = $database->prepare('INSERT INTO login_attempts (email_normalized, ip_address, was_successful, attempted_at) VALUES (:email, :ip, :success, :attempted_at)');
            $record->execute(['email' => $email, 'ip' => $ip, 'success' => $user === null ? 0 : 1, 'attempted_at' => $current->format('Y-m-d H:i:s')]);

                if ($user === null) {
                    self::audit($database, null, 'Unknown or unauthenticated actor', 'auth.login_failed', 'user', null, 'failure', 'warning');
                } else {
                    $update = $database->prepare('UPDATE users SET last_login_at = :last_login_at WHERE id = :id');
                    $update->execute(['last_login_at' => gmdate('Y-m-d H:i:s'), 'id' => $user['id']]);
                    self::audit($database, (int) $user['id'], $user['full_name'], 'auth.login_succeeded', 'user', (int) $user['id']);
                }
                $database->commit();
            } catch (Throwable $error) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $error;
            }
        }

        if ($user === null) {
            $failures = LoginRateLimitPolicy::failureCounts($database, $email, $ip, $current);
            $accountRemaining = LoginRateLimitPolicy::remainingCooldown($failures['account'], $failures['account_latest'], $current);
            $cooldownSeconds = max($accountRemaining, (int) $failures['ip_remaining']);
            if (LoginRateLimitPolicy::isLimited($failures['account'], $failures['ip'], $failures['account_latest'], (int) $failures['ip_remaining'], $current)) {
                return [
                    'ok' => false,
                    'error' => 'cooldown',
                    'message' => LoginRateLimitPolicy::formatCooldownMessage($cooldownSeconds),
                    'cooldown_seconds' => $cooldownSeconds,
                ];
            }
            return ['ok' => false, 'error' => 'authentication', 'message' => 'We could not sign you in. Check your details, then try again.'];
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        $_SESSION['last_activity'] = time();

        return ['ok' => true, 'message' => 'You are signed in.'];
    }

    public static function changePassword(PDO $database, array $user, string $currentPassword, string $newPassword, string $confirmation): array
    {
        if (!SecurityPolicy::passwordLengthIsValid($newPassword)) {
            return ['ok' => false, 'message' => 'Choose a password between 10 and 200 characters.'];
        }
        if (!hash_equals($newPassword, $confirmation)) {
            return ['ok' => false, 'message' => 'The password confirmation does not match.'];
        }

        $database->beginTransaction();
        try {
            $lockSuffix = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $query = $database->prepare('SELECT password_hash FROM users WHERE id = :id AND account_status = \'active\'' . $lockSuffix);
            $query->execute(['id' => $user['id']]);
            $passwordHash = $query->fetchColumn();
            if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
                $database->rollBack();
                return ['ok' => false, 'message' => 'The current password is incorrect.'];
            }

            $update = $database->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND account_status = \'active\'');
            $update->execute(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $user['id']]);
            $invalidate = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL');
            $invalidate->execute(['used_at' => gmdate('Y-m-d H:i:s'), 'user_id' => $user['id']]);
            self::audit($database, (int) $user['id'], $user['full_name'], 'auth.password_changed', 'user', (int) $user['id'], 'success', 'notice');
            $database->commit();

            session_regenerate_id(true);
            unset($_SESSION['csrf_token'], $_SESSION['captcha_answer']);

            return ['ok' => true, 'message' => 'Your password has been changed.'];
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    }

    public static function logout(PDO $database, array $user): void
    {
        try {
            $database->beginTransaction();
            self::audit($database, (int) $user['id'], $user['full_name'], 'auth.logout', 'user', (int) $user['id']);
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            error_log('[admitflow] Logout audit event could not be recorded.');
        } finally {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
                $parameters = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        }
    }

    private static function audit(PDO $database, ?int $userId, string $actor, string $action, ?string $entity, ?int $entityId, string $outcome = 'success', string $severity = 'info'): void
    {
        $statement = $database->prepare(
            'INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address) VALUES (:user_id, :actor_label, :action, :entity_type, :entity_id, :outcome, :severity, :ip_address)'
        );
        $statement->execute([
            'user_id' => $userId,
            'actor_label' => mb_substr($actor, 0, 160),
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'outcome' => $outcome,
            'severity' => $severity,
            'ip_address' => request_ip(),
        ]);
    }
}
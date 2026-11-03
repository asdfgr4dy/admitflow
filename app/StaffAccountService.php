<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/SecurityPolicy.php';
require_once __DIR__ . '/MailService.php';

final class StaffAccountService
{
    public static function create(PDO $database, array $actor, string $name, string $email, string $role, ?callable $delivery = null): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if (($actor['role'] ?? '') !== 'admin' || (int) ($actor['id'] ?? 0) < 1) {
            throw new DomainException('Administrator access is required to create staff accounts.');
        }
        if ($name === '' || mb_strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254 || !in_array($role, ['reviewer', 'admin'], true)) {
            throw new DomainException('Enter a valid staff name, email, and role.');
        }

        $database->beginTransaction();
        try {
            $initialPassword = bin2hex(random_bytes(32));
            $insert = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status, must_change_password) VALUES (:name, :email, :password_hash, :role, \'active\', 1)');
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($initialPassword, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            $userId = (int) $database->lastInsertId();

            AuditLog::record($database, $actor, 'user.staff_account_created', 'user', $userId, 'success', ['role' => $role], 'notice');
            $database->commit();

            $invitation = self::sendInvitation($database, $actor, $userId, false, $delivery);
            return ['user_id' => $userId, 'invitation' => $invitation];
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                $existingAccount = $database->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
                $existingAccount->execute(['email' => $email]);
                if ($existingAccount->fetchColumn()) {
                    throw new DomainException('That email address already has an account.');
                }
            }
            throw $error;
        }
    }

    public static function sendInvitation(PDO $database, array $actor, int $targetUserId, bool $resend = true, ?callable $delivery = null): array
    {
        if (($actor['role'] ?? '') !== 'admin' || (int) ($actor['id'] ?? 0) < 1) {
            throw new DomainException('Administrator access is required to send staff invitations.');
        }
        if ($targetUserId < 1) throw new DomainException('Choose a valid staff account.');

        $database->beginTransaction();
        try {
            $lockSuffix = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $lookup = $database->prepare('SELECT id, full_name, email, role, account_status, must_change_password FROM users WHERE id = :id' . $lockSuffix);
            $lookup->execute(['id' => $targetUserId]);
            $account = $lookup->fetch();
            if (!is_array($account) || !in_array($account['role'], ['reviewer', 'admin'], true) || $account['account_status'] !== 'active' || (int) $account['must_change_password'] !== 1) {
                $database->rollBack();
                throw new DomainException('This active staff account is not awaiting password setup.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $windowStart = $now->sub(new DateInterval('PT' . PasswordResetService::REQUEST_WINDOW_SECONDS . 'S'))->format('Y-m-d H:i:s');
            $count = $database->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = :user_id AND created_at >= :window_start');
            $count->execute(['user_id' => $targetUserId, 'window_start' => $windowStart]);
            if ((int) $count->fetchColumn() >= PasswordResetService::REQUEST_LIMIT) {
                $database->rollBack();
                AuditLog::record($database, $actor, 'user.staff_invitation_delivery_failed', 'user', $targetUserId, 'failure', ['reason' => 'rate_limited'], 'warning');
                return ['ok' => false, 'reason' => 'rate_limited', 'message' => 'Please wait before requesting another invitation email.'];
            }

            $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL')->execute([
                'used_at' => $now->format('Y-m-d H:i:s'),
                'user_id' => $targetUserId,
            ]);
            $token = bin2hex(random_bytes(32));
            $expiresAt = $now->add(new DateInterval('PT30M'));
            $insert = $database->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
            $insert->execute(['user_id' => $targetUserId, 'token_hash' => hash('sha256', $token), 'expires_at' => $expiresAt->format('Y-m-d H:i:s')]);
            AuditLog::record($database, $actor, $resend ? 'user.staff_invitation_resent' : 'user.staff_invitation_created', 'user', $targetUserId, 'success', [], 'notice');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }

        try {
            $result = $delivery === null
                ? MailService::sendStaffInvitation((string) $account['email'], (string) $account['full_name'], $token, $resend)
                : (array) $delivery((string) $account['email'], (string) $account['full_name'], $token, $resend);
        } catch (Throwable $error) {
            error_log('[admitflow] Staff invitation transport failed (' . $error::class . ').');
            $result = ['ok' => false, 'message' => 'The SMTP provider did not accept the invitation email.'];
        }

        if (empty($result['ok'])) {
            $invalidate = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND token_hash = :token_hash AND used_at IS NULL');
            $invalidate->execute(['used_at' => gmdate('Y-m-d H:i:s'), 'user_id' => $targetUserId, 'token_hash' => hash('sha256', $token)]);
            AuditLog::record($database, $actor, 'user.staff_invitation_delivery_failed', 'user', $targetUserId, 'failure', ['reason' => 'transport_or_configuration'], 'warning');
            return ['ok' => false, 'reason' => 'delivery_failed', 'message' => (string) ($result['message'] ?? 'The invitation email could not be delivered.')];
        }

        AuditLog::record($database, $actor, 'user.staff_invitation_delivery_succeeded', 'user', $targetUserId, 'success', [], 'notice');
        return ['ok' => true, 'reason' => 'sent', 'message' => 'The SMTP provider accepted the invitation email.'];
    }

    public static function delete(PDO $database, array $actor, int $targetUserId): void
    {
        if (($actor['role'] ?? '') !== 'admin' || (int) ($actor['id'] ?? 0) < 1) {
            self::recordDeleteAttempt($database, $actor, $targetUserId, 'user.account_delete_blocked', 'failure', ['reason' => 'Administrator access is required.']);
            throw new DomainException('Administrator access is required to delete accounts.');
        }
        if ($targetUserId < 1) {
            self::recordDeleteAttempt($database, $actor, null, 'user.account_delete_blocked', 'warning', ['reason' => 'invalid_account']);
            throw new DomainException('Choose a valid account to delete.');
        }
        if ((int) $actor['id'] === $targetUserId) {
            self::recordDeleteAttempt($database, $actor, $targetUserId, 'user.account_delete_blocked', 'warning', ['reason' => 'self_delete']);
            throw new DomainException('You cannot delete your own account.');
        }

        $stagedAvatar = [];
        $target = null;
        try {
            $database->beginTransaction();
            $lockSuffix = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $targetQuery = $database->prepare('SELECT id, full_name, email, role, account_status FROM users WHERE id = :id' . $lockSuffix);
            $targetQuery->execute(['id' => $targetUserId]);
            $target = $targetQuery->fetch();
            if (!is_array($target)) throw new DomainException('That account could not be found.');

            if ($target['role'] === 'admin' && $target['account_status'] === 'active') {
                $activeAdminQuery = $database->query('SELECT id FROM users WHERE role = \'admin\' AND account_status = \'active\' ORDER BY id' . $lockSuffix);
                if (count($activeAdminQuery->fetchAll(PDO::FETCH_COLUMN)) <= 1) {
                    throw new DomainException('At least one active administrator account must remain.');
                }
            }

            $blockingHistory = [
                'applications' => 'SELECT COUNT(*) FROM applications WHERE student_id = :user_id',
                'uploaded_documents' => 'SELECT COUNT(*) FROM application_documents WHERE uploaded_by = :user_id',
                'document_reviews' => 'SELECT COUNT(*) FROM document_reviews WHERE reviewer_id = :user_id',
                'reviewer_assignments' => 'SELECT COUNT(*) FROM reviewer_assignments WHERE reviewer_id = :reviewer_id OR assigned_by = :assigned_by',
                'screening_notes' => 'SELECT COUNT(*) FROM screening_notes WHERE author_id = :user_id',
                'interviews' => 'SELECT COUNT(*) FROM interviews WHERE interviewer_id = :interviewer_id OR created_by = :created_by',
                'enrollment_records' => 'SELECT COUNT(*) FROM enrollment_records WHERE prepared_by = :user_id',
                'announcements' => 'SELECT COUNT(*) FROM announcements WHERE created_by = :user_id',
            ];
            foreach ($blockingHistory as $historyName => $querySql) {
                $historyQuery = $database->prepare($querySql);
                $historyParameters = str_contains($querySql, ':reviewer_id')
                    ? ['reviewer_id' => $targetUserId, 'assigned_by' => $targetUserId]
                    : (str_contains($querySql, ':interviewer_id') ? ['interviewer_id' => $targetUserId, 'created_by' => $targetUserId] : ['user_id' => $targetUserId]);
                $historyQuery->execute($historyParameters);
                if ((int) $historyQuery->fetchColumn() > 0) {
                    throw new DomainException('This account has admission history (' . $historyName . ') and must be deactivated instead.');
                }
            }

            foreach (['student_profiles', 'notifications', 'password_reset_tokens'] as $table) {
                $remove = $database->prepare('DELETE FROM ' . $table . ' WHERE user_id = :user_id');
                $remove->execute(['user_id' => $targetUserId]);
            }
            $stagedAvatar = AvatarService::stageRemoval($targetUserId);
            $delete = $database->prepare('DELETE FROM users WHERE id = :user_id');
            $delete->execute(['user_id' => $targetUserId]);
            if ($delete->rowCount() !== 1) throw new DomainException('The account changed before it could be deleted.');

            AuditLog::record($database, $actor, 'user.account_deleted', 'user', $targetUserId, 'success', [
                'deleted_account' => ['full_name' => $target['full_name'], 'email' => $target['email'], 'role' => $target['role']],
            ], 'notice');
            $database->commit();
        } catch (DomainException $error) {
            if ($database->inTransaction()) $database->rollBack();
            AvatarService::restoreStagedRemoval($stagedAvatar);
            self::recordDeleteAttempt($database, $actor, $targetUserId, 'user.account_delete_blocked', 'warning', ['reason' => $error->getMessage()]);
            throw $error;
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            AvatarService::restoreStagedRemoval($stagedAvatar);
            self::recordDeleteAttempt($database, $actor, $targetUserId, 'user.account_delete_failed', 'failure', ['reason' => 'transaction_failed']);
            throw $error;
        }

        try {
            AvatarService::finalizeStagedRemoval($stagedAvatar);
        } catch (Throwable $error) {
            error_log('[admitflow account deletion] Staged avatar cleanup failed (' . $error::class . ').');
        }
    }

    private static function recordDeleteAttempt(PDO $database, array $actor, ?int $targetUserId, string $action, string $outcome, array $details): void
    {
        try {
            AuditLog::record($database, $actor, $action, 'user', $targetUserId, $outcome, $details, $outcome === 'failure' ? 'warning' : 'notice');
        } catch (Throwable $error) {
            error_log('[admitflow account deletion] Audit event could not be recorded (' . $error::class . ').');
        }
    }
}
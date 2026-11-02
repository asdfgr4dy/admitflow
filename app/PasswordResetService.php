<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditLog.php';

require_once __DIR__ . '/PasswordResetDelivery.php';

final class PasswordResetService
{
    public const REQUEST_WINDOW_SECONDS = 900;
    public const REQUEST_LIMIT = 3;

    public static function passwordContextForToken(PDO $database, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return [];
        }

        $statement = $database->prepare('SELECT u.full_name, u.email FROM password_reset_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = :token_hash AND t.used_at IS NULL AND t.expires_at > :now AND u.account_status = \'active\' LIMIT 1');
        $statement->execute(['token_hash' => hash('sha256', $token), 'now' => gmdate('Y-m-d H:i:s')]);
        $context = $statement->fetch();
        return is_array($context) ? ['name' => (string) $context['full_name'], 'email' => (string) $context['email']] : [];
    }

    public static function request(PDO $database, string $email, ?callable $delivery = null): bool
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            AuditLog::record($database, null, 'auth.password_reset_request_failed', 'user', null, 'failure', ['reason' => 'invalid_request'], 'warning');
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->add(new DateInterval('PT30M'));
        $database->beginTransaction();
        try {
            $lockSuffix = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $lookup = $database->prepare('SELECT id, full_name FROM users WHERE email = :email AND account_status = \'active\' LIMIT 1' . $lockSuffix);
            $lookup->execute(['email' => $email]);
            $user = $lookup->fetch();
            if (!is_array($user)) {
                $database->rollBack();
                AuditLog::record($database, null, 'auth.password_reset_request_failed', 'user', null, 'warning', ['reason' => 'unavailable_or_rate_limited'], 'warning');
                return false;
            }
            $windowStart = $now->sub(new DateInterval('PT' . self::REQUEST_WINDOW_SECONDS . 'S'))->format('Y-m-d H:i:s');
            $count = $database->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = :user_id AND created_at >= :window_start');
            $count->execute(['user_id' => $user['id'], 'window_start' => $windowStart]);
            if ((int) $count->fetchColumn() >= self::REQUEST_LIMIT) {
                $database->rollBack();
                AuditLog::record($database, null, 'auth.password_reset_request_failed', 'user', null, 'warning', ['reason' => 'unavailable_or_rate_limited'], 'warning');
                return false;
            }
            $invalidate = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL');
            $invalidate->execute(['used_at' => $now->format('Y-m-d H:i:s'), 'user_id' => $user['id']]);
            $insert = $database->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
            $insert->execute(['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => $expiresAt->format('Y-m-d H:i:s')]);
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }

        try {
            $delivered = $delivery === null
                ? PasswordResetDelivery::deliver($email, (string) $user['full_name'], $token)
                : (bool) $delivery($email, (string) $user['full_name'], $token);
        } catch (Throwable $error) {
            error_log('[admitflow] Password reset delivery failed.');
            $delivered = false;
        }

        if (!$delivered) {
            $invalidateFailed = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND token_hash = :token_hash AND used_at IS NULL');
            $invalidateFailed->execute(['used_at' => gmdate('Y-m-d H:i:s'), 'user_id' => $user['id'], 'token_hash' => hash('sha256', $token)]);
            error_log('[admitflow] Password reset delivery failed; the generated token was invalidated.');
            AuditLog::record($database, ['id' => (int) $user['id']], 'auth.password_reset_request_failed', 'user', (int) $user['id'], 'failure', ['reason' => 'delivery_failed'], 'warning');
            return false;
        }

        AuditLog::record($database, ['id' => (int) $user['id']], 'auth.password_reset_requested', 'user', (int) $user['id'], 'success', [], 'notice');

        return true;
    }

    public static function reset(PDO $database, string $token, string $password, string $confirmation): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            AuditLog::record($database, null, 'auth.password_reset_failed', 'user', null, 'failure', ['reason' => 'malformed_token'], 'warning');
            return ['ok' => false, 'message' => 'This reset link is invalid or expired. Request a new one.'];
        }

        try {
            $normalizedPassword = SecurityPolicy::normalizePassword($password);
            $normalizedConfirmation = SecurityPolicy::normalizePassword($confirmation);
        } catch (RuntimeException) {
            $normalizedPassword = '';
            $normalizedConfirmation = '';
            $passwordErrors = ['Password validation is temporarily unavailable. Please try again later.'];
        }
        $passwordContext = self::passwordContextForToken($database, $token);
        $passwordErrors ??= SecurityPolicy::passwordErrors($normalizedPassword, $passwordContext);
        if (!hash_equals($normalizedPassword, $normalizedConfirmation)) {
            $passwordErrors[] = 'The password confirmation does not match.';
        }
        if ($passwordErrors !== []) {
            AuditLog::record($database, null, 'auth.password_reset_failed', 'user', null, 'failure', ['reason' => 'password_policy'], 'warning');
            return ['ok' => false, 'message' => implode(' ', $passwordErrors), 'errors' => $passwordErrors];
        }

        $database->beginTransaction();
        try {
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockSuffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $now = gmdate('Y-m-d H:i:s');
            $lookup = $database->prepare('SELECT t.id, t.user_id, u.full_name, u.email FROM password_reset_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = :token_hash AND t.used_at IS NULL AND t.expires_at > :now AND u.account_status = \'active\' LIMIT 1' . $lockSuffix);
            $lookup->execute(['token_hash' => hash('sha256', $token), 'now' => $now]);
            $reset = $lookup->fetch();
            if (!is_array($reset)) {
                $database->rollBack();
                AuditLog::record($database, null, 'auth.password_reset_failed', 'user', null, 'failure', ['reason' => 'invalid_or_expired'], 'warning');
                return ['ok' => false, 'message' => 'This reset link is invalid or expired. Request a new one.'];
            }

            $passwordErrors = SecurityPolicy::passwordErrors($normalizedPassword, ['name' => $reset['full_name'], 'email' => $reset['email']]);
            if ($passwordErrors !== []) {
                $database->rollBack();
                AuditLog::record($database, null, 'auth.password_reset_failed', 'user', null, 'failure', ['reason' => 'password_policy'], 'warning');
                return ['ok' => false, 'message' => implode(' ', $passwordErrors), 'errors' => $passwordErrors];
            }

            $updateUser = $database->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :user_id AND account_status = \'active\'');
            $updateUser->execute(['password_hash' => password_hash($normalizedPassword, PASSWORD_DEFAULT), 'user_id' => $reset['user_id']]);
            if ($updateUser->rowCount() !== 1) {
                $database->rollBack();
                AuditLog::record($database, null, 'auth.password_reset_failed', 'user', null, 'failure', ['reason' => 'account_unavailable'], 'warning');
                return ['ok' => false, 'message' => 'This reset link is invalid or expired. Request a new one.'];
            }
            $markUsed = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE id = :id AND used_at IS NULL');
            $markUsed->execute(['used_at' => $now, 'id' => $reset['id']]);
            $invalidateOther = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND id <> :id AND used_at IS NULL');
            $invalidateOther->execute(['used_at' => $now, 'user_id' => $reset['user_id'], 'id' => $reset['id']]);
            AuditLog::record($database, ['id' => (int) $reset['user_id']], 'auth.password_reset', 'user', (int) $reset['user_id'], 'success', [], 'notice');
            $database->commit();

            return ['ok' => true, 'message' => 'Your password has been changed. Sign in with the new password.'];
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    }

}
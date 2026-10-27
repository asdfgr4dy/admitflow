<?php
declare(strict_types=1);

require_once __DIR__ . '/PasswordResetDelivery.php';

final class PasswordResetService
{
    public const REQUEST_WINDOW_SECONDS = 900;
    public const REQUEST_LIMIT = 3;

    public static function request(PDO $database, string $email, ?callable $delivery = null): bool
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
                return false;
            }
            $windowStart = $now->sub(new DateInterval('PT' . self::REQUEST_WINDOW_SECONDS . 'S'))->format('Y-m-d H:i:s');
            $count = $database->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = :user_id AND created_at >= :window_start');
            $count->execute(['user_id' => $user['id'], 'window_start' => $windowStart]);
            if ((int) $count->fetchColumn() >= self::REQUEST_LIMIT) {
                $database->rollBack();
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
        }

        return $delivered;
    }

    public static function reset(PDO $database, string $token, string $password, string $confirmation): array
    {
        if (!SecurityPolicy::passwordLengthIsValid($password)) {
            return ['ok' => false, 'message' => 'Choose a password between 10 and 200 characters.'];
        }
        if (!hash_equals($password, $confirmation)) {
            return ['ok' => false, 'message' => 'The password confirmation does not match.'];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'message' => 'This reset link is invalid or expired. Request a new one.'];
        }

        $database->beginTransaction();
        try {
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockSuffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $now = gmdate('Y-m-d H:i:s');
            $lookup = $database->prepare('SELECT id, user_id FROM password_reset_tokens WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now LIMIT 1' . $lockSuffix);
            $lookup->execute(['token_hash' => hash('sha256', $token), 'now' => $now]);
            $reset = $lookup->fetch();
            if (!is_array($reset)) {
                $database->rollBack();
                return ['ok' => false, 'message' => 'This reset link is invalid or expired. Request a new one.'];
            }

            $updateUser = $database->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id AND account_status = \'active\'');
            $updateUser->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'user_id' => $reset['user_id']]);
            if ($updateUser->rowCount() !== 1) {
                $database->rollBack();
                return ['ok' => false, 'message' => 'This reset link is invalid or expired. Request a new one.'];
            }
            $markUsed = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE id = :id AND used_at IS NULL');
            $markUsed->execute(['used_at' => $now, 'id' => $reset['id']]);
            $invalidateOther = $database->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND id <> :id AND used_at IS NULL');
            $invalidateOther->execute(['used_at' => $now, 'user_id' => $reset['user_id'], 'id' => $reset['id']]);
            $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity) SELECT id, full_name, \'auth.password_reset\', \'user\', id, \'success\', \'notice\' FROM users WHERE id = :user_id');
            $audit->execute(['user_id' => $reset['user_id']]);
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
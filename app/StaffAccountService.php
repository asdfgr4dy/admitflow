<?php
declare(strict_types=1);

final class StaffAccountService
{
    public static function create(PDO $database, array $actor, string $name, string $email, string $role): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if (($actor['role'] ?? '') !== 'admin' || (int) ($actor['id'] ?? 0) < 1) {
            throw new DomainException('Administrator access is required to create staff accounts.');
        }
        if ($name === '' || mb_strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254 || !in_array($role, ['reviewer', 'admin'], true)) {
            throw new DomainException('Enter a valid staff name, email, and role.');
        }

        $setupToken = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT30M'));
        $database->beginTransaction();
        try {
            $insert = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :password_hash, :role, \'active\')');
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            $userId = (int) $database->lastInsertId();

            $token = $database->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
            $token->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $setupToken),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);
            $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address) VALUES (:user_id, :actor, \'user.staff_account_created\', \'user\', :entity_id, \'success\', \'notice\', :ip)');
            $audit->execute([
                'user_id' => $actor['id'],
                'actor' => $actor['full_name'],
                'entity_id' => $userId,
                'ip' => request_ip(),
            ]);
            $database->commit();

            return ['user_id' => $userId, 'setup_path' => '/reset-password?token=' . rawurlencode($setupToken)];
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
}
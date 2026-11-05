<?php
declare(strict_types=1);

final class DemoAccountCredentials
{
    public const IDENTITIES = [
        'admin' => ['label' => 'Demo Administrator', 'name' => 'Demo Administrator', 'email' => 'demo.admin@example.test', 'role' => 'admin'],
        'reviewer' => ['label' => 'Demo Admissions Staff/Reviewer', 'name' => 'Demo Reviewer', 'email' => 'demo.reviewer@example.test', 'role' => 'reviewer'],
        'student' => ['label' => 'Demo Student Applicant', 'name' => 'Demo Applicant', 'email' => 'demo.student@example.test', 'role' => 'student'],
    ];

    public static function localPath(): string
    {
        return dirname(__DIR__) . '/config/demo-accounts.local.php';
    }

    public static function isEnabled(array $environment, array $databaseConfig, string|false $connectedDatabase): bool
    {
        $applicationEnvironment = strtolower(trim((string) ($environment['ADMITFLOW_APP_ENV'] ?? '')));
        $mode = strtolower(trim((string) ($environment['ADMITFLOW_DEMO_MODE'] ?? '')));
        if ($mode === 'off') return false;
        if (!is_string($connectedDatabase) || $connectedDatabase === '' || $connectedDatabase !== (string) ($databaseConfig['database'] ?? '')) return false;

        if ($applicationEnvironment === 'local') {
            return $mode === '' || $mode === 'local';
        }
        if ($mode !== 'hosted') return false;

        $expectedHost = trim((string) ($environment['ADMITFLOW_DEMO_EXPECTED_DB_HOST'] ?? ''));
        $expectedDatabase = trim((string) ($environment['ADMITFLOW_DEMO_EXPECTED_DB_NAME'] ?? ''));
        return $expectedHost !== ''
            && $expectedDatabase !== ''
            && hash_equals(strtolower($expectedHost), strtolower((string) ($databaseConfig['host'] ?? '')))
            && hash_equals($expectedDatabase, (string) ($databaseConfig['database'] ?? ''))
            && hash_equals($expectedDatabase, $connectedDatabase);
    }

    public static function forLogin(PDO $database): array
    {
        $environment = Database::environment();
        try {
            $databaseConfig = Database::loadConfiguration($environment, dirname(__DIR__) . '/config/database.local.php');
            $connectedDatabase = $database->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable) {
            return [];
        }

        return self::forDatabase($database, is_string($connectedDatabase) ? $connectedDatabase : false, $environment, $databaseConfig);
    }

    public static function forDatabase(PDO $database, string|false $connectedDatabase, array $environment, array $databaseConfig, ?string $credentialPath = null): array
    {
        if (!self::isEnabled($environment, $databaseConfig, $connectedDatabase)) return [];

        $path = $credentialPath ?? self::localPath();
        if (!is_file($path)) return [];
        $passwords = require $path;
        if (!is_array($passwords)) return [];

        $accounts = [];
        foreach (self::IDENTITIES as $key => $identity) {
            $password = $passwords[$key] ?? null;
            if (!is_string($password) || $password === '') return [];
            $accounts[$key] = $identity + ['password' => $password];
        }

        $placeholders = implode(', ', array_fill(0, count(self::IDENTITIES), '?'));
        $users = $database->prepare('SELECT email, role, password_hash FROM users WHERE email IN (' . $placeholders . ')');
        $users->execute(array_column(self::IDENTITIES, 'email'));
        $usersByEmail = [];
        foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $user) {
            $usersByEmail[(string) $user['email']] = $user;
        }

        foreach (self::IDENTITIES as $key => $identity) {
            $user = $usersByEmail[$identity['email']] ?? null;
            if (!is_array($user)
                || (string) $user['role'] !== $identity['role']
                || !password_verify($passwords[$key], (string) $user['password_hash'])) {
                return [];
            }
        }

        return $accounts;
    }
}
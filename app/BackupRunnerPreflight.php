<?php
declare(strict_types=1);

final class BackupRunnerPreflight
{
    public const ALLOWED_PROJECT_ROOT = 'C:\\Users\\Administrator\\Documents\\admitflow';
    public const ALLOWED_HOST = '127.0.0.1';
    public const ALLOWED_PORT = '3306';

    private const ALLOWED_SCHEMA_PRIVILEGES = ['SELECT', 'SHOW VIEW', 'TRIGGER'];
    private const REQUIRED_SCHEMA_PRIVILEGES = ['SELECT', 'SHOW VIEW', 'TRIGGER'];
    private const REQUIRED_INSERT_TABLES = ['audit_logs', 'backups'];

    public static function environment(): array
    {
        $values = [];
        foreach ([
            'ADMITFLOW_APP_ENV',
            'ADMITFLOW_BACKUP_RUNNER_ENABLED',
            'ADMITFLOW_BACKUP_APPROVED_DATABASE',
            'ADMITFLOW_DB_HOST',
            'ADMITFLOW_DB_PORT',
            'ADMITFLOW_DB_DATABASE',
            'ADMITFLOW_DB_USERNAME',
            'ADMITFLOW_DB_PASSWORD',
            'ADMITFLOW_DB_CHARSET',
        ] as $name) {
            $value = getenv($name);
            if ($value !== false) $values[$name] = $value;
        }
        return $values;
    }

    public static function validateRunnerConfiguration(array $configuration, array $environment, string $projectRoot): void
    {
        if (($environment['ADMITFLOW_BACKUP_RUNNER_ENABLED'] ?? '') !== '1') {
            throw new DomainException('Automated backup runner opt-in is required.');
        }
        if (!in_array(strtolower(trim((string) ($environment['ADMITFLOW_APP_ENV'] ?? ''))), ['local', 'development', 'test', 'staging', 'production'], true)) {
            throw new DomainException('Automated backup runner environment is not explicitly allowed.');
        }
        $resolvedRoot = realpath($projectRoot);
        $approvedRoot = realpath(self::ALLOWED_PROJECT_ROOT);
        if ($resolvedRoot === false || $approvedRoot === false
            || strcasecmp(rtrim(str_replace('/', '\\', $resolvedRoot), '\\'), rtrim(str_replace('/', '\\', $approvedRoot), '\\')) !== 0) {
            throw new DomainException('Automated backup runner project root is not approved.');
        }

        $database = (string) ($configuration['database'] ?? '');
        if ($database === ''
            || (string) ($environment['ADMITFLOW_BACKUP_APPROVED_DATABASE'] ?? '') !== $database
            || (string) ($configuration['host'] ?? '') !== self::ALLOWED_HOST
            || (string) ($configuration['port'] ?? '') !== self::ALLOWED_PORT
            || (string) ($configuration['charset'] ?? '') !== 'utf8mb4') {
            throw new DomainException('Database target must match explicit approval for local 127.0.0.1:3306 and utf8mb4.');
        }
        foreach (['host' => self::ALLOWED_HOST, 'port' => self::ALLOWED_PORT, 'database' => $database] as $key => $expected) {
            $environmentKey = 'ADMITFLOW_DB_' . strtoupper($key);
            if (isset($environment[$environmentKey]) && (string) $environment[$environmentKey] !== $expected) {
                throw new DomainException('Database environment overrides do not match the approved target.');
            }
        }

        $username = trim((string) ($configuration['username'] ?? ''));
        if ($username === '' || strcasecmp($username, 'root') === 0 || strcasecmp($username, 'administrator') === 0
            || trim((string) ($configuration['password'] ?? '')) === '') {
            throw new DomainException('A dedicated non-administrative backup account is required.');
        }
    }

    public static function validateGrants(array $grantLines, string $databaseName): void
    {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $databaseName) || $grantLines === []) {
            throw new DomainException('Backup account grants could not be verified.');
        }
        $schema = preg_quote($databaseName, '/');
        $schemaPrivileges = array_fill_keys(self::ALLOWED_SCHEMA_PRIVILEGES, false);
        $insertTables = array_fill_keys(self::REQUIRED_INSERT_TABLES, false);
        $globalUsageFound = false;

        foreach ($grantLines as $grantLine) {
            $grant = trim((string) $grantLine);
            if ($grant === '' || stripos($grant, 'WITH GRANT OPTION') !== false || stripos($grant, 'WITH ADMIN OPTION') !== false
                || preg_match('/\AGRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\b/i', $grant, $matches) !== 1) {
                throw new DomainException('Backup account has a grant option, role, malformed grant, or unverifiable privilege.');
            }
            $privileges = array_map(static fn (string $privilege): string => strtoupper(trim($privilege)), explode(',', trim($matches[1])));
            if (in_array('', $privileges, true) || count($privileges) !== count(array_unique($privileges))) {
                throw new DomainException('Backup account has a malformed or duplicate privilege list.');
            }
            $scope = strtolower(str_replace('`', '', preg_replace('/\s+/', '', trim($matches[2])) ?? ''));

            if ($scope === '*.*') {
                if ($privileges !== ['USAGE'] || $globalUsageFound) {
                    throw new DomainException('Backup account has a global privilege beyond USAGE or a duplicate grant.');
                }
                $globalUsageFound = true;
                continue;
            }
            if ($scope === strtolower($databaseName . '.*')) {
                foreach ($privileges as $privilege) {
                    if (!array_key_exists($privilege, $schemaPrivileges) || $schemaPrivileges[$privilege]) {
                        throw new DomainException('Backup account has an unnecessary, duplicate, or administrative schema privilege.');
                    }
                    $schemaPrivileges[$privilege] = true;
                }
                continue;
            }
            if (preg_match('/\A' . $schema . '\.(audit_logs|backups)\z/i', $scope, $tableMatch) === 1) {
                $table = strtolower($tableMatch[1]);
                if ($privileges !== ['INSERT'] || $insertTables[$table]) {
                    throw new DomainException('Backup account has an unexpected table-level privilege or target.');
                }
                $insertTables[$table] = true;
                continue;
            }
            throw new DomainException('Backup account has privileges outside the approved database schema.');
        }

        if (!$globalUsageFound || in_array(false, $schemaPrivileges, true) || in_array(false, $insertTables, true)) {
            throw new DomainException('Backup account is missing one or more exact required database privileges.');
        }
    }

    public static function verifyConnection(PDO $database, array $configuration): void
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new DomainException('Backup runner requires a MySQL-compatible PDO connection.');
        }
        $identity = $database->query('SELECT DATABASE(), CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        if (!is_array($identity) || count($identity) < 2
            || (string) $identity[0] !== (string) $configuration['database']
            || (string) $identity[0] !== (string) getenv('ADMITFLOW_BACKUP_APPROVED_DATABASE')
            || strcasecmp(explode('@', (string) $identity[1], 2)[0], (string) $configuration['username']) !== 0
            || strcasecmp((string) $configuration['username'], 'root') === 0) {
            throw new DomainException('Connected database identity does not match the approved database and dedicated backup account.');
        }
        $grantLines = $database->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        self::validateGrants($grantLines, (string) $identity[0]);
    }
}
<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = self::loadConfiguration(self::environment(), dirname(__DIR__) . '/config/database.local.php');
        self::validateCredentials($config);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            (int) $config['port'],
            $config['database'],
            $config['charset']
        );

        self::$connection = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);

        return self::$connection;
    }

    public static function environment(): array
    {
        $values = [];
        foreach (['ADMITFLOW_APP_ENV', 'ADMITFLOW_DB_HOST', 'ADMITFLOW_DB_PORT', 'ADMITFLOW_DB_DATABASE', 'ADMITFLOW_DB_USERNAME', 'ADMITFLOW_DB_PASSWORD', 'ADMITFLOW_DB_CHARSET'] as $name) {
            $value = getenv($name);
            if ($value !== false) $values[$name] = $value;
        }
        return $values;
    }

    public static function loadConfiguration(array $environment, ?string $configPath = null): array
    {
        $configPath ??= dirname(__DIR__) . '/config/database.local.php';
        $config = is_file($configPath) ? require $configPath : [];
        if (!is_array($config)) throw new RuntimeException('Database configuration file must return an array.');
        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            $environmentKey = 'ADMITFLOW_DB_' . strtoupper($key);
            if (array_key_exists($environmentKey, $environment)) $config[$key] = $environment[$environmentKey];
        }
        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException('Database configuration is incomplete. Set ADMITFLOW_DB_* environment variables or configure config/database.local.php.');
            }
        }
        $port = filter_var($config['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || !preg_match('/\A[A-Za-z0-9_.:-]+\z/', (string) $config['host']) || !preg_match('/\A[A-Za-z0-9_]+\z/', (string) $config['database'])) {
            throw new RuntimeException('Database host, port, or schema name is invalid.');
        }
        $config['port'] = $port;
        return $config;
    }

    public static function validateCredentials(array $config, ?string $environment = null): void
    {
        $environment ??= (string) (getenv('ADMITFLOW_APP_ENV') ?: '');
        if ($environment !== 'local' && (strtolower((string) ($config['username'] ?? '')) === 'root' || trim((string) ($config['password'] ?? '')) === '')) {
            throw new RuntimeException('Non-local environments require a dedicated database user and a non-empty password.');
        }
    }
}
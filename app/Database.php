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

        $configPath = dirname(__DIR__) . '/config/database.local.php';
        $config = is_file($configPath) ? require $configPath : [];
        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            $environmentKey = 'ADMITFLOW_DB_' . strtoupper($key);
            $environmentValue = getenv($environmentKey);
            if ($environmentValue !== false) {
                $config[$key] = $environmentValue;
            }
        }
        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException('Database configuration is incomplete. Set ADMITFLOW_DB_* environment variables or configure config/database.local.php.');
            }
        }
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

    public static function validateCredentials(array $config, ?string $environment = null): void
    {
        $environment ??= (string) (getenv('ADMITFLOW_APP_ENV') ?: '');
        if ($environment !== 'local' && (strtolower((string) ($config['username'] ?? '')) === 'root' || trim((string) ($config['password'] ?? '')) === '')) {
            throw new RuntimeException('Non-local environments require a dedicated database user and a non-empty password.');
        }
    }
}
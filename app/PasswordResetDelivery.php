<?php
declare(strict_types=1);

final class PasswordResetDelivery
{
    public static function isValidApplicationUrl(string $url, bool $allowInsecureLocal): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        return $allowInsecureLocal && $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function deliver(string $email, string $fullName, string $token): bool
    {
        $isLocal = getenv('ADMITFLOW_APP_ENV') === 'local';
        $configuredUrl = getenv('ADMITFLOW_APP_URL');
        $baseUrl = is_string($configuredUrl) ? rtrim($configuredUrl, '/') : '';
        if ($isLocal && $baseUrl === '') {
            $baseUrl = 'http://127.0.0.1:8000';
        }
        $mailEnabled = getenv('ADMITFLOW_MAIL_ENABLED') === '1';
        $localFallbackEnabled = getenv('ADMITFLOW_MAIL_LOCAL_FALLBACK') === '1';
        if (self::shouldUseLocalFallback($isLocal, $mailEnabled, $localFallbackEnabled)) {
            return self::writeLocalMessage($email, $fullName, $token, $baseUrl);
        }
        if (!$mailEnabled || !self::hasSmtpConfiguration()) {
            error_log('[admitflow] Password reset delivery is not configured.');
            return false;
        }
        if (!self::isValidApplicationUrl($baseUrl, $isLocal)) {
            error_log('[admitflow] Password reset delivery skipped: configure a valid absolute application URL.');
            return false;
        }
        if ($isLocal && !self::isValidApplicationUrl($baseUrl, true)) {
            error_log('[admitflow] Password reset delivery skipped: local HTTP URLs must use localhost.');
            return false;
        }

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            error_log('[admitflow] Password reset delivery unavailable: Composer dependencies are not installed.');
            return false;
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log('[admitflow] Password reset delivery unavailable: SMTP library is missing.');
            return false;
        }

        $resetUrl = self::buildResetUrl($baseUrl, $token, $isLocal);
        if ($resetUrl === null) {
            error_log('[admitflow] Password reset delivery skipped: invalid URL configuration or token.');
            return false;
        }
        $safeName = trim(str_replace(["\r", "\n"], '', $fullName));
        $body = "Hello {$safeName},\n\nUse this one-time link within 30 minutes to reset your password:\n{$resetUrl}\n\nIf you did not request this, ignore this message.\n";
        $configuration = self::smtpConfiguration();

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $configuration['host'];
            $mailer->Port = $configuration['port'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $configuration['username'];
            $mailer->Password = $configuration['password'];
            $mailer->Timeout = 10;
            $mailer->SMTPDebug = 0;
            $mailer->SMTPSecure = $configuration['encryption'] === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($configuration['from_address'], $configuration['from_name']);
            $mailer->addAddress($email, $safeName);
            $mailer->Subject = 'admitflow password reset';
            $mailer->Body = $body;

            return $mailer->send();
        } catch (Throwable $error) {
            error_log('[admitflow] SMTP password reset delivery failed (' . $error::class . ').');
            return false;
        }
    }

    public static function hasSmtpConfiguration(): bool
    {
        $configuration = self::smtpConfiguration();
        return getenv('ADMITFLOW_MAIL_ENABLED') === '1'
            && $configuration['host'] !== ''
            && $configuration['port'] > 0
            && $configuration['username'] !== ''
            && $configuration['password'] !== ''
            && in_array($configuration['encryption'], ['tls', 'ssl'], true)
            && filter_var($configuration['from_address'], FILTER_VALIDATE_EMAIL) !== false
            && $configuration['from_name'] !== '';
    }

    public static function shouldUseLocalFallback(bool $isLocal, bool $mailEnabled, bool $fallbackEnabled): bool
    {
        return $isLocal && !$mailEnabled && $fallbackEnabled;
    }

    public static function buildResetUrl(string $baseUrl, string $token, bool $allowInsecureLocal): ?string
    {
        if (!self::isValidApplicationUrl($baseUrl, $allowInsecureLocal) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/reset-password?token=' . rawurlencode($token);
    }

    private static function smtpConfiguration(): array
    {
        $port = filter_var(getenv('ADMITFLOW_MAIL_PORT'), FILTER_VALIDATE_INT);
        return [
            'host' => trim((string) (getenv('ADMITFLOW_MAIL_HOST') ?: '')),
            'port' => is_int($port) && $port >= 1 && $port <= 65535 ? $port : 0,
            'username' => (string) (getenv('ADMITFLOW_MAIL_USERNAME') ?: ''),
            'password' => (string) (getenv('ADMITFLOW_MAIL_PASSWORD') ?: ''),
            'encryption' => strtolower(trim((string) (getenv('ADMITFLOW_MAIL_ENCRYPTION') ?: ''))),
            'from_address' => trim((string) (getenv('ADMITFLOW_MAIL_FROM_ADDRESS') ?: '')),
            'from_name' => trim(str_replace(["\r", "\n"], '', (string) (getenv('ADMITFLOW_MAIL_FROM_NAME') ?: ''))),
        ];
    }

    private static function writeLocalMessage(string $email, string $fullName, string $token, string $baseUrl): bool
    {
        if (!self::isValidApplicationUrl($baseUrl, true)) {
            error_log('[admitflow] Local password-reset delivery failed: invalid application URL.');
            return false;
        }
        $directory = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            error_log('[admitflow] Local password-reset delivery failed: private log directory unavailable.');
            return false;
        }
        $log = $directory . '/password-reset-mail.log';
        $url = self::buildResetUrl($baseUrl, $token, true);
        if ($url === null) {
            error_log('[admitflow] Local password-reset delivery failed: invalid URL or token.');
            return false;
        }
        $entry = '[' . gmdate('Y-m-d H:i:s') . ' UTC] recipient=' . $email . ' name=' . str_replace(["\r", "\n"], '', $fullName) . ' url=' . $url . PHP_EOL;
        if (file_put_contents($log, $entry, FILE_APPEND | LOCK_EX) === false || !chmod($log, 0600)) {
            error_log('[admitflow] Local password-reset delivery failed while writing the private test mailbox.');
            return false;
        }
        return true;
    }
}
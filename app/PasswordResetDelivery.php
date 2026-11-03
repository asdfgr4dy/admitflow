<?php
declare(strict_types=1);

require_once __DIR__ . '/MailService.php';

final class PasswordResetDelivery
{
    public static function isValidApplicationUrl(string $url, bool $allowInsecureLocal): bool
    {
        return MailService::isValidApplicationUrl($url, $allowInsecureLocal);
    }

    public static function deliver(string $email, string $fullName, string $token): bool
    {
        return MailService::sendPasswordReset($email, $fullName, $token)['ok'];
    }

    public static function hasSmtpConfiguration(): bool
    {
        return MailService::configurationStatus()['configured'];
    }

    public static function shouldUseLocalFallback(bool $isLocal, bool $mailEnabled, bool $fallbackEnabled): bool
    {
        return $isLocal && !$mailEnabled && $fallbackEnabled;
    }

    public static function buildResetUrl(string $baseUrl, string $token, bool $allowInsecureLocal): ?string
    {
        if (!self::isValidApplicationUrl($baseUrl, $allowInsecureLocal) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return null;
        }
        return rtrim($baseUrl, '/') . '/reset-password?token=' . rawurlencode($token);
    }
}
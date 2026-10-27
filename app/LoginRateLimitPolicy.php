<?php
declare(strict_types=1);

final class LoginRateLimitPolicy
{
    public const WINDOW_SECONDS = 900;
    public const ACCOUNT_FAILURE_LIMIT = 5;
    public const IP_FAILURE_LIMIT = 30;
    public const ACCOUNT_COOLDOWN_SECONDS = 180;

    public static function isLimited(int $accountFailures, int $ipFailures, ?string $latestAccountFailure = null, ?int $ipRemainingSeconds = null, ?DateTimeImmutable $now = null): bool
    {
        return self::remainingCooldown($accountFailures, $latestAccountFailure, $now) > 0
            || ($ipFailures >= self::IP_FAILURE_LIMIT && ($ipRemainingSeconds ?? 1) > 0);
    }

    public static function remainingCooldown(int $accountFailures, ?string $latestFailure, ?DateTimeImmutable $now = null): int
    {
        if ($accountFailures < self::ACCOUNT_FAILURE_LIMIT || $latestFailure === null) {
            return 0;
        }

        $current = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        try {
            $expires = new DateTimeImmutable($latestFailure, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return self::ACCOUNT_COOLDOWN_SECONDS;
        }
        $remaining = $expires->getTimestamp() + self::ACCOUNT_COOLDOWN_SECONDS - $current->getTimestamp();

        return max(0, $remaining);
    }

    public static function remainingIpLimit(PDO $database, ?string $ip, int $ipFailures, ?DateTimeImmutable $now = null): int
    {
        if ($ip === null || $ipFailures < self::IP_FAILURE_LIMIT) {
            return 0;
        }

        $statement = $database->prepare('SELECT attempted_at FROM login_attempts WHERE attempted_at >= :window_start AND was_successful = 0 AND ip_address = :ip ORDER BY attempted_at DESC LIMIT 1 OFFSET ' . (self::IP_FAILURE_LIMIT - 1));
        $current = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $windowStart = $current->sub(new DateInterval('PT' . self::WINDOW_SECONDS . 'S'))->format('Y-m-d H:i:s');
        $statement->execute(['window_start' => $windowStart, 'ip' => $ip]);
        $thresholdAttempt = $statement->fetchColumn();
        if (!is_string($thresholdAttempt)) {
            return self::WINDOW_SECONDS;
        }

        try {
            $expires = new DateTimeImmutable($thresholdAttempt, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return self::WINDOW_SECONDS;
        }

        return max(0, $expires->getTimestamp() + self::WINDOW_SECONDS - $current->getTimestamp());
    }

    public static function formatCooldownMessage(int $remainingSeconds): string
    {
        $remainingSeconds = max(0, $remainingSeconds);
        $minutes = intdiv($remainingSeconds, 60);
        $seconds = $remainingSeconds % 60;
        $minuteLabel = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        $secondLabel = $seconds . ' second' . ($seconds === 1 ? '' : 's');

        return 'Too many failed login attempts. Please try again in ' . $minuteLabel . ' and ' . $secondLabel . '.';
    }

    public static function failureCounts(PDO $database, string $email, ?string $ip, ?DateTimeImmutable $now = null): array
    {
        $current = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $windowStart = $current->sub(new DateInterval('PT' . self::WINDOW_SECONDS . 'S'))->format('Y-m-d H:i:s');
        $account = $database->prepare('SELECT COUNT(*) AS failure_count, MAX(attempted_at) AS latest_failure FROM login_attempts WHERE attempted_at >= :window_start AND was_successful = 0 AND email_normalized = :email');
        $account->execute(['window_start' => $windowStart, 'email' => $email]);
        $accountRow = $account->fetch();
        $accountFailures = (int) ($accountRow['failure_count'] ?? 0);

        $ipFailures = 0;
        if ($ip !== null) {
            $ipQuery = $database->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= :window_start AND was_successful = 0 AND ip_address = :ip');
            $ipQuery->execute(['window_start' => $windowStart, 'ip' => $ip]);
            $ipFailures = (int) $ipQuery->fetchColumn();
        }

        return [
            'account' => $accountFailures,
            'account_latest' => $accountRow['latest_failure'] ?? null,
            'ip' => $ipFailures,
            'ip_remaining' => self::remainingIpLimit($database, $ip, $ipFailures, $current),
        ];
    }
}
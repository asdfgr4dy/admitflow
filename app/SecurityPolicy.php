<?php
declare(strict_types=1);

final class SecurityPolicy
{
    private const PASSWORD_MIN_LENGTH = 10;
    private const PASSWORD_MAX_LENGTH = 200;

    public static function passwordLengthLimits(): array
    {
        return ['min' => self::PASSWORD_MIN_LENGTH, 'max' => self::PASSWORD_MAX_LENGTH];
    }

    public static function passwordLengthIsValid(string $password): bool
    {
        return strlen($password) >= self::PASSWORD_MIN_LENGTH && strlen($password) <= self::PASSWORD_MAX_LENGTH;
    }

    public static function csrfMatches(string $expected, mixed $submitted): bool
    {
        return is_string($submitted) && $expected !== '' && hash_equals($expected, $submitted);
    }

    public static function hasRole(?array $user, array $allowedRoles): bool
    {
        return $user !== null && isset($user['role']) && in_array($user['role'], $allowedRoles, true);
    }

    public static function ownsRecord(int $authenticatedUserId, int $recordOwnerId): bool
    {
        return $authenticatedUserId > 0 && $authenticatedUserId === $recordOwnerId;
    }
}
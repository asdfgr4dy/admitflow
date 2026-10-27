<?php
declare(strict_types=1);

final class StatusTransitionPolicy
{
    private const TRANSITIONS = [
        'submitted' => ['in_screening', 'documents_incomplete'],
        'in_screening' => ['documents_incomplete', 'interview_scheduled', 'accepted', 'declined'],
        'documents_incomplete' => ['in_screening', 'interview_scheduled'],
        'interview_scheduled' => ['in_screening', 'accepted', 'declined'],
        'accepted' => ['enrolled'],
        'declined' => [],
        'enrolled' => [],
    ];

    public static function allows(string $from, string $to, string $role): bool
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) return false;
        return $to !== 'enrolled' || $role === 'admin';
    }

    public static function isKnownStatus(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }

    public static function targets(string $from, string $role): array
    {
        $targets = self::TRANSITIONS[$from] ?? [];
        if ($role !== 'admin') {
            $targets = array_values(array_filter($targets, static fn (string $status): bool => $status !== 'enrolled'));
        }

        return $targets;
    }
}
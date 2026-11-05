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

    public static function forLogin(): array
    {
        $path = self::localPath();
        if (!is_file($path)) return [];

        $passwords = require $path;
        if (!is_array($passwords)) return [];

        $accounts = [];
        foreach (self::IDENTITIES as $key => $identity) {
            $password = $passwords[$key] ?? null;
            if (!is_string($password) || $password === '') return [];
            $accounts[$key] = $identity + ['password' => $password];
        }

        return $accounts;
    }
}
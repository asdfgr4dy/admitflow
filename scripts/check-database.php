<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Database.php';

$requiredTables = [
    'users',
    'student_profiles',
    'academic_years',
    'academic_programs',
    'applications',
    'admission_requirements',
    'application_documents',
    'document_reviews',
    'reviewer_assignments',
    'screening_notes',
    'interviews',
    'enrollment_records',
    'announcements',
    'notifications',
    'application_status_history',
    'audit_logs',
    'password_reset_tokens',
    'login_attempts',
    'backups',
    'system_settings',
];

try {
    $database = Database::connection();
    $serverVersion = $database->query('SELECT VERSION()')->fetchColumn();
    $existingTables = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missingTables = array_values(array_diff($requiredTables, $existingTables));

    printf("Database connection: OK (%s)\n", $serverVersion);
    if ($missingTables === []) {
        echo "Schema check: OK (all expected tables are present)\n";
        exit(0);
    }

    printf("Schema check: incomplete; missing %d table(s): %s\n", count($missingTables), implode(', ', $missingTables));
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "Database check failed: {$error->getMessage()}\n");
    exit(1);
}
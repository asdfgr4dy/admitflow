<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/ApplicationService.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/app/StatusTransitionPolicy.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$workerMode = ($argv[1] ?? '') === '--worker';
$database = Database::connection();
$databaseName = (string) $database->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/^admitflow_phase4_concurrency_[a-z0-9_]+$/', $databaseName)) {
    fwrite(STDERR, "Refusing to run outside a dedicated admitflow_phase4_concurrency_* schema.\n");
    exit(2);
}

if ($workerMode) {
    $mode = (string) ($argv[2] ?? '');
    if ($mode === 'submit') {
        $email = (string) ($argv[3] ?? '');
        $studentQuery = $database->prepare('SELECT id FROM users WHERE email = :email AND role = \'student\' AND account_status = \'active\'');
        $studentQuery->execute(['email' => $email]);
        $studentId = (int) $studentQuery->fetchColumn();
        $programId = (int) $database->query('SELECT id FROM academic_programs WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $yearId = (int) $database->query('SELECT id FROM academic_years WHERE is_open = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $input = [
            'full_name' => 'Concurrency Applicant',
            'mobile_number' => '+639171234567',
            'date_of_birth' => '2000-01-01',
            'address_line' => 'Synthetic Test Address',
            'municipality' => 'Test Municipality',
            'province' => 'Test Province',
            'emergency_contact_name' => 'Synthetic Contact',
            'emergency_contact_number' => '09171234567',
            'academic_program_id' => (string) $programId,
            'academic_year_id' => (string) $yearId,
            'preferred_campus' => 'Online',
            'previous_school' => 'Synthetic School',
            'senior_high_strand' => 'STEM',
            'privacy_consent' => '1',
        ];
        if ($studentId < 1) {
            fwrite(STDERR, "Submission fixture student is missing.\n");
            exit(2);
        }
        try {
            $result = ApplicationService::save($database, $studentId, $input, [], true);
            echo !empty($result['ok']) ? "submitted\n" : "rejected\n";
        } catch (Throwable $error) {
            fwrite(STDERR, 'Submission worker failed (' . $error::class . ').' . PHP_EOL);
            exit(1);
        }
        exit(0);
    }

    if ($mode === 'checklist' || $mode === 'enroll') {
        $applicationId = (int) ($argv[3] ?? 0);
        $adminEmail = (string) ($argv[4] ?? '');
        $adminQuery = $database->prepare('SELECT id, full_name, role FROM users WHERE email = :email AND role = \'admin\' AND account_status = \'active\'');
        $adminQuery->execute(['email' => $adminEmail]);
        $admin = $adminQuery->fetch();
        if (!is_array($admin) || $applicationId < 1) {
            fwrite(STDERR, "Enrollment fixture is missing.\n");
            exit(2);
        }
        try {
            if ($mode === 'checklist') {
                StaffService::updateEnrollmentChecklist($database, $admin, $applicationId, ['offer_confirmed', 'documents_checked', 'instructions_sent', 'registration_ready']);
                echo "checklist\n";
            } else {
                StaffService::changeStatus($database, $admin, $applicationId, 'enrolled');
                echo "enrolled\n";
            }
        } catch (DomainException) {
            echo "blocked\n";
        } catch (Throwable $error) {
            fwrite(STDERR, 'Enrollment worker failed (' . $error::class . ').' . PHP_EOL);
            exit(1);
        }
        exit(0);
    }

    if ($mode !== 'interview') {
        fwrite(STDERR, "Unknown concurrency worker mode.\n");
        exit(2);
    }
    $reference = (string) ($argv[3] ?? '');
    $reviewerEmail = (string) ($argv[4] ?? '');
    $reviewerQuery = $database->prepare('SELECT id, full_name, role FROM users WHERE email = :email AND role = \'reviewer\' AND account_status = \'active\'');
    $reviewerQuery->execute(['email' => $reviewerEmail]);
    $reviewer = $reviewerQuery->fetch();
    $applicationQuery = $database->prepare('SELECT id FROM applications WHERE reference_number = :reference AND deleted_at IS NULL');
    $applicationQuery->execute(['reference' => $reference]);
    $applicationId = (int) $applicationQuery->fetchColumn();
    if (!is_array($reviewer) || $applicationId < 1) {
        fwrite(STDERR, "Concurrency fixture is missing.\n");
        exit(2);
    }

    $start = (new DateTimeImmutable('+2 days', new DateTimeZone('Asia/Manila')))->setTime(10, 0)->format('Y-m-d\\TH:i');
    $end = (new DateTimeImmutable('+2 days', new DateTimeZone('Asia/Manila')))->setTime(11, 0)->format('Y-m-d\\TH:i');
    try {
        StaffService::scheduleInterview($database, $reviewer, $applicationId, (int) $reviewer['id'], $start, $end, 'Synthetic concurrency test');
        echo "scheduled\n";
    } catch (DomainException) {
        echo "conflict\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'Worker failed (' . $error::class . ').' . PHP_EOL);
        exit(1);
    }
    exit(0);
}

$runId = bin2hex(random_bytes(5));
$studentEmail = 'phase4-concurrency-applicant-' . $runId . '@example.test';
$submissionEmail = 'phase4-concurrency-submit-' . $runId . '@example.test';
$reviewerEmail = 'phase4-concurrency-reviewer-' . $runId . '@example.test';
$adminEmail = 'phase4-concurrency-admin-' . $runId . '@example.test';
$student = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :password, \'student\', \'active\')');
$student->execute(['name' => 'Concurrency Applicant', 'email' => $studentEmail, 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
$studentId = (int) $database->lastInsertId();
$profile = $database->prepare('INSERT INTO student_profiles (user_id) VALUES (:user_id)');
$profile->execute(['user_id' => $studentId]);
$submissionStudent = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :password, \'student\', \'active\')');
$submissionStudent->execute(['name' => 'Submission Race Applicant', 'email' => $submissionEmail, 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
$submissionStudentId = (int) $database->lastInsertId();
$profile->execute(['user_id' => $submissionStudentId]);

$reviewerInsert = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :password, \'reviewer\', \'active\')');
$reviewerInsert->execute(['name' => 'Concurrency Reviewer', 'email' => $reviewerEmail, 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
$reviewerId = (int) $database->lastInsertId();
$adminInsert = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :password, \'admin\', \'active\')');
$adminInsert->execute(['name' => 'Concurrency Administrator', 'email' => $adminEmail, 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
$adminId = (int) $database->lastInsertId();

$programId = (int) $database->query('SELECT id FROM academic_programs WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$yearId = (int) $database->query('SELECT id FROM academic_years WHERE is_open = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($programId < 1 || $yearId < 1) {
    fwrite(STDERR, "Seed one active program and open academic year in this disposable schema first.\n");
    exit(2);
}

$runWorkers = static function (array $workerArguments): array {
    $processes = [];
    foreach ($workerArguments as $arguments) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(array_merge([PHP_BINARY, __FILE__, '--worker'], $arguments), $descriptors, $pipes, dirname(__DIR__), getenv() ?: []);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start all MariaDB concurrency workers.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes[1], $pipes[2]];
    }

    $outcomes = [];
    foreach ($processes as [$process, $stdout, $stderr]) {
        $outcomes[] = trim((string) stream_get_contents($stdout));
        fclose($stdout);
        $errorOutput = trim((string) stream_get_contents($stderr));
        fclose($stderr);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException($errorOutput !== '' ? $errorOutput : 'Concurrency worker exited unsuccessfully.');
        }
    }
    return $outcomes;
};

$references = ['AF-P4-CONCURRENCY-A-' . bin2hex(random_bytes(4)), 'AF-P4-CONCURRENCY-B-' . bin2hex(random_bytes(4))];
$applicationInsert = $database->prepare('INSERT INTO applications (student_id, academic_program_id, academic_year_id, reference_number, status, submitted_at) VALUES (:student, :program, :year, :reference, \'in_screening\', UTC_TIMESTAMP())');
foreach ($references as $reference) {
    $applicationInsert->execute(['student' => $studentId, 'program' => $programId, 'year' => $yearId, 'reference' => $reference]);
}

$interviewOutcomes = $runWorkers(array_map(static fn (string $reference): array => ['interview', $reference, $reviewerEmail], $references));
$scheduled = count(array_filter($interviewOutcomes, static fn (string $outcome): bool => $outcome === 'scheduled'));
$conflicts = count(array_filter($interviewOutcomes, static fn (string $outcome): bool => $outcome === 'conflict'));
$reviewerQuery = $database->prepare('SELECT id FROM users WHERE email = :email');
$reviewerQuery->execute(['email' => $reviewerEmail]);
$reviewerId = (int) $reviewerQuery->fetchColumn();
$interviews = $database->prepare('SELECT COUNT(*) FROM interviews WHERE interviewer_id = :reviewer_id AND status = \'scheduled\' AND starts_at_utc = :starts_at');
$expectedStart = (new DateTimeImmutable('+2 days', new DateTimeZone('Asia/Manila')))->setTime(10, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$interviews->execute(['reviewer_id' => $reviewerId, 'starts_at' => $expectedStart]);
$persisted = (int) $interviews->fetchColumn();

$draft = $database->prepare('INSERT INTO applications (student_id, academic_program_id, academic_year_id, status) VALUES (:student, :program, :year, \'draft\')');
$draft->execute(['student' => $submissionStudentId, 'program' => $programId, 'year' => $yearId]);
$draftApplicationId = (int) $database->lastInsertId();
$requirements = $database->query('SELECT id FROM admission_requirements WHERE is_active = 1 AND is_required = 1')->fetchAll(PDO::FETCH_COLUMN);
$documentFixture = $database->prepare('INSERT INTO application_documents (application_id, requirement_id, version, storage_name, original_name, detected_mime_type, file_size_bytes, sha256, review_status, uploaded_by) VALUES (:application_id, :requirement_id, 1, :storage_name, \'synthetic.pdf\', \'application/pdf\', 100, :sha256, \'pending\', :uploaded_by)');
foreach ($requirements as $requirementId) {
    $documentFixture->execute(['application_id' => $draftApplicationId, 'requirement_id' => $requirementId, 'storage_name' => 'concurrency-' . $draftApplicationId . '-' . $requirementId . '.pdf', 'sha256' => hash('sha256', 'synthetic'), 'uploaded_by' => $submissionStudentId]);
}
$submissionOutcomes = $runWorkers([
    ['submit', $submissionEmail],
    ['submit', $submissionEmail],
]);
$submissionSuccesses = count(array_filter($submissionOutcomes, static fn (string $outcome): bool => $outcome === 'submitted'));
$submissionRejected = count(array_filter($submissionOutcomes, static fn (string $outcome): bool => $outcome === 'rejected'));
$studentApplications = $database->prepare('SELECT COUNT(*) FROM applications WHERE student_id = :student_id');
$studentApplications->execute(['student_id' => $submissionStudentId]);
$submissionRows = (int) $studentApplications->fetchColumn();
$submissionEvents = $database->prepare('SELECT COUNT(*) FROM application_status_history WHERE application_id = :application_id AND to_status = \'submitted\'');
$submissionEvents->execute(['application_id' => $draftApplicationId]);
$submittedHistories = (int) $submissionEvents->fetchColumn();

$accepted = $database->prepare('INSERT INTO applications (student_id, academic_program_id, academic_year_id, reference_number, status, submitted_at) VALUES (:student, :program, :year, :reference, \'accepted\', UTC_TIMESTAMP())');
$accepted->execute(['student' => $studentId, 'program' => $programId, 'year' => $yearId, 'reference' => 'AF-P4-CONCURRENCY-ENROLL-' . bin2hex(random_bytes(4))]);
$acceptedApplicationId = (int) $database->lastInsertId();
foreach ($requirements as $requirementId) {
    $documentFixture->execute(['application_id' => $acceptedApplicationId, 'requirement_id' => $requirementId, 'storage_name' => 'concurrency-' . $acceptedApplicationId . '-' . $requirementId . '.pdf', 'sha256' => hash('sha256', 'synthetic'), 'uploaded_by' => $studentId]);
    $database->prepare('UPDATE application_documents SET review_status = \'verified\' WHERE application_id = :application_id AND requirement_id = :requirement_id')->execute(['application_id' => $acceptedApplicationId, 'requirement_id' => $requirementId]);
}
$enrollmentOutcomes = $runWorkers([['checklist', (string) $acceptedApplicationId, $adminEmail], ['enroll', (string) $acceptedApplicationId, $adminEmail]]);
$enrollmentStatus = $database->prepare('SELECT status FROM applications WHERE id = :id');
$enrollmentStatus->execute(['id' => $acceptedApplicationId]);
$finalApplicationStatus = (string) $enrollmentStatus->fetchColumn();
$checklistStatus = $database->prepare('SELECT status FROM enrollment_records WHERE application_id = :id');
$checklistStatus->execute(['id' => $acceptedApplicationId]);
$finalChecklistStatus = $checklistStatus->fetchColumn();
$enrollmentInvariant = $finalApplicationStatus !== 'enrolled' || $finalChecklistStatus === 'complete';

printf("Interview concurrency: scheduled=%d conflicts=%d matching_rows=%d\n", $scheduled, $conflicts, $persisted);
printf("Submission concurrency: submitted=%d rejected=%d student_applications=%d submitted_history=%d\n", $submissionSuccesses, $submissionRejected, $submissionRows, $submittedHistories);
printf("Enrollment race: outcomes=%s application=%s checklist=%s invariant=%s\n", implode(',', $enrollmentOutcomes), $finalApplicationStatus, $finalChecklistStatus ?: 'none', $enrollmentInvariant ? 'ok' : 'violated');
$submissionInvariant = $submissionSuccesses === 1 && $submissionRejected === 1 && $submissionRows === 1 && $submittedHistories === 1;
exit($scheduled === 1 && $conflicts === 1 && $persisted === 1 && $submissionInvariant && $enrollmentInvariant ? 0 : 1);

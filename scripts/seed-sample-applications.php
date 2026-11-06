<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Database.php';

if ((getenv('ADMITFLOW_APP_ENV') ?: '') !== 'local') {
    fwrite(STDERR, "Refusing to seed sample applications unless ADMITFLOW_APP_ENV=local.\n");
    exit(2);
}

try {
    $database = Database::connection();
    if ((string) $database->query('SELECT DATABASE()')->fetchColumn() !== 'admitflow') {
        throw new RuntimeException('Refusing to seed a database other than admitflow.');
    }

    $existingStudents = (int) $database->query("SELECT COUNT(*) FROM users WHERE email LIKE 'sample50-%@example.test'")->fetchColumn();
    $existingApplications = (int) $database->query("SELECT COUNT(*) FROM applications WHERE reference_number LIKE 'AF-S50-2026-%'")->fetchColumn();
    if ($existingStudents !== 0 || $existingApplications !== 0) {
        if ($existingStudents === 50 && $existingApplications === 50) {
            require __DIR__ . '/enrich-sample-applications.php';
            exit(0);
        }
        throw new RuntimeException('A partial sample50 dataset already exists; no changes were made.');
    }

    $programs = array_map('intval', $database->query('SELECT id FROM academic_programs WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $years = array_map('intval', $database->query('SELECT id FROM academic_years WHERE is_open = 1 ORDER BY starts_on DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN));
    if ($programs === [] || $years === []) {
        throw new RuntimeException('At least one active program and one open academic year are required.');
    }

    $insertUser = $database->prepare('INSERT INTO users (full_name, email, password_hash, must_change_password, role, account_status) VALUES (:full_name, :email, :password_hash, 1, \'student\', \'inactive\')');
    $insertProfile = $database->prepare('INSERT INTO student_profiles (user_id, mobile_number, date_of_birth, address_line, municipality, province, emergency_contact_name, emergency_contact_number, privacy_consent_at) VALUES (:user_id, :mobile_number, :date_of_birth, :address_line, :municipality, :province, :emergency_contact_name, :emergency_contact_number, UTC_TIMESTAMP())');
    $insertApplication = $database->prepare('INSERT INTO applications (reference_number, student_id, academic_program_id, academic_year_id, preferred_campus, previous_school, senior_high_strand, applicant_profile_snapshot, status, submitted_at) VALUES (:reference_number, :student_id, :program_id, :year_id, :campus, :previous_school, :strand, :profile_snapshot, :status, :submitted_at)');
    $insertHistory = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by, note, created_at) VALUES (:application_id, :from_status, :to_status, :changed_by, :note, :created_at)');
    $applicationCounts = ['submitted' => 20, 'in_screening' => 20, 'documents_incomplete' => 10];

    $database->beginTransaction();
    try {
        $sampleIndex = 0;
        foreach ($applicationCounts as $finalStatus => $count) {
            for ($statusIndex = 0; $statusIndex < $count; $statusIndex++) {
                $sampleIndex++;
                $name = sprintf('Sample Applicant %03d', $sampleIndex);
                $email = sprintf('sample50-%03d@example.test', $sampleIndex);
                $mobile = '+63917' . str_pad((string) $sampleIndex, 7, '0', STR_PAD_LEFT);
                $emergencyNumber = '09' . str_pad((string) (700000000 + $sampleIndex), 9, '0', STR_PAD_LEFT);
                $birthDay = str_pad((string) (($sampleIndex - 1) % 28 + 1), 2, '0', STR_PAD_LEFT);
                $birthDate = '2003-01-' . $birthDay;
                $address = 'Sample Street ' . $sampleIndex;
                $municipality = 'Sample City';
                $province = 'Sample Province';
                $emergencyName = 'Sample Contact ' . $sampleIndex;
                $yearId = $years[($sampleIndex - 1) % count($years)];
                $programId = $programs[($sampleIndex - 1) % count($programs)];
                $strand = ['STEM', 'ABM', 'HUMSS', 'TVL'][($sampleIndex - 1) % 4];
                $snapshot = [
                    'full_name' => $name,
                    'mobile_number' => $mobile,
                    'date_of_birth' => $birthDate,
                    'address_line' => $address,
                    'municipality' => $municipality,
                    'province' => $province,
                    'emergency_contact_name' => $emergencyName,
                    'emergency_contact_number' => $emergencyNumber,
                ];

                $insertUser->execute([
                    'full_name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                ]);
                $studentId = (int) $database->lastInsertId();
                $insertProfile->execute([
                    'user_id' => $studentId,
                    'mobile_number' => $mobile,
                    'date_of_birth' => $birthDate,
                    'address_line' => $address,
                    'municipality' => $municipality,
                    'province' => $province,
                    'emergency_contact_name' => $emergencyName,
                    'emergency_contact_number' => $emergencyNumber,
                ]);

                $submittedAt = gmdate('Y-m-d H:i:s', time() - (($sampleIndex - 1) * 86400));
                $insertApplication->execute([
                    'reference_number' => sprintf('AF-S50-2026-%03d', $sampleIndex),
                    'student_id' => $studentId,
                    'program_id' => $programId,
                    'year_id' => $yearId,
                    'campus' => 'Sample Campus',
                    'previous_school' => 'Sample Secondary School',
                    'strand' => $strand,
                    'profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    'status' => $finalStatus,
                    'submitted_at' => $submittedAt,
                ]);
                $applicationId = (int) $database->lastInsertId();
                $insertHistory->execute([
                    'application_id' => $applicationId,
                    'from_status' => 'draft',
                    'to_status' => 'submitted',
                    'changed_by' => $studentId,
                    'note' => 'Synthetic sample application.',
                    'created_at' => $submittedAt,
                ]);
                if ($finalStatus !== 'submitted') {
                    $nextStatus = $finalStatus === 'in_screening' ? 'in_screening' : 'documents_incomplete';
                    $insertHistory->execute([
                        'application_id' => $applicationId,
                        'from_status' => 'submitted',
                        'to_status' => $nextStatus,
                        'changed_by' => null,
                        'note' => 'Synthetic sample workflow history.',
                        'created_at' => gmdate('Y-m-d H:i:s', strtotime($submittedAt . ' UTC') + 3600),
                    ]);
                }
            }
        }
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollBack();
        throw $error;
    }

    echo "Created 50 synthetic applications and 50 inactive sample student accounts (20 submitted, 20 in screening, 10 documents incomplete).\n";
    require __DIR__ . '/enrich-sample-applications.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'Sample application seeding failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
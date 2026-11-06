<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/AuditLog.php';
require dirname(__DIR__) . '/app/StatusTransitionPolicy.php';
require dirname(__DIR__) . '/app/UploadPolicy.php';

if ((getenv('ADMITFLOW_APP_ENV') ?: '') !== 'local') {
    fwrite(STDERR, "Refusing to enrich sample applications unless ADMITFLOW_APP_ENV=local.\n");
    exit(2);
}

$makePdf = static function (string $reference, string $applicantName, string $requirementName): string {
    $escape = static fn (string $value): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    $lines = [
        'SAMPLE ONLY - NOT A REAL DOCUMENT',
        'AdmitFlow interface demonstration file',
        'Reference: ' . $reference,
        'Applicant: ' . $applicantName,
        'Requirement: ' . $requirementName,
        'No real personal or institutional information is included.',
    ];
    $stream = "BT\n/F1 16 Tf\n50 740 Td\n(" . $escape(array_shift($lines)) . ") Tj\n/F1 11 Tf\n";
    foreach ($lines as $line) $stream .= '0 -28 Td' . "\n(" . $escape($line) . ") Tj\n";
    $stream .= "ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream',
    ];
    $pdf = "%PDF-1.4\n%SAMPLE\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
};

$makeJpeg = static function (string $reference, string $applicantName, string $requirementName): string {
    $image = imagecreatetruecolor(800, 500);
    if ($image === false) throw new RuntimeException('Could not create the sample image.');
    $white = imagecolorallocate($image, 248, 250, 248);
    $navy = imagecolorallocate($image, 24, 53, 71);
    $green = imagecolorallocate($image, 26, 116, 97);
    $muted = imagecolorallocate($image, 91, 108, 111);
    imagefill($image, 0, 0, $white);
    imagerectangle($image, 18, 18, 781, 481, $green);
    imagestring($image, 5, 40, 55, 'SAMPLE ONLY', $green);
    imagestring($image, 5, 40, 125, 'NOT A REAL PHOTO OR IDENTIFICATION', $navy);
    imagestring($image, 4, 40, 220, 'Reference: ' . $reference, $muted);
    imagestring($image, 4, 40, 260, 'Applicant: ' . $applicantName, $muted);
    imagestring($image, 4, 40, 300, 'Requirement: ' . $requirementName, $muted);
    imagestring($image, 3, 40, 420, 'Synthetic demonstration asset - no real personal data', $navy);
    ob_start();
    $written = imagejpeg($image, null, 86);
    $bytes = ob_get_clean();
    imagedestroy($image);
    if (!$written || !is_string($bytes)) throw new RuntimeException('Could not encode the sample image.');

    return $bytes;
};

try {
    $database = Database::connection();
    if ((string) $database->query('SELECT DATABASE()')->fetchColumn() !== 'admitflow') {
        throw new RuntimeException('Refusing to enrich a database other than admitflow.');
    }

    $sampleQuery = $database->query("SELECT a.id AS application_id, a.reference_number, a.student_id, a.academic_program_id, a.academic_year_id, a.applicant_profile_snapshot, a.status, u.full_name FROM applications a JOIN users u ON u.id = a.student_id WHERE a.reference_number LIKE 'AF-S50-2026-%' AND u.email LIKE 'sample50-%@example.test' ORDER BY a.reference_number");
    $samples = $sampleQuery->fetchAll();
    if (count($samples) !== 50) throw new RuntimeException('Expected exactly 50 linked sample applications; no changes were made.');

    $reviewerId = (int) $database->query("SELECT id FROM users WHERE role='reviewer' AND account_status='active' ORDER BY id LIMIT 1")->fetchColumn();
    $demoReviewerQuery = $database->prepare("SELECT id FROM users WHERE email = 'demo.reviewer@example.test' AND role = 'reviewer' AND account_status = 'active' LIMIT 1");
    $demoReviewerQuery->execute();
    $demoReviewerId = (int) $demoReviewerQuery->fetchColumn();
    $adminId = (int) $database->query("SELECT id FROM users WHERE role='admin' AND account_status='active' ORDER BY id LIMIT 1")->fetchColumn();
    if ($reviewerId < 1 || $demoReviewerId < 1 || $adminId < 1) throw new RuntimeException('An active demo reviewer, reviewer, and administrator are required to seed sample workflow history.');

    $firstNames = ['Avery', 'Jordan', 'Morgan', 'Taylor', 'Casey', 'Riley', 'Cameron', 'Quinn', 'Rowan', 'Skyler', 'Hayden', 'Finley', 'Parker', 'Sage', 'Reese', 'Alex', 'Jamie', 'Devon', 'Elliot', 'Emerson', 'Blake', 'Drew', 'Kai', 'Leslie', 'Robin', 'Micah', 'Noel', 'Sam', 'Shiloh', 'Toni'];
    $lastNames = ['Bennett', 'Rivera', 'Santos', 'Navarro', 'Flores', 'Mendoza', 'Reyes', 'Castillo', 'Cruz', 'Garcia', 'Lim', 'Santiago', 'Morales', 'Aquino', 'Villanueva', 'Torres', 'Ramos', 'Bautista', 'Mercado', 'Herrera', 'Romero', 'Valdez', 'Salazar', 'Vargas', 'Dizon', 'Delos Santos', 'Manalo', 'Soriano', 'Estrada', 'Padilla'];
    $usedNames = [];
    foreach ($samples as $sample) {
        if (!preg_match('/^Sample Applicant \d{3}$/', (string) $sample['full_name'])) $usedNames[mb_strtolower((string) $sample['full_name'])] = true;
    }
    $namesToAssign = [];
    foreach ($samples as $sample) {
        if (!preg_match('/^Sample Applicant \d{3}$/', (string) $sample['full_name'])) continue;
        do {
            $name = $firstNames[random_int(0, count($firstNames) - 1)] . ' ' . $lastNames[random_int(0, count($lastNames) - 1)];
            $nameKey = mb_strtolower($name);
        } while (isset($usedNames[$nameKey]));
        $usedNames[$nameKey] = true;
        $namesToAssign[(int) $sample['application_id']] = $name;
    }

    $uploadDirectory = dirname(__DIR__) . '/storage/uploads';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0700, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Private upload storage is unavailable.');
    }

    $requirements = $database->query('SELECT id, code, name, academic_program_id, academic_year_id, allowed_extensions, max_file_size_bytes FROM admission_requirements WHERE is_active = 1 AND is_required = 1 ORDER BY sort_order, id')->fetchAll();
    $findDocument = $database->prepare('SELECT id FROM application_documents WHERE application_id = :application_id AND requirement_id = :requirement_id AND is_current = 1 LIMIT 1');
    $listDocuments = $database->prepare('SELECT id, requirement_id FROM application_documents WHERE application_id = :application_id AND is_current = 1 ORDER BY requirement_id');
    $insertDocument = $database->prepare('INSERT INTO application_documents (application_id, requirement_id, version, storage_name, original_name, detected_mime_type, file_size_bytes, sha256, review_status, uploaded_by, is_current) VALUES (:application_id, :requirement_id, 1, :storage_name, :original_name, :mime_type, :file_size, :sha256, :review_status, :uploaded_by, 1)');
    $updateDocumentStatus = $database->prepare('UPDATE application_documents SET review_status = :review_status WHERE id = :document_id AND is_current = 1');
    $findSampleReview = $database->prepare('SELECT id FROM document_reviews WHERE document_id = :document_id AND feedback LIKE \'SAMPLE ONLY:%\' LIMIT 1');
    $insertDocumentReview = $database->prepare('INSERT INTO document_reviews (document_id, reviewer_id, decision, feedback) VALUES (:document_id, :reviewer_id, :decision, :feedback)');
    $findHistory = $database->prepare('SELECT id, to_status FROM application_status_history WHERE application_id = :application_id ORDER BY id DESC LIMIT 1');
    $insertHistory = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by, note) VALUES (:application_id, :from_status, :to_status, :changed_by, :note)');
    $updateApplicationStatus = $database->prepare('UPDATE applications SET status = :status WHERE id = :application_id');
    $insertAssignment = $database->prepare('INSERT INTO reviewer_assignments (application_id, reviewer_id, assigned_by) VALUES (:application_id, :reviewer_id, :assigned_by)');
    $findActiveAssignment = $database->prepare('SELECT COUNT(*) FROM reviewer_assignments WHERE application_id = :application_id AND unassigned_at IS NULL');
    $findDemoAssignment = $database->prepare('SELECT COUNT(*) FROM reviewer_assignments WHERE application_id = :application_id AND reviewer_id = :reviewer_id AND unassigned_at IS NULL');
    $findScheduledInterview = $database->prepare('SELECT COUNT(*) FROM interviews WHERE application_id = :application_id AND status = \'scheduled\'');
    $insertInterview = $database->prepare('INSERT INTO interviews (application_id, interviewer_id, starts_at_utc, ends_at_utc, instructions, created_by) VALUES (:application_id, :interviewer_id, :starts_at, :ends_at, :instructions, :created_by)');
    $findEnrollment = $database->prepare('SELECT id FROM enrollment_records WHERE application_id = :application_id');
    $saveEnrollment = $database->prepare('INSERT INTO enrollment_records (application_id, status, checklist, prepared_by, completed_at) VALUES (:application_id, :status, :checklist, :prepared_by, :completed_at) ON DUPLICATE KEY UPDATE status = VALUES(status), checklist = VALUES(checklist), prepared_by = VALUES(prepared_by), completed_at = VALUES(completed_at)');
    $updateUser = $database->prepare('UPDATE users SET full_name = :full_name WHERE id = :student_id AND email LIKE :sample_email');
    $updateProfile = $database->prepare('UPDATE student_profiles SET emergency_contact_name = :contact_name WHERE user_id = :student_id');
    $updateSnapshot = $database->prepare('UPDATE applications SET applicant_profile_snapshot = :snapshot WHERE id = :application_id AND student_id = :student_id');
    $createdFiles = [];
    $renamedCount = 0;
    $documentCount = 0;
    $reviewCount = 0;
    $demoAssignmentCount = 0;
    $interviewCount = 0;
    $enrollmentCount = 0;

    $database->beginTransaction();
    try {
        foreach ($samples as $sample) {
            $applicationId = (int) $sample['application_id'];
            $studentId = (int) $sample['student_id'];
            $applicantName = $namesToAssign[$applicationId] ?? (string) $sample['full_name'];
            $sampleNumber = (int) substr((string) $sample['reference_number'], -3);
            $targetStatus = match (true) {
                $sampleNumber <= 20 => 'submitted',
                $sampleNumber <= 30 => 'in_screening',
                $sampleNumber <= 35 => 'interview_scheduled',
                $sampleNumber <= 38 => 'accepted',
                $sampleNumber <= 40 => 'declined',
                $sampleNumber <= 47 => 'documents_incomplete',
                $sampleNumber === 48 => 'enrolled',
                default => 'accepted',
            };
            if ($sampleNumber >= 21 && $sampleNumber <= 28) {
                $findDemoAssignment->execute(['application_id' => $applicationId, 'reviewer_id' => $demoReviewerId]);
                if ((int) $findDemoAssignment->fetchColumn() === 0) {
                    $insertAssignment->execute(['application_id' => $applicationId, 'reviewer_id' => $demoReviewerId, 'assigned_by' => $adminId]);
                    $demoAssignmentCount++;
                }
            }
            if (isset($namesToAssign[$applicationId])) {
                $updateUser->execute(['full_name' => $applicantName, 'student_id' => $studentId, 'sample_email' => 'sample50-%@example.test']);
                $updateProfile->execute(['contact_name' => $applicantName . ' Contact', 'student_id' => $studentId]);
                $snapshot = json_decode((string) ($sample['applicant_profile_snapshot'] ?? ''), true);
                if (!is_array($snapshot)) $snapshot = [];
                $snapshot['full_name'] = $applicantName;
                $updateSnapshot->execute(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 'application_id' => $applicationId, 'student_id' => $studentId]);
                $renamedCount++;
            }

            $findHistory->execute(['application_id' => $applicationId]);
            $latestHistory = $findHistory->fetch();
            $currentHistoryStatus = is_array($latestHistory) ? (string) $latestHistory['to_status'] : 'draft';
            $transitionPath = match ($targetStatus) {
                'interview_scheduled' => ['in_screening', 'interview_scheduled'],
                'accepted' => ['in_screening', 'accepted'],
                'declined' => ['in_screening', 'declined'],
                'enrolled' => ['in_screening', 'accepted', 'enrolled'],
                default => [],
            };
            $currentPathPosition = array_search($currentHistoryStatus, $transitionPath, true);
            $remainingTransitions = $currentPathPosition === false ? $transitionPath : array_slice($transitionPath, $currentPathPosition + 1);
            foreach ($remainingTransitions as $nextStatus) {
                if (!StatusTransitionPolicy::allows($currentHistoryStatus, $nextStatus, 'admin')) {
                    throw new RuntimeException('Sample workflow transition is not allowed: ' . $currentHistoryStatus . ' to ' . $nextStatus . '.');
                }
                $insertHistory->execute([
                    'application_id' => $applicationId,
                    'from_status' => $currentHistoryStatus,
                    'to_status' => $nextStatus,
                    'changed_by' => $reviewerId,
                    'note' => 'SAMPLE ONLY: synthetic workflow history.',
                ]);
                $currentHistoryStatus = $nextStatus;
            }
            if ($currentHistoryStatus !== $targetStatus) {
                throw new RuntimeException('Sample application history does not reach its target status.');
            }
            if ((string) $sample['status'] !== $targetStatus) {
                $updateApplicationStatus->execute(['status' => $targetStatus, 'application_id' => $applicationId]);
            }
            $sample['status'] = $targetStatus;

            $appRequirements = array_values(array_filter($requirements, static function (array $requirement) use ($sample): bool {
                return (empty($requirement['academic_program_id']) || (int) $requirement['academic_program_id'] === (int) $sample['academic_program_id'])
                    && (empty($requirement['academic_year_id']) || (int) $requirement['academic_year_id'] === (int) $sample['academic_year_id']);
            }));
            foreach ($appRequirements as $requirement) {
                $findDocument->execute(['application_id' => $applicationId, 'requirement_id' => $requirement['id']]);
                if ($findDocument->fetchColumn()) continue;

                $allowedExtensions = array_map('trim', explode(',', strtolower((string) $requirement['allowed_extensions'])));
                $extension = in_array('pdf', $allowedExtensions, true) ? 'pdf' : (in_array('jpg', $allowedExtensions, true) ? 'jpg' : (in_array('jpeg', $allowedExtensions, true) ? 'jpeg' : ''));
                if ($extension === '') throw new RuntimeException('No supported sample format is configured for a required document.');
                $bytes = $extension === 'pdf'
                    ? $makePdf((string) $sample['reference_number'], $applicantName, (string) $requirement['name'])
                    : $makeJpeg((string) $sample['reference_number'], $applicantName, (string) $requirement['name']);
                $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                if (!UploadPolicy::accepts($extension, $mime, strlen($bytes), $allowedExtensions, (int) $requirement['max_file_size_bytes'])) {
                    throw new RuntimeException('Generated sample document failed the application upload policy.');
                }

                $storageName = bin2hex(random_bytes(24)) . '.' . $extension;
                $filePath = $uploadDirectory . DIRECTORY_SEPARATOR . $storageName;
                if (file_put_contents($filePath, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Could not write a private sample document.');
                $createdFiles[] = $filePath;
                $documentStatus = $sample['status'] === 'documents_incomplete' && (int) $requirement['id'] === (int) ($appRequirements[0]['id'] ?? 0)
                    ? 'action_required'
                    : ($sample['status'] === 'submitted' ? 'pending' : 'verified');
                $insertDocument->execute([
                    'application_id' => $applicationId,
                    'requirement_id' => $requirement['id'],
                    'storage_name' => $storageName,
                    'original_name' => 'SAMPLE_ONLY_' . $sample['reference_number'] . '_' . $requirement['code'] . '.' . $extension,
                    'mime_type' => $mime,
                    'file_size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'review_status' => $documentStatus,
                    'uploaded_by' => $studentId,
                ]);
                $documentCount++;
            }

            $listDocuments->execute(['application_id' => $applicationId]);
            $sampleDocuments = $listDocuments->fetchAll();
            foreach ($sampleDocuments as $documentIndex => $document) {
                $documentStatus = $sample['status'] === 'documents_incomplete' && $documentIndex === 0
                    ? 'action_required'
                    : ($sample['status'] === 'submitted' ? 'pending' : 'verified');
                $updateDocumentStatus->execute(['review_status' => $documentStatus, 'document_id' => $document['id']]);
                if ($documentStatus === 'pending') continue;

                $reviewFeedback = 'SAMPLE ONLY: ' . ($documentStatus === 'verified' ? 'synthetic review for interface demonstration.' : 'example document needs attention.');
                $findSampleReview->execute(['document_id' => $document['id']]);
                if (!$findSampleReview->fetchColumn()) {
                    $insertDocumentReview->execute(['document_id' => $document['id'], 'reviewer_id' => $reviewerId, 'decision' => $documentStatus, 'feedback' => $reviewFeedback]);
                    AuditLog::record($database, null, 'sample.document_reviewed', 'application_document', (int) $document['id'], 'success', ['sample_data' => true], 'notice', 'AdmitFlow Sample Data');
                    $reviewCount++;
                }
            }

            if ($sample['status'] === 'interview_scheduled') {
                $findActiveAssignment->execute(['application_id' => $applicationId]);
                if ((int) $findActiveAssignment->fetchColumn() === 0) {
                    $insertAssignment->execute(['application_id' => $applicationId, 'reviewer_id' => $reviewerId, 'assigned_by' => $adminId]);
                }
                $findScheduledInterview->execute(['application_id' => $applicationId]);
                if ((int) $findScheduledInterview->fetchColumn() === 0) {
                    $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . (2 + $sampleNumber - 31) . ' days')->setTime(2, 0);
                    $end = $start->add(new DateInterval('PT1H'));
                    $insertInterview->execute([
                        'application_id' => $applicationId,
                        'interviewer_id' => $reviewerId,
                        'starts_at' => $start->format('Y-m-d H:i:s'),
                        'ends_at' => $end->format('Y-m-d H:i:s'),
                        'instructions' => 'SAMPLE ONLY: demonstration interview record.',
                        'created_by' => $adminId,
                    ]);
                    $interviewCount++;
                }
            }

            $enrollmentStatus = match ($sampleNumber) {
                36, 50 => 'pending',
                37, 49 => 'in_progress',
                38, 48 => 'complete',
                default => null,
            };
            if ($enrollmentStatus !== null) {
                $checklist = [
                    'offer_confirmed' => in_array($enrollmentStatus, ['in_progress', 'complete'], true),
                    'documents_checked' => in_array($enrollmentStatus, ['in_progress', 'complete'], true),
                    'instructions_sent' => $enrollmentStatus === 'complete',
                    'registration_ready' => $enrollmentStatus === 'complete',
                ];
                $saveEnrollment->execute([
                    'application_id' => $applicationId,
                    'status' => $enrollmentStatus,
                    'checklist' => json_encode($checklist, JSON_THROW_ON_ERROR),
                    'prepared_by' => $adminId,
                    'completed_at' => $enrollmentStatus === 'complete' ? gmdate('Y-m-d H:i:s') : null,
                ]);
                $enrollmentCount++;
            }
        }
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollBack();
        foreach ($createdFiles as $createdFile) if (is_file($createdFile)) unlink($createdFile);
        throw $error;
    }

    echo "Enriched sample applications: {$renamedCount} randomized synthetic names, {$documentCount} private SAMPLE ONLY document uploads added, {$reviewCount} synthetic document reviews, {$demoAssignmentCount} demo reviewer assignments added, {$interviewCount} upcoming interviews, and {$enrollmentCount} enrollment records updated.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Sample application enrichment failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
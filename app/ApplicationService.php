<?php
declare(strict_types=1);

final class ApplicationService
{
    public static function canProgressToStep(int $requestedStep, int $furthestStep): bool
    {
        return $requestedStep >= 0 && $requestedStep <= 2 && $requestedStep <= $furthestStep;
    }

    public static function canSubmitAtStep(int $furthestStep): bool
    {
        return $furthestStep >= 3;
    }

    public static function visibleWizardStep(int $requestedStep, int $furthestStep): int
    {
        return max(0, min($requestedStep, max(0, min($furthestStep, 3)), 3));
    }

    public static function options(PDO $database): array
    {
        return [
            'programs' => $database->query('SELECT id, code, name FROM academic_programs WHERE is_active = 1 ORDER BY name')->fetchAll(),
            'years' => $database->query('SELECT id, label, application_deadline FROM academic_years WHERE is_open = 1 ORDER BY starts_on DESC')->fetchAll(),
            'requirements' => $database->query(
                'SELECT id, code, name, instructions, academic_program_id, academic_year_id, allowed_extensions, max_file_size_bytes FROM admission_requirements WHERE is_active = 1 AND is_required = 1 ORDER BY sort_order, name'
            )->fetchAll(),
        ];
    }

    public static function current(PDO $database, int $studentId): ?array
    {
        $statement = $database->prepare(
            'SELECT a.*, p.name AS program_name, y.label AS academic_year_label FROM applications a LEFT JOIN academic_programs p ON p.id = a.academic_program_id LEFT JOIN academic_years y ON y.id = a.academic_year_id WHERE a.student_id = :student_id AND a.deleted_at IS NULL ORDER BY (a.status = \'draft\') DESC, a.updated_at DESC LIMIT 1'
        );
        $statement->execute(['student_id' => $studentId]);
        $application = $statement->fetch();

        return is_array($application) ? $application : null;
    }

    public static function applicantProfileSnapshot(array $input): array
    {
        $snapshot = [];
        foreach (['full_name', 'mobile_number', 'date_of_birth', 'address_line', 'municipality', 'province', 'emergency_contact_name', 'emergency_contact_number'] as $field) {
            $snapshot[$field] = trim((string) ($input[$field] ?? ''));
        }

        return $snapshot;
    }

    public static function decodeApplicantProfileSnapshot(mixed $snapshot): array
    {
        if (!is_string($snapshot) || $snapshot === '') {
            return [];
        }
        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? array_intersect_key($decoded, array_flip(['full_name', 'mobile_number', 'date_of_birth', 'address_line', 'municipality', 'province', 'emergency_contact_name', 'emergency_contact_number'])) : [];
    }

    public static function applicantFormValues(array $profile, ?array $application, array $validationValues = []): array
    {
        $values = [];
        $personalFields = ['full_name', 'mobile_number', 'date_of_birth', 'address_line', 'municipality', 'province', 'emergency_contact_name', 'emergency_contact_number'];
        $academicFields = ['academic_program_id', 'academic_year_id', 'preferred_campus', 'previous_school', 'senior_high_strand'];
        $snapshot = $application === null ? [] : self::decodeApplicantProfileSnapshot($application['applicant_profile_snapshot'] ?? null);

        foreach ($personalFields as $field) {
            if (array_key_exists($field, $validationValues)) {
                $values[$field] = (string) $validationValues[$field];
            } elseif ($application !== null) {
                $values[$field] = (string) ($snapshot[$field] ?? '');
            } else {
                $values[$field] = (string) ($profile[$field] ?? '');
            }
        }
        foreach ($academicFields as $field) {
            if (array_key_exists($field, $validationValues)) {
                $values[$field] = (string) $validationValues[$field];
            } else {
                $values[$field] = (string) ($application[$field] ?? '');
            }
        }

        return $values;
    }

    public static function printableSummary(PDO $database, int $studentId): ?array
    {
        $application = self::current($database, $studentId);
        if ($application === null) {
            return null;
        }

        $profile = $database->prepare('SELECT u.full_name, u.email, p.mobile_number, p.address_line, p.municipality, p.province FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.id = :student_id AND u.role = \'student\'');
        $profile->execute(['student_id' => $studentId]);
        $application['applicant'] = $profile->fetch() ?: [];

        $decision = $database->prepare('SELECT created_at FROM application_status_history WHERE application_id = :application_id AND to_status = \'accepted\' ORDER BY created_at DESC LIMIT 1');
        $decision->execute(['application_id' => $application['id']]);
        $application['accepted_at'] = $decision->fetchColumn() ?: null;

        $enrollment = $database->prepare('SELECT er.status, er.completed_at FROM enrollment_records er JOIN applications a ON a.id = er.application_id WHERE a.id = :application_id AND a.student_id = :student_id AND a.deleted_at IS NULL LIMIT 1');
        $enrollment->execute(['application_id' => $application['id'], 'student_id' => $studentId]);
        $application['enrollment'] = $enrollment->fetch() ?: null;
        $application['documents'] = self::documents($database, $studentId, (int) $application['id']);

        return $application;
    }

    public static function canPrintAcceptance(array $application): bool
    {
        return in_array($application['status'] ?? '', ['accepted', 'enrolled'], true);
    }

    public static function canPrintEnrollment(array $application): bool
    {
        return ($application['status'] ?? '') === 'enrolled'
            && ($application['enrollment']['status'] ?? '') === 'complete'
            && !empty($application['enrollment']['completed_at']);
    }

    public static function documents(PDO $database, int $studentId, int $applicationId): array
    {
        $statement = $database->prepare(
            'SELECT r.id AS requirement_id, r.name, r.instructions, d.id AS document_id, d.original_name, d.review_status, d.uploaded_at, latest.feedback FROM admission_requirements r LEFT JOIN applications a ON a.id = :application_id AND a.student_id = :student_id AND a.deleted_at IS NULL LEFT JOIN application_documents d ON d.application_id = a.id AND d.requirement_id = r.id AND d.is_current = 1 LEFT JOIN (SELECT document_id, feedback FROM document_reviews WHERE id IN (SELECT MAX(id) FROM document_reviews GROUP BY document_id)) latest ON latest.document_id = d.id WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = a.academic_program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = a.academic_year_id) ORDER BY r.sort_order, r.name'
        );
        $statement->execute(['application_id' => $applicationId, 'student_id' => $studentId]);

        return $statement->fetchAll();
    }

    public static function authorizedCurrentDocument(PDO $database, int $studentId, int $documentId): ?array
    {
        $statement = $database->prepare(
            'SELECT d.id, d.storage_name, d.original_name, d.detected_mime_type, d.file_size_bytes, d.sha256, d.application_id FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = :document_id AND d.is_current = 1 AND a.student_id = :student_id AND a.deleted_at IS NULL'
        );
        $statement->execute(['document_id' => $documentId, 'student_id' => $studentId]);
        $document = $statement->fetch();

        return is_array($document) ? $document : null;
    }

    public static function interviews(PDO $database, int $studentId, int $applicationId): array
    {
        $statement = $database->prepare('SELECT i.starts_at_utc, i.ends_at_utc, i.instructions, i.status, u.full_name AS interviewer_name FROM interviews i JOIN applications a ON a.id = i.application_id LEFT JOIN users u ON u.id = i.interviewer_id WHERE i.application_id = :application_id AND a.student_id = :student_id AND a.deleted_at IS NULL ORDER BY i.starts_at_utc DESC LIMIT 5');
        $statement->execute(['application_id' => $applicationId, 'student_id' => $studentId]);

        return $statement->fetchAll();
    }

    public static function replaceRejectedDocument(PDO $database, int $studentId, int $applicationId, int $requirementId, array $file): void
    {
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            self::auditReplacementFailure($database, $studentId, $applicationId);
            $message = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The replacement file exceeds the upload size limit.'
                : ($uploadError === UPLOAD_ERR_NO_FILE ? 'Choose a replacement file before submitting.' : 'The replacement upload did not complete. Please select the file again.');
            throw new DomainException($message);
        }
        $uploads = [
            'name' => [$requirementId => $file['name'] ?? ''],
            'type' => [$requirementId => $file['type'] ?? ''],
            'tmp_name' => [$requirementId => $file['tmp_name'] ?? ''],
            'error' => [$requirementId => $file['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$requirementId => $file['size'] ?? 0],
        ];

        $movedFiles = [];
        $database->beginTransaction();
        try {
            $lock = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $applicationQuery = $database->prepare('SELECT academic_program_id, academic_year_id, status FROM applications WHERE id = :application_id AND student_id = :student_id AND deleted_at IS NULL LIMIT 1' . $lock);
            $applicationQuery->execute(['application_id' => $applicationId, 'student_id' => $studentId]);
            $application = $applicationQuery->fetch();
            if (!is_array($application) || $application['status'] !== 'documents_incomplete') {
                throw new DomainException('This application is not accepting document replacements.');
            }
            $current = $database->prepare('SELECT id FROM application_documents WHERE application_id = :application_id AND requirement_id = :requirement_id AND is_current = 1 AND review_status IN (\'rejected\', \'action_required\') LIMIT 1' . $lock);
            $current->execute(['application_id' => $applicationId, 'requirement_id' => $requirementId]);
            if (!$current->fetchColumn()) {
                throw new DomainException('Only a document marked as rejected or needing attention can be replaced.');
            }
            $documentIds = self::storeUploads($database, $studentId, $applicationId, (int) $application['academic_program_id'], (int) $application['academic_year_id'], $uploads, $movedFiles, false);
            foreach ($documentIds as $documentId) {
                $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) SELECT id, full_name, \'document.replaced\', \'application_document\', :document_id, \'success\', :ip FROM users WHERE id = :user_id');
                $audit->execute(['document_id' => $documentId, 'ip' => request_ip(), 'user_id' => $studentId]);
                $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, \'document_replaced\', \'Replacement document received\', \'Your replacement document was uploaded and is waiting for review.\', \'application_document\', :document_id)');
                $notification->execute(['user_id' => $studentId, 'document_id' => $documentId]);
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            foreach ($movedFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            self::auditReplacementFailure($database, $studentId, $applicationId);
            if ($error instanceof DomainException) {
                throw $error;
            }
            throw $error;
        }
    }

    private static function auditReplacementFailure(PDO $database, int $studentId, int $applicationId): void
    {
        try {
            $failureAudit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address) SELECT id, full_name, \'document.replacement_failed\', \'application\', :application_id, \'failure\', \'warning\', :ip FROM users WHERE id = :user_id');
            $failureAudit->execute(['application_id' => $applicationId > 0 ? $applicationId : null, 'ip' => request_ip(), 'user_id' => $studentId]);
        } catch (Throwable) {
        }
    }

    public static function save(PDO $database, int $studentId, array $input, array $files, bool $submit, ?int $step = null): array
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $mobile = trim((string) ($input['mobile_number'] ?? ''));
        $birthDate = trim((string) ($input['date_of_birth'] ?? ''));
        $address = trim((string) ($input['address_line'] ?? ''));
        $municipality = trim((string) ($input['municipality'] ?? ''));
        $province = trim((string) ($input['province'] ?? ''));
        $emergencyName = trim((string) ($input['emergency_contact_name'] ?? ''));
        $emergencyNumber = trim((string) ($input['emergency_contact_number'] ?? ''));
        $programValue = trim((string) ($input['academic_program_id'] ?? ''));
        $yearValue = trim((string) ($input['academic_year_id'] ?? ''));
        $programId = $programValue === '' ? 0 : filter_var($programValue, FILTER_VALIDATE_INT);
        $yearId = $yearValue === '' ? 0 : filter_var($yearValue, FILTER_VALIDATE_INT);
        $campus = trim((string) ($input['preferred_campus'] ?? ''));
        $previousSchool = trim((string) ($input['previous_school'] ?? ''));
        $strand = trim((string) ($input['senior_high_strand'] ?? ''));

        if ($step !== null && ($step < 0 || $step > 2)) {
            return ['ok' => false, 'step' => 0, 'errors' => [], 'message' => 'The requested application step is invalid.'];
        }
        if ($programId === false || $yearId === false || $programId < 0 || $yearId < 0) {
            return ['ok' => false, 'step' => 1, 'errors' => ['academic_program_id' => 'Choose a valid available program.', 'academic_year_id' => 'Choose an open intake.'], 'message' => 'Choose a valid available program and academic year.'];
        }
        $errors = [];
        $requiredThrough = $submit ? 2 : $step;
        if ($requiredThrough !== null && $requiredThrough >= 0) {
            foreach ([
                'full_name' => $fullName,
                'mobile_number' => $mobile,
                'date_of_birth' => $birthDate,
                'address_line' => $address,
                'municipality' => $municipality,
                'province' => $province,
                'emergency_contact_name' => $emergencyName,
                'emergency_contact_number' => $emergencyNumber,
            ] as $field => $fieldValue) {
                if ($fieldValue === '') {
                    $errors[$field] = match ($field) {
                        'full_name' => 'Please enter your full name.',
                        'mobile_number' => 'Please enter your mobile number.',
                        'date_of_birth' => 'Please enter your date of birth.',
                        'address_line' => 'Please enter your house number, street, and barangay.',
                        'municipality' => 'Please enter your city or municipality.',
                        'province' => 'Please enter your province.',
                        'emergency_contact_name' => 'Please enter your emergency contact name.',
                        'emergency_contact_number' => 'Please enter your emergency contact number.',
                    };
                }
            }
        }
        if ($requiredThrough !== null && $requiredThrough >= 1) {
            foreach ([
                'academic_program_id' => [$programId, 'Choose an active academic program.'],
                'academic_year_id' => [$yearId, 'Choose an open intake.'],
                'preferred_campus' => [$campus, 'Please enter your preferred campus.'],
                'previous_school' => [$previousSchool, 'Please enter your previous school.'],
                'senior_high_strand' => [$strand, 'Please enter your Senior High School strand.'],
            ] as $field => [$fieldValue, $message]) {
                if ($fieldValue === '' || $fieldValue === 0) {
                    $errors[$field] = $message;
                }
            }
        }
        if ($submit && ($input['privacy_consent'] ?? '') !== '1') {
            $errors['privacy_consent'] = 'Please confirm the privacy notice before submitting.';
        }
        $date = $birthDate === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        if ($fullName !== '' && (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'-]*$/u', $fullName) || mb_strlen($fullName) > 160)) {
            $errors['full_name'] = 'Enter a valid name using letters, spaces, apostrophes, or hyphens.';
        }
        if ($mobile !== '' && !preg_match('/^(?:09\d{9}|\+639\d{9})$/', $mobile)) {
            $errors['mobile_number'] = 'Enter an 11-digit mobile number starting with 09, or use +639 followed by 9 digits.';
        }
        if ($birthDate !== '' && (!$date || $date->format('Y-m-d') !== $birthDate || $date >= new DateTimeImmutable('today'))) {
            $errors['date_of_birth'] = 'Enter a valid date of birth in the past.';
        }
        if ($address !== '' && (mb_strlen($address) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $address))) {
            $errors['address_line'] = 'Enter an address of 255 characters or fewer.';
        }
        if ($municipality !== '' && mb_strlen($municipality) > 120) {
            $errors['municipality'] = 'City or municipality must be 120 characters or fewer.';
        }
        if ($province !== '' && mb_strlen($province) > 120) {
            $errors['province'] = 'Province must be 120 characters or fewer.';
        }
        if ($emergencyName !== '' && (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'-]*$/u', $emergencyName) || mb_strlen($emergencyName) > 160)) {
            $errors['emergency_contact_name'] = 'Enter a valid emergency contact name.';
        }
        if ($emergencyNumber !== '' && (!preg_match('/^[+]?[0-9(). -]{7,30}$/', $emergencyNumber) || mb_strlen($emergencyNumber) > 30)) {
            $errors['emergency_contact_number'] = 'Enter a valid contact number.';
        }
        if (mb_strlen($campus) > 120) {
            $errors['preferred_campus'] = 'Preferred campus must be 120 characters or fewer.';
        }
        if (mb_strlen($previousSchool) > 180) {
            $errors['previous_school'] = 'Previous school must be 180 characters or fewer.';
        }
        if (mb_strlen($strand) > 120) {
            $errors['senior_high_strand'] = 'Strand must be 120 characters or fewer.';
        }
        if ($errors !== []) {
            $applicantFields = ['full_name', 'mobile_number', 'date_of_birth', 'address_line', 'municipality', 'province', 'emergency_contact_name', 'emergency_contact_number'];
            $academicFields = ['academic_program_id', 'academic_year_id', 'preferred_campus', 'previous_school', 'senior_high_strand'];
            $errorStep = array_intersect(array_keys($errors), $applicantFields) !== []
                ? 0
                : (array_intersect(array_keys($errors), $academicFields) !== [] ? 1 : 3);
            return ['ok' => false, 'step' => $errorStep, 'errors' => $errors, 'message' => reset($errors)];
        }
        $applicantProfileSnapshot = json_encode(self::applicantProfileSnapshot($input), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $programQuery = $database->prepare('SELECT id FROM academic_programs WHERE id = :id AND is_active = 1');
        $programQuery->execute(['id' => $programId]);
        $yearQuery = $database->prepare('SELECT id FROM academic_years WHERE id = :id AND is_open = 1');
        $yearQuery->execute(['id' => $yearId]);
        if (($programId > 0 && !$programQuery->fetchColumn()) || ($yearId > 0 && !$yearQuery->fetchColumn())) {
            return ['ok' => false, 'step' => 1, 'errors' => ['academic_program_id' => 'Choose an active program and open intake.'], 'message' => 'Choose an available program and open academic year.'];
        }

        $movedFiles = [];
        $missingNames = [];
        try {
            $database->beginTransaction();
            $lock = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $student = $database->prepare('SELECT id FROM users WHERE id = :user_id AND role = \'student\'' . $lock);
            $student->execute(['user_id' => $studentId]);
            if (!$student->fetchColumn()) {
                throw new DomainException('The student account is not available.');
            }
            $existing = self::current($database, $studentId);
            if ($existing !== null && $existing['status'] !== 'draft') {
                throw new DomainException('This application has already been submitted and can no longer be edited here.');
            }
            $profile = $database->prepare(
                'UPDATE users SET full_name = :full_name WHERE id = :user_id AND role = \'student\';'
            );
            $profile->execute(['full_name' => $fullName, 'user_id' => $studentId]);
            $profile = $database->prepare(
                'UPDATE student_profiles SET mobile_number = :mobile, date_of_birth = :birth_date, address_line = :address, municipality = :municipality, province = :province, emergency_contact_name = :emergency_name, emergency_contact_number = :emergency_number, privacy_consent_at = CASE WHEN :consent = 1 THEN UTC_TIMESTAMP() ELSE privacy_consent_at END WHERE user_id = :user_id'
            );
            $profile->execute([
                'mobile' => $mobile ?: null,
                'birth_date' => $birthDate ?: null,
                'address' => $address ?: null,
                'municipality' => $municipality ?: null,
                'province' => $province ?: null,
                'emergency_name' => $emergencyName ?: null,
                'emergency_number' => $emergencyNumber ?: null,
                'consent' => ($input['privacy_consent'] ?? '') === '1' ? 1 : 0,
                'user_id' => $studentId,
            ]);

            if ($existing === null) {
                $insert = $database->prepare(
                    'INSERT INTO applications (student_id, academic_program_id, academic_year_id, preferred_campus, previous_school, senior_high_strand, applicant_profile_snapshot) VALUES (:student_id, :program_id, :year_id, :campus, :school, :strand, :profile_snapshot)'
                );
                $insert->execute([
                    'student_id' => $studentId,
                    'program_id' => $programId ?: null,
                    'year_id' => $yearId ?: null,
                    'campus' => $campus ?: null,
                    'school' => $previousSchool ?: null,
                    'strand' => $strand ?: null,
                    'profile_snapshot' => $applicantProfileSnapshot,
                ]);
                $applicationId = (int) $database->lastInsertId();
                self::audit($database, $studentId, 'application.created', 'application', $applicationId);
            } else {
                $applicationId = (int) $existing['id'];
                $update = $database->prepare(
                    'UPDATE applications SET academic_program_id = :program_id, academic_year_id = :year_id, preferred_campus = :campus, previous_school = :school, senior_high_strand = :strand, applicant_profile_snapshot = :profile_snapshot WHERE id = :application_id AND student_id = :student_id AND status = \'draft\''
                );
                $update->execute([
                    'program_id' => $programId ?: null,
                    'year_id' => $yearId ?: null,
                    'campus' => $campus ?: null,
                    'school' => $previousSchool ?: null,
                    'strand' => $strand ?: null,
                    'profile_snapshot' => $applicantProfileSnapshot,
                    'application_id' => $applicationId,
                    'student_id' => $studentId,
                ]);
                self::audit($database, $studentId, 'application.draft_updated', 'application', $applicationId);
            }

            if (!empty($files['name']) && is_array($files['name'])) {
                $uploadedDocumentIds = self::storeUploads($database, $studentId, $applicationId, $programId, $yearId, $files, $movedFiles, $existing === null || $existing['status'] === 'draft');
                foreach ($uploadedDocumentIds as $documentId) {
                    self::audit($database, $studentId, 'document.uploaded', 'application_document', $documentId);
                }
            }

            if ($submit || $requiredThrough === 2) {
                $missing = $database->prepare(
                    'SELECT r.name FROM admission_requirements r LEFT JOIN application_documents d ON d.requirement_id = r.id AND d.application_id = :application_id AND d.is_current = 1 AND d.review_status <> \'rejected\' WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = :program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = :year_id) AND d.id IS NULL ORDER BY r.sort_order, r.name'
                );
                $missing->execute(['application_id' => $applicationId, 'program_id' => $programId, 'year_id' => $yearId]);
                $missingNames = $missing->fetchAll(PDO::FETCH_COLUMN);
            }

            if ($submit && $missingNames === []) {

                $reference = 'AF-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(5)));
                $submitStatement = $database->prepare(
                    'UPDATE applications SET reference_number = :reference, status = \'submitted\', submitted_at = UTC_TIMESTAMP() WHERE id = :application_id AND student_id = :student_id AND status = \'draft\''
                );
                $submitStatement->execute(['reference' => $reference, 'application_id' => $applicationId, 'student_id' => $studentId]);
                if ($submitStatement->rowCount() !== 1) {
                    throw new DomainException('This application has already been submitted.');
                }
                $history = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by) VALUES (:application_id, \'draft\', \'submitted\', :user_id)');
                $history->execute(['application_id' => $applicationId, 'user_id' => $studentId]);
                $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, \'application_submitted\', \'Application submitted\', \'Your application was submitted successfully. The Admission Office will review it and update your portal.\', \'application\', :application_id)');
                $notification->execute(['user_id' => $studentId, 'application_id' => $applicationId]);
                self::audit($database, $studentId, 'application.submitted', 'application', $applicationId);
            }

            $database->commit();

            if ($missingNames !== []) {
                return [
                    'ok' => false,
                    'step' => 2,
                    'errors' => [],
                    'message' => 'Upload each required document before continuing: ' . implode(', ', $missingNames) . '.',
                ];
            }

            return ['ok' => true, 'step' => $step === null ? null : $step + 1, 'errors' => [], 'message' => $submit ? 'Your application was submitted successfully.' : ($step === null ? 'Your draft has been saved.' : 'Step saved. Continue when you are ready.')];
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            foreach ($movedFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if ($error instanceof DomainException) {
                $message = $error->getMessage();
                $failedAt = preg_match('/document|upload|file/i', $message) ? 2 : ($submit ? 3 : (int) ($step ?? 0));
                return ['ok' => false, 'step' => $failedAt, 'errors' => [], 'message' => $message];
            }
            throw $error;
        }
    }

    private static function storeUploads(PDO $database, int $studentId, int $applicationId, int $programId, int $yearId, array $files, array &$movedFiles, bool $allowDraftReplace): array
    {
        $uploadedDocumentIds = [];
        $directory = dirname(__DIR__) . '/storage/uploads';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private upload storage is unavailable.');
        }

        foreach ($files['name'] as $rawRequirementId => $originalName) {
            $errorCode = $files['error'][$rawRequirementId] ?? UPLOAD_ERR_NO_FILE;
            if ($errorCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errorCode !== UPLOAD_ERR_OK) {
                throw new DomainException('A document upload did not complete. Please select the file again.');
            }

            $requirementId = filter_var($rawRequirementId, FILTER_VALIDATE_INT);
            $temporaryPath = $files['tmp_name'][$rawRequirementId] ?? '';
            $reportedSize = (int) ($files['size'][$rawRequirementId] ?? 0);
            $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
            $requirementQuery = $database->prepare(
                'SELECT id, allowed_extensions, max_file_size_bytes FROM admission_requirements WHERE id = :id AND is_active = 1 AND is_required = 1 AND (academic_program_id IS NULL OR academic_program_id = :program_id) AND (academic_year_id IS NULL OR academic_year_id = :year_id)'
            );
            $requirementQuery->execute(['id' => $requirementId, 'program_id' => $programId, 'year_id' => $yearId]);
            $requirement = $requirementQuery->fetch();
            if (!is_array($requirement) || !is_uploaded_file($temporaryPath)) {
                throw new DomainException('One of the selected files is not an allowed type or exceeds its size limit.');
            }

            $fileSize = filesize($temporaryPath);
            if ($fileSize === false || $reportedSize !== $fileSize) {
                throw new DomainException('The selected document size could not be verified. Please select the file again.');
            }
            $allowedExtensions = array_map('trim', explode(',', strtolower($requirement['allowed_extensions'])));
            $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            if (!UploadPolicy::accepts($extension, $detectedMime, $fileSize, $allowedExtensions, (int) $requirement['max_file_size_bytes'])) {
                throw new DomainException('A document file type does not match the configured requirement.');
            }

            $existingQuery = $database->prepare('SELECT id, version, review_status FROM application_documents WHERE application_id = :application_id AND requirement_id = :requirement_id AND is_current = 1 LIMIT 1 FOR UPDATE');
            $existingQuery->execute(['application_id' => $applicationId, 'requirement_id' => $requirementId]);
            $existingDocument = $existingQuery->fetch();
            $version = 1;
            if (is_array($existingDocument)) {
                if (!$allowDraftReplace && !in_array($existingDocument['review_status'], ['rejected', 'action_required'], true)) {
                    throw new DomainException('A document already under review cannot be replaced yet.');
                }
                $version = (int) $existingDocument['version'] + 1;
                $expire = $database->prepare('UPDATE application_documents SET is_current = 0 WHERE id = :id AND is_current = 1');
                $expire->execute(['id' => $existingDocument['id']]);
            }

            $storageName = bin2hex(random_bytes(24)) . '.' . $extension;
            $targetPath = $directory . '/' . $storageName;
            if (!move_uploaded_file($temporaryPath, $targetPath)) {
                throw new DomainException('The selected document could not be saved. Try again.');
            }
            $movedFiles[] = $targetPath;
            chmod($targetPath, 0600);

            $insert = $database->prepare(
                'INSERT INTO application_documents (application_id, requirement_id, version, storage_name, original_name, detected_mime_type, file_size_bytes, sha256, uploaded_by) VALUES (:application_id, :requirement_id, :version, :storage_name, :original_name, :mime, :size, :sha256, :user_id)'
            );
            $insert->execute([
                'application_id' => $applicationId,
                'requirement_id' => $requirementId,
                'version' => $version,
                'storage_name' => $storageName,
                'original_name' => mb_substr(basename((string) $originalName), 0, 255),
                'mime' => $detectedMime,
                'size' => $fileSize,
                'sha256' => hash_file('sha256', $targetPath),
                'user_id' => $studentId,
            ]);
            $uploadedDocumentIds[] = (int) $database->lastInsertId();
        }

        return $uploadedDocumentIds;
    }

    private static function audit(PDO $database, int $studentId, string $action, string $entityType, int $entityId): void
    {
        $statement = $database->prepare(
            'INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity) SELECT id, full_name, :action, :entity_type, :entity_id, \'success\', \'info\' FROM users WHERE id = :user_id'
        );
        $statement->execute([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $studentId,
        ]);
    }
}
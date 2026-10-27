<?php
declare(strict_types=1);

final class AccountSettingsService
{
    public static function profile(PDO $database, int $userId): ?array
    {
        $statement = $database->prepare('SELECT u.id, u.full_name, u.email, u.role, u.account_status, u.created_at, sp.mobile_number, sp.date_of_birth, sp.address_line, sp.municipality, sp.province, sp.emergency_contact_name, sp.emergency_contact_number FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.id = :user_id AND u.account_status = \'active\' LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $profile = $statement->fetch();

        return is_array($profile) ? $profile : null;
    }

    public static function applicantProfileFields(): array
    {
        return [
            'full_name' => 'Full name',
            'mobile_number' => 'Mobile number',
            'date_of_birth' => 'Date of birth',
            'address_line' => 'House number, street, and barangay',
            'municipality' => 'City or municipality',
            'province' => 'Province',
            'emergency_contact_name' => 'Emergency contact name',
            'emergency_contact_number' => 'Emergency contact number',
        ];
    }

    public static function missingApplicantProfileFields(array $profile): array
    {
        $missing = [];
        foreach (self::applicantProfileFields() as $field => $label) {
            if (trim((string) ($profile[$field] ?? '')) === '') {
                $missing[$field] = $label;
            }
        }

        return $missing;
    }

    public static function applicationStartGate(PDO $database, int $studentId): array
    {
        $profile = self::profile($database, $studentId);
        $missing = !is_array($profile) || ($profile['role'] ?? '') !== 'student'
            ? self::applicantProfileFields()
            : self::missingApplicantProfileFields($profile);

        return ['allowed' => $missing === [], 'missing' => $missing];
    }

    public static function updateApplicantProfile(PDO $database, array $user, array $input): array
    {
        if (($user['role'] ?? '') !== 'student' || (int) ($user['id'] ?? 0) < 1) {
            return ['ok' => false, 'message' => 'Only an active applicant can update applicant profile information.', 'errors' => []];
        }

        $values = [
            'full_name' => trim((string) ($input['full_name'] ?? '')),
            'mobile_number' => trim((string) ($input['mobile_number'] ?? '')),
            'date_of_birth' => trim((string) ($input['date_of_birth'] ?? '')),
            'address_line' => trim((string) ($input['address_line'] ?? '')),
            'municipality' => trim((string) ($input['municipality'] ?? '')),
            'province' => trim((string) ($input['province'] ?? '')),
            'emergency_contact_name' => trim((string) ($input['emergency_contact_name'] ?? '')),
            'emergency_contact_number' => trim((string) ($input['emergency_contact_number'] ?? '')),
        ];
        $errors = [];
        foreach (self::applicantProfileFields() as $field => $label) {
            if ($values[$field] === '') {
                $errors[$field] = 'Enter your ' . mb_strtolower($label) . '.';
            }
        }
        if ($values['full_name'] !== '' && (mb_strlen($values['full_name']) > 160 || !preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'-]*$/u', $values['full_name']))) {
            $errors['full_name'] = 'Enter a valid name using letters, spaces, apostrophes, or hyphens (160 characters maximum).';
        }
        if ($values['mobile_number'] !== '' && !preg_match('/^(?:09\d{9}|\+639\d{9})$/', $values['mobile_number'])) {
            $errors['mobile_number'] = 'Enter an 11-digit mobile number starting with 09, or use +639 followed by 9 digits.';
        }
        if ($values['date_of_birth'] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date_of_birth']);
            if (!$date || $date->format('Y-m-d') !== $values['date_of_birth'] || $date >= new DateTimeImmutable('today')) {
                $errors['date_of_birth'] = 'Enter a valid date of birth in the past.';
            }
        }
        if ($values['address_line'] !== '' && (mb_strlen($values['address_line']) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $values['address_line']))) {
            $errors['address_line'] = 'Enter an address of 255 characters or fewer.';
        }
        foreach (['municipality' => 120, 'province' => 120] as $field => $maximum) {
            if (mb_strlen($values[$field]) > $maximum) {
                $errors[$field] = self::applicantProfileFields()[$field] . ' must be ' . $maximum . ' characters or fewer.';
            }
        }
        if ($values['emergency_contact_name'] !== '' && (mb_strlen($values['emergency_contact_name']) > 160 || !preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'-]*$/u', $values['emergency_contact_name']))) {
            $errors['emergency_contact_name'] = 'Enter a valid emergency contact name.';
        }
        if ($values['emergency_contact_number'] !== '' && !preg_match('/^[+]?[0-9(). -]{7,30}$/', $values['emergency_contact_number'])) {
            $errors['emergency_contact_number'] = 'Enter a valid emergency contact number.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => reset($errors), 'errors' => $errors, 'values' => $values];
        }

        $database->beginTransaction();
        try {
            $lock = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $check = $database->prepare('SELECT full_name FROM users WHERE id = :user_id AND role = \'student\' AND account_status = \'active\'' . $lock);
            $check->execute(['user_id' => $user['id']]);
            $previousName = $check->fetchColumn();
            if (!is_string($previousName)) {
                throw new DomainException('Your active applicant account could not be found.');
            }
            $updateUser = $database->prepare('UPDATE users SET full_name = :full_name WHERE id = :user_id AND role = \'student\' AND account_status = \'active\'');
            $updateUser->execute(['full_name' => $values['full_name'], 'user_id' => $user['id']]);
            $updateProfile = $database->prepare('UPDATE student_profiles SET mobile_number = :mobile_number, date_of_birth = :date_of_birth, address_line = :address_line, municipality = :municipality, province = :province, emergency_contact_name = :emergency_contact_name, emergency_contact_number = :emergency_contact_number WHERE user_id = :user_id');
            $updateProfile->execute([
                'mobile_number' => $values['mobile_number'],
                'date_of_birth' => $values['date_of_birth'],
                'address_line' => $values['address_line'],
                'municipality' => $values['municipality'],
                'province' => $values['province'],
                'emergency_contact_name' => $values['emergency_contact_name'],
                'emergency_contact_number' => $values['emergency_contact_number'],
                'user_id' => $user['id'],
            ]);
            if ($updateProfile->rowCount() === 0) {
                $exists = $database->prepare('SELECT 1 FROM student_profiles WHERE user_id = :user_id');
                $exists->execute(['user_id' => $user['id']]);
                if (!$exists->fetchColumn()) {
                    throw new DomainException('Your applicant profile is not available.');
                }
            }
            $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address) VALUES (:user_id, :actor, \'account.applicant_profile_updated\', \'user\', :entity_id, \'success\', \'notice\', :ip)');
            $audit->execute(['user_id' => $user['id'], 'actor' => mb_substr($previousName, 0, 160), 'entity_id' => $user['id'], 'ip' => request_ip()]);
            $database->commit();

            return ['ok' => true, 'message' => 'Your applicant profile has been updated.', 'full_name' => $values['full_name'], 'values' => $values, 'errors' => []];
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            if ($error instanceof DomainException) {
                return ['ok' => false, 'message' => $error->getMessage(), 'errors' => [], 'values' => $values];
            }
            throw $error;
        }
    }

    public static function updateProfileName(PDO $database, array $user, string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160 || !preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'-]*$/u', $name)) {
            return ['ok' => false, 'message' => 'Enter a valid name using letters, spaces, apostrophes, or hyphens (160 characters maximum).'];
        }
        if ((int) ($user['id'] ?? 0) < 1 || !in_array($user['role'] ?? '', ['student', 'reviewer', 'admin'], true)) {
            return ['ok' => false, 'message' => 'Your account could not be updated. Sign in again and retry.'];
        }

        $database->beginTransaction();
        try {
            $lock = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $check = $database->prepare('SELECT full_name FROM users WHERE id = :user_id AND role = :role AND account_status = \'active\'' . $lock);
            $check->execute(['user_id' => $user['id'], 'role' => $user['role']]);
            $previousName = $check->fetchColumn();
            if (!is_string($previousName)) {
                throw new DomainException('Your active account could not be found.');
            }
            $update = $database->prepare('UPDATE users SET full_name = :full_name WHERE id = :user_id AND role = :role AND account_status = \'active\'');
            $update->execute(['full_name' => $name, 'user_id' => $user['id'], 'role' => $user['role']]);
            $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address) VALUES (:user_id, :actor, \'account.profile_updated\', \'user\', :entity_id, \'success\', \'notice\', :ip)');
            $audit->execute(['user_id' => $user['id'], 'actor' => mb_substr($previousName, 0, 160), 'entity_id' => $user['id'], 'ip' => request_ip()]);
            $database->commit();

            return ['ok' => true, 'message' => 'Your profile name has been updated.', 'full_name' => $name];
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            if ($error instanceof DomainException) {
                return ['ok' => false, 'message' => $error->getMessage()];
            }
            throw $error;
        }
    }
}

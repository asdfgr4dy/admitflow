-- Fictional, editable reference data for local demonstration only.
-- No student accounts or real personal information are included.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT IGNORE INTO academic_years
    (label, starts_on, ends_on, application_deadline, is_open, is_sample)
VALUES
    ('AY 2026-2027', '2026-06-01', '2027-05-31', '2026-10-31', 1, 1);

INSERT IGNORE INTO academic_programs (code, name, is_active, is_sample) VALUES
    ('BSIT', 'BS Information Technology', 1, 1),
    ('BSCS', 'BS Computer Science', 1, 1),
    ('BSA', 'BS Accountancy', 1, 1),
    ('BACOMM', 'BA Communication', 1, 1),
    ('BSPSY', 'BS Psychology', 1, 1),
    ('BSN', 'BS Nursing', 1, 1),
    ('BSBA', 'BS Business Administration', 1, 1);

INSERT IGNORE INTO admission_requirements
    (code, name, instructions, allowed_extensions, max_file_size_bytes, is_required, is_active, is_sample, sort_order)
VALUES
    ('PSA_BIRTH_CERTIFICATE', 'PSA Birth Certificate', 'Upload a clear copy of the applicant birth certificate.', 'pdf,jpg,jpeg,png', 10485760, 1, 1, 1, 10),
    ('FORM_138', 'Form 138 / Senior High School Report Card', 'Upload a readable copy of the latest report card.', 'pdf,jpg,jpeg,png', 10485760, 1, 1, 1, 20),
    ('GOOD_MORAL', 'Certificate of Good Moral Character', 'Upload a current certificate issued by the previous school.', 'pdf,jpg,jpeg,png', 10485760, 1, 1, 1, 30),
    ('ID_PHOTO', '2x2 ID Photo', 'Upload a recent, clear 2x2 ID photo.', 'jpg,jpeg,png', 5242880, 1, 1, 1, 40),
    ('VALID_ID', 'Valid ID', 'Upload a clear copy of one valid identification document.', 'pdf,jpg,jpeg,png', 10485760, 1, 1, 1, 50);

INSERT IGNORE INTO system_settings (setting_key, setting_value, value_type, description, is_public) VALUES
    ('office_name', 'Admission Office', 'string', 'Official office identity used in the application.', 1),
    ('timezone', 'Asia/Manila', 'string', 'Timezone used for dates displayed to users.', 1),
    ('academic_year_label', 'AY 2026-2027', 'string', 'Editable sample academic year for local demonstration.', 1),
    ('sample_data_notice', 'Reference records are fictional sample data and should be reviewed before public use.', 'string', 'Reminder to replace demonstration values before deployment.', 0);
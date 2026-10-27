-- Add an immutable applicant-profile snapshot for application drafts and submissions.
-- Approved migration: existing active drafts are initialized from their current profile values.
-- Submitted applications and other records are not updated by the backfill.

ALTER TABLE applications
    ADD COLUMN applicant_profile_snapshot JSON NULL AFTER senior_high_strand;

UPDATE applications a
JOIN users u ON u.id = a.student_id AND u.role = 'student'
LEFT JOIN student_profiles sp ON sp.user_id = u.id
SET a.applicant_profile_snapshot = JSON_OBJECT(
    'full_name', u.full_name,
    'mobile_number', sp.mobile_number,
    'date_of_birth', sp.date_of_birth,
    'address_line', sp.address_line,
    'municipality', sp.municipality,
    'province', sp.province,
    'emergency_contact_name', sp.emergency_contact_name,
    'emergency_contact_number', sp.emergency_contact_number
)
WHERE a.status = 'draft'
  AND a.deleted_at IS NULL
  AND a.applicant_profile_snapshot IS NULL;
<?php
declare(strict_types=1);
require 'app/bootstrap.php';
$_SESSION['csrf_token'] = 'test-csrf-token-123';
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32)); }
$profile = [
  'id' => 7, 'full_name' => 'Juan dela Cruz', 'email' => 'juan@example.test',
  'role' => 'student', 'account_status' => 'active',
  'created_at' => '2026-01-15 08:00:00',
  'mobile_number' => '09123456789', 'date_of_birth' => '2000-02-20',
  'address_line' => '123 Mabini St', 'municipality' => 'Manila', 'province' => 'Metro Manila',
  'emergency_contact_name' => 'Maria dela Cruz', 'emergency_contact_number' => '09987654321',
];
$avatarAvailable = false;
$applicantProfileValues = [];
$applicantProfileErrors = [];
$profileFormName = null;
$profileFormError = null;
$showApplicationCompletionPrompt = false;
$missingApplicantFields = [];
ob_start();
require 'resources/views/settings/profile.php';
$html = ob_get_clean();
$checks = [
  'data-profile-card' => 'single card marker',
  'data-profile-view' => 'read-only view marker',
  'data-profile-edit' => 'edit form marker',
  'data-profile-edit-toggle' => 'edit toggle button',
  'data-profile-cancel' => 'cancel button',
  'Edit Profile' => 'edit label',
  'Save Changes' => 'save label',
  'applicant-profile-form' => 'applicant form class',
  'profile-settings-grid' => 'grid class',
  'Read-only' => 'read-only email hint',
  'role-badge' => 'role badge',
  'Juan dela Cruz' => 'escaped name',
  'juan@example.test' => 'escaped email',
  'Not provided' => 'placeholder',
  '09123456789' => 'mobile',
  'Manila' => 'municipality',
  'Metro Manila' => 'province',
  'Maria dela Cruz' => 'emergency contact',
  'data-avatar-menu-toggle' => 'avatar toggle',
  'role="menu"' => 'avatar menu',
  'Upload new photo' => 'upload item',
  'data-avatar-preview-open' => 'preview item',
  'form="avatar-remove-form"' => 'remove form link',
  'data-confirm="Remove your current profile picture?"' => 'confirm attr',
  'name="_csrf"' => 'csrf token',
  'accept="image/jpeg,image/png"' => 'image accept',
  'data-avatar-save' => 'save photo button',
  'profile-name-error' => 'error id',
  'href="/account/password"' => 'security link',
  'href="/notifications"' => 'notifications link',
];
$fail = 0;
foreach ($checks as $needle => $label) {
  if (str_contains($html, $needle)) { echo "PASS $label\n"; }
  else { echo "FAIL $label (missing: $needle)\n"; $fail++; }
}
echo "hidden edit form: " (str_contains($html, 'data-profile-edit hidden') ? 'PASS' : 'FAIL') . "\n"; if (!str_contains($html, 'data-profile-edit hidden')) $fail++;
echo "visible read-only view: " (!str_contains($html, 'data-profile-view hidden') ? 'PASS' : 'FAIL') . "\n"; if (str_contains($html, 'data-profile-view hidden')) $fail++;
echo "\n$fail failures\n";

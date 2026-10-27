<?php
$applicantFieldValue = static fn (string $field): string => (string) ($applicantProfileValues[$field] ?? $profile[$field] ?? '');
$applicantFieldError = static fn (string $field): string => (string) ($applicantProfileErrors[$field] ?? '');
$profileInEditMode = !empty($applicantProfileErrors) || !empty($profileFormError);
?>
<section class="settings-page container-xl profile-settings-page">
    <div class="settings-page-heading"><div><p class="eyebrow"><a href="/settings">Settings</a> · Personal account</p><h1>My Profile.</h1><p>Manage your identity, avatar, and account information.</p></div><a href="/settings" class="btn btn-outline-navy"><i class="bi bi-arrow-left" aria-hidden="true"></i> Settings</a></div>
    <?php if ($profile['role'] === 'student' && !empty($showApplicationCompletionPrompt)): ?><div class="alert alert-warning profile-completion-alert" role="status"><strong>Complete your applicant profile to start an application.</strong><?php if (($missingApplicantFields ?? []) !== []): ?><span> Still needed: <?= e(implode(', ', array_values($missingApplicantFields))) ?>.</span><?php else: ?><a class="btn btn-sm btn-navy" href="/application">Start your application</a><?php endif; ?></div><?php endif; ?>
    <div class="profile-settings-grid">
        <div class="profile-settings-column">
            <section class="profile-settings-panel profile-card" data-profile-card aria-labelledby="profile-identity-title">
                <div class="profile-card-header">
                    <div class="profile-card-avatar">
                        <div class="profile-avatar-menu" data-avatar-menu>
                            <button class="profile-avatar-trigger" type="button" data-avatar-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="profile-avatar-menu" aria-label="Edit profile picture">
                                <img class="profile-settings-avatar profile-settings-avatar-image" <?= !empty($avatarAvailable) ? 'src="/settings/avatar"' : '' ?> alt="" data-avatar-preview <?= empty($avatarAvailable) ? 'hidden' : '' ?>><span class="profile-settings-avatar" aria-hidden="true" data-avatar-fallback <?= !empty($avatarAvailable) ? 'hidden' : '' ?>><?= e(strtoupper(mb_substr(trim($profile['full_name']), 0, 1))) ?></span><span class="profile-avatar-camera" aria-hidden="true"><i class="bi bi-camera-fill"></i></span>
                            </button>
                            <div class="profile-avatar-popover" id="profile-avatar-menu" role="menu" aria-label="Profile picture options" hidden>
                                <button type="button" role="menuitem" data-avatar-upload><i class="bi bi-upload" aria-hidden="true"></i> Upload new photo</button>
                                <button type="button" role="menuitem" data-avatar-preview-open hidden><i class="bi bi-eye" aria-hidden="true"></i> Preview photo</button>
                                <?php if (!empty($avatarAvailable)): ?><button type="submit" form="avatar-remove-form" role="menuitem" data-confirm="Remove your current profile picture?"><i class="bi bi-trash" aria-hidden="true"></i> Remove photo</button><?php endif; ?>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-amber profile-avatar-save-button" type="submit" form="avatar-upload-form" data-avatar-save hidden data-loading-label="Uploading..."><i class="bi bi-upload" aria-hidden="true"></i> Save Photo</button>
                        <form method="post" action="/settings/avatar" enctype="multipart/form-data" class="profile-avatar-upload-form" id="avatar-upload-form" data-avatar-form data-submit-once><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input class="visually-hidden" id="profile_avatar" name="avatar" type="file" accept="image/jpeg,image/png" data-avatar-input aria-label="Choose a JPEG or PNG profile photo"><button class="visually-hidden" type="submit" data-loading-label="Uploading...">Save Photo</button></form>
                        <?php if (!empty($avatarAvailable)): ?><form method="post" action="/settings/avatar" class="visually-hidden" id="avatar-remove-form" data-submit-once><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"></form><?php endif; ?>
                        <p class="settings-inline-feedback profile-avatar-feedback" data-avatar-feedback role="status" aria-live="polite">JPEG or PNG, maximum 2 MB.</p>
                        <dialog class="profile-avatar-preview-dialog" data-avatar-preview-dialog aria-labelledby="avatar-preview-title"><div class="profile-avatar-dialog-heading"><div><p class="eyebrow">Profile photo</p><h2 id="avatar-preview-title">Preview photo</h2></div><button class="btn btn-sm btn-outline-navy" type="button" data-avatar-preview-cancel aria-label="Close photo preview"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div><img class="profile-avatar-dialog-image" data-avatar-dialog-image alt="Selected profile photo preview"><div class="profile-avatar-dialog-actions"><button class="btn btn-outline-navy" type="button" data-avatar-preview-cancel>Close preview</button></div></dialog>
                    </div>
                    <div class="profile-card-identity">
                        <div><p class="eyebrow">Authenticated account</p><h2 id="profile-identity-title"><?= e($profile['full_name']) ?></h2></div>
                        <div class="profile-card-meta"><span class="profile-card-email"><?= e($profile['email']) ?></span><span class="role-badge"><?= e(ucfirst($profile['role'])) ?></span></div>
                    </div>
                    <button class="btn btn-navy" type="button" data-profile-edit-toggle aria-controls="profile-card-edit" aria-expanded="<?= $profileInEditMode ? 'true' : 'false' ?>"<?= $profileInEditMode ? ' hidden' : '' ?>>Edit Profile</button>
                </div>

                <div class="profile-card-view" data-profile-view<?= $profileInEditMode ? ' hidden' : '' ?>>
                    <?php if ($profile['role'] === 'student'): ?>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Personal information</h3>
                        <dl class="profile-settings-details">
                            <div><dt>Full name</dt><dd><?= e($profile['full_name'] ?: 'Not provided') ?></dd></div>
                            <div><dt>Mobile number</dt><dd><?= e($profile['mobile_number'] ?: 'Not provided') ?></dd></div>
                            <div><dt>Date of birth</dt><dd><?= e($profile['date_of_birth'] ?: 'Not provided') ?></dd></div>
                            <div><dt>Residential address</dt><dd><?= e(trim(implode(', ', array_filter([$profile['address_line'], $profile['municipality'], $profile['province']]))) ?: 'Not provided') ?></dd></div>
                            <div><dt>Emergency contact</dt><dd><?= e($profile['emergency_contact_name'] ?: 'Not provided') ?></dd></div>
                            <div><dt>Emergency number</dt><dd><?= e($profile['emergency_contact_number'] ?: 'Not provided') ?></dd></div>
                        </dl>
                    </div>
                    <?php else: ?>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Personal information</h3>
                        <dl class="profile-settings-details">
                            <div><dt>Full name</dt><dd><?= e($profile['full_name'] ?: 'Not provided') ?></dd></div>
                        </dl>
                    </div>
                    <?php endif; ?>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Account information</h3>
                        <dl class="profile-settings-details">
                            <div><dt>Email address</dt><dd><?= e($profile['email']) ?> <small>Read-only</small></dd></div>
                            <div><dt>Role</dt><dd><?= e(ucfirst($profile['role'])) ?></dd></div>
                            <div><dt>Account status</dt><dd><span class="status-pill status-<?= e($profile['account_status']) ?>"><?= e(ucfirst($profile['account_status'])) ?></span></dd></div>
                            <div><dt>Account created</dt><dd><?= e(date('M j, Y', strtotime($profile['created_at'] . ' UTC'))) ?></dd></div>
                        </dl>
                    </div>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Account tools</h3>
                        <div class="settings-admin-links"><a href="/account/password"><i class="bi bi-shield-lock" aria-hidden="true"></i> Security</a><a href="/notifications"><i class="bi bi-bell" aria-hidden="true"></i> Notifications</a></div>
                    </div>
                </div>

                <form method="post" action="/settings/profile" id="profile-card-edit" class="profile-card-edit profile-settings-form <?= $profile['role'] === 'student' ? 'applicant-profile-form' : '' ?>" data-profile-edit data-submit-once<?= !$profileInEditMode ? ' hidden' : '' ?>>
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <?php if ($profile['role'] === 'student'): ?>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Personal information</h3>
                        <div><label for="profile_full_name">Full name</label><input class="form-control" id="profile_full_name" name="full_name" value="<?= e($applicantFieldValue('full_name')) ?>" data-profile-saved-value="<?= e($profile['full_name']) ?>" maxlength="160" autocomplete="name" required><?php if ($applicantFieldError('full_name') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('full_name')) ?></small><?php endif; ?></div>
                        <div><label for="profile_mobile_number">Mobile number</label><input class="form-control" id="profile_mobile_number" name="mobile_number" value="<?= e($applicantFieldValue('mobile_number')) ?>" data-profile-saved-value="<?= e($profile['mobile_number'] ?? '') ?>" maxlength="30" autocomplete="tel" inputmode="tel" required><?php if ($applicantFieldError('mobile_number') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('mobile_number')) ?></small><?php endif; ?></div>
                        <div><label for="profile_date_of_birth">Date of birth</label><input class="form-control" id="profile_date_of_birth" name="date_of_birth" type="date" value="<?= e($applicantFieldValue('date_of_birth')) ?>" data-profile-saved-value="<?= e($profile['date_of_birth'] ?? '') ?>" required><?php if ($applicantFieldError('date_of_birth') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('date_of_birth')) ?></small><?php endif; ?></div>
                        <div class="field-wide"><label for="profile_address_line">House number, street, and barangay</label><input class="form-control" id="profile_address_line" name="address_line" value="<?= e($applicantFieldValue('address_line')) ?>" data-profile-saved-value="<?= e($profile['address_line'] ?? '') ?>" maxlength="255" autocomplete="street-address" required><?php if ($applicantFieldError('address_line') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('address_line')) ?></small><?php endif; ?></div>
                        <div><label for="profile_municipality">City or municipality</label><input class="form-control" id="profile_municipality" name="municipality" value="<?= e($applicantFieldValue('municipality')) ?>" data-profile-saved-value="<?= e($profile['municipality'] ?? '') ?>" maxlength="120" autocomplete="address-level2" required><?php if ($applicantFieldError('municipality') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('municipality')) ?></small><?php endif; ?></div>
                        <div><label for="profile_province">Province</label><input class="form-control" id="profile_province" name="province" value="<?= e($applicantFieldValue('province')) ?>" data-profile-saved-value="<?= e($profile['province'] ?? '') ?>" maxlength="120" autocomplete="address-level1" required><?php if ($applicantFieldError('province') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('province')) ?></small><?php endif; ?></div>
                        <div><label for="profile_emergency_contact_name">Emergency contact name</label><input class="form-control" id="profile_emergency_contact_name" name="emergency_contact_name" value="<?= e($applicantFieldValue('emergency_contact_name')) ?>" data-profile-saved-value="<?= e($profile['emergency_contact_name'] ?? '') ?>" maxlength="160" autocomplete="name" required><?php if ($applicantFieldError('emergency_contact_name') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('emergency_contact_name')) ?></small><?php endif; ?></div>
                        <div><label for="profile_emergency_contact_number">Emergency contact number</label><input class="form-control" id="profile_emergency_contact_number" name="emergency_contact_number" value="<?= e($applicantFieldValue('emergency_contact_number')) ?>" data-profile-saved-value="<?= e($profile['emergency_contact_number'] ?? '') ?>" maxlength="30" autocomplete="tel" inputmode="tel" required><?php if ($applicantFieldError('emergency_contact_number') !== ''): ?><small class="settings-field-error" role="alert"><?= e($applicantFieldError('emergency_contact_number')) ?></small><?php endif; ?></div>
                    </div>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Account information</h3>
                        <div class="field-readonly"><label for="profile_edit_email">Email address</label><input class="form-control" id="profile_edit_email" type="email" value="<?= e($profile['email']) ?>" readonly aria-readonly="true"><small class="form-hint">Email address cannot be changed from this page.</small></div>
                        <div class="field-readonly"><label for="profile_edit_role">Role</label><input class="form-control" id="profile_edit_role" type="text" value="<?= e(ucfirst($profile['role'])) ?>" readonly aria-readonly="true"></div>
                        <div class="field-readonly"><label for="profile_edit_status">Account status</label><input class="form-control" id="profile_edit_status" type="text" value="<?= e(ucfirst($profile['account_status'])) ?>" readonly aria-readonly="true"></div>
                        <div class="field-readonly"><label for="profile_edit_created">Account created</label><input class="form-control" id="profile_edit_created" type="text" value="<?= e(date('M j, Y', strtotime($profile['created_at'] . ' UTC'))) ?>" readonly aria-readonly="true"></div>
                    </div>
                    <?php else: ?>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Personal information</h3>
                        <div><label for="profile_full_name">Display name</label><input class="form-control" id="profile_full_name" name="full_name" value="<?= e($profileFormName ?? $profile['full_name']) ?>" data-profile-saved-value="<?= e($profile['full_name']) ?>" maxlength="160" autocomplete="name" aria-describedby="profile-name-help<?= !empty($profileFormError) ? ' profile-name-error' : '' ?>" <?= !empty($profileFormError) ? 'aria-invalid="true"' : '' ?> required><?php if (!empty($profileFormError)): ?><small class="settings-field-error" id="profile-name-error" role="alert"><?= e($profileFormError) ?></small><?php endif; ?><small id="profile-name-help">Use letters, spaces, apostrophes, or hyphens. Maximum 160 characters.</small></div>
                    </div>
                    <div class="profile-card-section">
                        <h3 class="profile-card-section-title">Account information</h3>
                        <div class="field-readonly"><label for="profile_edit_email">Email address</label><input class="form-control" id="profile_edit_email" type="email" value="<?= e($profile['email']) ?>" readonly aria-readonly="true"><small class="form-hint">Email address cannot be changed from this page.</small></div>
                        <div class="field-readonly"><label for="profile_edit_role">Role</label><input class="form-control" id="profile_edit_role" type="text" value="<?= e(ucfirst($profile['role'])) ?>" readonly aria-readonly="true"></div>
                        <div class="field-readonly"><label for="profile_edit_status">Account status</label><input class="form-control" id="profile_edit_status" type="text" value="<?= e(ucfirst($profile['account_status'])) ?>" readonly aria-readonly="true"></div>
                        <div class="field-readonly"><label for="profile_edit_created">Account created</label><input class="form-control" id="profile_edit_created" type="text" value="<?= e(date('M j, Y', strtotime($profile['created_at'] . ' UTC'))) ?>" readonly aria-readonly="true"></div>
                    </div>
                    <?php endif; ?>
                    <div class="profile-card-actions">
                        <button type="button" class="btn btn-outline-navy" data-profile-cancel>Cancel</button>
                        <button type="submit" class="btn btn-navy" data-loading-label="Saving..."><i class="bi bi-check2" aria-hidden="true"></i> Save Changes</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</section>

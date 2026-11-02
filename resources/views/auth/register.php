<section class="auth-shell container-xl">
    <div class="auth-aside"><p class="eyebrow">Admission Office · Student portal</p><h1>Begin with<br><em>your account.</em></h1><p>Create a student account to save a draft, submit requirements, and follow application updates.</p><div class="aside-note"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Your account is private. Staff access is granted separately by an administrator.</span></div></div>
    <div class="auth-form-wrap">
        <p class="eyebrow">New applicant</p><h2>Create an account</h2><p class="form-intro">All fields are required unless marked optional.</p>
        <?php if (!empty($result)): ?><div class="alert alert-warning" role="alert"><?= e($result['message']) ?></div><?php endif; ?>
        <form method="post" action="/register" class="form-stack" autocomplete="on" data-password-checklist data-password-name-hint="<?= e((string) ($values['full_name'] ?? '')) ?>" data-password-email-hint="<?= e((string) ($values['email'] ?? '')) ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label class="form-label" for="full_name">Full name</label>
            <input class="form-control" id="full_name" name="full_name" maxlength="160" autocomplete="name" value="<?= e($values['full_name'] ?? '') ?>" required>
            <label class="form-label" for="email">Email address</label>
            <input class="form-control" id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= e($values['email'] ?? '') ?>" required>
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <!-- HTML maxlength counts UTF-16 code units; 128 permits 64 Unicode code points, with the server enforcing the 72-byte ceiling. -->
                <input class="form-control" id="password" name="password" type="password" minlength="12" maxlength="128" data-max-characters="64" autocomplete="new-password" aria-describedby="password-checklist password-server-note" data-password-new required>
                <button class="btn btn-outline-secondary" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <ul id="password-checklist" class="password-checklist" aria-live="polite">
                <li data-password-rule="length"><span data-rule-state>Not checked</span><span>At least 12 characters</span></li>
                <li data-password-rule="variety"><span data-rule-state>Not checked</span><span>Mix 3 of 4 character types, or use at least 16 characters</span></li>
                <li data-password-rule="personal"><span data-rule-state>Not checked</span><span>Does not contain your name or email</span></li>
                <li data-password-rule="match"><span data-rule-state>Not checked</span><span>Passwords match</span></li>
            </ul>
            <div class="password-strength" data-password-strength-panel data-state="empty"><div class="password-strength-meter" role="progressbar" aria-label="Preliminary password strength" aria-valuemin="0" aria-valuemax="3" aria-valuenow="0"><span data-password-strength-meter></span></div><p data-password-strength-status aria-live="polite">Strength: not checked</p></div>
            <small id="password-server-note" class="form-hint">Common-password validation is checked securely when you submit. Final password acceptance is checked by the server.</small>
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <div class="input-group">
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="128" data-max-characters="64" autocomplete="new-password" aria-describedby="password-checklist" data-password-confirmation required>
                <button class="btn btn-outline-secondary" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <label class="consent-check"><input type="checkbox" name="privacy_consent" value="1" required><span>I understand that the Admission Office will use my information to manage my admission application.</span></label>
            <button class="btn btn-amber btn-lg w-100" type="submit">Create student account <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
        </form>
        <p class="form-footnote">Already registered? <a href="/login">Sign in</a></p>
    </div>
</section>
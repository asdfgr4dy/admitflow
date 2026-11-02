<section class="auth-shell container-xl">
    <div class="auth-aside"><p class="eyebrow">Account recovery</p><h1>Choose a new<br><em>password.</em></h1><p>Reset links are time-limited and can only be used once.</p></div>
    <div class="auth-form-wrap">
        <p class="eyebrow">Secure recovery</p><h2>Set a new password</h2>
        <?php if (!empty($result)): ?><div class="alert alert-warning" role="alert"><?= e($result['message']) ?></div><?php endif; ?>
        <form method="post" action="/reset-password" class="form-stack" data-password-checklist data-password-name-hint="<?= e((string) ($passwordContext['name'] ?? '')) ?>" data-password-email-hint="<?= e((string) ($passwordContext['email'] ?? '')) ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
            <label class="form-label" for="password">New password</label>
            <!-- HTML maxlength counts UTF-16 code units; 128 permits 64 Unicode code points, with the server enforcing the 72-byte ceiling. -->
            <div class="input-group">
                <input class="form-control" id="password" name="password" type="password" minlength="12" maxlength="128" data-max-characters="64" autocomplete="new-password" aria-describedby="password-checklist password-server-note" data-password-new required>
                <button class="btn btn-outline-secondary" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <ul id="password-checklist" class="password-checklist" aria-live="polite">
                <li data-password-rule="length"><span data-rule-state>Not checked</span><span>At least 12 characters</span></li>
                <li data-password-rule="variety"><span data-rule-state>Not checked</span><span>Mix 3 of 4 character types, or use at least 16 characters</span></li>
                <li data-password-rule="personal"><span data-rule-state>Not checked</span><span>Does not contain your name or email</span></li>
                <li data-password-rule="match"><span data-rule-state>Not checked</span><span>Passwords match</span></li>
            </ul>
            <div class="password-strength" data-password-strength-panel data-state="empty">
                <div class="password-strength-meter" role="progressbar" aria-label="Preliminary password strength" aria-valuemin="0" aria-valuemax="3" aria-valuenow="0"><span data-password-strength-meter></span></div>
                <p data-password-strength-status aria-live="polite">Strength: not checked</p>
            </div>
            <small id="password-server-note" class="form-hint">Common-password validation is checked securely when you submit. Final password acceptance is checked by the server.</small>
            <label class="form-label" for="password_confirmation">Confirm new password</label>
            <div class="input-group">
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="128" data-max-characters="64" autocomplete="new-password" aria-describedby="password-checklist" data-password-confirmation required>
                <button class="btn btn-outline-secondary" type="button" data-password-toggle aria-label="Show password confirmation" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <button class="btn btn-amber btn-lg w-100 mt-3" type="submit">Change password <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
        </form>
        <p class="form-footnote"><a href="/login">Return to sign in</a></p>
    </div>
</section>
<section class="settings-page container-xl account-settings-page">
    <div class="settings-page-heading"><div><p class="eyebrow">Account workspace</p><h1>Settings.</h1><p>Manage your profile, display, and account security.</p></div></div>
    <div class="settings-link-grid">
        <a class="settings-link-card" href="/settings/profile"><span class="settings-link-icon"><i class="bi bi-person" aria-hidden="true"></i></span><span><strong>My Profile</strong><small>Review and update your display name and account details.</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        <a class="settings-link-card" href="/account/password"><span class="settings-link-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span><strong>Security</strong><small>Change your password and review recent sign-in attempts.</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        <a class="settings-link-card" href="/notifications"><span class="settings-link-icon"><i class="bi bi-bell" aria-hidden="true"></i></span><span><strong>Notifications</strong><small>Review in-app updates from admissions workflows.</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
        <section class="settings-admin-group"><div class="section-heading"><div><p class="eyebrow">Administrator</p><h2>System Settings.</h2><p>Open the existing administration tools. Each destination retains its own access checks.</p></div><a href="/settings/system" class="btn btn-outline-navy">System Settings <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
            <div class="settings-admin-links"><a href="/staff/settings#program-settings"><i class="bi bi-mortarboard" aria-hidden="true"></i> Academic Settings</a><a href="/staff/settings#requirements"><i class="bi bi-card-checklist" aria-hidden="true"></i> Application Requirements</a><a href="/staff/users"><i class="bi bi-people" aria-hidden="true"></i> User Administration</a><a href="/staff/audit"><i class="bi bi-journal-text" aria-hidden="true"></i> Audit Log</a><a href="/staff/backups"><i class="bi bi-archive" aria-hidden="true"></i> Backups and Restore</a></div>
        </section>
    <?php endif; ?>
</section>

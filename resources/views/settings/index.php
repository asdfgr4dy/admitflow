<section class="settings-page container-xl account-settings-page">
    <div class="settings-page-heading"><div><p class="eyebrow">Account workspace</p><h1>Settings.</h1><p>Manage your profile, display, and account security.</p></div></div>
    <div class="settings-link-grid">
        <a class="settings-link-card" href="/settings/profile"><span class="settings-link-icon"><i class="bi bi-person" aria-hidden="true"></i></span><span><strong>My Profile</strong><small>Review and update your display name and account details.</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        <a class="settings-link-card" href="/account/password"><span class="settings-link-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span><strong>Security</strong><small>Change your password and review recent sign-in attempts.</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        <a class="settings-link-card" href="/notifications"><span class="settings-link-icon"><i class="bi bi-bell" aria-hidden="true"></i></span><span><strong>Notifications</strong><small>Review in-app updates from admissions workflows.</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
        <section class="settings-admin-group"><div class="section-heading"><div><p class="eyebrow">Administrator</p><h2>System Settings.</h2><p>Open the existing administration tools. Each destination retains its own access checks.</p></div><a href="/settings/system" class="btn btn-outline-navy">System Settings <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
            <div class="settings-admin-links"><a href="/staff/academic/programs"><i class="bi bi-mortarboard" aria-hidden="true"></i> Programs</a><a href="/staff/academic/years"><i class="bi bi-calendar3" aria-hidden="true"></i> Academic Years</a><a href="/staff/academic/requirements"><i class="bi bi-card-checklist" aria-hidden="true"></i> Requirements</a><a href="/staff/users/administrators"><i class="bi bi-person-gear" aria-hidden="true"></i> Administrator Accounts</a><a href="/staff/users/staff"><i class="bi bi-people" aria-hidden="true"></i> Staff Accounts</a><a href="/staff/users/students"><i class="bi bi-person-vcard" aria-hidden="true"></i> Student Accounts</a><a href="/staff/audit"><i class="bi bi-journal-text" aria-hidden="true"></i> Audit Log</a></div>
        </section>
    <?php endif; ?>
</section>

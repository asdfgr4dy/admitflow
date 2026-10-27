<section class="staff-wrap container-xl admin-users-page">
    <div class="staff-heading"><div><p class="eyebrow">Administrator · Access control</p><h1>User administration.</h1><p>Staff accounts are created here. Public registration can only create student accounts.</p></div><a href="/admin" class="btn btn-outline-navy">Back to dashboard</a></div>
    <section class="settings-section">
        <div class="section-heading"><div><p class="eyebrow">Authorized staff</p><h2>Staff accounts.</h2></div><button class="btn btn-amber" type="button" data-bs-toggle="modal" data-bs-target="#createStaffAccountModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Create staff account</button></div>
        <p class="form-hint">No password is shown or emailed. Share the time-limited setup link securely so the staff member can choose a password and then sign in.</p>
        <?php if (is_string($staffSetupLink ?? null)): ?>
            <div class="staff-setup-link" role="status"><strong>One-time password setup link</strong><p>This link expires in 30 minutes and can be used once. Share it only with the intended account holder.</p><a href="<?= e($staffSetupLink) ?>"><?= e($staffSetupLink) ?></a></div>
        <?php endif; ?>
    </section>

    <form method="get" action="/staff/users" class="filter-bar user-filter-bar" data-auto-filter-form data-page-params="page_admin,page_reviewer,page_student" data-filter-target="admin-accounts">
        <input type="hidden" name="per_page" value="<?= (int) ($paginationByRole[$roleFilter !== '' ? $roleFilter : 'admin']['page_size'] ?? 20) ?>">
        <div class="filter-field search-field"><label for="user_q">Search name or email</label><input class="form-control" id="user_q" name="q" value="<?= e($userFilters['q']) ?>" maxlength="160" data-filter-search></div>
        <div class="filter-field"><label for="user_account_status">Account status</label><select class="form-select" id="user_account_status" name="account_status" data-filter-change><option value="">All statuses</option><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked'] as $statusValue => $statusLabel): ?><option value="<?= e($statusValue) ?>" <?= $userFilters['account_status'] === $statusValue ? 'selected' : '' ?>><?= e($statusLabel) ?></option><?php endforeach; ?></select></div>
        <div class="filter-actions"><button class="btn btn-outline-navy" type="button" aria-expanded="<?= $userFilters['role'] !== '' || $userFilters['start_date'] !== '' || $userFilters['end_date'] !== '' ? 'true' : 'false' ?>" aria-controls="userAdvancedFilters" data-advanced-toggle><i class="bi bi-sliders" aria-hidden="true"></i> More Filters <span class="application-advanced-count"><?= count(array_filter([$userFilters['role'], $userFilters['start_date'], $userFilters['end_date']])) ?: '' ?></span></button></div>
        <section class="application-advanced-panel" id="userAdvancedFilters" aria-labelledby="userAdvancedHeading" data-advanced-panel <?= $userFilters['role'] !== '' || $userFilters['start_date'] !== '' || $userFilters['end_date'] !== '' ? '' : 'hidden' ?>><div class="application-advanced-heading"><h2 id="userAdvancedHeading">Advanced Filters</h2><button type="button" class="btn btn-sm btn-outline-navy" aria-label="Close advanced filters" data-advanced-close>Close</button></div><div class="application-advanced-grid"><div class="filter-field"><label for="user_role">Role</label><select class="form-select" id="user_role" name="role" data-filter-change><option value="">All roles</option><?php foreach (['admin' => 'Administrator', 'reviewer' => 'Staff Reviewer', 'student' => 'Student'] as $roleValue => $roleLabel): ?><option value="<?= e($roleValue) ?>" <?= $userFilters['role'] === $roleValue ? 'selected' : '' ?>><?= e($roleLabel) ?></option><?php endforeach; ?></select></div><div class="filter-field"><label for="user_date_preset">Creation date</label><select class="form-select" id="user_date_preset" name="date_range" data-date-preset><option value="">Any date</option><option value="last7" <?= $userFilters['date_range'] === 'last7' ? 'selected' : '' ?>>Last 7 days</option><option value="last30" <?= $userFilters['date_range'] === 'last30' ? 'selected' : '' ?>>Last 30 days</option><option value="custom" <?= $userFilters['start_date'] !== '' || $userFilters['end_date'] !== '' ? 'selected' : '' ?>>Custom range</option></select><div class="application-custom-dates" data-custom-range <?= $userFilters['date_range'] === 'custom' || $userFilters['start_date'] !== '' || $userFilters['end_date'] !== '' ? '' : 'hidden' ?>><div><label class="visually-hidden" for="user_start_date">Start date</label><input class="form-control" id="user_start_date" name="start_date" type="date" value="<?= e($userFilters['start_date']) ?>" data-range-start></div><span>to</span><div><label class="visually-hidden" for="user_end_date">End date</label><input class="form-control" id="user_end_date" name="end_date" type="date" value="<?= e($userFilters['end_date']) ?>" data-range-end></div><small>Account creation date · Asia/Manila</small></div></div></div></section>
        <?php
        $activeFilterChips = [];
        if ($userFilters['q'] !== '') $activeFilterChips[] = ['key' => 'q', 'label' => 'Search: ' . $userFilters['q']];
        if ($userFilters['account_status'] !== '') $activeFilterChips[] = ['key' => 'account_status', 'label' => 'Account status: ' . ucfirst($userFilters['account_status'])];
        if ($userFilters['role'] !== '') $activeFilterChips[] = ['key' => 'role', 'label' => 'Role: ' . (['admin' => 'Administrator', 'reviewer' => 'Staff Reviewer', 'student' => 'Student'][$userFilters['role']] ?? $userFilters['role'])];
        if ($userFilters['start_date'] !== '' || $userFilters['end_date'] !== '') $activeFilterChips[] = ['key' => 'date_range', 'clear_keys' => ['date_range', 'start_date', 'end_date'], 'label' => 'Creation date: ' . ($userFilters['start_date'] ?: 'Beginning') . ' to ' . ($userFilters['end_date'] ?: 'Today')];
        $filterResetUrl = '/staff/users?per_page=' . (int) ($paginationByRole[$roleFilter !== '' ? $roleFilter : 'admin']['page_size'] ?? 20);
        require dirname(__DIR__) . '/partials/active-filter-chips.php';
        ?>
        <span class="visually-hidden" role="status" aria-live="polite" data-filter-status></span>
    </form>

    <nav class="user-category-nav" aria-label="Account categories">
        <?php foreach ($userCategories as $category): ?><a href="#<?= e($category['id']) ?>"><?= e($category['title']) ?> <span><?= number_format($accountTotals[$category['key']]) ?></span></a><?php endforeach; ?>
    </nav>

    <?php foreach ($userCategories as $category): ?>
        <?php $categoryUsers = $accountsByRole[$category['key']]; ?>
        <section class="staff-section user-category-section" id="<?= e($category['id']) ?>" data-pagination-target>
            <div class="section-heading"><div><p class="eyebrow">Account category</p><h2><?= e($category['title']) ?></h2></div><span class="section-count"><?= number_format($accountTotals[$category['key']]) ?> accounts</span></div>
            <?php if ($categoryUsers === []): ?>
                <p class="empty-note">No <?= e(strtolower($category['title'])) ?> found.</p>
            <?php else: ?>
                <div class="table-responsive staff-table-wrap"><table class="table staff-table">
                    <thead><tr><th scope="col">Name / email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign in</th><th scope="col">Created</th><th scope="col">Save access</th></tr></thead>
                    <tbody>
                    <?php foreach ($categoryUsers as $account): $formId = 'user-form-' . (int) $account['id']; ?>
                        <tr>
                            <td><form id="<?= e($formId) ?>" method="post" action="/staff/user/save"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>"></form><strong><?= e($account['full_name']) ?></strong><small><?= e($account['email']) ?></small></td>
                            <td><label class="visually-hidden" for="role_<?= (int) $account['id'] ?>">Role for <?= e($account['full_name']) ?></label><select form="<?= e($formId) ?>" class="form-select" id="role_<?= (int) $account['id'] ?>" name="role"><option value="student" <?= $account['role'] === 'student' ? 'selected' : '' ?>>Student</option><option value="reviewer" <?= $account['role'] === 'reviewer' ? 'selected' : '' ?>>Reviewer</option><option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option></select></td>
                            <td><label class="visually-hidden" for="status_<?= (int) $account['id'] ?>">Status for <?= e($account['full_name']) ?></label><select form="<?= e($formId) ?>" class="form-select" id="status_<?= (int) $account['id'] ?>" name="account_status"><option value="active" <?= $account['account_status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $account['account_status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option><option value="locked" <?= $account['account_status'] === 'locked' ? 'selected' : '' ?>>Locked</option></select></td>
                            <td><?= e($account['last_login_at'] ? date('M j, Y', strtotime($account['last_login_at'] . ' UTC')) : 'Never') ?></td>
                            <td><?= e(date('M j, Y', strtotime($account['created_at'] . ' UTC'))) ?></td>
                            <td><button form="<?= e($formId) ?>" class="btn btn-sm btn-outline-navy" type="submit" data-confirm="Apply this role and account-status change?">Save</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php $pagination = $paginationByRole[$category['key']] + ['base_url' => '/staff/users', 'query' => $userFilters + ['per_page' => $paginationByRole[$category['key']]['page_size']], 'fragment' => $category['id']]; require dirname(__DIR__) . '/partials/pagination.php'; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <div class="alert alert-info">Account changes are audit logged. Deactivation invalidates existing sessions on their next request. The system prevents removing the last active administrator account.</div>
</section>

<div class="modal fade admitflow-form-modal" id="createStaffAccountModal" tabindex="-1" aria-labelledby="createStaffAccountTitle" aria-hidden="true" data-create-form-modal data-open="<?= !empty($staffCreateFormOpen) ? 'true' : 'false' ?>">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">
        <form method="post" action="/staff/user/save" data-submit-once>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="modal-header"><div><p class="eyebrow">Administrator · Access control</p><h2 class="modal-title" id="createStaffAccountTitle">Create a staff account</h2><p>No password is created or sent. The staff member chooses one using a time-limited setup link.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close create staff account form"></button></div>
            <div class="modal-body"><div class="mb-3"><label class="form-label" for="staff_name">Full name</label><input class="form-control" id="staff_name" name="full_name" value="<?= e($staffCreateFormValues['full_name'] ?? '') ?>" maxlength="160" required></div><div class="mb-3"><label class="form-label" for="staff_email">Email address</label><input class="form-control" id="staff_email" name="email" type="email" value="<?= e($staffCreateFormValues['email'] ?? '') ?>" maxlength="254" required></div><div><label class="form-label" for="staff_role">Role</label><select class="form-select" id="staff_role" name="role" required><option value="reviewer" <?= ($staffCreateFormValues['role'] ?? 'reviewer') === 'reviewer' ? 'selected' : '' ?>>Admissions reviewer</option><option value="admin" <?= ($staffCreateFormValues['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option></select></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-navy" data-bs-dismiss="modal">Cancel</button><button class="btn btn-navy" type="submit" data-loading-label="Creating account...">Create account</button></div>
        </form>
    </div></div>
</div>
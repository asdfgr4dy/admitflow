<?php
$stateLabels = ['draft' => 'Drafts', 'scheduled' => 'Scheduled', 'published' => 'Published', 'expired' => 'Expired'];
$selectedState = $activeState ?? '';
$activeAudience = $activeAudience ?? '';
$activeSearch = $activeSearch ?? '';
$activeStartDate = $activeStartDate ?? '';
$activeEndDate = $activeEndDate ?? '';
$activeDatePreset = in_array(($activeDatePreset ?? ''), ['last7', 'last30', 'custom'], true) ? $activeDatePreset : ($activeStartDate !== '' || $activeEndDate !== '' ? 'custom' : '');
$formValues = $formValues ?? [];
$formErrors = $formErrors ?? [];
$stateCounts = $stateCounts ?? array_fill_keys(array_keys($stateLabels), 0);
$allCount = array_sum($stateCounts);
$toLocalInput = static function (?string $value): string {
    if ($value === null || $value === '') return '';
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d\\TH:i');
};
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') return 'Not scheduled';
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A') . ' Asia/Manila';
};
$tabUrl = static function (string $state) use ($activeAudience, $activeSearch, $activeStartDate, $activeEndDate, $activeDatePreset): string {
    return '/staff/announcements?' . http_build_query(array_filter(['state' => $state, 'audience' => $activeAudience, 'q' => $activeSearch, 'start_date' => $activeStartDate, 'end_date' => $activeEndDate, 'date_range' => $activeDatePreset], static fn (string $value): bool => $value !== ''));
};
?>
<section class="staff-wrap container-xl announcement-center">
    <header class="announcement-center-heading">
        <div><p class="eyebrow">Admission Office · Communications</p><h1>Announcement Center</h1><p>Publish timely updates to the audiences who need them.</p></div>
        <button class="btn btn-amber" type="button" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create Announcement</button>
    </header>

    <nav class="announcement-state-nav" aria-label="Announcement status filters">
        <a href="<?= e($tabUrl('')) ?>" <?= $selectedState === '' ? 'aria-current="page"' : '' ?>>All <span><?= number_format($allCount) ?></span></a>
        <?php foreach ($stateLabels as $key => $label): ?><a href="<?= e($tabUrl($key)) ?>" <?= $selectedState === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?> <span><?= number_format((int) ($stateCounts[$key] ?? 0)) ?></span></a><?php endforeach; ?>
    </nav>

    <form method="get" action="/staff/announcements" class="announcement-filter-bar" data-auto-filter-form data-filter-target="announcement-list">
        <input type="hidden" name="state" value="<?= e($selectedState) ?>">
        <input type="hidden" name="per_page" value="<?= (int) ($pagination['page_size'] ?? 20) ?>">
        <div class="announcement-search-field"><label for="announcement_search">Search announcements</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" type="search" id="announcement_search" name="q" value="<?= e($activeSearch) ?>" maxlength="180" placeholder="Title or message" data-filter-search></div></div>
        <div><label for="announcement_audience_filter">Target audience</label><select class="form-select" id="announcement_audience_filter" name="audience" data-filter-change><option value="">All audiences</option><?php foreach (['public' => 'Public', 'students' => 'Students', 'staff' => 'Staff', 'all' => 'Everyone'] as $audienceKey => $audienceLabel): ?><option value="<?= e($audienceKey) ?>" <?= $activeAudience === $audienceKey ? 'selected' : '' ?>><?= e($audienceLabel) ?></option><?php endforeach; ?></select></div>
        <div class="announcement-filter-actions"><button class="btn btn-outline-navy" type="button" aria-expanded="<?= $activeDatePreset !== '' ? 'true' : 'false' ?>" aria-controls="announcementAdvancedFilters" data-advanced-toggle><i class="bi bi-sliders" aria-hidden="true"></i> More Filters <span class="application-advanced-count"><?= $activeDatePreset !== '' ? '1' : '' ?></span></button></div>
        <section class="application-advanced-panel" id="announcementAdvancedFilters" aria-labelledby="announcementAdvancedHeading" data-advanced-panel <?= $activeDatePreset !== '' ? '' : 'hidden' ?>><div class="application-advanced-heading"><h2 id="announcementAdvancedHeading">Advanced Filters</h2><button class="btn btn-sm btn-outline-navy" type="button" aria-label="Close advanced filters" data-advanced-close>Close</button></div><div class="filter-field"><label for="announcement_date_preset">Publication / scheduled date</label><select class="form-select" id="announcement_date_preset" name="date_range" data-date-preset><option value="">Any date</option><option value="last7" <?= $activeDatePreset === 'last7' ? 'selected' : '' ?>>Last 7 days</option><option value="last30" <?= $activeDatePreset === 'last30' ? 'selected' : '' ?>>Last 30 days</option><option value="custom" <?= $activeDatePreset === 'custom' ? 'selected' : '' ?>>Custom range</option></select><div class="application-custom-dates" data-custom-range <?= $activeDatePreset === 'custom' ? '' : 'hidden' ?>><div><label class="visually-hidden" for="announcement_start_date">Start date</label><input class="form-control" id="announcement_start_date" name="start_date" type="date" value="<?= e($activeStartDate) ?>" data-range-start></div><span>to</span><div><label class="visually-hidden" for="announcement_end_date">End date</label><input class="form-control" id="announcement_end_date" name="end_date" type="date" value="<?= e($activeEndDate) ?>" data-range-end></div><small>Uses scheduled start date, or creation date when published immediately · Asia/Manila</small></div></div></section>
        <?php
        $activeFilterChips = [];
        if ($selectedState !== '') $activeFilterChips[] = ['key' => 'state', 'label' => 'Status: ' . ($stateLabels[$selectedState] ?? ucfirst($selectedState))];
        if ($activeAudience !== '') $activeFilterChips[] = ['key' => 'audience', 'label' => 'Audience: ' . (['public' => 'Public', 'students' => 'Students', 'staff' => 'Staff', 'all' => 'Everyone'][$activeAudience] ?? ucfirst($activeAudience))];
        if ($activeSearch !== '') $activeFilterChips[] = ['key' => 'q', 'label' => 'Search: ' . $activeSearch];
        if ($activeDatePreset !== '') $activeFilterChips[] = ['key' => 'date_range', 'clear_keys' => ['date_range', 'start_date', 'end_date'], 'label' => 'Publication date: ' . ['last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'custom' => trim($activeStartDate . ' to ' . $activeEndDate)][$activeDatePreset]];
        $filterResetUrl = '/staff/announcements?per_page=' . (int) ($pagination['page_size'] ?? 20) . '#announcement-list';
        require dirname(__DIR__) . '/partials/active-filter-chips.php';
        ?>
        <span class="visually-hidden" role="status" aria-live="polite" data-filter-status></span>
    </form>

    <section class="staff-section announcement-list" id="announcement-list" data-pagination-target aria-labelledby="announcement-list-heading">
        <div class="section-heading"><div><p class="eyebrow">Announcement records</p><h2 id="announcement-list-heading"><?= $selectedState === '' ? 'All announcements' : e($stateLabels[$selectedState] ?? 'Announcements') ?></h2></div><span class="section-count"><?= number_format($pagination['total']) ?> matching</span></div>
        <?php if ($announcements === []): ?>
            <div class="announcement-empty-state"><i class="bi bi-megaphone" aria-hidden="true"></i><h3>No announcements found</h3><p>Try a different search or clear your filters.</p></div>
        <?php else: ?>
            <?php foreach ($announcements as $announcement): ?>
                <?php $editId = 'announcement-edit-' . (int) $announcement['id']; ?>
                <article class="announcement-management-row">
                    <div class="announcement-management-summary"><div><p class="eyebrow"><i class="bi bi-people" aria-hidden="true"></i> <?= e(['public' => 'Public', 'students' => 'Students', 'staff' => 'Staff', 'all' => 'Everyone'][$announcement['audience']] ?? ucfirst($announcement['audience'])) ?></p><h3><?= e($announcement['title']) ?></h3></div><span class="announcement-state-badge state-<?= e($announcement['state']) ?>"><?= e($stateLabels[$announcement['state']] ?? ucfirst($announcement['state'])) ?></span></div>
                    <p class="announcement-card-preview"><?= e(mb_strimwidth($announcement['body'], 0, 260, '...')) ?></p>
                    <dl class="announcement-management-meta"><div><dt><?= $announcement['state'] === 'scheduled' ? 'Scheduled for' : 'Publication' ?></dt><dd><?= e($announcement['starts_at'] ? $formatDate($announcement['starts_at']) : ($announcement['is_published'] ? 'Published immediately' : 'Not published')) ?></dd></div><div><dt>Ends</dt><dd><?= e($formatDate($announcement['ends_at'])) ?></dd></div><div><dt>Created by</dt><dd><?= e($announcement['creator_name'] ?: 'Administrator') ?></dd></div><div><dt>Last updated</dt><dd><?= e($formatDate($announcement['updated_at'])) ?></dd></div></dl>
                    <div class="announcement-management-actions"><?php if ($announcement['state'] === 'published'): ?><a class="btn btn-sm btn-outline-navy" href="<?= e('/announcement/' . (int) $announcement['id']) ?>"><i class="bi bi-eye" aria-hidden="true"></i> Preview</a><?php endif; ?><a class="btn btn-sm btn-outline-navy" href="#<?= e($editId) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a><form method="post" action="/staff/announcement/delete"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Delete this announcement? This cannot be undone."><i class="bi bi-trash" aria-hidden="true"></i> Delete</button></form></div>
                    <details class="announcement-edit-details" id="<?= e($editId) ?>"><summary>Edit announcement</summary><form method="post" action="/staff/announcement/save" class="announcement-editor announcement-record" data-submit-once><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>"><div><label for="announcement_title_<?= (int) $announcement['id'] ?>">Title</label><input class="form-control" id="announcement_title_<?= (int) $announcement['id'] ?>" name="title" value="<?= e($announcement['title']) ?>" maxlength="180" required></div><div><label for="announcement_audience_<?= (int) $announcement['id'] ?>">Audience</label><select class="form-select" id="announcement_audience_<?= (int) $announcement['id'] ?>" name="audience"><?php foreach (['public', 'students', 'staff', 'all'] as $audience): ?><option value="<?= e($audience) ?>" <?= $announcement['audience'] === $audience ? 'selected' : '' ?>><?= e(ucfirst($audience)) ?></option><?php endforeach; ?></select></div><div class="field-wide"><label for="announcement_body_<?= (int) $announcement['id'] ?>">Message</label><textarea class="form-control" id="announcement_body_<?= (int) $announcement['id'] ?>" name="body" rows="4" maxlength="10000" required><?= e($announcement['body']) ?></textarea></div><div><label for="announcement_start_<?= (int) $announcement['id'] ?>">Start time (Asia/Manila)</label><input class="form-control" id="announcement_start_<?= (int) $announcement['id'] ?>" name="starts_at" type="datetime-local" value="<?= e($toLocalInput($announcement['starts_at'])) ?>"></div><div><label for="announcement_end_<?= (int) $announcement['id'] ?>">End time (Asia/Manila)</label><input class="form-control" id="announcement_end_<?= (int) $announcement['id'] ?>" name="ends_at" type="datetime-local" value="<?= e($toLocalInput($announcement['ends_at'])) ?>"></div><label class="settings-toggle"><input type="checkbox" name="is_published" value="1" <?= $announcement['is_published'] ? 'checked' : '' ?>> Published</label><button class="btn btn-navy" type="submit">Save changes</button></form></details>
                </article>
            <?php endforeach; ?>
            <?php $pagination = $pagination + ['base_url' => '/staff/announcements', 'query' => array_filter(['state' => $selectedState, 'audience' => $activeAudience, 'q' => $activeSearch], static fn (string $value): bool => $value !== ''), 'target' => 'announcement-list']; require dirname(__DIR__) . '/partials/pagination.php'; ?>
        <?php endif; ?>
    </section>
</section>

<div class="modal fade announcement-create-modal" id="createAnnouncementModal" tabindex="-1" aria-labelledby="createAnnouncementTitle" aria-describedby="createAnnouncementHelp" aria-hidden="true" data-announcement-modal data-open="<?= $formValues !== [] ? 'true' : 'false' ?>">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">
        <form method="post" action="/staff/announcement/save" data-submit-once>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_state" value="<?= e($selectedState) ?>"><input type="hidden" name="return_audience" value="<?= e($activeAudience) ?>"><input type="hidden" name="return_q" value="<?= e($activeSearch) ?>">
            <div class="modal-header"><div><p class="eyebrow">Admission Office · Communications</p><h2 class="modal-title" id="createAnnouncementTitle">Create Announcement</h2><p id="createAnnouncementHelp">Compose a notice for the selected audience.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close create announcement form"></button></div>
            <div class="modal-body">
                <?php if (!empty($formErrors['form'])): ?><div class="alert alert-danger" role="alert"><?= e($formErrors['form']) ?></div><?php endif; ?>
                <section class="announcement-form-section" aria-labelledby="announcementDetailsHeading"><h3 id="announcementDetailsHeading">Announcement details</h3>
                    <div class="mb-3"><label class="form-label" for="new_announcement_title">Title</label><input class="form-control <?= isset($formErrors['title']) ? 'is-invalid' : '' ?>" id="new_announcement_title" name="title" value="<?= e($formValues['title'] ?? '') ?>" maxlength="180" required aria-describedby="new_announcement_title_error"><?php if (isset($formErrors['title'])): ?><div class="invalid-feedback" id="new_announcement_title_error"><?= e($formErrors['title']) ?></div><?php endif; ?></div>
                    <div class="mb-3"><label class="form-label" for="new_announcement_body">Message</label><textarea class="form-control announcement-message-input <?= isset($formErrors['body']) ? 'is-invalid' : '' ?>" id="new_announcement_body" name="body" rows="7" maxlength="10000" placeholder="Write the update your audience needs to know..." required aria-describedby="new_announcement_body_help new_announcement_body_error"><?= e($formValues['body'] ?? '') ?></textarea><div class="form-text" id="new_announcement_body_help">Plain text message, up to 10,000 characters.</div><?php if (isset($formErrors['body'])): ?><div class="invalid-feedback" id="new_announcement_body_error"><?= e($formErrors['body']) ?></div><?php endif; ?></div>
                    <div><label class="form-label" for="new_announcement_audience">Target audience</label><select class="form-select <?= isset($formErrors['audience']) ? 'is-invalid' : '' ?>" id="new_announcement_audience" name="audience" required aria-describedby="new_announcement_audience_error"><?php foreach (['public' => 'Public', 'students' => 'Students', 'staff' => 'Staff', 'all' => 'Everyone'] as $key => $label): ?><option value="<?= e($key) ?>" <?= ($formValues['audience'] ?? 'public') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?php if (isset($formErrors['audience'])): ?><div class="invalid-feedback" id="new_announcement_audience_error"><?= e($formErrors['audience']) ?></div><?php endif; ?></div>
                </section>
                <section class="announcement-form-section" aria-labelledby="announcementPublicationHeading"><h3 id="announcementPublicationHeading">Publication settings</h3>
                    <fieldset class="announcement-publish-options"><legend class="visually-hidden">When should this announcement be published?</legend><label><input type="radio" name="publication_mode" value="publish" <?= ($formValues['publication_mode'] ?? 'publish') === 'publish' ? 'checked' : '' ?> data-announcement-mode> <span><strong>Publish now</strong><small>Visible immediately after the announcement is saved.</small></span></label><label><input type="radio" name="publication_mode" value="schedule" <?= ($formValues['publication_mode'] ?? '') === 'schedule' ? 'checked' : '' ?> data-announcement-mode> <span><strong>Schedule for later</strong><small>Choose a future date and time in Asia/Manila.</small></span></label></fieldset>
                    <?php if (isset($formErrors['publication_mode'])): ?><p class="text-danger small" role="alert"><?= e($formErrors['publication_mode']) ?></p><?php endif; ?>
                    <div class="announcement-schedule-fields" data-schedule-fields <?= ($formValues['publication_mode'] ?? '') === 'schedule' ? '' : 'hidden' ?>><label class="form-label" for="new_announcement_start">Scheduled date and time <span class="text-danger">*</span></label><input class="form-control <?= isset($formErrors['starts_at']) ? 'is-invalid' : '' ?>" id="new_announcement_start" name="starts_at" type="datetime-local" value="<?= e($formValues['starts_at'] ?? '') ?>" aria-describedby="new_announcement_start_help new_announcement_start_error" data-schedule-date><div class="form-text" id="new_announcement_start_help">Times are interpreted as Asia/Manila time.</div><?php if (isset($formErrors['starts_at'])): ?><div class="invalid-feedback d-block" id="new_announcement_start_error"><?= e($formErrors['starts_at']) ?></div><?php endif; ?></div>
                </section>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-navy" data-bs-dismiss="modal">Cancel</button><button class="btn btn-navy" type="submit" data-announcement-submit data-publish-label="Publish Now" data-schedule-label="Schedule Announcement"><i class="bi bi-megaphone" aria-hidden="true"></i> <span data-announcement-submit-label><?= ($formValues['publication_mode'] ?? 'publish') === 'schedule' ? 'Schedule Announcement' : 'Publish Now' ?></span></button></div>
        </form>
    </div></div>
</div>
<?php
$backPath = match ($user['role'] ?? '') {
    'student' => '/portal',
    'admin' => '/admin',
    'reviewer' => '/staff',
    default => '/',
};
$announcementFilters = $announcementFilters ?? ['q' => ''];
$announcementPageSize = (int) ($pagination['page_size'] ?? 20);
?>
<section class="announcement-reader container-xl">
    <div class="announcement-reader-heading"><div><p class="eyebrow">AdmitFlow · Admission Office</p><h1>Announcements.</h1><p>Updates relevant to your audience.</p></div><a class="btn btn-outline-navy" href="<?= e($backPath) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back</a></div>
    <form method="get" action="/announcements" class="filter-bar" data-auto-filter-form data-filter-target="announcement-results"><input type="hidden" name="per_page" value="<?= $announcementPageSize ?>"><div class="filter-field search-field"><label for="reader_announcement_search">Search announcements</label><input class="form-control" type="search" id="reader_announcement_search" name="q" value="<?= e($announcementFilters['q']) ?>" maxlength="180" placeholder="Title or message" data-filter-search></div><?php $activeFilterChips = $announcementFilters['q'] !== '' ? [['key' => 'q', 'label' => 'Search: ' . $announcementFilters['q']]] : []; $filterResetUrl = '/announcements?per_page=' . $announcementPageSize . '#announcement-results'; require dirname(__DIR__) . '/partials/active-filter-chips.php'; ?><span class="visually-hidden" role="status" aria-live="polite" data-filter-status></span></form>
    <?php if ($announcements === []): ?><p class="empty-note" id="announcement-results"><?= $announcementFilters['q'] !== '' ? 'No active announcements match this search.' : 'There are no active announcements for your audience right now.' ?></p><?php else: ?>
        <div class="announcement-reader-list" id="announcement-results" data-pagination-target>
            <?php foreach ($announcements as $announcement): ?>
                <article class="announcement-reader-item">
                    <p class="eyebrow"><i class="bi bi-megaphone" aria-hidden="true"></i> <?= e(ucfirst($announcement['audience'])) ?> · <?= e($announcement['starts_at'] ? date('M j, Y', strtotime($announcement['starts_at'] . ' UTC')) : 'Current notice') ?></p>
                    <h2><?= e($announcement['title']) ?></h2>
                    <p><?= e(mb_strimwidth($announcement['body'], 0, 240, '...')) ?></p>
                    <a class="btn btn-sm btn-outline-navy" href="<?= e('/announcement/' . (int) $announcement['id']) ?>">Read full announcement <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php $pagination = $pagination + ['base_url' => '/announcements', 'query' => $announcementFilters, 'target' => 'announcement-results']; require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</section>

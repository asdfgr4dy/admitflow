<?php
$activeFilterChips = is_array($activeFilterChips ?? null) ? $activeFilterChips : [];
$filterResetUrl = (string) ($filterResetUrl ?? '');
?>
<?php if ($activeFilterChips !== []): ?>
    <div class="shared-filter-chips" aria-label="Active filters" data-active-filters>
        <span class="shared-filter-chips-label">Active filters</span>
        <?php foreach ($activeFilterChips as $chip): ?>
            <span class="application-filter-chip"><span><?= e($chip['label']) ?></span><button type="button" aria-label="Remove <?= e($chip['label']) ?> filter" data-clear-filter="<?= e($chip['key']) ?>"<?= isset($chip['clear_keys']) ? ' data-clear-keys="' . e(implode(',', $chip['clear_keys'])) . '"' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button></span>
        <?php endforeach; ?>
        <?php if ($filterResetUrl !== ''): ?><span class="shared-filter-actions"><a href="<?= e($filterResetUrl) ?>" class="btn btn-link application-reset">Reset Filters</a></span><?php endif; ?>
    </div>
<?php endif; ?>
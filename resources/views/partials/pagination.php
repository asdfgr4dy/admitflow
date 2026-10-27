<?php
$pagination = $pagination ?? [];
$baseUrl = (string) ($pagination['base_url'] ?? '');
$query = array_merge(is_array($_GET ?? null) ? $_GET : [], is_array($pagination['query'] ?? null) ? $pagination['query'] : []);
$pageKey = (string) ($pagination['page_key'] ?? 'page');
$sizeKey = (string) ($pagination['size_key'] ?? 'per_page');
$target = (string) ($pagination['target'] ?? $pagination['fragment'] ?? '');
$currentPage = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);
$makePageUrl = static function (int $page) use ($baseUrl, $query, $pageKey, $sizeKey, $pagination, $target): string {
    $parameters = array_merge($query, [$sizeKey => (int) ($pagination['page_size'] ?? 20), $pageKey => $page]);
    return $baseUrl . '?' . http_build_query($parameters) . ($target !== '' ? '#' . rawurlencode($target) : '');
};
$visiblePages = array_unique(array_filter([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages - 1, $totalPages], static fn (int $page): bool => $page >= 1 && $page <= $totalPages));
sort($visiblePages);
?>
<nav class="pagination-bar" aria-label="Pagination">
    <p class="pagination-summary">Showing <?= number_format((int) ($pagination['from'] ?? 0)) ?>–<?= number_format((int) ($pagination['to'] ?? 0)) ?> of <?= number_format((int) ($pagination['total'] ?? 0)) ?> records</p>
    <div class="pagination-controls">
        <a class="btn btn-sm btn-outline-navy <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="<?= $currentPage <= 1 ? '#' : e($makePageUrl($currentPage - 1)) ?>" <?= $currentPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?> aria-label="Previous page"><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</a>
        <div class="pagination-pages">
            <?php $previousPage = 0; foreach ($visiblePages as $pageNumber): ?>
                <?php if ($previousPage > 0 && $pageNumber > $previousPage + 1): ?><span class="pagination-ellipsis" aria-hidden="true">...</span><?php endif; ?>
                <?php if ($pageNumber === $currentPage): ?><span class="pagination-current" aria-current="page"><?= $pageNumber ?></span><?php else: ?><a href="<?= e($makePageUrl($pageNumber)) ?>" aria-label="Page <?= $pageNumber ?>"><?= $pageNumber ?></a><?php endif; ?>
                <?php $previousPage = $pageNumber; ?>
            <?php endforeach; ?>
        </div>
        <a class="btn btn-sm btn-outline-navy <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="<?= $currentPage >= $totalPages ? '#' : e($makePageUrl($currentPage + 1)) ?>" <?= $currentPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?> aria-label="Next page">Next <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </div>
    <div class="pagination-page-size"><label for="<?= e($pageKey) ?>_page_size">Rows</label><select id="<?= e($pageKey) ?>_page_size" class="form-select form-select-sm" data-page-size-select="<?= e($sizeKey) ?>" data-page-param="<?= e($pageKey) ?>"><?php foreach ([10, 20, 50] as $size): ?><option value="<?= $size ?>" <?= $size === (int) ($pagination['page_size'] ?? 20) ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
</nav>

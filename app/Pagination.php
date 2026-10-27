<?php
declare(strict_types=1);

final class Pagination
{
    public static function make(int $total, array $input, string $pageKey = 'page', string $sizeKey = 'per_page', int $defaultSize = 20): array
    {
        $allowedSizes = [10, 20, 50];
        $rawSize = filter_var($input[$sizeKey] ?? $defaultSize, FILTER_VALIDATE_INT);
        $pageSize = is_int($rawSize) && in_array($rawSize, $allowedSizes, true) ? $rawSize : $defaultSize;
        $rawPage = filter_var($input[$pageKey] ?? 1, FILTER_VALIDATE_INT);
        $page = is_int($rawPage) && $rawPage > 0 ? $rawPage : 1;
        $totalPages = max(1, (int) ceil(max(0, $total) / $pageSize));
        $page = min($page, $totalPages);
        $from = $total === 0 ? 0 : (($page - 1) * $pageSize) + 1;
        $to = min($total, $page * $pageSize);

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'page_key' => $pageKey,
            'size_key' => $sizeKey,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => ($page - 1) * $pageSize,
            'from' => $from,
            'to' => $to,
        ];
    }
}

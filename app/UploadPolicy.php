<?php
declare(strict_types=1);

final class UploadPolicy
{
    private const MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public static function accepts(string $extension, string|false $detectedMime, int $size, array $allowedExtensions, int $maxBytes): bool
    {
        $extension = strtolower($extension);
        $allowedExtensions = array_map(static fn ($value): string => strtolower(trim((string) $value)), $allowedExtensions);
        return $size > 0
            && $size <= $maxBytes
            && in_array($extension, $allowedExtensions, true)
            && isset(self::MIME_BY_EXTENSION[$extension])
            && $detectedMime === self::MIME_BY_EXTENSION[$extension];
    }
}
<?php
declare(strict_types=1);

final class AvatarService
{
    public const MAX_BYTES = 2097152;

    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public static function inspectUpload(string $temporaryPath, string $originalName): ?array
    {
        if (!is_file($temporaryPath) || !is_readable($temporaryPath)) {
            return null;
        }
        $size = filesize($temporaryPath);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES || !isset(self::MIME_BY_EXTENSION[$extension])) {
            return null;
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $image = @getimagesize($temporaryPath);
        if ($mimeType !== self::MIME_BY_EXTENSION[$extension] || !is_array($image)) {
            return null;
        }
        $expectedType = $mimeType === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        $width = (int) ($image[0] ?? 0);
        $height = (int) ($image[1] ?? 0);
        if (($image[2] ?? null) !== $expectedType || $width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $width * $height > 12000000) {
            return null;
        }

        return ['extension' => $extension === 'jpeg' ? 'jpg' : $extension, 'mime_type' => $mimeType, 'size' => $size];
    }

    public static function store(int $userId, array $upload): void
    {
        if ($userId < 1 || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new DomainException('Choose a valid JPEG or PNG image to upload.');
        }
        $details = self::inspectUpload($upload['tmp_name'], (string) ($upload['name'] ?? ''));
        if ($details === null) {
            throw new DomainException('Use a JPEG or PNG image no larger than 2 MB, with dimensions no greater than 4096 by 4096.');
        }

        $root = self::storageRoot();
        $name = 'avatar-' . $userId . '-' . bin2hex(random_bytes(12)) . '.' . $details['extension'];
        $target = $root . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($upload['tmp_name'], $target) || !chmod($target, 0600)) {
            if (is_file($target)) unlink($target);
            throw new RuntimeException('The avatar could not be stored securely.');
        }

        $pointer = self::pointerPath($root, $userId);
        $previousName = self::currentName($root, $userId);
        if (file_put_contents($pointer, $name, LOCK_EX) !== strlen($name) || !chmod($pointer, 0600)) {
            if ($previousName !== null) {
                file_put_contents($pointer, $previousName, LOCK_EX);
                chmod($pointer, 0600);
            } elseif (is_file($pointer)) {
                unlink($pointer);
            }
            unlink($target);
            throw new RuntimeException('The avatar reference could not be saved securely.');
        }
        if ($previousName !== null && $previousName !== $name) {
            $previousPath = self::safeAvatarPath($root, $userId, $previousName);
            if ($previousPath !== null && is_file($previousPath)) unlink($previousPath);
        }
    }

    public static function remove(int $userId): void
    {
        if ($userId < 1) {
            throw new DomainException('Your account could not be verified. Sign in again and retry.');
        }
        $root = self::storageRoot(false);
        if ($root === null) return;
        $name = self::currentName($root, $userId);
        if ($name !== null) {
            $path = self::safeAvatarPath($root, $userId, $name);
            if ($path !== null && is_file($path)) unlink($path);
        }
        $pointer = self::pointerPath($root, $userId);
        if (is_file($pointer)) unlink($pointer);
    }

    public static function currentPath(int $userId): ?string
    {
        if ($userId < 1) return null;
        $root = self::storageRoot(false);
        if ($root === null) return null;
        $name = self::currentName($root, $userId);
        return $name === null ? null : self::safeAvatarPath($root, $userId, $name);
    }

    public static function currentMimeType(string $path): ?string
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        return in_array($mimeType, ['image/jpeg', 'image/png'], true) ? $mimeType : null;
    }

    private static function storageRoot(bool $create = true): ?string
    {
        $path = dirname(__DIR__) . '/storage/avatars';
        if (!is_dir($path) && $create && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Private avatar storage is not available.');
        }
        if (!is_dir($path)) return null;
        $realPath = realpath($path);
        $publicRoot = realpath(dirname(__DIR__) . '/public');
        if ($realPath === false || $publicRoot === false || str_starts_with($realPath, $publicRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Avatar storage must remain outside the public web root.');
        }
        return $realPath;
    }

    private static function pointerPath(string $root, int $userId): string
    {
        return $root . DIRECTORY_SEPARATOR . 'user-' . $userId . '.current';
    }

    private static function currentName(string $root, int $userId): ?string
    {
        $pointer = self::pointerPath($root, $userId);
        if (!is_file($pointer) || !is_readable($pointer)) return null;
        $name = trim((string) file_get_contents($pointer));
        return self::safeAvatarPath($root, $userId, $name) === null ? null : $name;
    }

    private static function safeAvatarPath(string $root, int $userId, string $name): ?string
    {
        if (!preg_match('/^avatar-' . $userId . '-[a-f0-9]{24}\.(?:jpg|png)$/', $name)) return null;
        $candidate = $root . DIRECTORY_SEPARATOR . $name;
        $realPath = realpath($candidate);
        return $realPath !== false && str_starts_with($realPath, $root . DIRECTORY_SEPARATOR) && is_file($realPath) ? $realPath : null;
    }
}
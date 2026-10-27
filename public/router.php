<?php
declare(strict_types=1);

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = realpath(__DIR__);
$publicFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($requestedPath, '/\\'));

if ($requestedPath !== '/' && $publicRoot !== false && $publicFile !== false && is_file($publicFile) && str_starts_with($publicFile, $publicRoot . DIRECTORY_SEPARATOR)) {
    return false;
}

require __DIR__ . '/index.php';

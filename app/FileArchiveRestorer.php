<?php
declare(strict_types=1);

final class FileArchiveRestorer
{
    private const MAX_ENTRIES = 100000;
    private const MAX_TOTAL_BYTES = 21474836480;

    public static function restore(
        string $archivePath,
        string $projectRoot,
        string $uploadsRoot,
        string $workingRoot,
        callable $createSafetyBackup,
        callable $finalizeRestore
    ): array {
        $archivePath = realpath($archivePath);
        $projectRoot = realpath($projectRoot);
        $uploadsRoot = realpath($uploadsRoot);
        $workingRoot = realpath($workingRoot);
        if ($archivePath === false || $projectRoot === false || $uploadsRoot === false || $workingRoot === false
            || !is_file($archivePath) || !is_dir($projectRoot) || !is_dir($uploadsRoot) || !is_dir($workingRoot)
            || !self::isWithin($projectRoot, $uploadsRoot) || !self::isWithin($projectRoot, $workingRoot)) {
            throw new RuntimeException('File restore paths could not be verified inside the application storage roots.');
        }
        if (!BackupIntegrity::tarHasSafeStructure($archivePath)) {
            throw new RuntimeException('The selected file archive failed structural validation.');
        }
        self::validateTarMemberTypes($archivePath);

        $uploadsRelativeRoot = self::relativeTo($projectRoot, $uploadsRoot);
        $destinationRoots = [
            'application' => '',
            'uploads' => $uploadsRelativeRoot,
            'avatars' => 'storage/avatars',
        ];
        $manifest = self::manifest($archivePath, $projectRoot, $destinationRoots);
        $lockPath = $workingRoot . DIRECTORY_SEPARATOR . '.file-restore.lock';
        if (is_link($lockPath)) throw new RuntimeException('File restore lock cannot be a symbolic link.');
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new RuntimeException('File restore lock could not be opened.');
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another file restore operation is already running.');
        }

        $stageRoot = $workingRoot . DIRECTORY_SEPARATOR . '.file-restore-stage-' . bin2hex(random_bytes(12));
        $safetyBackupId = 0;
        $changes = [];
        $createdDirectories = [];
        $preserveStage = false;
        try {
            if (!mkdir($stageRoot, 0700)) throw new RuntimeException('File restore staging directory could not be created.');
            @chmod($stageRoot, 0700);
            self::stageEntries($manifest, $stageRoot, $projectRoot);
            foreach ($manifest as $entry) {
                self::targetPath($projectRoot, $entry['project_path'], false);
            }

            $safetyBackupId = (int) $createSafetyBackup();
            if ($safetyBackupId < 1) throw new RuntimeException('A verified safety file backup was not created.');

            foreach ($manifest as $entry) {
                $targetPath = self::targetPath($projectRoot, $entry['project_path'], true, $createdDirectories);
                $stagedPath = self::targetPath($stageRoot, $entry['project_path'], false);
                $heldPath = self::targetPath($stageRoot, 'replaced/' . $entry['project_path'], true, $createdDirectories);
                $hadOriginal = file_exists($targetPath);
                $changeIndex = count($changes);
                $changes[] = [
                    'target' => $targetPath,
                    'project_path' => $entry['project_path'],
                    'held' => $heldPath,
                    'had_original' => $hadOriginal,
                    'original_held' => false,
                    'installed' => false,
                ];

                if ($hadOriginal) {
                    if (!@rename($targetPath, $heldPath)) {
                        throw new RuntimeException('An existing destination file could not be moved into the staging area.');
                    }
                    $changes[$changeIndex]['original_held'] = true;
                }
                if (!@rename($stagedPath, $targetPath)) {
                    throw new RuntimeException('A staged file could not be installed at its validated destination.');
                }
                $changes[$changeIndex]['installed'] = true;
            }

            $finalizeRestore($safetyBackupId);
            return ['safety_backup_id' => $safetyBackupId, 'files_restored' => count($manifest)];
        } catch (Throwable $error) {
            $rollbackFailed = !self::rollback($projectRoot, $changes, $createdDirectories);
            if ($rollbackFailed) {
                $preserveStage = true;
                $backupNote = $safetyBackupId > 0 ? ' Verified safety backup #' . $safetyBackupId . ' remains in private storage.' : '';
                throw new RuntimeException('File restoration may be partially applied and recovery material was preserved in private staging.' . $backupNote, 0, $error);
            }
            if ($safetyBackupId > 0) {
                throw new RuntimeException('File restoration failed; prior files were restored and verified safety backup #' . $safetyBackupId . ' remains in private storage.', 0, $error);
            }
            throw new RuntimeException('File restoration failed before any destination files were replaced.', 0, $error);
        } finally {
            if (!$preserveStage && is_dir($stageRoot)) self::removeTree($stageRoot);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function manifest(string $archivePath, string $projectRoot, array $destinationRoots): array
    {
        try {
            $archive = new PharData($archivePath);
            $prefix = 'phar://' . str_replace('\\', '/', $archivePath) . '/';
            $entries = [];
            $seen = [];
            $totalBytes = 0;
            foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY) as $entry) {
                if (!$entry instanceof PharFileInfo || $entry->isLink() || $entry->isDir() || !$entry->isFile()) {
                    throw new RuntimeException('File archives may contain regular files only; links and other entry types are rejected.');
                }
                $mode = $entry->getPerms() & 0170000;
                if ($mode !== 0 && $mode !== 0100000) {
                    throw new RuntimeException('File archive contains an unsupported non-regular entry.');
                }

                $sourcePath = str_replace('\\', '/', $entry->getPathName());
                $prefixMatches = DIRECTORY_SEPARATOR === '\\'
                    ? stripos($sourcePath, $prefix) === 0
                    : str_starts_with($sourcePath, $prefix);
                if (!$prefixMatches) throw new RuntimeException('File archive entry path could not be associated with the selected archive.');
                $archiveRelative = substr($sourcePath, strlen($prefix));
                $archiveRelative = self::validateArchivePath($archiveRelative);
                [$area, $relativePath] = self::mapArchivePath($archiveRelative, $destinationRoots);
                $projectRelative = $destinationRoots[$area] === ''
                    ? $relativePath
                    : $destinationRoots[$area] . '/' . $relativePath;
                $projectRelative = self::validateArchivePath($projectRelative);
                $key = strtolower($projectRelative);
                if (isset($seen[$key])) throw new RuntimeException('File archive contains duplicate destination paths.');
                $seen[$key] = true;

                $size = $entry->getSize();
                if (!is_int($size) || $size < 0 || $size > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('File archive contains an invalid entry size.');
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_TOTAL_BYTES || count($entries) >= self::MAX_ENTRIES) {
                    throw new RuntimeException('File archive exceeds the supported restore limits.');
                }
                $entries[] = [
                    'source_path' => $sourcePath,
                    'project_path' => $projectRelative,
                    'size' => $size,
                ];
            }
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException) throw $error;
            throw new RuntimeException('File archive entries could not be safely enumerated.', 0, $error);
        }

        if ($entries === []) throw new RuntimeException('The file archive contains no restorable files.');
        return $entries;
    }

    private static function validateTarMemberTypes(string $archivePath): void
    {
        $handle = @fopen($archivePath, 'rb');
        if ($handle === false) throw new RuntimeException('File archive could not be opened for member-type validation.');
        $memberCount = 0;
        try {
            while (!feof($handle)) {
                $header = fread($handle, 512);
                if (!is_string($header) || $header === '') break;
                if (strlen($header) !== 512) throw new RuntimeException('File archive contains a truncated TAR header.');
                if ($header === str_repeat("\0", 512)) break;
                if (++$memberCount > self::MAX_ENTRIES * 2) throw new RuntimeException('File archive has too many TAR metadata entries.');

                $type = $header[156] ?? "\0";
                if ($type === '1' || $type === '2') {
                    throw new RuntimeException('File archives may not contain hard links or symbolic links.');
                }
                if (!in_array($type, ["\0", '0', '5', 'x', 'g', 'L', 'K'], true)) {
                    throw new RuntimeException('File archive contains an unsupported TAR member type.');
                }

                $sizeField = trim(substr($header, 124, 12), " \0");
                if ($sizeField === '') {
                    $size = 0;
                } elseif (preg_match('/\A[0-7]+\z/', $sizeField) === 1) {
                    $size = octdec($sizeField);
                } else {
                    throw new RuntimeException('File archive contains an invalid TAR member size.');
                }
                if (!is_int($size) || $size < 0 || $size > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('File archive contains an unsupported TAR member size.');
                }
                $padding = (512 - ($size % 512)) % 512;
                if (fseek($handle, $size + $padding, SEEK_CUR) !== 0) {
                    throw new RuntimeException('File archive member data could not be traversed safely.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private static function validateArchivePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || strlen($path) > 240 || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new RuntimeException('File archive contains an absolute or invalid path.');
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[<>:"|?*]/', $segment) === 1
                || preg_match('/[. ]$/', $segment) === 1
                || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment) === 1) {
                throw new RuntimeException('File archive contains an unsafe path component.');
            }
            $lower = strtolower($segment);
            if (in_array($lower, ['.git', 'node_modules', 'cache', 'caches', 'tmp', 'temp', 'logs', 'backups', 'sessions', '__pycache__'], true)
                || preg_match('/\A(?:\.env(?:\..*)?|database\.local\.php|auth\.json|credentials?\.json)\z/i', $segment) === 1) {
                throw new RuntimeException('File archive includes an excluded runtime or credential path.');
            }
        }
        return implode('/', $segments);
    }

    private static function mapArchivePath(string $path, array $destinationRoots): array
    {
        if (str_starts_with($path, 'application/')) {
            $relative = substr($path, strlen('application/'));
            $topLevel = explode('/', $relative, 2)[0];
            $applicationDirectories = ['app', 'public', 'resources', 'database', 'scripts', 'vendor'];
            $applicationFiles = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'README.md'];
            if (!in_array($topLevel, $applicationDirectories, true)
                && !in_array($relative, $applicationFiles, true)
                && $relative !== 'config/database.example.php') {
                throw new RuntimeException('File archive contains an unsupported application path.');
            }
            return ['application', self::validateArchivePath($relative)];
        }
        if (str_starts_with($path, 'uploads/')) {
            return ['uploads', self::validateArchivePath(substr($path, strlen('uploads/')))];
        }
        if (str_starts_with($path, 'avatars/')) {
            return ['avatars', self::validateArchivePath(substr($path, strlen('avatars/')))];
        }
        throw new RuntimeException('File archive contains an unsupported top-level path.');
    }

    private static function stageEntries(array $manifest, string $stageRoot, string $projectRoot): void
    {
        foreach ($manifest as $entry) {
            $stagePath = self::targetPath($stageRoot, $entry['project_path'], true);
            $source = @fopen($entry['source_path'], 'rb');
            $destination = @fopen($stagePath, 'xb');
            if ($source === false || $destination === false) {
                if (is_resource($source)) fclose($source);
                if (is_resource($destination)) fclose($destination);
                throw new RuntimeException('A file archive entry could not be staged.');
            }
            @chmod($stagePath, 0600);
            try {
                $copiedBytes = stream_copy_to_stream($source, $destination);
                if (!is_int($copiedBytes) || $copiedBytes !== $entry['size']) {
                    throw new RuntimeException('A staged file size did not match its archive entry.');
                }
            } finally {
                fclose($source);
                fclose($destination);
            }
        }
    }

    private static function targetPath(string $root, string $relativePath, bool $createParents, array &$createdDirectories = []): string
    {
        $segments = explode('/', self::validateArchivePath($relativePath));
        $leaf = array_pop($segments);
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal) || !self::samePath($rootReal, $root)) {
            throw new RuntimeException('A restore root is missing, redirected, or could not be resolved.');
        }

        $current = $rootReal;
        foreach ($segments as $segment) {
            $next = $current . DIRECTORY_SEPARATOR . $segment;
            if (is_link($next)) throw new RuntimeException('A restore destination contains a symbolic link.');
            if (is_dir($next)) {
                $resolved = realpath($next);
                if ($resolved === false || !self::samePath($resolved, $next)) {
                    throw new RuntimeException('A restore destination contains a redirected directory.');
                }
            } elseif (file_exists($next)) {
                throw new RuntimeException('A restore destination parent is not a directory.');
            } elseif ($createParents) {
                if (!@mkdir($next, 0700)) throw new RuntimeException('A restore destination directory could not be created.');
                @chmod($next, 0700);
                $createdDirectories[] = $next;
                $resolved = realpath($next);
                if ($resolved === false || !self::samePath($resolved, $next)) {
                    throw new RuntimeException('A newly created restore directory could not be verified.');
                }
            } else {
                return $next . DIRECTORY_SEPARATOR . $leaf;
            }
            $current = $next;
        }

        $target = $current . DIRECTORY_SEPARATOR . $leaf;
        if (is_link($target)) throw new RuntimeException('A restore destination is a symbolic link.');
        if (file_exists($target)) {
            if (!is_file($target)) throw new RuntimeException('A restore destination is not a regular file.');
            $resolved = realpath($target);
            if ($resolved === false || !self::samePath($resolved, $target)) {
                throw new RuntimeException('A restore destination is redirected outside its validated path.');
            }
        }
        return $target;
    }

    private static function rollback(string $projectRoot, array $changes, array $createdDirectories): bool
    {
        $success = true;
        foreach (array_reverse($changes) as $change) {
            try {
                self::targetPath($projectRoot, $change['project_path'], false);
                if ($change['installed'] && is_file($change['target']) && !@unlink($change['target'])) {
                    $success = false;
                    continue;
                }
                if ($change['original_held'] && is_file($change['held']) && !@rename($change['held'], $change['target'])) {
                    $success = false;
                }
            } catch (Throwable) {
                $success = false;
            }
        }
        foreach (array_reverse($createdDirectories) as $directory) {
            if (is_dir($directory) && !is_link($directory)) @rmdir($directory);
        }
        return $success;
    }

    private static function relativeTo(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (!self::isWithin($root, $path) || self::samePath($root, $path)) {
            throw new RuntimeException('A restore path is outside its approved root.');
        }
        return substr($path, strlen($root) + 1);
    }

    private static function isWithin(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (self::samePath($root, $path)) return true;
        $prefix = $root . '/';
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $child) {
            self::removeTree($child->getPathname());
        }
        @rmdir($path);
    }
}
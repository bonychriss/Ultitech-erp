<?php

declare(strict_types=1);

if (!function_exists('backupEngineRootDir')) {
    function backupEngineRootDir(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('backupEngineStorageDir')) {
    function backupEngineStorageDir(int $companyId): string
    {
        $dir = backupEngineRootDir() . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'tenant_' . max(0, $companyId) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('backupEngineValidateId')) {
    function backupEngineValidateId(string $id): bool
    {
        return (bool) preg_match('/^backup_[0-9]{8}_[0-9]{6}$/', $id);
    }
}

if (!function_exists('backupEngineList')) {
    /**
     * @return list<array{id:string,filename:string,size_bytes:int,size_label:string,created_at:string,created_label:string,download_url:string}>
     */
    function backupEngineList(int $companyId): array
    {
        $dir = backupEngineStorageDir($companyId);
        $items = [];
        if (!is_dir($dir)) {
            return $items;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . 'backup_*.zip') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $filename = basename($path);
            $id = preg_replace('/\.zip$/', '', $filename) ?: '';
            if (!backupEngineValidateId($id)) {
                continue;
            }
            $mtime = (int) filemtime($path);
            $size = (int) filesize($path);
            $items[] = [
                'id' => $id,
                'filename' => $filename,
                'size_bytes' => $size,
                'size_label' => backupEngineFormatBytes($size),
                'created_at' => gmdate('c', $mtime),
                'created_label' => date('d M Y, H:i', $mtime),
                'download_url' => backupEngineDownloadUrl($id),
            ];
        }

        usort($items, static function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return $items;
    }
}

if (!function_exists('backupEngineFormatBytes')) {
    function backupEngineFormatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

if (!function_exists('backupEngineDownloadUrl')) {
    function backupEngineDownloadUrl(string $id): string
    {
        if (function_exists('backupDeskPublicUrl')) {
            return backupDeskPublicUrl('api/index.php') . '?action=download&id=' . rawurlencode($id);
        }
        return '/modules/backup/api/index.php?action=download&id=' . rawurlencode($id);
    }
}

if (!function_exists('backupEngineFindMysqldump')) {
    function backupEngineFindMysqldump(): ?string
    {
        $candidates = ['mysqldump'];
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
            $candidates[] = 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe';
            $candidates[] = 'C:\\Program Files\\MariaDB 10.4\\bin\\mysqldump.exe';
        }
        foreach ($candidates as $bin) {
            if ($bin === 'mysqldump') {
                $out = [];
                $code = 1;
                @exec('mysqldump --version 2>&1', $out, $code);
                if ($code === 0) {
                    return 'mysqldump';
                }
                continue;
            }
            if (is_file($bin)) {
                return $bin;
            }
        }
        return null;
    }
}

if (!function_exists('backupEngineResolveDbCredentials')) {
    /**
     * @return array{host:string,name:string,user:string,pass:string}|null
     */
    function backupEngineResolveDbCredentials(PDO $pdo): ?array
    {
        $name = '';
        try {
            $name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
            $name = '';
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];
        $host = trim((string) ($company['db_host'] ?? ''));
        if ($host === '' && defined('DB_HOST')) {
            $host = (string) DB_HOST;
        }
        if ($host === '') {
            $host = '127.0.0.1';
        }

        $user = trim((string) ($company['db_user'] ?? ''));
        if ($user === '' && defined('DB_USER')) {
            $user = (string) DB_USER;
        }
        $pass = array_key_exists('db_pass', $company) ? (string) $company['db_pass'] : (defined('DB_PASS') ? (string) DB_PASS : '');

        return [
            'host' => $host,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ];
    }
}

if (!function_exists('backupEngineDumpDatabase')) {
    function backupEngineDumpDatabase(PDO $pdo, string $outputFile): array
    {
        $creds = backupEngineResolveDbCredentials($pdo);
        $mysqldump = backupEngineFindMysqldump();
        if ($creds && $mysqldump) {
            $cmd = escapeshellarg($mysqldump)
                . ' --host=' . escapeshellarg($creds['host'])
                . ' --user=' . escapeshellarg($creds['user'])
                . ' --default-character-set=utf8mb4'
                . ' --single-transaction --routines --triggers'
                . ' --result-file=' . escapeshellarg($outputFile)
                . ' ' . escapeshellarg($creds['name']);
            if ($creds['pass'] !== '') {
                $cmd = escapeshellarg($mysqldump)
                    . ' --host=' . escapeshellarg($creds['host'])
                    . ' --user=' . escapeshellarg($creds['user'])
                    . ' --password=' . escapeshellarg($creds['pass'])
                    . ' --default-character-set=utf8mb4'
                    . ' --single-transaction --routines --triggers'
                    . ' --result-file=' . escapeshellarg($outputFile)
                    . ' ' . escapeshellarg($creds['name']);
            }
            $out = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0 && is_file($outputFile) && filesize($outputFile) > 0) {
                return ['method' => 'mysqldump', 'tables' => null, 'rows' => null];
            }
        }

        return backupEngineDumpDatabaseViaPdo($pdo, $outputFile);
    }
}

if (!function_exists('backupEngineDumpDatabaseViaPdo')) {
    function backupEngineDumpDatabaseViaPdo(PDO $pdo, string $outputFile): array
    {
        $handle = fopen($outputFile, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write database dump file.');
        }

        $tables = 0;
        $rows = 0;
        fwrite($handle, "-- ERP company backup\n-- Generated: " . gmdate('c') . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tableList = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tableList as $table) {
            $table = (string) $table;
            if ($table === '') {
                continue;
            }
            $tables++;
            $createRow = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
            $createSql = (string) ($createRow['Create Table'] ?? $createRow['Create View'] ?? '');
            fwrite($handle, "DROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n");
            if ($createSql !== '') {
                fwrite($handle, $createSql . ";\n\n");
            }

            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            $columns = [];
            $colCount = $stmt ? $stmt->columnCount() : 0;
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = '`' . str_replace('`', '``', (string) ($meta['name'] ?? '')) . '`';
            }
            if ($columns === []) {
                continue;
            }
            $colList = implode(', ', $columns);

            while ($stmt && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $values[] = (string) $value;
                    } else {
                        $values[] = $pdo->quote((string) $value);
                    }
                }
                fwrite($handle, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $colList . ') VALUES (' . implode(', ', $values) . ");\n");
                $rows++;
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return ['method' => 'pdo', 'tables' => $tables, 'rows' => $rows];
    }
}

if (!function_exists('backupEngineCollectFilePaths')) {
    /**
     * @return list<string> absolute paths
     */
    function backupEngineCollectFilePaths(int $companyId): array
    {
        $root = backupEngineRootDir();
        $paths = [];

        $tenantDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . max(0, $companyId);
        if (is_dir($tenantDir)) {
            $paths[] = $tenantDir;
        }

        $legacyUploads = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'company_' . max(0, $companyId);
        if (is_dir($legacyUploads)) {
            $paths[] = $legacyUploads;
        }

        // Company-scoped logo folder (outside tenant storage).
        $logoDir = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images'
            . DIRECTORY_SEPARATOR . 'company_logos' . DIRECTORY_SEPARATOR . (string) max(0, $companyId);
        if (is_dir($logoDir)) {
            $paths[] = $logoDir;
        }

        // Document / attachment trees used across modules (Documents desk + system attachments).
        foreach (backupEngineDocumentDirectoryRoots() as $rel) {
            $abs = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            if (is_dir($abs)) {
                $paths[] = $abs;
            }
        }

        return array_values(array_unique($paths));
    }
}

if (!function_exists('backupEngineDocumentDirectoryRoots')) {
    /**
     * Relative roots (from project root) that hold attached documents outside tenant storage.
     *
     * @return list<string>
     */
    function backupEngineDocumentDirectoryRoots(): array
    {
        return [
            'assets/uploads/vouchers',
            'assets/uploads/deliveries',
            'assets/uploads/invoices',
            'assets/uploads/petty-cash',
            'assets/uploads/swift',
            'assets/uploads/messages',
            'assets/uploads/chat',
            'assets/signatures',
            'uploads/expenses',
            'uploads/revenue',
            'uploads/evidence',
            'uploads/trip_attachments',
            'uploads/vouchers',
            'uploads/email_attachments',
            'uploads/sales',
            'stock/uploads/invoices',
            'stock/uploads/purchases',
            'modules/finance/uploads',
            'store-management-system/uploads',
        ];
    }
}

if (!function_exists('backupEngineRequireDocumentsCollector')) {
    function backupEngineRequireDocumentsCollector(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $path = backupEngineRootDir() . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'uploads_documents_collect.inc.php';
        if (is_file($path)) {
            require_once $path;
        }
        $loaded = true;
    }
}

if (!function_exists('backupEngineCollectAttachedDocuments')) {
    /**
     * Every document/attachment referenced in the tenant DB (Documents desk sources + extras).
     *
     * @return list<array{abs:string,rel:string,source:string}>
     */
    function backupEngineCollectAttachedDocuments(PDO $pdo, int $companyId): array
    {
        backupEngineRequireDocumentsCollector();
        if (!function_exists('uploads_collect_system_documents') || !function_exists('uploads_docs_resolve_fast')) {
            return [];
        }

        $docs = uploads_collect_system_documents($pdo, $companyId, 100000);
        $out = [];
        $seen = [];

        foreach ($docs as $doc) {
            $rel = ltrim(str_replace('\\', '/', (string) ($doc['rel'] ?? '')), '/');
            if ($rel === '') {
                continue;
            }
            $key = strtolower($rel);
            if (isset($seen[$key])) {
                continue;
            }

            [$abs, $webRel] = uploads_docs_resolve_fast($rel);
            $webRel = ltrim(str_replace('\\', '/', (string) $webRel), '/');
            if ($abs === '' || !is_file($abs)) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'abs' => $abs,
                'rel' => $webRel !== '' ? $webRel : $rel,
                'source' => (string) ($doc['source'] ?? 'system'),
            ];
        }

        return $out;
    }
}

if (!function_exists('backupEngineSafeZipPath')) {
    /**
     * Keep ZIP entry paths extractable on Windows (MAX_PATH / Error 0x80010135).
     *
     * @param array<string,bool> $usedNames
     * @param list<array{original:string,stored_as:string}> $pathMap
     */
    function backupEngineSafeZipPath(string $zipPath, array &$usedNames = [], array &$pathMap = []): string
    {
        $zipPath = preg_replace('#/+#', '/', str_replace('\\', '/', trim($zipPath, '/'))) ?: '';
        if ($zipPath === '') {
            return 'files/unnamed.bin';
        }

        // Leave room for a typical extract folder (Downloads/backup_id/...).
        $maxEntry = 140;
        $maxBase = 60;

        $dir = trim(str_replace('\\', '/', dirname($zipPath)), '.');
        if ($dir === '/' || $dir === '\\') {
            $dir = '';
        }
        $base = basename($zipPath);
        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($base, PATHINFO_FILENAME);
        $extSuffix = $ext !== '' ? '.' . $ext : '';
        $hash = substr(sha1($zipPath), 0, 8);

        $needsShortName = strlen($base) > $maxBase || strlen($zipPath) > $maxEntry;
        if ($needsShortName) {
            $maxStem = max(16, $maxBase - strlen($extSuffix) - 9);
            if (function_exists('mb_substr')) {
                $stemShort = mb_substr($stem, 0, $maxStem, 'UTF-8');
            } else {
                $stemShort = substr($stem, 0, $maxStem);
            }
            $stemShort = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $stemShort) ?: 'file';
            $stemShort = trim($stemShort, '._-');
            if ($stemShort === '') {
                $stemShort = 'file';
            }
            $base = $stemShort . '_' . $hash . $extSuffix;
        }

        $candidate = ($dir !== '' ? $dir . '/' : '') . $base;

        // Still too long (deep folders + long parents): flatten under files/_long/.
        if (strlen($candidate) > $maxEntry) {
            $candidate = 'files/_long/' . substr(sha1($zipPath), 0, 16) . $extSuffix;
        }

        $final = $candidate;
        $i = 1;
        while (isset($usedNames[strtolower($final)])) {
            $info = pathinfo($candidate);
            $d = isset($info['dirname']) && $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';
            $f = (string) ($info['filename'] ?? 'file');
            $e = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
            $final = $d . $f . '_' . $i . $e;
            $i++;
            if ($i > 500) {
                $final = 'files/_long/' . substr(sha1($zipPath . '#' . $i), 0, 20) . $extSuffix;
                break;
            }
        }
        $usedNames[strtolower($final)] = true;

        if ($final !== $zipPath) {
            $pathMap[] = [
                'original' => $zipPath,
                'stored_as' => $final,
            ];
        }

        return $final;
    }
}

if (!function_exists('backupEngineAddAttachedDocumentsToZip')) {
    /**
     * @param list<array{abs:string,rel:string,source?:string}> $files
     * @param array<string,bool> $usedNames
     * @param list<array{original:string,stored_as:string}> $pathMap
     * @param callable|null $onFileProgress function(int $filesAdded): void
     */
    function backupEngineAddAttachedDocumentsToZip(
        ZipArchive $zip,
        array $files,
        array &$stats,
        array $skipUnderDirs = [],
        $onFileProgress = null,
        array &$usedNames = [],
        array &$pathMap = []
    ): int {
        $added = 0;
        $skipUnderDirs = array_values(array_filter(array_map(static function ($dir) {
            return rtrim(str_replace('\\', '/', (string) $dir), '/');
        }, $skipUnderDirs)));

        foreach ($files as $file) {
            $abs = (string) ($file['abs'] ?? '');
            $rel = ltrim(str_replace('\\', '/', (string) ($file['rel'] ?? '')), '/');
            if ($abs === '' || $rel === '' || !is_file($abs)) {
                continue;
            }

            $absNorm = str_replace('\\', '/', $abs);
            foreach ($skipUnderDirs as $skipDir) {
                if ($skipDir !== '' && strpos($absNorm, $skipDir . '/') === 0) {
                    continue 2;
                }
            }

            $zipPath = backupEngineSafeZipPath('files/system-documents/' . $rel, $usedNames, $pathMap);
            if ($zip->locateName($zipPath) !== false) {
                continue;
            }
            if ($zip->addFile($abs, $zipPath)) {
                $stats['files']++;
                $stats['bytes'] += (int) filesize($abs);
                $added++;
                if (is_callable($onFileProgress) && ($added % 25 === 0 || $added === 1)) {
                    $onFileProgress($added);
                }
            }
        }

        return $added;
    }
}

if (!function_exists('backupEngineAddDirToZip')) {
    /**
     * @param array<string,bool> $usedNames
     * @param list<array{original:string,stored_as:string}> $pathMap
     * @param callable|null $onFileProgress function(int $filesAdded): void
     */
    function backupEngineAddDirToZip(
        ZipArchive $zip,
        string $sourceDir,
        string $zipPrefix,
        array &$stats,
        array $excludeDirs = [],
        $onFileProgress = null,
        array &$usedNames = [],
        array &$pathMap = []
    ): void {
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        if (!is_dir($sourceDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $tick = 0;
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $path = str_replace('\\', '/', $fileInfo->getPathname());
            foreach ($excludeDirs as $exclude) {
                $exclude = rtrim(str_replace('\\', '/', $exclude), '/');
                if ($exclude !== '' && strpos($path, $exclude) === 0) {
                    continue 2;
                }
            }
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relative = ltrim(substr($path, strlen($sourceDir)), '/');
            $rawZipPath = trim($zipPrefix . '/' . str_replace('\\', '/', $relative), '/');
            $zipPath = backupEngineSafeZipPath($rawZipPath, $usedNames, $pathMap);
            if ($zip->locateName($zipPath) !== false) {
                continue;
            }
            if ($zip->addFile($fileInfo->getPathname(), $zipPath)) {
                $stats['files']++;
                $stats['bytes'] += (int) $fileInfo->getSize();
                $tick++;
                if (is_callable($onFileProgress) && ($tick % 25 === 0 || $tick === 1)) {
                    $onFileProgress((int) $stats['files']);
                }
            }
        }
    }
}

if (!function_exists('backupEngineCreate')) {
    /**
     * @param array<string,mixed> $companyInfo
     * @param callable|null $onProgress function(int $percent, string $label): void
     * @return array<string,mixed>
     */
    function backupEngineCreate(PDO $pdo, int $companyId, array $companyInfo = [], $onProgress = null): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP Zip extension is required for backups.');
        }

        $progress = static function (int $percent, string $label) use ($onProgress): void {
            if (is_callable($onProgress)) {
                $onProgress(max(0, min(100, $percent)), $label);
            }
        };

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $progress(2, 'Preparing backup workspace...');

        $backupId = 'backup_' . date('Ymd_His');
        $storageDir = backupEngineStorageDir($companyId);
        $zipPath = $storageDir . DIRECTORY_SEPARATOR . $backupId . '.zip';
        $tempDir = $storageDir . DIRECTORY_SEPARATOR . '.tmp_' . $backupId;
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true)) {
            throw new RuntimeException('Could not create temporary backup directory.');
        }

        try {
            $progress(8, 'Exporting company database...');
            $sqlFile = $tempDir . DIRECTORY_SEPARATOR . 'database.sql';
            $dbMeta = backupEngineDumpDatabase($pdo, $sqlFile);
            $progress(42, 'Database export complete. Building archive...');

            $manifest = [
                'backup_id' => $backupId,
                'created_at' => gmdate('c'),
                'company_id' => $companyId,
                'company_name' => (string) ($companyInfo['company_name'] ?? ''),
                'company_slug' => (string) ($companyInfo['company_slug'] ?? ''),
                'database' => $dbMeta,
                'files' => [],
            ];

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create backup archive.');
            }

            if (is_file($sqlFile)) {
                $zip->addFile($sqlFile, 'database/database.sql');
            }

            $progress(48, 'Collecting company files...');
            $fileStats = ['files' => 0, 'bytes' => 0];
            $usedZipNames = [];
            $zipPathMap = [];
            $rootNorm = rtrim(str_replace('\\', '/', backupEngineRootDir()), '/');
            $backupsDir = str_replace('\\', '/', backupEngineStorageDir($companyId));
            $tenantDir = str_replace(
                '\\',
                '/',
                backupEngineRootDir() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . max(0, $companyId)
            );
            $sources = backupEngineCollectFilePaths($companyId);
            $sourceCount = max(1, count($sources));
            $sourceIndex = 0;

            foreach ($sources as $absPath) {
                $sourceIndex++;
                $absNorm = rtrim(str_replace('\\', '/', $absPath), '/');
                $relFromRoot = $absNorm;
                if (strpos($absNorm, $rootNorm . '/') === 0) {
                    $relFromRoot = substr($absNorm, strlen($rootNorm) + 1);
                }
                $label = $relFromRoot !== '' ? $relFromRoot : basename($absPath);
                $zipPrefix = 'files/' . str_replace('\\', '/', $label);
                $basePct = 50 + (int) floor((($sourceIndex - 1) / $sourceCount) * 28);
                $progress($basePct, 'Packing files: ' . $label);

                backupEngineAddDirToZip(
                    $zip,
                    $absPath,
                    $zipPrefix,
                    $fileStats,
                    [$backupsDir],
                    static function (int $filesAdded) use ($progress, $basePct, $label): void {
                        $pct = min(82, $basePct + min(8, (int) floor($filesAdded / 50)));
                        $progress($pct, 'Packing ' . $label . ' (' . number_format($filesAdded) . ' files)...');
                    },
                    $usedZipNames,
                    $zipPathMap
                );

                $manifest['files'][] = [
                    'source' => $absPath,
                    'label' => $label,
                    'zip_prefix' => $zipPrefix,
                    'files' => $fileStats['files'],
                    'bytes' => $fileStats['bytes'],
                ];
            }

            $progress(84, 'Collecting system-attached documents...');
            $attachedDocs = backupEngineCollectAttachedDocuments($pdo, $companyId);
            $attachedBefore = (int) $fileStats['files'];
            $attachedAdded = backupEngineAddAttachedDocumentsToZip(
                $zip,
                $attachedDocs,
                $fileStats,
                [$tenantDir, $backupsDir],
                static function (int $filesAdded) use ($progress): void {
                    $pct = min(92, 84 + min(8, (int) floor($filesAdded / 40)));
                    $progress($pct, 'Packing attached documents (' . number_format($filesAdded) . ')...');
                },
                $usedZipNames,
                $zipPathMap
            );
            $manifest['attached_documents'] = [
                'discovered' => count($attachedDocs),
                'packed' => $attachedAdded,
                'files_total_after' => (int) $fileStats['files'],
                'bytes_total_after' => (int) $fileStats['bytes'],
                'files_added' => max(0, (int) $fileStats['files'] - $attachedBefore),
            ];
            $manifest['path_map'] = $zipPathMap;
            $manifest['path_map_count'] = count($zipPathMap);

            $progress(93, 'Writing backup manifest...');
            $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFile($manifestPath, 'manifest.json');
            $readme = "ERP Company Backup\n==================\n\n"
                . "Backup ID: {$backupId}\n"
                . "Company: " . ($manifest['company_name'] ?: 'Unknown') . "\n"
                . "Created: " . ($manifest['created_at'] ?? '') . "\n\n"
                . "Contents:\n"
                . "- database/database.sql       Full MySQL dump of the tenant database\n"
                . "- files/                      Company storage + document upload folders\n"
                . "- files/system-documents/     Extra DB-referenced attachments (if outside folders above)\n"
                . "- manifest.json               Metadata + path_map for any shortened Windows paths\n\n"
                . "Restore the SQL file into a MySQL/MariaDB database, then restore files under files/\n"
                . "back to the matching project-relative paths (e.g. files/assets/uploads/vouchers/...).\n"
                . "If a name was shortened for Windows path limits, see path_map in manifest.json.\n";
            $readmePath = $tempDir . DIRECTORY_SEPARATOR . 'README.txt';
            file_put_contents($readmePath, $readme);
            $zip->addFile($readmePath, 'README.txt');

            $progress(95, 'Finalizing ZIP archive...');
            $zip->close();

            if (!is_file($zipPath)) {
                throw new RuntimeException('Backup archive was not created.');
            }

            $size = (int) filesize($zipPath);
            $mtime = (int) filemtime($zipPath);

            $progress(100, 'Backup ready');

            return [
                'id' => $backupId,
                'filename' => basename($zipPath),
                'size_bytes' => $size,
                'size_label' => backupEngineFormatBytes($size),
                'created_at' => gmdate('c', $mtime),
                'created_label' => date('d M Y, H:i', $mtime),
                'download_url' => backupEngineDownloadUrl($backupId),
                'database' => $dbMeta,
                'files' => $fileStats,
            ];
        } finally {
            backupEngineRemoveDir($tempDir);
        }
    }
}

if (!function_exists('backupEngineRemoveDir')) {
    function backupEngineRemoveDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                backupEngineRemoveDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

if (!function_exists('backupEngineDelete')) {
    function backupEngineDelete(int $companyId, string $id): bool
    {
        if (!backupEngineValidateId($id)) {
            return false;
        }
        $path = backupEngineStorageDir($companyId) . DIRECTORY_SEPARATOR . $id . '.zip';
        if (!is_file($path)) {
            return false;
        }
        return @unlink($path);
    }
}

if (!function_exists('backupEngineGetZipPath')) {
    function backupEngineGetZipPath(int $companyId, string $id): ?string
    {
        if (!backupEngineValidateId($id)) {
            return null;
        }
        $path = backupEngineStorageDir($companyId) . DIRECTORY_SEPARATOR . $id . '.zip';
        return is_file($path) ? $path : null;
    }
}

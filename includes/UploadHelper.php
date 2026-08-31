<?php
/**
 * UploadHelper - Centralized, Secure, and Multi-Tenant File Upload Engine
 * 
 * Handles multi-tenant storage paths, strict validation, deterministic uncacheable renaming,
 * MIME sniffing, image resizing, and physical file deletion.
 */

class UploadHelper {
    
    // Allowed MIME types grouped by utility
    public static $ALLOWED_TYPES = [
        'images' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'],
        'documents' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'],
        'spreadsheets' => ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'all' => [] // Populated dynamically if needed
    ];

    /**
     * Get the tenant-scoped absolute storage directory and ensure it exists.
     * Maps to: public_html/storage/tenant_{company_id}/{submodule}
     */
    public static function getTenantDir(int $companyId, string $submodule = ''): string {
        $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
        
        // Tenant directory
        $tenantDir = $baseDir . DIRECTORY_SEPARATOR . 'tenant_' . $companyId;
        
        if ($submodule !== '') {
            $submodule = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $submodule));
            $tenantDir .= DIRECTORY_SEPARATOR . $submodule;
        }

        if (!is_dir($tenantDir)) {
            @mkdir($tenantDir, 0775, true);
        }

        // Keep directory permissions secure
        if (is_dir($tenantDir) && !is_writable($tenantDir)) {
            @chmod($tenantDir, 0775);
        }

        return $tenantDir;
    }

    /**
     * Converts a full file path inside the storage directory into a web URL.
     * E.g., C:/xampp/htdocs/public_html/storage/tenant_1/invoices/invoice_x.pdf -> /storage/tenant_1/invoices/invoice_x.pdf
     */
    public static function getUrlFromPath(string $absolutePath): string {
        $root = dirname(__DIR__);
        $cleanRoot = str_replace('\\', '/', $root);
        $cleanPath = str_replace('\\', '/', $absolutePath);

        // Strip the root path
        if (strpos($cleanPath, $cleanRoot) === 0) {
            $relativePath = substr($cleanPath, strlen($cleanRoot));
            if (function_exists('app_url')) {
                return app_url('/' . ltrim($relativePath, '/'));
            }
            return '/' . ltrim($relativePath, '/');
        }

        return $absolutePath;
    }

    /**
     * Safely upload, validate, sanitize, and rename a file under tenant context.
     * 
     * @param array $fileArray Element from $_FILES (e.g. $_FILES['receipt'])
     * @param int $companyId The current active tenant ID
     * @param string $submodule Folder name (e.g. 'vouchers', 'products', 'signatures')
     * @param array $options Validation options [
     *      'allowed_groups' => ['images', 'documents'], // keys of $ALLOWED_TYPES
     *      'allowed_mimes' => ['image/png'], // override all restrictions
     *      'max_bytes' => 5000000, // max file size
     *      'prefix' => 'proof', // name prefix
     *      'record_id' => 14, // link ID to track filename
     *      'optimize_image' => true, // resize images to 1600x1200 max
     * ]
     * @return array ['ok' => bool, 'filename' => string, 'stored_path' => string, 'url' => string, 'mime' => string, 'size' => int, 'error' => string]
     */
    public static function upload(array $fileArray, int $companyId, string $submodule, array $options = []): array {
        // 1. Basic error checking
        $err = $fileArray['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $errorsMap = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit.',
                UPLOAD_ERR_PARTIAL => 'File only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'PHP extension blocked the upload.'
            ];
            return ['ok' => false, 'error' => $errorsMap[$err] ?? 'Unknown upload error.'];
        }

        $tmpPath = $fileArray['tmp_name'] ?? '';
        if (!is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'error' => 'Invalid upload request.'];
        }

        $size = (int) ($fileArray['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'File appears to be empty.'];
        }

        // 2. Max bytes check
        $maxBytes = $options['max_bytes'] ?? 5242880; // Default: 5MB
        if ($size > $maxBytes) {
            $sizeFriendly = round($maxBytes / 1024 / 1024, 2) . 'MB';
            return ['ok' => false, 'error' => "File size exceeds the permitted limit of {$sizeFriendly}."];
        }

        // 3. Strict MIME Sniffing using finfo
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? @finfo_file($finfo, $tmpPath) : null;
        if ($finfo) {
            @finfo_close($finfo);
        }
        if (!$mime) {
            $mime = $fileArray['type'] ?? 'application/octet-stream';
        }
        $mime = strtolower(trim($mime));

        // 4. Validate allowed MIMEs
        $isAllowed = false;
        $allowedList = [];

        if (!empty($options['allowed_mimes'])) {
            $allowedList = array_map('strtolower', $options['allowed_mimes']);
        } else {
            $groups = $options['allowed_groups'] ?? ['images', 'documents', 'spreadsheets'];
            foreach ($groups as $group) {
                if (isset(self::$ALLOWED_TYPES[$group])) {
                    $allowedList = array_merge($allowedList, self::$ALLOWED_TYPES[$group]);
                }
            }
        }

        if (empty($allowedList) || in_array($mime, $allowedList, true)) {
            $isAllowed = true;
        }

        if (!$isAllowed) {
            return ['ok' => false, 'error' => "File type '{$mime}' is not permitted."];
        }

        // 5. Resolve target folder
        $targetDir = self::getTenantDir($companyId, $submodule);
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            return ['ok' => false, 'error' => "Storage directory is not writable."];
        }

        // 6. Generate uncacheable filename
        $originalExt = strtolower(pathinfo($fileArray['name'] ?? 'file', PATHINFO_EXTENSION));
        if ($originalExt === '') {
            $mimeExtMap = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf',
                'text/csv' => 'csv'
            ];
            $originalExt = $mimeExtMap[$mime] ?? 'dat';
        }

        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $options['prefix'] ?? 'file');
        $recordId = (int) ($options['record_id'] ?? 0);
        $hash = substr(hash('sha256', $companyId . '|' . microtime(true) . '|' . random_bytes(8)), 0, 10);
        $newName = $prefix;
        if ($recordId > 0) {
            $newName .= '_' . $recordId;
        }
        $newName .= '_' . date('YmdHis') . '_' . $hash . '.' . $originalExt;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $newName;

        // 7. Process / Optimize / Resize Images
        $optimizeImage = $options['optimize_image'] ?? true;
        $resized = false;
        
        if ($optimizeImage && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && function_exists('imagecreatefromstring')) {
            $resized = self::optimizeImage($tmpPath, $targetPath, $mime);
        }

        // If not an image or resizing was bypassed/failed, move file normally
        if (!$resized) {
            if (!@move_uploaded_file($tmpPath, $targetPath)) {
                return ['ok' => false, 'error' => 'Failed to persist uploaded file.'];
            }
        }

        $storedPath = 'storage/tenant_' . $companyId . '/' . ($submodule !== '' ? strtolower($submodule) . '/' : '') . $newName;
        $url = self::getUrlFromPath($targetPath);

        return [
            'ok' => true,
            'filename' => $newName,
            'stored_path' => $storedPath,
            'url' => $url,
            'mime' => $mime,
            'size' => $size,
            'error' => null
        ];
    }

    /**
     * Optimize and resize large images down to max dimensions (1600x1200) to save storage.
     */
    private static function optimizeImage(string $src, string $dest, string $mime, int $maxWidth = 1600, int $maxHeight = 1200): bool {
        $raw = @file_get_contents($src);
        if ($raw === false) return false;

        $img = @imagecreatefromstring($raw);
        if (!$img) return false;

        $width = imagesx($img);
        $height = imagesy($img);

        // Do not resize if already smaller than constraints
        if ($width <= $maxWidth && $height <= $maxHeight) {
            imagedestroy($img);
            return false;
        }

        // Calculate scaling ratio
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int) ($width * $ratio);
        $newHeight = (int) ($height * $ratio);

        $tmpImg = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve alpha transparency for PNG/WebP
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($tmpImg, false);
            imagesavealpha($tmpImg, true);
            $transparent = imagecolorallocatealpha($tmpImg, 255, 255, 255, 127);
            imagefilledrectangle($tmpImg, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($tmpImg, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $saved = false;
        switch ($mime) {
            case 'image/jpeg':
                $saved = @imagejpeg($tmpImg, $dest, 85);
                break;
            case 'image/png':
                $saved = @imagepng($tmpImg, $dest, 6);
                break;
            case 'image/webp':
                $saved = @imagewebp($tmpImg, $dest, 80);
                break;
        }

        imagedestroy($img);
        imagedestroy($tmpImg);

        return $saved;
    }

    /**
     * Safely delete a file physically from disk.
     */
    public static function delete(string $storedPath): bool {
        $storedPath = ltrim(str_replace('\\', '/', $storedPath), '/');
        
        // Block directory traversal attempts
        if (strpos($storedPath, '../') !== false || strpos($storedPath, '..\\') !== false) {
            return false;
        }

        $root = dirname(__DIR__);
        $absPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);

        if (file_exists($absPath) && is_file($absPath)) {
            return @unlink($absPath);
        }

        return false;
    }
}

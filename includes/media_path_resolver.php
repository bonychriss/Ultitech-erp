<?php
/**
 * Resolve a stored web-relative media path to an absolute filesystem path.
 * Handles legacy mirrors (uploads/ vs assets/uploads/) and tenant voucher storage.
 */
if (!function_exists('mediaPathProjectRoot')) {
    function mediaPathProjectRoot(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('mediaPathCompanyIdsToTry')) {
    /**
     * @return int[]
     */
    function mediaPathCompanyIdsToTry(int $companyId = 0): array
    {
        if ($companyId > 0) {
            return [$companyId];
        }

        return [1, 2];
    }
}

if (!function_exists('resolveStoredMediaFilePath')) {
    function resolveStoredMediaFilePath(string $file, int $companyId = 0): string
    {
        $file = ltrim(str_replace('\\', '/', trim($file)), '/');
        if ($file === '' || preg_match('#^https?://#i', $file)) {
            return '';
        }

        $root = mediaPathProjectRoot();
        $candidates = [];
        $push = static function (string $rel) use (&$candidates, $root): void {
            $rel = ltrim(str_replace('\\', '/', $rel), '/');
            if ($rel !== '') {
                $candidates[] = $root . '/' . $rel;
            }
        };

        $push($file);

        if (strpos($file, 'assets/uploads/') === 0) {
            $push(substr($file, strlen('assets/')));
        } elseif (strpos($file, 'uploads/') === 0) {
            $push('assets/' . $file);
        }

        $baseName = basename($file);
        if (preg_match('#^(?:assets/uploads|uploads)/vouchers(?:/([0-9]+))?/([^/]+)$#i', $file, $m)) {
            $voucherFolder = (string) ($m[1] ?? '');
            $baseName = (string) ($m[2] ?? $baseName);

            $push('uploads/vouchers/' . ($voucherFolder !== '' ? $voucherFolder . '/' : '') . $baseName);
            $push('assets/uploads/vouchers/' . $baseName);
            $push('uploads/vouchers/' . $baseName);

            foreach (mediaPathCompanyIdsToTry($companyId) as $tenantId) {
                $tenantBase = 'storage/tenant_' . $tenantId . '/vouchers';
                $push($tenantBase . '/' . $baseName);
                if ($voucherFolder !== '') {
                    $push($tenantBase . '/' . $voucherFolder . '/' . $baseName);
                }
            }

            foreach (['assets/uploads/vouchers', 'uploads/vouchers'] as $voucherRoot) {
                if ($voucherFolder !== '') {
                    $push($voucherRoot . '/' . $voucherFolder . '/' . $baseName);
                }
                $globPattern = $root . '/' . $voucherRoot . '/*/' . $baseName;
                foreach (glob($globPattern) ?: [] as $match) {
                    $candidates[] = $match;
                }
            }
        } elseif ($baseName !== '' && $baseName !== $file) {
            foreach (['assets/uploads/vouchers', 'uploads/vouchers'] as $voucherRoot) {
                $push($voucherRoot . '/' . $baseName);
                $globPattern = $root . '/' . $voucherRoot . '/*/' . $baseName;
                foreach (glob($globPattern) ?: [] as $match) {
                    $candidates[] = $match;
                }
            }
        }

        $seen = [];
        foreach ($candidates as $candidate) {
            $candidate = str_replace('\\', '/', $candidate);
            if (isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('proxyPdfCompanyIdFromRequest')) {
    function proxyPdfCompanyIdFromRequest(): int
    {
        $companyId = (int) ($_GET['company_id'] ?? ($_SESSION['company_id'] ?? 0));
        if ($companyId > 0) {
            return $companyId;
        }

        $slug = strtolower(trim((string) ($_GET['company_slug'] ?? ($_SESSION['company_slug'] ?? ''))));
        if ($slug === 'roadmaster') {
            return 2;
        }
        if ($slug === 'ultimate') {
            return 1;
        }

        $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (strpos($uri, '/roadmaster/') !== false) {
            return 2;
        }
        if (strpos($uri, '/ultimate/') !== false) {
            return 1;
        }

        return 0;
    }
}

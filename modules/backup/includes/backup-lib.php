<?php

declare(strict_types=1);

function backupDeskBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        require_once __DIR__ . '/backup-engine.php';
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    return $pdo;
}

function backupDeskRequireAccess(): void
{
    backupDeskBootstrap();
    requireLogin();
}

function backupDeskWebBasePath(): string
{
    // Always serve assets/API from the real modules/backup tree (not tenant wrappers).
    if (function_exists('app_url')) {
        return rtrim(app_url('/modules/backup'), '/');
    }

    return '/modules/backup';
}

function backupDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return backupDeskWebBasePath() . '/' . $relativePath;
}

/**
 * @return array{assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function backupDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $assetBase = backupDeskPublicUrl('frontend/dist/assets/');
    $apiUrl = backupDeskPublicUrl('api/index.php');
    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function backupDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('renderSystemFontHeadMarkup')) {
            ob_start();
            renderSystemFontHeadMarkup();
            $fontMarkup = ob_get_clean();
            if (is_string($fontMarkup) && $fontMarkup !== '') {
                $parts[] = trim($fontMarkup);
            }
        }
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    return implode("\n    ", $parts);
}

function backupDeskFetchPayload(PDO $pdo): array
{
    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];

    return [
        'company' => [
            'id' => $companyId,
            'name' => (string) ($company['company_name'] ?? ($_SESSION['company_name'] ?? 'Company')),
            'slug' => (string) ($company['company_slug'] ?? ($_SESSION['company_slug'] ?? '')),
            'db_name' => (string) ($company['db_name'] ?? ''),
        ],
        'backups' => backupEngineList($companyId),
        'capabilities' => [
            'zip' => class_exists('ZipArchive'),
            'mysqldump' => backupEngineFindMysqldump() !== null,
        ],
        'includes' => [
            'Full tenant database export (SQL)',
            'All files under company storage (uploads, documents, signatures, etc.)',
            'All system document folders (vouchers, expenses, purchases, deliveries, revenue, trips, etc.)',
            'All database-referenced attachments (Documents desk sources)',
            'Backup manifest with company metadata',
        ],
        'links' => [
            'page' => function_exists('company_url')
                ? company_url('modules/backup/index') . '?module=backup'
                : backupDeskPublicUrl('index.php') . '?module=backup',
            'api' => backupDeskPublicUrl('api/index.php'),
            'modules' => function_exists('company_url') ? company_url('select-module') : '/select-module.php',
        ],
    ];
}

<?php

declare(strict_types=1);

/**
 * Asset/URL helpers for the System Font React app.
 * Built with Vite into employee/personalization/system-font-ui/frontend/dist.
 */

function systemFontUiWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/employee/personalization'), '/');
    }
    return '/employee/personalization';
}

function systemFontUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return systemFontUiWebBasePath() . '/system-font-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl:string}|null
 */
function systemFontUiLoadReactAssets(): ?array
{
    $uiDir = __DIR__ . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => systemFontUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        'apiUrl' => systemFontUiPublicUrl('api/index.php'),
    ];
}

/**
 * @return array<string, mixed>
 */
function systemFontUiBuildInitialConfig(int $userId): array
{
    $fontCatalog = getSystemFontCatalog();
    $companyFontKey = getSystemFontKey();
    $userFontKey = getUserFontKey($userId);
    $effectiveFontKey = getEffectiveFontKey($userId);
    $effectiveFontDef = getSystemFontDefinition($effectiveFontKey);
    $companyFontDef = getSystemFontDefinition($companyFontKey);

    $fonts = [];
    foreach ($fontCatalog as $fontId => $fontMeta) {
        $fonts[] = [
            'id' => $fontId,
            'label' => (string) ($fontMeta['label'] ?? $fontId),
            'stack' => (string) ($fontMeta['stack'] ?? ''),
            'google' => (string) ($fontMeta['google'] ?? ''),
        ];
    }

    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'personalization';
    $backUrl = function_exists('company_url')
        ? company_url('employee/personalization/index.php', null) . '?module=' . rawurlencode($module)
        : app_url('/employee/personalization/index.php?module=' . rawurlencode($module));

    return [
        'selectedKey' => $userFontKey ?? '',
        'effectiveKey' => $effectiveFontKey,
        'isPersonalChoice' => $userFontKey !== null,
        'companyFont' => [
            'key' => $companyFontKey,
            'label' => (string) ($companyFontDef['label'] ?? 'Poppins'),
            'stack' => (string) ($companyFontDef['stack'] ?? "'Poppins', sans-serif"),
        ],
        'effectiveFont' => [
            'key' => $effectiveFontKey,
            'label' => (string) ($effectiveFontDef['label'] ?? 'Poppins'),
            'stack' => (string) ($effectiveFontDef['stack'] ?? "'Poppins', sans-serif"),
        ],
        'fonts' => $fonts,
        'backUrl' => $backUrl,
        'module' => $module,
    ];
}

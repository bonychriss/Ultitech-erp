<?php
/**
 * Bank Reconciliation - shared helpers for React shell.
 */
declare(strict_types=1);

function rcRequireAccess(): void
{
    require_once dirname(__DIR__) . '/../includes/functions.php';
    requireLogin();
}

function rcDeskShellScriptSuffix(): string
{
    return '/reconciliation.php';
}

function rcDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $suffix = rcDeskShellScriptSuffix();

    if ($script !== '' && substr($script, -strlen($suffix)) === $suffix) {
        return rtrim(dirname($script), '/') . '/' . $relativePath;
    }

    // Ultimate/company rewrite may omit ".php".
    if ($script !== '' && preg_match('#/accounting(?:/reconciliation(?:\.php)?)?$#', $script) === 1) {
        $base = preg_replace('#/accounting(?:/reconciliation(?:\.php)?)?$#', '/accounting', $script);
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/' . $relativePath;
        }
    }

    if (function_exists('app_url')) {
        return app_url('accounting/' . $relativePath);
    }

    return $relativePath;
}

function rcPreserveQueryKeys(array $base = []): array
{
    $out = $base;
    foreach (['module', 'company_slug'] as $key) {
        if (!empty($_GET[$key])) {
            $out[$key] = (string) $_GET[$key];
        }
    }
    return $out;
}

function rcBuildQuery(array $extra = []): string
{
    $qs = rcPreserveQueryKeys($_GET ?: []);
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($qs[$key]);
        } else {
            $qs[$key] = $value;
        }
    }
    return $qs === [] ? '' : ('?' . http_build_query($qs));
}

/**
 * @return array{backUrl: string, module: string}
 */
function rcBuildBootPayload(): array
{
    $module = trim((string) ($_GET['module'] ?? 'balances'));
    $qs = rcBuildQuery();

    $backUrl = '../modules/balances/transactions.php' . $qs;
    if ($module !== 'balances') {
        $backUrl = '../modules/balances/index.php' . $qs;
    }

    return [
        'backUrl' => $backUrl,
        'module' => $module,
    ];
}

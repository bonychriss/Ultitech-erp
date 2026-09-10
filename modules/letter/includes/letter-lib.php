<?php
declare(strict_types=1);

/**
 * Resolve the active company PDO for Letter (tenant or shared).
 */
function letterResolvePdo(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $control = $GLOBALS['control_pdo'] ?? null;
    if ($control instanceof PDO) {
        return $control;
    }

    $configPath = dirname(__DIR__, 3) . '/includes/config.php';
    if (is_file($configPath)) {
        require_once $configPath;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $control = $GLOBALS['control_pdo'] ?? null;
    return $control instanceof PDO ? $control : null;
}

/**
 * Active company users available for share / send-to-inbox.
 *
 * @return list<array{id:int,name:string,email:string,phone:string,department:string}>
 */
function letterLoadShareEmployees(int $companyId, int $excludeUserId = 0): array
{
    $pdo = letterResolvePdo();
    if (!($pdo instanceof PDO)) {
        return [];
    }

    $isTenantDb = defined('IS_TENANT_DB') && IS_TENANT_DB;
    $selects = [
        'SELECT id, full_name, email, phone, whatsapp_number, department FROM users',
        'SELECT id, full_name, email, phone, department FROM users',
        'SELECT id, full_name, email, department FROM users',
        'SELECT id, full_name, email FROM users',
    ];

    $rows = [];
    foreach ($selects as $select) {
        $queries = [];
        if ($companyId > 0 && !$isTenantDb) {
            $queries[] = [
                $select . ' WHERE company_id = ? AND COALESCE(is_active, 1) = 1 ORDER BY full_name ASC LIMIT 500',
                [$companyId],
            ];
        }
        // Tenant DBs (and fallback) list everyone in the connected database.
        $queries[] = [
            $select . ' WHERE COALESCE(is_active, 1) = 1 ORDER BY full_name ASC LIMIT 500',
            [],
        ];

        foreach ($queries as [$sql, $params]) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($fetched || $params === []) {
                    $rows = $fetched;
                    break 2;
                }
            } catch (Throwable $inner) {
                // try next select / query shape
            }
        }
    }

    $employees = [];
    foreach ($rows as $row) {
        $empId = (int) ($row['id'] ?? 0);
        if ($empId <= 0 || ($excludeUserId > 0 && $empId === $excludeUserId)) {
            continue;
        }
        $phone = trim((string) ($row['phone'] ?? ''));
        if ($phone === '') {
            $phone = trim((string) ($row['whatsapp_number'] ?? ''));
        }
        $employees[] = [
            'id' => $empId,
            'name' => trim((string) ($row['full_name'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'phone' => $phone,
            'department' => trim((string) ($row['department'] ?? '')),
        ];
    }

    return $employees;
}

/**
 * Build branding + user defaults for the Letter React shell.
 *
 * @return array<string,mixed>
 */
function letterBuildClientCfg(array $erp = []): array
{
    $companyId = (int) ($erp['company_id'] ?? ($_SESSION['company_id'] ?? 0));
    $info = function_exists('getCompanyInfo') ? getCompanyInfo($companyId ?: null) : [];
    $settings = [];
    try {
        $pdo = letterResolvePdo();
        if ($pdo instanceof PDO && $companyId > 0 && function_exists('fetchCompanySettingsMap')) {
            $settings = fetchCompanySettingsMap($pdo, $companyId);
        }
    } catch (Throwable $e) {
        $settings = [];
    }

    $companyName = trim((string) ($settings['company_name'] ?? $info['company_name'] ?? $_SESSION['company_name'] ?? 'Company Name'));
    if ($companyName === '') {
        $companyName = 'Company Name';
    }

    $logoUrl = '';
    if (function_exists('resolveCompanyBrandingLogoUrl')) {
        $logoUrl = (string) resolveCompanyBrandingLogoUrl($companyId ?: null);
    }
    if ($logoUrl === '' && function_exists('getCompanyLogoUrl')) {
        $logoUrl = (string) getCompanyLogoUrl($companyId ?: null);
    }

    $phone = trim((string) ($settings['company_phone'] ?? $settings['phone'] ?? ''));
    $email = trim((string) ($settings['company_email'] ?? $settings['email'] ?? ''));
    $website = trim((string) ($settings['company_website'] ?? $settings['website'] ?? ''));
    $address = trim((string) ($settings['company_address'] ?? $settings['address'] ?? ''));
    $tagline = trim((string) ($settings['company_tagline'] ?? $settings['tagline'] ?? ''));

    $slug = strtolower(trim((string) ($erp['company_slug'] ?? ($_SESSION['company_slug'] ?? ''))));
    if (!function_exists('letterheadResolveForCompany')) {
        require_once dirname(__DIR__, 3) . '/includes/letterhead.php';
    }
    $letterhead = letterheadResolveForCompany($slug, $companyName);
    $accent = (string) ($letterhead['accentColor'] ?? '#FBC51C');
    $defaults = is_array($letterhead['defaults'] ?? null) ? $letterhead['defaults'] : [];

    $userId = (int) ($erp['user_id'] ?? ($_SESSION['user_id'] ?? 0));
    $userName = trim((string) ($erp['full_name'] ?? $_SESSION['full_name'] ?? ''));
    $userTitle = trim((string) ($erp['department'] ?? $_SESSION['department'] ?? $_SESSION['job_title'] ?? ''));
    if ($phone === '') {
        $phone = (string) ($defaults['phone'] ?? '+255 755 282 861');
    }
    if ($email === '') {
        $email = (string) ($defaults['email'] ?? 'sales@ultimate.co.tz');
    }
    if ($website === '') {
        $website = (string) ($defaults['website'] ?? 'www.ultimate.co.tz');
    }
    if ($address === '') {
        $address = (string) ($defaults['address'] ?? 'House No.03, Manyara Street, Mikocheni B. P.O. Box 78004, Dar Es Salaam, TZ');
    }
    if ($tagline === '' && !empty($defaults['tagline'])) {
        $tagline = (string) $defaults['tagline'];
    }
    if (($companyName === '' || $companyName === 'Company Name') && !empty($defaults['companyName'])) {
        $companyName = (string) $defaults['companyName'];
    }

    $isUltimate = !empty($letterhead['isUltimate']);
    $isRoadmaster = !empty($letterhead['isRoadmaster']);
    $showStamp = !empty($letterhead['showStamp']);
    $stampPreviewUrl = (string) ($letterhead['stampUrl'] ?? '');
    $letterheadHeaderUrl = (string) ($letterhead['headerUrl'] ?? '');
    $letterheadFooterUrl = (string) ($letterhead['footerUrl'] ?? '');

    $signatureUrl = '';
    if ($userId > 0 && function_exists('getUserSignaturePathById')) {
        $sigPath = getUserSignaturePathById($userId);
        if (is_string($sigPath) && $sigPath !== '') {
            if (function_exists('mediaUrlFromPath')) {
                $signatureUrl = (string) mediaUrlFromPath($sigPath);
            } elseif (function_exists('app_url')) {
                $signatureUrl = rtrim((string) app_url('/' . ltrim($sigPath, '/')), '/');
            } else {
                $signatureUrl = '/' . ltrim($sigPath, '/');
            }
            if ($signatureUrl !== '') {
                $sigFs = dirname(__DIR__, 3) . '/' . ltrim(str_replace('\\', '/', $sigPath), '/');
                $sigVer = @filemtime($sigFs) ?: time();
                $signatureUrl .= (strpos($signatureUrl, '?') === false ? '?' : '&') . 'v=' . (int) $sigVer;
            }
        }
    }

    $editorUrl = '';
    $listUrl = '';
    $composeUrl = '';
    $inboxUrl = '';
    $emptyAnimationUrl = '';
    if (function_exists('app_url')) {
        $editorUrl = rtrim((string) app_url('/3D/dist/'), '/') . '/';
        $emptyAnimationUrl = rtrim((string) app_url('/assets/animations/nothing.lottie'), '/');
    }
    if ($emptyAnimationUrl === '') {
        $emptyAnimationUrl = '/assets/animations/nothing.lottie';
    }
    if ($slug !== '' && function_exists('company_url')) {
        $listUrl = company_url('modules/letter/index.php', $slug) . '?module=letter';
        $composeUrl = company_url('modules/letter/compose.php', $slug) . '?module=letter';
        $inboxUrl = company_url('modules/letter/inbox.php', $slug) . '?module=letter';
    } elseif (function_exists('app_url')) {
        $listUrl = rtrim((string) app_url('/modules/letter/index.php'), '/') . '?module=letter';
        $composeUrl = rtrim((string) app_url('/modules/letter/compose.php'), '/') . '?module=letter';
        $inboxUrl = rtrim((string) app_url('/modules/letter/inbox.php'), '/') . '?module=letter';
    }

    $employees = [];
    try {
        $employees = letterLoadShareEmployees($companyId, $userId);
    } catch (Throwable $e) {
        $employees = [];
    }

    return [
        'module' => 'letter',
        'engine' => 'erp-laravel Domains/Letter',
        'companySlug' => $slug,
        'backUrl' => (string) ($erp['back_url'] ?? ''),
        'listUrl' => $listUrl,
        'composeUrl' => $composeUrl,
        'inboxUrl' => $inboxUrl,
        'emptyAnimationUrl' => $emptyAnimationUrl,
        'isUltimateCompany' => $isUltimate,
        'isRoadmasterCompany' => $isRoadmaster,
        'showUltimateStamp' => $isUltimate && $showStamp,
        'showStamp' => $showStamp,
        'stampEditorUrl' => $editorUrl,
        'stampPreviewUrl' => $stampPreviewUrl,
        'letterheadHeaderUrl' => $letterheadHeaderUrl,
        'letterheadFooterUrl' => $letterheadFooterUrl,
        'signatureUrl' => $signatureUrl,
        'employees' => $employees,
        'branding' => [
            'companyName' => $companyName,
            'tagline' => $tagline !== '' ? $tagline : 'Your tagline here',
            'logoUrl' => $logoUrl,
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'address' => $address,
            'accentColor' => $accent,
            'accentShades' => $isRoadmaster
                ? ['#5BB8B5', '#008784', '#066B68', '#0D2A4A']
                : ['#F7E08A', '#E6B800', '#C9A227', '#8B6914'],
        ],
        'user' => [
            'id' => $userId,
            'name' => $userName !== '' ? $userName : 'Your Name',
            'title' => $userTitle !== '' ? $userTitle : 'Title',
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'address' => $address,
            'signatureUrl' => $signatureUrl,
        ],
    ];
}

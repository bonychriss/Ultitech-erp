<?php
declare(strict_types=1);

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
        global $pdo;
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
    // Letter module uses Ultimate yellow letterhead theme.
    $accent = '#E6B800';

    $userName = trim((string) ($erp['full_name'] ?? $_SESSION['full_name'] ?? ''));
    $userTitle = trim((string) ($erp['department'] ?? $_SESSION['department'] ?? $_SESSION['job_title'] ?? ''));

    return [
        'module' => 'letter',
        'engine' => 'erp-laravel Domains/Letter',
        'companySlug' => (string) ($erp['company_slug'] ?? ''),
        'backUrl' => (string) ($erp['back_url'] ?? ''),
        'branding' => [
            'companyName' => $companyName,
            'tagline' => $tagline !== '' ? $tagline : 'Your tagline here',
            'logoUrl' => $logoUrl,
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'address' => $address,
            'accentColor' => $accent,
            'accentShades' => ['#F7E08A', '#E6B800', '#C9A227', '#8B6914'],
        ],
        'user' => [
            'name' => $userName !== '' ? $userName : 'Your Name',
            'title' => $userTitle !== '' ? $userTitle : 'Title',
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'address' => $address,
        ],
    ];
}

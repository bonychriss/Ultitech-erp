<?php
declare(strict_types=1);

/**
 * Resolve company letterhead banner / stamp asset URLs.
 *
 * @return array{
 *   companyKey:string,
 *   isUltimate:bool,
 *   isRoadmaster:bool,
 *   accentColor:string,
 *   headerUrl:string,
 *   footerUrl:string,
 *   stampUrl:string,
 *   showStamp:bool,
 *   defaults:array{phone:string,email:string,website:string,address:string,companyName:string,tagline:string}
 * }
 */
function letterheadResolveForCompany(?string $slug = null, ?string $companyName = null): array
{
    $slug = strtolower(trim((string) ($slug ?? ($_SESSION['company_slug'] ?? ''))));
    $name = trim((string) ($companyName ?? ($_SESSION['company_name'] ?? '')));

    $isUltimate = ($slug === 'ultimate') || (stripos($name, 'ultimate general') !== false);
    $isRoadmaster = ($slug === 'roadmaster')
        || (stripos($name, 'roadmaster') !== false)
        || (function_exists('isRoadmaster') && isRoadmaster());

    $appRoot = dirname(__DIR__);
    $urlFor = static function (string $rel) use ($appRoot): string {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $fs = $appRoot . '/' . $rel;
        if (!is_file($fs)) {
            return '';
        }
        $url = function_exists('app_url')
            ? rtrim((string) app_url('/' . $rel), '/')
            : ('/' . $rel);
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . (int) filemtime($fs);
    };

    if ($isRoadmaster) {
        $headerUrl = $urlFor('letterhead/roadmaster/header.png');
        $footerUrl = $urlFor('letterhead/roadmaster/footer.png');
        $stampUrl = $urlFor('letterhead/stamps/roadmaster-stamp.png');
        if ($stampUrl === '') {
            $stampUrl = $urlFor('assets/images/roadmaster-logo.png');
        }
        return [
            'companyKey' => 'roadmaster',
            'isUltimate' => false,
            'isRoadmaster' => true,
            'accentColor' => '#B7312C',
            'headerUrl' => $headerUrl,
            'footerUrl' => $footerUrl,
            'stampUrl' => $stampUrl,
            'showStamp' => ($stampUrl !== ''),
            'defaults' => [
                'companyName' => 'ROADMASTER SPARES LIMITED',
                'tagline' => 'PARTS THAT LAST, BACKED BY MASTERS',
                'phone' => '+255 754 000 000',
                'email' => 'sales@roadmasterspares.com',
                'website' => 'www.roadmasterspares.com',
                'address' => 'Dar Es Salaam, Tanzania',
            ],
        ];
    }

    // Ultimate (default letterhead template used by Ultimate and as fallback).
    $headerUrl = $urlFor('letterhead/header.png');
    $footerUrl = $urlFor('letterhead/footer.png');
    $stampUrl = $urlFor('letterhead/stamps/ultimate-stamp-white.png');
    if ($stampUrl === '') {
        $stampUrl = $urlFor('modules/letter/frontend/src/assets/ultimate-stamp.png');
    }

    return [
        'companyKey' => $isUltimate ? 'ultimate' : 'default',
        'isUltimate' => $isUltimate,
        'isRoadmaster' => false,
        'accentColor' => '#FBC51C',
        'headerUrl' => $headerUrl,
        'footerUrl' => $footerUrl,
        'stampUrl' => $stampUrl,
        'showStamp' => $isUltimate && $stampUrl !== '',
        'defaults' => [
            'companyName' => 'ULTIMATE GENERAL TRADING',
            'tagline' => 'Your tagline here',
            'phone' => '+255 755 282 861',
            'email' => 'sales@ultimate.co.tz',
            'website' => 'www.ultimate.co.tz',
            'address' => 'House No.14, Atisoko Street, Mikocheni B. P.O. Box 78004, Dar Es Salaam, TZ',
        ],
    ];
}

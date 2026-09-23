<?php

declare(strict_types=1);

/**
 * WhatsApp settings React UI helpers + get/save payload.
 */

require_once dirname(__DIR__, 2) . '/includes/functions.php';

function whatsappSettingsUiWebBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        if (preg_match('#^(.*?)/[A-Za-z0-9-]+/admin$#', $dir, $m)) {
            return rtrim($m[1] . '/admin', '/') ?: '/admin';
        }
        if (substr($dir, -6) === '/admin') {
            return $dir;
        }
    }
    return '/admin';
}

function whatsappSettingsUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return whatsappSettingsUiWebBasePath() . '/whatsapp-settings-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function whatsappSettingsUiLoadReactAssets(): ?array
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
        'assetBase' => whatsappSettingsUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function whatsappSettingsUiShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];
    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 2) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }
    return implode("\n    ", $parts);
}

function whatsappSettingsUiRequireAdmin(): void
{
    requireAdmin();
    ensureSystemSettingsSchema();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'settings';
}

function whatsappSettingsUiPdo(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }
    return $pdo;
}

function whatsappSettingsUiGetSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false || $val === null ? $default : (string) $val;
    } catch (Throwable $e) {
        return $default;
    }
}

function whatsappSettingsUiSetSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([$key, $value]);
}

/**
 * @return array{form:array<string,mixed>,links:array<string,string>,meta:array<string,mixed>}
 */
function whatsappSettingsUiGetPayload(PDO $pdo): array
{
    $token = whatsappSettingsUiGetSetting($pdo, 'whatsapp_access_token');
    $kapsoKey = whatsappSettingsUiGetSetting($pdo, 'whatsapp_kapso_api_key');
    $provider = strtolower(trim(whatsappSettingsUiGetSetting($pdo, 'whatsapp_provider', 'meta')));
    if ($provider !== 'kapso') {
        $provider = 'meta';
    }
    $phoneNumberId = whatsappSettingsUiGetSetting($pdo, 'whatsapp_phone_number_id');
    $configured = $phoneNumberId !== '' && (
        ($provider === 'kapso' && $kapsoKey !== '')
        || ($provider === 'meta' && $token !== '')
    );
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    $backUrl = $slug !== ''
        ? company_url('admin/settings.php?module=settings', $slug)
        : (function_exists('app_url') ? app_url('/admin/settings.php?module=settings') : '/admin/settings.php?module=settings');
    $botUrl = function_exists('app_url')
        ? rtrim((string) app_url('/whatsapp/frontend/web/'), '/') . '/'
        : '/public_html/whatsapp/frontend/web/';

    return [
        'form' => [
            'displayPhone' => whatsappSettingsUiGetSetting($pdo, 'whatsapp_display_phone'),
            'businessAccountId' => whatsappSettingsUiGetSetting($pdo, 'whatsapp_business_account_id'),
            'provider' => $provider,
            'phoneNumberId' => $phoneNumberId,
            'accessToken' => '',
            'accessTokenSet' => $token !== '',
            'accessTokenMasked' => $token !== '' ? (str_repeat('*', max(0, strlen($token) - 4)) . substr($token, -4)) : '',
            'kapsoApiKey' => '',
            'kapsoApiKeySet' => $kapsoKey !== '',
            'kapsoApiKeyMasked' => $kapsoKey !== '' ? (str_repeat('*', max(0, strlen($kapsoKey) - 4)) . substr($kapsoKey, -4)) : '',
            'kapsoBaseUrl' => whatsappSettingsUiGetSetting($pdo, 'whatsapp_kapso_base_url', 'https://api.kapso.ai/meta/whatsapp'),
            'webhookVerifyToken' => (static function () use ($pdo): string {
                $token = whatsappSettingsUiGetSetting($pdo, 'whatsapp_webhook_verify_token');
                if ($token === '') {
                    $token = bin2hex(random_bytes(12));
                    whatsappSettingsUiSetSetting($pdo, 'whatsapp_webhook_verify_token', $token);
                }
                return $token;
            })(),
            'groupLink' => whatsappSettingsUiGetSetting($pdo, 'whatsapp_group_link'),
            'autoReplyEnabled' => whatsappSettingsUiGetSetting($pdo, 'whatsapp_auto_reply_enabled', '0') === '1',
            'autoReplyText' => whatsappSettingsUiGetSetting($pdo, 'whatsapp_auto_reply_text', 'Thanks - our team will get back to you shortly.'),
            'autoSendVouchers' => whatsappSettingsUiGetSetting($pdo, 'whatsapp_auto_send_vouchers', '0') === '1',
        ],
        'links' => [
            'backUrl' => $backUrl,
            'botUrl' => $botUrl,
            'webhookUrl' => rtrim($botUrl, '/') . '/index.php/api/webhook',
            'metaDocs' => 'https://developers.facebook.com/docs/whatsapp/cloud-api',
            'kapsoDocs' => 'https://docs.kapso.ai/docs/whatsapp/send-messages/text',
        ],
        'meta' => [
            'configured' => $configured,
            'companySlug' => $slug,
        ],
    ];
}

/**
 * @param array<string,mixed> $input
 * @return array{form:array<string,mixed>,links:array<string,string>,meta:array<string,mixed>}
 */
function whatsappSettingsUiSavePayload(PDO $pdo, array $input): array
{
    $map = [
        'displayPhone' => 'whatsapp_display_phone',
        'businessAccountId' => 'whatsapp_business_account_id',
        'phoneNumberId' => 'whatsapp_phone_number_id',
        'webhookVerifyToken' => 'whatsapp_webhook_verify_token',
        'groupLink' => 'whatsapp_group_link',
        'autoReplyText' => 'whatsapp_auto_reply_text',
        'kapsoBaseUrl' => 'whatsapp_kapso_base_url',
    ];
    foreach ($map as $field => $key) {
        if (array_key_exists($field, $input)) {
            whatsappSettingsUiSetSetting($pdo, $key, trim((string) $input[$field]));
        }
    }
    if (array_key_exists('provider', $input)) {
        $provider = strtolower(trim((string) $input['provider']));
        whatsappSettingsUiSetSetting($pdo, 'whatsapp_provider', $provider === 'kapso' ? 'kapso' : 'meta');
    }
    if (array_key_exists('autoReplyEnabled', $input)) {
        whatsappSettingsUiSetSetting(
            $pdo,
            'whatsapp_auto_reply_enabled',
            !empty($input['autoReplyEnabled']) ? '1' : '0'
        );
    }
    if (array_key_exists('autoSendVouchers', $input)) {
        whatsappSettingsUiSetSetting(
            $pdo,
            'whatsapp_auto_send_vouchers',
            !empty($input['autoSendVouchers']) ? '1' : '0'
        );
    }
    if (array_key_exists('kapsoApiKey', $input)) {
        $kapsoKey = trim((string) $input['kapsoApiKey']);
        if ($kapsoKey !== '') {
            whatsappSettingsUiSetSetting($pdo, 'whatsapp_kapso_api_key', $kapsoKey);
        }
    }
    if (array_key_exists('accessToken', $input)) {
        $token = trim((string) $input['accessToken']);
        if ($token !== '') {
            whatsappSettingsUiSetSetting($pdo, 'whatsapp_access_token', $token);
        }
    }

    return whatsappSettingsUiGetPayload($pdo);
}

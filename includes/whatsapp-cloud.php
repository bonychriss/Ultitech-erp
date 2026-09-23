<?php

declare(strict_types=1);

/**
 * WhatsApp Cloud API helper (Meta Graph or Kapso proxy).
 * Credentials live in system_settings (Admin ? WhatsApp settings).
 */

if (!function_exists('whatsappCloudGetSetting')) {
    function whatsappCloudGetSetting(string $key, string $default = ''): string
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return $default;
        }
        try {
            if (function_exists('ensureSystemSettingsSchema')) {
                ensureSystemSettingsSchema();
            }
            $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val === false || $val === null ? $default : (string) $val;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('whatsappCloudSetSetting')) {
    function whatsappCloudSetSetting(string $key, string $value): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }
        if (function_exists('ensureSystemSettingsSchema')) {
            ensureSystemSettingsSchema();
        }
        $stmt = $pdo->prepare('
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ');
        $stmt->execute([$key, $value]);
    }
}

if (!function_exists('whatsappCloudProvider')) {
    /**
     * @return 'kapso'|'meta'
     */
    function whatsappCloudProvider(): string
    {
        $p = strtolower(trim(whatsappCloudGetSetting('whatsapp_provider', 'meta')));
        return $p === 'kapso' ? 'kapso' : 'meta';
    }
}

if (!function_exists('whatsappCloudIsConfigured')) {
    function whatsappCloudIsConfigured(): bool
    {
        $phoneNumberId = trim(whatsappCloudGetSetting('whatsapp_phone_number_id'));
        if ($phoneNumberId === '') {
            return false;
        }
        if (whatsappCloudProvider() === 'kapso') {
            return trim(whatsappCloudGetSetting('whatsapp_kapso_api_key')) !== '';
        }
        return trim(whatsappCloudGetSetting('whatsapp_access_token')) !== '';
    }
}

if (!function_exists('whatsappCloudAutoSendEnabled')) {
    function whatsappCloudAutoSendEnabled(): bool
    {
        return whatsappCloudGetSetting('whatsapp_auto_send_vouchers', '0') === '1'
            && whatsappCloudIsConfigured();
    }
}

if (!function_exists('whatsappCloudSendText')) {
    /**
     * Send a text message via Meta Graph or Kapso.
     *
     * @return array{ok:bool, message_id?:string, error?:string, raw?:mixed, fallback_link?:string}
     */
    function whatsappCloudSendText(string $toPhone, string $body): array
    {
        $to = function_exists('cleanWhatsAppNumber')
            ? cleanWhatsAppNumber($toPhone)
            : preg_replace('/\D+/', '', $toPhone);
        $to = ltrim((string) $to, '+');
        if ($to === '') {
            return ['ok' => false, 'error' => 'Invalid recipient phone number.'];
        }

        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Message body is empty.'];
        }

        if (!whatsappCloudIsConfigured()) {
            $fallback = function_exists('getWhatsAppLink')
                ? getWhatsAppLink($to, $body)
                : ('https://wa.me/' . $to . '?text=' . rawurlencode($body));
            return [
                'ok' => false,
                'error' => 'WhatsApp Cloud API is not configured. Add Kapso API key or Meta token in WhatsApp settings.',
                'fallback_link' => $fallback,
            ];
        }

        $phoneNumberId = trim(whatsappCloudGetSetting('whatsapp_phone_number_id'));
        $provider = whatsappCloudProvider();
        $version = trim(whatsappCloudGetSetting('whatsapp_graph_version', 'v21.0'));
        if ($version === '') {
            $version = 'v21.0';
        }

        if ($provider === 'kapso') {
            $base = rtrim(whatsappCloudGetSetting(
                'whatsapp_kapso_base_url',
                'https://api.kapso.ai/meta/whatsapp'
            ), '/');
            // Kapso docs use v24.0; keep configurable.
            $kapsoVersion = trim(whatsappCloudGetSetting('whatsapp_kapso_graph_version', 'v24.0'));
            if ($kapsoVersion === '') {
                $kapsoVersion = 'v24.0';
            }
            $url = $base . '/' . rawurlencode($kapsoVersion) . '/' . rawurlencode($phoneNumberId) . '/messages';
            $headers = [
                'Content-Type: application/json',
                'X-API-Key: ' . whatsappCloudGetSetting('whatsapp_kapso_api_key'),
            ];
        } else {
            $url = sprintf(
                'https://graph.facebook.com/%s/%s/messages',
                rawurlencode($version),
                rawurlencode($phoneNumberId)
            );
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . whatsappCloudGetSetting('whatsapp_access_token'),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ],
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not start HTTP request.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => 'WhatsApp request failed: ' . $err];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }

        if ($status < 200 || $status >= 300) {
            $apiError = '';
            if (isset($data['error']['message'])) {
                $apiError = (string) $data['error']['message'];
            } elseif (isset($data['message'])) {
                $apiError = (string) $data['message'];
            }
            return [
                'ok' => false,
                'error' => $apiError !== '' ? $apiError : ('WhatsApp API HTTP ' . $status),
                'raw' => $data,
            ];
        }

        $messageId = $data['messages'][0]['id'] ?? null;
        return [
            'ok' => true,
            'message_id' => $messageId ? (string) $messageId : null,
            'raw' => $data,
        ];
    }
}

if (!function_exists('sendVoucherWhatsAppNotify')) {
    /**
     * Resolve next approval target and send (or return wa.me fallback).
     *
     * @param array<string,mixed> $voucher
     * @return array{ok:bool, sent?:bool, role?:string, name?:string, error?:string, fallback_link?:string, message_id?:string}
     */
    function sendVoucherWhatsAppNotify(array $voucher, string $currentUserFullName): array
    {
        if (!function_exists('getVoucherNotificationTarget')) {
            return ['ok' => false, 'error' => 'Notification helper unavailable.'];
        }
        $target = getVoucherNotificationTarget($voucher, $currentUserFullName);
        if (!$target || empty($target['number']) || empty($target['message'])) {
            return ['ok' => false, 'error' => 'No WhatsApp recipient found for the next approval step.'];
        }

        $result = whatsappCloudSendText((string) $target['number'], (string) $target['message']);
        if (!empty($result['ok'])) {
            return [
                'ok' => true,
                'sent' => true,
                'role' => (string) ($target['role'] ?? ''),
                'name' => (string) ($target['name'] ?? ''),
                'message_id' => $result['message_id'] ?? null,
            ];
        }

        $fallback = $result['fallback_link'] ?? ($target['link'] ?? null);
        return [
            'ok' => false,
            'sent' => false,
            'role' => (string) ($target['role'] ?? ''),
            'name' => (string) ($target['name'] ?? ''),
            'error' => (string) ($result['error'] ?? 'Send failed'),
            'fallback_link' => $fallback ? (string) $fallback : null,
        ];
    }
}

if (!function_exists('maybeAutoSendVoucherWhatsApp')) {
    /**
     * Best-effort auto-send when enabled and Cloud API is configured.
     *
     * @param array<string,mixed>|int $voucherOrId
     */
    function maybeAutoSendVoucherWhatsApp($voucherOrId, ?string $currentUserFullName = null): void
    {
        if (!whatsappCloudAutoSendEnabled()) {
            return;
        }
        global $pdo;
        try {
            $voucher = null;
            if (is_array($voucherOrId)) {
                $voucher = $voucherOrId;
            } elseif ($pdo instanceof PDO) {
                $id = (int) $voucherOrId;
                if ($id <= 0) {
                    return;
                }
                $stmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ? LIMIT 1');
                $stmt->execute([$id]);
                $voucher = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$voucher) {
                return;
            }
            $who = $currentUserFullName
                ?? (string) ($_SESSION['full_name'] ?? '')
                ?? (string) ($voucher['prepared_by'] ?? '');
            $result = sendVoucherWhatsAppNotify($voucher, $who);
            if (empty($result['ok']) && function_exists('app_log')) {
                app_log('maybeAutoSendVoucherWhatsApp: ' . ($result['error'] ?? 'failed'));
            }
        } catch (Throwable $e) {
            if (function_exists('app_log')) {
                app_log('maybeAutoSendVoucherWhatsApp exception: ' . $e->getMessage());
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace common\services;

use common\models\WaContact;
use common\models\WaMessage;
use common\models\WaSetting;
use Yii;

/**
 * Meta WhatsApp Cloud API client.
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api
 */
class WhatsAppCloudService
{
    public function __construct(
        private readonly WaSetting $setting,
    ) {
    }

    public static function forUser(int $userId): self
    {
        $setting = WaSetting::findOne(['user_id' => $userId]);
        if (!$setting) {
            $setting = new WaSetting([
                'user_id' => $userId,
                'webhook_verify_token' => Yii::$app->security->generateRandomString(24),
            ]);
            $setting->save(false);
        }
        return new self($setting);
    }

    public function getSetting(): WaSetting
    {
        return $this->setting;
    }

    /**
     * @return array{ok: bool, message_id?: string, error?: string, raw?: mixed}
     */
    public function sendText(string $toPhone, string $body): array
    {
        if (!$this->setting->isConfigured()) {
            return [
                'ok' => false,
                'error' => 'WhatsApp API is not configured. Add Phone Number ID and Access Token in Settings.',
            ];
        }

        $to = WaContact::normalizePhone($toPhone);
        if ($to === '') {
            return ['ok' => false, 'error' => 'Invalid phone number.'];
        }

        $version = (string) (Yii::$app->params['whatsapp.graphVersion'] ?? 'v21.0');
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $version,
            rawurlencode($this->setting->phone_number_id),
        );

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

        $response = $this->request('POST', $url, $payload);
        if (!$response['ok']) {
            return $response;
        }

        $data = $response['data'] ?? [];
        $messageId = $data['messages'][0]['id'] ?? null;
        if (!$messageId) {
            return [
                'ok' => false,
                'error' => 'WhatsApp API returned no message id.',
                'raw' => $data,
            ];
        }

        return [
            'ok' => true,
            'message_id' => (string) $messageId,
            'raw' => $data,
        ];
    }

    /**
     * Persist + send an outbound message.
     */
    public function sendAndLog(
        int $userId,
        string $phone,
        string $body,
        string $matter = 'general',
        ?int $contactId = null,
        ?int $campaignId = null,
    ): WaMessage {
        $message = new WaMessage([
            'user_id' => $userId,
            'contact_id' => $contactId,
            'campaign_id' => $campaignId,
            'direction' => WaMessage::DIR_OUT,
            'phone' => WaContact::normalizePhone($phone),
            'body' => $body,
            'status' => WaMessage::STATUS_PENDING,
            'matter' => $matter,
        ]);
        $message->save(false);

        $result = $this->sendText($phone, $body);
        if ($result['ok']) {
            $message->status = WaMessage::STATUS_SENT;
            $message->wa_message_id = $result['message_id'] ?? null;
            $message->error_message = null;
        } else {
            $message->status = WaMessage::STATUS_FAILED;
            $message->error_message = $result['error'] ?? 'Send failed';
        }
        $message->save(false);

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, data?: array, error?: string}
     */
    private function request(string $method, string $url, array $payload = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not init HTTP client.'];
        }

        $headers = [
            'Authorization: Bearer ' . (string) $this->setting->access_token,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_POSTFIELDS => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => 'Network error: ' . $error];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Invalid API response (HTTP ' . $status . ').'];
        }

        if ($status >= 400) {
            $apiError = $data['error']['message'] ?? ('HTTP ' . $status);
            return ['ok' => false, 'error' => (string) $apiError, 'data' => $data];
        }

        return ['ok' => true, 'data' => $data];
    }
}

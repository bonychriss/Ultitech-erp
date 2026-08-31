<?php

class Auth
{
    public static function requireApiKey(array $config): void
    {
        $expected = trim((string) ($config['api_key'] ?? ''));
        if ($expected === '' || $expected === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET') {
            JsonResponse::error('API key is not configured on this mail bridge.', 500);
        }

        $provided = self::extractApiKey();
        if ($provided === '' || !hash_equals($expected, $provided)) {
            JsonResponse::error('Unauthorized. Provide a valid X-Api-Key header.', 401);
        }
    }

    private static function extractApiKey(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : [];

        if (!empty($headers['x-api-key'])) {
            return trim((string) $headers['x-api-key']);
        }

        $auth = (string) ($headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        if (isset($_GET['api_key'])) {
            return trim((string) $_GET['api_key']);
        }

        return '';
    }
}

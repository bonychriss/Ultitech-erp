<?php

class Auth
{
    public static function requireApiKey(array $config)
    {
        $expected = trim((string) (isset($config['api_key']) ? $config['api_key'] : ''));
        if ($expected === '' || $expected === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET') {
            JsonResponse::error('API key is not configured on this mail bridge.', 500);
        }

        $provided = self::extractApiKey();
        if ($provided === '' || !hash_equals($expected, $provided)) {
            JsonResponse::error('Unauthorized. Provide Authorization: Bearer <key>, X-Api-Key, or ?api_key=', 401);
        }
    }

    private static function extractApiKey()
    {
        // Prefer Authorization Bearer — StackCDN / some proxies strip X-Api-Key.
        $auth = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim((string) $_SERVER['HTTP_X_API_KEY']);
        }

        $headers = function_exists('getallheaders') ? getallheaders() : array();
        $headers = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : array();

        if (!empty($headers['authorization']) && stripos((string) $headers['authorization'], 'Bearer ') === 0) {
            return trim(substr((string) $headers['authorization'], 7));
        }
        if (!empty($headers['x-api-key'])) {
            return trim((string) $headers['x-api-key']);
        }

        if (isset($_GET['api_key'])) {
            return trim((string) $_GET['api_key']);
        }

        return '';
    }
}

<?php

declare(strict_types=1);

/**
 * Ultitech ERP ? Mail app SSO helpers (HMAC token handoff).
 */

if (!function_exists('mail_sso_shared_secret')) {
    function mail_sso_shared_secret(): string
    {
        // Keep in sync with mail/common/config/params.php ? mail.ssoSecret
        $fromEnv = '';
        if (isset($GLOBALS['MAIL_SSO_SECRET']) && is_string($GLOBALS['MAIL_SSO_SECRET'])) {
            $fromEnv = trim($GLOBALS['MAIL_SSO_SECRET']);
        }
        if ($fromEnv === '' && function_exists('getenv')) {
            $g = getenv('MAIL_SSO_SECRET');
            if (is_string($g) && trim($g) !== '') {
                $fromEnv = trim($g);
            }
        }
        return $fromEnv !== ''
            ? $fromEnv
            : 'UltitechMailSso_v1_9mKp2xR7vN4wQ6tY_change_in_prod';
    }
}

if (!function_exists('mail_sso_b64url_encode')) {
    function mail_sso_b64url_encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

if (!function_exists('mail_sso_b64url_decode')) {
    function mail_sso_b64url_decode(string $raw): string|false
    {
        $pad = 4 - (strlen($raw) % 4);
        if ($pad < 4) {
            $raw .= str_repeat('=', $pad);
        }
        return base64_decode(strtr($raw, '-_', '+/'), true);
    }
}

if (!function_exists('mail_sso_create_token')) {
    /**
     * @param array{user_id?:int|string,email?:string,name?:string,username?:string,company?:string} $claims
     */
    function mail_sso_create_token(array $claims, int $ttlSeconds = 90): string
    {
        $payload = [
            'uid' => (string) ($claims['user_id'] ?? ''),
            'email' => strtolower(trim((string) ($claims['email'] ?? ''))),
            'name' => trim((string) ($claims['name'] ?? '')),
            'username' => trim((string) ($claims['username'] ?? '')),
            'company' => strtolower(trim((string) ($claims['company'] ?? ''))),
            'exp' => time() + max(30, $ttlSeconds),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $body = mail_sso_b64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = mail_sso_b64url_encode(hash_hmac('sha256', $body, mail_sso_shared_secret(), true));
        return $body . '.' . $sig;
    }
}

if (!function_exists('mail_sso_verify_token')) {
    /**
     * @return array<string,mixed>|null
     */
    function mail_sso_verify_token(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !str_contains($token, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        if ($body === '' || $sig === '') {
            return null;
        }
        $expected = mail_sso_b64url_encode(hash_hmac('sha256', $body, mail_sso_shared_secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = mail_sso_b64url_decode($body);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp < time()) {
            return null;
        }
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $payload;
    }
}

if (!function_exists('mail_sso_target_url')) {
    function mail_sso_target_url(string $companySlug): string
    {
        $slug = strtolower(trim($companySlug));
        $map = [
            'ultimate' => 'https://ultimate.co.tz/staff/mail/frontend/web/index.php/app',
            'roadmaster' => 'https://roadmasterspares.com/mail/frontend/web/index.php/app',
        ];
        return $map[$slug] ?? '';
    }
}

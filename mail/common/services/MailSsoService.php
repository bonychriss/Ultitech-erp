<?php

declare(strict_types=1);

namespace common\services;

use common\models\User;
use Yii;

/**
 * Verify Ultitech ERP SSO tokens and map them to Mail app users.
 */
final class MailSsoService
{
    public static function sharedSecret(): string
    {
        $fromParams = trim((string) (Yii::$app->params['mail.ssoSecret'] ?? ''));
        if ($fromParams !== '') {
            return $fromParams;
        }
        $env = getenv('MAIL_SSO_SECRET');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        // Must match includes/mail-sso.php on Ultitech ERP
        return 'UltitechMailSso_v1_9mKp2xR7vN4wQ6tY_change_in_prod';
    }

    public static function expectedCompany(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $path = str_replace('\\', '/', Yii::getAlias('@app'));
        if (str_contains($host, 'roadmasterspares.com')
            || str_contains($path, '/home/roady/')
            || str_contains($path, '/8a9d1d19f3/')) {
            return 'roadmaster';
        }
        if (str_contains($host, 'ultimate.co.tz') || str_contains($path, '/home/ultimate/')) {
            return 'ultimate';
        }
        return '';
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !str_contains($token, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        if ($body === '' || $sig === '') {
            return null;
        }
        $expected = self::b64urlEncode(hash_hmac('sha256', $body, self::sharedSecret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = self::b64urlDecode($body);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        if ((int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $expectedCompany = self::expectedCompany();
        $tokenCompany = strtolower(trim((string) ($payload['company'] ?? '')));
        if ($expectedCompany !== '' && $tokenCompany !== '' && $tokenCompany !== $expectedCompany) {
            return null;
        }

        return $payload;
    }

    public static function findOrCreateUser(array $payload): ?User
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $user = User::findOne(['email' => $email]);
        if ($user && (int) $user->status === User::STATUS_DELETED) {
            return null;
        }

        if (!$user) {
            $baseUsername = trim((string) ($payload['username'] ?? ''));
            if ($baseUsername === '' || !preg_match('/^[a-zA-Z0-9._@-]{3,64}$/', $baseUsername)) {
                $baseUsername = strstr($email, '@', true) ?: ('user' . substr(md5($email), 0, 8));
            }
            $username = $baseUsername;
            $i = 1;
            while (User::findOne(['username' => $username])) {
                $username = $baseUsername . $i;
                $i++;
                if ($i > 50) {
                    $username = 'erp_' . substr(md5($email . microtime(true)), 0, 12);
                    break;
                }
            }

            $user = new User();
            $user->username = $username;
            $user->email = $email;
            $user->status = User::STATUS_ACTIVE;
            $user->setPassword(Yii::$app->security->generateRandomString(24));
            $user->generateAuthKey();
            if (!$user->save(false)) {
                return null;
            }
        } elseif ((int) $user->status === User::STATUS_INACTIVE) {
            $user->status = User::STATUS_ACTIVE;
            $user->save(false);
        }

        return $user;
    }

    private static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $raw): string|false
    {
        $pad = 4 - (strlen($raw) % 4);
        if ($pad < 4) {
            $raw .= str_repeat('=', $pad);
        }
        return base64_decode(strtr($raw, '-_', '+/'), true);
    }
}

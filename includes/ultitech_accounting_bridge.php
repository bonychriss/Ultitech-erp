<?php
declare(strict_types=1);

/**
 * Dual-run helpers for UltiTech Accounting ↔ ERP (in-ERP upgrade path).
 *
 * Modes:
 * - off      : classic accounting only
 * - parallel : new module + classic fallback (default)
 * - primary  : new module is default entry; classic hub still linked for rollback
 */

if (! function_exists('ultitech_accounting_mode')) {
    function ultitech_accounting_mode(): string
    {
        $mode = '';
        if (isset($GLOBALS['ULTITECH_ACCOUNTING_MODE'])) {
            $mode = (string) $GLOBALS['ULTITECH_ACCOUNTING_MODE'];
        } elseif (defined('ULTITECH_ACCOUNTING_MODE')) {
            $mode = (string) ULTITECH_ACCOUNTING_MODE;
        } else {
            $fromEnv = getenv('ULTITECH_ACCOUNTING_MODE');
            $mode = is_string($fromEnv) ? $fromEnv : '';
        }

        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['off', 'parallel', 'primary'], true)) {
            return 'parallel';
        }

        return $mode;
    }
}

if (! function_exists('ultitech_accounting_api_base')) {
    function ultitech_accounting_api_base(): string
    {
        if (isset($GLOBALS['ULTITECH_ACCOUNTING_API']) && is_string($GLOBALS['ULTITECH_ACCOUNTING_API']) && $GLOBALS['ULTITECH_ACCOUNTING_API'] !== '') {
            return rtrim($GLOBALS['ULTITECH_ACCOUNTING_API'], '/');
        }
        if (defined('ULTITECH_ACCOUNTING_API') && is_string(ULTITECH_ACCOUNTING_API) && ULTITECH_ACCOUNTING_API !== '') {
            return rtrim(ULTITECH_ACCOUNTING_API, '/');
        }
        $fromEnv = getenv('ULTITECH_ACCOUNTING_API');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        return 'http://127.0.0.1:8000';
    }
}

if (! function_exists('ultitech_accounting_browser_api_base')) {
    /**
     * Browser-facing API base (same-origin proxy). Upstream remains ultitech_accounting_api_base().
     */
    function ultitech_accounting_browser_api_base(): string
    {
        // Frontend appends paths like /bank-accounts; proxy rewrites to upstream /api/v1/...
        if (function_exists('app_url')) {
            return rtrim((string) app_url('/ultitech-accounting/api-proxy.php'), '/') . '?ua_api=1&path=/api/v1';
        }

        return '/public_html/ultitech-accounting/api-proxy.php?ua_api=1&path=/api/v1';
    }
}

if (! function_exists('ultitech_accounting_frontend_url')) {
    /** @deprecated Standalone Vite URL — prefer in-ERP bridge path. */
    function ultitech_accounting_frontend_url(): string
    {
        if (isset($GLOBALS['ULTITECH_ACCOUNTING_URL']) && is_string($GLOBALS['ULTITECH_ACCOUNTING_URL']) && $GLOBALS['ULTITECH_ACCOUNTING_URL'] !== '') {
            return rtrim($GLOBALS['ULTITECH_ACCOUNTING_URL'], '/');
        }
        if (defined('ULTITECH_ACCOUNTING_URL') && is_string(ULTITECH_ACCOUNTING_URL) && ULTITECH_ACCOUNTING_URL !== '') {
            return rtrim(ULTITECH_ACCOUNTING_URL, '/');
        }

        return 'http://127.0.0.1:5173';
    }
}

if (! function_exists('ultitech_accounting_bridge_secret')) {
    function ultitech_accounting_bridge_secret(): string
    {
        if (isset($GLOBALS['ULTITECH_ACCOUNTING_BRIDGE_SECRET']) && is_string($GLOBALS['ULTITECH_ACCOUNTING_BRIDGE_SECRET'])) {
            return (string) $GLOBALS['ULTITECH_ACCOUNTING_BRIDGE_SECRET'];
        }
        if (defined('ULTITECH_ACCOUNTING_BRIDGE_SECRET')) {
            return (string) ULTITECH_ACCOUNTING_BRIDGE_SECRET;
        }
        $fromEnv = getenv('ULTITECH_ACCOUNTING_BRIDGE_SECRET');

        return is_string($fromEnv) ? $fromEnv : '';
    }
}

if (! function_exists('ultitech_accounting_bridge_path')) {
    function ultitech_accounting_bridge_path(string $companySlug = '', string $hashRoute = ''): string
    {
        $path = 'accounting/ultitech-bridge';
        $url = function_exists('company_url')
            ? (string) company_url($path, $companySlug !== '' ? $companySlug : null) . '?module=accounting'
            : (function_exists('app_url')
                ? (string) app_url('/' . $path . '.php?module=accounting')
                : '/' . $path . '.php?module=accounting');

        $hashRoute = trim($hashRoute);
        if ($hashRoute !== '') {
            $url .= '#' . (str_starts_with($hashRoute, '/') ? $hashRoute : '/' . ltrim($hashRoute, '/'));
        }

        return $url;
    }
}

if (! function_exists('ultitech_accounting_classic_path')) {
    function ultitech_accounting_classic_path(string $companySlug = ''): string
    {
        if (function_exists('company_url')) {
            return (string) company_url('accounting', $companySlug !== '' ? $companySlug : null) . '?module=accounting&engine=classic';
        }

        return function_exists('app_url')
            ? (string) app_url('/accounting.php?module=accounting&engine=classic')
            : '/accounting.php?module=accounting&engine=classic';
    }
}

if (! function_exists('ultitech_accounting_uses_internal_api')) {
    function ultitech_accounting_uses_internal_api(): bool
    {
        $base = strtolower(ultitech_accounting_api_base());

        return $base === 'internal' || $base === 'app' || $base === 'in-process';
    }
}

if (! function_exists('ultitech_accounting_backend_root')) {
    function ultitech_accounting_backend_root(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ultitech-accounting' . DIRECTORY_SEPARATOR . 'backend';
    }
}

if (! function_exists('ultitech_accounting_dispatch_api')) {
    /**
     * Dispatch an Accounting API request through Laravel in-process (shared hosting safe).
     *
     * @param  array<string,string>  $headers
     * @return array{ok:bool,status:int,body:string,error?:string}
     */
    function ultitech_accounting_dispatch_api(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $backend = ultitech_accounting_backend_root();
        $autoload = $backend . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $bootstrap = $backend . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        if (! is_file($autoload) || ! is_file($bootstrap)) {
            return [
                'ok' => false,
                'status' => 503,
                'body' => '',
                'error' => 'Accounting API backend is not deployed (missing vendor/bootstrap).',
            ];
        }

        try {
            require_once $autoload;
            /** @var \Illuminate\Foundation\Application $app */
            $app = require $bootstrap;
            $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

            $server = [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ];
            foreach ($headers as $name => $value) {
                $key = strtoupper(str_replace('-', '_', $name));
                if ($key === 'CONTENT_TYPE') {
                    $server['CONTENT_TYPE'] = $value;
                } elseif ($key === 'AUTHORIZATION') {
                    $server['HTTP_AUTHORIZATION'] = $value;
                } else {
                    $server['HTTP_' . $key] = $value;
                }
            }

            $uri = '/' . ltrim(str_replace('\\', '/', $path), '/');
            $request = \Illuminate\Http\Request::create($uri, strtoupper($method), [], [], [], $server, $body);
            $response = $kernel->handle($request);
            $content = (string) $response->getContent();
            $status = (int) $response->getStatusCode();
            $kernel->terminate($request, $response);

            return [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'body' => $content,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => 500,
                'body' => '',
                'error' => 'Accounting API internal error: ' . $e->getMessage(),
            ];
        }
    }
}

if (! function_exists('ultitech_accounting_sign_payload')) {
    /**
     * @param  array<string,mixed>  $claims
     * @return array{payload:string,sig:string}
     */
    function ultitech_accounting_sign_payload(array $claims): array
    {
        $secret = ultitech_accounting_bridge_secret();
        if ($secret === '') {
            throw new RuntimeException('ULTITECH_ACCOUNTING_BRIDGE_SECRET is not configured.');
        }

        $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode bridge payload.');
        }

        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $payload, $secret);

        return ['payload' => $payload, 'sig' => $sig];
    }
}

if (! function_exists('ultitech_accounting_exchange_session')) {
    /**
     * Server-side SSO into Accounting API. Returns token payload or error.
     *
     * @param  array<string,mixed>  $claims
     * @return array{ok:bool,data?:array<string,mixed>,error?:string}
     */
    function ultitech_accounting_exchange_session(array $claims): array
    {
        try {
            $signed = ultitech_accounting_sign_payload($claims);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $body = json_encode([
            'payload' => $signed['payload'],
            'sig' => $signed['sig'],
            'back_url' => (string) ($claims['back_url'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            return ['ok' => false, 'error' => 'Failed to encode bridge request.'];
        }

        if (ultitech_accounting_uses_internal_api()) {
            $dispatched = ultitech_accounting_dispatch_api(
                'POST',
                '/api/v1/auth/erp-bridge',
                $body,
                ['Accept' => 'application/json', 'Content-Type' => 'application/json']
            );
            if (($dispatched['error'] ?? '') !== '') {
                return ['ok' => false, 'error' => (string) $dispatched['error']];
            }
            $raw = (string) ($dispatched['body'] ?? '');
            $status = (int) ($dispatched['status'] ?? 0);
        } else {
            $url = ultitech_accounting_api_base() . '/api/v1/auth/erp-bridge';

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                ]);
                $raw = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr = curl_error($ch);
                curl_close($ch);
                if ($raw === false) {
                    return ['ok' => false, 'error' => 'Accounting API unreachable: ' . $cerr . ' (is php artisan serve running?)'];
                }
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
                        'content' => $body,
                        'timeout' => 20,
                        'ignore_errors' => true,
                    ],
                ]);
                $raw = @file_get_contents($url, false, $ctx);
                $status = 0;
                if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                    $status = (int) $m[1];
                }
                if ($raw === false) {
                    return ['ok' => false, 'error' => 'Accounting API unreachable at ' . $url];
                }
            }
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded) || $status < 200 || $status >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['message'] ?? (is_array($decoded['bridge'] ?? null) ? ($decoded['bridge'][0] ?? 'Bridge failed') : 'Bridge failed'))
                : 'Bridge failed';

            return ['ok' => false, 'error' => $msg . ' (HTTP ' . $status . ')'];
        }

        return ['ok' => true, 'data' => $decoded];
    }
}

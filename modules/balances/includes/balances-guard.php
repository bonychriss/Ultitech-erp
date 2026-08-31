<?php
/**
 * Balances  register animated error handlers for fatal/exception failures.
 * Include before config/database.php on user-facing pages.
 */
declare(strict_types=1);

require_once __DIR__ . '/balances-error-page.php';

if (!function_exists('balances_guard_module_param')) {
    function balances_guard_module_param(): string
    {
        return trim((string) ($_GET['module'] ?? 'balances'));
    }
}

if (!function_exists('balances_guard_accounts_url')) {
    function balances_guard_accounts_url(?string $module = null): string
    {
        $module = $module ?? balances_guard_module_param();
        $url = 'accounts.php';
        if ($module !== '') {
            $url .= '?module=' . rawurlencode($module);
        }

        return $url;
    }
}

if (!function_exists('balances_guard_current_url')) {
    function balances_guard_current_url(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri !== '') {
            return $uri;
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? 'accounts.php');
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

        return $query !== '' ? $script . '?' . $query : $script;
    }
}

if (!function_exists('balances_register_error_handlers')) {
    /**
     * @param array{
     *   title?: string,
     *   back_url?: string,
     *   retry_url?: string,
     *   back_label?: string,
     *   retry_label?: string
     * } $pageOptions
     */
    function balances_register_error_handlers(string $logContext, array $pageOptions = []): void
    {
        static $registeredContexts = [];
        if (isset($registeredContexts[$logContext])) {
            return;
        }
        $registeredContexts[$logContext] = true;

        $defaults = [
            'title' => 'Page unavailable',
            'headline' => 'Oops! Page unavailable',
            'back_url' => balances_guard_accounts_url(),
            'retry_url' => '#back',
            'home_url' => balances_guard_accounts_url(),
            'back_label' => 'Go Back',
            'retry_label' => 'Try again',
            'home_label' => 'Go Home',
            'error_code' => '500',
            'log_context' => $logContext,
        ];
        $pageOptions = array_merge($defaults, $pageOptions);

        $showError = static function (string $message, ?string $userMessage = null) use ($pageOptions): void {
            $display = $userMessage ?? $message;
            if (trim($display) === '') {
                $display = "The page you're looking for doesn't exist or has been moved.";
            }
            balances_render_error_page($display, $pageOptions);
        };

        set_exception_handler(static function ($e) use ($showError, $logContext): void {
            error_log($logContext . ' exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $showError($e->getMessage());
        });

        register_shutdown_function(static function () use ($showError, $logContext): void {
            $err = error_get_last();
            if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            error_log($logContext . ' fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
            $showError($err['message'], "The page you're looking for doesn't exist or has been moved.");
        });
    }
}

if (!function_exists('balances_bootstrap_or_error')) {
    /**
     * @param array<string, mixed> $pageOptions
     */
    function balances_bootstrap_or_error(string $logContext, array $pageOptions = []): void
    {
        balances_register_error_handlers($logContext, $pageOptions);
        try {
            require_once dirname(__DIR__) . '/config/database.php';
        } catch (Throwable $e) {
            error_log($logContext . ' bootstrap: ' . $e->getMessage());
            balances_render_error_page(
                'Could not load this page. ' . $e->getMessage(),
                array_merge([
                    'title' => 'Page unavailable',
                    'headline' => 'Oops! Page unavailable',
                    'back_url' => balances_guard_accounts_url(),
                    'retry_url' => '#back',
                    'home_url' => balances_guard_accounts_url(),
                    'error_code' => '500',
                    'log_context' => $logContext,
                ], $pageOptions)
            );
        }

        global $pdo;
        if (!($pdo instanceof PDO)) {
            balances_render_error_page(
                'Database connection is not available.',
                array_merge([
                    'title' => 'Page unavailable',
                    'headline' => 'Oops! Page unavailable',
                    'back_url' => balances_guard_accounts_url(),
                    'retry_url' => '#back',
                    'home_url' => balances_guard_accounts_url(),
                    'error_code' => '500',
                    'log_context' => $logContext,
                ], $pageOptions)
            );
        }
    }
}

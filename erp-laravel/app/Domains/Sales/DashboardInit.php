<?php

namespace App\Domains\Sales;

use Throwable;

/**
 * Sales dashboard init payload (wraps legacy dashboardInitData).
 */
final class DashboardInit
{
    /**
     * @return array{ok:bool,engine?:string,error?:string}|array<string,mixed>
     */
    public function payload(): array
    {
        $lib = $this->dashboardLibPath();
        if (!is_file($lib)) {
            return ['ok' => false, 'error' => 'Sales dashboard library missing.'];
        }

        require_once $lib;

        try {
            if (function_exists('dashboardDeskBootstrap')) {
                dashboardDeskBootstrap();
            }
            if (!function_exists('dashboardInitData')) {
                return ['ok' => false, 'error' => 'dashboardInitData unavailable.'];
            }

            $data = dashboardInitData();
            if (!is_array($data)) {
                return ['ok' => false, 'error' => 'Invalid dashboard payload.'];
            }

            $data['ok'] = true;
            $data['engine'] = 'erp-laravel Domains/Sales';

            return $data;
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Dashboard init failed.',
            ];
        }
    }

    public function dashboardLibPath(): string
    {
        return rtrim((string) config('erp.app_root'), '\\/')
            . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'sales'
            . DIRECTORY_SEPARATOR . 'includes'
            . DIRECTORY_SEPARATOR . 'dashboard-lib.php';
    }
}

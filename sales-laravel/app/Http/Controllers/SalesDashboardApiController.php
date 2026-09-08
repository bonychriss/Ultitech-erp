<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sales dashboard JSON � wraps existing ERP dashboardInitData().
 */
class SalesDashboardApiController extends Controller
{
    public function init(Request $request): JsonResponse
    {
        $lib = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'modules'
            . DIRECTORY_SEPARATOR . 'sales'
            . DIRECTORY_SEPARATOR . 'includes'
            . DIRECTORY_SEPARATOR . 'dashboard-lib.php';

        if (!is_file($lib)) {
            return response()->json(['ok' => false, 'error' => 'Sales dashboard library missing.'], 500);
        }

        require_once $lib;

        try {
            if (function_exists('dashboardDeskBootstrap')) {
                dashboardDeskBootstrap();
            }
            if (!function_exists('dashboardInitData')) {
                return response()->json(['ok' => false, 'error' => 'dashboardInitData unavailable.'], 500);
            }

            $data = dashboardInitData();
            if (!is_array($data)) {
                return response()->json(['ok' => false, 'error' => 'Invalid dashboard payload.'], 500);
            }

            $data['ok'] = true;
            $data['engine'] = 'Laravel + React';

            return response()->json($data);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Dashboard init failed.',
            ], 500);
        }
    }
}

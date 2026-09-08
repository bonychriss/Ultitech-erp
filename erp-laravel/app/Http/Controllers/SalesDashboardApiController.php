<?php

namespace App\Http\Controllers;

use App\Domains\Sales\DashboardInit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sales dashboard JSON — Domains/Sales.
 */
class SalesDashboardApiController extends Controller
{
    public function init(Request $request): JsonResponse
    {
        $data = (new DashboardInit())->payload();
        $ok = !empty($data['ok']);
        $status = $ok ? 200 : (isset($data['error']) && str_contains((string) $data['error'], 'missing') ? 500 : 500);

        return response()->json($data, $status);
    }
}

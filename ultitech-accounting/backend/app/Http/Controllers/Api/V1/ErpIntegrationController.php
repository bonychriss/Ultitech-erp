<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\ErpSyncService;
use App\Http\Controllers\Controller;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpIntegrationController extends Controller
{
    public function __construct(private readonly ErpSyncService $sync) {}

    public function status(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $connection = $this->sync->ensureConnection((int) $company->id);

        return response()->json([
            'data' => [
                'connection' => $connection,
                'enabled' => (bool) config('erp.enabled'),
                'safety' => [
                    'read_only' => true,
                    'erp_writes' => false,
                    'note' => 'UltiTech Accounting only SELECTs from ERP. Existing ERP accounting is never changed or deleted.',
                ],
                'recent_runs' => ErpSyncRun::query()
                    ->where('company_id', $company->id)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get(),
            ],
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $connection = $this->sync->ensureConnection((int) $company->id);
        $result = $this->sync->test($connection);

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }

    public function pull(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'in:accounts,customers,suppliers,invoices,journals,wallets'],
            'journal_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $run = $this->sync->pull(
            $request->attributes->get('book'),
            $request->user(),
            $payload['scopes'] ?? ['accounts', 'customers', 'suppliers', 'invoices', 'journals', 'wallets'],
            $payload['journal_limit'] ?? null
        );

        return response()->json(['data' => $run], 201);
    }

    public function runs(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => ErpSyncRun::query()
                ->where('company_id', $company->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }
}

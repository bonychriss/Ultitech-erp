<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\ErpBridgeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpBridgeController extends Controller
{
    public function exchange(Request $request, ErpBridgeService $bridge): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['required', 'string'],
            'sig' => ['required', 'string'],
            'back_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $bridge->exchange(
            (string) $data['payload'],
            (string) $data['sig'],
            isset($data['back_url']) ? (string) $data['back_url'] : null,
        );

        return response()->json($result);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DeveloperSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuggestApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $erp = $request->attributes->get('erp', []);
        $rows = DeveloperSuggestion::query()
            ->with('user:id,full_name')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(static function (DeveloperSuggestion $s) {
                return [
                    'id' => (int) $s->id,
                    'suggestion' => (string) $s->suggestion,
                    'status' => (string) ($s->status ?: 'pending'),
                    'author' => (string) ($s->user->full_name ?? 'Unknown'),
                    'created_at' => $s->created_at ? $s->created_at->format('d M Y, H:i') : '',
                ];
            })
            ->values();

        return response()->json([
            'ok' => true,
            'engine' => 'Laravel + React',
            'user' => [
                'id' => (int) ($erp['user_id'] ?? 0),
                'name' => (string) ($erp['full_name'] ?? ''),
            ],
            'suggestions' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $erp = $request->attributes->get('erp', []);
        $userId = (int) ($erp['user_id'] ?? 0);
        if ($userId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Not authenticated.'], 401);
        }

        $validated = $request->validate([
            'suggestion' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        $row = DeveloperSuggestion::query()->create([
            'user_id' => $userId,
            'suggestion' => trim($validated['suggestion']),
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $row->load('user:id,full_name');

        return response()->json([
            'ok' => true,
            'message' => 'Feature request submitted successfully! The developer will review it.',
            'suggestion' => [
                'id' => (int) $row->id,
                'suggestion' => (string) $row->suggestion,
                'status' => (string) ($row->status ?: 'pending'),
                'author' => (string) ($row->user->full_name ?? 'You'),
                'created_at' => $row->created_at ? $row->created_at->format('d M Y, H:i') : date('d M Y, H:i'),
            ],
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AccountingPolicyService;
use App\Http\Controllers\Controller;
use App\Models\AccountingPolicy;
use App\Models\DisclosureTemplate;
use App\Models\PolicyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StandardsController extends Controller
{
    public function __construct(private readonly AccountingPolicyService $policies) {}

    public function frameworks(): JsonResponse
    {
        return response()->json(['data' => $this->policies->frameworks()]);
    }

    public function disclosures(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => DisclosureTemplate::query()
                ->where('framework_id', $book->framework_id)
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function policies(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => AccountingPolicy::query()
                ->where('book_id', $book->id)
                ->with(['versions' => fn ($q) => $q->orderByDesc('version_no')])
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'effective_from' => ['required', 'date'],
            'policy_json' => ['nullable', 'array'],
            'approve' => ['nullable', 'boolean'],
        ]);

        $policy = $this->policies->createPolicy($book, $data);
        $version = $this->policies->addVersion(
            $policy,
            [
                'effective_from' => $data['effective_from'],
                'policy_json' => $data['policy_json'] ?? [],
            ],
            $request->user(),
            (bool) ($data['approve'] ?? false)
        );

        return response()->json([
            'data' => $policy->load(['versions' => fn ($q) => $q->orderByDesc('version_no')]),
            'version' => $version,
        ], 201);
    }

    public function approveVersion(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $version = PolicyVersion::query()
            ->whereHas('policy', fn ($q) => $q->where('book_id', $book->id))
            ->findOrFail($id);

        $approved = $this->policies->approveVersion($version, $request->user());

        return response()->json(['data' => $approved]);
    }

    public function checklist(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json(['data' => $this->policies->complianceChecklist($book)]);
    }
}

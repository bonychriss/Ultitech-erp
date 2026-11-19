<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\JournalPostingService;
use App\Application\Services\JournalReversalService;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class JournalController extends Controller
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly JournalReversalService $reversals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        $journals = JournalEntry::query()
            ->where('book_id', $book->id)
            ->with('lines')
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($journals);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $book = $request->attributes->get('book');
        $journal = $this->posting->createDraft($book, $request->user(), $payload);

        return response()->json(['data' => $journal], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $journal = JournalEntry::query()->where('book_id', $book->id)->with('lines')->findOrFail($id);

        return response()->json(['data' => $journal]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $journal = JournalEntry::query()->where('book_id', $book->id)->findOrFail($id);

        if (! $journal->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Posted journals are immutable.',
            ]);
        }

        $payload = $this->validatedPayload($request);
        $journal->lines()->delete();
        $journal->delete();

        $fresh = $this->posting->createDraft($book, $request->user(), $payload);

        return response()->json(['data' => $fresh]);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $journal = JournalEntry::query()->where('book_id', $book->id)->findOrFail($id);
        $posted = $this->posting->post($journal, $request->user(), $request->header('Idempotency-Key'));

        return response()->json(['data' => $posted]);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'reversal_date' => ['nullable', 'date'],
        ]);

        $book = $request->attributes->get('book');
        $journal = JournalEntry::query()->where('book_id', $book->id)->with('lines')->findOrFail($id);
        $reversal = $this->reversals->reverse(
            $journal,
            $request->user(),
            $data['reason'],
            $data['reversal_date'] ?? null
        );

        return response()->json(['data' => $reversal]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'journal_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:acc_currencies,code'],
            'source_hash' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.dimensions' => ['nullable', 'array'],
            'lines.*.dimensions.cost_center_id' => ['nullable', 'integer'],
        ]);
    }
}

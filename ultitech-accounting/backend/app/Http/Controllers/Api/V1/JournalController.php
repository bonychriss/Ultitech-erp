<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\JournalErpPresentService;
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
        private readonly JournalErpPresentService $erpJournals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        $limit = max(1, min(500, (int) $request->query('limit', 200)));
        $status = strtolower(trim((string) $request->query('status', '')));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $q = trim((string) $request->query('q', ''));
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
        $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

        $query = JournalEntry::query()
            ->where('book_id', $book->id)
            ->with([
                'lines.account:id,code,name',
                'source:id,code,name',
            ]);

        if (in_array($status, ['draft', 'posted', 'reversed'], true)) {
            $query->where('status', $status);
        }
        if ($dateFrom !== null) {
            $query->whereDate('journal_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('journal_date', '<=', $dateTo);
        }
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('journal_number', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('source_type', 'like', $like);
            });
        }

        $local = $query
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $importedHashes = [];
        foreach ($local as $row) {
            $hash = trim((string) ($row->source_hash ?? ''));
            if ($hash !== '') {
                $importedHashes[$hash] = true;
            }
        }

        $erpRows = $this->erpJournals->listJournals(
            $book,
            $limit,
            $dateFrom,
            $dateTo,
            $status !== '' ? $status : null,
            $q !== '' ? $q : null,
        );

        $merged = [];
        foreach ($local as $row) {
            $payload = $row->toArray();
            $payload['read_only'] = false;
            $merged[] = $payload;
        }
        foreach ($erpRows as $row) {
            $hash = trim((string) ($row['source_hash'] ?? ''));
            if ($hash !== '' && isset($importedHashes[$hash])) {
                continue;
            }
            $merged[] = $row;
        }

        usort($merged, static function (array $a, array $b): int {
            $byDate = strcmp((string) ($b['journal_date'] ?? ''), (string) ($a['journal_date'] ?? ''));
            if ($byDate !== 0) {
                return $byDate;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        if (count($merged) > $limit) {
            $merged = array_slice($merged, 0, $limit);
        }

        return response()->json(['data' => array_values($merged)]);
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

        if ($id < 0) {
            $erp = $this->erpJournals->findJournal($book, abs($id));
            if ($erp === null) {
                abort(404);
            }

            return response()->json(['data' => $erp]);
        }

        $journal = JournalEntry::query()
            ->where('book_id', $book->id)
            ->with(['lines.account:id,code,name', 'source:id,code,name'])
            ->findOrFail($id);

        $payload = $journal->toArray();
        $payload['read_only'] = false;

        return response()->json(['data' => $payload]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertLocalMutableId($id);
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
        $this->assertLocalMutableId($id);
        $book = $request->attributes->get('book');
        $journal = JournalEntry::query()->where('book_id', $book->id)->findOrFail($id);
        $posted = $this->posting->post($journal, $request->user(), $request->header('Idempotency-Key'));

        return response()->json(['data' => $posted]);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $this->assertLocalMutableId($id);
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

    private function assertLocalMutableId(int $id): void
    {
        if ($id <= 0) {
            throw ValidationException::withMessages([
                'journal' => 'Classic ERP journals are read-only here. Create a new journal to adjust the books.',
            ]);
        }
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

<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\IdempotencyKey;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\JournalSource;
use App\Models\OutboxEvent;
use App\Models\PostingSequence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JournalPostingService
{
    public function __construct(
        private readonly JournalValidationService $validator,
        private readonly AuditTrailService $audit,
        private readonly ApprovalWorkflowService $approvals,
    ) {}

    public function createDraft(
        AccountingBook $book,
        User $user,
        array $payload,
        string $sourceCode = 'manual'
    ): JournalEntry {
        $validated = $this->validator->validateForPosting(
            $book,
            $payload['journal_date'],
            $payload['currency_code'],
            $payload['lines'],
            $sourceCode === 'manual'
        );

        if (! empty($payload['source_hash'])) {
            $exists = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->where('source_hash', $payload['source_hash'])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'source_hash' => 'A journal with this source identity already exists.',
                ]);
            }
        }

        $source = JournalSource::query()->where('code', $sourceCode)->firstOrFail();

        return DB::transaction(function () use ($book, $user, $payload, $validated, $source) {
            $number = $this->nextJournalNumber((int) $book->company_id, (int) $book->id);

            $journal = JournalEntry::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'period_id' => $validated['period_id'],
                'source_id' => $source->id,
                'journal_number' => $number,
                'journal_date' => $payload['journal_date'],
                'posting_date' => $payload['journal_date'],
                'description' => $payload['description'],
                'reference' => $payload['reference'] ?? null,
                'status' => 'draft',
                'currency_code' => $payload['currency_code'],
                'exchange_rate' => $validated['exchange_rate'],
                'total_debit' => $validated['total_debit'],
                'total_credit' => $validated['total_credit'],
                'source_type' => $payload['source_type'] ?? null,
                'source_id_ref' => $payload['source_id_ref'] ?? null,
                'source_hash' => $payload['source_hash'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->replaceLines($journal, $book, $validated['lines']);

            $this->audit->record('journal.draft_created', 'journal_entry', $journal->id, null, $journal->toArray());

            return $journal->load('lines');
        });
    }

    public function post(JournalEntry $journal, User $user, ?string $idempotencyKey = null): JournalEntry
    {
        if ($journal->status === 'posted') {
            return $journal->load('lines');
        }

        if ($journal->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft journals can be posted.',
            ]);
        }

        if ($idempotencyKey) {
            $existing = $this->findIdempotent((int) $journal->company_id, $idempotencyKey);
            if ($existing) {
                return JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($existing['journal_id']);
            }
        }

        if ($journal->source_hash) {
            $duplicate = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $journal->company_id)
                ->where('book_id', $journal->book_id)
                ->where('source_hash', $journal->source_hash)
                ->where('status', 'posted')
                ->where('id', '!=', $journal->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'source_hash' => 'A posted journal with this source identity already exists.',
                ]);
            }
        }

        $this->approvals->assertJournalPostable($journal);

        $book = AccountingBook::withoutGlobalScopes()->findOrFail($journal->book_id);
        $lines = $journal->lines()->get()->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'description' => $line->description,
            'dimensions_json' => $line->dimensions_json,
        ])->all();

        $validated = $this->validator->validateForPosting(
            $book,
            $journal->journal_date->toDateString(),
            $journal->currency_code,
            $lines,
            false
        );

        return DB::transaction(function () use ($journal, $user, $validated, $book, $idempotencyKey) {
            $locked = JournalEntry::withoutGlobalScopes()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'posted') {
                return $locked->load('lines');
            }

            $locked->update([
                'period_id' => $validated['period_id'],
                'exchange_rate' => $validated['exchange_rate'],
                'total_debit' => $validated['total_debit'],
                'total_credit' => $validated['total_credit'],
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $user->id,
                'posting_date' => $locked->journal_date,
            ]);

            $this->replaceLines($locked, $book, $validated['lines']);

            OutboxEvent::query()->create([
                'company_id' => $locked->company_id,
                'event_type' => 'journal.posted',
                'aggregate_type' => 'journal_entry',
                'aggregate_id' => $locked->id,
                'payload_json' => [
                    'journal_id' => $locked->id,
                    'journal_number' => $locked->journal_number,
                    'book_id' => $locked->book_id,
                ],
                'status' => 'pending',
                'available_at' => now(),
                'created_at' => now(),
            ]);

            $this->audit->record('journal.posted', 'journal_entry', $locked->id, null, [
                'journal_number' => $locked->journal_number,
                'total_debit' => $locked->total_debit,
                'total_credit' => $locked->total_credit,
            ]);

            if ($idempotencyKey) {
                IdempotencyKey::query()->create([
                    'company_id' => $locked->company_id,
                    'key_hash' => hash('sha256', $idempotencyKey),
                    'request_path' => '/api/v1/journals/'.$locked->id.'/post',
                    'response_code' => 200,
                    'response_body' => ['journal_id' => $locked->id],
                    'created_at' => now(),
                ]);
            }

            return $locked->load('lines');
        });
    }

    public function createAndPost(
        AccountingBook $book,
        User $user,
        array $payload,
        string $sourceCode = 'manual',
        ?string $idempotencyKey = null
    ): JournalEntry {
        $draft = $this->createDraft($book, $user, $payload, $sourceCode);

        return $this->post($draft, $user, $idempotencyKey);
    }

    private function nextJournalNumber(int $companyId, int $bookId): string
    {
        $sequence = PostingSequence::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('book_id', $bookId)
            ->where('sequence_key', 'journal')
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $sequence = PostingSequence::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'book_id' => $bookId,
                'sequence_key' => 'journal',
                'prefix' => 'JE',
                'next_number' => 1,
                'padding' => 6,
            ]);
            $sequence = PostingSequence::withoutGlobalScopes()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
        }

        // Advance until the candidate is free. Sequence updates must survive a later
        // draft insert failure (unique collision) so ERP bulk imports cannot stall on
        // a rolled-back next_number that keeps reissuing an existing journal_number.
        do {
            $candidate = (int) $sequence->next_number;
            $number = $sequence->prefix.str_pad((string) $candidate, $sequence->padding, '0', STR_PAD_LEFT);
            $sequence->update(['next_number' => $candidate + 1]);
            $sequence->next_number = $candidate + 1;

            $taken = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('book_id', $bookId)
                ->where('journal_number', $number)
                ->exists();
        } while ($taken);

        return $number;
    }

    private function replaceLines(JournalEntry $journal, AccountingBook $book, $lines): void
    {
        JournalLine::withoutGlobalScopes()->where('journal_id', $journal->id)->delete();

        foreach ($lines->values() as $index => $line) {
            JournalLine::withoutGlobalScopes()->create([
                'journal_id' => $journal->id,
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'line_no' => $index + 1,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'currency_code' => $line['currency_code'],
                'exchange_rate' => $line['exchange_rate'],
                'functional_debit' => $line['functional_debit'],
                'functional_credit' => $line['functional_credit'],
                'dimensions_json' => $line['dimensions_json'] ?? null,
            ]);
        }
    }

    private function findIdempotent(int $companyId, string $key): ?array
    {
        $row = IdempotencyKey::query()
            ->where('company_id', $companyId)
            ->where('key_hash', hash('sha256', $key))
            ->first();

        return $row?->response_body;
    }
}

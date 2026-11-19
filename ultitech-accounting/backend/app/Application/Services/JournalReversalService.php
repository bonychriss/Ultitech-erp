<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\JournalEntry;
use App\Models\JournalReversal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalReversalService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly AuditTrailService $audit,
    ) {}

    public function reverse(JournalEntry $original, User $user, string $reason, ?string $reversalDate = null): JournalEntry
    {
        if ($original->status !== 'posted') {
            throw ValidationException::withMessages([
                'status' => 'Only posted journals can be reversed.',
            ]);
        }

        if ($original->reversal()->exists()) {
            throw ValidationException::withMessages([
                'journal' => 'Journal has already been reversed.',
            ]);
        }

        $book = AccountingBook::withoutGlobalScopes()->findOrFail($original->book_id);
        $date = $reversalDate ?: $original->journal_date->toDateString();

        $lines = $original->lines()->get()->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'description' => 'Reversal: '.($line->description ?: $original->description),
        ])->all();

        return DB::transaction(function () use ($original, $user, $reason, $book, $date, $lines) {
            $reversal = $this->posting->createAndPost($book, $user, [
                'journal_date' => $date,
                'description' => 'Reversal of '.$original->journal_number.': '.$reason,
                'reference' => $original->journal_number,
                'currency_code' => $original->currency_code,
                'source_type' => 'reversal',
                'source_id_ref' => (string) $original->id,
                'source_hash' => 'reversal:'.$original->id,
                'lines' => $lines,
            ], 'reversal');

            JournalReversal::withoutGlobalScopes()->create([
                'company_id' => $original->company_id,
                'original_journal_id' => $original->id,
                'reversal_journal_id' => $reversal->id,
                'reversed_at' => now(),
                'reversed_by' => $user->id,
                'reason' => $reason,
            ]);

            $original->update(['status' => 'reversed']);

            $this->audit->record('journal.reversed', 'journal_entry', $original->id, null, [
                'original_journal_id' => $original->id,
                'reversal_journal_id' => $reversal->id,
                'reason' => $reason,
            ]);

            return $reversal->load('lines');
        });
    }
}

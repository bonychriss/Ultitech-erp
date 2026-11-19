<?php

namespace App\Application\Services;

use App\Models\CustomerInvoice;
use App\Models\ErpIdMap;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rename local AR invoice numbers to classic Accounting REV vouchers
 * when a revenue_entries row exists for the mapped ERP sales invoice.
 * Never deletes rows; preserves original INV number in erp map meta.
 */
final class RevenueNumberBackfillService
{
    /**
     * @return array{updated:int,skipped:int,conflicts:int,samples:list<array{id:int,from:string,to:string}>}
     */
    public function backfill(?int $companyId = null): array
    {
        $updated = 0;
        $skipped = 0;
        $conflicts = 0;
        $samples = [];

        if (! config('erp.enabled') || ! Schema::connection('erp_ultimate')->hasTable('revenue_entries')) {
            return compact('updated', 'skipped', 'conflicts', 'samples') + [
                'message' => 'ERP revenue_entries unavailable; nothing changed.',
            ];
        }

        $mapsQuery = ErpIdMap::query()->where('entity_type', 'customer_invoice');
        if ($companyId) {
            $mapsQuery->where('company_id', $companyId);
        }
        $maps = $mapsQuery->get();

        $erpIds = $maps->pluck('erp_id')->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
        if ($erpIds === []) {
            return compact('updated', 'skipped', 'conflicts', 'samples');
        }

        $revByInvoice = DB::connection('erp_ultimate')
            ->table('revenue_entries')
            ->whereIn('source_invoice_id', $erpIds)
            ->whereNotNull('voucher_number')
            ->orderBy('id')
            ->get(['source_invoice_id', 'voucher_number']);

        $voucherByErpInvoice = [];
        foreach ($revByInvoice as $row) {
            $sid = (int) $row->source_invoice_id;
            $vn = trim((string) $row->voucher_number);
            if ($sid <= 0 || $vn === '' || ! str_starts_with(strtoupper($vn), 'REV-')) {
                continue;
            }
            // Prefer the first REV voucher for that sales invoice.
            if (! isset($voucherByErpInvoice[$sid])) {
                $voucherByErpInvoice[$sid] = $vn;
            }
        }

        DB::transaction(function () use ($maps, $voucherByErpInvoice, &$updated, &$skipped, &$conflicts, &$samples) {
            foreach ($maps as $map) {
                $erpInvoiceId = (int) $map->erp_id;
                $localId = (int) $map->local_id;
                $target = $voucherByErpInvoice[$erpInvoiceId] ?? null;
                if ($target === null) {
                    $skipped++;
                    continue;
                }

                $invoice = CustomerInvoice::withoutGlobalScopes()->whereKey($localId)->first();
                if (! $invoice) {
                    $skipped++;
                    continue;
                }

                $from = trim((string) $invoice->invoice_number);
                if ($from === $target) {
                    $skipped++;
                    continue;
                }

                // Already classic REV (e.g. manual revenue) — leave alone unless still INV-*.
                if (str_starts_with(strtoupper($from), 'REV-') && ! str_starts_with(strtoupper($from), 'INV')) {
                    $skipped++;
                    continue;
                }

                $clash = CustomerInvoice::withoutGlobalScopes()
                    ->where('company_id', $invoice->company_id)
                    ->where('book_id', $invoice->book_id)
                    ->where('invoice_number', $target)
                    ->where('id', '!=', $invoice->id)
                    ->exists();
                if ($clash) {
                    $conflicts++;
                    Log::warning("Revenue number backfill conflict: {$from} -> {$target} (local #{$invoice->id})");
                    continue;
                }

                $invoice->forceFill(['invoice_number' => $target])->save();

                $meta = is_array($map->meta_json) ? $map->meta_json : [];
                if (! isset($meta['invoice_number']) && $from !== '') {
                    $meta['invoice_number'] = $from;
                }
                $meta['sales_invoice_number'] = $meta['sales_invoice_number'] ?? $from;
                $meta['revenue_voucher_number'] = $target;
                $map->forceFill(['meta_json' => $meta])->save();

                $this->retargetJournalLabels($invoice, $from, $target);

                $updated++;
                if (count($samples) < 25) {
                    $samples[] = [
                        'id' => (int) $invoice->id,
                        'from' => $from,
                        'to' => $target,
                    ];
                }
            }
        });

        return compact('updated', 'skipped', 'conflicts', 'samples');
    }

    private function retargetJournalLabels(CustomerInvoice $invoice, string $from, string $to): void
    {
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        $journalId = (int) ($invoice->posted_journal_id ?? 0);
        if ($journalId <= 0) {
            return;
        }

        $journal = JournalEntry::withoutGlobalScopes()->whereKey($journalId)->first();
        if (! $journal) {
            return;
        }

        $dirty = false;
        foreach (['reference', 'description'] as $field) {
            $val = (string) ($journal->{$field} ?? '');
            if ($val !== '' && str_contains($val, $from)) {
                $journal->{$field} = str_replace($from, $to, $val);
                $dirty = true;
            }
        }
        if ($dirty) {
            $journal->save();
        }

        if (Schema::hasTable('acc_journal_lines')) {
            DB::table('acc_journal_lines')
                ->where('journal_id', $journalId)
                ->orderBy('id')
                ->get(['id', 'description'])
                ->each(function ($line) use ($from, $to) {
                    $desc = (string) ($line->description ?? '');
                    if ($desc !== '' && str_contains($desc, $from)) {
                        DB::table('acc_journal_lines')->where('id', $line->id)->update([
                            'description' => str_replace($from, $to, $desc),
                        ]);
                    }
                });
        }
    }
}

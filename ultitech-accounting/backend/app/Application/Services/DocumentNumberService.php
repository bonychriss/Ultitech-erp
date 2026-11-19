<?php

namespace App\Application\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function next(int $companyId, string $documentType, string $prefix): string
    {
        return DB::transaction(function () use ($companyId, $documentType, $prefix) {
            $sequence = DocumentSequence::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = DocumentSequence::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'document_type' => $documentType,
                    'prefix' => $prefix,
                    'next_number' => 1,
                ]);
                $sequence = DocumentSequence::withoutGlobalScopes()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
            }

            $number = $sequence->prefix.str_pad((string) $sequence->next_number, 6, '0', STR_PAD_LEFT);
            $sequence->update(['next_number' => $sequence->next_number + 1]);

            return $number;
        });
    }
}

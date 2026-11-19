<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\AccountingPolicy;
use App\Models\DisclosureTemplate;
use App\Models\Framework;
use App\Models\PolicyVersion;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AccountingPolicyService
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function frameworks(): Collection
    {
        return Framework::query()->with(['standards.versions'])->orderBy('code')->get();
    }

    public function createPolicy(AccountingBook $book, array $payload): AccountingPolicy
    {
        return AccountingPolicy::withoutGlobalScopes()->create([
            'company_id' => $book->company_id,
            'book_id' => $book->id,
            'code' => $payload['code'],
            'name' => $payload['name'],
        ]);
    }

    public function addVersion(AccountingPolicy $policy, array $payload, User $user, bool $approve = false): PolicyVersion
    {
        $next = (int) PolicyVersion::query()->where('policy_id', $policy->id)->max('version_no') + 1;

        $version = PolicyVersion::query()->create([
            'policy_id' => $policy->id,
            'version_no' => $next,
            'effective_from' => $payload['effective_from'],
            'policy_json' => $payload['policy_json'] ?? [],
            'status' => $approve ? 'approved' : 'draft',
            'approved_by' => $approve ? $user->id : null,
            'approved_at' => $approve ? now() : null,
        ]);

        $this->audit->record('policy.version_created', 'policy_version', $version->id, null, [
            'policy_id' => $policy->id,
            'version_no' => $next,
            'status' => $version->status,
        ]);

        return $version;
    }

    public function approveVersion(PolicyVersion $version, User $user): PolicyVersion
    {
        if ($version->status === 'approved') {
            return $version;
        }

        $version->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $this->audit->record('policy.version_approved', 'policy_version', $version->id, null, [
            'approved_by' => $user->id,
        ]);

        return $version->fresh();
    }

    public function activePolicy(AccountingBook $book, string $policyCode, string $asOfDate): ?PolicyVersion
    {
        $policy = AccountingPolicy::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->where('code', $policyCode)
            ->first();

        if (! $policy) {
            return null;
        }

        return PolicyVersion::query()
            ->where('policy_id', $policy->id)
            ->where('status', 'approved')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('version_no')
            ->first();
    }

    public function validateBookFrameworkSeparation(AccountingBook $primary, AccountingBook $parallel): void
    {
        if ((int) $primary->company_id !== (int) $parallel->company_id) {
            throw ValidationException::withMessages(['book' => 'Books must belong to the same company.']);
        }

        if ((int) $primary->framework_id === (int) $parallel->framework_id) {
            throw ValidationException::withMessages([
                'framework' => 'Parallel books should use different reporting frameworks when used for dual reporting.',
            ]);
        }
    }

    public function seedDefaultDisclosures(int $frameworkId): void
    {
        $defaults = [
            ['IAS1-PRESENTATION', ['title' => 'Presentation of financial statements', 'required' => true]],
            ['IAS2-INVENTORY', ['title' => 'Inventory measurement disclosure', 'required' => true]],
            ['IFRS15-REVENUE', ['title' => 'Revenue recognition policy', 'required' => true]],
            ['IFRS16-LEASES', ['title' => 'Lease accounting policy', 'required' => false]],
        ];

        foreach ($defaults as [$code, $json]) {
            DisclosureTemplate::query()->updateOrCreate(
                ['framework_id' => $frameworkId, 'code' => $code],
                ['template_json' => $json, 'effective_from' => '2024-01-01']
            );
        }
    }

    public function complianceChecklist(AccountingBook $book): array
    {
        $framework = Framework::query()->with('standards')->find($book->framework_id);
        $policies = AccountingPolicy::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->with(['versions' => fn ($q) => $q->orderByDesc('version_no')])
            ->get();

        return [
            'framework' => $framework?->only(['id', 'code', 'name']),
            'standards_count' => $framework?->standards->count() ?? 0,
            'policies' => $policies->map(function ($policy) {
                $latest = $policy->versions->first();

                return [
                    'code' => $policy->code,
                    'name' => $policy->name,
                    'latest_version' => $latest?->version_no,
                    'status' => $latest?->status,
                    'effective_from' => $latest?->effective_from,
                ];
            })->values(),
            'note' => 'Standards metadata and policies are version-controlled. Statutory completeness requires professional validation.',
        ];
    }
}

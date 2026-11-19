<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\AccountingPolicy;
use App\Models\AuditLog;
use App\Models\ComplianceReport;
use App\Models\FiscalPeriod;
use App\Models\PeriodClose;
use App\Models\PeriodLock;
use App\Models\PolicyVersion;
use App\Models\TaxReturn;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class ComplianceReportService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly DocumentNumberService $numbers,
        private readonly AuditTrailService $audit,
    ) {}

    public function generate(AccountingBook $book, User $user, string $asOfDate): ComplianceReport
    {
        $checks = [];

        $tb = $this->balances->trialBalance((int) $book->company_id, (int) $book->id, $asOfDate);
        $debit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $credit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');
        $checks[] = $this->item('TB_BALANCED', 'Trial balance balanced', Money::equals($debit, $credit), "D {$debit} / C {$credit}");

        $openPeriods = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('status', 'open')
            ->whereDate('end_date', '<', $asOfDate)
            ->count();
        $checks[] = $this->item('PRIOR_PERIODS_CLOSED', 'Prior periods closed', $openPeriods === 0, $openPeriods === 0 ? 'OK' : "{$openPeriods} open prior period(s)");

        $locks = PeriodLock::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->count();
        $closes = PeriodClose::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('book_id', $book->id)
            ->count();
        $checks[] = $this->item('PERIOD_LOCKS_PRESENT', 'Period locks / closes recorded', $locks > 0 || $closes > 0, "locks={$locks}, closes={$closes}");

        $approvedPolicies = PolicyVersion::query()
            ->where('status', 'approved')
            ->whereIn('policy_id', AccountingPolicy::withoutGlobalScopes()
                ->where('company_id', $book->company_id)
                ->where('book_id', $book->id)
                ->pluck('id'))
            ->count();
        $checks[] = $this->item('POLICIES_APPROVED', 'Approved accounting policies exist', $approvedPolicies > 0, "{$approvedPolicies} approved version(s)");

        $taxReturns = TaxReturn::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->whereDate('period_end', '<=', $asOfDate)
            ->count();
        $checks[] = $this->item('TAX_RETURNS_TRACKED', 'Tax returns present for compliance trail', $taxReturns > 0, "{$taxReturns} return(s)", false);

        $auditCount = AuditLog::query()
            ->where('company_id', $book->company_id)
            ->whereDate('created_at', '<=', $asOfDate)
            ->count();
        $checks[] = $this->item('AUDIT_TRAIL_ACTIVE', 'Audit trail has entries', $auditCount > 0, "{$auditCount} audit event(s)");

        $passed = collect($checks)->where('passed', true)->count();
        $failed = collect($checks)->where('passed', false)->where('required', true)->count();

        $report = ComplianceReport::withoutGlobalScopes()->create([
            'company_id' => $book->company_id,
            'book_id' => $book->id,
            'report_number' => $this->numbers->next((int) $book->company_id, 'compliance_report', 'CMP'),
            'as_of_date' => $asOfDate,
            'result_json' => [
                'framework_id' => $book->framework_id,
                'checks' => $checks,
                'summary' => [
                    'passed' => $passed,
                    'failed_required' => $failed,
                    'note' => 'Governance compliance snapshot — not a statutory filing.',
                ],
            ],
            'passed_count' => $passed,
            'failed_count' => $failed,
            'status' => $failed === 0 ? 'passed' : 'attention',
            'generated_by' => $user->id,
        ]);

        $this->audit->record('compliance.report_generated', 'compliance_report', $report->id, null, [
            'status' => $report->status,
            'failed_count' => $failed,
        ]);

        return $report;
    }

    public function auditSearch(int $companyId, array $filters = [])
    {
        $q = AuditLog::query()->where('company_id', $companyId)->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $q->where('action', 'like', '%'.$filters['action'].'%');
        }
        if (! empty($filters['entity_type'])) {
            $q->where('entity_type', $filters['entity_type']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('created_at', '<=', $filters['to']);
        }

        return $q->limit(200)->get();
    }

    private function item(string $code, string $label, bool $passed, string $details, bool $required = true): array
    {
        return compact('code', 'label', 'passed', 'details', 'required');
    }
}

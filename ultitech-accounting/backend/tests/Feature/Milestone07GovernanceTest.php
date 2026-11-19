<?php

namespace Tests\Feature;

use App\Application\Services\ApprovalWorkflowService;
use App\Application\Services\ComplianceReportService;
use App\Application\Services\JournalPostingService;
use App\Application\Services\PeriodCloseService;
use App\Application\Services\YearCloseService;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountType;
use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\Jurisdiction;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Support\Money;
use App\Support\TenantContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Milestone07GovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private AccountingBook $book;
    private FiscalYear $year;
    private FiscalPeriod $jan;
    private FiscalPeriod $feb;
    private Account $cash;
    private Account $revenue;
    private Account $expense;
    private Account $retained;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
    }

    public function test_manual_journal_requires_approval_when_workflow_active(): void
    {
        app(ApprovalWorkflowService::class)->createWorkflow((int) $this->company->id, [
            'code' => 'JE1',
            'name' => 'Journal approval',
            'entity_type' => 'journal_entry',
            'steps' => [['name' => 'Review', 'step_no' => 1]],
        ]);

        $draft = app(JournalPostingService::class)->createDraft($this->book, $this->user, [
            'journal_date' => '2026-01-15',
            'description' => 'Needs approval',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '5000', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '5000'],
            ],
        ], 'manual');

        try {
            app(JournalPostingService::class)->post($draft, $this->user);
            $this->fail('Expected posting to be blocked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approval_status', $e->errors());
        }

        $request = app(ApprovalWorkflowService::class)->submitJournal($draft->fresh(), $this->book, $this->user);
        $this->assertSame('pending', $request->status);

        $approved = app(ApprovalWorkflowService::class)->act($request, $this->user, 'approve');
        $this->assertSame('approved', $approved->status);

        $posted = app(JournalPostingService::class)->post($draft->fresh(), $this->user);
        $this->assertSame('posted', $posted->status);
    }

    public function test_month_end_and_year_end_close(): void
    {
        app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-01-10',
            'description' => 'Jan sale',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->cash->id, 'debit' => '100000', 'credit' => '0'],
                ['account_id' => $this->revenue->id, 'debit' => '0', 'credit' => '100000'],
            ],
        ], 'system');

        app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-01-20',
            'description' => 'Jan expense',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '40000', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '40000'],
            ],
        ], 'system');

        $janClose = app(PeriodCloseService::class)->close($this->book, $this->user, (int) $this->jan->id);
        $this->assertSame('closed', $janClose->status);
        $this->assertSame('closed', $this->jan->fresh()->status);

        app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-02-05',
            'description' => 'Feb sale',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->cash->id, 'debit' => '20000', 'credit' => '0'],
                ['account_id' => $this->revenue->id, 'debit' => '0', 'credit' => '20000'],
            ],
        ], 'system');

        app(PeriodCloseService::class)->close($this->book, $this->user, (int) $this->feb->id);

        $yearClose = app(YearCloseService::class)->close($this->book, $this->user, (int) $this->year->id);
        $this->assertSame('closed', $yearClose->status);
        $this->assertSame('closed', $this->year->fresh()->status);
        // Net income = 100k + 20k - 40k = 80k
        $this->assertTrue(Money::equals(Money::normalize($yearClose->net_income), '80000.0000'));
        $this->assertNotNull($yearClose->closing_journal_id);
    }

    public function test_compliance_report_and_audit_search(): void
    {
        app(PeriodCloseService::class)->close($this->book, $this->user, (int) $this->jan->id);

        $report = app(ComplianceReportService::class)->generate($this->book, $this->user, '2026-01-31');
        $this->assertGreaterThan(0, $report->passed_count);
        $this->assertContains($report->status, ['passed', 'attention']);

        $logs = app(ComplianceReportService::class)->auditSearch((int) $this->company->id, [
            'action' => 'period.closed',
        ]);
        $this->assertNotEmpty($logs);
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'M07',
            'email' => 'm07@ultitech.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'M07',
            'legal_name' => 'M07 Co',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);
        UserCompanyAccess::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role_id' => $adminRole->id,
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->book = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'IFRS-TZ',
            'name' => 'Primary',
            'framework_id' => Framework::query()->where('code', 'IFRS')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => true,
            'status' => 'active',
        ]);

        PostingSequence::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'sequence_key' => 'journal',
            'prefix' => 'JE',
            'next_number' => 1,
            'padding' => 6,
        ]);

        ExchangeRate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'from_currency' => 'TZS',
            'to_currency' => 'TZS',
            'rate_date' => '2026-01-01',
            'rate' => 1,
            'source' => 'system',
            'created_by' => $this->user->id,
        ]);

        $this->year = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $this->jan = FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->year->id,
            'period_number' => 1,
            'name' => 'Jan 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
        ]);
        $this->feb = FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->year->id,
            'period_number' => 2,
            'name' => 'Feb 2026',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'status' => 'open',
        ]);

        $types = AccountType::query()->pluck('id', 'code');
        $make = function (string $code, string $name, string $type, string $balance) use ($types) {
            return Account::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'book_id' => $this->book->id,
                'account_type_id' => $types[$type],
                'code' => $code,
                'name' => $name,
                'normal_balance' => $balance,
                'is_postable' => true,
                'allow_manual_entry' => true,
                'status' => 'active',
                'level' => 1,
            ]);
        };

        $this->cash = $make('1000', 'Cash', 'asset', 'debit');
        $this->retained = $make('3100', 'Retained Earnings', 'equity', 'credit');
        $this->revenue = $make('4000', 'Sales', 'revenue', 'credit');
        $this->expense = $make('5000', 'OpEx', 'expense', 'debit');

        AccountMapping::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'mapping_key' => 'retained_earnings',
            'account_id' => $this->retained->id,
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }
}

<?php

namespace Tests\Feature;

use App\Application\Services\AccountBalanceService;
use App\Application\Services\JournalPostingService;
use App\Application\Services\JournalReversalService;
use App\Models\Account;
use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Framework;
use App\Models\JournalEntry;
use App\Models\Jurisdiction;
use App\Models\PeriodLock;
use App\Models\PostingSequence;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Support\Money;
use App\Support\TenantContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone01AccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private AccountingBook $book;

    private Account $cash;

    private Account $expense;

    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->bootDemo();
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        $this->expectExceptionMessage('Journal is unbalanced');

        app(JournalPostingService::class)->createDraft($this->book, $this->user, [
            'journal_date' => '2026-03-15',
            'description' => 'Unbalanced',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '50'],
            ],
        ]);
    }

    public function test_inactive_account_is_rejected(): void
    {
        $this->expense->update(['status' => 'inactive']);
        $this->expectExceptionMessage('Account is inactive');

        app(JournalPostingService::class)->createDraft($this->book, $this->user, $this->balancedPayload());
    }

    public function test_non_postable_account_is_rejected(): void
    {
        $this->expense->update(['is_postable' => false]);
        $this->expectExceptionMessage('Account is not postable');

        app(JournalPostingService::class)->createDraft($this->book, $this->user, $this->balancedPayload());
    }

    public function test_posted_journal_and_trial_balance_reconcile(): void
    {
        $journal = app(JournalPostingService::class)->createAndPost(
            $this->book,
            $this->user,
            $this->balancedPayload()
        );

        $this->assertSame('posted', $journal->status);
        $this->assertTrue(Money::equals((string) $journal->total_debit, (string) $journal->total_credit));

        $tb = app(AccountBalanceService::class)->trialBalance((int) $this->company->id, (int) $this->book->id);
        $totalDebit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['debit']), '0.0000');
        $totalCredit = $tb->reduce(fn ($c, $r) => Money::add($c, $r['credit']), '0.0000');
        $this->assertTrue(Money::equals($totalDebit, $totalCredit));
    }

    public function test_posted_journal_is_immutable_via_api(): void
    {
        $journal = app(JournalPostingService::class)->createAndPost(
            $this->book,
            $this->user,
            $this->balancedPayload()
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeaders($this->tenantHeaders())
            ->patchJson('/api/v1/journals/'.$journal->id, $this->balancedPayload());

        $response->assertStatus(422);
    }

    public function test_reversal_nets_to_zero_and_is_traceable(): void
    {
        $original = app(JournalPostingService::class)->createAndPost(
            $this->book,
            $this->user,
            $this->balancedPayload()
        );

        $reversal = app(JournalReversalService::class)->reverse($original->fresh('lines'), $this->user, 'Correction');

        $this->assertSame('posted', $reversal->status);
        $original->refresh();
        $this->assertSame('reversed', $original->status);
        $this->assertDatabaseHas('acc_journal_reversals', [
            'original_journal_id' => $original->id,
            'reversal_journal_id' => $reversal->id,
        ]);
        $this->assertDatabaseHas('acc_audit_logs', [
            'action' => 'journal.reversed',
            'entity_id' => (string) $original->id,
        ]);

        $tb = app(AccountBalanceService::class)->trialBalance((int) $this->company->id, (int) $this->book->id);
        foreach ($tb as $row) {
            $this->assertTrue(Money::equals($row['debit'], $row['credit']));
        }
    }

    public function test_locked_period_blocks_posting(): void
    {
        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereDate('start_date', '<=', '2026-03-15')
            ->whereDate('end_date', '>=', '2026-03-15')
            ->firstOrFail();

        PeriodLock::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'period_id' => $period->id,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
            'reason' => 'Month end',
        ]);

        $this->expectExceptionMessage('locked');
        app(JournalPostingService::class)->createDraft($this->book, $this->user, $this->balancedPayload());
    }

    public function test_duplicate_source_hash_is_prevented(): void
    {
        $payload = $this->balancedPayload();
        $payload['source_hash'] = 'invoice:1001';

        app(JournalPostingService::class)->createAndPost($this->book, $this->user, $payload);

        $this->expectExceptionMessage('source identity');
        app(JournalPostingService::class)->createAndPost($this->book, $this->user, $payload);
    }

    public function test_company_isolation_on_accounts_api(): void
    {
        $other = $this->createSecondCompany();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeaders([
                'X-Company-Id' => (string) $other['company']->id,
                'X-Book-Id' => (string) $other['book']->id,
            ])
            ->getJson('/api/v1/accounts');

        $response->assertForbidden();
    }

    public function test_multi_book_separation(): void
    {
        $bookB = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'SME-TZ',
            'name' => 'SME Book',
            'framework_id' => Framework::query()->where('code', 'IFRS_SME')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => false,
            'status' => 'active',
        ]);

        PostingSequence::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $bookB->id,
            'sequence_key' => 'journal',
            'prefix' => 'SME',
            'next_number' => 1,
            'padding' => 6,
        ]);

        $cashB = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $bookB->id,
            'account_type_id' => $this->cash->account_type_id,
            'code' => '1000',
            'name' => 'Cash SME',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);
        $expenseB = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $bookB->id,
            'account_type_id' => $this->expense->account_type_id,
            'code' => '5100',
            'name' => 'Expense SME',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);

        app(JournalPostingService::class)->createAndPost($this->book, $this->user, $this->balancedPayload());

        $tbA = app(AccountBalanceService::class)->trialBalance((int) $this->company->id, (int) $this->book->id);
        $tbB = app(AccountBalanceService::class)->trialBalance((int) $this->company->id, (int) $bookB->id);

        $this->assertNotEmpty($tbA);
        $this->assertTrue($tbB->isEmpty());
        $this->assertNotNull($cashB);
        $this->assertNotNull($expenseB);
    }

    public function test_currency_conversion_to_functional(): void
    {
        $journal = app(JournalPostingService::class)->createAndPost($this->book, $this->user, [
            'journal_date' => '2026-03-15',
            'description' => 'USD expense',
            'currency_code' => 'USD',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '10', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '10'],
            ],
        ]);

        $this->assertTrue(Money::equals((string) $journal->total_debit, '25000.0000'));
        $this->assertTrue(Money::equals((string) $journal->lines->first()->functional_debit, '25000.0000')
            || Money::equals((string) $journal->lines->first()->functional_credit, '25000.0000'));
    }

    public function test_login_and_permission_enforcement(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);
        $login->assertOk()->assertJsonStructure(['token', 'companies']);

        $viewer = User::factory()->create(['password' => 'password']);
        $viewerRole = Role::query()->whereNull('company_id')->where('code', 'viewer')->firstOrFail();
        UserCompanyAccess::query()->create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role_id' => $viewerRole->id,
            'is_default' => true,
            'status' => 'active',
        ]);

        $denied = $this->actingAs($viewer, 'sanctum')
            ->withHeaders($this->tenantHeaders())
            ->postJson('/api/v1/journals', $this->balancedPayload());

        $denied->assertForbidden();
    }

    public function test_idempotent_post_returns_same_journal(): void
    {
        $draft = app(JournalPostingService::class)->createDraft($this->book, $this->user, $this->balancedPayload());
        $service = app(JournalPostingService::class);
        $first = $service->post($draft, $this->user, 'idem-123');
        $second = $service->post($draft->fresh(), $this->user, 'idem-123');

        $this->assertSame($first->id, $second->id);
    }

    private function bootDemo(): void
    {
        $adminRole = Role::query()->whereNull('company_id')->where('code', 'admin')->firstOrFail();
        $this->user = User::factory()->create(['password' => 'password']);
        $this->company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'TEST1',
            'legal_name' => 'Test Company One',
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
        ExchangeRate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'from_currency' => 'USD',
            'to_currency' => 'TZS',
            'rate_date' => '2026-01-01',
            'rate' => 2500,
            'source' => 'manual',
            'created_by' => $this->user->id,
        ]);

        $year = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        FiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 3,
            'name' => 'Mar 2026',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'status' => 'open',
        ]);

        $types = \App\Models\AccountType::query()->pluck('id', 'code');
        $this->cash = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['asset'],
            'code' => '1000',
            'name' => 'Cash',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);
        $this->expense = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['expense'],
            'code' => '5100',
            'name' => 'Rent',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);
        $this->revenue = Account::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'book_id' => $this->book->id,
            'account_type_id' => $types['revenue'],
            'code' => '4000',
            'name' => 'Sales',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'allow_manual_entry' => true,
            'status' => 'active',
            'level' => 1,
        ]);

        app(TenantContext::class)->setUser($this->user);
        app(TenantContext::class)->setCompany($this->company);
        app(TenantContext::class)->setBook($this->book);
    }

    private function balancedPayload(): array
    {
        return [
            'journal_date' => '2026-03-15',
            'description' => 'Rent payment',
            'currency_code' => 'TZS',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '100000', 'credit' => '0', 'description' => 'Rent'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '100000', 'description' => 'Cash'],
            ],
        ];
    }

    private function tenantHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->company->id,
            'X-Book-Id' => (string) $this->book->id,
        ];
    }

    private function createSecondCompany(): array
    {
        $company = Company::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'TEST2',
            'legal_name' => 'Test Company Two',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);
        $book = AccountingBook::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'IFRS-TZ',
            'name' => 'Primary',
            'framework_id' => Framework::query()->where('code', 'IFRS')->value('id'),
            'jurisdiction_id' => Jurisdiction::query()->where('code', 'TZ')->value('id'),
            'functional_currency_code' => 'TZS',
            'is_primary' => true,
            'status' => 'active',
        ]);

        return compact('company', 'book');
    }
}

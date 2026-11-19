<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_cost_centers_unique');
        });

        Schema::create('acc_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_budgets_unique');
        });

        Schema::create('acc_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('acc_budgets')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('acc_fiscal_periods')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('acc_cost_centers')->nullOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['budget_id', 'period_id', 'account_id', 'cost_center_id'], 'acc_budget_lines_unique');
        });

        Schema::create('acc_fx_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('acc_fiscal_periods')->restrictOnDelete();
            $table->date('revaluation_date');
            $table->char('functional_currency', 3);
            $table->decimal('total_gain', 19, 4)->default(0);
            $table->decimal('total_loss', 19, 4)->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('status', 20)->default('posted');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('acc_fx_revaluation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fx_revaluation_id')->constrained('acc_fx_revaluations')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('foreign_balance', 19, 4);
            $table->decimal('book_functional', 19, 4);
            $table->decimal('closing_rate', 18, 8);
            $table->decimal('revalued_functional', 19, 4);
            $table->decimal('difference', 19, 4);
            $table->timestamps();
        });

        Schema::create('acc_consolidation_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->foreignId('parent_book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->char('reporting_currency', 3);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'acc_consol_groups_unique');
        });

        Schema::create('acc_consolidation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('acc_consolidation_groups')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('member_company_id')->constrained('acc_companies')->restrictOnDelete();
            $table->foreignId('member_book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->decimal('ownership_percent', 8, 4)->default(100);
            $table->boolean('is_parent')->default(false);
            $table->timestamps();
            $table->unique(['group_id', 'member_book_id'], 'acc_consol_members_unique');
        });

        Schema::create('acc_consolidation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('acc_consolidation_groups')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->date('as_of_date');
            $table->json('result_json');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('acc_leases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('lease_number', 40);
            $table->string('name', 200);
            $table->date('commencement_date');
            $table->unsignedInteger('term_months');
            $table->decimal('payment_amount', 19, 4);
            $table->decimal('annual_rate_percent', 8, 4);
            $table->decimal('initial_liability', 19, 4);
            $table->decimal('rou_asset_cost', 19, 4);
            $table->decimal('liability_balance', 19, 4);
            $table->decimal('rou_net_book_value', 19, 4);
            $table->foreignId('rou_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('liability_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('interest_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('depreciation_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('commencement_journal_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'lease_number'], 'acc_leases_unique');
        });

        Schema::create('acc_lease_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained('acc_leases')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->unsignedInteger('period_no');
            $table->date('due_date');
            $table->decimal('opening_liability', 19, 4);
            $table->decimal('interest', 19, 4);
            $table->decimal('payment', 19, 4);
            $table->decimal('principal', 19, 4);
            $table->decimal('closing_liability', 19, 4);
            $table->decimal('depreciation', 19, 4);
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();
            $table->unique(['lease_id', 'period_no'], 'acc_lease_schedules_unique');
        });

        Schema::create('acc_impairment_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('asset_type', 40);
            $table->unsignedBigInteger('asset_id');
            $table->date('test_date');
            $table->decimal('carrying_amount', 19, 4);
            $table->decimal('recoverable_amount', 19, 4);
            $table->decimal('impairment_loss', 19, 4);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('status', 20)->default('posted');
            $table->unsignedBigInteger('created_by');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_impairment_tests');
        Schema::dropIfExists('acc_lease_schedules');
        Schema::dropIfExists('acc_leases');
        Schema::dropIfExists('acc_consolidation_runs');
        Schema::dropIfExists('acc_consolidation_members');
        Schema::dropIfExists('acc_consolidation_groups');
        Schema::dropIfExists('acc_fx_revaluation_lines');
        Schema::dropIfExists('acc_fx_revaluations');
        Schema::dropIfExists('acc_budget_lines');
        Schema::dropIfExists('acc_budgets');
        Schema::dropIfExists('acc_cost_centers');
    }
};

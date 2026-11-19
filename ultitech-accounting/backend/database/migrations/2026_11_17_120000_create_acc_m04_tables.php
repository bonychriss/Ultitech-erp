<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->string('statement_number', 40);
            $table->date('statement_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 19, 4)->default(0);
            $table->decimal('closing_balance', 19, 4)->default(0);
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'bank_account_id', 'statement_number'], 'acc_bank_statements_unique');
        });

        Schema::create('acc_bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('statement_id')->constrained('acc_bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('line_date');
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->boolean('is_matched')->default(false);
            $table->timestamps();
        });

        Schema::create('acc_bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->foreignId('statement_id')->constrained('acc_bank_statements')->restrictOnDelete();
            $table->date('reconciliation_date');
            $table->decimal('gl_balance', 19, 4)->default(0);
            $table->decimal('statement_balance', 19, 4)->default(0);
            $table->decimal('unreconciled_total', 19, 4)->default(0);
            $table->string('status', 20)->default('in_progress');
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_bank_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('reconciliation_id')->constrained('acc_bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('statement_line_id')->constrained('acc_bank_statement_lines')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->constrained('acc_bank_transactions')->restrictOnDelete();
            $table->decimal('matched_amount', 19, 4);
            $table->timestamps();
            $table->unique(['statement_line_id', 'bank_transaction_id'], 'acc_bank_matches_unique');
        });

        Schema::create('acc_depreciation_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('acc_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->foreignId('asset_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('accum_depr_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('depr_expense_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('depreciation_method_id')->constrained('acc_depreciation_methods')->restrictOnDelete();
            $table->unsignedInteger('useful_life_months')->default(36);
            $table->decimal('salvage_percent', 8, 4)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_asset_categories_unique');
        });

        Schema::create('acc_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('acc_asset_categories')->restrictOnDelete();
            $table->string('asset_code', 40);
            $table->string('name', 200);
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 19, 4);
            $table->decimal('salvage_value', 19, 4)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->decimal('accum_depreciation', 19, 4)->default(0);
            $table->decimal('net_book_value', 19, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('acquisition_journal_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'asset_code'], 'acc_fixed_assets_unique');
            $table->foreign('acquisition_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        Schema::create('acc_depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('acc_fiscal_periods')->restrictOnDelete();
            $table->date('run_date');
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->string('status', 20)->default('posted');
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'period_id'], 'acc_depreciation_runs_unique');
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        Schema::create('acc_depreciation_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('acc_depreciation_runs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('acc_fixed_assets')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
        });

        Schema::create('acc_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('acc_fixed_assets')->restrictOnDelete();
            $table->date('disposal_date');
            $table->decimal('proceeds', 19, 4)->default(0);
            $table->decimal('net_book_value', 19, 4)->default(0);
            $table->decimal('gain_loss', 19, 4)->default(0);
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        Schema::create('acc_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('sku', 40);
            $table->string('name', 200);
            $table->string('valuation_method', 20)->default('weighted_average');
            $table->foreignId('inventory_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('cogs_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->decimal('quantity_on_hand', 19, 4)->default(0);
            $table->decimal('unit_cost', 19, 4)->default(0);
            $table->decimal('total_value', 19, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'sku'], 'acc_inventory_items_unique');
        });

        Schema::create('acc_inventory_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('acc_inventory_items')->cascadeOnDelete();
            $table->date('received_date');
            $table->decimal('quantity_remaining', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->timestamps();
        });

        Schema::create('acc_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('acc_inventory_items')->restrictOnDelete();
            $table->string('movement_type', 30);
            $table->date('movement_date');
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_cost', 19, 4)->default(0);
            $table->decimal('total_cost', 19, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_inventory_movements');
        Schema::dropIfExists('acc_inventory_layers');
        Schema::dropIfExists('acc_inventory_items');
        Schema::dropIfExists('acc_asset_disposals');
        Schema::dropIfExists('acc_depreciation_run_lines');
        Schema::dropIfExists('acc_depreciation_runs');
        Schema::dropIfExists('acc_fixed_assets');
        Schema::dropIfExists('acc_asset_categories');
        Schema::dropIfExists('acc_depreciation_methods');
        Schema::dropIfExists('acc_bank_matches');
        Schema::dropIfExists('acc_bank_reconciliations');
        Schema::dropIfExists('acc_bank_statement_lines');
        Schema::dropIfExists('acc_bank_statements');
    }
};

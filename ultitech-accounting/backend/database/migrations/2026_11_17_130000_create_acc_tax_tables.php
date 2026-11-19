<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_tax_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('direction', 20)->default('both');
            $table->timestamps();
        });

        Schema::create('acc_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jurisdiction_id')->constrained('acc_jurisdictions')->cascadeOnDelete();
            $table->foreignId('tax_type_id')->constrained('acc_tax_types')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->decimal('rate_percent', 8, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamps();
            $table->unique(['jurisdiction_id', 'code', 'effective_from'], 'acc_tax_rates_unique');
        });

        Schema::create('acc_tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('jurisdiction_id')->constrained('acc_jurisdictions')->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->constrained('acc_tax_rates')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('applies_to', 30)->default('both');
            $table->boolean('is_recoverable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'acc_tax_codes_unique');
        });

        Schema::create('acc_tax_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained('acc_tax_codes')->restrictOnDelete();
            $table->foreignId('tax_rate_id')->constrained('acc_tax_rates')->restrictOnDelete();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->date('tax_date');
            $table->string('direction', 20);
            $table->decimal('taxable_amount', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('rate_percent', 8, 4);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('status', 20)->default('posted');
            $table->timestamps();
            $table->index(['company_id', 'book_id', 'tax_date'], 'acc_tax_txn_company_book_date_idx');
        });

        Schema::create('acc_tax_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('jurisdiction_id')->constrained('acc_jurisdictions')->restrictOnDelete();
            $table->foreignId('tax_type_id')->constrained('acc_tax_types')->restrictOnDelete();
            $table->string('return_number', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('output_tax', 19, 4)->default(0);
            $table->decimal('input_tax', 19, 4)->default(0);
            $table->decimal('net_tax', 19, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('prepared_by');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'return_number'], 'acc_tax_returns_unique');
        });

        Schema::create('acc_tax_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_return_id')->constrained('acc_tax_returns')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('tax_transaction_id')->constrained('acc_tax_transactions')->restrictOnDelete();
            $table->string('line_type', 20);
            $table->decimal('taxable_amount', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->timestamps();
        });

        Schema::create('acc_tax_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('tax_return_id')->constrained('acc_tax_returns')->cascadeOnDelete();
            $table->decimal('gl_balance', 19, 4)->default(0);
            $table->decimal('return_balance', 19, 4)->default(0);
            $table->decimal('difference', 19, 4)->default(0);
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_tax_reconciliations');
        Schema::dropIfExists('acc_tax_return_lines');
        Schema::dropIfExists('acc_tax_returns');
        Schema::dropIfExists('acc_tax_transactions');
        Schema::dropIfExists('acc_tax_codes');
        Schema::dropIfExists('acc_tax_rates');
        Schema::dropIfExists('acc_tax_types');
    }
};

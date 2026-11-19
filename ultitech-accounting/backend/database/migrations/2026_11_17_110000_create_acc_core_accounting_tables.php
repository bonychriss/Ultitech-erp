<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->char('currency_code', 3)->default('TZS');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->char('currency_code', 3)->default('TZS');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('bank_name', 150)->nullable();
            $table->string('account_number', 80)->nullable();
            $table->char('currency_code', 3);
            $table->foreignId('gl_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->string('account_type', 30)->default('bank');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_bank_accounts_unique');
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->char('currency_code', 3);
            $table->foreignId('gl_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_cash_accounts_unique');
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('acc_customers')->restrictOnDelete();
            $table->string('invoice_number', 40);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->decimal('balance_due', 19, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'invoice_number'], 'acc_customer_invoices_number_unique');
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        Schema::create('acc_customer_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('acc_customer_invoices')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('description', 255);
            $table->decimal('quantity', 19, 4)->default(1);
            $table->decimal('unit_price', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);
            $table->foreignId('revenue_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('acc_customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('acc_customers')->restrictOnDelete();
            $table->string('receipt_number', 40);
            $table->date('receipt_date');
            $table->char('currency_code', 3);
            $table->decimal('amount', 19, 4);
            $table->foreignId('bank_account_id')->nullable()->constrained('acc_bank_accounts')->nullOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('acc_cash_accounts')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'receipt_number'], 'acc_customer_receipts_number_unique');
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        Schema::create('acc_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('receipt_id')->constrained('acc_customer_receipts')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('acc_customer_invoices')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['receipt_id', 'invoice_id']);
        });

        Schema::create('acc_customer_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('acc_customers')->restrictOnDelete();
            $table->string('credit_note_number', 40);
            $table->date('credit_note_date');
            $table->decimal('total', 19, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'credit_note_number'], 'acc_customer_cn_number_unique');
        });

        Schema::create('acc_supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('acc_suppliers')->restrictOnDelete();
            $table->string('bill_number', 40);
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->decimal('balance_due', 19, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'bill_number'], 'acc_supplier_bills_number_unique');
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        Schema::create('acc_supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('acc_supplier_bills')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('description', 255);
            $table->decimal('quantity', 19, 4)->default(1);
            $table->decimal('unit_price', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);
            $table->foreignId('expense_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('acc_supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('acc_suppliers')->restrictOnDelete();
            $table->string('payment_number', 40);
            $table->date('payment_date');
            $table->char('currency_code', 3);
            $table->decimal('amount', 19, 4);
            $table->foreignId('bank_account_id')->nullable()->constrained('acc_bank_accounts')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'payment_number'], 'acc_supplier_payments_number_unique');
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        Schema::create('acc_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('acc_supplier_payments')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('acc_supplier_bills')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['payment_id', 'bill_id']);
        });

        Schema::create('acc_supplier_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('acc_suppliers')->restrictOnDelete();
            $table->string('credit_note_number', 40);
            $table->date('credit_note_date');
            $table->decimal('total', 19, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'credit_note_number'], 'acc_supplier_cn_number_unique');
        });

        Schema::create('acc_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'bank_account_id', 'transaction_date'], 'acc_bank_txn_company_bank_date_idx');
        });

        Schema::create('acc_cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('transfer_number', 40);
            $table->date('transfer_date');
            $table->foreignId('from_bank_account_id')->nullable()->constrained('acc_bank_accounts')->nullOnDelete();
            $table->foreignId('to_bank_account_id')->nullable()->constrained('acc_bank_accounts')->nullOnDelete();
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'transfer_number'], 'acc_cash_transfers_number_unique');
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_cash_transfers');
        Schema::dropIfExists('acc_bank_transactions');
        Schema::dropIfExists('acc_supplier_credit_notes');
        Schema::dropIfExists('acc_payment_allocations');
        Schema::dropIfExists('acc_supplier_payments');
        Schema::dropIfExists('acc_supplier_bill_lines');
        Schema::dropIfExists('acc_supplier_bills');
        Schema::dropIfExists('acc_customer_credit_notes');
        Schema::dropIfExists('acc_receipt_allocations');
        Schema::dropIfExists('acc_customer_receipts');
        Schema::dropIfExists('acc_customer_invoice_lines');
        Schema::dropIfExists('acc_customer_invoices');
        Schema::dropIfExists('acc_cash_accounts');
        Schema::dropIfExists('acc_bank_accounts');
        Schema::dropIfExists('acc_suppliers');
        Schema::dropIfExists('acc_customers');
    }
};

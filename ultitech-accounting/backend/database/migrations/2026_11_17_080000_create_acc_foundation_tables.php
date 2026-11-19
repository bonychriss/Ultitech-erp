<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 150);
            $table->string('module', 50);
            $table->timestamps();
        });

        Schema::create('acc_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('acc_role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('acc_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('acc_permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('acc_currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 100);
            $table->string('symbol', 10)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('acc_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_jurisdictions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->char('default_currency', 3);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->foreign('default_currency')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 30)->unique();
            $table->string('legal_name', 200);
            $table->string('trading_name', 200)->nullable();
            $table->char('country_code', 2);
            $table->char('base_currency_code', 3);
            $table->string('timezone', 64)->default('Africa/Dar_es_Salaam');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('base_currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('acc_companies')->cascadeOnDelete();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('acc_user_company_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('acc_users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('acc_roles')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['user_id', 'company_id']);
        });

        Schema::table('acc_roles', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('acc_companies')->nullOnDelete();
        });

        Schema::create('acc_accounting_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->foreignId('framework_id')->constrained('acc_frameworks')->restrictOnDelete();
            $table->foreignId('jurisdiction_id')->constrained('acc_jurisdictions')->restrictOnDelete();
            $table->char('functional_currency_code', 3);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->foreign('functional_currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->string('source', 50)->default('manual');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'from_currency', 'to_currency', 'rate_date'], 'acc_fx_unique');
            $table->foreign('from_currency')->references('code')->on('acc_currencies');
            $table->foreign('to_currency')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('acc_fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->cascadeOnDelete();
            $table->unsignedTinyInteger('period_number');
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'period_number']);
        });

        Schema::create('acc_period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('acc_fiscal_periods')->cascadeOnDelete();
            $table->timestamp('locked_at');
            $table->unsignedBigInteger('locked_by');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'period_id'], 'acc_period_locks_unique');
        });

        Schema::create('acc_account_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->string('fs_classification', 50);
            $table->timestamps();
        });

        Schema::create('acc_account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->foreignId('account_type_id')->constrained('acc_account_types')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_account_groups_unique');
        });

        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->foreignId('account_type_id')->constrained('acc_account_types')->restrictOnDelete();
            $table->foreignId('account_group_id')->nullable()->constrained('acc_account_groups')->nullOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('allow_manual_entry')->default(true);
            $table->char('currency_code', 3)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('level')->default(1);
            $table->string('path', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_accounts_unique');
            $table->foreign('parent_id')->references('id')->on('acc_accounts')->nullOnDelete();
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->string('mapping_key', 100);
            $table->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'mapping_key'], 'acc_account_mappings_unique');
        });

        Schema::create('acc_account_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('acc_accounts')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('acc_fiscal_periods')->restrictOnDelete();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->unsignedBigInteger('posted_journal_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'account_id', 'period_id'], 'acc_account_openings_unique');
        });

        Schema::create('acc_journal_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('acc_posting_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->string('sequence_key', 40);
            $table->string('prefix', 20)->default('JE');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'sequence_key'], 'acc_posting_sequences_unique');
        });

        Schema::create('acc_posting_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->string('batch_number', 40);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('acc_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('acc_fiscal_periods')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('acc_journal_sources')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('acc_posting_batches')->nullOnDelete();
            $table->string('journal_number', 40);
            $table->date('journal_date');
            $table->date('posting_date');
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default('draft');
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->decimal('total_debit', 19, 4)->default(0);
            $table->decimal('total_credit', 19, 4)->default(0);
            $table->string('source_type', 80)->nullable();
            $table->string('source_id_ref', 80)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'journal_number'], 'acc_journals_number_unique');
            $table->unique(['company_id', 'book_id', 'source_hash'], 'acc_journals_source_hash_unique');
            $table->index(['company_id', 'book_id', 'journal_date']);
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('acc_journal_entries')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->decimal('functional_debit', 19, 4)->default(0);
            $table->decimal('functional_credit', 19, 4)->default(0);
            $table->json('dimensions_json')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'book_id', 'account_id']);
            $table->foreign('currency_code')->references('code')->on('acc_currencies');
        });

        Schema::create('acc_journal_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('original_journal_id')->unique()->constrained('acc_journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_id')->constrained('acc_journal_entries')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->unsignedBigInteger('reversed_by');
            $table->string('reason', 255);
            $table->timestamps();
        });

        Schema::create('acc_journal_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('journal_id')->constrained('acc_journal_entries')->cascadeOnDelete();
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });

        Schema::create('acc_posting_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('book_id')->nullable()->index();
            $table->string('code', 50);
            $table->json('rule_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('acc_standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('acc_frameworks')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('title', 200);
            $table->timestamps();
            $table->unique(['framework_id', 'code']);
        });

        Schema::create('acc_standard_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_id')->constrained('acc_standards')->cascadeOnDelete();
            $table->string('version_label', 50);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_accounting_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'code'], 'acc_policies_unique');
        });

        Schema::create('acc_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('acc_accounting_policies')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->date('effective_from');
            $table->json('policy_json')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'version_no']);
        });

        Schema::create('acc_disclosure_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('acc_frameworks')->cascadeOnDelete();
            $table->string('code', 50);
            $table->json('template_json')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_reporting_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('acc_frameworks')->cascadeOnDelete();
            $table->string('code', 50);
            $table->json('template_json')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_compliance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jurisdiction_id')->constrained('acc_jurisdictions')->cascadeOnDelete();
            $table->string('code', 50);
            $table->json('rule_json')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action', 50);
            $table->string('entity_type', 80);
            $table->string('entity_id', 80)->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'action', 'created_at']);
        });

        Schema::create('acc_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('prefix', 20)->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'document_type']);
        });

        Schema::create('acc_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->char('key_hash', 64);
            $table->string('request_path', 200);
            $table->unsignedInteger('response_code');
            $table->json('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'key_hash']);
        });

        Schema::create('acc_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 80);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload_json');
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'available_at']);
        });

        Schema::table('acc_account_openings', function (Blueprint $table) {
            $table->foreign('posted_journal_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tables = [
            'acc_outbox_events',
            'acc_idempotency_keys',
            'acc_document_sequences',
            'acc_audit_logs',
            'acc_compliance_rules',
            'acc_reporting_templates',
            'acc_disclosure_templates',
            'acc_policy_versions',
            'acc_accounting_policies',
            'acc_standard_versions',
            'acc_standards',
            'acc_posting_rules',
            'acc_journal_attachments',
            'acc_journal_reversals',
            'acc_journal_lines',
            'acc_journal_entries',
            'acc_posting_batches',
            'acc_posting_sequences',
            'acc_journal_sources',
            'acc_account_openings',
            'acc_account_mappings',
            'acc_accounts',
            'acc_account_groups',
            'acc_account_types',
            'acc_period_locks',
            'acc_fiscal_periods',
            'acc_fiscal_years',
            'acc_exchange_rates',
            'acc_accounting_books',
            'acc_user_company_access',
            'acc_branches',
            'acc_company_settings',
            'acc_companies',
            'acc_jurisdictions',
            'acc_frameworks',
            'acc_currencies',
            'acc_role_permissions',
            'acc_roles',
            'acc_permissions',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};

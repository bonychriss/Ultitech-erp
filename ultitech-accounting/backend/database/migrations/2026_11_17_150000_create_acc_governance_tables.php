<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('entity_type', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'acc_approval_workflows_unique');
        });

        Schema::create('acc_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('acc_approval_workflows')->cascadeOnDelete();
            $table->unsignedInteger('step_no');
            $table->string('name', 150);
            $table->string('required_permission', 80)->nullable();
            $table->timestamps();
            $table->unique(['workflow_id', 'step_no'], 'acc_approval_steps_unique');
        });

        Schema::create('acc_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('acc_accounting_books')->nullOnDelete();
            $table->foreignId('workflow_id')->constrained('acc_approval_workflows')->restrictOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('current_step')->default(1);
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'entity_type', 'entity_id'], 'acc_approval_req_entity_idx');
            $table->index(['company_id', 'status'], 'acc_approval_req_status_idx');
        });

        Schema::create('acc_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('acc_approval_requests')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->unsignedInteger('step_no');
            $table->string('action', 20);
            $table->unsignedBigInteger('acted_by');
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();
        });

        Schema::create('acc_close_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->cascadeOnDelete();
            $table->string('close_type', 20);
            $table->unsignedBigInteger('period_id')->nullable();
            $table->unsignedBigInteger('fiscal_year_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('acc_close_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('acc_close_checklists')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 200);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_passed')->default(false);
            $table->text('details')->nullable();
            $table->timestamps();
            $table->unique(['checklist_id', 'code'], 'acc_close_items_unique');
        });

        Schema::create('acc_period_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('acc_fiscal_periods')->restrictOnDelete();
            $table->foreignId('checklist_id')->nullable()->constrained('acc_close_checklists')->nullOnDelete();
            $table->string('status', 20)->default('closed');
            $table->timestamp('closed_at');
            $table->unsignedBigInteger('closed_by');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'period_id'], 'acc_period_closes_unique');
        });

        Schema::create('acc_year_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->foreignId('checklist_id')->nullable()->constrained('acc_close_checklists')->nullOnDelete();
            $table->unsignedBigInteger('closing_journal_id')->nullable();
            $table->decimal('net_income', 19, 4)->default(0);
            $table->string('status', 20)->default('closed');
            $table->timestamp('closed_at');
            $table->unsignedBigInteger('closed_by');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'book_id', 'fiscal_year_id'], 'acc_year_closes_unique');
        });

        Schema::create('acc_compliance_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->string('report_number', 40);
            $table->date('as_of_date');
            $table->json('result_json');
            $table->unsignedInteger('passed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20)->default('generated');
            $table->unsignedBigInteger('generated_by');
            $table->timestamps();
            $table->unique(['company_id', 'report_number'], 'acc_compliance_reports_unique');
        });

        Schema::table('acc_journal_entries', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('not_required')->after('status');
            $table->unsignedBigInteger('approval_request_id')->nullable()->after('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('acc_journal_entries', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approval_request_id']);
        });
        Schema::dropIfExists('acc_compliance_reports');
        Schema::dropIfExists('acc_year_closes');
        Schema::dropIfExists('acc_period_closes');
        Schema::dropIfExists('acc_close_checklist_items');
        Schema::dropIfExists('acc_close_checklists');
        Schema::dropIfExists('acc_approval_actions');
        Schema::dropIfExists('acc_approval_requests');
        Schema::dropIfExists('acc_approval_steps');
        Schema::dropIfExists('acc_approval_workflows');
    }
};

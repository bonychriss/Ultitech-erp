<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_erp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('connection_name', 60)->default('erp_ultimate');
            $table->string('erp_database', 191);
            $table->boolean('read_only')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 40)->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'acc_erp_connections_unique');
        });

        Schema::create('acc_erp_id_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('acc_erp_connections')->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->string('erp_id', 80);
            $table->unsignedBigInteger('local_id');
            $table->json('meta_json')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'entity_type', 'erp_id'], 'acc_erp_id_maps_unique');
            $table->index(['company_id', 'entity_type', 'local_id'], 'acc_erp_id_maps_local_idx');
        });

        Schema::create('acc_erp_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('acc_companies')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('acc_accounting_books')->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('acc_erp_connections')->restrictOnDelete();
            $table->string('run_number', 40);
            $table->string('mode', 40)->default('pull');
            $table->json('scopes_json');
            $table->json('stats_json')->nullable();
            $table->string('status', 20)->default('running');
            $table->text('message')->nullable();
            $table->unsignedBigInteger('started_by');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'run_number'], 'acc_erp_sync_runs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_erp_sync_runs');
        Schema::dropIfExists('acc_erp_id_maps');
        Schema::dropIfExists('acc_erp_connections');
    }
};

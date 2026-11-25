<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE acc_erp_sync_runs MODIFY status VARCHAR(40) NOT NULL DEFAULT 'running'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE acc_erp_sync_runs MODIFY status VARCHAR(20) NOT NULL DEFAULT 'running'");
    }
};

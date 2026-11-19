<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `path` already exists on acc_accounts; used as origin:
        // template | user | banking | erp
        if (! Schema::hasColumn('acc_accounts', 'path')) {
            Schema::table('acc_accounts', function (Blueprint $table) {
                $table->string('path', 40)->nullable()->after('level');
            });
        }
    }

    public function down(): void
    {
        // Keep column; it is part of core schema.
    }
};

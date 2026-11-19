<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_wallet_balance_watches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('book_id')->index();
            $table->string('wallet_type', 20); // bank|cash
            $table->unsignedBigInteger('wallet_id');
            $table->decimal('last_balance', 19, 4)->nullable();
            $table->string('direction', 10)->nullable(); // up|down
            $table->decimal('delta', 19, 4)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'book_id', 'wallet_type', 'wallet_id'],
                'ua_wallet_bal_watch_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_wallet_balance_watches');
    }
};

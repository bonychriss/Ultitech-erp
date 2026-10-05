<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quotes')) {
            Schema::create('quotes', function (Blueprint $table) {
                $table->increments('id');
                $table->string('quote_number', 40)->unique();
                $table->string('customer_name');
                $table->string('customer_phone', 64);
                $table->string('customer_email')->nullable();
                $table->text('customer_notes')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->string('sync_status', 32)->default('pending')->index();
                $table->string('ultitech_reference', 64)->nullable();
                $table->dateTime('last_sync_attempt')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->text('admin_notes')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('quote_items')) {
            Schema::create('quote_items', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('quote_id')->index();
                $table->integer('product_id')->nullable();
                $table->integer('ultitech_product_id')->nullable();
                $table->string('product_name');
                $table->string('sku', 191)->nullable();
                $table->decimal('quantity', 12, 2);
                $table->decimal('unit_price', 20, 2)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('sync_queue')) {
            Schema::create('sync_queue', function (Blueprint $table) {
                $table->increments('id');
                $table->string('entity_type', 32);
                $table->integer('entity_id');
                $table->string('action', 32);
                $table->longText('payload')->nullable();
                $table->string('status', 32)->default('pending');
                $table->integer('attempts')->default(0);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('next_attempt_at')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index(['status', 'next_attempt_at']);
                $table->index(['entity_type', 'entity_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_queue');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};

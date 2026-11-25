<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check columns outside Schema::table — SQLite can mis-report hasColumn inside the closure.
        if (! Schema::hasColumn('acc_tax_codes', 'supply_category')) {
            Schema::table('acc_tax_codes', function (Blueprint $table) {
                $table->string('supply_category', 30)->default('standard');
            });
        }

        if (! Schema::hasColumn('acc_tax_transactions', 'memo')) {
            Schema::table('acc_tax_transactions', function (Blueprint $table) {
                $table->string('memo', 255)->nullable();
            });
        }
        if (! Schema::hasColumn('acc_tax_transactions', 'supply_category')) {
            Schema::table('acc_tax_transactions', function (Blueprint $table) {
                $table->string('supply_category', 30)->nullable();
            });
        }

        $returnCols = [
            'filing_reference' => fn (Blueprint $table) => $table->string('filing_reference', 120)->nullable(),
            'filing_date' => fn (Blueprint $table) => $table->date('filing_date')->nullable(),
            'payment_amount' => fn (Blueprint $table) => $table->decimal('payment_amount', 19, 4)->nullable(),
            'payment_date' => fn (Blueprint $table) => $table->date('payment_date')->nullable(),
            'payment_bank_account_id' => fn (Blueprint $table) => $table->unsignedBigInteger('payment_bank_account_id')->nullable(),
            'payment_journal_id' => fn (Blueprint $table) => $table->unsignedBigInteger('payment_journal_id')->nullable(),
            'period_locked' => fn (Blueprint $table) => $table->boolean('period_locked')->default(false),
        ];
        foreach ($returnCols as $col => $adder) {
            if (! Schema::hasColumn('acc_tax_returns', $col)) {
                Schema::table('acc_tax_returns', function (Blueprint $table) use ($adder) {
                    $adder($table);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('acc_tax_returns', function (Blueprint $table) {
            foreach ([
                'filing_reference',
                'filing_date',
                'payment_amount',
                'payment_date',
                'payment_bank_account_id',
                'payment_journal_id',
                'period_locked',
            ] as $col) {
                if (Schema::hasColumn('acc_tax_returns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('acc_tax_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('acc_tax_transactions', 'memo')) {
                $table->dropColumn('memo');
            }
            if (Schema::hasColumn('acc_tax_transactions', 'supply_category')) {
                $table->dropColumn('supply_category');
            }
        });

        Schema::table('acc_tax_codes', function (Blueprint $table) {
            if (Schema::hasColumn('acc_tax_codes', 'supply_category')) {
                $table->dropColumn('supply_category');
            }
        });
    }
};

<?php

namespace App\Domains\CashBook;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ensure cash book tables exist on the active company database.
 */
final class Schema
{
    public static function ensure(): void
    {
        try {
            DB::statement("CREATE TABLE IF NOT EXISTS cash_books (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                status ENUM('active','archived') NOT NULL DEFAULT 'active',
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cash_books_status (status),
                INDEX idx_cash_books_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            DB::statement("CREATE TABLE IF NOT EXISTS cash_book_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                entry_type ENUM('in','out','both') NOT NULL DEFAULT 'both',
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cash_book_categories_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            DB::statement("CREATE TABLE IF NOT EXISTS cash_book_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                book_id INT UNSIGNED NOT NULL,
                entry_date DATE NOT NULL,
                entry_type ENUM('in','out') NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                category_id INT UNSIGNED NULL,
                party_name VARCHAR(180) NULL,
                remark VARCHAR(500) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cbe_book_date (book_id, entry_date, id),
                INDEX idx_cbe_type (entry_type),
                INDEX idx_cbe_category (category_id),
                CONSTRAINT fk_cbe_book FOREIGN KEY (book_id) REFERENCES cash_books(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log('CashBook Schema::ensure: ' . $e->getMessage());
        }

        self::seedDefaultCategories();
    }

    private static function seedDefaultCategories(): void
    {
        try {
            $count = (int) DB::table('cash_book_categories')->count();
            if ($count > 0) {
                return;
            }

            $defaults = [
                ['name' => 'General', 'entry_type' => 'both'],
                ['name' => 'Salary', 'entry_type' => 'out'],
                ['name' => 'Transport', 'entry_type' => 'out'],
                ['name' => 'Office supplies', 'entry_type' => 'out'],
                ['name' => 'Utilities', 'entry_type' => 'out'],
                ['name' => 'Sales cash', 'entry_type' => 'in'],
                ['name' => 'Top-up', 'entry_type' => 'in'],
                ['name' => 'Refund', 'entry_type' => 'in'],
            ];
            $now = now();
            foreach ($defaults as $row) {
                DB::table('cash_book_categories')->insert([
                    'name' => $row['name'],
                    'entry_type' => $row['entry_type'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (Throwable $e) {
            error_log('CashBook seed categories: ' . $e->getMessage());
        }
    }
}

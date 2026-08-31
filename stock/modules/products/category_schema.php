<?php
// Shared categories table introspection (minimal vs extended schema)

if (!function_exists('stock_categories_table_columns')) {
    /**
     * @return array<string, true>
     */
    function stock_categories_table_columns(PDO $pdo, bool $refresh = false): array {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }
        $cache = [];
        try {
            foreach ($pdo->query('SHOW COLUMNS FROM `categories`') as $row) {
                if (!empty($row['Field'])) {
                    $cache[$row['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }
}

if (!function_exists('stock_categories_ensure_image_columns')) {
    /**
     * Ensure categories can store icon / cover / banner filenames.
     * @return array<string, true>
     */
    function stock_categories_ensure_image_columns(PDO $pdo): array {
        $cols = stock_categories_table_columns($pdo);
        $needed = [
            'icon' => "ALTER TABLE `categories` ADD COLUMN `icon` VARCHAR(255) NULL DEFAULT NULL",
            'cover_image' => "ALTER TABLE `categories` ADD COLUMN `cover_image` VARCHAR(255) NULL DEFAULT NULL",
            'banner' => "ALTER TABLE `categories` ADD COLUMN `banner` VARCHAR(255) NULL DEFAULT NULL",
        ];
        foreach ($needed as $field => $sql) {
            if (!empty($cols[$field])) {
                continue;
            }
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // Column may already exist or ALTER not permitted; ignore.
            }
        }
        return stock_categories_table_columns($pdo, true);
    }
}

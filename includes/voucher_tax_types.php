<?php

if (!function_exists('voucher_tax_types')) {
    /** Tax type choices for Tax & Compliance voucher items: value => label. */
    function voucher_tax_types(): array
    {
        return [
            'vat' => 'VAT',
            'income_tax' => 'Corporate / Income tax',
            'paye' => 'PAYE',
            'sdl' => 'SDL (Skills Development Levy)',
            'withholding' => 'Withholding tax',
            'levy' => 'Levies, licences & stamp duty (Council, BRELA)',
            'penalty' => 'TRA penalties & fines',
        ];
    }
}

if (!function_exists('voucher_tax_budget_types')) {
    /** Budget types whose items must carry a tax type. */
    function voucher_tax_budget_types(): array
    {
        return ['Tax & Compliance'];
    }
}

if (!function_exists('voucher_is_tax_budget_type')) {
    function voucher_is_tax_budget_type(string $budgetType): bool
    {
        return in_array(trim($budgetType), voucher_tax_budget_types(), true);
    }
}

if (!function_exists('voucher_normalize_tax_type')) {
    /** Valid tax type for an item, or null when the budget type is not a tax category or the value is unknown. */
    function voucher_normalize_tax_type(string $budgetType, $taxType): ?string
    {
        $taxType = strtolower(trim((string) $taxType));
        if (!voucher_is_tax_budget_type($budgetType) || !isset(voucher_tax_types()[$taxType])) {
            return null;
        }
        return $taxType;
    }
}

if (!function_exists('voucher_ensure_tax_type_column')) {
    function voucher_ensure_tax_type_column(PDO $pdo): bool
    {
        static $done = [];
        $oid = spl_object_id($pdo);
        if (isset($done[$oid])) {
            return $done[$oid];
        }
        try {
            $has = $pdo->query("SHOW COLUMNS FROM voucher_items LIKE 'tax_type'")->fetch();
            if (!$has) {
                $pdo->exec('ALTER TABLE voucher_items ADD COLUMN tax_type VARCHAR(30) NULL DEFAULT NULL AFTER budget_type');
            }
            return $done[$oid] = true;
        } catch (Throwable $e) {
            error_log('voucher_ensure_tax_type_column: ' . $e->getMessage());
            return $done[$oid] = false;
        }
    }
}

<?php
/**
 * Expense category helpers (erp_accounts type = expense).
 */
if (!function_exists('expenses_next_category_code')) {
    function expenses_next_category_code(PDO $pdo): string
    {
        $year = date('Y');
        $prefix = "EXP-CAT-{$year}-";

        $stmt = $pdo->prepare(
            "SELECT code FROM erp_accounts
             WHERE type = 'expense' AND code IS NOT NULL AND code LIKE ?
             ORDER BY id DESC"
        );
        $stmt->execute([$prefix . '%']);

        $nextNum = 1;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '' && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $code, $m)) {
                $nextNum = max($nextNum, (int) $m[1] + 1);
            }
        }

        $isUnique = false;
        while (!$isUnique) {
            $candidate = $prefix . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
            $check = $pdo->prepare("SELECT COUNT(*) FROM erp_accounts WHERE code = ?");
            $check->execute([$candidate]);
            if ((int) $check->fetchColumn() === 0) {
                return $candidate;
            }
            $nextNum++;
        }

        return $prefix . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
    }
}

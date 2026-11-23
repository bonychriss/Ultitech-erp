<?php
require_once __DIR__ . '/gl_core.php';
require_once __DIR__ . '/../bot_exchange_rates.php';

if (!function_exists('gl_fx_mode')) {
    function gl_fx_mode(PDO $pdo): string
    {
        return gl_meta_get($pdo, 'fx_mode') === 'auto' ? 'auto' : 'manual';
    }
}

if (!function_exists('gl_fx_currencies_in_use')) {
    /** @return string[] */
    function gl_fx_currencies_in_use(PDO $pdo): array
    {
        $base = gl_base_currency($pdo);
        $out = [];
        try {
            $rows = $pdo->query("SELECT DISTINCT UPPER(currency) AS c FROM financial_accounts WHERE currency IS NOT NULL AND currency <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $c) {
                if (preg_match('/^[A-Z]{3}$/', (string) $c) && $c !== $base) {
                    $out[] = (string) $c;
                }
            }
        } catch (Throwable $e) {
            error_log('gl_fx_currencies_in_use: ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('gl_fx_lookup_rate')) {
    /** Base-currency value of 1 unit of $cur from BOT (AI fallback), or null. */
    function gl_fx_lookup_rate(PDO $pdo, string $cur): ?array
    {
        $base = gl_base_currency($pdo);
        $baseTzs = 1.0;
        if ($base !== 'TZS') {
            $b = bot_get_exchange_rate($base);
            $baseTzs = $b ? (float) $b['rate'] : 0.0;
        }
        $r = $baseTzs > 0 ? bot_get_exchange_rate($cur) : null;
        if (!$r || (float) $r['rate'] <= 0) {
            return null;
        }

        return [
            'rate' => round((float) $r['rate'] / $baseTzs, 6),
            'source' => (string) $r['source'] . (!empty($r['via_ai']) ? ' (AI assistant)' : ''),
        ];
    }
}

if (!function_exists('gl_fx_fetch_today')) {
    /**
     * Fetch today's rates (BOT, AI fallback) for every foreign currency in use and store them.
     *
     * @return array{saved:string[],failed:string[]}
     */
    function gl_fx_fetch_today(PDO $pdo, ?int $userId = null): array
    {
        $base = gl_base_currency($pdo);
        $baseTzs = 1.0;
        if ($base !== 'TZS') {
            $b = bot_get_exchange_rate($base);
            $baseTzs = $b ? (float) $b['rate'] : 0.0;
        }
        $saved = [];
        $failed = [];
        $today = date('Y-m-d');
        foreach (gl_fx_currencies_in_use($pdo) as $cur) {
            $r = $baseTzs > 0 ? bot_get_exchange_rate($cur) : null;
            if (!$r || (float) $r['rate'] <= 0) {
                $failed[] = $cur;
                continue;
            }
            $rate = round((float) $r['rate'] / $baseTzs, 6);
            $note = 'Auto: ' . $r['source'] . (!empty($r['via_ai']) ? ' (AI assistant)' : '');
            $pdo->prepare('INSERT INTO gl_exchange_rates (currency, rate_date, rate, note, created_by) VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE rate = VALUES(rate), note = VALUES(note), created_by = VALUES(created_by)')
                ->execute([$cur, $today, $rate, $note, $userId]);
            $saved[] = $cur;
        }
        gl_meta_set($pdo, 'fx_last_auto', $today);

        return ['saved' => $saved, 'failed' => $failed];
    }
}

if (!function_exists('gl_fx_auto_refresh_if_due')) {
    /** Runs at most once per day when the mode is "auto". */
    function gl_fx_auto_refresh_if_due(PDO $pdo, ?int $userId = null): ?array
    {
        if (gl_fx_mode($pdo) !== 'auto' || gl_meta_get($pdo, 'fx_last_auto') === date('Y-m-d')) {
            return null;
        }

        return gl_fx_fetch_today($pdo, $userId);
    }
}

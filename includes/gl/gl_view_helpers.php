<?php
/**
 * Shared helpers for ledger report pages.
 */

if (!function_exists('gl_h')) {
    function gl_h($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('gl_fmt')) {
    /** Accounting format: negatives in brackets. */
    function gl_fmt(float $v): string
    {
        if (abs($v) < 0.005) {
            return '0.00';
        }
        return $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2);
    }
}

if (!function_exists('gl_date_param')) {
    function gl_date_param(string $key, string $default): string
    {
        $v = (string) ($_GET[$key] ?? '');
        $ts = $v !== '' ? strtotime($v) : false;
        return $ts ? date('Y-m-d', $ts) : $default;
    }
}

if (!function_exists('gl_period_params')) {
    /** @return array{0:string,1:string} from/to, swapped if reversed */
    function gl_period_params(): array
    {
        $from = gl_date_param('from_date', gl_date_param('start_date', date('Y-01-01')));
        $to = gl_date_param('to_date', gl_date_param('end_date', date('Y-m-d')));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }
}

if (!function_exists('gl_send_csv')) {
    /** @param list<list<string|float|int>> $rows */
    function gl_send_csv(string $filename, array $header, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
}

if (!function_exists('gl_export_url')) {
    function gl_export_url(): string
    {
        $q = $_GET;
        $q['export'] = 'csv';
        return '?' . http_build_query($q);
    }
}

if (!function_exists('gl_account_label')) {
    function gl_account_label(array $a): string
    {
        $code = trim((string) ($a['code'] ?? ''));
        return ($code !== '' ? $code . ' - ' : '') . (string) ($a['name'] ?? '');
    }
}

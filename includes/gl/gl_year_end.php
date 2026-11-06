<?php
/**
 * Year-end close: moves a finished financial year's profit or loss into Retained Earnings.
 * The closing entry has entry_kind 'closing', which the Profit & Loss report ignores so the year stays readable.
 */

require_once __DIR__ . '/gl_reports.php';

if (!function_exists('gl_year_close_key')) {
    function gl_year_close_key(string $yearEnd): string
    {
        return 'year-close:' . $yearEnd;
    }
}

if (!function_exists('gl_year_close_candidate')) {
    /**
     * The latest financial year that has finished.
     *
     * @return array{start:string, end:string, entry_id:int, closed:bool}
     */
    function gl_year_close_candidate(PDO $pdo): array
    {
        [$curStart] = gl_fiscal_year_bounds($pdo);
        $prevEnd = date('Y-m-d', strtotime($curStart . ' -1 day'));
        [$start, $end] = gl_fiscal_year_bounds($pdo, $prevEnd);
        $st = $pdo->prepare("SELECT e.id FROM gl_journal_entries e
                             WHERE e.entry_kind = 'closing' AND e.entry_date = ?
                               AND NOT EXISTS (SELECT 1 FROM gl_journal_entries r WHERE r.reversal_of_id = e.id)
                             LIMIT 1");
        $st->execute([$end]);
        $id = (int) ($st->fetchColumn() ?: 0);
        return ['start' => $start, 'end' => $end, 'entry_id' => $id, 'closed' => $id > 0];
    }
}

if (!function_exists('gl_year_close_lines')) {
    /** @return array{lines:list<array>, net:float} lines that zero every income and expense account for the year */
    function gl_year_close_lines(PDO $pdo, string $start, string $end): array
    {
        $lines = [];
        $dr = $cr = 0.0;
        foreach (gl_account_movements($pdo, $start, $end, true) as $a) {
            if ($a['type'] !== 'revenue' && $a['type'] !== 'expense') {
                continue;
            }
            $net = round($a['debit'] - $a['credit'], 2);
            if (abs($net) < 0.005) {
                continue;
            }
            $lines[] = ['account_id' => $a['id'], 'debit' => $net < 0 ? -$net : 0, 'credit' => $net > 0 ? $net : 0, 'memo' => 'Close ' . $a['code'] . ' ' . $a['name']];
            $dr += $net < 0 ? -$net : 0;
            $cr += $net > 0 ? $net : 0;
        }
        return ['lines' => $lines, 'net' => round($dr - $cr, 2)];
    }
}

if (!function_exists('gl_year_close')) {
    /** @return array{ok:bool, error:?string, net:float} */
    function gl_year_close(PDO $pdo, string $start, string $end): array
    {
        $fail = static fn (string $m): array => ['ok' => false, 'error' => $m, 'net' => 0.0];
        $cand = gl_year_close_candidate($pdo);
        if ($cand['start'] !== $start || $cand['end'] !== $end) {
            return $fail('Only the latest finished financial year can be closed.');
        }
        if ($cand['closed']) {
            return $fail('This year is already closed.');
        }
        $locked = gl_locked_through($pdo);
        if ($locked !== null && $locked >= $end) {
            return $fail('The books are already closed through ' . date('d M Y', strtotime($locked)) . '. Close the year before locking the period.');
        }
        $calc = gl_year_close_lines($pdo, $start, $end);
        if (!$calc['lines']) {
            return $fail('There is no income or expense in this year to close.');
        }
        $lines = $calc['lines'];
        $net = $calc['net'];
        $lines[] = ['account_id' => gl_system_account($pdo, 'retained_earnings'), 'debit' => $net < 0 ? -$net : 0, 'credit' => $net > 0 ? $net : 0, 'memo' => $net >= 0 ? 'Profit for the year' : 'Loss for the year'];

        // A reversed earlier close leaves its source key taken, so later attempts get a numbered key.
        $key = gl_year_close_key($end);
        if (gl_entry_id_by_source_key($pdo, $key) > 0) {
            $key .= ':' . time();
        }
        $res = gl_post_entry($pdo, [
            'date' => $end,
            'description' => 'Year-end close ' . date('d M Y', strtotime($start)) . ' to ' . date('d M Y', strtotime($end)),
            'source_type' => 'year_close',
            'source_key' => $key,
            'entry_kind' => 'closing',
        ], $lines);
        return ['ok' => $res['ok'], 'error' => $res['error'], 'net' => $net];
    }
}

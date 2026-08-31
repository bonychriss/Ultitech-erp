<?php

declare(strict_types=1);

/**
 * KPI trace AI confirmation for the revenue desk.
 */

/**
 * @param array<string, mixed> $context
 */
function revenue_kpi_build_confirmation(string $key, array $context): string
{
    $net = number_format((float) ($context['sum_net'] ?? 0), 2);
    $vat = number_format((float) ($context['sum_vat'] ?? 0), 2);
    $total = number_format((float) ($context['sum_total'] ?? 0), 2);
    $outstanding = number_format((float) ($context['outstanding'] ?? 0), 2);
    $monthRevenue = number_format((float) ($context['month_revenue'] ?? 0), 2);
    $monthCount = (int) ($context['month_count'] ?? 0);
    $entryCount = (int) ($context['entry_count'] ?? 0);
    $monthLabel = date('F Y');

    if ($key === 'totalNet') {
        return $entryCount === 0
            ? 'No revenue entries match your current filters, so net revenue is TZS 0.00.'
            : "Net revenue is TZS {$net} across {$entryCount} matching " . ($entryCount === 1 ? 'entry' : 'entries') . ', before VAT.';
    }

    if ($key === 'totalVat') {
        return $entryCount === 0
            ? 'No VAT is recorded because no revenue entries match your current filters.'
            : "Total VAT on filtered revenue is TZS {$vat}, summed from the VAT column on each matching entry.";
    }

    if ($key === 'totalInclTax') {
        return $entryCount === 0
            ? 'Gross revenue is TZS 0.00 because no entries match your current filters.'
            : "Gross revenue including tax is TZS {$total} across {$entryCount} matching " . ($entryCount === 1 ? 'entry' : 'entries') . '.';
    }

    if ($key === 'outstandingAr') {
        return (float) ($context['outstanding'] ?? 0) < 0.01
            ? 'All matching revenue entries are fully paid. Nothing is outstanding on accounts receivable.'
            : "TZS {$outstanding} remains outstanding on approved revenue that still has an unpaid balance.";
    }

    if ($key === 'thisMonth') {
        if ($monthCount === 0) {
            return "No revenue entries are dated in {$monthLabel} with your current filters.";
        }

        return "{$monthCount} " . ($monthCount === 1 ? 'entry is' : 'entries are') . " dated in {$monthLabel}, totalling TZS {$monthRevenue}.";
    }

    $invTotal = number_format((float) ($context['invoice_total'] ?? 0), 2);
    $invOutstanding = number_format((float) ($context['invoice_outstanding'] ?? 0), 2);
    $invOverdue = number_format((float) ($context['invoice_overdue'] ?? 0), 2);
    $invCount = (int) ($context['invoice_count'] ?? 0);
    $pctOutstanding = number_format((float) ($context['invoice_pct_outstanding'] ?? 0), 1);
    $pctOverdue = number_format((float) ($context['invoice_pct_overdue'] ?? 0), 1);

    if ($key === 'totalInvoices') {
        return $invCount === 0
            ? 'No sales invoices match your current invoice filters.'
            : "Total invoice value is TZS {$invTotal} across {$invCount} " . ($invCount === 1 ? 'invoice' : 'invoices') . '.';
    }

    if ($key === 'outstandingInvoices') {
        return (float) ($context['invoice_outstanding'] ?? 0) < 0.01
            ? 'All matching invoices are fully paid.'
            : "TZS {$invOutstanding} remains outstanding on invoices ({$pctOutstanding}% of invoice total).";
    }

    if ($key === 'overdueInvoices') {
        return (float) ($context['invoice_overdue'] ?? 0) < 0.01
            ? 'No matching invoices are past due with an unpaid balance.'
            : "TZS {$invOverdue} is overdue on invoices ({$pctOverdue}% of invoice total).";
    }

    return '';
}

/**
 * @param array<string, mixed> $trace
 * @return array{confirmation: string, viaAi: bool}
 */
function revenue_kpi_ai_confirm(string $key, array $trace): array
{
    if (is_file(dirname(__DIR__, 3) . '/modules/balances/functions.php')) {
        require_once dirname(__DIR__, 3) . '/modules/balances/functions.php';
    }

    $context = is_array($trace['context'] ?? null) ? $trace['context'] : [];
    $computed = (string) ($trace['confirmation'] ?? '');
    if ($computed === '') {
        $computed = revenue_kpi_build_confirmation($key, $context);
    }

    if (!function_exists('ai_openai_request')) {
        return ['confirmation' => $computed, 'viaAi' => false];
    }

    if (function_exists('balances_ai_is_connected') && !balances_ai_is_connected()) {
        return ['confirmation' => $computed, 'viaAi' => false];
    }

    $title = (string) ($trace['title'] ?? $key);
    $headline = (string) ($trace['headline'] ?? '');
    $method = (string) ($trace['method'] ?? '');
    $itemCount = count($trace['items'] ?? []);
    $criteriaLines = [];
    foreach ($trace['criteria'] ?? [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $criteriaLines[] = ($line['label'] ?? '') . ': ' . ($line['value'] ?? '');
    }

    $messages = [
        [
            'role' => 'system',
            'content' => 'You explain revenue and accounts-receivable KPIs in plain language for business users. Use short, simple sentences. Avoid SQL, database jargon, and markdown. Reply in 2-4 sentences.',
        ],
        [
            'role' => 'user',
            'content' => "KPI: {$title}\nHeadline value: {$headline}\nMethod: {$method}\nCriteria:\n" . implode("\n", $criteriaLines) . "\nContributing rows in breakdown: {$itemCount}\nComputed summary: {$computed}",
        ],
    ];

    try {
        $result = ai_openai_request($messages);
        $content = trim((string) ($result['content'] ?? $result['message'] ?? ''));
        if ($content !== '') {
            return ['confirmation' => $content, 'viaAi' => true];
        }
    } catch (Throwable $e) {
        // fall through
    }

    return ['confirmation' => $computed, 'viaAi' => false];
}

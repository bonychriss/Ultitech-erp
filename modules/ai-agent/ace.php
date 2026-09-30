<?php
/**
 * Local tool endpoint for ACE. ACE calls this on 127.0.0.1 with a company token.
 * It only reads Ultitech data for the company in the URL.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/includes/agent-lib.php';
require_once __DIR__ . '/includes/ace-client.php';

header('Content-Type: application/json; charset=utf-8');

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'ACE must call this from this computer.']);
    exit;
}

$token = (string) ($_SERVER['HTTP_X_ULTITECH_ACE_TOKEN'] ?? '');
$record = aiAgentAceReadToken($token);
$companyId = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
if ($record === null || $companyId <= 0 || (int) ($record['company_id'] ?? 0) !== $companyId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'This ACE request is not authorized for the current company.']);
    exit;
}

$_SESSION['user_id'] = (int) $record['user_id'];
$_SESSION['role'] = (string) ($record['role'] ?? '');
$_SESSION['department'] = (string) ($record['department'] ?? '');
$_SESSION['full_name'] = (string) ($record['full_name'] ?? '');
if (!empty($record['company_name'])) {
    $_SESSION['company_name'] = (string) $record['company_name'];
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}
$action = (string) ($body['action'] ?? '');

try {
    $ctx = aiAgentContext();
    if ((int) $ctx['company_id'] !== $companyId) {
        throw new RuntimeException('Company context changed.');
    }
    $currency = (string) $ctx['currency'];
    $links = [
        ['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()],
    ];

    if ($action === 'daily_briefing') {
        $briefing = aiAgentGenerateDailyBriefing($ctx);
        $summary = $briefing['receivables'];
        $parts = [];
        $parts[] = (string) ($briefing['greeting'] ?? 'Hello') . '.';
        $parts[] = 'There are ' . (int) $briefing['total_attention_items'] . ' items that need attention.';
        if (!empty($summary['available'])) {
            $parts[] = (int) $summary['overdue_count'] . ' customer invoices are overdue, totaling ' . aiAgentFormatMoney((float) $summary['overdue_amount'], $currency, true) . '.';
            $parts[] = 'Outstanding receivables are ' . aiAgentFormatMoney((float) $summary['outstanding_amount'], $currency) . '.';
        } else {
            $parts[] = (string) ($summary['reason'] ?? 'Receivables are unavailable.');
        }
        if (!empty($briefing['approvals']['available'])) {
            $parts[] = (int) $briefing['approvals']['pending_count'] . ' payment vouchers are waiting for approval.';
        } elseif (!empty($briefing['approvals']['reason'])) {
            $parts[] = (string) $briefing['approvals']['reason'];
        }
        if (!empty($briefing['stock']['available'])) {
            $parts[] = (int) $briefing['stock']['low_stock_count'] . ' products are below the minimum level.';
        } elseif (!empty($briefing['stock']['reason'])) {
            $parts[] = (string) $briefing['stock']['reason'];
        }
        if (!empty($briefing['procurement']['available'])) {
            $parts[] = (int) $briefing['procurement']['stalled_count'] . ' purchase requests have been waiting, the oldest for ' . (int) $briefing['procurement']['oldest_wait_days'] . ' days.';
        } elseif (!empty($briefing['procurement']['reason'])) {
            $parts[] = (string) $briefing['procurement']['reason'];
        }
        if (!empty($briefing['narrative']['analysis'])) {
            $parts[] = (string) $briefing['narrative']['analysis'];
        }
        echo json_encode([
            'ok' => true,
            'message' => implode(' ', $parts),
            'briefing' => [
                'date' => $briefing['date_label'],
                'greeting' => $briefing['greeting'],
                'attention_items' => $briefing['total_attention_items'],
                'outstanding' => $summary['outstanding_amount'] ?? null,
                'overdue_amount' => $summary['overdue_amount'] ?? null,
                'overdue_count' => $summary['overdue_count'] ?? null,
                'due_soon_amount' => $summary['due_soon_amount'] ?? null,
                'due_soon_count' => $summary['due_soon_count'] ?? null,
                'currency' => $currency,
                'approvals_pending' => $briefing['approvals']['pending_count'] ?? null,
                'approvals_available' => !empty($briefing['approvals']['available']),
                'stock_low' => $briefing['stock']['low_stock_count'] ?? null,
                'stock_available' => !empty($briefing['stock']['available']),
                'procurement_stalled' => $briefing['procurement']['stalled_count'] ?? null,
                'procurement_available' => !empty($briefing['procurement']['available']),
                'procurement_reason' => $briefing['procurement']['reason'] ?? '',
                'facts' => $briefing['narrative']['facts'] ?? '',
                'analysis' => $briefing['narrative']['analysis'] ?? '',
            ],
            'links' => [
                ['label' => 'Review receivables', 'url' => aiAgentInvoicesUrl()],
                ['label' => 'Review approvals', 'url' => (string) ($briefing['approvals']['url'] ?? aiAgentVouchersUrl())],
                ['label' => 'View stock', 'url' => aiAgentStockUrl()],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'receivables_summary') {
        $summary = aiAgentReceivablesSummary($ctx);
        if (empty($summary['available'])) {
            echo json_encode(['ok' => true, 'message' => (string) $summary['reason'], 'summary' => $summary]);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'message' => 'Outstanding ' . aiAgentFormatMoney((float) $summary['outstanding_amount'], $currency)
                . '. Overdue ' . aiAgentFormatMoney((float) $summary['overdue_amount'], $currency)
                . ' across ' . (int) $summary['overdue_count'] . ' invoices. Due soon '
                . aiAgentFormatMoney((float) $summary['due_soon_amount'], $currency) . '.',
            'summary' => $summary,
            'links' => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'recent_invoice') {
        $reply = aiAgentRecentInvoiceReply($ctx);
        echo json_encode([
            'ok' => true,
            'message' => (string) $reply['text'],
            'invoices' => $reply['invoices'],
            'links' => $reply['actions'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'overdue_invoices' || $action === 'due_soon_invoices' || $action === 'oldest_overdue') {
        $which = $action === 'due_soon_invoices' ? 'due_soon' : 'overdue';
        $limit = $action === 'oldest_overdue' ? 1 : 8;
        $rows = aiAgentInvoiceRows($ctx, $which, $limit);
        $summary = aiAgentReceivablesSummary($ctx);
        $message = $which === 'due_soon'
            ? ((int) ($summary['due_soon_count'] ?? 0) . ' invoices are due within ' . AI_AGENT_DUE_SOON_DAYS . ' days.')
            : ((int) ($summary['overdue_count'] ?? 0) . ' invoices are overdue, totaling ' . aiAgentFormatMoney((float) ($summary['overdue_amount'] ?? 0), $currency) . '.');
        if ($action === 'oldest_overdue') {
            $message = $rows === []
                ? 'No invoice is overdue.'
                : $rows[0]['invoice_number'] . ' for ' . $rows[0]['customer_name'] . ' is the oldest, ' . (int) $rows[0]['days_overdue'] . ' days overdue.';
        }
        echo json_encode([
            'ok' => true,
            'message' => $message,
            'invoices' => $rows,
            'links' => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'customer_receivables') {
        $name = trim((string) ($body['name'] ?? ''));
        $rows = $name !== '' ? aiAgentCustomersMatching($ctx, $name) : aiAgentCustomerReceivables($ctx, 8);
        $message = $rows === []
            ? ($name !== '' ? 'No customer in this company matches that name.' : 'No customers have an overdue invoice.')
            : $rows[0]['customer_name'] . ' has the largest matching overdue balance, ' . aiAgentFormatMoney((float) ($rows[0]['overdue_amount'] ?? $rows[0]['outstanding_amount'] ?? 0), $currency) . '.';
        echo json_encode(['ok' => true, 'message' => $message, 'customers' => $rows, 'links' => $links], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'month_receivables') {
        $month = aiAgentMonthReceivables($ctx);
        if (empty($month['available'])) {
            echo json_encode(['ok' => true, 'message' => (string) ($month['reason'] ?? 'This month\'s receivables are unavailable.')]);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'message' => 'In ' . $month['period_label'] . ', ' . (int) $month['invoice_count'] . ' invoices were issued for '
                . aiAgentFormatMoney((float) $month['invoiced_amount'], $currency) . '. '
                . aiAgentFormatMoney((float) $month['outstanding_amount'], $currency) . ' is still outstanding.',
            'month' => $month,
            'invoices' => aiAgentInvoiceRows($ctx, 'month', 5),
            'links' => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'stock_alerts') {
        $stock = aiAgentStockAlerts($ctx);
        $message = empty($stock['available'])
            ? (string) $stock['reason']
            : (int) $stock['low_stock_count'] . ' products are below the minimum level.';
        echo json_encode([
            'ok' => true,
            'message' => $message,
            'stock' => $stock,
            'links' => [['label' => 'View stock', 'url' => aiAgentStockUrl()]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'pending_approvals') {
        $approvals = aiAgentPendingApprovals($ctx);
        $message = empty($approvals['available'])
            ? (string) $approvals['reason']
            : (int) $approvals['pending_count'] . ' payment vouchers are waiting for approval.';
        echo json_encode([
            'ok' => true,
            'message' => $message,
            'approvals' => $approvals,
            'links' => [['label' => 'Review approvals', 'url' => (string) ($approvals['url'] ?? aiAgentVouchersUrl())]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'stalled_procurement') {
        $procurement = aiAgentStalledProcurement($ctx);
        $message = empty($procurement['available'])
            ? (string) $procurement['reason']
            : (int) $procurement['stalled_count'] . ' purchase requests have been waiting, the oldest for ' . (int) $procurement['oldest_wait_days'] . ' days.';
        echo json_encode([
            'ok' => true,
            'message' => $message,
            'procurement' => $procurement,
            'links' => empty($procurement['available']) ? [] : [['label' => 'Review procurement', 'url' => (string) $procurement['url']]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'create_invoice') {
        $created = aiAgentCreateCustomerInvoice(
            $ctx,
            (string) ($body['customer_name'] ?? ''),
            (float) ($body['amount'] ?? 0),
            (string) ($body['description'] ?? '')
        );
        echo json_encode([
            'ok' => !empty($created['ok']),
            'message' => (string) ($created['message'] ?? ''),
            'invoices' => $created['invoices'] ?? [],
            'links' => !empty($created['invoices'][0]['view_url'])
                ? [['label' => 'View invoice', 'url' => (string) $created['invoices'][0]['view_url']]]
                : [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'prepare_follow_up') {
        $invoice = aiAgentInvoiceById($ctx, (int) ($body['invoice_id'] ?? 0));
        if ($invoice === null) {
            echo json_encode(['ok' => true, 'message' => 'That invoice is not in the current company.']);
            exit;
        }
        $draft = aiAgentFollowUpDraft($invoice, (string) $ctx['company_name']);
        echo json_encode([
            'ok' => true,
            'message' => $invoice['customer_name'] . ' has ' . $invoice['invoice_number'] . ' for ' . aiAgentFormatMoney((float) $invoice['balance_due'], $currency) . ' outstanding. A follow-up draft is prepared and has not been sent.',
            'invoices' => [$invoice],
            'follow_up' => $draft,
            'links' => [['label' => 'View invoice', 'url' => (string) $invoice['view_url']]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ACE cannot change Ultitech invoices, payments, or accounting from this connection.']);
} catch (Throwable $e) {
    error_log('ai-agent ace bridge: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ultitech could not read this company.']);
}

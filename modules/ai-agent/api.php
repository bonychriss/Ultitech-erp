<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/includes/agent-lib.php';
if (is_file(dirname(__DIR__, 2) . '/includes/ai_helpers.php')) {
    require_once dirname(__DIR__, 2) . '/includes/ai_helpers.php';
}

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'briefing');

try {
    if ($action === 'alert' || $action === 'briefing') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    if ($action === 'alert') {
        echo json_encode(['ok' => true, 'alert' => aiAgentAlertPayload()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'briefing') {
        echo json_encode(['ok' => true, 'briefing' => aiAgentGenerateDailyBriefing()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'ask' || $action === 'follow_up' || $action === 'ace_briefing') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('verify_csrf') || !verify_csrf((string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
            exit;
        }
        if ($action === 'follow_up') {
            $ctx = aiAgentContext();
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $invoice = aiAgentInvoiceById($ctx, $invoiceId);
            if ($invoice === null) {
                $reply = [
                    'text' => 'That invoice is not in the current company.',
                    'facts' => '',
                    'analysis' => '',
                    'invoices' => [],
                    'actions' => [],
                    'follow_up' => null,
                    'source' => 'erp',
                ];
            } else {
                $reply = [
                    'text' => $invoice['customer_name'] . ' has invoice ' . $invoice['invoice_number'] . ' of ' . aiAgentFormatMoney((float) $invoice['balance_due'], (string) $ctx['currency']) . ' outstanding.',
                    'facts' => 'The message below is a draft only. It has not been sent.',
                    'analysis' => '',
                    'invoices' => [$invoice],
                    'actions' => [['label' => 'View invoice', 'url' => (string) $invoice['view_url']]],
                    'follow_up' => aiAgentFollowUpDraft($invoice, (string) $ctx['company_name']),
                    'source' => 'erp',
                ];
            }
            echo json_encode(['ok' => true, 'reply' => $reply], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'ace_briefing') {
            echo json_encode([
                'ok' => true,
                'source' => 'erp',
                'text' => aiAgentCompanyBriefingText(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $message = trim((string) ($_POST['message'] ?? ''));
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500);
        }
        $reply = aiAgentAnswer($message);
        $reply['source'] = 'erp';
        echo json_encode(['ok' => true, 'reply' => $reply], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('ai-agent api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'I could not read ERP data for this company.']);
}

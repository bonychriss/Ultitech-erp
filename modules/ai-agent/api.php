<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/includes/agent-lib.php';
require_once __DIR__ . '/includes/ace-client.php';

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
            $aceReply = $invoice === null ? null : aiAgentAskAce(
                'Call ultitech_prepare_follow_up with invoice_id ' . $invoiceId . '. Tell the user the draft is not sent.'
            );
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
            } elseif (is_array($aceReply)) {
                if (empty($aceReply['follow_up'])) {
                    $aceReply['follow_up'] = aiAgentFollowUpDraft($invoice, (string) $ctx['company_name']);
                }
                if ($aceReply['invoices'] === []) {
                    $aceReply['invoices'] = [$invoice];
                }
                $reply = $aceReply;
            } else {
                $reply = [
                    'text' => $invoice['customer_name'] . ' has invoice ' . $invoice['invoice_number'] . ' of ' . aiAgentFormatMoney((float) $invoice['balance_due'], (string) $ctx['currency']) . ' outstanding.',
                    'facts' => 'ACE is not running, so this draft was prepared directly from ERP data. It has not been sent.',
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
            $reply = aiAgentAskAce('daily briefing', 'briefing');
            if (!is_array($reply)) {
                $briefing = aiAgentGenerateDailyBriefing();
                echo json_encode([
                    'ok' => true,
                    'source' => 'erp',
                    'text' => trim((string) ($briefing['narrative']['facts'] ?? '') . ' ' . (string) ($briefing['narrative']['analysis'] ?? '')),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            echo json_encode([
                'ok' => true,
                'source' => 'ace',
                'text' => (string) $reply['text'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $message = trim((string) ($_POST['message'] ?? ''));
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500);
        }
        $reply = aiAgentAskAce($message);
        if (!is_array($reply)) {
            $reply = aiAgentAnswer($message);
            $reply['source'] = 'erp';
            $reply['facts'] = trim((string) ($reply['facts'] ?? '') . ' ACE is not running, so this answer comes directly from ERP data.');
        }
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/ai_helpers.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = trim((string) ($input['mode'] ?? 'confirm'));
    $traceKey = trim((string) ($input['traceKey'] ?? ''));
    $trace = is_array($input['trace'] ?? null) ? $input['trace'] : [];
    $question = trim((string) ($input['question'] ?? ''));
    $messages = is_array($input['messages'] ?? null) ? $input['messages'] : [];

    $settings = function_exists('ai_fetch_settings_row') ? ai_fetch_settings_row() : null;
    if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
        echo json_encode([
            'ok' => false,
            'error' => 'AI assistant is not configured.',
            'confirmation' => (string) ($trace['confirmation'] ?? ''),
            'viaAi' => false,
        ]);
        exit;
    }

    $companyId = 0;
    if (function_exists('currentCompanyId')) {
        $companyId = (int) currentCompanyId();
    }
    if ($companyId <= 0) {
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if (function_exists('ai_check_company_limit') && $companyId > 0) {
        $limit = ai_check_company_limit($companyId);
        if (empty($limit['allowed'])) {
            echo json_encode([
                'ok' => false,
                'error' => 'Daily AI limit reached.',
                'confirmation' => (string) ($trace['confirmation'] ?? ''),
                'viaAi' => false,
            ]);
            exit;
        }
    }

    $title = trim((string) ($trace['title'] ?? 'KPI'));
    $headline = trim((string) ($trace['headline'] ?? ''));
    $itemCount = is_array($trace['items'] ?? null) ? count($trace['items']) : (int) ($trace['itemCount'] ?? 0);
    $confirmation = trim((string) ($trace['confirmation'] ?? ''));
    $method = trim((string) ($trace['method'] ?? ''));
    $context = "KPI: {$title}\nValue: {$headline}\nContributing records: {$itemCount}\nMethod: {$method}\nSummary: {$confirmation}";

    if ($mode === 'chat') {
        if ($question === '') {
            echo json_encode(['ok' => false, 'error' => 'Please enter a question.']);
            exit;
        }

        $chatMessages = [
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant inside a delivery logistics KPI detail modal. '
                    . 'Answer questions about the selected KPI using only the context provided. '
                    . 'Be concise, practical, and friendly. If you do not know, say so.',
            ],
            [
                'role' => 'system',
                'content' => "KPI context:\n{$context}",
            ],
        ];

        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $chatMessages[] = ['role' => $role, 'content' => $content];
        }

        $chatMessages[] = ['role' => 'user', 'content' => $question];

        $result = ai_openai_request($chatMessages);
        $reply = trim((string) ($result['content'] ?? ''));

        if ($reply === '') {
            echo json_encode(['ok' => false, 'error' => 'No response from AI assistant.']);
            exit;
        }

        if (function_exists('ai_estimate_cost') && function_exists('ai_log_usage') && $companyId > 0) {
            $cost = ai_estimate_cost((int) $result['prompt_tokens'], (int) $result['completion_tokens'], (string) $result['model']);
            ai_log_usage($companyId, $userId, 'deliveries', 'kpi_chat', (int) $result['prompt_tokens'], (int) $result['completion_tokens'], $cost);
        }

        echo json_encode(['ok' => true, 'reply' => $reply, 'viaAi' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $system = 'You verify and explain a delivery KPI in 1-2 short sentences for a business user. '
        . 'Confirm whether the headline count aligns with the method and contributing records. '
        . 'Do not invent numbers. Return plain text only.';

    $user = "KPI key: {$traceKey}\n{$context}";

    $result = ai_openai_request([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ]);

    $aiConfirmation = trim((string) ($result['content'] ?? ''));
    if ($aiConfirmation === '') {
        $aiConfirmation = $confirmation;
    }

    if (function_exists('ai_estimate_cost') && function_exists('ai_log_usage') && $companyId > 0) {
        $cost = ai_estimate_cost((int) $result['prompt_tokens'], (int) $result['completion_tokens'], (string) $result['model']);
        ai_log_usage($companyId, $userId, 'deliveries', 'kpi_confirm', (int) $result['prompt_tokens'], (int) $result['completion_tokens'], $cost);
    }

    echo json_encode([
        'ok' => true,
        'confirmation' => $aiConfirmation,
        'viaAi' => true,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('deliveries/deliveries-ui/api/kpi-ai-assist.php failed: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => 'AI assistant is unavailable right now.',
        'viaAi' => false,
    ], JSON_UNESCAPED_UNICODE);
}

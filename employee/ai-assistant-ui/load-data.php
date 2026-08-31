<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/ai_assistant_helper.php';

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function ai_assistant_load_init_payload(PDO $pdo, int $userId, int $companyId, array $query = []): array
{
    $role = 'employee';
    $activeTab = (string) ($query['tab'] ?? 'chat');
    if (!in_array($activeTab, ['chat', 'performance', 'attendance', 'guidelines'], true)) {
        $activeTab = 'chat';
    }
    $activeModule = (string) ($query['module'] ?? 'general');

    $apiConfig = ai_settings_for_api();
    $aiEnabled = !empty($apiConfig['is_enabled']);

    $recentChats = ai_assistant_fetch_recent_chats($pdo, $userId, $companyId, 20);
    $answerPreferences = ai_assistant_get_user_preferences($pdo, $companyId, $userId);

    $builder = new AIAssistantContextBuilder($pdo, $userId, $companyId, $role);

    $fullName = (string) ($_SESSION['full_name'] ?? 'there');
    $firstName = trim(explode(' ', $fullName)[0] ?? $fullName);
    if ($firstName === '') {
        $firstName = 'there';
    }

    $kpi = isset($query['kpi']) ? trim((string) $query['kpi']) : '';
    $kpiVal = isset($query['val']) ? trim((string) $query['val']) : '';

    return [
        'ok' => true,
        'data' => [
            'aiEnabled' => $aiEnabled,
            'activeTab' => $activeTab,
            'activeModule' => $activeModule,
            'userFirstName' => $firstName,
            'recentChats' => $recentChats,
            'answerPreferences' => [
                'answerLength' => $answerPreferences['answer_length'] ?? null,
                'answerStyle' => $answerPreferences['answer_style'] ?? null,
                'hasPreferences' => ai_assistant_has_defined_preferences($answerPreferences),
            ],
            'contexts' => [
                'performance' => $builder->buildPerformanceContext(),
                'attendance' => $builder->buildAttendanceContext(),
                'vouchers' => $builder->buildVouchersContext(),
            ],
            'kpiPrompt' => $kpi !== '' ? ['kpi' => $kpi, 'val' => $kpiVal] : null,
        ],
    ];
}

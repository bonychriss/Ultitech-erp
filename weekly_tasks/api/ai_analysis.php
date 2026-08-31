<?php
require_once __DIR__ . '/../includes/performance_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $teamStats = perf_fetch_team_stats($pdo, $weekStartDate);
    $summary = perf_week_summary($pdo, $weekStartDate, $teamStats);

    $topPerformer = null;
    foreach ($teamStats as $s) {
        if ((int) ($s['total_tasks'] ?? 0) > 0) {
            $topPerformer = $s;
            break;
        }
    }

    require_once __DIR__ . '/../../includes/ai_helpers.php';
    
    $insights = null;
    try {
        $settings = ai_fetch_settings_row();
        if ($settings && (int) ($settings['is_enabled'] ?? 0)) {
            $statsText = "Weekly performance statistics:\n";
            $statsText .= "- Completion rate: " . $summary['completion_pct'] . "%\n";
            $statsText .= "- Submissions: " . $summary['plans_submitted'] . " plans on time\n";
            $statsText .= "- Delayed tasks/missions: " . $summary['delayed_tasks'] . "\n";
            if ($topPerformer) {
                $statsText .= "- Top performer: " . $topPerformer['full_name'] . " with " . $topPerformer['score_pct'] . "%\n";
            }
            $statsText .= "\nTeam details:\n";
            foreach ($teamStats as $s) {
                $statsText .= sprintf("  - %s (%s): %d%% completed (%d total, %d pending)\n", 
                    $s['full_name'], 
                    $s['department'] ?: 'General', 
                    $s['score_pct'], 
                    $s['total_tasks'], 
                    $s['pending_count']
                );
            }
            
            $messages = [
                [
                    'role' => 'system',
                    'content' => "You are a professional HR performance coach. Based on the team metrics, return a list of achievements and suggestions. Format your response exactly like this, with up to 4 items each:\nACHIEVEMENT: [Achievement point]\nSUGGESTION: [Suggestion point]"
                ],
                [
                    'role' => 'user',
                    'content' => $statsText
                ]
            ];
            
            $openai = ai_openai_request($messages);
            $lines = explode("\n", $openai['content']);
            $ach = [];
            $sug = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (stripos($line, 'ACHIEVEMENT:') === 0) {
                    $ach[] = trim(substr($line, 12));
                } elseif (stripos($line, 'SUGGESTION:') === 0) {
                    $sug[] = trim(substr($line, 11));
                }
            }
            if (!empty($ach) || !empty($sug)) {
                $insights = [
                    'achievements' => $ach,
                    'suggestions' => $sug
                ];
            }
        }
    } catch (Throwable $eAI) {
        error_log('AI performance analysis fallback: ' . $eAI->getMessage());
    }

    if (!$insights) {
        $insights = perf_build_insights($teamStats, $summary, $topPerformer);
    }

    $fromMissions = ($summary['data_source'] ?? '') === 'missions';
    $weekSummary = $fromMissions
        ? [
            $summary['completion_pct'] . '% of weekly missions were completed.',
            $summary['plans_submitted'] . ' team member' . ($summary['plans_submitted'] === 1 ? '' : 's') . ' submitted missions this week.',
            $summary['delayed_tasks'] . ' mission' . ($summary['delayed_tasks'] === 1 ? ' is' : 's are') . ' delayed.',
        ]
        : [
            $summary['completion_pct'] . '% of weighted tasks were completed.',
            $summary['plans_submitted'] . ' plan' . ($summary['plans_submitted'] === 1 ? '' : 's') . ' submitted on time.',
            $summary['delayed_tasks'] . ' task' . ($summary['delayed_tasks'] === 1 ? ' was' : 's were') . ' delayed.',
        ];

    $rewardText = $topPerformer
        ? '<strong>' . htmlspecialchars($topPerformer['full_name']) . '</strong> is the most deserving candidate for this week.'
        : 'Submit weekly plans to unlock AI reward suggestions.';

    echo json_encode([
        'success' => true,
        'week_summary' => $weekSummary,
        'reward_text' => $rewardText,
        'achievements' => $insights['achievements'],
        'suggestions' => $insights['suggestions'],
        'top_performer' => $topPerformer ? [
            'name' => $topPerformer['full_name'],
            'score' => (int) $topPerformer['score_pct'],
        ] : null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Analysis failed']);
}

<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';
require_once __DIR__ . '/../includes/ai_assistant_helper.php';

$userId = (int) $_SESSION['user_id'];
$companyId = (int) currentCompanyId();
$role = $_SESSION['role'] ?? 'admin';
$apiConfig = ai_settings_for_api();
$aiEnabled = $apiConfig['is_enabled'];

// Fast API dispatcher for AJAX queries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$aiEnabled) {
        echo json_encode(['success' => false, 'error' => 'AI Assistant is currently disabled. Please enable it in the Control Center settings.']);
        exit;
    }
    
    $action = $_POST['ajax_action'];
    $params = $_POST['params'] ?? [];
    
    try {
        $response = ai_assistant_handle_action($pdo, $userId, $companyId, $role, $action, $params);
        echo json_encode($response);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$teamStats = perf_fetch_team_stats($pdo, $weekStartDate);
$summary = perf_week_summary($pdo, $weekStartDate, $teamStats);

$topPerformer = null;
foreach ($teamStats as $s) {
    if ((int) ($s['total_tasks'] ?? 0) > 0) {
        $topPerformer = $s;
        break;
    }
}

$insights = perf_build_insights($teamStats, $summary, $topPerformer);
$rewardCandidate = $topPerformer;
$overview = perf_overview_stats($pdo, $weekStartDate, $teamStats, $summary);

$candidates = [];
foreach ($teamStats as $member) {
    if ((int) ($member['total_tasks'] ?? 0) === 0 && (int) ($member['score_pct'] ?? 0) === 0) {
        continue;
    }
    $candidates[] = array_merge($member, perf_ai_suggestion_label((int) $member['score_pct']));
}
$candidates = array_slice($candidates, 0, 8);

$usesMissions = ($summary['data_source'] ?? '') === 'missions';
$weekSummaryLines = $usesMissions
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

$perfHeaderActions = '<button type="button" class="perf-btn-ai" id="btnGenerateAi"><i class="bi bi-stars"></i> Generate AI Analysis</button>';

perf_layout_start(
    'ai-assistant',
    'AI helps you analyze performance and suggest reward candidates.',
    true,
    $perfHeaderActions
);
?>

<?php if ($isAdmin): ?>
<style>
    :root {
        --ai-primary: #6366f1;
        --ai-primary-glow: rgba(99, 102, 241, 0.15);
        --ai-secondary: #a855f7;
        --ai-bg-glass: rgba(255, 255, 255, 0.85);
        --ai-border: rgba(229, 231, 235, 0.8);
        --ai-dark-text: #0f172a;
        --ai-muted-text: #64748b;
    }
    
    .whatsapp-layout {
        display: flex;
        background: #ffffff;
        border: 1px solid var(--ai-border);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.05);
        height: 720px;
        margin-bottom: 30px;
    }

    .whatsapp-sidebar {
        width: 330px;
        border-right: 1px solid var(--ai-border);
        display: flex;
        flex-direction: column;
        background: #f8fafc;
        flex-shrink: 0;
    }

    .thread-list {
        flex: 1;
        overflow-y: auto;
    }

    .thread-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px;
        cursor: pointer;
        border-bottom: 1px solid rgba(229, 231, 235, 0.4);
        transition: all 0.2s ease;
    }

    .thread-item:hover {
        background: rgba(99, 102, 241, 0.04);
    }

    .thread-item.active {
        background: rgba(99, 102, 241, 0.08);
        border-left: 4px solid var(--ai-primary);
    }

    .thread-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
    }

    .thread-details {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .thread-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .thread-name {
        font-weight: 700;
        font-size: 13px;
        color: var(--ai-dark-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .thread-time {
        font-size: 10px;
        color: var(--ai-muted-text);
        font-weight: 500;
    }

    .thread-preview {
        font-size: 11px;
        color: var(--ai-muted-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .whatsapp-chat-panel {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        min-width: 0;
        height: 100%;
        overflow: hidden;
    }
    
    .whatsapp-chat-panel .ai-card {
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        height: 100% !important;
        padding: 24px !important;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
    }

    .analysis-output-box {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid var(--ai-border);
        padding: 20px;
        min-height: 200px;
        font-size: 14px;
        line-height: 1.6;
        white-space: pre-wrap;
        color: var(--ai-dark-text);
    }

    .anomalies-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .anomaly-item {
        padding: 16px;
        border-radius: 12px;
        background: rgba(254, 242, 242, 0.6);
        border: 1px solid rgba(252, 165, 165, 0.4);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .anomaly-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .anomaly-icon {
        color: #ef4444;
        font-size: 20px;
    }

    .anomaly-meta {
        font-size: 13px;
        color: var(--ai-muted-text);
    }

    .growth-trend-box {
        display: flex;
        align-items: center;
        justify-content: space-around;
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(168, 85, 247, 0.05) 100%);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        border: 1px solid var(--ai-border);
    }

    .trend-stat {
        text-align: center;
    }

    .trend-stat-val {
        font-family: 'Outfit', sans-serif;
        font-size: 32px;
        font-weight: 700;
        color: var(--ai-primary);
    }

    .trend-stat-lbl {
        font-size: 12px;
        color: var(--ai-muted-text);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 4px;
    }

    .status-badge-ai {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }
    .status-badge-ai.enabled { background: #d1fae5; color: #065f46; }
    .status-badge-ai.disabled { background: #fee2e2; color: #991b1b; }
</style>

<div class="whatsapp-layout">
    <!-- Left Thread List Sidebar -->
    <div class="whatsapp-sidebar">
        <div class="thread-list">
            <div class="thread-item active" onclick="switchTab('performance')" id="thread-performance">
                <div class="thread-icon bg-primary-subtle text-primary">
                    <i class="bi bi-award-fill"></i>
                </div>
                <div class="thread-details">
                    <div class="thread-meta">
                        <span class="thread-name">Performance Analytics</span>
                        <span class="thread-time">Score</span>
                    </div>
                    <span class="thread-preview">AI week summary, suggestions & ratings...</span>
                </div>
            </div>
            <div class="thread-item" onclick="switchTab('reports')" id="thread-reports">
                <div class="thread-icon bg-success-subtle text-success">
                    <i class="bi bi-file-earmark-bar-graph-fill"></i>
                </div>
                <div class="thread-details">
                    <div class="thread-meta">
                        <span class="thread-name">Module Reports</span>
                        <span class="thread-time">Docs</span>
                    </div>
                    <span class="thread-preview">Generate Voucher, Attendance & Task audits...</span>
                </div>
            </div>
            <div class="thread-item" onclick="switchTab('anomalies')" id="thread-anomalies">
                <div class="thread-icon bg-danger-subtle text-danger">
                    <i class="bi bi-shield-fill-exclamation"></i>
                </div>
                <div class="thread-details">
                    <div class="thread-meta">
                        <span class="thread-name">Integrity Scanner</span>
                        <span class="thread-time">Alert</span>
                    </div>
                    <span class="thread-preview">Scan for duplicates, unpaid items & geofence...</span>
                </div>
            </div>
            <div class="thread-item" onclick="switchTab('growth')" id="thread-growth">
                <div class="thread-icon bg-warning-subtle text-warning">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div class="thread-details">
                    <div class="thread-meta">
                        <span class="thread-name">Growth Forecast</span>
                        <span class="thread-time">Trend</span>
                    </div>
                    <span class="thread-preview">Run linear regression analysis forecasts...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Chat Panel -->
    <div class="whatsapp-chat-panel">
        <!-- TAB 1: PERFORMANCE ANALYTICS -->
        <div id="tab-performance" class="ai-tab-content h-100" style="padding: 24px; overflow-y: auto;">
<?php endif; ?>

<div class="perf-kpi-grid">
    <div class="perf-kpi">
        <div class="perf-kpi__top">
            <span class="perf-kpi__label">Total Employees</span>
            <span class="perf-kpi__icon perf-kpi__icon--violet"><i class="bi bi-people-fill"></i></span>
        </div>
        <span class="perf-kpi__value"><?= (int) $overview['total_employees'] ?></span>
        <span class="perf-kpi__hint">Active this week</span>
    </div>
    <div class="perf-kpi">
        <div class="perf-kpi__top">
            <span class="perf-kpi__label"><?= $usesMissions ? 'Missions Submitted' : 'Plans Submitted' ?></span>
            <span class="perf-kpi__icon perf-kpi__icon--green"><i class="bi bi-clipboard-check-fill"></i></span>
        </div>
        <span class="perf-kpi__value"><?= (int) $overview['plans_submitted'] ?></span>
        <span class="perf-kpi__hint"><?= (int) $overview['plans_pct'] ?>% of employees</span>
    </div>
    <div class="perf-kpi">
        <div class="perf-kpi__top">
            <span class="perf-kpi__label">Avg Performance</span>
            <span class="perf-kpi__icon perf-kpi__icon--blue"><i class="bi bi-graph-up-arrow"></i></span>
        </div>
        <span class="perf-kpi__value"><?= (int) $overview['avg_performance'] ?>%</span>
        <?php if ($overview['trend'] > 0): ?>
            <span class="perf-kpi__hint perf-kpi__hint--up"><i class="bi bi-arrow-up-short"></i><?= (int) $overview['trend'] ?>% from last week</span>
        <?php elseif ($overview['trend'] < 0): ?>
            <span class="perf-kpi__hint perf-kpi__hint--down"><i class="bi bi-arrow-down-short"></i><?= abs((int) $overview['trend']) ?>% from last week</span>
        <?php else: ?>
            <span class="perf-kpi__hint">Same as last week</span>
        <?php endif; ?>
    </div>
    <div class="perf-kpi">
        <div class="perf-kpi__top">
            <span class="perf-kpi__label">Delayed Tasks</span>
            <span class="perf-kpi__icon perf-kpi__icon--orange"><i class="bi bi-clock-fill"></i></span>
        </div>
        <span class="perf-kpi__value"><?= (int) $overview['delayed_tasks'] ?></span>
        <span class="perf-kpi__hint perf-kpi__hint--warn">Needs attention</span>
    </div>
    <div class="perf-kpi">
        <div class="perf-kpi__top">
            <span class="perf-kpi__label">Tasks Completed</span>
            <span class="perf-kpi__icon perf-kpi__icon--green"><i class="bi bi-check-circle-fill"></i></span>
        </div>
        <span class="perf-kpi__value"><?= (int) $overview['completion_pct'] ?>%</span>
        <span class="perf-kpi__hint">Weighted completion</span>
    </div>
</div>

<div class="perf-summary-grid">
    <article class="perf-summary-card perf-summary-card--green">
        <div class="perf-summary-card__head">
            <span class="perf-summary-icon perf-summary-icon--green"><i class="bi bi-check-lg"></i></span>
            <span class="perf-summary-card__title perf-summary-card__title--green">AI Week Summary</span>
        </div>
        <div class="perf-summary-card__body perf-week-body">
            <ul id="weekSummaryList" class="perf-check-list">
                <?php foreach ($weekSummaryLines as $line): ?>
                    <li><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="perf-donut" style="--pct: <?= (int) $overview['completion_pct'] ?>">
                <span><?= (int) $overview['completion_pct'] ?>%</span>
            </div>
        </div>
        <div class="perf-summary-card__foot">
            <a href="dashboard.php?module=tasks<?= $weekOffset ? '&week=' . $weekOffset : '' ?>" class="perf-summary-card__link perf-summary-card__link--green">View details &rarr;</a>
        </div>
    </article>

    <article class="perf-summary-card perf-summary-card--blue">
        <div class="perf-summary-card__head">
            <span class="perf-summary-icon perf-summary-icon--blue"><i class="bi bi-trophy-fill"></i></span>
            <span class="perf-summary-card__title perf-summary-card__title--blue">Top Performer</span>
        </div>
        <div class="perf-summary-card__body">
            <?php if ($topPerformer): ?>
                <div class="perf-top-person">
                    <?php
                    $tpPhoto = perf_user_avatar_url($topPerformer['profile_photo'] ?? '', $topPerformer['full_name']);
                    $tpInitials = perf_initials($topPerformer['full_name']);
                    if ($tpPhoto !== ''): ?>
                        <img src="<?= htmlspecialchars($tpPhoto) ?>" alt="<?= htmlspecialchars($topPerformer['full_name']) ?>" class="perf-top-avatar" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <span class="perf-avatar" style="display:none;"><i class="bi bi-person-fill" style="font-size: 1.2rem;"></i></span>
                    <?php else: ?>
                        <span class="perf-avatar"><i class="bi bi-person-fill" style="font-size: 1.2rem;"></i></span>
                    <?php endif; ?>
                    <div>
                        <strong><?= htmlspecialchars($topPerformer['full_name']) ?></strong>
                        <small><?= htmlspecialchars(trim($topPerformer['department'] ?? '') ?: 'General') ?> Department</small>
                    </div>
                </div>
                <div class="perf-top-score" id="topPerformerScore"><?= (int) $topPerformer['score_pct'] ?>%</div>
            <?php else: ?>
                <p class="perf-reward-text">No plans submitted for this week yet.</p>
            <?php endif; ?>
        </div>
        <div class="perf-summary-card__foot">
            <?php if ($topPerformer): ?>
                <a href="view_plan.php?user_id=<?= (int) $topPerformer['id'] ?>&week_offset=<?= $weekOffset ?>&module=tasks" class="perf-summary-card__link perf-summary-card__link--blue">View reason &rarr;</a>
            <?php else: ?>
                <a href="plan.php?module=tasks" class="perf-summary-card__link perf-summary-card__link--blue">Create a plan &rarr;</a>
            <?php endif; ?>
        </div>
    </article>

    <article class="perf-summary-card perf-summary-card--purple">
        <div class="perf-summary-card__head">
            <span class="perf-summary-icon perf-summary-icon--purple"><i class="bi bi-gift-fill"></i></span>
            <span class="perf-summary-card__title perf-summary-card__title--purple">AI Reward Suggestion</span>
        </div>
        <div class="perf-summary-card__body">
            <p class="perf-reward-text" id="rewardSuggestionText">
                <?php if ($rewardCandidate): ?>
                    <strong><?= htmlspecialchars($rewardCandidate['full_name']) ?></strong> is the most deserving candidate for this week.
                <?php else: ?>
                    Submit weekly plans to unlock AI reward suggestions.
                <?php endif; ?>
            </p>
            <i class="bi bi-trophy-fill perf-reward-trophy" aria-hidden="true"></i>
        </div>
        <div class="perf-summary-card__foot">
            <a href="leaderboard.php?module=tasks<?= $weekOffset ? '&week=' . $weekOffset : '' ?>" class="perf-summary-card__link perf-summary-card__link--purple">See all candidates &rarr;</a>
        </div>
    </article>
</div>

<div class="perf-bottom-grid">
    <div class="perf-panel">
        <div class="perf-panel__head">
            <h3>Reward Candidates</h3>
        </div>
        <div class="perf-table-wrap">
            <?php if (empty($candidates)): ?>
                <div class="perf-empty">No candidates for this week.</div>
            <?php else: ?>
            <table class="perf-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Score</th>
                        <th>AI Suggestion</th>
                    </tr>
                </thead>
                <tbody id="candidatesBody">
                    <?php foreach ($candidates as $idx => $row): ?>
                    <tr>
                        <td><?= perf_medal_rank($idx) ?></td>
                        <td>
                            <div class="perf-employee-cell">
                                <?php
                                $photo = perf_user_avatar_url($row['profile_photo'] ?? '', $row['full_name']);
                                $rowInitials = perf_initials($row['full_name']);
                                if ($photo !== ''): ?>
                                    <img src="<?= htmlspecialchars($photo) ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <span class="perf-avatar-sm" style="display:none;"><i class="bi bi-person-fill" style="font-size: 0.9rem;"></i></span>
                                <?php else: ?>
                                    <span class="perf-avatar-sm"><i class="bi bi-person-fill" style="font-size: 0.9rem;"></i></span>
                                <?php endif; ?>
                                <span><?= htmlspecialchars($row['full_name']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars(trim($row['department'] ?? '') ?: '-') ?></td>
                        <td><span class="perf-score <?= perf_score_class((int) $row['score_pct']) ?>"><?= (int) $row['score_pct'] ?>%</span></td>
                        <td><span class="perf-pill <?= htmlspecialchars($row['class']) ?>"><?= htmlspecialchars($row['label']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="perf-panel__foot">
            <a href="leaderboard.php?module=tasks<?= $weekOffset ? '&week=' . $weekOffset : '' ?>">View full leaderboard &rarr;</a>
        </div>
    </div>

    <div class="perf-panel">
        <div class="perf-insights">
            <h3><i class="bi bi-lightbulb-fill"></i> AI Insights</h3>
            <div class="perf-insights-block">
                <h4 class="achievements">Achievements</h4>
                <ul id="insightsAchievements" class="perf-insights-list perf-insights-list--check">
                    <?php foreach ($insights['achievements'] as $a): ?>
                        <li><?= htmlspecialchars($a) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="perf-insights-block">
                <h4 class="suggestions">Suggestions</h4>
                <ul id="insightsSuggestions" class="perf-insights-list perf-insights-list--warn">
                    <?php foreach ($insights['suggestions'] as $s): ?>
                        <li><?= htmlspecialchars($s) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="perf-tip">
                <span class="perf-tip__icon"><i class="bi bi-stars"></i></span>
                <p class="perf-tip__text"><strong>Tip:</strong> Consistent weekly planning improves team performance.</p>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
        </div> <!-- Close TAB 1: tab-performance -->

        <!-- TAB 2: MODULE REPORTS -->
        <div id="tab-reports" class="ai-tab-content d-none" style="padding: 24px; overflow-y: auto;">
            <div class="ai-card">
                <h3 style="font-family:'Outfit'; font-size:18px; margin-bottom:12px;">
                    <i class="bi bi-file-earmark-text text-primary"></i> Module Analytics Summary
                </h3>
                <p class="text-muted" style="font-size:13px; margin-bottom:20px;">
                    Select any ERP feature area below to generate a detailed compliance report, audit logs, and operational recommendations.
                </p>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary w-100 p-3 text-start" onclick="generateReport('vouchers')">
                            <i class="bi bi-file-earmark-medical fs-4 d-block mb-2"></i>
                            <strong>Vouchers Report</strong>
                            <small class="d-block text-muted mt-1" style="font-size:11px;">Aggregates, amounts, flows</small>
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary w-100 p-3 text-start" onclick="generateReport('attendance')">
                            <i class="bi bi-clock-history fs-4 d-block mb-2"></i>
                            <strong>Attendance Audit</strong>
                            <small class="d-block text-muted mt-1" style="font-size:11px;">Lateness, geofence, shifts</small>
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary w-100 p-3 text-start" onclick="generateReport('performance')">
                            <i class="bi bi-award fs-4 d-block mb-2"></i>
                            <strong>Performance KPI</strong>
                            <small class="d-block text-muted mt-1" style="font-size:11px;">Streaks, points, completion</small>
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary w-100 p-3 text-start" onclick="generateReport('general')">
                            <i class="bi bi-grid-fill fs-4 d-block mb-2"></i>
                            <strong>Complete Audit</strong>
                            <small class="d-block text-muted mt-1" style="font-size:11px;">Cross-module intelligence</small>
                        </button>
                    </div>
                </div>

                <h4 style="font-size:15px; font-weight:600; margin-bottom:12px;">AI Analytical Suggestions</h4>
                <div class="analysis-output-box" id="reportsOutput">
                    Click any of the reports buttons above to execute AI intelligence compilation.
                </div>
            </div>
        </div>

        <!-- TAB 3: INTEGRITY & ANOMALIES -->
        <div id="tab-anomalies" class="ai-tab-content d-none" style="padding: 24px; overflow-y: auto;">
            <div class="ai-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 style="font-family:'Outfit'; font-size:18px; margin:0;">
                        <i class="bi bi-shield-alert text-danger"></i> System Integrity & Error Scan
                    </h3>
                    <button class="btn btn-danger btn-sm" onclick="runIntegrityScan()">
                        <i class="bi bi-shield-slash"></i> Run Scan Now
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-lg-5 col-12 mb-3">
                        <h4 style="font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">Scanning Results</h4>
                        
                        <ul class="anomalies-list" id="anomaliesList">
                            <li class="anomaly-item" style="background:#f1f5f9; border-color:#cbd5e1; color:#475569;">
                                <div class="anomaly-info">
                                    <i class="bi bi-info-circle-fill" style="color:#64748b;"></i>
                                    <div>
                                        <strong>Scan Pending</strong>
                                        <span class="anomaly-meta d-block">Click "Run Scan Now" to verify voucher and geofence integrity.</span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="col-lg-7 col-12">
                        <h4 style="font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">AI Risk Evaluation</h4>
                        <div class="analysis-output-box" id="anomaliesOutput">
                            Integrity audit recommendations and action items will populate here after executing the scan.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: GROWTH FORECAST -->
        <div id="tab-growth" class="ai-tab-content d-none" style="padding: 24px; overflow-y: auto;">
            <div class="ai-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 style="font-family:'Outfit'; font-size:18px; margin:0;">
                        <i class="bi bi-graph-up-arrow text-success"></i> Sales Aggregates & Growth Forecaster
                    </h3>
                    <button class="btn btn-success btn-sm" onclick="generateGrowthForecast()">
                        <i class="bi bi-calculator"></i> Run Linear Regression
                    </button>
                </div>

                <div class="growth-trend-box">
                    <div class="trend-stat">
                        <div class="trend-stat-val" id="forecastVal">--</div>
                        <div class="trend-stat-lbl">Forecasted next month</div>
                    </div>
                    <div class="trend-stat">
                        <div class="trend-stat-val" id="growthRate">--</div>
                        <div class="trend-stat-lbl">Forecasted Growth Rate</div>
                    </div>
                    <div class="trend-stat">
                        <div class="trend-stat-val" id="slopeVal">--</div>
                        <div class="trend-stat-lbl">Linear Slope (m)</div>
                    </div>
                </div>

                <h4 style="font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">Strategic Trend Report</h4>
                <div class="analysis-output-box" id="growthOutput">
                    The linear regression slope calculations, month-over-month averages, and growth commentary will show here.
                </div>
            </div>
        </div>
    </div> <!-- Close whatsapp-chat-panel -->
</div> <!-- Close whatsapp-layout -->
<?php endif; ?>

<script>
document.getElementById('btnGenerateAi')?.addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Analyzing...';
    fetch('api/ai_analysis.php?module=tasks&week=<?= (int) $weekOffset ?>')
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Failed');
            if (data.week_summary) {
                document.getElementById('weekSummaryList').innerHTML =
                    data.week_summary.map(t => '<li>' + escapeHtml(t) + '</li>').join('');
            }
            if (data.reward_text) {
                document.getElementById('rewardSuggestionText').innerHTML = data.reward_text;
            }
            if (data.achievements) {
                document.getElementById('insightsAchievements').innerHTML =
                    data.achievements.map(t => '<li>' + escapeHtml(t) + '</li>').join('');
            }
            if (data.suggestions) {
                document.getElementById('insightsSuggestions').innerHTML =
                    data.suggestions.map(t => '<li>' + escapeHtml(t) + '</li>').join('');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-stars"></i> Generate AI Analysis';
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-stars"></i> Generate AI Analysis';
            alert('Could not refresh analysis. Please try again.');
        });
});

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Added JS Logic for AI Management Sidebar
function switchTab(tabId) {
    document.querySelectorAll('.ai-tab-content').forEach(el => el.classList.add('d-none'));
    document.querySelectorAll('.thread-item').forEach(el => el.classList.remove('active'));
    
    document.getElementById('tab-' + tabId).classList.remove('d-none');
    const activeThread = document.getElementById('thread-' + tabId);
    if (activeThread) {
        activeThread.classList.add('active');
    }
}

// Reports Logic
function generateReport(moduleName) {
    const out = document.getElementById('reportsOutput');
    out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Compiling records and executing AI report builder...';
    
    const formData = new FormData();
    formData.append('ajax_action', 'module_report');
    formData.append('params[module]', moduleName);
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            out.textContent = data.analysis;
        } else {
            out.textContent = 'Error: ' + data.error;
        }
    })
    .catch(err => {
        out.textContent = 'Connection error.';
    });
}

// Integrity Scan Logic
function runIntegrityScan() {
    const list = document.getElementById('anomaliesList');
    const out = document.getElementById('anomaliesOutput');
    
    list.innerHTML = '<li><span class="spinner-border spinner-border-sm"></span> Auditing voucher and lateness records...</li>';
    out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Evaluation queued...';
    
    const formData = new FormData();
    formData.append('ajax_action', 'scan_errors');
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            out.textContent = data.analysis;
            
            // Render anomaly list items
            let itemsHtml = '';
            const anom = data.anomalies;
            let foundAny = false;
            
            if (anom.paid_unapproved.length > 0) {
                foundAny = true;
                anom.paid_unapproved.forEach(v => {
                    itemsHtml += `
                        <li class="anomaly-item">
                            <div class="anomaly-info">
                                <i class="bi bi-exclamation-triangle-fill anomaly-icon"></i>
                                <div>
                                    <strong>Paid Before Approval</strong>
                                    <span class="anomaly-meta d-block">Voucher ${v.voucher_no} is marked paid, but has status '${v.status}' (Value: ${v.currency} ${v.total_amount})</span>
                                </div>
                            </div>
                        </li>
                    `;
                });
            }
            
            if (anom.duplicates.length > 0) {
                foundAny = true;
                anom.duplicates.forEach(d => {
                    itemsHtml += `
                        <li class="anomaly-item">
                            <div class="anomaly-info">
                                <i class="bi bi-layers-half anomaly-icon"></i>
                                <div>
                                    <strong>Duplicate Detected</strong>
                                    <span class="anomaly-meta d-block">Vouchers ${d.voucher_a_no} and ${d.voucher_b_no} created to payee '${d.payee_name}' within 24h</span>
                                </div>
                            </div>
                        </li>
                    `;
                });
            }

            if (anom.attendance_geofence.length > 0) {
                foundAny = true;
                anom.attendance_geofence.forEach(a => {
                    itemsHtml += `
                        <li class="anomaly-item" style="background:#fffbeb; border-color:#fde047;">
                            <div class="anomaly-info">
                                <i class="bi bi-geo-alt-fill text-warning fs-5"></i>
                                <div>
                                    <strong>Geofence Exception</strong>
                                    <span class="anomaly-meta d-block">${a.full_name} signed in at ${a.time_in} (Status: ${a.status}, IP: ${a.ip_address})</span>
                                </div>
                            </div>
                        </li>
                    `;
                });
            }
            
            if (!foundAny) {
                itemsHtml = `
                    <li class="anomaly-item" style="background:#ecfdf5; border-color:#a7f3d0; color:#065f46;">
                        <div class="anomaly-info">
                            <i class="bi bi-check-circle-fill text-success fs-5"></i>
                            <div>
                                <strong>All Clear</strong>
                                <span class="anomaly-meta d-block" style="color:#047857;">No duplicate vouchers, unapproved paid items, or geofence exceptions found.</span>
                            </div>
                        </div>
                    </li>
                `;
            }
            
            list.innerHTML = itemsHtml;
        } else {
            list.innerHTML = '<li>Error loading results.</li>';
            out.textContent = data.error;
        }
    })
    .catch(err => {
        list.innerHTML = '<li>Connection failure.</li>';
        out.textContent = 'Connection error.';
    });
}

// Growth Forecaster Logic
function generateGrowthForecast() {
    const out = document.getElementById('growthOutput');
    out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Compiling sales matrix and forecasting regression values...';
    
    const formData = new FormData();
    formData.append('ajax_action', 'predict_growth');
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            out.textContent = data.analysis;
            
            // Update KPI totals
            const f = data.forecast;
            document.getElementById('forecastVal').textContent = 'TZS ' + Math.round(f.forecast_value).toLocaleString();
            document.getElementById('growthRate').textContent = (f.growth_rate >= 0 ? '+' : '') + f.growth_rate.toFixed(1) + '%';
            document.getElementById('slopeVal').textContent = f.slope.toFixed(0);
        } else {
            out.textContent = 'Error: ' + data.error;
        }
    })
    .catch(err => {
        out.textContent = 'Connection error.';
    });
}
</script>

<?php perf_layout_end(); ?>

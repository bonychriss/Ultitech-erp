<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/includes/agent-lib.php';
requireLogin();
if (function_exists('csrf_token')) {
    csrf_token();
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$page_title = 'AI Agent';
$employeeHeaderTitle = 'AI Agent';
$employeeHeaderSubtitle = 'Receivables and today\'s briefing';

$loadError = '';
$briefing = null;
$overdue = [];
$dueSoon = [];
try {
    $ctx = aiAgentContext();
    $briefing = aiAgentGenerateDailyBriefing($ctx);
    $overdue = aiAgentInvoiceRows($ctx, 'overdue', 8);
    $dueSoon = aiAgentInvoiceRows($ctx, 'due_soon', 5);
} catch (Throwable $e) {
    error_log('ai-agent page: ' . $e->getMessage());
    $loadError = 'I could not read ERP data for this company.';
}

$cssPath = dirname(__DIR__, 2) . '/assets/css/ai-agent.css';
$jsPath = dirname(__DIR__, 2) . '/assets/js/ai-agent.js';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVer = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();
$cssUrl = function_exists('app_url') ? app_url('/assets/css/ai-agent.css') : '/assets/css/ai-agent.css';
$jsUrl = function_exists('app_url') ? app_url('/assets/js/ai-agent.js') : '/assets/js/ai-agent.js';

$currency = (string) ($briefing['currency'] ?? 'TZS');
$summary = is_array($briefing['receivables'] ?? null) ? $briefing['receivables'] : [];
$attention = (int) ($briefing['total_attention_items'] ?? 0);
$attentionLabel = $attention === 1 ? '1 item needs attention today.' : $attention . ' items need attention today.';

function ai_agent_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ai_agent_metric(array $summary, string $key, string $currency): string
{
    if (empty($summary['available']) || !isset($summary[$key]) || $summary[$key] === null) {
        return 'Unavailable';
    }
    return aiAgentFormatMoney((float) $summary[$key], $currency);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Agent</title>
    <script>
    (function () {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= ai_agent_h($cssUrl . '?v=' . $cssVer) ?>">
</head>
<body class="ai-agent-body">
<?php require dirname(__DIR__, 2) . '/includes/header_employee.php'; ?>
<main class="main-content ai-agent-page">
    <section class="ai-hero">
        <div class="ai-hero-mark" aria-hidden="true"><i class="bi bi-robot"></i></div>
        <div>
            <h2><?= ai_agent_h($briefing['greeting'] ?? aiAgentGreeting()) ?></h2>
            <p><?= $loadError !== '' ? ai_agent_h($loadError) : ai_agent_h($attentionLabel) ?></p>
        </div>
    </section>

    <?php if ($loadError === '' && is_array($briefing)): ?>
    <div class="ai-actions">
        <a class="ai-chip" href="#ai-receivables">Review receivables</a>
        <a class="ai-chip" href="<?= ai_agent_h((string) ($briefing['urls']['vouchers'] ?? '#')) ?>">View pending approvals</a>
        <a class="ai-chip" href="<?= ai_agent_h((string) ($briefing['urls']['stock'] ?? '#')) ?>">View stock alerts</a>
    </div>

    <section class="ai-metrics" id="ai-receivables">
        <article class="ai-metric">
            <span>Outstanding</span>
            <strong><?= ai_agent_h(ai_agent_metric($summary, 'outstanding_amount', $currency)) ?></strong>
        </article>
        <article class="ai-metric ai-metric-urgent">
            <span>Overdue</span>
            <strong><?= ai_agent_h(ai_agent_metric($summary, 'overdue_amount', $currency)) ?></strong>
        </article>
        <article class="ai-metric ai-metric-soon">
            <span>Due soon</span>
            <strong><?= ai_agent_h(ai_agent_metric($summary, 'due_soon_amount', $currency)) ?></strong>
            <small>Next <?= (int) ($summary['within_days'] ?? 7) ?> days</small>
        </article>
        <article class="ai-metric">
            <span>Overdue invoices</span>
            <strong><?= empty($summary['available']) ? 'Unavailable' : (int) ($summary['overdue_count'] ?? 0) ?></strong>
        </article>
    </section>
    <?php if (empty($summary['available'])): ?>
        <p class="ai-note"><?= ai_agent_h((string) ($summary['reason'] ?? 'Receivables are unavailable.')) ?></p>
    <?php else: ?>
        <section class="ai-card" id="ai-summary-card">
            <p class="ai-kicker">Receivables summary <span id="ai-summary-source"></span></p>
            <p class="ai-lead" id="ai-summary-facts"><?= ai_agent_h((string) ($briefing['narrative']['facts'] ?? '')) ?></p>
            <p class="ai-analysis" id="ai-summary-analysis"><?= ai_agent_h((string) ($briefing['narrative']['analysis'] ?? '')) ?></p>
        </section>
    <?php endif; ?>

    <section class="ai-card">
        <div class="ai-card-head">
            <h3>Overdue invoices</h3>
            <a href="<?= ai_agent_h((string) ($briefing['urls']['invoices'] ?? '#')) ?>">Open invoices</a>
        </div>
        <?php if ($overdue === []): ?>
            <p class="ai-empty">No overdue customer invoices.</p>
        <?php else: ?>
            <ul class="ai-invoice-list">
                <?php foreach ($overdue as $invoice): ?>
                    <li>
                        <div>
                            <strong><?= ai_agent_h((string) $invoice['customer_name']) ?></strong>
                            <span><?= ai_agent_h((string) $invoice['invoice_number']) ?></span>
                            <span><?= ai_agent_h(aiAgentFormatMoney((float) $invoice['balance_due'], $currency)) ?></span>
                            <span class="ai-overdue-days"><?= (int) $invoice['days_overdue'] ?> days overdue</span>
                        </div>
                        <div class="ai-row-actions">
                            <a class="ai-btn" href="<?= ai_agent_h((string) $invoice['view_url']) ?>">View invoice</a>
                            <button type="button" class="ai-btn ai-btn-ghost" data-follow-up="<?= (int) $invoice['id'] ?>">Prepare follow-up</button>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if (!empty($summary['available'])): ?>
    <section class="ai-card">
        <div class="ai-card-head">
            <h3>Due soon</h3>
        </div>
        <?php if ($dueSoon === []): ?>
            <p class="ai-empty">No invoices are due within the next <?= (int) ($summary['within_days'] ?? 7) ?> days.</p>
        <?php else: ?>
            <ul class="ai-invoice-list">
                <?php foreach ($dueSoon as $invoice): ?>
                    <li>
                        <div>
                            <strong><?= ai_agent_h((string) $invoice['customer_name']) ?></strong>
                            <span><?= ai_agent_h((string) $invoice['invoice_number']) ?></span>
                            <span><?= ai_agent_h(aiAgentFormatMoney((float) $invoice['balance_due'], $currency)) ?></span>
                            <span>Due <?= ai_agent_h((string) $invoice['due_date']) ?></span>
                        </div>
                        <div class="ai-row-actions">
                            <a class="ai-btn" href="<?= ai_agent_h((string) $invoice['view_url']) ?>">View invoice</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="ai-card" id="ai-briefing">
        <p class="ai-kicker">Daily briefing <span id="ai-briefing-source"></span></p>
        <p class="ai-date"><?= ai_agent_h((string) ($briefing['date_label'] ?? '')) ?></p>
        <p class="ai-lead" id="ai-briefing-lead"><?= ai_agent_h((string) ($briefing['greeting'] ?? '')) ?>. <?= ai_agent_h($attentionLabel) ?></p>
        <div class="ai-briefing">
            <?php
            $blocks = [
                [
                    'tone' => 'urgent',
                    'title' => 'Receivables',
                    'show' => !empty($summary['available']),
                    'lines' => !empty($summary['available'])
                        ? [
                            ((int) $summary['overdue_count']) . ' customer ' . ((int) $summary['overdue_count'] === 1 ? 'invoice is' : 'invoices are') . ' overdue',
                            aiAgentFormatMoney((float) $summary['overdue_amount'], $currency, true) . ' overdue',
                        ]
                        : [(string) ($summary['reason'] ?? '')],
                    'url' => (string) ($briefing['urls']['invoices'] ?? '#'),
                    'label' => 'Review receivables',
                ],
                [
                    'tone' => 'attention',
                    'title' => 'Approvals',
                    'show' => !empty($briefing['approvals']['available']),
                    'lines' => !empty($briefing['approvals']['available'])
                        ? [((int) $briefing['approvals']['pending_count']) . ' payment ' . ((int) $briefing['approvals']['pending_count'] === 1 ? 'voucher is' : 'vouchers are') . ' waiting for approval']
                        : [(string) ($briefing['approvals']['reason'] ?? 'Unavailable')],
                    'url' => (string) ($briefing['approvals']['url'] ?? '#'),
                    'label' => 'Review approvals',
                ],
                [
                    'tone' => 'warn',
                    'title' => 'Stock',
                    'show' => !empty($briefing['stock']['available']),
                    'lines' => !empty($briefing['stock']['available'])
                        ? [((int) $briefing['stock']['low_stock_count']) . ((int) $briefing['stock']['low_stock_count'] === 1 ? ' product is' : ' products are') . ' below the minimum level']
                        : [(string) ($briefing['stock']['reason'] ?? 'Unavailable')],
                    'url' => (string) ($briefing['stock']['url'] ?? '#'),
                    'label' => 'View stock',
                ],
                [
                    'tone' => 'info',
                    'title' => 'Procurement',
                    'show' => !empty($briefing['procurement']['available']),
                    'lines' => !empty($briefing['procurement']['available'])
                        ? [((int) $briefing['procurement']['stalled_count']) . ((int) $briefing['procurement']['stalled_count'] === 1 ? ' request has' : ' requests have') . ' been waiting'
                            . ((int) $briefing['procurement']['oldest_wait_days'] > 0
                                ? ', the oldest for ' . (int) $briefing['procurement']['oldest_wait_days'] . ' days'
                                : '')]
                        : [(string) ($briefing['procurement']['reason'] ?? 'Unavailable')],
                    'url' => (string) ($briefing['procurement']['url'] ?? '#'),
                    'label' => 'Review procurement',
                ],
            ];
            foreach ($blocks as $block):
            ?>
                <article class="ai-brief-item ai-tone-<?= ai_agent_h($block['tone']) ?>">
                    <h3><?= ai_agent_h($block['title']) ?></h3>
                    <?php foreach ($block['lines'] as $line): ?>
                        <p><?= ai_agent_h($line) ?></p>
                    <?php endforeach; ?>
                    <?php if ($block['show'] && $block['url'] !== ''): ?>
                        <a class="ai-btn" href="<?= ai_agent_h($block['url']) ?>"><?= ai_agent_h($block['label']) ?></a>
                    <?php else: ?>
                        <p class="ai-future">Future integration until this data exists for the company.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!empty($briefing['anomalies']['available']) && (int) $briefing['anomalies']['count'] > 0): ?>
                <article class="ai-brief-item ai-tone-info">
                    <h3>Invoice balances</h3>
                    <p><?= (int) $briefing['anomalies']['count'] ?> invoices have a balance that does not match the amount paid, or are marked paid while a balance remains.</p>
                    <a class="ai-btn" href="<?= ai_agent_h((string) $briefing['anomalies']['url']) ?>">Review invoices</a>
                </article>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="ai-card ai-chat" id="ai-chat">
        <p class="ai-kicker">Ask about receivables</p>
        <div class="ai-thread" id="ai-thread" aria-live="polite"></div>
        <form id="ai-ask-form" class="ai-ask">
            <label class="visually-hidden" for="ai-message">Message</label>
            <input id="ai-message" name="message" type="text" maxlength="500" placeholder="Ask about overdue invoices, balances, or today's briefing" autocomplete="off">
            <button type="submit" class="ai-btn">Ask</button>
        </form>
        <div class="ai-suggestions">
            <button type="button" data-ask="Show me overdue invoices.">Show me overdue invoices.</button>
            <button type="button" data-ask="How much do customers owe us?">How much do customers owe us?</button>
            <button type="button" data-ask="Which customers have overdue invoices?">Which customers have overdue invoices?</button>
            <button type="button" data-ask="What needs my attention today?">What needs my attention today?</button>
            <button type="button" data-ask="Which invoice is the oldest overdue?">Which invoice is the oldest overdue?</button>
            <button type="button" data-ask="Show invoices due soon.">Show invoices due soon.</button>
            <button type="button" data-ask="Show me receivables for this month.">Show me receivables for this month.</button>
        </div>
        <p class="ai-footnote">Questions and the briefing narrative go through ACE on this computer. The figures come from the current company's ERP records. ACE does not change invoices, payments, or accounting. Email and WhatsApp sending are future integrations.</p>
    </section>
</main>
<script>
window.__AI_AGENT__ = <?= json_encode([
    'apiUrl' => aiAgentApiUrl(),
    'csrf' => function_exists('csrf_token') ? csrf_token() : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= ai_agent_h($jsUrl . '?v=' . $jsVer) ?>"></script>
</body>
</html>

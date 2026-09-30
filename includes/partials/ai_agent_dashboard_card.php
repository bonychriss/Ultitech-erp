<?php
/**
 * Compact dashboard link into the AI Agent briefing.
 * Counts stay on the AI Agent page so the dashboard can render immediately.
 */
declare(strict_types=1);

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    return;
}

$aiDashUrl = function_exists('company_url')
    ? company_url('modules/ai-agent/index.php')
    : (function_exists('app_url') ? app_url('/modules/ai-agent/index.php') : '/modules/ai-agent/index.php');

$aiCss = dirname(__DIR__, 2) . '/assets/css/ai-agent.css';
$aiCssUrl = function_exists('app_url') ? app_url('/assets/css/ai-agent.css') : '/assets/css/ai-agent.css';
$aiCssVer = is_file($aiCss) ? (string) filemtime($aiCss) : (string) time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($aiCssUrl . '?v=' . $aiCssVer, ENT_QUOTES, 'UTF-8') ?>">
<a class="ai-dash-card" href="<?= htmlspecialchars($aiDashUrl, ENT_QUOTES, 'UTF-8') ?>">
    <div>
        <strong>AI Agent</strong>
        <p class="mb-0">Open today's briefing</p>
    </div>
    <span class="ai-btn">Open briefing</span>
</a>

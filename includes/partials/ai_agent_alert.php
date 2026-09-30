<?php
/**
 * Non-blocking receivables alert. Loads counts after the page, once per company per day.
 */
declare(strict_types=1);

if (!empty($GLOBALS['_ultitech_ai_agent_alert_rendered'])) {
    return;
}
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    return;
}
$GLOBALS['_ultitech_ai_agent_alert_rendered'] = true;

$aiLib = dirname(__DIR__, 2) . '/modules/ai-agent/includes/agent-lib.php';
if (!is_file($aiLib)) {
    return;
}
require_once $aiLib;

$aiCss = dirname(__DIR__, 2) . '/assets/css/ai-agent.css';
$aiJs = dirname(__DIR__, 2) . '/assets/js/ai-agent-alert.js';
$aiCssUrl = function_exists('app_url') ? app_url('/assets/css/ai-agent.css') : '/assets/css/ai-agent.css';
$aiJsUrl = function_exists('app_url') ? app_url('/assets/js/ai-agent-alert.js') : '/assets/js/ai-agent-alert.js';
$aiCssVer = is_file($aiCss) ? (string) filemtime($aiCss) : (string) time();
$aiJsVer = is_file($aiJs) ? (string) filemtime($aiJs) : (string) time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($aiCssUrl . '?v=' . $aiCssVer, ENT_QUOTES, 'UTF-8') ?>">
<script>
window.__AI_AGENT_ALERT__ = <?= json_encode([
    'apiUrl' => aiAgentApiUrl(),
    'pageUrl' => aiAgentPageUrl(),
    'companyId' => (int) ($_SESSION['company_id'] ?? 0),
    'date' => date('Y-m-d'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars($aiJsUrl . '?v=' . $aiJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>

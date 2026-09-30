<?php

declare(strict_types=1);

namespace App\Domains\AiAgent;

/**
 * AI Agent React shell. Page data comes from the existing read-only agent library.
 */
final class AiAgentShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $lib = $root . '/modules/ai-agent/includes/agent-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;

        $assets = $this->loadReactAssets($root);
        if ($assets === null) {
            return null;
        }

        $csrf = (string) ($cfg['csrf'] ?? '');
        $payload = function_exists('aiAgentPagePayload') ? aiAgentPagePayload($csrf) : ['ok' => false, 'loadError' => 'AI Agent data is unavailable.', 'csrf' => $csrf];
        $bootJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{"ok":false,"loadError":"AI Agent data is unavailable."}';
        }

        $cssUrl = function_exists('app_url') ? app_url('/assets/css/ai-agent.css') : '/assets/css/ai-agent.css';
        $cssPath = $root . '/assets/css/ai-agent.css';
        $cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
        $uiJs = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];
        $uiCssTag = '';
        if (($assets['cssFile'] ?? '') !== '') {
            $uiCss = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
            $uiCssTag = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($uiCss, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        $headMarkup = '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl . '?v=' . $cssVer, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $uiCssTag
            . $this->pageChromeCss() . "\n"
            . '<script>window.__AI_AGENT__ = ' . $bootJson . ';</script>';

        return [
            'pageTitle' => 'AI Agent',
            'bodyClass' => 'page-ai-agent page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => '<script type="module" crossorigin src="'
                . htmlspecialchars($uiJs, ENT_QUOTES, 'UTF-8')
                . '"></script>',
            'employeeHeaderTitle' => 'AI Agent',
            'employeeHeaderSubtitle' => 'Receivables and today\'s briefing',
            'hideHeaderCompanyBranding' => false,
            'employeeHeaderExtraClass' => 'employee-header--ai-agent',
            'mainRootClass' => 'ai-agent-react-root',
        ];
    }

    private function pageChromeCss(): string
    {
        return <<<'CSS'
<style>
body.page-ai-agent,
body.page-ai-agent.dashboard,
body.page-ai-agent .layout-main-wrapper,
body.page-ai-agent .layout-main-wrapper > .flex-grow-1,
body.page-ai-agent main.main-content.ai-agent-react-root {
    background: #f8fafc !important;
}
body.page-ai-agent .employee-header.employee-header--ai-agent {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
}
html[data-theme="dark"] body.page-ai-agent,
html[data-theme="dark"] body.page-ai-agent.dashboard,
html[data-theme="dark"] body.page-ai-agent .layout-main-wrapper,
html[data-theme="dark"] body.page-ai-agent .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-ai-agent main.main-content.ai-agent-react-root,
html[data-theme="dark"] body.page-ai-agent .employee-header.employee-header--ai-agent {
    background: #0f172a !important;
}
</style>
CSS;
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    private function loadReactAssets(string $root): ?array
    {
        $distIndex = $root . '/modules/ai-agent/frontend/dist/index.html';
        if (!is_file($distIndex)) {
            return null;
        }
        $distHtml = file_get_contents($distIndex) ?: '';
        preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
        preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
        $jsFile = $jsMatch[1] ?? '';
        $cssFile = $cssMatch[1] ?? '';
        if ($jsFile === '') {
            return null;
        }
        $base = function_exists('app_url')
            ? rtrim((string) app_url('/modules/ai-agent/frontend/dist/assets'), '/') . '/'
            : '/modules/ai-agent/frontend/dist/assets/';
        $cssPath = $root . '/modules/ai-agent/frontend/dist/assets/' . $cssFile;
        $jsPath = $root . '/modules/ai-agent/frontend/dist/assets/' . $jsFile;

        return [
            'assetBase' => $base,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        ];
    }
}

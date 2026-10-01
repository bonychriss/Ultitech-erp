<?php

declare(strict_types=1);

namespace App\Domains\AiAssistant;

/**
 * ChatGPT-style AI Assistant shell. Messages go to modules/ai-agent/api.php.
 */
final class AiAssistantShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $assets = $this->loadReactAssets($root);
        if ($assets === null) {
            return null;
        }

        $erp = is_array($cfg['erp'] ?? null) ? $cfg['erp'] : [];
        $full = trim((string) ($erp['full_name'] ?? ''));
        $first = $full !== '' ? (string) (preg_split('/\s+/', $full)[0] ?? '') : '';
        $boot = [
            'csrf' => (string) ($erp['csrf'] ?? ''),
            'apiUrl' => (string) ($erp['api_url'] ?? ''),
            'firstName' => $first,
            'module' => (string) ($erp['module'] ?? ''),
        ];
        $bootJson = json_encode(
            $boot,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{"csrf":"","apiUrl":"","firstName":"","module":""}';
        }

        $uiJs = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];
        $uiCssTag = '';
        if (($assets['cssFile'] ?? '') !== '') {
            $uiCss = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
            $uiCssTag = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($uiCss, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        $headMarkup = '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">' . "\n"
            . $uiCssTag
            . $this->pageChromeCss() . "\n"
            . '<script>window.__AI_ASSISTANT__ = ' . $bootJson . ';</script>';

        return [
            'pageTitle' => 'AI Assistant',
            'bodyClass' => 'page-ai-assistant page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => '<script type="module" crossorigin src="'
                . htmlspecialchars($uiJs, ENT_QUOTES, 'UTF-8')
                . '"></script>',
            'employeeHeaderTitle' => '',
            'employeeHeaderSubtitle' => '',
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--ai-assistant',
            'mainRootClass' => 'ai-assistant-react-root',
        ];
    }

    private function pageChromeCss(): string
    {
        return <<<'CSS'
<style>
body.page-ai-assistant,
body.page-ai-assistant.dashboard,
body.page-ai-assistant .layout-main-wrapper,
body.page-ai-assistant .layout-main-wrapper > .flex-grow-1,
body.page-ai-assistant main.main-content.ai-assistant-react-root {
    background: #ffffff !important;
}
body.page-ai-assistant .layout-main-wrapper {
    display: flex !important;
    flex-direction: row !important;
    align-items: stretch !important;
    min-height: 100vh;
    height: 100vh;
    overflow: hidden;
}
body.page-ai-assistant #native-sidebar {
    height: 100vh !important;
    max-height: 100vh !important;
    align-self: stretch !important;
    flex: 0 0 var(--sidebar-width) !important;
    overflow-y: auto !important;
}
body.page-ai-assistant .layout-main-wrapper > .flex-grow-1 {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto;
    min-width: 0;
    min-height: 0;
    height: 100vh;
    overflow: hidden;
}
body.page-ai-assistant .employee-header.employee-header--ai-assistant {
    background: #ffffff !important;
    border: none !important;
    box-shadow: none !important;
    min-height: 0;
    height: auto !important;
    flex: none;
    padding: 0 1rem !important;
}
body.page-ai-assistant main.main-content.ai-assistant-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 0 72px !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column;
    min-height: 0;
    position: relative !important;
}
body.page-ai-assistant main.main-content.ai-assistant-react-root #root {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}
html[data-theme="dark"] body.page-ai-assistant,
html[data-theme="dark"] body.page-ai-assistant.dashboard,
html[data-theme="dark"] body.page-ai-assistant .layout-main-wrapper,
html[data-theme="dark"] body.page-ai-assistant .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-ai-assistant main.main-content.ai-assistant-react-root,
html[data-theme="dark"] body.page-ai-assistant .employee-header.employee-header--ai-assistant,
html[data-theme="dark"] body.page-ai-assistant #native-sidebar {
    background: #212121 !important;
}
</style>
CSS;
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    private function loadReactAssets(string $root): ?array
    {
        $distIndex = $root . '/admin/ai-assistant-ui/frontend/dist/index.html';
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
            ? rtrim((string) app_url('/admin/ai-assistant-ui/frontend/dist/assets'), '/') . '/'
            : '/admin/ai-assistant-ui/frontend/dist/assets/';
        $cssPath = $root . '/admin/ai-assistant-ui/frontend/dist/assets/' . $cssFile;
        $jsPath = $root . '/admin/ai-assistant-ui/frontend/dist/assets/' . $jsFile;

        return [
            'assetBase' => $base,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        ];
    }
}

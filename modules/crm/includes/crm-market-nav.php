<?php
/**
 * CRM Market sidebar links (Client Market tools in the ERP sidebar).
 */

declare(strict_types=1);

/**
 * @return list<array{id:string,label:string,path:string}>
 */
function crmMarketSidebarViews(): array
{
    return [
        ['id' => 'home', 'label' => 'Home', 'path' => '/'],
        ['id' => 'search', 'label' => 'Search', 'path' => '/search'],
        ['id' => 'history', 'label' => 'Saved search', 'path' => '/history'],
        ['id' => 'message', 'label' => 'Message', 'path' => '/message'],
        ['id' => 'settings', 'label' => 'Settings', 'path' => '/settings'],
    ];
}

/**
 * Map legacy settings views to a settings hub tab.
 */
function crmMarketSettingsTab(string $view): ?string
{
    static $map = [
        'settings' => 'crawling',
        'crawling-settings' => 'crawling',
        'rapid-settings' => 'rapid',
        'search-settings' => 'search',
        'search-02-settings' => 'search-02',
        'email' => 'email',
        'apify' => 'apify',
        'database' => 'database',
    ];
    return $map[$view] ?? null;
}

function crmMarketIsSettingsView(string $view): bool
{
    return crmMarketSettingsTab($view) !== null;
}

function crmMarketCurrentView(): string
{
    $view = strtolower(trim((string) ($_GET['view'] ?? 'home')));
    if ($view === 'new-leads' || $view === 'summary') {
        return $view;
    }
    if (crmMarketIsSettingsView($view)) {
        return 'settings';
    }
    $allowed = array_column(crmMarketSidebarViews(), 'id');
    if (!in_array($view, $allowed, true)) {
        return 'home';
    }
    return $view;
}

function crmMarketViewPath(string $view): string
{
    $tab = crmMarketSettingsTab($view);
    if ($tab !== null) {
        return '/settings?tab=' . rawurlencode($tab);
    }
    foreach (crmMarketSidebarViews() as $row) {
        if ($row['id'] === $view) {
            $path = (string) $row['path'];
            if ($view === 'search') {
                $extra = [];
                foreach (['q', 'location', 'category', 'from', 'run'] as $key) {
                    $val = trim((string) ($_GET[$key] ?? ''));
                    if ($val !== '') {
                        $extra[$key] = $val;
                    }
                }
                if ($extra) {
                    $path .= '?' . http_build_query($extra);
                }
            }
            return $path;
        }
    }
    return '/';
}

/**
 * @return list<array{id:string,label:string,icon:string,path:string}>
 */
function crmMarketSidebarChildren(string $marketBaseHref, string $iconSet = 'fa'): array
{
    $iconsFa = [
        'home' => 'home',
        'crawling' => 'search',
        'rapid' => 'bolt',
        'search' => 'search',
        'history' => 'bookmark',
        'search-02' => 'flask',
        'customers' => 'database',
        'message' => 'envelope',
        'settings' => 'cog',
        'import' => 'file-import',
    ];
    $iconsBs = [
        'home' => 'house',
        'crawling' => 'search',
        'rapid' => 'lightning',
        'search' => 'search',
        'history' => 'bookmark',
        'search-02' => 'eyedropper',
        'customers' => 'database',
        'message' => 'envelope',
        'settings' => 'gear',
        'import' => 'box-arrow-in-down',
    ];
    $icons = $iconSet === 'bs' ? $iconsBs : $iconsFa;
    $out = [];
    foreach (crmMarketSidebarViews() as $row) {
        $id = $row['id'];
        $parts = parse_url($marketBaseHref);
        $path = is_array($parts) && !empty($parts['path'])
            ? (string) $parts['path']
            : preg_replace('/\?.*$/', '', $marketBaseHref);
        parse_str(is_array($parts) ? (string) ($parts['query'] ?? '') : '', $query);
        $query['module'] = 'crm';
        $query['view'] = $id;
        $href = $path . '?' . http_build_query($query);
        $out[] = [
            'id' => 'crm-market-' . $id,
            'label' => $row['label'],
            'icon' => $icons[$id] ?? 'circle',
            'path' => $href,
            'title' => $row['label'],
        ];
    }
    return $out;
}


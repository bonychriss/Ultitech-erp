<?php

declare(strict_types=1);

/**
 * UltiTech browser-tab icon. The root /favicon.ico covers pages served from the domain root;
 * these tags cover installs under a sub-path (e.g. localhost/public_html) and pages that set no icon.
 */

if (!function_exists('erp_favicon_links')) {
    /**
     * @return array<int,array{rel:string,href:string,type?:string,sizes?:string}>
     */
    function erp_favicon_links(): array
    {
        $root = dirname(__DIR__, 2);
        $url = static function (string $path) use ($root): string {
            $file = $root . '/' . ltrim($path, '/');
            $version = is_file($file) ? '?v=' . filemtime($file) : '';
            if (function_exists('app_url')) {
                return app_url($path) . $version;
            }
            $base = defined('APP_BASE_PATH') ? '/' . trim((string) APP_BASE_PATH, '/') : '';

            return rtrim($base, '/') . '/' . ltrim($path, '/') . $version;
        };

        return [
            ['rel' => 'icon', 'href' => $url('/favicon.ico'), 'sizes' => 'any'],
            ['rel' => 'icon', 'href' => $url('/assets/favicon/favicon-32.png'), 'type' => 'image/png', 'sizes' => '32x32'],
            ['rel' => 'icon', 'href' => $url('/assets/favicon/favicon-192.png'), 'type' => 'image/png', 'sizes' => '192x192'],
            ['rel' => 'apple-touch-icon', 'href' => $url('/assets/favicon/apple-touch-icon.png'), 'sizes' => '180x180'],
        ];
    }
}

if (!function_exists('erp_favicon_tags')) {
    function erp_favicon_tags(): string
    {
        $html = '';
        foreach (erp_favicon_links() as $link) {
            $html .= '<link';
            foreach ($link as $attr => $value) {
                $html .= ' ' . $attr . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }
            $html .= ">\n";
        }

        return $html;
    }
}

if (!function_exists('erp_favicon_script')) {
    /** For markup printed inside <body>: adds the icon tags to <head> when the page set none. */
    function erp_favicon_script(): string
    {
        $links = json_encode(erp_favicon_links(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<script>(function(){if(document.querySelector(\'link[rel~="icon"]\'))return;'
            . 'var links=' . $links . ';links.forEach(function(l){var el=document.createElement("link");'
            . 'Object.keys(l).forEach(function(k){el.setAttribute(k,l[k]);});document.head.appendChild(el);});})();</script>' . "\n";
    }
}

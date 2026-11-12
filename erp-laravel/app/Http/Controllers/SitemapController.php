<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public XML sitemap for the UltiTech marketing site.
 */
class SitemapController extends Controller
{
    public function show(Request $request): Response
    {
        $cfg = is_array($GLOBALS['ERP_SITEMAP_CONTEXT'] ?? null) ? $GLOBALS['ERP_SITEMAP_CONTEXT'] : [];
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? config('app.url')), '/');
        $siteRoot = dirname(base_path());

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ((array) config('sitemap.pages', []) as $page) {
            $path = '/' . ltrim((string) ($page['path'] ?? '/'), '/');
            $file = $siteRoot . '/' . ltrim((string) ($page['file'] ?? ''), '/');
            $lastmod = is_file($file) ? date('Y-m-d', (int) filemtime($file)) : date('Y-m-d');

            $xml .= "  <url>\n"
                . '    <loc>' . htmlspecialchars($baseUrl . ($path === '/' ? '/' : $path), ENT_XML1) . "</loc>\n"
                . "    <lastmod>{$lastmod}</lastmod>\n"
                . '    <changefreq>' . htmlspecialchars((string) ($page['changefreq'] ?? 'monthly'), ENT_XML1) . "</changefreq>\n"
                . '    <priority>' . htmlspecialchars((string) ($page['priority'] ?? '0.5'), ENT_XML1) . "</priority>\n"
                . "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}

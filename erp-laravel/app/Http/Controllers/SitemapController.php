<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public XML sitemap (with Google image extension) for the UltiTech marketing site.
 */
class SitemapController extends Controller
{
    public function show(Request $request): Response
    {
        $cfg = is_array($GLOBALS['ERP_SITEMAP_CONTEXT'] ?? null) ? $GLOBALS['ERP_SITEMAP_CONTEXT'] : [];
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? config('app.url')), '/');
        $siteRoot = dirname(base_path());
        $manifestPath = (string) config('sitemap.manifest', '');
        $manifest = $this->loadManifest($siteRoot . '/' . $manifestPath);
        $buildDir = trim(dirname(dirname($manifestPath)), '/');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ((array) config('sitemap.pages', []) as $page) {
            $path = '/' . ltrim((string) ($page['path'] ?? '/'), '/');
            $file = $siteRoot . '/' . ltrim((string) ($page['file'] ?? ''), '/');
            $lastmod = is_file($file) ? date('Y-m-d', (int) filemtime($file)) : date('Y-m-d');

            $xml .= "  <url>\n"
                . '    <loc>' . htmlspecialchars($baseUrl . $path, ENT_XML1) . "</loc>\n"
                . "    <lastmod>{$lastmod}</lastmod>\n"
                . '    <changefreq>' . htmlspecialchars((string) ($page['changefreq'] ?? 'monthly'), ENT_XML1) . "</changefreq>\n"
                . '    <priority>' . htmlspecialchars((string) ($page['priority'] ?? '0.5'), ENT_XML1) . "</priority>\n";

            foreach ((array) ($page['images'] ?? []) as $image) {
                $rel = $this->resolveImage((string) $image, $manifest, $buildDir, $siteRoot);
                if ($rel !== null) {
                    $xml .= "    <image:image>\n"
                        . '      <image:loc>' . htmlspecialchars($baseUrl . '/' . $rel, ENT_XML1) . "</image:loc>\n"
                        . "    </image:image>\n";
                }
            }

            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function loadManifest(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Site-relative path of an existing image file, or null when it cannot be found.
     *
     * @param array<string,array<string,mixed>> $manifest
     */
    private function resolveImage(string $image, array $manifest, string $buildDir, string $siteRoot): ?string
    {
        $image = ltrim($image, '/');
        if (str_starts_with($image, 'src/')) {
            $built = (string) ($manifest[$image]['file'] ?? '');
            if ($built === '') {
                return null;
            }
            $image = $buildDir . '/' . $built;
        }
        return is_file($siteRoot . '/' . $image) ? $image : null;
    }
}

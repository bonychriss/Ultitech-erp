<?php
/**
 * Shared Enerpize catalog helpers for Roadmaster stock scripts.
 */

declare(strict_types=1);

const RM_ENERPIZE_BASE = 'https://roadmaster.enerpize.com';

function rm_en_normalize_name(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = strtolower($name);
    $name = preg_replace('/#\d+/', '', $name) ?? $name;
    $name = str_replace(['disel', 'automative'], ['diesel', 'automotive'], $name);
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);

    return $name;
}

function rm_en_name_tokens(string $normalized): array
{
    $stop = ['and', 'with', 'the', 'for', 'of', 'assembly', 'lh', 'rh', 'left', 'right', 'main', 'heavy', 'duty'];
    $parts = explode(' ', $normalized);
    $tokens = [];
    foreach ($parts as $part) {
        if ($part === '' || strlen($part) < 2 || in_array($part, $stop, true)) {
            continue;
        }
        $tokens[] = $part;
    }

    return array_values(array_unique($tokens));
}

/** @return array<string, string> */
function rm_en_alias_map(): array
{
    return [
        'oil filter' => 'Oil filter',
        'diesel filter' => 'Fuel filter',
        'disel filter' => 'Fuel filter',
        'fuel filter' => 'Fuel filter',
        'rotary diesel filter assembly filter element x0710' => 'Fuel filter',
        'air cleaner' => 'Cabin air filter',
        'water separator' => 'Fuel filter',
        'cylinder head gasket' => 'Engine Gasket Set',
        'brake drum' => 'Brake drum',
        'rear brake drum' => 'Brake drum',
        'axle brake shoe with friction plate assembly' => 'Brake Pad',
        'oil pressure sensor' => 'Temperature sensor',
        'oil ring piston' => 'Piston Rings',
        'supercharge' => 'Throttle Body',
        'injection pump' => 'Fuel Rail',
        'clutch cover and pressure plate assembly' => 'Pressure plate',
        'main filter element assembly' => 'Oil filter',
        'expansion tank pressure cover assembly' => 'Coolant reservoir',
        'expansion tank and pressure cover assembly' => 'Coolant reservoir',
        'expansion tank assembly plastic' => 'Coolant reservoir',
        'power steering mechanismassembly' => 'Steering pump',
        'power steering mechanism assembly' => 'Steering pump',
        'brake chamber assembly right' => 'Brake booster',
        'air compressor pump assembly' => 'Belt tensioner',
        'right steering tie rod arm' => 'Steering rod',
        'front leaf spring' => 'leaf spring',
        'fan backing block' => 'Fan blade',
        'rim cover' => 'wheel hub',
        'yunnan white radiator mask' => 'Radiator',
        'front bumper mask assembly primer' => 'Front bumper',
        'center bumper assembly' => 'Front bumper',
        'right bumper welding assembly' => 'Front bumper',
        'towing hook cover bumper' => 'Front bumper',
        'valve' => 'EGR valve',
        'diesel engine oil' => 'Oil cooler',
        'heavy duty automotive gear oil' => 'Oil cooler',
        'left fender assembly' => 'Mudguards',
        'right fender assembly' => 'Mudguards',
        'side trim cover right top cover' => 'Side mirror',
        'side trim cover left top cover' => 'Side mirror',
        'front out panel lh' => 'Grill',
        'front out panel rh' => 'Grill',
        'front wall left deflector assembly' => 'Grill',
        'front wall right deflector assembly' => 'Grill',
        'front wall left trim panel' => 'Grill',
        'front wall right trim panel' => 'Grill',
        'left door outer trim assembly' => 'Door handle',
        'right door outer trim assembly' => 'Door handle',
        'right front pillar outer trim panel' => 'Door hinges',
        'left front pillar outer trim panel' => 'Door hinges',
        'headlight upper right bracket b assembly' => 'Headlight assembly',
        'left level 1 and stage ii foot pedal shield' => 'Cabin mount',
        'left pedal upper baffle' => 'Cabin mount',
        'right pedal upper baffle' => 'Cabin mount',
    ];
}

function rm_en_fetch_url(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'UltiTech-ERP-EnerpizeImport/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body || $code < 200 || $code >= 400) {
        return null;
    }

    return (string) $body;
}

function rm_en_discover_list_pages(string $html): array
{
    $pages = [1];
    if (preg_match_all('/products_list\/page:(\d+)/', $html, $m)) {
        foreach ($m[1] as $page) {
            $pages[] = max(1, (int) $page);
        }
    }

    return array_values(array_unique($pages));
}

function rm_en_extract_attrs_from_block(string $block): ?array
{
    $get = static function (string $attr) use ($block): string {
        if (!preg_match('/' . preg_quote($attr, '/') . '="([^"]*)"/i', $block, $m)) {
            return '';
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };

    $name = trim($get('data-name'));
    $image = trim($get('data-image'));
    if ($name === '' || $image === '') {
        return null;
    }

    $description = '';
    if (preg_match('/<p class="[^"]*font-weight-medium text-body[^"]*">\s*(.*?)\s*<\/p>/is', $block, $descMatch)) {
        $description = trim(html_entity_decode(strip_tags($descMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return [
        'energize_id' => trim($get('data-product-id')),
        'name' => $name,
        'image' => $image,
        'description' => $description,
        'price' => (float) preg_replace('/[^0-9.]/', '', $get('data-price')),
        'brand' => trim($get('data-brand'), " \t,"),
        'category' => trim($get('data-category'), " \t,"),
    ];
}

/**
 * @return array<string, array{
 *   energize_id:string,name:string,normalized:string,tokens:array<int,string>,
 *   image:string,description:string,price:float,brand:string,category:string
 * }>
 */
function rm_en_parse_catalog_html(string $html, bool $withDetails = false): array
{
    $catalog = [];
    $detailsByNormalized = [];

    if ($withDetails && preg_match_all('/data-product-id="\d+"/i', $html, $idMatches, PREG_OFFSET_CAPTURE)) {
        $offsets = $idMatches[0];
        $count = count($offsets);
        for ($i = 0; $i < $count; $i++) {
            $start = max(0, (int) $offsets[$i][1] - 200);
            $end = $i + 1 < $count
                ? (int) $offsets[$i + 1][1]
                : min(strlen($html), (int) $offsets[$i][1] + 5000);
            $block = substr($html, $start, $end - $start);
            $attrs = rm_en_extract_attrs_from_block($block);
            if ($attrs === null) {
                continue;
            }
            $normalized = rm_en_normalize_name($attrs['name']);
            if ($normalized === '') {
                continue;
            }
            $detailsByNormalized[$normalized] = $attrs;
        }
    }

    if (!preg_match_all(
        '/data-image="([^"]+)"[^>]*data-name="([^"]+)"/i',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        return $catalog;
    }

    foreach ($matches as $match) {
        $image = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($name === '' || $image === '') {
            continue;
        }
        $normalized = rm_en_normalize_name($name);
        if ($normalized === '' || isset($catalog[$normalized])) {
            continue;
        }

        $detail = $detailsByNormalized[$normalized] ?? null;
        $catalog[$normalized] = [
            'energize_id' => (string) ($detail['energize_id'] ?? ''),
            'name' => $name,
            'normalized' => $normalized,
            'tokens' => rm_en_name_tokens($normalized),
            'image' => $image,
            'description' => (string) ($detail['description'] ?? ''),
            'price' => (float) ($detail['price'] ?? 0),
            'brand' => (string) ($detail['brand'] ?? ''),
            'category' => (string) ($detail['category'] ?? ''),
        ];
    }

    return $catalog;
}

/** @return array<string, array> */
function rm_en_load_catalog(bool $withDetails = false): array
{
    $urls = [
        RM_ENERPIZE_BASE . '/home',
        RM_ENERPIZE_BASE . '/contents/products_list',
    ];

    $html = '';
    foreach ($urls as $url) {
        $chunk = rm_en_fetch_url($url);
        if ($chunk !== null) {
            $html .= "\n" . $chunk;
        }
    }

    if ($html === '') {
        throw new RuntimeException('Could not fetch Enerpize catalog pages.');
    }

    $pages = rm_en_discover_list_pages($html);
    foreach ($pages as $page) {
        if ($page <= 1) {
            continue;
        }
        $chunk = rm_en_fetch_url(RM_ENERPIZE_BASE . '/contents/products_list/page:' . $page);
        if ($chunk !== null) {
            $html .= "\n" . $chunk;
        }
    }

    return rm_en_parse_catalog_html($html, $withDetails);
}

function rm_en_token_overlap_score(array $a, array $b): float
{
    if ($a === [] || $b === []) {
        return 0.0;
    }
    $shared = array_intersect($a, $b);
    $denom = max(count($a), count($b));

    return (count($shared) / $denom) * 100.0;
}

/** @param array<string, array> $catalog */
function rm_en_find_match(string $localName, array $catalog, int $minScore): ?array
{
    $normalized = rm_en_normalize_name($localName);
    $aliases = rm_en_alias_map();

    if (isset($aliases[$normalized])) {
        $target = rm_en_normalize_name($aliases[$normalized]);
        if (isset($catalog[$target])) {
            return ['score' => 100.0, 'match' => $catalog[$target], 'method' => 'alias'];
        }
    }

    if (isset($catalog[$normalized])) {
        return ['score' => 100.0, 'match' => $catalog[$normalized], 'method' => 'exact'];
    }

    $tokens = rm_en_name_tokens($normalized);
    $best = null;
    $bestScore = 0.0;
    $bestMethod = 'fuzzy';

    foreach ($catalog as $entry) {
        similar_text($normalized, $entry['normalized'], $pct);
        $tokenScore = rm_en_token_overlap_score($tokens, $entry['tokens']);
        $score = max($pct, $tokenScore);

        if (str_contains($entry['normalized'], $normalized) || str_contains($normalized, $entry['normalized'])) {
            $score = max($score, 85.0);
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $entry;
            $bestMethod = $tokenScore >= $pct ? 'tokens' : 'fuzzy';
        }
    }

    if ($best === null || $bestScore < $minScore) {
        return null;
    }

    return ['score' => $bestScore, 'match' => $best, 'method' => $bestMethod];
}

function rm_en_download_file(string $url, string $dest): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $fh = fopen($dest, 'wb');
    if ($fh === false) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 25,
        CURLOPT_USERAGENT => 'UltiTech-ERP-EnerpizeImport/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if (!$ok || $code < 200 || $code >= 400) {
        @unlink($dest);

        return false;
    }

    $info = @getimagesize($dest);

    return is_array($info) && ($info[0] ?? 0) > 80 && ($info[1] ?? 0) > 80;
}

function rm_en_assign_product_image(PDO $pdo, ImageProcessor $processor, int $productId, string $sourcePath): string
{
    $filename = $processor->processUploadedImage($sourcePath, $productId);

    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare(
        'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, 1, NULL)'
    )->execute([$productId, $filename]);
    $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$filename, $productId]);

    return $filename;
}

/** @param array<string, array> $catalog @return array<string, true> */
function rm_en_matched_catalog_keys(array $catalog, array $localProducts, int $minScore): array
{
    $matched = [];
    foreach ($localProducts as $local) {
        $name = trim((string) ($local['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $found = rm_en_find_match($name, $catalog, $minScore);
        if ($found !== null) {
            $matched[(string) $found['match']['normalized']] = true;
        }
    }

    return $matched;
}

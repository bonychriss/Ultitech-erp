<?php
/**
 * Download bank / mobile money logos into assets/balances/institutions/{slug}.png (128x128).
 * Tries the institution's own site icons, then DuckDuckGo's icon service, then draws an initials badge.
 *
 * Usage: php scripts/fetch-institution-logos.php [--force] [--badges] [slug ...]
 *   --force   re-download even when a logo already exists
 *   --badges  only redraw the initials badge (no downloads)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$catalog = require __DIR__ . '/../modules/balances/institutions_catalog.php';
$outDir = dirname(__DIR__) . '/assets/balances/institutions';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$badgesOnly = in_array('--badges', $args, true);
$only = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));

function http_get(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if ($body === false || $code >= 400 || $body === '') {
        return null;
    }
    return ['body' => $body, 'url' => $final];
}

function absolute_url(string $href, string $base): string
{
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    if (str_starts_with($href, '//')) {
        return ($p['scheme'] ?? 'https') . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    return $origin . $dir . $href;
}

/** Largest PNG embedded in an ICO file, or null. */
function ico_png(string $data): ?string
{
    if (strlen($data) < 6 || substr($data, 0, 4) !== "\x00\x00\x01\x00") {
        return null;
    }
    $count = unpack('v', substr($data, 4, 2))[1];
    $best = null;
    $bestW = 0;
    for ($i = 0; $i < $count; $i++) {
        $e = substr($data, 6 + $i * 16, 16);
        if (strlen($e) < 16) {
            break;
        }
        $w = ord($e[0]) ?: 256;
        $size = unpack('V', substr($e, 8, 4))[1];
        $off = unpack('V', substr($e, 12, 4))[1];
        $img = substr($data, $off, $size);
        if (str_starts_with($img, "\x89PNG") && $w > $bestW) {
            $best = $img;
            $bestW = $w;
        }
    }
    return $best;
}

/** GD image from raw bytes (PNG/JPEG/GIF/WEBP, or PNG inside ICO), or null. */
function load_raster(string $data)
{
    if ($png = ico_png($data)) {
        $data = $png;
    }
    if (str_starts_with(ltrim($data), '<')) {
        return null;
    }
    $im = @imagecreatefromstring($data);
    return $im ?: null;
}

function save_square_png($src, string $path): void
{
    $size = 128;
    $dst = imagecreatetruecolor($size, $size);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 0));
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(($size - 12) / $w, ($size - 12) / $h);
    $nw = (int) round($w * $scale);
    $nh = (int) round($h * $scale);
    imagecopyresampled($dst, $src, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    imagepng($dst, $path, 9);
    imagedestroy($dst);
}

function initials(string $name): string
{
    if (preg_match('/\(([A-Z&]{2,5})\)/', $name, $m)) {
        return $m[1];
    }
    $clean = preg_replace('/\([^)]*\)|\b(bank|tanzania|commercial|microfinance|co-operative|of|the)\b/i', '', $name);
    $words = preg_split('/[\s\-]+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) === 1) {
        return strtoupper(substr($words[0], 0, 3));
    }
    return strtoupper(implode('', array_map(static fn ($w) => $w[0], array_slice($words, 0, 3))));
}

function draw_badge(array $inst, string $path): void
{
    $size = 128;
    $im = imagecreatetruecolor($size, $size);
    [$r, $g, $b] = sscanf($inst['color'], '#%02x%02x%02x');
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    $light = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160;
    $fg = $light ? imagecolorallocate($im, 17, 24, 39) : imagecolorallocate($im, 255, 255, 255);
    $text = $inst['badge'] ?? initials($inst['name']);
    $font = 'C:/Windows/Fonts/arialbd.ttf';
    if (is_file($font) && function_exists('imagettftext')) {
        $pt = strlen($text) > 3 ? 26 : (strlen($text) === 3 ? 32 : 40);
        $box = imagettfbbox($pt, 0, $font, $text);
        $tw = $box[2] - $box[0];
        $th = $box[1] - $box[7];
        imagettftext($im, $pt, 0, (int) (($size - $tw) / 2 - $box[0]), (int) (($size + $th) / 2), $fg, $font, $text);
    } else {
        imagestring($im, 5, (int) (($size - imagefontwidth(5) * strlen($text)) / 2), 56, $text, $fg);
    }
    imagepng($im, $path, 9);
    imagedestroy($im);
}

$report = [];
foreach ($catalog as $inst) {
    $slug = $inst['slug'];
    if ($only && !in_array($slug, $only, true)) {
        continue;
    }
    $path = "{$outDir}/{$slug}.png";
    if ($badgesOnly) {
        draw_badge($inst, $path);
        $report[] = sprintf('%-20s badge redrawn', $slug);
        continue;
    }
    if (is_file($path) && !$force) {
        $report[] = sprintf('%-20s kept existing', $slug);
        continue;
    }

    $candidates = [];
    $home = http_get('https://' . $inst['domain'] . '/') ?? http_get('https://www.' . $inst['domain'] . '/');
    if ($home) {
        if (preg_match_all('/<link\b[^>]*>/i', $home['body'], $links)) {
            foreach ($links[0] as $tag) {
                if (!preg_match('/rel=["\']([^"\']+)["\']/i', $tag, $rel) || !preg_match('/href=["\']([^"\']+)["\']/i', $tag, $href)) {
                    continue;
                }
                $relv = strtolower($rel[1]);
                if (!str_contains($relv, 'icon')) {
                    continue;
                }
                $rank = str_contains($relv, 'apple-touch') ? 2 : 1;
                $candidates[] = [absolute_url(html_entity_decode($href[1]), $home['url']), $rank];
            }
        }
        $candidates[] = [absolute_url('/apple-touch-icon.png', $home['url']), 2];
        $candidates[] = [absolute_url('/favicon.ico', $home['url']), 0];
    }
    $altDomains = [
        'nmb' => ['nmb.co.tz'], 'equity' => ['equitygroupholdings.com', 'equitybank.co.ke'], 'kcb' => ['ke.kcbgroup.com'],
        'im-bank' => ['imbankgroup.com', 'imbank.co.tz'], 'dtb' => ['dtbtanzania.com', 'dtbk.dtbafrica.com'],
        'airtel-money' => ['airtel.africa'], 'mixx-by-yas' => ['mixxbyyas.co.tz'], 'nbc' => ['nbcbank.co.tz', 'absa.africa'],
        'halopesa' => ['halopesa.co.tz'], 'letshego' => ['letshego.co.tz'], 'access' => ['accessbankplc.com', 'tz.accessbankplc.com'],
        'ecobank' => ['ecobank.com'], 'bank-of-baroda' => ['bankofbaroda.com'], 'bank-of-india' => ['bankofindia.co.in'],
        'tib' => ['tib.co.tz'], 'amana' => ['amanabank.co.tz'], 'mkombozi' => ['mkombozibank.co.tz'],
    ];
    foreach (array_merge([$inst['domain']], $altDomains[$slug] ?? []) as $d) {
        $candidates[] = ['https://t3.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=https://' . $d . '&size=256', 1];
        $candidates[] = ['https://icons.duckduckgo.com/ip3/' . $d . '.ico', 0];
    }

    $best = null;
    $bestScore = 0;
    $bestSrc = '';
    $seen = [];
    foreach ($candidates as [$url, $rank]) {
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $res = http_get($url);
        $im = $res ? load_raster($res['body']) : null;
        if (!$im) {
            continue;
        }
        $w = min(imagesx($im), imagesy($im));
        $score = $w * 10 + $rank;
        if ($w >= 48 && $score > $bestScore) {
            if ($best) {
                imagedestroy($best);
            }
            $best = $im;
            $bestScore = $score;
            $bestSrc = $url;
        } else {
            imagedestroy($im);
        }
    }

    if ($best) {
        save_square_png($best, $path);
        imagedestroy($best);
        $report[] = sprintf('%-20s logo  %dpx  %s', $slug, intdiv($bestScore, 10), $bestSrc);
    } else {
        draw_badge($inst, $path);
        $report[] = sprintf('%-20s badge (no usable logo found)', $slug);
    }
}

echo implode("\n", $report), "\n";

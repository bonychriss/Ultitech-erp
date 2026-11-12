<?php
/**
 * Shared helpers for ledger report pages.
 */

if (!function_exists('gl_h')) {
    function gl_h($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('gl_fmt')) {
    /** Accounting format: negatives in brackets. */
    function gl_fmt(float $v): string
    {
        if (abs($v) < 0.005) {
            return '0.00';
        }
        return $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2);
    }
}

if (!function_exists('gl_date_param')) {
    function gl_date_param(string $key, string $default): string
    {
        $v = (string) ($_GET[$key] ?? '');
        $ts = $v !== '' ? strtotime($v) : false;
        return $ts ? date('Y-m-d', $ts) : $default;
    }
}

if (!function_exists('gl_period_params')) {
    /** @return array{0:string,1:string} from/to, swapped if reversed */
    function gl_period_params(): array
    {
        $from = gl_date_param('from_date', gl_date_param('start_date', date('Y-01-01')));
        $to = gl_date_param('to_date', gl_date_param('end_date', date('Y-m-d')));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }
}

if (!function_exists('gl_send_csv')) {
    /** @param list<list<string|float|int>> $rows */
    function gl_send_csv(string $filename, array $header, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
}

if (!function_exists('gl_export_url')) {
    function gl_export_url(): string
    {
        $q = $_GET;
        $q['export'] = 'csv';
        return '?' . http_build_query($q);
    }
}

if (!function_exists('gl_pdf_ascii')) {
    function gl_pdf_ascii(string $text): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            return '';
        }
        return preg_replace('/[^\x20-\x7E]/', '', $converted) ?? '';
    }
}

if (!function_exists('gl_pdf_text')) {
    /** One line of Helvetica text as a PDF content-stream fragment. */
    function gl_pdf_text(float $x, float $y, float $size, string $text, bool $rightAligned = false, bool $bold = false): string
    {
        $plain = gl_pdf_ascii($text);
        if ($rightAligned) {
            $x -= strlen($plain) * $size * 0.52;
        }
        $escaped = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $plain);
        return sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $escaped);
    }
}

if (!function_exists('gl_pdf_logo')) {
    /**
     * Company logo as a white-backed JPEG for PDF embedding (only files inside the app root).
     *
     * @return array{bytes:string,width:int,height:int}|null
     */
    function gl_pdf_logo(): ?array
    {
        if (!function_exists('getCompanyLogoUrl') || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $logoUrl = getCompanyLogoUrl();
        $logoPath = parse_url($logoUrl, PHP_URL_PATH);
        $logoHost = parse_url($logoUrl, PHP_URL_HOST);
        $requestHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (!is_string($logoPath) || $logoPath === '' || ($logoHost !== null && strcasecmp((string) $logoHost, $requestHost) !== 0)) {
            return null;
        }
        $appBasePath = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
        if ($appBasePath !== '/' && str_starts_with($logoPath, $appBasePath . '/')) {
            $logoPath = substr($logoPath, strlen($appBasePath) + 1);
        } else {
            $logoPath = ltrim($logoPath, '/');
        }
        $appRoot = realpath(dirname(__DIR__, 2));
        if ($appRoot === false) {
            return null;
        }
        $resolved = realpath($appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($logoPath)));
        if ($resolved === false || !str_starts_with($resolved, $appRoot . DIRECTORY_SEPARATOR) || !is_file($resolved)) {
            return null;
        }
        $imageBytes = @file_get_contents($resolved);
        if (!@getimagesize($resolved) || !is_string($imageBytes) || $imageBytes === '') {
            return null;
        }
        $source = @imagecreatefromstring($imageBytes);
        if ($source === false) {
            return null;
        }
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = min(1, 180 / max(1, $sw), 54 / max(1, $sh));
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $sw, $sh);
        ob_start();
        $ok = imagejpeg($canvas, null, 88);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);
        return $ok && is_string($jpeg) && $jpeg !== '' ? ['bytes' => $jpeg, 'width' => $w, 'height' => $h] : null;
    }
}

if (!function_exists('gl_pdf_send')) {
    /**
     * Send a landscape A4 PDF built from page content streams. The logo (if any) is available as /Logo.
     *
     * @param list<string> $pages
     * @param array{bytes:string,width:int,height:int}|null $logo
     */
    function gl_pdf_send(string $filename, array $pages, ?array $logo): void
    {
        if (!$pages) {
            $pages = [''];
        }
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $boldObject = 4 + count($pages) * 2 + ($logo !== null ? 1 : 0);
        $objects[$boldObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $logoObject = 4 + count($pages) * 2;
        $refs = [];
        foreach ($pages as $index => $content) {
            $pageObject = 4 + $index * 2;
            $contentObject = $pageObject + 1;
            $refs[] = "{$pageObject} 0 R";
            $xObject = $logo !== null ? " /XObject << /Logo {$logoObject} 0 R >>" : '';
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 {$boldObject} 0 R >>{$xObject} >> /Contents {$contentObject} 0 R >>";
            $objects[$contentObject] = '<< /Length ' . strlen($content) . " >>\nstream\n{$content}endstream";
        }
        if ($logo !== null) {
            $objects[$logoObject] = "<< /Type /XObject /Subtype /Image /Width {$logo['width']} /Height {$logo['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($logo['bytes']) . " >>\nstream\n{$logo['bytes']}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $refs) . '] /Count ' . count($pages) . ' >>';
        ksort($objects);

        $doc = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($doc);
            $doc .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($doc);
        $count = max(array_keys($objects)) + 1;
        $doc .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $doc .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $doc .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($doc));
        echo $doc;
        exit;
    }
}

if (!function_exists('gl_account_label')) {
    function gl_account_label(array $a): string
    {
        $code = trim((string) ($a['code'] ?? ''));
        return ($code !== '' ? $code . ' - ' : '') . (string) ($a['name'] ?? '');
    }
}

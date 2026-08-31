<?php

declare(strict_types=1);

require_once __DIR__ . '/fpdf.php';

/**
 * PHP fallback PDF generator for shared hosting without Python.
 */
final class SmsLabelPdfGenerator extends FPDF
{
    /** @var array<int, array<string, mixed>> */
    private const LAYOUTS = [
        1 => [
            'cols' => 1,
            'rows' => 1,
            'landscape' => true,
            'wide' => true,
            'font' => 20,
            'line_gap_mm' => 7,
            'image_ratio' => 0.44,
            'min_font' => 11,
            'text_offset_mm' => 14,
        ],
        2 => ['cols' => 2, 'rows' => 1, 'landscape' => false, 'wide' => false, 'font' => 10, 'line_gap_mm' => 4.5, 'image_ratio' => 0.38, 'min_font' => 7],
        4 => ['cols' => 2, 'rows' => 2, 'landscape' => false, 'wide' => false, 'font' => 9, 'line_gap_mm' => 4, 'image_ratio' => 0.34, 'min_font' => 6],
        6 => ['cols' => 2, 'rows' => 3, 'landscape' => false, 'wide' => false, 'font' => 8, 'line_gap_mm' => 3.5, 'image_ratio' => 0.30, 'min_font' => 5.5],
        8 => ['cols' => 2, 'rows' => 4, 'landscape' => false, 'wide' => false, 'font' => 7, 'line_gap_mm' => 3, 'image_ratio' => 0.28, 'min_font' => 5],
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public static function generateBinary(array $payload): string
    {
        $perPage = (int) ($payload['per_page'] ?? 1);
        if (!isset(self::LAYOUTS[$perPage])) {
            $perPage = 1;
        }

        $labels = $payload['labels'] ?? [];
        if (!is_array($labels) || $labels === []) {
            throw new RuntimeException('No labels to generate.');
        }

        $layout = self::LAYOUTS[$perPage];
        $pages = array_chunk($labels, $perPage);
        $pdf = new self();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        foreach ($pages as $pageLabels) {
            $pdf->AddPage($layout['landscape'] ? 'L' : 'P');
            self::drawPage($pdf, $pageLabels, $layout);
        }

        return $pdf->Output('S');
    }

    /**
     * @param list<array<string, mixed>> $pageLabels
     * @param array<string, mixed> $layout
     */
    private static function drawPage(self $pdf, array $pageLabels, array $layout): void
    {
        $margin = 8.0;
        $gap = 6.0;
        $pageW = $pdf->GetPageWidth();
        $pageH = $pdf->GetPageHeight();
        $usableW = $pageW - (2 * $margin);
        $usableH = $pageH - (2 * $margin);
        $cols = (int) $layout['cols'];
        $rows = (int) $layout['rows'];
        $cellW = ($usableW - (($cols - 1) * $gap)) / $cols;
        $cellH = ($usableH - (($rows - 1) * $gap)) / $rows;

        foreach ($pageLabels as $index => $label) {
            if (!is_array($label)) {
                continue;
            }
            $col = $index % $cols;
            $row = (int) floor($index / $cols);
            $cellX = $margin + ($col * ($cellW + $gap));
            $cellY = $margin + ($row * ($cellH + $gap));
            self::drawLabel($pdf, $label, $cellX, $cellY, $cellW, $cellH, $layout);
        }
    }

    /**
     * @param array<string, mixed> $label
     * @param array<string, mixed> $layout
     */
    private static function drawLabel(self $pdf, array $label, float $x, float $y, float $width, float $height, array $layout): void
    {
        $padding = 3.0;
        $border = !empty($layout['wide']) ? 0.9 : 0.5;
        $pdf->SetLineWidth($border);
        $pdf->Rect($x, $y, $width, $height);

        $innerX = $x + $padding;
        $innerY = $y + $padding;
        $innerW = $width - (2 * $padding);
        $innerH = $height - (2 * $padding);
        $imagePath = (string) ($label['image_path'] ?? '');

        $blocks = [
            'PRODUCT CODE: ' . strtoupper((string) ($label['code'] ?? '')),
            'PRODUCT NAME : ' . strtoupper((string) ($label['name'] ?? '')),
            'CATEGORY : ' . strtoupper((string) ($label['category'] ?? '')),
            'SIZE(s) :',
        ];

        $startFont = (float) $layout['font'];
        $minFont = (float) ($layout['min_font'] ?? 8);
        $lineGapMm = (float) $layout['line_gap_mm'];

        if (!empty($layout['wide'])) {
            $imageGap = 4.0;
            $imageW = $innerW * (float) ($layout['image_ratio'] ?? 0.44);
            $textX = $innerX + $imageW + $imageGap;
            $textW = $innerW - $imageW - $imageGap;

            self::drawImageBox($pdf, $imagePath, $innerX, $innerY, $imageW, $innerH);

            $textOffset = (float) ($layout['text_offset_mm'] ?? 0);
            $textTop = $innerY + $textOffset;
            $textH = $innerH - $textOffset;
            $fontSize = self::pickFontSize($pdf, $blocks, $textW, $textH, $startFont, $minFont, $lineGapMm);
            self::drawTextBlocks($pdf, $blocks, $textX, $textTop, $textW, $textH, $fontSize, $lineGapMm);
            return;
        }

        $imageRatio = (float) ($layout['image_ratio'] ?? 0.4);
        $imageH = $innerH * $imageRatio;
        $textH = $innerH - $imageH - 2.0;
        $imageY = $innerY + $textH + 2.0;

        self::drawImageBox($pdf, $imagePath, $innerX, $imageY, $innerW, $imageH);

        $fontSize = self::pickFontSize($pdf, $blocks, $innerW, $textH, $startFont, $minFont, $lineGapMm);
        self::drawTextBlocks($pdf, $blocks, $innerX, $innerY, $innerW, $textH, $fontSize, $lineGapMm);
    }

    private static function drawImageBox(self $pdf, string $imagePath, float $x, float $y, float $width, float $height): void
    {
        if ($imagePath !== '' && is_file($imagePath)) {
            try {
                $info = @getimagesize($imagePath);
                if ($info !== false && (int) ($info[0] ?? 0) > 0 && (int) ($info[1] ?? 0) > 0) {
                    $imgW = (float) $info[0];
                    $imgH = (float) $info[1];
                    $scale = min($width / $imgW, $height / $imgH);
                    $drawW = $imgW * $scale;
                    $drawH = $imgH * $scale;
                    $drawX = $x + (($width - $drawW) / 2);
                    $drawY = $y + (($height - $drawH) / 2);
                    $pdf->Image($imagePath, $drawX, $drawY, $drawW, $drawH);
                    return;
                }

                $pdf->Image($imagePath, $x, $y, $width, 0);
                return;
            } catch (Throwable $e) {
                // Fall through to placeholder.
            }
        }

        $pdf->SetFont('Helvetica', 'B', min(8, $height / 6));
        $pdf->SetTextColor(140, 140, 140);
        $pdf->SetXY($x, $y + ($height / 2) - 3);
        $pdf->Cell($width, 6, 'NO IMAGE', 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * @param list<string> $blocks
     */
    private static function pickFontSize(self $pdf, array $blocks, float $maxWidth, float $maxHeight, float $startFont, float $minFont, float $lineGapMm): float
    {
        for ($size = $startFont; $size >= $minFont; $size -= ($startFont >= 14 ? 1.0 : 0.5)) {
            if (self::estimateBlocksHeight($pdf, $blocks, $maxWidth, $size, $lineGapMm) <= $maxHeight) {
                return $size;
            }
        }

        return $minFont;
    }

    /**
     * @param list<string> $blocks
     */
    private static function estimateBlocksHeight(self $pdf, array $blocks, float $maxWidth, float $fontSize, float $lineGapMm): float
    {
        $lineGap = max($fontSize * 1.25, $lineGapMm * ($fontSize / 20));
        $blockGap = 3.5;
        $total = 0.0;

        foreach ($blocks as $index => $block) {
            $lines = self::wrapLines($pdf, $block, $maxWidth, $fontSize);
            $total += count($lines) * $lineGap;
            if ($index < count($blocks) - 1) {
                $total += $blockGap;
            }
        }

        return $total;
    }

    /**
     * @param list<string> $blocks
     */
    private static function drawTextBlocks(self $pdf, array $blocks, float $x, float $y, float $width, float $maxHeight, float $fontSize, float $lineGapMm): void
    {
        $pdf->SetFont('Helvetica', 'B', $fontSize);
        $lineGap = max($fontSize * 1.25, $lineGapMm * ($fontSize / 20));
        $blockGap = 3.5;
        $cursorY = $y;
        $minY = $y + $maxHeight;

        foreach ($blocks as $blockIndex => $block) {
            foreach (self::wrapLines($pdf, $block, $width, $fontSize) as $line) {
                if ($cursorY + $fontSize > $minY) {
                    return;
                }
                $pdf->SetXY($x, $cursorY);
                $pdf->Cell($width, $fontSize, $line, 0, 0, 'L');
                $cursorY += $lineGap;
            }
            if ($blockIndex < count($blocks) - 1) {
                $cursorY += $blockGap;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function wrapLines(self $pdf, string $text, float $maxWidth, float $fontSize): array
    {
        $pdf->SetFont('Helvetica', 'B', $fontSize);
        $value = strtoupper(trim($text));
        if ($value === '') {
            return [''];
        }

        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', $value) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($pdf->GetStringWidth($candidate) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }

            if ($pdf->GetStringWidth($word) <= $maxWidth) {
                $current = $word;
                continue;
            }

            $chunk = '';
            $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($chars as $char) {
                $test = $chunk . $char;
                if ($pdf->GetStringWidth($test) <= $maxWidth) {
                    $chunk = $test;
                } else {
                    if ($chunk !== '') {
                        $lines[] = $chunk;
                    }
                    $chunk = $char;
                }
            }
            $current = $chunk;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines !== [] ? $lines : [''];
    }
}

function sms_generate_label_pdf_via_php(array $payload): string
{
    $binary = SmsLabelPdfGenerator::generateBinary($payload);
    if ($binary === '') {
        throw new RuntimeException('Generated PDF file is empty.');
    }

    return $binary;
}

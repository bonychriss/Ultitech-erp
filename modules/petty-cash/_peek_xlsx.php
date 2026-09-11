<?php
$path = 'c:/xampp/htdocs/public_html/reports/Cash Book 11-Sep-2026.xlsx';
$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    fwrite(STDERR, "open fail\n");
    exit(1);
}
$shared = [];
$sx = $zip->getFromName('xl/sharedStrings.xml');
if (is_string($sx) && $sx !== '') {
    $doc = new DOMDocument();
    $doc->loadXML($sx);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach ($xp->query('//m:si') as $si) {
        $t = '';
        foreach ($xp->query('.//m:t', $si) as $n) {
            $t .= $n->textContent;
        }
        $shared[] = $t;
    }
}
$sheet = null;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $n = (string) ($zip->statIndex($i)['name'] ?? '');
    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) {
        $sheet = $n;
        break;
    }
}
$xml = $sheet ? $zip->getFromName($sheet) : false;
$zip->close();
if (!is_string($xml)) {
    fwrite(STDERR, "no sheet\n");
    exit(1);
}
$doc = new DOMDocument();
$doc->loadXML($xml);
$xp = new DOMXPath($doc);
$xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
$rowNum = 0;
foreach ($xp->query('//m:sheetData/m:row') as $row) {
    if ($rowNum++ > 30) {
        break;
    }
    $cells = [];
    $max = -1;
    foreach ($xp->query('./m:c', $row) as $c) {
        if (!preg_match('/^([A-Z]+)/i', $c->getAttribute('r'), $m)) {
            continue;
        }
        $letters = strtoupper($m[1]);
        $col = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $col = $col * 26 + (ord($letters[$i]) - 64);
        }
        $col--;
        $max = max($max, $col);
        $type = $c->getAttribute('t');
        $vNode = $xp->query('./m:v', $c)->item(0);
        $is = $xp->query('./m:is//m:t', $c)->item(0);
        $raw = $is ? $is->textContent : ($vNode ? $vNode->textContent : '');
        if ($type === 's' && $raw !== '' && isset($shared[(int) $raw])) {
            $raw = $shared[(int) $raw];
        }
        $cells[$col] = $raw;
    }
    $line = [];
    for ($i = 0; $i <= $max; $i++) {
        $line[] = $cells[$i] ?? '';
    }
    echo 'R' . $rowNum . ': ' . json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

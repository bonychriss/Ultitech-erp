<?php

namespace App\Domains\CashBook;

use ZipArchive;

/**
 * Minimal CSV / XLSX reader for Cash Book import.
 */
final class SpreadsheetReader
{
    /**
     * @param array<string,mixed> $file $_FILES entry
     * @return array{ok:bool,error?:string,headers?:list<string>,rows?:list<list<string>>}
     */
    public function readUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed. Please choose a file and try again.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? 'upload');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => 'Uploaded file was not found.'];
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            return $this->readCsv($tmp);
        }
        if ($ext === 'xlsx') {
            return $this->readXlsx($tmp);
        }

        return ['ok' => false, 'error' => 'Unsupported file type. Upload a .xlsx or .csv file.'];
    }

    /**
     * @return array{ok:bool,error?:string,headers?:list<string>,rows?:list<list<string>>}
     */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'Could not read CSV file.'];
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $firstPos = ftell($fh);
        $first = fgets($fh);
        if ($first === false) {
            fclose($fh);

            return ['ok' => false, 'error' => 'CSV file is empty.'];
        }
        fseek($fh, $firstPos);

        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $matrix = [];
        while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
            $matrix[] = array_map(static fn ($c) => trim((string) $c), $cells);
        }
        fclose($fh);

        return $this->matrixToTable($matrix);
    }

    /**
     * @return array{ok:bool,error?:string,headers?:list<string>,rows?:list<list<string>>}
     */
    private function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'ZipArchive is required to read Excel files.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['ok' => false, 'error' => 'Could not open Excel file.'];
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $shared = $this->parseSharedStrings($sharedXml);
        }

        $sheetPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if (preg_match('#^xl/worksheets/sheet\\d+\\.xml$#', $name)) {
                $sheetPath = $name;
                break;
            }
        }
        $sheetXml = $sheetPath !== null ? $zip->getFromName($sheetPath) : false;
        $zip->close();

        if (!is_string($sheetXml) || $sheetXml === '') {
            return ['ok' => false, 'error' => 'Excel sheet data was not found.'];
        }

        return $this->matrixToTable($this->parseSheetMatrix($sheetXml, $shared));
    }

    /**
     * @return list<string>
     */
    private function parseSharedStrings(string $xml): array
    {
        $out = [];
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml)) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            return [];
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xpath->query('//m:si') ?: [] as $si) {
            $texts = [];
            foreach ($xpath->query('.//m:t', $si) ?: [] as $t) {
                $texts[] = (string) $t->textContent;
            }
            $out[] = implode('', $texts);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $out;
    }

    /**
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private function parseSheetMatrix(string $xml, array $shared): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml)) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            return [];
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        foreach ($xpath->query('//m:sheetData/m:row') ?: [] as $rowNode) {
            $cells = [];
            $maxCol = -1;
            foreach ($xpath->query('./m:c', $rowNode) ?: [] as $c) {
                $ref = (string) $c->getAttribute('r');
                $col = $this->colIndexFromRef($ref);
                if ($col < 0) {
                    continue;
                }
                $maxCol = max($maxCol, $col);
                $type = (string) $c->getAttribute('t');
                $vNode = $xpath->query('./m:v', $c)->item(0);
                $isNode = $xpath->query('./m:is//m:t', $c)->item(0);
                $raw = $isNode ? (string) $isNode->textContent : ($vNode ? (string) $vNode->textContent : '');
                if ($type === 's' && $raw !== '' && isset($shared[(int) $raw])) {
                    $raw = $shared[(int) $raw];
                }
                $cells[$col] = trim($raw);
            }
            $line = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $rows;
    }

    private function colIndexFromRef(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
            return -1;
        }
        $letters = strtoupper($m[1]);
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return $n - 1;
    }

    /**
     * @param list<list<string>> $matrix
     * @return array{ok:bool,error?:string,matrix?:list<list<string>>}
     */
    private function matrixToTable(array $matrix): array
    {
        $matrix = array_values(array_map(static function ($row) {
            if (!is_array($row)) {
                return [];
            }

            return array_map(static fn ($c) => trim((string) $c), $row);
        }, $matrix));

        $matrix = array_values(array_filter($matrix, static function ($row) {
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    return true;
                }
            }

            return false;
        }));

        if ($matrix === []) {
            return ['ok' => false, 'error' => 'Spreadsheet is empty.'];
        }

        return ['ok' => true, 'matrix' => $matrix];
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/ai_assistant_reports.php';

requireLogin();

$format = strtolower(trim((string) ($_GET['format'] ?? $_POST['format'] ?? 'csv')));
$reportJson = (string) ($_POST['report'] ?? $_GET['report'] ?? '');

if ($reportJson === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (is_array($body)) {
        $format = strtolower(trim((string) ($body['format'] ?? $format)));
        $reportJson = json_encode($body['report'] ?? [], JSON_UNESCAPED_UNICODE);
    }
}

$report = json_decode($reportJson, true);
if (!is_array($report)) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Invalid report payload.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$title = (string) ($report['title'] ?? 'AI Report');
$periodLabel = (string) ($report['periodLabel'] ?? '');
$cards = is_array($report['cards'] ?? null) ? $report['cards'] : [];
$table = is_array($report['table'] ?? null) ? $report['table'] : [];
$columns = is_array($table['columns'] ?? null) ? $table['columns'] : [];
$rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
$footer = is_array($table['footer'] ?? null) ? $table['footer'] : [];

$safeName = preg_replace('/[^a-z0-9_-]+/i', '-', $title) ?: 'ai-report';
$safeName = trim($safeName, '-');

if ($format === 'csv' || $format === 'excel' || $format === 'xlsx') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, [$title]);
    if ($periodLabel !== '') {
        fputcsv($out, ['Period', $periodLabel]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Metric', 'Value']);
    foreach ($cards as $card) {
        if (!is_array($card)) {
            continue;
        }
        fputcsv($out, [(string) ($card['label'] ?? ''), (string) ($card['value'] ?? '')]);
    }
    fputcsv($out, []);
    if ($columns !== []) {
        fputcsv($out, $columns);
        foreach ($rows as $row) {
            fputcsv($out, is_array($row) ? $row : [$row]);
        }
        if ($footer !== []) {
            fputcsv($out, $footer);
        }
    }
    fclose($out);
    exit;
}

if ($format === 'pdf' || $format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $safeName . '-' . date('Y-m-d') . '.html"');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: Inter, Arial, sans-serif; color: #111827; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .period { color: #6b7280; font-size: 13px; margin-bottom: 20px; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; }
        .card-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .card-value { font-size: 18px; font-weight: 700; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; }
        th { background: #f9fafb; font-size: 11px; text-transform: uppercase; color: #6b7280; }
        tfoot td { background: #eff6ff; font-weight: 700; }
        @media print { body { margin: 12px; } }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($periodLabel !== ''): ?>
        <div class="period"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div class="cards">
        <?php foreach ($cards as $card): if (!is_array($card)) continue; ?>
            <div class="card">
                <div class="card-label"><?= htmlspecialchars((string) ($card['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="card-value"><?= htmlspecialchars((string) ($card['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($columns !== []): ?>
        <table>
            <thead><tr><?php foreach ($columns as $col): ?><th><?= htmlspecialchars((string) $col, ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr><?php foreach ((array) $row as $cell): ?><td><?= htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($footer !== []): ?>
                <tfoot><tr><?php foreach ($footer as $cell): ?><td><?= htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') ?></td><?php endforeach; ?></tr></tfoot>
            <?php endif; ?>
        </table>
    <?php endif; ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
    <?php
    exit;
}

http_response_code(422);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'Unsupported export format.'], JSON_UNESCAPED_UNICODE);

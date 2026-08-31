<?php
/**
 * CLI: export Ultimate inbox rows that live is missing (after mid-May 2026).
 * Run: C:\xampp\php\php.exe export_inbox_catchup.php
 *
 * Import the SQL on ultitech.io phpMyAdmin into the Ultimate tenant DB
 * (same DB as module_emails, usually new_trading_voucher-...).
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=new_trading_voucher-35313030c7e2;charset=utf8mb4',
    'root',
    '',
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);

$since = '2026-05-14 23:59:59';
$outPath = 'C:\\xampp\\tmp\\ultimate_inbox_catchup.sql';

$stmt = $pdo->prepare(
    "SELECT user_id, customer_id, message_id, sender_email, recipient_email, subject, body,
            direction, status, is_starred, created_at
     FROM module_emails
     WHERE direction = 'inbound'
       AND created_at > ?
     ORDER BY created_at ASC"
);
$stmt->execute(array($since));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fh = fopen($outPath, 'wb');
if (!$fh) {
    fwrite(STDERR, "Cannot write $outPath\n");
    exit(1);
}

fwrite($fh, "-- Ultimate inbox catch-up for ultitech.io\n");
fwrite($fh, "-- Generated " . gmdate('c') . " from localhost (" . count($rows) . " inbound rows after $since)\n");
fwrite($fh, "-- Import into the Ultimate tenant database that contains module_emails.\n");
fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

foreach ($rows as $row) {
    $mid = trim((string) ($row['message_id'] ?? ''));
    $cols = array(
        'user_id' => 0,
        'customer_id' => $row['customer_id'],
        'message_id' => $row['message_id'],
        'sender_email' => $row['sender_email'],
        'recipient_email' => $row['recipient_email'],
        'subject' => $row['subject'],
        'body' => $row['body'],
        'direction' => $row['direction'],
        'status' => $row['status'],
        'is_starred' => $row['is_starred'],
        'created_at' => $row['created_at'],
    );
    $names = implode(', ', array_keys($cols));
    $vals = array();
    foreach ($cols as $v) {
        if ($v === null || $v === '') {
            // keep empty strings as quotes; only SQL NULL for actual nulls
        }
        if ($v === null) {
            $vals[] = 'NULL';
        } else {
            $vals[] = $pdo->quote((string) $v);
        }
    }
    $valueSql = implode(', ', $vals);
    if ($mid !== '') {
        fwrite(
            $fh,
            "INSERT INTO module_emails ($names)\nSELECT $valueSql FROM DUAL\n"
            . "WHERE NOT EXISTS (SELECT 1 FROM module_emails e WHERE e.message_id = " . $pdo->quote($mid) . " LIMIT 1);\n\n"
        );
    } else {
        fwrite($fh, "INSERT INTO module_emails ($names) VALUES ($valueSql);\n\n");
    }
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

echo "Wrote " . count($rows) . " statements to $outPath (" . round(filesize($outPath) / 1048576, 2) . " MB)\n";

<?php
// CLI sanity check for signaling storage/retrieval
if (php_sapi_name() !== 'cli') { http_response_code(400); echo "CLI only"; exit; }
require_once __DIR__ . '/includes/functions.php';

$out = [ 'ok' => false, 'error' => null ];

try {
    // Pick a user
    $u = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 LIMIT 1")->fetch();
    if (!$u) { $u = $pdo->query("SELECT id, full_name FROM users LIMIT 1")->fetch(); }
    if (!$u) throw new Exception('No users found');
    $uid = (int)$u['id'];

    // Ensure a meeting exists
    $m = $pdo->query("SELECT id FROM meetings ORDER BY id DESC LIMIT 1")->fetch();
    if (!$m) { $m = createMeeting('CLI Signal Test', $uid, null); $mid = (int)$m['id']; } else { $mid = (int)$m['id']; }

    // Insert a loopback signal (to self)
    $stmt = $pdo->prepare("INSERT INTO meeting_signals (meeting_id, from_user_id, to_user_id, signal_type, signal_data) VALUES (?,?,?,?,?)");
    $payload = ['test' => true, 'note' => 'loopback'];
    $stmt->execute([$mid, $uid, $uid, 'offer', json_encode($payload)]);
    $insertId = (int)$pdo->lastInsertId();

    // Fetch back like the endpoint would
    $stmt = $pdo->prepare("SELECT s.*, u.full_name as from_name FROM meeting_signals s JOIN users u ON s.from_user_id = u.id WHERE s.meeting_id = ? AND s.to_user_id = ? AND s.id > ? ORDER BY s.id ASC");
    $stmt->execute([$mid, $uid, $insertId - 1]);
    $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($signals as &$s) { $s['signal_data'] = json_decode($s['signal_data'], true); }

    $out['ok'] = true;
    $out['meeting_id'] = $mid;
    $out['user_id'] = $uid;
    $out['insert_id'] = $insertId;
    $out['fetched'] = $signals;
} catch (Throwable $e) {
    $out['ok'] = false; $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT), "\n";

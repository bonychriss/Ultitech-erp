<?php
// CLI smoke test: create a meeting and join with an existing user
if (php_sapi_name() !== 'cli') { http_response_code(400); echo "CLI only"; exit; }
require_once __DIR__ . '/includes/functions.php';

$out = [ 'ok' => false, 'error' => null ];

try {
    // Pick an active user
    $stmt = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 LIMIT 1");
    $u = $stmt->fetch();
    if (!$u) {
        $u = $pdo->query("SELECT id, full_name FROM users LIMIT 1")->fetch();
    }
    if (!$u) { throw new Exception('No users found'); }

    $userId = (int)$u['id'];
    $meeting = createMeeting('CLI Test Meeting', $userId, null);
    $meetingId = (int)$meeting['id'];

    $joined = joinMeeting($meetingId, $userId);
    $parts = getMeetingParticipants($meetingId);

    $out['ok'] = true;
    $out['meeting'] = [ 'id' => $meetingId, 'code' => $meeting['code'] ];
    $out['user'] = [ 'id' => $userId, 'name' => $u['full_name'] ?? '' ];
    $out['joined'] = (bool)$joined;
    $out['participants'] = $parts;
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT), "\n";

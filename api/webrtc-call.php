<?php
/**
 * 1:1 WebRTC call signaling + presence (company-scoped).
 *
 * POST JSON actions:
 *   heartbeat | invite | accept | reject | hangup | send_signal | poll
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$companyId = (int) ($_SESSION['company_id'] ?? 0);
if ($companyId <= 0 && function_exists('currentCompanyId')) {
    $companyId = (int) currentCompanyId();
}

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if (!$data && !empty($_POST)) {
    $data = $_POST;
}

$action = trim((string) ($data['action'] ?? $_GET['action'] ?? ''));

function webrtc_call_db(): PDO
{
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (function_exists('erp_data_pdo')) {
        $db = erp_data_pdo();
        if ($db instanceof PDO) {
            return $db;
        }
    }
    throw new RuntimeException('Database unavailable');
}

function webrtc_call_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS webrtc_presence (
        user_id INT NOT NULL PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        last_seen DATETIME NOT NULL,
        KEY idx_webrtc_presence_company_seen (company_id, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS webrtc_calls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        caller_id INT NOT NULL,
        callee_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ringing',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_webrtc_calls_callee_status (callee_id, status),
        KEY idx_webrtc_calls_caller_status (caller_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS webrtc_call_signals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        call_id INT NOT NULL,
        from_user_id INT NOT NULL,
        to_user_id INT NOT NULL,
        signal_type VARCHAR(20) NOT NULL,
        signal_data LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_webrtc_signals_poll (call_id, to_user_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function webrtc_call_now(): string
{
    return date('Y-m-d H:i:s');
}

function webrtc_call_user_label(PDO $db, int $id): string
{
    try {
        $stmt = $db->prepare('SELECT full_name, username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $full = trim((string) ($row['full_name'] ?? ''));
        if ($full !== '') {
            $parts = preg_split('/\s+/', $full) ?: [];
            return trim((string) ($parts[0] ?? $full));
        }
        return trim((string) ($row['username'] ?? ('User #' . $id)));
    } catch (Throwable $e) {
        return 'User #' . $id;
    }
}

function webrtc_call_fetch(PDO $db, int $callId): ?array
{
    $stmt = $db->prepare('SELECT * FROM webrtc_calls WHERE id = ? LIMIT 1');
    $stmt->execute([$callId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function webrtc_call_participant(array $call, int $userId): bool
{
    return (int) $call['caller_id'] === $userId || (int) $call['callee_id'] === $userId;
}

try {
    $db = webrtc_call_db();
    webrtc_call_ensure_schema($db);

    if ($action === 'heartbeat') {
        $stmt = $db->prepare(
            'INSERT INTO webrtc_presence (user_id, company_id, last_seen)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), last_seen = VALUES(last_seen)'
        );
        $stmt->execute([$userId, $companyId, webrtc_call_now()]);

        // Expire stale ringing calls (> 60s)
        $db->exec(
            "UPDATE webrtc_calls
             SET status = 'missed', updated_at = NOW()
             WHERE status = 'ringing' AND created_at < (NOW() - INTERVAL 60 SECOND)"
        );

        echo json_encode(['ok' => true, 'server_time' => webrtc_call_now()]);
        exit;
    }

    if ($action === 'invite') {
        $calleeId = (int) ($data['callee_id'] ?? 0);
        if ($calleeId <= 0 || $calleeId === $userId) {
            throw new RuntimeException('Select a valid teammate to call.');
        }

        $preserveActive = !empty($data['preserve_active']);

        // Must be same company when users.company_id exists and session has a company
        if ($companyId > 0) {
            static $usersHasCompanyId = null;
            if ($usersHasCompanyId === null) {
                $usersHasCompanyId = false;
                try {
                    $colRows = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    $usersHasCompanyId = in_array('company_id', $colRows, true);
                } catch (Throwable $e) {
                    $usersHasCompanyId = false;
                }
            }
            if ($usersHasCompanyId) {
                $chk = $db->prepare('SELECT id, company_id FROM users WHERE id = ? LIMIT 1');
                $chk->execute([$calleeId]);
                $peer = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$peer) {
                    throw new RuntimeException('User not found.');
                }
                $peerCompany = (int) ($peer['company_id'] ?? 0);
                if ($peerCompany > 0 && $peerCompany !== $companyId) {
                    throw new RuntimeException('That user is not in your organization.');
                }
            } else {
                $chk = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                $chk->execute([$calleeId]);
                if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('User not found.');
                }
            }
        } else {
            $chk = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $chk->execute([$calleeId]);
            if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('User not found.');
            }
        }

        $now = webrtc_call_now();
        if (!$preserveActive) {
            // End any open calls involving this user
            $end = $db->prepare(
                "UPDATE webrtc_calls
                 SET status = 'ended', updated_at = ?
                 WHERE status IN ('ringing','active')
                   AND (caller_id = ? OR callee_id = ? OR caller_id = ? OR callee_id = ?)"
            );
            $end->execute([$now, $userId, $userId, $calleeId, $calleeId]);
        } else {
            // Only clear ringing leftovers with this callee; keep other active legs
            $end = $db->prepare(
                "UPDATE webrtc_calls
                 SET status = 'ended', updated_at = ?
                 WHERE status = 'ringing'
                   AND ((caller_id = ? AND callee_id = ?) OR (caller_id = ? AND callee_id = ?))"
            );
            $end->execute([$now, $userId, $calleeId, $calleeId, $userId]);
        }

        $ins = $db->prepare(
            'INSERT INTO webrtc_calls (company_id, caller_id, callee_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$companyId, $userId, $calleeId, 'ringing', $now, $now]);
        $callId = (int) $db->lastInsertId();

        echo json_encode([
            'ok' => true,
            'call' => [
                'id' => $callId,
                'status' => 'ringing',
                'role' => 'caller',
                'peer_id' => $calleeId,
                'peer_name' => webrtc_call_user_label($db, $calleeId),
            ],
        ]);
        exit;
    }

    if ($action === 'accept' || $action === 'reject' || $action === 'hangup') {
        $callId = (int) ($data['call_id'] ?? 0);
        $call = webrtc_call_fetch($db, $callId);
        if (!$call || !webrtc_call_participant($call, $userId)) {
            throw new RuntimeException('Call not found.');
        }

        $now = webrtc_call_now();
        if ($action === 'accept') {
            if ((int) $call['callee_id'] !== $userId) {
                throw new RuntimeException('Only the callee can accept.');
            }
            if ($call['status'] !== 'ringing') {
                throw new RuntimeException('Call is no longer ringing.');
            }
            $upd = $db->prepare("UPDATE webrtc_calls SET status = 'active', updated_at = ? WHERE id = ?");
            $upd->execute([$now, $callId]);
            $status = 'active';
        } elseif ($action === 'reject') {
            if ((int) $call['callee_id'] !== $userId) {
                throw new RuntimeException('Only the callee can reject.');
            }
            $upd = $db->prepare("UPDATE webrtc_calls SET status = 'rejected', updated_at = ? WHERE id = ?");
            $upd->execute([$now, $callId]);
            $status = 'rejected';
            $peerId = (int) $call['caller_id'];
            $sig = $db->prepare(
                'INSERT INTO webrtc_call_signals (call_id, from_user_id, to_user_id, signal_type, signal_data, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $sig->execute([$callId, $userId, $peerId, 'hangup', json_encode(['reason' => 'rejected']), $now]);
        } else {
            $upd = $db->prepare("UPDATE webrtc_calls SET status = 'ended', updated_at = ? WHERE id = ? AND status IN ('ringing','active')");
            $upd->execute([$now, $callId]);
            $status = 'ended';
            // Notify peer via hangup signal
            $peerId = (int) $call['caller_id'] === $userId ? (int) $call['callee_id'] : (int) $call['caller_id'];
            $sig = $db->prepare(
                'INSERT INTO webrtc_call_signals (call_id, from_user_id, to_user_id, signal_type, signal_data, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $sig->execute([$callId, $userId, $peerId, 'hangup', json_encode(['reason' => 'hangup']), $now]);
        }

        echo json_encode(['ok' => true, 'call_id' => $callId, 'status' => $status]);
        exit;
    }

    if ($action === 'send_signal') {
        $callId = (int) ($data['call_id'] ?? 0);
        $toUserId = (int) ($data['to_user_id'] ?? 0);
        $signalType = trim((string) ($data['signal_type'] ?? ''));
        $signalData = $data['signal_data'] ?? null;
        if (!in_array($signalType, ['offer', 'answer', 'ice', 'hangup'], true)) {
            throw new RuntimeException('Invalid signal type.');
        }
        $call = webrtc_call_fetch($db, $callId);
        if (!$call || !webrtc_call_participant($call, $userId)) {
            throw new RuntimeException('Call not found.');
        }
        if ($toUserId <= 0 || !webrtc_call_participant($call, $toUserId)) {
            throw new RuntimeException('Invalid peer.');
        }
        $ins = $db->prepare(
            'INSERT INTO webrtc_call_signals (call_id, from_user_id, to_user_id, signal_type, signal_data, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $callId,
            $userId,
            $toUserId,
            $signalType,
            json_encode($signalData),
            webrtc_call_now(),
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'poll') {
        $sinceSignalId = (int) ($data['since_signal_id'] ?? 0);
        $callId = (int) ($data['call_id'] ?? 0);
        $callIds = [];
        if (!empty($data['call_ids']) && is_array($data['call_ids'])) {
            foreach ($data['call_ids'] as $cid) {
                $cid = (int) $cid;
                if ($cid > 0) {
                    $callIds[] = $cid;
                }
            }
        }
        if ($callId > 0 && !in_array($callId, $callIds, true)) {
            $callIds[] = $callId;
        }
        $sinceMap = [];
        if (!empty($data['since_map']) && is_array($data['since_map'])) {
            foreach ($data['since_map'] as $k => $v) {
                $sinceMap[(int) $k] = (int) $v;
            }
        }

        // Incoming ringing for me (exclude already-ended noise by only ringing)
        $incoming = null;
        $inq = $db->prepare(
            "SELECT * FROM webrtc_calls
             WHERE callee_id = ? AND status = 'ringing'
             ORDER BY id DESC LIMIT 1"
        );
        $inq->execute([$userId]);
        $incomingRow = $inq->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($incomingRow) {
            $incoming = [
                'id' => (int) $incomingRow['id'],
                'status' => $incomingRow['status'],
                'role' => 'callee',
                'peer_id' => (int) $incomingRow['caller_id'],
                'peer_name' => webrtc_call_user_label($db, (int) $incomingRow['caller_id']),
            ];
        }

        $calls = [];
        foreach ($callIds as $cid) {
            $row = webrtc_call_fetch($db, $cid);
            if ($row && webrtc_call_participant($row, $userId)) {
                $peerId = (int) $row['caller_id'] === $userId ? (int) $row['callee_id'] : (int) $row['caller_id'];
                $calls[] = [
                    'id' => (int) $row['id'],
                    'status' => $row['status'],
                    'role' => (int) $row['caller_id'] === $userId ? 'caller' : 'callee',
                    'peer_id' => $peerId,
                    'peer_name' => webrtc_call_user_label($db, $peerId),
                ];
            }
        }

        $signals = [];
        foreach ($callIds as $cid) {
            $since = $sinceMap[$cid] ?? ($cid === $callId ? $sinceSignalId : 0);
            $sq = $db->prepare(
                'SELECT id, call_id, from_user_id, to_user_id, signal_type, signal_data, created_at
                 FROM webrtc_call_signals
                 WHERE call_id = ? AND to_user_id = ? AND id > ?
                 ORDER BY id ASC
                 LIMIT 100'
            );
            $sq->execute([$cid, $userId, $since]);
            foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $signals[] = [
                    'id' => (int) $s['id'],
                    'call_id' => (int) $s['call_id'],
                    'from_user_id' => (int) $s['from_user_id'],
                    'signal_type' => $s['signal_type'],
                    'signal_data' => json_decode((string) $s['signal_data'], true),
                    'created_at' => $s['created_at'],
                ];
            }
        }

        echo json_encode([
            'ok' => true,
            'incoming' => $incoming,
            'call' => $calls[0] ?? null,
            'calls' => $calls,
            'signals' => $signals,
        ]);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

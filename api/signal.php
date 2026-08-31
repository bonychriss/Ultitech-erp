<?php
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$action = $data['action'] ?? '';

if ($action === 'send') {
    // Send a signal to another peer
    $meeting_id = (int)($data['meeting_id'] ?? 0);
    $to_user_id = (int)($data['to_user_id'] ?? 0);
    $signal_type = $data['signal_type'] ?? '';
    $signal_data = $data['signal_data'] ?? '';
    
    if ($meeting_id > 0 && $to_user_id > 0 && in_array($signal_type, ['offer', 'answer', 'ice'])) {
        global $pdo;
        $stmt = $pdo->prepare("
            INSERT INTO meeting_signals (meeting_id, from_user_id, to_user_id, signal_type, signal_data)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$meeting_id, $user_id, $to_user_id, $signal_type, json_encode($signal_data)]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    }
    
} elseif ($action === 'receive') {
    // Receive signals for current user
    $meeting_id = (int)($data['meeting_id'] ?? 0);
    $since_id = (int)($data['since_id'] ?? 0);
    
    if ($meeting_id > 0) {
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT s.*, u.full_name as from_name
            FROM meeting_signals s
            JOIN users u ON s.from_user_id = u.id
            WHERE s.meeting_id = ? AND s.to_user_id = ? AND s.id > ?
            ORDER BY s.id ASC
        ");
        $stmt->execute([$meeting_id, $user_id, $since_id]);
        $signals = $stmt->fetchAll();
        
        // Decode signal_data
        foreach ($signals as &$signal) {
            $signal['signal_data'] = json_decode($signal['signal_data'], true);
        }
        
        echo json_encode(['success' => true, 'signals' => $signals]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid meeting ID']);
    }
    
} elseif ($action === 'cleanup') {
    // Clean up old signals (older than 1 hour)
    global $pdo;
    $stmt = $pdo->prepare("
        DELETE FROM meeting_signals 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>

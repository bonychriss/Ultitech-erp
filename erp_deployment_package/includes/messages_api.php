<?php
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json');

try {
    requireLogin();
    ensureMessagesSchema();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

    $groupId = ensureGlobalGroupAndMembership($uid);
    $action = $_GET['action'] ?? $_POST['action'] ?? 'fetch';
    global $pdo;

    // Handle different actions
    if ($action === 'edit_message') {
        $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        $newMessage = trim((string)($_POST['message'] ?? ''));
        
        if ($messageId <= 0 || $newMessage === '') {
            echo json_encode(['ok'=>false,'error'=>'Invalid parameters']);
            exit;
        }
        
        // Check if user owns the message
        $check = $pdo->prepare('SELECT sender_id FROM messages WHERE id = ?');
        $check->execute([$messageId]);
        $msg = $check->fetch();
        
        if (!$msg || (int)$msg['sender_id'] !== $uid) {
            echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
            exit;
        }
        
        // Update message
        $stmt = $pdo->prepare('UPDATE messages SET message = ?, edited_message = ?, edited_at = NOW() WHERE id = ?');
        $stmt->execute([$newMessage, $newMessage, $messageId]);
        
        echo json_encode(['ok'=>true]);
        exit;
    }
    
    if ($action === 'delete_message') {
        $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        
        if ($messageId <= 0) {
            echo json_encode(['ok'=>false,'error'=>'Invalid message ID']);
            exit;
        }
        
        // Check if user owns the message
        $check = $pdo->prepare('SELECT sender_id FROM messages WHERE id = ?');
        $check->execute([$messageId]);
        $msg = $check->fetch();
        
        if (!$msg || (int)$msg['sender_id'] !== $uid) {
            echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
            exit;
        }
        
        // Soft delete
        $stmt = $pdo->prepare('UPDATE messages SET is_deleted = 1, message = "[Message deleted]", edited_message = message WHERE id = ?');
        $stmt->execute([$messageId]);
        
        echo json_encode(['ok'=>true]);
        exit;
    }
    
    if ($action === 'add_reaction') {
        $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        $reaction = isset($_POST['reaction']) ? trim((string)$_POST['reaction']) : '';
        
        if ($messageId <= 0 || $reaction === '') {
            echo json_encode(['ok'=>false,'error'=>'Invalid parameters']);
            exit;
        }
        
        // Toggle reaction (if exists, remove; if not, add)
        $check = $pdo->prepare('SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ?');
        $check->execute([$messageId, $uid, $reaction]);
        $existing = $check->fetch();
        
        if ($existing) {
            // Remove reaction
            $del = $pdo->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ?');
            $del->execute([$messageId, $uid, $reaction]);
        } else {
            // Add reaction
            $ins = $pdo->prepare('INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (?, ?, ?)');
            $ins->execute([$messageId, $uid, $reaction]);
        }
        
        echo json_encode(['ok'=>true]);
        exit;
    }
    
    if ($action === 'get_reactions') {
        $messageId = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
        
        if ($messageId <= 0) {
            echo json_encode(['ok'=>false,'error'=>'Invalid message ID']);
            exit;
        }
        
        $stmt = $pdo->prepare('SELECT mr.reaction, mr.user_id, u.full_name FROM message_reactions mr JOIN users u ON u.id = mr.user_id WHERE mr.message_id = ?');
        $stmt->execute([$messageId]);
        $reactions = $stmt->fetchAll();
        
        echo json_encode(['ok'=>true, 'reactions'=>$reactions]);
        exit;
    }
    
    if ($action === 'pin_message') {
        $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        
        if ($messageId <= 0) {
            echo json_encode(['ok'=>false,'error'=>'Invalid message ID']);
            exit;
        }
        
        // Check if already pinned
        $check = $pdo->prepare('SELECT id FROM pinned_messages WHERE message_id = ?');
        $check->execute([$messageId]);
        $existing = $check->fetch();
        
        if ($existing) {
            // Unpin
            $del = $pdo->prepare('DELETE FROM pinned_messages WHERE message_id = ?');
            $del->execute([$messageId]);
            echo json_encode(['ok'=>true, 'pinned'=>false]);
        } else {
            // Pin
            $ins = $pdo->prepare('INSERT INTO pinned_messages (message_id, group_id, pinned_by) VALUES (?, ?, ?)');
            $ins->execute([$messageId, $groupId, $uid]);
            echo json_encode(['ok'=>true, 'pinned'=>true]);
        }
        exit;
    }
    
    if ($action === 'get_pinned') {
        $stmt = $pdo->prepare('SELECT pm.message_id, m.message, m.created_at, u.full_name 
                               FROM pinned_messages pm
                               JOIN messages m ON m.id = pm.message_id
                               JOIN users u ON u.id = m.sender_id
                               WHERE pm.group_id = ?
                               ORDER BY pm.pinned_at DESC');
        $stmt->execute([$groupId]);
        $pinned = $stmt->fetchAll();
        
        echo json_encode(['ok'=>true, 'messages'=>$pinned]);
        exit;
    }
    
    if ($action === 'typing') {
        $typing = isset($_POST['typing']) ? (int)$_POST['typing'] : 0;
        
        if ($typing === 1) {
            // Update typing indicator
            $stmt = $pdo->prepare('INSERT INTO typing_indicators (user_id, group_id, last_typed_at) VALUES (?, ?, NOW()) 
                                   ON DUPLICATE KEY UPDATE last_typed_at = NOW()');
            $stmt->execute([$uid, $groupId]);
        } else {
            // Clear typing indicator (delete or set old timestamp)
            $stmt = $pdo->prepare('DELETE FROM typing_indicators WHERE user_id = ? AND group_id = ?');
            $stmt->execute([$uid, $groupId]);
        }
        
        echo json_encode(['ok'=>true]);
        exit;
    }
    
    if ($action === 'get_typing') {
        // Get users currently typing (within last 3 seconds)
        $stmt = $pdo->prepare('SELECT DISTINCT ti.user_id, u.full_name 
                               FROM typing_indicators ti
                               JOIN users u ON u.id = ti.user_id
                               WHERE ti.group_id = ? AND ti.user_id != ? AND ti.last_typed_at > DATE_SUB(NOW(), INTERVAL 3 SECOND)');
        $stmt->execute([$groupId, $uid]);
        $typing = $stmt->fetchAll();
        
        echo json_encode(['ok'=>true, 'typing'=>$typing]);
        exit;
    }

    // Default: fetch messages
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

    // fetch new messages since last id for the global group
    if ($since > 0) {
        $stmt = $pdo->prepare('SELECT m.id, m.sender_id, m.message, m.created_at, m.reply_to_id, m.edited_at, m.is_deleted, u.full_name,
                                      r.message AS reply_message, ru.full_name AS reply_full_name
                               FROM messages m
                               JOIN users u ON u.id = m.sender_id
                               LEFT JOIN messages r ON r.id = m.reply_to_id
                               LEFT JOIN users ru ON ru.id = r.sender_id
                               WHERE m.group_id = ? AND m.id > ? AND (m.is_deleted = 0 OR m.sender_id = ?)
                               ORDER BY m.created_at ASC, m.id ASC');
        $stmt->execute([$groupId, $since, $uid]);
    } else {
        $stmt = $pdo->prepare('SELECT m.id, m.sender_id, m.message, m.created_at, m.reply_to_id, m.edited_at, m.is_deleted, u.full_name,
                                      r.message AS reply_message, ru.full_name AS reply_full_name
                               FROM messages m
                               JOIN users u ON u.id = m.sender_id
                               LEFT JOIN messages r ON r.id = m.reply_to_id
                               LEFT JOIN users ru ON ru.id = r.sender_id
                               WHERE m.group_id = ? AND (m.is_deleted = 0 OR m.sender_id = ?)
                               ORDER BY m.created_at ASC, m.id ASC');
        $stmt->execute([$groupId, $uid]);
    }
    $rows = $stmt->fetchAll();

    $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
    $attachments = [];
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $aStmt = $pdo->prepare("SELECT id, message_id, file_path, file_name, mime_type, size_bytes FROM message_attachments WHERE message_id IN ($in) ORDER BY id ASC");
        $aStmt->execute($ids);
        foreach ($aStmt->fetchAll() as $a) {
            $mid = (int)$a['message_id'];
            if (!isset($attachments[$mid])) $attachments[$mid] = [];
            $attachments[$mid][] = [
                'id' => (int)$a['id'],
                'file_path' => (string)$a['file_path'],
                'file_name' => (string)$a['file_name'],
                'mime_type' => (string)$a['mime_type'],
                'size_bytes' => (int)$a['size_bytes'],
            ];
        }
    }

    $out = [];
    $lastId = $since;
    $messageIds = [];
    
    foreach ($rows as $r) {
        $mid = (int)$r['id'];
        $messageIds[] = $mid;
        if ($mid > $lastId) $lastId = $mid;
        
        // Get reactions for this message
        $reactStmt = $pdo->prepare('SELECT reaction, user_id FROM message_reactions WHERE message_id = ?');
        $reactStmt->execute([$mid]);
        $reactions = $reactStmt->fetchAll();
        
        // Get read receipts for this message (if user is sender)
        $reads = [];
        if ((int)$r['sender_id'] === $uid) {
            $readStmt = $pdo->prepare('SELECT user_id FROM message_reads WHERE message_id = ?');
            $readStmt->execute([$mid]);
            $reads = $readStmt->fetchAll();
        }
        
        // Mark message as read for current user
        try {
            $readIns = $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (?, ?)');
            $readIns->execute([$mid, $uid]);
        } catch (Exception $e) { /* ignore */ }
        
        $out[] = [
            'id' => $mid,
            'sender_id' => (int)$r['sender_id'],
            'full_name' => (string)$r['full_name'],
            'message' => (string)$r['message'],
            'created_at' => (string)$r['created_at'],
            'edited_at' => isset($r['edited_at']) && $r['edited_at'] ? (string)$r['edited_at'] : null,
            'is_deleted' => isset($r['is_deleted']) ? (int)$r['is_deleted'] : 0,
            'reply_to_id' => isset($r['reply_to_id']) ? (int)$r['reply_to_id'] : null,
            'reply' => isset($r['reply_message']) ? [
                'full_name' => (string)$r['reply_full_name'],
                'message' => (string)$r['reply_message'],
            ] : null,
            'attachments' => $attachments[$mid] ?? [],
            'reactions' => $reactions,
            'read_by' => array_map(function($r) { return (int)$r['user_id']; }, $reads),
        ];
    }

    // mark as read
    updateGroupLastRead($groupId, $uid);
    
    // Get typing indicators
    $typingStmt = $pdo->prepare('SELECT DISTINCT ti.user_id, u.full_name 
                                 FROM typing_indicators ti
                                 JOIN users u ON u.id = ti.user_id
                                 WHERE ti.group_id = ? AND ti.user_id != ? AND ti.last_typed_at > DATE_SUB(NOW(), INTERVAL 3 SECOND)');
    $typingStmt->execute([$groupId, $uid]);
    $typing = $typingStmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'messages' => $out,
        'lastId' => (int)$lastId,
        'serverTime' => date('c'),
        'currentUserId' => $uid,
        'typing' => $typing,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

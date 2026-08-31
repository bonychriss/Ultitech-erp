<?php
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$meeting_id = (int)($_GET['meeting_id'] ?? $_POST['meeting_id'] ?? 0);
$user_id = $_SESSION['user_id'];

try {
    if ($action === 'get_messages') {
        // Get chat messages for a meeting
        $messages = getMeetingChatMessages($meeting_id);
        echo json_encode(['success' => true, 'messages' => $messages]);
        
    } elseif ($action === 'send_message') {
        // Send a chat message
        $message = trim($_POST['message'] ?? '');
        
        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
            exit;
        }
        
        if (strlen($message) > 1000) {
            echo json_encode(['success' => false, 'error' => 'Message too long (max 1000 characters)']);
            exit;
        }
        
        $message_id = sendMeetingChatMessage($meeting_id, $user_id, $message);
        
        // Get the full message data with user info
        $stmt = $pdo->prepare("SELECT mcm.*, u.full_name 
            FROM meeting_chat_messages mcm
            JOIN users u ON mcm.user_id = u.id
            WHERE mcm.id = ?");
        $stmt->execute([$message_id]);
        $messageData = $stmt->fetch();
        
        echo json_encode(['success' => true, 'message' => $messageData]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Meeting chat API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred']);
}

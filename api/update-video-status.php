<?php
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$meeting_id = isset($data['meeting_id']) ? (int)$data['meeting_id'] : 0;
$is_video_on = isset($data['is_video_on']) ? (bool)$data['is_video_on'] : false;

if ($meeting_id > 0) {
    try {
        global $pdo;
        $stmt = $pdo->prepare("
            UPDATE meeting_participants 
            SET is_video_on = ? 
            WHERE meeting_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->execute([$is_video_on ? 1 : 0, $meeting_id, $user_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid meeting ID']);
}
?>

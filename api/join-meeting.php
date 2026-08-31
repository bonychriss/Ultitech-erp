<?php
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$meeting_id = isset($data['meeting_id']) ? (int)$data['meeting_id'] : 0;
$peer_id = isset($data['peer_id']) ? trim($data['peer_id']) : null;

if ($meeting_id > 0 && $peer_id) {
    // Update the user's peer_id in the meeting
    if (joinMeeting($meeting_id, $user_id, $peer_id)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to join meeting']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
}
?>

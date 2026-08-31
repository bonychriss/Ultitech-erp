<?php
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$meeting_id = isset($data['meeting_id']) ? (int)$data['meeting_id'] : 0;

if ($meeting_id > 0) {
    $success = leaveMeeting($meeting_id, $user_id);
    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid meeting ID']);
}
?>

<?php
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$meeting_id = isset($_GET['meeting_id']) ? (int)$_GET['meeting_id'] : 0;

if ($meeting_id > 0) {
    $participants = getMeetingParticipants($meeting_id);
    echo json_encode($participants);
} else {
    echo json_encode([]);
}
?>

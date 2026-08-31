<?php
// upload_recording.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = file_get_contents('php://input');
    $filename = 'recordings/meeting_' . ($_GET['room'] ?? 'default') . '.webm';
    
    if (!is_dir('recordings')) {
        mkdir('recordings', 0777, true);
    }

    file_put_contents($filename, $data, FILE_APPEND);
    echo "Chunk received";
}

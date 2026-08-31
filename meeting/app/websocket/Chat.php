<?php
namespace App\Websocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Chat implements MessageComponentInterface {
    protected $clients;
    protected $rooms; // Array to store clients per room

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->rooms = [];
        echo "WebSocket Server Started!\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg);
        
        if (!isset($data->type)) {
            return;
        }

        switch ($data->type) {
            case 'join':
                $room = $data->room;
                $this->rooms[$room][$from->resourceId] = $from;
                $from->room = $room; // Attach room to connection object
                echo "User {$from->resourceId} joined room {$room}\n";
                
                // Notify others in the room
                $this->broadcastToRoom($room, [
                    'type' => 'user-joined',
                    'userId' => $from->resourceId
                ], $from);
                break;

            case 'offer':
            case 'answer':
            case 'candidate':
                // Relay signaling data to specific target
                if (isset($data->target) && isset($this->rooms[$from->room][$data->target])) {
                    $targetConn = $this->rooms[$from->room][$data->target];
                    $data->sender = $from->resourceId;
                    $targetConn->send(json_encode($data));
                }
                break;
            
            case 'chat':
                 if (isset($from->room)) {
                    $this->broadcastToRoom($from->room, [
                        'type' => 'chat',
                        'sender' => $from->resourceId,
                        'message' => $data->message
                    ], $from); // Optionally exclude sender if handled locally
                 }
                 break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);
        
        if (isset($conn->room) && isset($this->rooms[$conn->room])) {
            unset($this->rooms[$conn->room][$conn->resourceId]);
            if (empty($this->rooms[$conn->room])) {
                unset($this->rooms[$conn->room]);
            } else {
                 // Notify others
                $this->broadcastToRoom($conn->room, [
                    'type' => 'user-left',
                    'userId' => $conn->resourceId
                ]);
            }
        }

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    protected function broadcastToRoom($room, $msg, $exclude = null) {
        if (!isset($this->rooms[$room])) return;
        
        foreach ($this->rooms[$room] as $client) {
            if ($exclude && $client === $exclude) continue;
            $client->send(json_encode($msg));
        }
    }
}

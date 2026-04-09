<?php
// app/Events/MessageRead.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $conversation_id;
    public $user_id;

    public function __construct($conversation_id, $user_id)
    {
        $this->conversation_id = $conversation_id;
        $this->user_id = $user_id;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('chat.' . $this->conversation_id);
    }

    public function broadcastWith()
    {
        return [
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id
        ];
    }
}
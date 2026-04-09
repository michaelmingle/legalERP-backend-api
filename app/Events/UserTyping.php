<?php
// app/Events/UserTyping.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $conversation_id;
    public $user_id;
    public $is_typing;

    public function __construct($conversation_id, $user_id, $is_typing)
    {
        $this->conversation_id = $conversation_id;
        $this->user_id = $user_id;
        $this->is_typing = $is_typing;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('chat.' . $this->conversation_id);
    }

    public function broadcastWith()
    {
        return [
            'user_id' => $this->user_id,
            'is_typing' => $this->is_typing
        ];
    }
}
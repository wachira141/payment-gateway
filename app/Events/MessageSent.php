<?php

namespace App\Events;

use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $conversation;

    public function __construct(Message $message, Conversation $conversation)
    {
        $this->message = $message;
        $this->conversation = $conversation;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->message->recipient_id),
            new PrivateChannel('user.' . $this->message->sender_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'sender_id' => $this->message->sender_id,
                'recipient_id' => $this->message->recipient_id,
                'content' => $this->message->content,
                'created_at' => $this->message->created_at->toISOString(),
                'read_at' => $this->message->read_at?->toISOString(),
                'sender' => [
                    'id' => $this->message->sender->id,
                    'name' => $this->message->sender->name,
                    'avatar_url' => $this->message->sender->avatar_url,
                ],
            ],
            'conversation' => [
                'id' => $this->conversation->id,
                'participant_id' => $this->conversation->participant_2_id,
                'participant_name' => $this->conversation->participant2->name,
                'participant_avatar_url' => $this->conversation->participant2->avatar_url,
                'last_message' => $this->message->content,
                'last_message_time' => $this->message->created_at->toISOString(),
                'unread_count' => $this->conversation->getUnreadCountFor($this->message->recipient_id),
            ],
        ];
    }
}
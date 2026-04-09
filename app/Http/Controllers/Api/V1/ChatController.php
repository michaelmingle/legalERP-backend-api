<?php
// app/Http/Controllers/ChatController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\NewMessage;
use App\Events\UserTyping;
use App\Events\MessageRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    public function getConversations()
    {
        $user = Auth::user();
        
        $conversations = Conversation::where('client_id', $user->id)
            ->orWhere('lawyer_id', $user->id)
            ->with(['client', 'lawyer', 'lastMessage.sender'])
            ->get()
            ->map(function ($conversation) use ($user) {
                $otherParticipant = $conversation->client_id === $user->id 
                    ? $conversation->lawyer 
                    : $conversation->client;
                
                $unreadCount = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', '!=', $user->id)
                    ->whereNull('read_at')
                    ->count();
                
                return [
                    'id' => $conversation->id,
                    'other_participant' => [
                        'id' => $otherParticipant->id,
                        'name' => $otherParticipant->name,
                        'email' => $otherParticipant->email,
                        'avatar' => $otherParticipant->avatar ?? null
                    ],
                    'last_message' => $conversation->lastMessage,
                    'unread_count' => $unreadCount
                ];
            });
        
        return response()->json($conversations);
    }
    
    public function getMessages($conversationId)
    {
        $user = Auth::user();
        
        $conversation = Conversation::findOrFail($conversationId);
        
        // Check if user is part of conversation
        if ($conversation->client_id !== $user->id && $conversation->lawyer_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $messages = Message::where('conversation_id', $conversationId)
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get();
        
        return response()->json($messages);
    }
    
    public function sendMessage(Request $request, $conversationId)
    {
        $user = Auth::user();
        
        $request->validate([
            'message' => 'required|string'
        ]);
        
        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_id' => $user->id,
            'message' => $request->message
        ]);
        
        $message->load('sender');
        
        // Broadcast to Reverb
        broadcast(new NewMessage($message))->toOthers();
        
        return response()->json($message);
    }
    
    public function markAsRead($conversationId)
    {
        $user = Auth::user();
        
        $updated = Message::where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
        if ($updated) {
            broadcast(new MessageRead($conversationId, $user->id))->toOthers();
        }
        
        return response()->json(['success' => true]);
    }
    
    public function startConversation(Request $request)
    {
        $request->validate([
            'recipient_id' => 'required|exists:users,id'
        ]);
        
        $user = Auth::user();
        
        // Check if conversation already exists
        $existingConversation = Conversation::where(function ($query) use ($user, $request) {
            $query->where('client_id', $user->id)
                  ->where('lawyer_id', $request->recipient_id);
        })->orWhere(function ($query) use ($user, $request) {
            $query->where('client_id', $request->recipient_id)
                  ->where('lawyer_id', $user->id);
        })->first();
        
        if ($existingConversation) {
            return response()->json($existingConversation);
        }
        
        $conversation = Conversation::create([
            'client_id' => $user->role === 'client' ? $user->id : $request->recipient_id,
            'lawyer_id' => $user->role === 'lawyer' ? $user->id : $request->recipient_id
        ]);
        
        return response()->json($conversation);
    }
    
    public function getAvailableUsers()
    {
        $user = Auth::user();
        
        // Get lawyers for clients, or clients for lawyers
        $users = $user->role === 'client' 
            ? \App\Models\User::where('role', 'lawyer')->get()
            : \App\Models\User::where('role', 'client')->get();
        
        return response()->json($users);
    }
    
    public function sendTypingIndicator(Request $request, $conversationId)
    {
        $user = Auth::user();
        
        broadcast(new UserTyping($conversationId, $user->id, $request->is_typing))->toOthers();
        
        return response()->json(['success' => true]);
    }
}
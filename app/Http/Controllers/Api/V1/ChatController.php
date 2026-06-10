<?php
// app/Http/Controllers/Api/V1/ChatController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Events\NewMessage;
use App\Events\UserTyping;
use App\Events\MessageRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    /**
     * Get conversations for the authenticated user (filtered by organization)
     */
    public function getConversations()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            if (!in_array($user->role, ['employee', 'lawyer', 'client'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $conversations = Conversation::where(function($query) use ($user) {
                    $query->where('client_id', $user->id)
                          ->orWhere('lawyer_id', $user->id);
                })
                ->where(function ($q) use ($organizationId) {
                    // Match the user's org, but also surface legacy rows with NULL org_id
                    $q->where('organization_id', $organizationId)
                      ->orWhereNull('organization_id');
                })
                ->with(['client', 'lawyer', 'lastMessage.sender'])
                ->orderBy('updated_at', 'desc')
                ->get()
                ->map(function ($conversation) use ($user) {
                    $otherParticipant = $conversation->client_id === $user->id 
                        ? $conversation->lawyer 
                        : $conversation->client;
                    
                    $unreadCount = Message::where('conversation_id', $conversation->id)
                        ->where('sender_id', '!=', $user->id)
                        ->whereNull('read_at')
                        ->count();
                    
                    $lastMsg = $conversation->lastMessage;
                    
                    return [
                        'id' => $conversation->id,
                        'other_participant' => $otherParticipant ? [
                            'id' => $otherParticipant->id,
                            'name' => ($otherParticipant->first_name ?? '') . ' ' . ($otherParticipant->last_name ?? ''),
                            'first_name' => $otherParticipant->first_name,
                            'last_name' => $otherParticipant->last_name,
                            'email' => $otherParticipant->email,
                            'role' => $otherParticipant->role,
                            'avatar' => $otherParticipant->photo_url ?? null
                        ] : null,
                        'last_message' => $lastMsg ? [
                            'id' => $lastMsg->id,
                            'message' => $lastMsg->message,
                            'created_at' => $lastMsg->created_at,
                            'sender' => $lastMsg->sender ? [
                                'id' => $lastMsg->sender->id,
                                'name' => ($lastMsg->sender->first_name ?? '') . ' ' . ($lastMsg->sender->last_name ?? ''),
                            ] : null,
                        ] : null,
                        'unread_count' => $unreadCount,
                        'created_at' => $conversation->created_at,
                        'updated_at' => $conversation->updated_at,
                    ];
                });
            
            return response()->json($conversations);
            
        } catch (\Exception $e) {
            Log::error('Error in getConversations: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch conversations: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Get messages for a conversation (filtered by organization)
     */
    public function getMessages($conversationId)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;

            if (!in_array($user->role, ['employee', 'lawyer', 'client'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $conversation = Conversation::where('id', $conversationId)
                ->where(function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId)
                      ->orWhereNull('organization_id');
                })
                ->first();

            if (!$conversation) {
                return response()->json(['error' => 'Conversation not found'], 404);
            }

            if ($conversation->client_id !== $user->id && $conversation->lawyer_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Backfill organization_id on legacy rows so downstream queries are clean
            if ($conversation->organization_id === null && $organizationId) {
                $conversation->organization_id = $organizationId;
                $conversation->saveQuietly();
            }
            
            $messages = Message::where('conversation_id', $conversationId)
                ->with('sender')
                ->orderBy('created_at', 'asc')
                ->get();
            
            // Mark messages as read
            Message::where('conversation_id', $conversationId)
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
            
            return response()->json($messages);
            
        } catch (\Exception $e) {
            Log::error('Error in getMessages: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch messages: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Send a message (filtered by organization)
     */
    public function sendMessage(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            if (!in_array($user->role, ['employee', 'lawyer', 'client'])) {
                return response()->json(['error' => 'You do not have permission to send messages'], 403);
            }
            
            $request->validate([
                'message' => 'required|string'
            ]);
            
            $conversation = Conversation::where('id', $conversationId)
                ->where(function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId)
                      ->orWhereNull('organization_id');
                })
                ->first();

            if (!$conversation) {
                return response()->json(['error' => 'Conversation not found'], 404);
            }

            if ($conversation->client_id !== $user->id && $conversation->lawyer_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if ($conversation->organization_id === null && $organizationId) {
                $conversation->organization_id = $organizationId;
                $conversation->saveQuietly();
            }

            $message = Message::create([
                'conversation_id' => $conversationId,
                'sender_id' => $user->id,
                'message' => $request->message,
                'organization_id' => $organizationId,
            ]);
            
            $message->load('sender');
            $conversation->touch();
            
            try {
                broadcast(new NewMessage($message))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Broadcast failed: ' . $e->getMessage());
            }
            
            return response()->json($message);
            
        } catch (\Exception $e) {
            Log::error('Error in sendMessage: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send message: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Mark messages as read (filtered by organization)
     */
   public function markAsRead($id)  // Changed from $conversationId to $id to match route
    {
        try {
            Log::info('markAsRead called with ID: ' . $id);
            
            $user = Auth::user();
            if (!$user) {
                Log::error('No authenticated user');
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            Log::info('User ID: ' . $user->id . ', Role: ' . $user->role);
            
            // Check if user has permission to access chat
            if (!in_array($user->role, ['employee', 'lawyer', 'client'])) {
                Log::error('User role not allowed: ' . $user->role);
                return response()->json(['error' => 'Unauthorized - Invalid role'], 403);
            }
            
            // Find the conversation
            $conversation = Conversation::find($id);
            
            if (!$conversation) {
                Log::error('Conversation not found with ID: ' . $id);
                return response()->json(['error' => 'Conversation not found'], 404);
            }
            
            Log::info('Found conversation: ' . $conversation->id);
            
            // Check if user is part of this conversation
            // Your conversation table has client_id and lawyer_id
            if ($conversation->client_id !== $user->id && $conversation->lawyer_id !== $user->id) {
                Log::error('User ' . $user->id . ' is not part of conversation ' . $id);
                return response()->json(['error' => 'Unauthorized - Not part of conversation'], 403);
            }
            
            // Mark messages as read
            $updatedCount = Message::where('conversation_id', $id)
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
            
            Log::info('Marked ' . $updatedCount . ' messages as read');
            
            // Broadcast the read status (optional - can fail silently)
            try {
                broadcast(new MessageRead($id, $user->id))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Broadcast failed but messages were marked as read: ' . $e->getMessage());
                // Don't return error - this is non-critical
            }
            
            return response()->json([
                'success' => true,
                'messages_marked' => $updatedCount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in markAsRead: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'error' => 'Failed to mark as read: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Start a new conversation (filtered by organization)
     */
    public function startConversation(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            if (!in_array($user->role, ['employee', 'lawyer', 'client'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $request->validate([
                'recipient_id' => 'required|exists:users,id'
            ]);
            
            $recipient = User::where('organization_id', $organizationId)->findOrFail($request->recipient_id);
            
            $existingConversation = Conversation::where(function ($query) use ($user, $recipient) {
                $query->where('client_id', $user->id)
                      ->where('lawyer_id', $recipient->id);
            })->orWhere(function ($query) use ($user, $recipient) {
                $query->where('client_id', $recipient->id)
                      ->where('lawyer_id', $user->id);
            })->first();
            
            if ($existingConversation) {
                return response()->json($existingConversation);
            }
            
            $clientId = null;
            $lawyerId = null;
            
            if ($user->role === 'client') {
                $clientId = $user->id;
                $lawyerId = $recipient->id;
            } elseif ($recipient->role === 'client') {
                $clientId = $recipient->id;
                $lawyerId = $user->id;
            } else {
                $clientId = $user->id;
                $lawyerId = $recipient->id;
            }
            
            $conversation = Conversation::create([
                'client_id' => $clientId,
                'lawyer_id' => $lawyerId,
                'organization_id' => $organizationId,
            ]);
            
            return response()->json($conversation, 201);
            
        } catch (\Exception $e) {
            Log::error('Error in startConversation: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to start conversation: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Get available users for chatting (filtered by organization)
     */
    public function getAvailableUsers()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            if (!in_array($user->role, ['employee', 'lawyer', 'client'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $allOrganizationUsers = User::where('organization_id', $organizationId)
                ->where('id', '!=', $user->id)
                ->where('status', 'active')
                ->get();
            
            $users = collect();
            
            if ($user->role === 'client') {
                $users = $allOrganizationUsers->filter(function($u) {
                    return in_array($u->role, ['lawyer', 'employee']);
                });
            } elseif ($user->role === 'lawyer') {
                $users = $allOrganizationUsers->filter(function($u) {
                    return in_array($u->role, ['client', 'employee']);
                });
            } else {
                $users = $allOrganizationUsers->filter(function($u) {
                    return in_array($u->role, ['client', 'lawyer']);
                });
            }
            
            $formattedUsers = $users->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => ($user->first_name ?? '') . ' ' . ($user->last_name ?? ''),
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $user->photo_url ?? null
                ];
            })->values();
            
            return response()->json($formattedUsers);
            
        } catch (\Exception $e) {
            Log::error('Error in getAvailableUsers: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch users: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Send typing indicator
     */
    public function sendTypingIndicator(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            if (!in_array($user->role, ['employee', 'lawyer', 'client'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $conversation = Conversation::where('id', $conversationId)
                ->where(function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId)
                      ->orWhereNull('organization_id');
                })
                ->first();

            if (!$conversation) {
                return response()->json(['error' => 'Conversation not found'], 404);
            }

            if ($conversation->client_id !== $user->id && $conversation->lawyer_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            try {
                broadcast(new UserTyping($conversationId, $user->id, $request->is_typing))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Broadcast failed: ' . $e->getMessage());
            }
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('Error in sendTypingIndicator: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send typing indicator'], 500);
        }
    }
}
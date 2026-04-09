<?php
// app/Http/Controllers/NotificationController.php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Models\Cases;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\CaseAssignedNotification;

class NotificationController extends Controller
{
    public function sendTestEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $email = $request->email;
            
            // Use Laravel's built-in Mail
            Mail::raw('This is a test email from Legal ERP System. Your email configuration is working!', function ($message) use ($email) {
                $message->to($email)
                        ->subject('Test Email from Legal ERP')
                        ->from(config('mail.from.address'), config('mail.from.name'));
            });
            
            Log::info("Test email sent to: {$email}");
            
            return response()->json([
                'success' => true,
                'message' => "Test email sent to {$email}"
            ]);
            
        } catch (\Exception $e) {
            Log::error("Test email failed: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function sendCaseNotification(Request $request)
    {
        try {
            $request->validate([
                'case_id' => 'required|integer',
                'case_name' => 'required|string',
                'case_number' => 'required|string',
                'client_id' => 'required|integer',
                'lawyer_id' => 'nullable|integer',
            ]);

            $case = Cases::findOrFail($request->case_id);
            $client = User::findOrFail($request->client_id);
            $lawyer = $request->lawyer_id ? User::find($request->lawyer_id) : null;

            // Send to client
            if ($client && $client->email) {
                // Database notification
                Notification::create([
                    'user_id' => $client->id,
                    'type' => 'case_assigned',
                    'title' => 'New Case Assigned',
                    'message' => "A new case '{$case->case_name}' has been assigned to you.",
                    'data' => [
                        'case_id' => $case->id,
                        'case_name' => $case->case_name,
                        'case_number' => $case->case_number,
                    ],
                ]);

                // Send email using Laravel Mail
                Mail::send('emails.case-assigned', [
                    'data' => [
                        'to_name' => $client->name,
                        'case_name' => $case->case_name,
                        'case_number' => $case->case_number,
                        'assigned_lawyer' => $lawyer ? $lawyer->name : 'Not assigned yet',
                        'role' => 'client',
                    ]
                ], function ($message) use ($client) {
                    $message->to($client->email, $client->name)
                            ->subject("New Case Assigned: {$client->name}")
                            ->from(config('mail.from.address'), config('mail.from.name'));
                });
            }

            // Send to lawyer
            if ($lawyer && $lawyer->email) {
                // Database notification
                Notification::create([
                    'user_id' => $lawyer->id,
                    'type' => 'case_assigned',
                    'title' => 'New Case Assignment',
                    'message' => "You have been assigned as the lawyer for case '{$case->case_name}'.",
                    'data' => [
                        'case_id' => $case->id,
                        'case_name' => $case->case_name,
                        'case_number' => $case->case_number,
                        'client_name' => $client->name,
                    ],
                ]);

                // Send email using Laravel Mail
                Mail::send('emails.case-assigned', [
                    'data' => [
                        'to_name' => $lawyer->name,
                        'case_name' => $case->case_name,
                        'case_number' => $case->case_number,
                        'client_name' => $client->name,
                        'role' => 'lawyer',
                    ]
                ], function ($message) use ($lawyer) {
                    $message->to($lawyer->email, $lawyer->name)
                            ->subject("New Case Assignment: {$lawyer->name}")
                            ->from(config('mail.from.address'), config('mail.from.name'));
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Notifications sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending case notification: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getNotifications(Request $request)
    {
        try {
            $user = $request->user();
            
            $notifications = Notification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
            
            $unreadCount = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();
            
            return response()->json([
                'notifications' => $notifications,
                'unread_count' => $unreadCount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'notifications' => [],
                'unread_count' => 0
            ]);
        }
    }

    public function markAsRead($id)
    {
        try {
            $notification = Notification::findOrFail($id);
            $notification->markAsRead();
            return response()->json(['message' => 'Notification marked as read']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function markAllAsRead(Request $request)
    {
        try {
            Notification::where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
            
            return response()->json(['message' => 'All notifications marked as read']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
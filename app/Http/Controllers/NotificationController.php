<?php
// app/Http/Controllers/NotificationController.php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Models\Cases;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\CaseAssignedMail;
use App\Mail\DocumentUploadedMail;
use App\Mail\InvoiceCreatedMail;
use App\Mail\AppointmentReminderMail;

class NotificationController extends Controller
{
    /**
     * Send test email
     */
    public function sendTestEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $email = $request->email;
            
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

    /**
     * Send case assignment notification
     */
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

                // Send email
                $mailData = [
                    'to_name' => $client->first_name . ' ' . $client->last_name,
                    'case_name' => $case->case_name,
                    'case_number' => $case->case_number,
                    'assigned_lawyer' => $lawyer ? ($lawyer->first_name . ' ' . $lawyer->last_name) : 'Not assigned yet',
                    'role' => 'client',
                    'subject' => "New Case Assigned: {$case->case_name}"
                ];
                
                Mail::to($client->email)->send(new CaseAssignedMail($mailData));
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
                        'client_name' => $client->first_name . ' ' . $client->last_name,
                    ],
                ]);

                // Send email
                $mailData = [
                    'to_name' => $lawyer->first_name . ' ' . $lawyer->last_name,
                    'case_name' => $case->case_name,
                    'case_number' => $case->case_number,
                    'client_name' => $client->first_name . ' ' . $client->last_name,
                    'role' => 'lawyer',
                    'subject' => "New Case Assignment: {$case->case_name}"
                ];
                
                Mail::to($lawyer->email)->send(new CaseAssignedMail($mailData));
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

    /**
     * Send document uploaded notification
     */
    public function sendDocumentNotification(Request $request)
    {
        try {
            $request->validate([
                'document_id' => 'required|integer',
                'document_name' => 'required|string',
                'case_id' => 'required|integer',
                'case_name' => 'required|string',
                'case_number' => 'required|string',
                'uploaded_by' => 'required|string',
                'uploaded_by_id' => 'required|integer',
                'client_id' => 'required|integer',
            ]);

            $client = User::findOrFail($request->client_id);
            
            if ($client && $client->email) {
                // Database notification
                Notification::create([
                    'user_id' => $client->id,
                    'type' => 'document_uploaded',
                    'title' => 'Document Uploaded',
                    'message' => "A new document '{$request->document_name}' has been uploaded to case '{$request->case_name}'.",
                    'data' => [
                        'document_id' => $request->document_id,
                        'document_name' => $request->document_name,
                        'case_id' => $request->case_id,
                        'case_name' => $request->case_name,
                        'case_number' => $request->case_number,
                    ],
                ]);

                // Send email
                $mailData = [
                    'to_name' => $client->first_name . ' ' . $client->last_name,
                    'case_name' => $request->case_name,
                    'case_number' => $request->case_number,
                    'document_name' => $request->document_name,
                    'uploaded_by' => $request->uploaded_by,
                    'subject' => "New Document Uploaded: {$request->document_name}"
                ];
                
                Mail::to($client->email)->send(new DocumentUploadedMail($mailData));
            }

            return response()->json([
                'success' => true,
                'message' => 'Document notification sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending document notification: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send invoice notification
     */
    public function sendInvoiceNotification(Request $request)
    {
        try {
            $request->validate([
                'invoice_id' => 'required|integer',
                'invoice_number' => 'required|string',
                'amount' => 'required|numeric',
                'due_date' => 'required|string',
                'case_id' => 'required|integer',
                'case_name' => 'required|string',
                'client_id' => 'required|integer',
            ]);

            $client = User::findOrFail($request->client_id);
            
            if ($client && $client->email) {
                // Database notification
                Notification::create([
                    'user_id' => $client->id,
                    'type' => 'invoice_created',
                    'title' => 'New Invoice Created',
                    'message' => "A new invoice #{$request->invoice_number} has been created for case '{$request->case_name}'.",
                    'data' => [
                        'invoice_id' => $request->invoice_id,
                        'invoice_number' => $request->invoice_number,
                        'amount' => $request->amount,
                        'due_date' => $request->due_date,
                        'case_id' => $request->case_id,
                        'case_name' => $request->case_name,
                    ],
                ]);

                // Send email
                $mailData = [
                    'to_name' => $client->first_name . ' ' . $client->last_name,
                    'invoice_number' => $request->invoice_number,
                    'amount' => $request->amount,
                    'due_date' => $request->due_date,
                    'case_name' => $request->case_name,
                    'subject' => "New Invoice: #{$request->invoice_number}"
                ];
                
                Mail::to($client->email)->send(new InvoiceCreatedMail($mailData));
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice notification sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending invoice notification: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send appointment reminder
     */
    public function sendAppointmentReminder(Request $request)
    {
        try {
            $request->validate([
                'appointment_id' => 'required|integer',
                'title' => 'required|string',
                'case_id' => 'required|integer',
                'case_name' => 'required|string',
                'date' => 'required|string',
                'time' => 'required|string',
                'user_id' => 'required|integer',
                'location' => 'nullable|string',
                'meeting_link' => 'nullable|url',
            ]);

            $user = User::findOrFail($request->user_id);
            
            if ($user && $user->email) {
                // Database notification
                Notification::create([
                    'user_id' => $user->id,
                    'type' => 'appointment_reminder',
                    'title' => 'Appointment Reminder',
                    'message' => "Reminder: {$request->title} for case '{$request->case_name}' at {$request->time} on {$request->date}.",
                    'data' => [
                        'appointment_id' => $request->appointment_id,
                        'title' => $request->title,
                        'case_id' => $request->case_id,
                        'case_name' => $request->case_name,
                        'date' => $request->date,
                        'time' => $request->time,
                        'location' => $request->location,
                        'meeting_link' => $request->meeting_link,
                    ],
                ]);

                // Send email
                $mailData = [
                    'to_name' => $user->first_name . ' ' . $user->last_name,
                    'title' => $request->title,
                    'case_name' => $request->case_name,
                    'date' => $request->date,
                    'time' => $request->time,
                    'location' => $request->location,
                    'meeting_link' => $request->meeting_link,
                    'subject' => "Appointment Reminder: {$request->title}"
                ];
                
                Mail::to($user->email)->send(new AppointmentReminderMail($mailData));
            }

            return response()->json([
                'success' => true,
                'message' => 'Appointment reminder sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending appointment reminder: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user notifications
     */
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

    /**
     * Mark notification as read
     */
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

    /**
     * Mark all notifications as read
     */
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
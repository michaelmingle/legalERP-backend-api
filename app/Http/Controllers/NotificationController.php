<?php
// app/Http/Controllers/NotificationController.php

namespace App\Http\Controllers;

use App\Mail\AppointmentReminderMail;
use App\Mail\CaseAssignedMail;
use App\Mail\DocumentUploadedMail;
use App\Mail\InvoiceCreatedMail;
use App\Mail\UserCredentialsMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationController extends Controller
{
    /**
     * Get user notifications
     */
    public function getNotifications(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'notifications' => [],
                    'unread_count' => 0
                ]);
            }
            
            $notifications = Notification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
                
            $unreadCount = Notification::where('user_id', $user->id)
                ->unread()
                ->count();
                
            return response()->json([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount
            ]);
        } catch (\Exception $e) {
            Log::error('Get notifications error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'notifications' => [],
                'unread_count' => 0,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($id)
    {
        try {
            $notification = Notification::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();
                
            if ($notification) {
                $notification->markAsRead();
                return response()->json([
                    'success' => true,
                    'message' => 'Notification marked as read'
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Mark as read error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        try {
            Notification::where('user_id', Auth::id())
                ->unread()
                ->update(['read_at' => now()]);
                
            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Mark all as read error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Send test email
     */
    public function sendTestEmail(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email'
            ]);
            
            $email = $request->email;
            
            // Simple email sending
            Mail::raw('This is a test email from Legal ERP System.', function ($message) use ($email) {
                $message->to($email)
                    ->subject('Test Email from Legal ERP')
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });
            
            return response()->json([
                'success' => true,
                'message' => "Test email sent to {$email}"
            ]);
        } catch (\Exception $e) {
            Log::error('Test email error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Send case-assignment notification email to one or more recipients.
     * Accepts either a single payload (to/to_name/...) or {case_id, client_id, lawyer_id}.
     */
    public function sendCaseNotification(Request $request)
    {
        try {
            $payload = $request->all();
            $sent = [];
            $failures = [];

            // Resolve recipients
            $recipients = [];
            if (!empty($payload['to'])) {
                $recipients[] = [
                    'email'   => $payload['to'],
                    'to_name' => $payload['to_name'] ?? 'there',
                    'role'    => $payload['role'] ?? 'recipient',
                    'extras'  => $payload,
                ];
            } else {
                // Look up client + lawyer from IDs
                $caseName   = $payload['case_name'] ?? null;
                $caseNumber = $payload['case_number'] ?? null;

                if (!empty($payload['client_id'])) {
                    $client = User::find($payload['client_id']);
                    if ($client && $client->email) {
                        $recipients[] = [
                            'email'   => $client->email,
                            'to_name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: $client->email,
                            'role'    => 'client',
                            'extras'  => $payload,
                        ];
                    }
                }

                if (!empty($payload['lawyer_id'])) {
                    $lawyer = User::find($payload['lawyer_id']);
                    if ($lawyer && $lawyer->email) {
                        $recipients[] = [
                            'email'   => $lawyer->email,
                            'to_name' => trim(($lawyer->first_name ?? '') . ' ' . ($lawyer->last_name ?? '')) ?: $lawyer->email,
                            'role'    => 'lawyer',
                            'extras'  => $payload,
                        ];
                    }
                }
            }

            if (empty($recipients)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No recipients resolved for case notification',
                ], 422);
            }

            foreach ($recipients as $r) {
                $data = array_merge($r['extras'] ?? [], [
                    'to_name'     => $r['to_name'],
                    'role'        => $r['role'],
                    'case_name'   => $payload['case_name']   ?? ($r['extras']['case_name']   ?? 'A case'),
                    'case_number' => $payload['case_number'] ?? ($r['extras']['case_number'] ?? ''),
                    'subject'     => $payload['subject']     ?? 'You have a new case assignment',
                ]);

                try {
                    Mail::to($r['email'])->send(new CaseAssignedMail($data));
                    $sent[] = $r['email'];
                } catch (\Throwable $e) {
                    Log::error('sendCaseNotification mail failed', ['email' => $r['email'], 'error' => $e->getMessage()]);
                    $failures[] = ['email' => $r['email'], 'error' => $e->getMessage()];
                }
            }

            return response()->json([
                'success'  => empty($failures),
                'message'  => empty($failures)
                    ? 'Case notification sent to ' . implode(', ', $sent)
                    : 'Some emails failed to send',
                'sent'     => $sent,
                'failures' => $failures,
            ], empty($failures) ? 200 : 207);
        } catch (\Throwable $e) {
            Log::error('Case notification error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Notify recipient(s) that a document has been uploaded.
     */
    public function sendDocumentNotification(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email']);

            $data = array_merge($request->all(), [
                'subject' => $request->input('subject', 'A new document has been uploaded'),
                'to_name' => $request->input('to_name', 'there'),
            ]);

            Mail::to($request->input('email'))->send(new DocumentUploadedMail($data));

            return response()->json([
                'success' => true,
                'message' => 'Document notification sent to ' . $request->input('email'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Document notification error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Notify a recipient about a newly issued invoice.
     */
    public function sendInvoiceNotification(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email']);

            $data = array_merge($request->all(), [
                'subject' => $request->input('subject', 'A new invoice is available'),
                'to_name' => $request->input('to_name', 'there'),
            ]);

            Mail::to($request->input('email'))->send(new InvoiceCreatedMail($data));

            return response()->json([
                'success' => true,
                'message' => 'Invoice notification sent to ' . $request->input('email'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Invoice notification error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a reminder for an upcoming appointment.
     */
    public function sendAppointmentReminder(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email']);

            $data = array_merge($request->all(), [
                'subject' => $request->input('subject', 'Reminder: upcoming appointment'),
                'to_name' => $request->input('to_name', 'there'),
            ]);

            Mail::to($request->input('email'))->send(new AppointmentReminderMail($data));

            return response()->json([
                'success' => true,
                'message' => 'Appointment reminder sent to ' . $request->input('email'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Appointment reminder error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send freshly-generated login credentials to a new client.
     */
    public function sendClientCredentials(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            $data = [
                'heading'   => 'Welcome to ' . config('app.name', 'Legal ERP'),
                'intro'     => 'A client portal account has been created for you.',
                'user_name' => $request->input('client_name') ?? $request->input('user_name') ?? 'there',
                'email'     => $request->input('email'),
                'password'  => $request->input('password'),
                'login_url' => $request->input('login_url'),
                'role'      => 'client',
                'subject'   => 'Your ' . config('app.name', 'Legal ERP') . ' client portal credentials',
            ];

            Mail::to($data['email'])->send(new UserCredentialsMail($data));

            return response()->json([
                'success' => true,
                'message' => 'Client credentials sent to ' . $data['email'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Client credentials error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send freshly-generated login credentials to a new internal user.
     */
    public function sendUserCredentials(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            $data = [
                'heading'   => 'Welcome to ' . config('app.name', 'Legal ERP'),
                'intro'     => 'An account has been created for you on ' . config('app.name', 'Legal ERP') . '.',
                'user_name' => $request->input('user_name') ?? 'there',
                'email'     => $request->input('email'),
                'password'  => $request->input('password'),
                'login_url' => $request->input('login_url'),
                'role'      => $request->input('role'),
                'subject'   => 'Your ' . config('app.name', 'Legal ERP') . ' login credentials',
            ];

            Mail::to($data['email'])->send(new UserCredentialsMail($data));

            return response()->json([
                'success' => true,
                'message' => 'User credentials sent to ' . $data['email'],
            ]);
        } catch (\Throwable $e) {
            Log::error('User credentials error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a new password to a user whose credentials were reset by an admin.
     */
    public function sendPasswordResetEmail(Request $request)
    {
        try {
            $request->validate([
                'email'        => 'required|email',
                'new_password' => 'required|string',
            ]);

            $data = [
                'heading'   => 'Your password has been reset',
                'intro'     => 'An administrator has reset your password. Use the new password below to sign in.',
                'user_name' => $request->input('user_name') ?? 'there',
                'email'     => $request->input('email'),
                'password'  => $request->input('new_password'),
                'login_url' => $request->input('login_url'),
                'subject'   => 'Your ' . config('app.name', 'Legal ERP') . ' password has been reset',
            ];

            Mail::to($data['email'])->send(new UserCredentialsMail($data));

            return response()->json([
                'success' => true,
                'message' => 'Password reset email sent to ' . $data['email'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Password reset error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
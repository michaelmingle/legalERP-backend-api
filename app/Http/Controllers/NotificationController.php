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


    /**
     * Send client credentials email
     */
    public function sendClientCredentials(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'client_name' => 'required|string',
                'password' => 'required|string',
                'login_url' => 'required|url',
            ]);

            $email = $request->email;
            $clientName = $request->client_name;
            $password = $request->password;
            $loginUrl = $request->login_url;

            // Prepare email content
            $subject = "Welcome to Legal ERP - Your Login Credentials";

            $htmlContent = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4F46E5; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9fafb; }
                .credentials { background-color: #fff; border: 1px solid #e5e7eb; padding: 15px; margin: 20px 0; border-radius: 8px; }
                .credential-item { margin: 10px 0; }
                .label { font-weight: bold; color: #4F46E5; }
                .value { font-family: monospace; background-color: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
                .button { display: inline-block; padding: 10px 20px; background-color: #4F46E5; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Welcome to Legal ERP!</h2>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$clientName}</strong>,</p>
                    <p>Your account has been successfully created in our Legal ERP System. You can now log in to access your cases, documents, and other legal services.</p>
                    
                    <div class='credentials'>
                        <h3 style='margin-top: 0;'>Your Login Credentials:</h3>
                        <div class='credential-item'>
                            <span class='label'>Email:</span>
                            <span class='value'>{$email}</span>
                        </div>
                        <div class='credential-item'>
                            <span class='label'>Password:</span>
                            <span class='value'>{$password}</span>
                        </div>
                    </div>
                    
                    <p><strong>Security Tips:</strong></p>
                    <ul>
                        <li>Please change your password after your first login</li>
                        <li>Never share your password with anyone</li>
                        <li>Contact support immediately if you suspect unauthorized access</li>
                    </ul>
                    
                    <a href='{$loginUrl}' class='button'>Login to Your Account</a>
                    
                    <p style='margin-top: 20px;'>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
                    
                    <p>Best regards,<br><strong>Legal ERP Team</strong></p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " Legal ERP System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";

            // Send email using Laravel's Mail facade
            Mail::send([], [], function ($message) use ($email, $subject, $htmlContent) {
                $message->to($email)
                    ->subject($subject)
                    ->html($htmlContent)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            // Create database notification for the client
            $client = \App\Models\User::where('email', $email)->first();
            if ($client) {
                \App\Models\Notification::create([
                    'user_id' => $client->id,
                    'type' => 'account_created',
                    'title' => 'Welcome to Legal ERP',
                    'message' => "Your account has been created successfully. Check your email for login credentials.",
                    'data' => [
                        'client_name' => $clientName,
                        'email' => $email,
                    ],
                ]);
            }

            Log::info("Client credentials sent to: {$email}");

            return response()->json([
                'success' => true,
                'message' => "Welcome email sent to {$email}"
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send client credentials: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send user credentials email
     */
    public function sendUserCredentials(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'user_name' => 'required|string',
                'password' => 'required|string',
                'role' => 'required|string',
                'login_url' => 'required|url',
            ]);

            $email = $request->email;
            $userName = $request->user_name;
            $password = $request->password;
            $role = $request->role;
            $loginUrl = $request->login_url;

            // Prepare email content
            $subject = "Welcome to Legal ERP - Your Account Credentials";

            $htmlContent = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4F46E5; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9fafb; }
                .credentials { background-color: #fff; border: 1px solid #e5e7eb; padding: 15px; margin: 20px 0; border-radius: 8px; }
                .credential-item { margin: 10px 0; }
                .label { font-weight: bold; color: #4F46E5; }
                .value { font-family: monospace; background-color: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
                .button { display: inline-block; padding: 10px 20px; background-color: #4F46E5; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Welcome to Legal ERP!</h2>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$userName}</strong>,</p>
                    <p>Your account has been successfully created in our Legal ERP System with the role of <strong>{$role}</strong>. You can now log in to access the system.</p>
                    
                    <div class='credentials'>
                        <h3 style='margin-top: 0;'>Your Login Credentials:</h3>
                        <div class='credential-item'>
                            <span class='label'>Email:</span>
                            <span class='value'>{$email}</span>
                        </div>
                        <div class='credential-item'>
                            <span class='label'>Password:</span>
                            <span class='value'>{$password}</span>
                        </div>
                    </div>
                    
                    <p><strong>Security Tips:</strong></p>
                    <ul>
                        <li>Please change your password after your first login</li>
                        <li>Never share your password with anyone</li>
                        <li>Contact your administrator immediately if you suspect unauthorized access</li>
                    </ul>
                    
                    <a href='{$loginUrl}' class='button'>Login to Your Account</a>
                    
                    <p style='margin-top: 20px;'>If you have any questions or need assistance, please contact your system administrator.</p>
                    
                    <p>Best regards,<br><strong>Legal ERP Team</strong></p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " Legal ERP System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";

            // Send email using Laravel's Mail facade
            Mail::send([], [], function ($message) use ($email, $subject, $htmlContent) {
                $message->to($email)
                    ->subject($subject)
                    ->html($htmlContent)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            // Create database notification for the user
            $user = \App\Models\User::where('email', $email)->first();
            if ($user) {
                \App\Models\Notification::create([
                    'user_id' => $user->id,
                    'type' => 'account_created',
                    'title' => 'Welcome to Legal ERP',
                    'message' => "Your account has been created successfully. Check your email for login credentials.",
                    'data' => [
                        'user_name' => $userName,
                        'email' => $email,
                        'role' => $role,
                    ],
                ]);
            }

            Log::info("User credentials sent to: {$email}");

            return response()->json([
                'success' => true,
                'message' => "Welcome email sent to {$email}"
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send user credentials: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Add to NotificationController.php
public function sendPasswordResetEmail(Request $request)
{
    try {
        $request->validate([
            'email' => 'required|email',
            'user_name' => 'required|string',
            'new_password' => 'required|string',
            'login_url' => 'required|url',
        ]);

        $email = $request->email;
        $userName = $request->user_name;
        $newPassword = $request->new_password;
        $loginUrl = $request->login_url;

        // Similar email template as above but for password reset
        $subject = "Your Password Has Been Reset";
        
        $htmlContent = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4F46E5; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9fafb; }
                .credentials { background-color: #fff; border: 1px solid #e5e7eb; padding: 15px; margin: 20px 0; border-radius: 8px; }
                .new-password { font-family: monospace; font-size: 18px; font-weight: bold; color: #4F46E5; }
                .button { display: inline-block; padding: 10px 20px; background-color: #4F46E5; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Password Reset Notification</h2>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$userName}</strong>,</p>
                    <p>Your password has been reset by an administrator.</p>
                    
                    <div class='credentials'>
                        <h3 style='margin-top: 0;'>Your New Login Credentials:</h3>
                        <div class='credential-item'>
                            <span class='label'>Email:</span>
                            <span class='value'>{$email}</span>
                        </div>
                        <div class='credential-item'>
                            <span class='label'>New Password:</span>
                            <span class='new-password'>{$newPassword}</span>
                        </div>
                    </div>
                    
                    <p><strong>Security Tips:</strong></p>
                    <ul>
                        <li>Please change your password after logging in</li>
                        <li>Never share your password with anyone</li>
                        <li>Contact your administrator if you did not request this change</li>
                    </ul>
                    
                    <a href='{$loginUrl}' class='button'>Login to Your Account</a>
                    
                    <p>Best regards,<br><strong>Legal ERP Team</strong></p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " Legal ERP System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        Mail::send([], [], function ($message) use ($email, $subject, $htmlContent) {
            $message->to($email)
                    ->subject($subject)
                    ->html($htmlContent)
                    ->from(config('mail.from.address'), config('mail.from.name'));
        });
        
        Log::info("Password reset email sent to: {$email}");
        
        return response()->json([
            'success' => true,
            'message' => "Password reset email sent to {$email}"
        ]);
        
    } catch (\Exception $e) {
        Log::error("Failed to send password reset email: " . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
}

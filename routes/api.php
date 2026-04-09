<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TeamInviteController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\CaseController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\CandidateController;
use App\Http\Controllers\Api\V1\JobOpeningController;
use App\Http\Controllers\Api\V1\TimeTrackingController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\NotificationController;

Route::get('/test', function () {
    return response()->json(['message' => 'API is working']);
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// routes/api.php
Route::get('/invite/accept/{token}', [TeamInviteController::class, 'accept']);

// Protected routes
// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/logout', [AuthController::class, 'logout']);
//     Route::get('/user', [AuthController::class, 'user']);
// });


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    // Route::get('/user', [AuthController::class, 'user']);
    Route::get('/users', [AuthController::class, 'index']);
    Route::post('/users', [AuthController::class, 'store']);
    Route::get('/users/{user}', [AuthController::class, 'show']);
    Route::put('/users/{user}', [AuthController::class, 'update']);
    Route::delete('/users/{user}', [AuthController::class, 'destroy']);

    Route::prefix('organization')->group(function () {
        Route::put('/goals', [OrganizationController::class, 'updateGoals']);
        Route::post('/invites', [OrganizationController::class, 'sendInvite']);
        Route::delete('/invites/{invite}', [OrganizationController::class, 'revokeInvite']);
    });

    // cases
    Route::apiResource('cases', CaseController::class);

    Route::get('/my-cases', [CaseController::class, 'lawyerCases']);

    // Route::apiResource('case-types', CaseTypeController::class);
    Route::apiResource('clients', ClientController::class);

    // Client cases
    Route::get('/client-cases', [CaseController::class, 'clientCases']);

    Route::apiResource('documents', DocumentController::class);
    Route::get('/my-documents', [DocumentController::class, 'myDocument']);

    // Client Documents
    Route::get('/client-documents', [DocumentController::class, 'clientDocuments']);

    // Lawyer Documents where assigned_to is user id with role lawyer or employee
    Route::get('/lawyer-documents', [DocumentController::class, 'lawyerDocuments']);

    // Billing routes
    Route::apiResource('invoices', BillingController::class);

    Route::get('/my-invoices', [BillingController::class, 'myInvoice']);

    // Client Invoices
    Route::get('/client-invoices', [BillingController::class, 'clientInvoices']);

    // api for invoice pdf generation
    Route::get('/invoices/{invoice}/pdf', [BillingController::class, 'generatePdf']);

    // edit invoice with items and payments
    Route::get('/invoices/{invoice}/edit', [BillingController::class, 'edit']);

    // Financial Route
    Route::apiResource('/payments', PaymentController::class);

    Route::apiResource('/employees', EmployeeController::class);

    Route::get('/my-leaves', [LeaveRequestController::class, 'myLeaves']);
    Route::get('/leave-balance', [LeaveRequestController::class, 'myLeaveBalance']);
    
    // Resource routes for leave requests
    Route::apiResource('leaves', LeaveRequestController::class);

    Route::apiResource('job-openings', JobOpeningController::class);
    Route::apiResource('candidates', CandidateController::class);

    Route::apiResource('time-trackings', TimeTrackingController::class);
    Route::get('/client/time-trackings', [TimeTrackingController::class, 'getTimeTracking']);

     Route::get('/conversations', [ChatController::class, 'getConversations']);
    Route::post('/conversations', [ChatController::class, 'startConversation']);
    Route::get('/conversations/{id}/messages', [ChatController::class, 'getMessages']);
    Route::post('/conversations/{id}/messages', [ChatController::class, 'sendMessage']);
    Route::post('/conversations/{id}/read', [ChatController::class, 'markAsRead']);
    Route::post('/conversations/{id}/typing', [ChatController::class, 'sendTypingIndicator']);
    
    // Users
    Route::get('/users/available', [ChatController::class, 'getAvailableUsers']);
    Route::get('/users/{id}/online-status', [AuthController::class, 'getOnlineStatus']);

    Route::post('/send-case-notification', [NotificationController::class, 'sendCaseNotification']);

    Route::get('/notifications', [NotificationController::class, 'getNotifications']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('/send-case-notification', [NotificationController::class, 'sendCaseNotification']);

    Route::post('/test-email', [NotificationController::class, 'sendTestEmail']);

});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TeamInviteController;
use App\Http\Controllers\Api\V1\OrganizationController;

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
    Route::get('/user', [AuthController::class, 'user']);

    Route::prefix('organization')->group(function () {
        Route::put('/goals', [OrganizationController::class, 'updateGoals']);
        Route::post('/invites', [OrganizationController::class, 'sendInvite']);
        Route::delete('/invites/{invite}', [OrganizationController::class, 'revokeInvite']);
    });
});
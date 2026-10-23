<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\TrackAuthenticatedActivity;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:otp-send');
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');

    Route::middleware(['auth:sanctum', TrackAuthenticatedActivity::class])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::patch('/privacy/inactivity-deletion', [AuthController::class, 'updateInactivityDeletion']);
        Route::post('/profile/email/verification-code', [AuthController::class, 'sendEmailVerificationCode']);
        Route::post('/profile/email/verify', [AuthController::class, 'verifyEmail'])
            ->middleware('throttle:otp-verify');
        Route::post('/profile/photo', [AuthController::class, 'updateProfilePhoto']);
        Route::delete('/profile/photo', [AuthController::class, 'deleteProfilePhoto']);
        Route::post('/profile/phone', [AuthController::class, 'changePhone'])->middleware('throttle:otp-verify');
        Route::delete('/account', [AuthController::class, 'deleteAccount'])->middleware('throttle:otp-verify');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/devices', [AuthController::class, 'devices']);
        Route::delete('/devices/{deviceId}', [AuthController::class, 'revokeDevice'])
            ->whereNumber('deviceId');
    });
});

<?php

use App\Http\Controllers\Api\V1\AdminDiscountCodeController;
use App\Http\Controllers\Api\V1\AdminPaymentGatewayController;
use App\Http\Controllers\Api\V1\AdminPaymentOrderController;
use App\Http\Controllers\Api\V1\AdminTransactionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WithdrawalRequestController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\TrackAuthenticatedActivity;
use Illuminate\Support\Facades\Route;

Route::get('/v1/plans', [SubscriptionController::class, 'plans']);

Route::prefix('v1/auth')->group(function () {
    Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:otp-send');
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    Route::post('/two-factor/verify-login', [TwoFactorController::class, 'verifyLogin'])
        ->middleware('throttle:10,1');

    Route::middleware([
        'auth:sanctum',
        EnsureActiveUser::class,
        'throttle:sensitive-api',
        TrackAuthenticatedActivity::class,
    ])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/subscription', [SubscriptionController::class, 'current']);
        Route::delete('/subscription', [SubscriptionController::class, 'cancel']);
        Route::get('/subscription/pending-order', [PaymentController::class, 'pendingSubscriptionOrder']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('/discounts/quote', [DiscountController::class, 'quote'])
            ->middleware('throttle:10,1');
        Route::post('/wallet/top-ups', [PaymentController::class, 'startWalletTopUp'])
            ->middleware('throttle:5,1,payment-wallet-top-ups');
        Route::post('/subscription/quote', [PaymentController::class, 'quoteSubscription'])
            ->middleware('throttle:10,1');
        Route::post('/subscription/purchase', [PaymentController::class, 'purchaseSubscription'])
            ->middleware('throttle:5,1,payment-subscription-purchases');
        Route::get('/payments/{paymentAttempt}', [PaymentController::class, 'show'])
            ->whereNumber('paymentAttempt');
        Route::get('/payment-orders/{paymentOrder}', [PaymentController::class, 'showOrder'])
            ->whereNumber('paymentOrder');
        Route::get('/wallet/withdrawal-requests', [WithdrawalRequestController::class, 'index']);
        Route::post('/wallet/withdrawal-requests', [WithdrawalRequestController::class, 'store'])
            ->middleware('throttle:5,1,wallet-withdrawal-requests');
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::patch('/privacy/inactivity-deletion', [AuthController::class, 'updateInactivityDeletion']);
        Route::post('/profile/photo', [AuthController::class, 'updateProfilePhoto']);
        Route::delete('/profile/photo', [AuthController::class, 'deleteProfilePhoto']);
        Route::post('/profile/phone', [AuthController::class, 'changePhone'])->middleware('throttle:otp-verify');
        Route::delete('/account', [AuthController::class, 'deleteAccount'])->middleware('throttle:otp-verify');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/devices', [AuthController::class, 'devices']);
        Route::delete('/devices/{deviceId}', [AuthController::class, 'revokeDevice'])
            ->whereNumber('deviceId');
        Route::get('/notifications/push-token', [PushSubscriptionController::class, 'show']);
        Route::put('/notifications/push-token', [PushSubscriptionController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::delete('/notifications/push-token', [PushSubscriptionController::class, 'destroy']);
        Route::get('/two-factor', [TwoFactorController::class, 'status']);
        Route::post('/two-factor/setup', [TwoFactorController::class, 'begin'])->middleware('throttle:5,1');
        Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1');
        Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:10,1');
    });
});

Route::prefix('v1/payment/callback')
    ->middleware('throttle:120,1,payment-callbacks')
    ->group(function (): void {
        Route::match(['get', 'post'], '/{gateway}', [PaymentController::class, 'callback']);
    });

Route::prefix('v1/webhook/payment')
    ->middleware('throttle:120,1,payment-webhooks')
    ->group(function (): void {
        Route::post('/{gateway}', [PaymentController::class, 'callback']);
    });

Route::prefix('v1/admin/wallet/withdrawal-requests')
    ->middleware(['auth:sanctum', 'active-user', 'permission:manage wallet'])
    ->group(function (): void {
        Route::get('/', [WithdrawalRequestController::class, 'queue'])
            ->middleware('throttle:30,1,admin-withdrawal-queue');
        Route::post('/{withdrawalRequest}/approve', [WithdrawalRequestController::class, 'approve'])
            ->whereNumber('withdrawalRequest')
            ->middleware('throttle:30,1,admin-withdrawal-approve');
        Route::post('/{withdrawalRequest}/reject', [WithdrawalRequestController::class, 'reject'])
            ->whereNumber('withdrawalRequest')
            ->middleware('throttle:30,1,admin-withdrawal-reject');
    });

Route::prefix('v1/admin/transactions')
    ->middleware(['auth:sanctum', 'active-user', 'permission:view transactions', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/', [AdminTransactionController::class, 'index']);
    });

Route::prefix('v1/admin/payment-gateways')
    ->middleware(['auth:sanctum', 'active-user', 'permission:manage payment gateways', 'throttle:30,1,admin-payment-gateways'])
    ->group(function (): void {
        Route::get('/', [AdminPaymentGatewayController::class, 'show']);
        Route::put('/', [AdminPaymentGatewayController::class, 'update']);
    });

Route::prefix('v1/admin/payment-orders')
    ->middleware(['auth:sanctum', 'active-user', 'permission:view transactions', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/', [AdminPaymentOrderController::class, 'index']);
    });

Route::prefix('v1/admin/discount-codes')
    ->middleware(['auth:sanctum', 'active-user', 'permission:manage discount codes', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('/', [AdminDiscountCodeController::class, 'index']);
        Route::post('/', [AdminDiscountCodeController::class, 'store']);
        Route::put('/{discountCode}', [AdminDiscountCodeController::class, 'update'])
            ->whereNumber('discountCode');
        Route::patch('/{discountCode}', [AdminDiscountCodeController::class, 'update'])
            ->whereNumber('discountCode');
        Route::get('/{discountCode}/usages', [AdminDiscountCodeController::class, 'usages'])
            ->whereNumber('discountCode');
    });

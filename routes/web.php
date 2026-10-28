<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDiscountCodePageController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\AdminRoleManagementController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminTransactionPageController;
use App\Http\Controllers\Admin\AdminTwoFactorController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWebAuthController;
use App\Http\Controllers\Admin\AdminWithdrawalPageController;
use App\Services\Admin\AdminRuntimeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function (AdminRuntimeSettings $runtimeSettings) {
    $viewPath = resource_path('views/welcome.blade.php');
    $manifestPath = public_path('build/manifest.json');
    $viewVersion = is_file($viewPath) ? (string) filemtime($viewPath) : 'missing';
    $assetVersion = is_file($manifestPath) ? (string) filemtime($manifestPath) : 'development';
    $plans = $runtimeSettings->plans();
    $siteContent = $runtimeSettings->siteContent();
    $cacheKey = 'public-homepage:v1:'.hash('sha256', serialize([
        $plans,
        $siteContent,
        $viewVersion,
        $assetVersion,
    ]));

    $html = Cache::remember(
        $cacheKey,
        now()->addMinutes(5),
        fn (): string => view('welcome', [
            'plans' => $plans,
            'siteContent' => $siteContent,
        ])->render(),
    );

    return response($html)
        ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AdminWebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login/otp', [AdminWebAuthController::class, 'sendOtp'])
        ->middleware('throttle:admin-login-send')
        ->name('login.send-otp');
    Route::get('/login/verify', [AdminWebAuthController::class, 'showVerify'])->name('login.verify');
    Route::post('/login/verify', [AdminWebAuthController::class, 'verify'])
        ->middleware('throttle:admin-login-verify')
        ->name('login.verify.submit');
    Route::get('/login/two-factor', [AdminWebAuthController::class, 'showTwoFactor'])->name('login.two-factor');
    Route::post('/login/two-factor', [AdminWebAuthController::class, 'verifyTwoFactor'])
        ->middleware('throttle:10,1')
        ->name('login.two-factor.submit');
    Route::post('/login/resend', [AdminWebAuthController::class, 'resendOtp'])
        ->middleware('throttle:admin-login-send')
        ->name('login.resend');

    Route::middleware(['auth:web', 'active-user'])->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::get('/users', [AdminUserController::class, 'index'])
            ->middleware('permission:manage users')
            ->name('users.index');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])
            ->middleware('permission:manage users')
            ->whereNumber('user')
            ->name('users.status');
        Route::patch('/users/{user}/access', [AdminUserController::class, 'updateAccess'])
            ->middleware('role:Owner')
            ->whereNumber('user')
            ->name('users.access');
        Route::middleware(['role:Owner', 'permission:manage roles'])->group(function (): void {
            Route::get('/roles', [AdminRoleManagementController::class, 'index'])->name('roles.index');
            Route::post('/roles', [AdminRoleManagementController::class, 'storeRole'])->name('roles.store');
            Route::put('/roles/{role}', [AdminRoleManagementController::class, 'updateRole'])->whereNumber('role')->name('roles.update');
            Route::delete('/roles/{role}', [AdminRoleManagementController::class, 'destroyRole'])->whereNumber('role')->name('roles.destroy');
            Route::post('/permissions', [AdminRoleManagementController::class, 'storePermission'])->name('permissions.store');
            Route::put('/permissions/{permission}', [AdminRoleManagementController::class, 'updatePermission'])->whereNumber('permission')->name('permissions.update');
            Route::delete('/permissions/{permission}', [AdminRoleManagementController::class, 'destroyPermission'])->whereNumber('permission')->name('permissions.destroy');
        });
        Route::get('/transactions', [AdminTransactionPageController::class, 'index'])
            ->middleware('permission:view transactions')
            ->name('transactions.index');
        Route::get('/withdrawals', [AdminWithdrawalPageController::class, 'index'])
            ->middleware('permission:manage wallet')
            ->name('withdrawals.index');
        Route::post('/withdrawals/{withdrawal}/review', [AdminWithdrawalPageController::class, 'review'])
            ->middleware('permission:manage wallet')
            ->whereNumber('withdrawal')
            ->name('withdrawals.review');
        Route::get('/discount-codes', [AdminDiscountCodePageController::class, 'index'])
            ->middleware('permission:manage discount codes')
            ->name('discount-codes.index');
        Route::post('/discount-codes', [AdminDiscountCodePageController::class, 'store'])
            ->middleware('permission:manage discount codes')
            ->name('discount-codes.store');
        Route::put('/discount-codes/{discountCode}', [AdminDiscountCodePageController::class, 'update'])
            ->middleware('permission:manage discount codes')
            ->whereNumber('discountCode')
            ->name('discount-codes.update');
        Route::get('/settings', [AdminSettingsController::class, 'index'])
            ->name('settings.index');
        Route::get('/settings/{section}', [AdminSettingsController::class, 'show'])
            ->whereIn('section', ['plans', 'wallet', 'gateways', 'site-content'])
            ->name('settings.section');
        Route::put('/settings/plans', [AdminSettingsController::class, 'updatePlans'])
            ->middleware('permission:manage plans')
            ->name('settings.plans.update');
        Route::put('/settings/wallet', [AdminSettingsController::class, 'updateWallet'])
            ->middleware('permission:manage wallet')
            ->name('settings.wallet.update');
        Route::put('/settings/payment-gateways', [AdminSettingsController::class, 'updateGateways'])
            ->middleware('permission:manage payment gateways')
            ->name('settings.gateways.update');
        Route::put('/settings/site-content', [AdminSettingsController::class, 'updateSiteContent'])
            ->middleware('permission:manage site content')
            ->name('settings.site-content.update');
        Route::get('/profile', [AdminProfileController::class, 'edit'])->name('profile.edit');
        Route::post('/profile', [AdminProfileController::class, 'update'])->name('profile.update');
        Route::get('/profile/two-factor', [AdminTwoFactorController::class, 'show'])->name('profile.two-factor');
        Route::post('/profile/two-factor/setup', [AdminTwoFactorController::class, 'begin'])->name('profile.two-factor.setup');
        Route::post('/profile/two-factor/confirm', [AdminTwoFactorController::class, 'confirm'])->name('profile.two-factor.confirm');
        Route::delete('/profile/two-factor', [AdminTwoFactorController::class, 'disable'])->name('profile.two-factor.disable');
        Route::post('/logout', [AdminWebAuthController::class, 'logout'])->name('logout');
    });
});

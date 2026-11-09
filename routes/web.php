<?php

use App\Http\Controllers\Admin\AdminAnnouncementController;
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
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\Admin\AdminRuntimeSettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$statelessPublicMiddleware = [
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
    HandleInertiaRequests::class,
];

Route::get('/', function (Request $request, AdminRuntimeSettings $runtimeSettings) {
    $viewPaths = [
        resource_path('views/layouts/public.blade.php'),
        resource_path('views/welcome.blade.php'),
        resource_path('views/partials/public-header.blade.php'),
        resource_path('views/partials/public-footer.blade.php'),
        resource_path('views/partials/public-meta.blade.php'),
        resource_path('views/partials/site-faq.blade.php'),
        resource_path('views/partials/theme-bootstrap.blade.php'),
    ];
    $manifestPath = public_path('build/manifest.json');
    $viewVersions = array_map(
        static fn (string $path): string => is_file($path) ? (string) filemtime($path) : 'missing',
        $viewPaths,
    );
    $assetVersion = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : 'development';
    $plans = $runtimeSettings->plans();
    $siteContent = $runtimeSettings->siteContent();
    $faqs = config('site-faq', []);
    $cacheKey = 'public-homepage:v3:'.hash('sha256', serialize([
        $request->getSchemeAndHttpHost(),
        $plans,
        $siteContent,
        $faqs,
        $viewVersions,
        $assetVersion,
    ]));

    $html = Cache::remember(
        $cacheKey,
        now()->addMinutes(5),
        fn (): string => view('welcome', [
            'plans' => $plans,
            'siteContent' => $siteContent,
            'faqs' => $faqs,
        ])->render(),
    );

    return response($html)
        ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
})->withoutMiddleware($statelessPublicMiddleware)->name('home');

Route::get('/tools', function (Request $request, AdminRuntimeSettings $runtimeSettings) {
    $plans = $runtimeSettings->plans();
    $features = collect($plans['features'])
        ->except(['group-calculation', 'legal_library'])
        ->map(function (array $feature, string $key): array {
            $feature['key'] = $key;

            return $feature;
        });
    $groups = $features->pluck('group')->filter()->unique()->values();
    $validator = Validator::make($request->query(), [
        'q' => ['nullable', 'string', 'max:100'],
        'group' => ['nullable', 'string', 'in:'.implode(',', $groups->all())],
    ]);
    $filterErrors = $validator->errors()->all();
    $validated = $filterErrors === [] ? $validator->validated() : [];
    $search = trim($validated['q'] ?? '');

    if ($filterErrors !== []) {
        $features = $features->take(0);
    }

    $tools = $features
        ->filter(function (array $feature) use ($search): bool {
            if ($search === '') {
                return true;
            }

            return Str::contains(
                implode(' ', [$feature['label'], $feature['description'], $feature['group']]),
                $search,
                true,
            );
        })
        ->groupBy('group');
    $selectedGroup = $validated['group'] ?? ($search !== ''
        ? $groups->first(fn (string $group): bool => $tools->has($group))
        : $groups->first());
    $selectedGroup ??= $groups->first();

    $response = response()->view('tools.index', [
        'siteContent' => $runtimeSettings->siteContent(),
        'tools' => $tools,
        'groups' => $groups,
        'search' => $search,
        'selectedGroup' => $selectedGroup,
        'filterErrors' => $filterErrors,
    ], $filterErrors === [] ? 200 : 422);

    return $filterErrors === []
        ? $response->header('Cache-Control', 'public, max-age=60, s-maxage=300')
        : $response->header('Cache-Control', 'no-store');
})->withoutMiddleware($statelessPublicMiddleware)->name('tools.index');

Route::get('/tools/{tool}', function (string $tool, AdminRuntimeSettings $runtimeSettings) {
    $plans = $runtimeSettings->plans();
    $features = collect($plans['features'])->except('legal_library');
    abort_unless($features->has($tool), 404);

    $feature = $features->get($tool);
    $enabledTiers = collect($plans['tiers'])
        ->filter(fn (array $tier): bool => (bool) ($tier['features'][$tool] ?? false));

    return response()->view('tools.show', [
        'siteContent' => $runtimeSettings->siteContent(),
        'toolKey' => $tool,
        'tool' => $feature,
        'enabledTiers' => $enabledTiers,
    ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
})->where('tool', '[A-Za-z0-9-]+')
    ->withoutMiddleware($statelessPublicMiddleware)
    ->name('tools.show');

Route::get('/laws', function (AdminRuntimeSettings $runtimeSettings) {
    return response()->view('laws.index', [
        'siteContent' => $runtimeSettings->siteContent(),
        'legalCategories' => config('site-legal-categories', []),
    ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
})->withoutMiddleware($statelessPublicMiddleware)->name('laws.index');

Route::get('/faq', function (AdminRuntimeSettings $runtimeSettings) {
    $relatedLinks = [
        'tools' => [
            'label' => 'مشاهدهٔ فهرست ابزارها',
            'url' => route('tools.index'),
        ],
        'law-library' => [
            'label' => 'مشاهدهٔ دسته‌های قوانین و مقررات',
            'url' => route('laws.index'),
        ],
        'group-calculation' => [
            'label' => 'جزئیات محاسبات جامع',
            'url' => route('tools.show', 'group-calculation'),
        ],
    ];
    $faqs = collect(config('site-faq', []))
        ->map(function (array $faq) use ($relatedLinks): array {
            $faq['related'] = $relatedLinks[$faq['related_link'] ?? ''] ?? null;

            return $faq;
        })
        ->all();

    return response()->view('faq.index', [
        'siteContent' => $runtimeSettings->siteContent(),
        'faqs' => $faqs,
    ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
})->withoutMiddleware($statelessPublicMiddleware)->name('faq.index');

Route::get('/blog', function (AdminRuntimeSettings $runtimeSettings) {
    return response()->view('blog.index', [
        'siteContent' => $runtimeSettings->siteContent(),
    ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
})->withoutMiddleware($statelessPublicMiddleware)->name('blog.index');

Route::get('/about', function (AdminRuntimeSettings $runtimeSettings) {
    return response()->view('about', [
        'siteContent' => $runtimeSettings->siteContent(),
    ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
})->withoutMiddleware($statelessPublicMiddleware)->name('about');

Route::get('/terms-of-use', function (AdminRuntimeSettings $runtimeSettings) {
    return response()->view('terms-of-use', [
        'siteContent' => $runtimeSettings->siteContent(),
    ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
})->withoutMiddleware($statelessPublicMiddleware)->name('terms');

Route::get('/robots.txt', function () {
    return response()->view('robots', [], 200, ['Content-Type' => 'text/plain; charset=UTF-8'])
        ->header('Cache-Control', 'public, max-age=3600');
})->withoutMiddleware($statelessPublicMiddleware)->name('robots');

Route::get('/sitemap.xml', function () {
    $toolUrls = collect(config('plans.features', []))
        ->except('legal_library')
        ->keys()
        ->map(fn (string $tool): string => route('tools.show', $tool));

    return response()->view('sitemap', [
        'urls' => collect([
            route('home'),
            route('about'),
            route('terms'),
            route('blog.index'),
            route('tools.index'),
            route('laws.index'),
            route('faq.index'),
        ])->concat($toolUrls),
    ], 200, ['Content-Type' => 'application/xml; charset=UTF-8'])
        ->header('Cache-Control', 'public, max-age=3600');
})->withoutMiddleware($statelessPublicMiddleware)->name('sitemap');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AdminWebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login/otp', [AdminWebAuthController::class, 'sendOtp'])
        ->middleware('throttle:otp-send')
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
        ->middleware('throttle:otp-send')
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
        Route::post('/withdrawals/{withdrawal}/paid', [AdminWithdrawalPageController::class, 'markPaid'])
            ->middleware('permission:manage wallet')
            ->whereNumber('withdrawal')
            ->name('withdrawals.paid');
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
        Route::get('/announcements', [AdminAnnouncementController::class, 'index'])
            ->middleware('permission:manage notifications')
            ->name('announcements.index');
        Route::post('/announcements', [AdminAnnouncementController::class, 'store'])
            ->middleware('permission:manage notifications')
            ->name('announcements.store');
        Route::post('/announcements/{announcement}/retry', [AdminAnnouncementController::class, 'retry'])
            ->middleware('permission:manage notifications')
            ->whereNumber('announcement')
            ->name('announcements.retry');
        Route::get('/settings', [AdminSettingsController::class, 'index'])
            ->name('settings.index');
        Route::get('/settings/{section}', [AdminSettingsController::class, 'show'])
            ->whereIn('section', ['plans', 'wallet', 'gateways', 'sms', 'site-content'])
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
        Route::put('/settings/sms-provider', [AdminSettingsController::class, 'updateSmsProvider'])
            ->middleware('permission:manage sms providers')
            ->name('settings.sms-provider.update');
        Route::post('/settings/sms-test', [AdminSettingsController::class, 'sendSmsTest'])
            ->middleware(['permission:manage sms providers', 'throttle:admin-sms-test'])
            ->name('settings.sms-test');
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

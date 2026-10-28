<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'adminNavigation' => fn (): ?array => $request->user('web')?->canAccessAdminPanel()
                ? [
                    'users' => $request->user('web')->can('manage users'),
                    'transactions' => $request->user('web')->can('view transactions'),
                    'wallet' => $request->user('web')->can('manage wallet'),
                    'discountCodes' => $request->user('web')->can('manage discount codes'),
                    'plans' => $request->user('web')->can('manage plans'),
                    'gateways' => $request->user('web')->can('manage payment gateways'),
                    'siteContent' => $request->user('web')->can('manage site content'),
                    'roles' => $request->user('web')->hasRole('Owner')
                        && $request->user('web')->can('manage roles'),
                    'settings' => $request->user('web')->can('manage plans')
                        || $request->user('web')->can('manage wallet')
                        || $request->user('web')->can('manage payment gateways')
                        || $request->user('web')->can('manage site content'),
                ]
                : null,
            'app' => [
                'name' => config('site.brand_name', 'پهناور کار'),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'generatedCode' => fn () => $request->session()->get('generated_code'),
            ],
        ];
    }
}

@php($homeHref = request()->routeIs('home') ? '' : route('home'))
@php($isFeatureRoute = request()->routeIs('tools.*', 'laws.index'))
<a href="#main-content" class="skip-link">رفتن به محتوای اصلی</a>
<header class="public-site-header sticky top-0 z-20 border-b border-slate-200/70 bg-white/90 backdrop-blur-xl">
    <nav class="relative mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-3 sm:px-8" aria-label="ناوبری اصلی">
        <a href="{{ $homeHref }}#home" class="flex shrink-0 items-center gap-2.5 rounded-xl focus:outline-none focus:ring-4 focus:ring-indigo-500/20" aria-label="{{ $siteContent['brand_name'] }}، صفحهٔ اصلی">
            <img src="{{ asset('images/pahnavarkar-logo-teal.png') }}?v=1" alt="" aria-hidden="true" class="size-10 rounded-full object-contain dark:hidden">
            <img src="{{ asset('images/pahnavarkar-logo-white.png') }}?v=1" alt="" aria-hidden="true" class="hidden size-10 rounded-full object-contain dark:block">
            <span class="text-sm font-bold tracking-tight text-slate-900 sm:text-base">{{ $siteContent['brand_name'] }}</span>
        </a>

        <div class="hidden items-center gap-1 md:flex">
            <a
                @class([
                    'rounded-full px-3 py-2 text-sm font-medium transition hover:bg-slate-100 hover:text-indigo-700',
                    'bg-indigo-50 font-bold text-indigo-800' => request()->routeIs('home'),
                    'text-slate-600' => ! request()->routeIs('home'),
                ])
                data-public-nav-section="home"
                data-active-classes="bg-indigo-50 font-bold text-indigo-800"
                data-inactive-classes="text-slate-600"
                href="{{ route('home') }}#home"
                @if (request()->routeIs('home')) aria-current="location" @endif
            >خانه</a>
            <details class="group relative" data-nav-dropdown data-public-nav-group>
                <summary
                    class="flex cursor-pointer list-none items-center gap-1 rounded-full px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-500/20 [&::-webkit-details-marker]:hidden"
                    data-public-nav-section="features"
                    data-public-nav-target="{{ $homeHref }}#features"
                    data-active-classes="bg-indigo-50 font-bold text-indigo-800"
                    data-inactive-classes="text-slate-600"
                    @if ($isFeatureRoute) aria-current="location" @endif
                >
                    امکانات
                    <i class="fa-solid fa-chevron-down size-4 transition-transform group-open:rotate-180" aria-hidden="true"></i>
                </summary>
                <div class="absolute right-0 top-full z-30 mt-2 hidden w-56 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10 group-open:block" data-nav-dropdown-panel>
                    <a href="{{ route('tools.index') }}" class="block rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-indigo-700">ابزارهای محاسباتی</a>
                    <a href="{{ route('laws.index') }}" class="mt-1 block rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-indigo-700">قوانین و مقررات</a>
                </div>
            </details>
            <a
                class="rounded-full px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-indigo-700"
                data-public-nav-section="plans"
                data-public-nav-target="{{ $homeHref }}#plans"
                data-active-classes="bg-indigo-50 font-bold text-indigo-800"
                data-inactive-classes="text-slate-600"
                href="{{ $homeHref }}#plans"
            >پلن‌ها و اشتراک</a>
            <a
                class="rounded-full px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-indigo-700"
                data-public-nav-section="faq"
                data-active-classes="bg-indigo-50 font-bold text-indigo-800"
                data-inactive-classes="text-slate-600"
                href="{{ route('faq.index') }}"
                @if (request()->routeIs('faq.index')) aria-current="page" @endif
            >پرسش‌های پرتکرار</a>
            <a
                @class([
                    'rounded-full px-3 py-2 text-sm font-medium transition hover:bg-slate-100 hover:text-indigo-700',
                    'bg-indigo-50 font-bold text-indigo-800' => request()->routeIs('blog.index'),
                    'text-slate-600' => ! request()->routeIs('blog.index'),
                ])
                href="{{ route('blog.index') }}"
                @if (request()->routeIs('blog.index')) aria-current="page" @endif
            >بلاگ</a>
            <a
                @class([
                    'rounded-full px-3 py-2 text-sm font-medium transition hover:bg-slate-100 hover:text-indigo-700',
                    'bg-indigo-50 font-bold text-indigo-800' => request()->routeIs('about'),
                    'text-slate-600' => ! request()->routeIs('about'),
                ])
                href="{{ route('about') }}"
                @if (request()->routeIs('about')) aria-current="page" @endif
            >درباره ما</a>
        </div>

        <div class="hidden md:block">
            @include('partials.theme-switcher', ['switcherId' => 'desktop'])
        </div>

        <button
            type="button"
            data-mobile-menu-toggle
            aria-controls="mobile-navigation"
            aria-expanded="false"
            aria-label="باز کردن منو"
            class="public-menu-toggle grid place-items-center md:hidden"
        >
            <i data-menu-icon class="fa-solid fa-bars public-menu-toggle-icon" aria-hidden="true"></i>
        </button>

        <div id="mobile-navigation" class="absolute inset-x-5 top-full z-30 mt-2 hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-lg shadow-slate-900/10 sm:inset-x-8 md:hidden" hidden>
            <div class="grid gap-1" role="group" aria-label="پیوندهای ناوبری">
                <a
                    @class([
                        'rounded-xl px-4 py-3 text-sm font-semibold transition hover:bg-slate-100',
                        'bg-indigo-50 text-indigo-800' => request()->routeIs('home'),
                        'text-slate-700' => ! request()->routeIs('home'),
                    ])
                    data-public-nav-section="home"
                    data-active-classes="bg-indigo-50 text-indigo-800"
                    data-inactive-classes="text-slate-700"
                    href="{{ route('home') }}#home"
                    @if (request()->routeIs('home')) aria-current="location" @endif
                >خانه</a>
                <details class="group rounded-xl" data-nav-dropdown data-public-nav-group>
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-indigo-500/20 [&::-webkit-details-marker]:hidden"
                        data-public-nav-section="features"
                        data-public-nav-target="{{ $homeHref }}#features"
                        data-active-classes="bg-indigo-50 text-indigo-800"
                        data-inactive-classes="text-slate-700"
                        @if ($isFeatureRoute) aria-current="location" @endif
                    >
                        امکانات
                        <i class="fa-solid fa-chevron-down size-4 transition-transform group-open:rotate-180" aria-hidden="true"></i>
                    </summary>
                    <div class="grid gap-1 px-2 pb-2" data-nav-dropdown-panel>
                        <a href="{{ route('tools.index') }}" class="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-indigo-700">ابزارهای محاسباتی</a>
                        <a href="{{ route('laws.index') }}" class="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-indigo-700">قوانین و مقررات</a>
                    </div>
                </details>
                <a
                    class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                    data-public-nav-section="plans"
                    data-public-nav-target="{{ $homeHref }}#plans"
                    data-active-classes="bg-indigo-50 text-indigo-800"
                    data-inactive-classes="text-slate-700"
                    href="{{ $homeHref }}#plans"
                >پلن‌ها و اشتراک</a>
                <a
                    class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                    data-public-nav-section="faq"
                    data-active-classes="bg-indigo-50 text-indigo-800"
                    data-inactive-classes="text-slate-700"
                    href="{{ route('faq.index') }}"
                    @if (request()->routeIs('faq.index')) aria-current="page" @endif
                >پرسش‌های پرتکرار</a>
                <a
                    @class([
                        'rounded-xl px-4 py-3 text-sm font-semibold transition hover:bg-slate-100',
                        'bg-indigo-50 text-indigo-800' => request()->routeIs('blog.index'),
                        'text-slate-700' => ! request()->routeIs('blog.index'),
                    ])
                    href="{{ route('blog.index') }}"
                    @if (request()->routeIs('blog.index')) aria-current="page" @endif
                >بلاگ</a>
                <a
                    @class([
                        'rounded-xl px-4 py-3 text-sm font-semibold transition hover:bg-slate-100',
                        'bg-indigo-50 text-indigo-800' => request()->routeIs('about'),
                        'text-slate-700' => ! request()->routeIs('about'),
                    ])
                    href="{{ route('about') }}"
                    @if (request()->routeIs('about')) aria-current="page" @endif
                >درباره ما</a>
            </div>
            <div class="mt-2 flex items-center justify-between gap-4 border-t border-slate-100 px-4 pt-3 text-xs font-semibold text-slate-600">
                <span>قالب رنگی</span>
                @include('partials.theme-switcher', ['switcherId' => 'mobile'])
            </div>
        </div>
    </nav>
</header>

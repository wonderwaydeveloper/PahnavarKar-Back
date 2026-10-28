<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#EFF8FA">
    <meta name="description" content="{{ $siteContent['meta_description'] }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}?v=3">
    <link rel="apple-touch-icon" href="{{ asset('favicon.png') }}?v=3">
    @include('partials.theme-bootstrap')
    <title>{{ $siteContent['brand_name'] }} | {{ $siteContent['tagline'] }}</title>
    @vite('resources/js/theme.js')
</head>
<body class="bg-[#eff8fa] font-sans text-slate-900 antialiased">
    <div class="site-ambient" aria-hidden="true">
        <svg class="site-ambient-art" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" focusable="false">
            <defs>
                <radialGradient id="ambient-glow-teal">
                    <stop offset="0" stop-color="#14b8a6" stop-opacity=".2" />
                    <stop offset="1" stop-color="#14b8a6" stop-opacity="0" />
                </radialGradient>
                <path id="ambient-chart-main" d="M-60 650 L70 650 L145 565 L220 590 L295 520 L370 548 L445 470 L520 505 L595 430 L670 455 L745 375 L820 418 L895 340 L970 370 L1045 295 L1120 335 L1195 260 L1270 300 L1345 220 L1420 240 L1500 170" />
            </defs>
            <ellipse class="site-ambient-glow site-ambient-glow--one" cx="300" cy="190" rx="470" ry="300" fill="url(#ambient-glow-teal)" />
            <ellipse class="site-ambient-glow site-ambient-glow--two" cx="1160" cy="680" rx="500" ry="330" fill="url(#ambient-glow-teal)" />
            <g class="site-ambient-chart">
                <g class="site-ambient-chart-donut" transform="translate(290 330) rotate(-90)">
                    <circle class="site-ambient-chart-donut-track" r="42" />
                    <circle class="site-ambient-chart-donut-segment site-ambient-chart-donut-segment--one" r="42" stroke-dasharray="82 182" />
                    <circle class="site-ambient-chart-donut-segment site-ambient-chart-donut-segment--two" r="42" stroke-dasharray="112 152" stroke-dashoffset="-86" />
                    <circle class="site-ambient-chart-donut-segment site-ambient-chart-donut-segment--three" r="42" stroke-dasharray="64 200" stroke-dashoffset="-202" />
                    <circle class="site-ambient-chart-donut-center" r="24" />
                </g>
                <g class="site-ambient-chart-bars">
                    <rect x="155" y="740" width="22" height="110" rx="4" />
                    <rect x="225" y="700" width="22" height="150" rx="4" />
                    <rect x="295" y="720" width="22" height="130" rx="4" />
                    <rect x="365" y="660" width="22" height="190" rx="4" />
                    <rect x="435" y="685" width="22" height="165" rx="4" />
                    <rect x="505" y="620" width="22" height="230" rx="4" />
                    <rect x="575" y="645" width="22" height="205" rx="4" />
                    <rect x="645" y="585" width="22" height="265" rx="4" />
                    <rect x="715" y="610" width="22" height="240" rx="4" />
                    <rect x="785" y="545" width="22" height="305" rx="4" />
                    <rect x="855" y="570" width="22" height="280" rx="4" />
                    <rect x="925" y="510" width="22" height="340" rx="4" />
                    <rect x="995" y="530" width="22" height="320" rx="4" />
                    <rect x="1065" y="475" width="22" height="375" rx="4" />
                    <rect x="1135" y="495" width="22" height="355" rx="4" />
                    <rect x="1205" y="440" width="22" height="410" rx="4" />
                </g>
                <use href="#ambient-chart-main" class="site-ambient-chart-line" />
                <g class="site-ambient-chart-points">
                    <circle r="3.5"><animateMotion dur="48s" calcMode="linear" begin="-12s" repeatCount="indefinite"><mpath href="#ambient-chart-main" /></animateMotion></circle>
                    <circle class="site-ambient-chart-point site-ambient-chart-point--glow" r="11"><animateMotion dur="64s" calcMode="linear" begin="-37s" repeatCount="indefinite"><mpath href="#ambient-chart-main" /></animateMotion></circle>
                    <circle class="site-ambient-chart-point" r="3"><animateMotion dur="64s" calcMode="linear" begin="-37s" repeatCount="indefinite"><mpath href="#ambient-chart-main" /></animateMotion></circle>
                    <circle class="site-ambient-chart-point site-ambient-chart-point--glow" r="9"><animateMotion dur="78s" calcMode="linear" begin="-21s" repeatCount="indefinite"><mpath href="#ambient-chart-main" /></animateMotion></circle>
                    <circle class="site-ambient-chart-point" r="2.5"><animateMotion dur="78s" calcMode="linear" begin="-21s" repeatCount="indefinite"><mpath href="#ambient-chart-main" /></animateMotion></circle>
                </g>
            </g>
        </svg>
    </div>
    <header class="sticky top-0 z-20 border-b border-slate-200/70 bg-white/90 backdrop-blur-xl">
        <nav class="relative mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 sm:py-4 lg:px-8" aria-label="ناوبری اصلی">
            <a href="#home" class="flex items-center gap-3" aria-label="{{ $siteContent['brand_name'] }}، صفحهٔ اصلی">
                <img src="{{ asset('favicon.png') }}?v=3" alt="" aria-hidden="true" class="size-10 rounded-2xl object-cover shadow-lg shadow-indigo-600/20 sm:size-11">
                <span>
                    <span class="block text-sm font-bold tracking-tight sm:text-base">{{ $siteContent['brand_name'] }}</span>
                    <span class="hidden text-xs text-slate-500 min-[380px]:block">{{ $siteContent['tagline'] }}</span>
                </span>
            </a>

            <div class="hidden items-center gap-3 text-xs font-medium text-slate-600 md:flex lg:gap-5 lg:text-sm xl:gap-8">
                <a class="whitespace-nowrap transition hover:text-indigo-700" href="#features">امکانات</a>
                <a class="whitespace-nowrap transition hover:text-indigo-700" href="#tools">محاسبه‌گرها</a>
                <a class="whitespace-nowrap transition hover:text-indigo-700" href="#law-library">قوانین کار</a>
                <a class="whitespace-nowrap transition hover:text-indigo-700" href="#plans">اشتراک</a>
            </div>

            <div class="hidden items-center md:flex">
                <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <span class="sr-only">قالب رنگی</span>
                    <select data-theme-switcher aria-label="انتخاب قالب رنگی" class="h-10 max-w-36 rounded-xl border border-slate-200 bg-white px-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 sm:px-3">
                        <option value="light">روشن</option>
                        <option value="dark">تیره</option>
                        <option value="system">مطابق سیستم</option>
                    </select>
                </label>
            </div>

            <button
                type="button"
                data-mobile-menu-toggle
                aria-controls="mobile-navigation"
                aria-expanded="false"
                aria-label="باز کردن منو"
                class="grid size-11 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-indigo-500/20 md:hidden"
            >
                <svg data-menu-open-icon class="size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <svg data-menu-close-icon class="hidden size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>

            <div id="mobile-navigation" class="absolute inset-x-4 top-full z-30 mt-2 hidden rounded-2xl border border-slate-200/80 bg-white p-3 shadow-xl shadow-slate-900/10 sm:inset-x-6 md:hidden" hidden>
                <div class="grid gap-1" aria-label="پیوندهای ناوبری">
                    <a class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" href="#features">امکانات</a>
                    <a class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" href="#tools">محاسبه‌گرها</a>
                    <a class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" href="#law-library">قوانین کار</a>
                    <a class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" href="#plans">اشتراک</a>
                </div>
                <label class="mt-2 flex items-center justify-between gap-4 border-t border-slate-100 px-4 pt-3 text-xs font-semibold text-slate-600">
                    <span>قالب رنگی</span>
                    <select data-theme-switcher aria-label="انتخاب قالب رنگی" class="h-10 max-w-40 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10">
                        <option value="light">روشن</option>
                        <option value="dark">تیره</option>
                        <option value="system">مطابق سیستم</option>
                    </select>
                </label>
            </div>
        </nav>
    </header>

    <main id="home">
        <section class="relative isolate overflow-hidden">
            <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_15%_15%,rgba(129,140,248,.19),transparent_38%),radial-gradient(ellipse_at_85%_70%,rgba(45,212,191,.13),transparent_35%)]"></div>
            <div class="mx-auto grid max-w-7xl items-center gap-14 px-5 py-16 sm:px-8 sm:py-20 lg:grid-cols-[1.03fr_.97fr] lg:gap-20 lg:py-28">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 rounded-full border border-indigo-100 bg-white/80 px-3.5 py-2 text-xs font-semibold text-indigo-700 shadow-sm">
                        <span class="size-2 rounded-full bg-teal-500"></span>
                        {{ $siteContent['hero_badge'] }}
                    </div>
                    <h1 class="mt-7 text-4xl font-bold leading-[1.45] tracking-tight text-slate-950 sm:text-5xl lg:text-[3.55rem]">
                        {{ $siteContent['hero_title'] }}
                        <span class="text-indigo-600">{{ $siteContent['hero_title_highlight'] }}</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-base leading-8 text-slate-600 sm:text-lg">
                        {{ $siteContent['hero_description'] }}
                    </p>
                    <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                        <a href="#tools" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-6 py-3.5 text-sm font-bold text-white shadow-xl shadow-indigo-600/20 transition hover:-translate-y-0.5 hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200">
                            دیدن محاسبه‌گرها
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                        <a href="#law-library" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white/80 px-6 py-3.5 text-sm font-bold text-slate-700 transition hover:border-indigo-200 hover:bg-white">مرور قوانین کار</a>
                    </div>
                    <div class="mt-9 flex flex-wrap items-center gap-x-6 gap-y-3 text-xs text-slate-500">
                        <span class="inline-flex items-center gap-2"><svg class="size-4 text-teal-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>ابزارهای تخصصی کار و حقوق</span>
                        <span class="inline-flex items-center gap-2"><svg class="size-4 text-teal-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>قوانین و مقررات قابل جست‌وجو</span>
                    </div>
                </div>

                <div class="relative mx-auto w-full max-w-xl">
                    <div class="hero-preview-glow absolute -inset-5 -z-10 rounded-[2.5rem] bg-gradient-to-br from-indigo-200/70 via-white to-teal-100/80 blur-2xl"></div>
                    <div class="hero-preview-card overflow-hidden rounded-[2rem] border border-white bg-white p-4 shadow-2xl shadow-slate-900/10 sm:p-6">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                            <div class="flex items-center gap-3">
                                <span class="grid size-10 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 015.5 4h13A1.5 1.5 0 0120 5.5v13a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5v-13zM8 8h8M8 12h2m4 0h2m-8 4h2m4 0h2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                </span>
                                <div>
                                    <div class="text-sm font-bold text-slate-800">ابزارهای کاربردی</div>
                                    <div class="mt-0.5 text-xs text-slate-500">همه‌چیز مرتب و یک‌جا</div>
                                </div>
                            </div>
                            <span class="hero-version-badge rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-semibold text-emerald-700">اپلیکیشن نسخهٔ ۱.۶.۳</span>
                        </div>

                        <div class="hero-category-panel mt-5 rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-700 p-5 text-white sm:p-6">
                            <div class="hero-category-caption text-xs text-indigo-100">دسته‌بندی صفحهٔ اصلی اپلیکیشن</div>
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                @foreach (['همه', 'اقلام مزدی مستمر', 'اقلام مزدی غیرمستمر', 'اقلام غیر مزدی', 'اطلاعات سال کارکرد', 'قوانین و مقررات'] as $category)
                                    <div class="hero-category-item rounded-xl border border-white/15 bg-white/10 px-3 py-3 text-xs font-semibold leading-5">{{ $category }}</div>
                                @endforeach
                            </div>
                            <p class="hero-category-note mt-4 text-xs leading-6 text-indigo-100">فهرست ابزارها و دسته‌بندی‌های این صفحه بر اساس اپلیکیشن است.</p>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div class="hero-tool-card rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
                                <div class="hero-tool-title text-xs font-bold text-slate-800">حقوق پایه</div>
                                <div class="hero-tool-description mt-1 text-[11px] leading-5 text-slate-500">محاسبه حقوق پایه و نمایش جزئیات آن</div>
                            </div>
                            <div class="hero-tool-card rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
                                <div class="hero-tool-title text-xs font-bold text-slate-800">پایه سنوات استحقاقی</div>
                                <div class="hero-tool-description mt-1 text-[11px] leading-5 text-slate-500">محاسبه پایه سنوات بر اساس تاریخ استخدام، تصفیه‌حساب تا پایان ۱۳۹۱ و طرح طبقه‌بندی مشاغل</div>
                            </div>
                        </div>
                    </div>
                    <div class="hero-preview-callout absolute -bottom-5 -left-3 hidden items-center gap-3 rounded-2xl border border-white bg-white px-4 py-3 shadow-xl shadow-slate-900/10 sm:flex">
                        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-teal-600">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="text-xs font-semibold text-slate-700">اطلاعات متمرکز، دسترسی ساده</span>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="scroll-mt-24 border-y border-slate-100 bg-white py-20 sm:py-24">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="text-xs font-bold tracking-wide text-indigo-600">اپلیکیشن {{ $siteContent['brand_name'] }} · نسخهٔ ۱.۶.۳</span>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">محاسبهٔ مزایا و دسترسی به منابع حقوق کار</h2>
                    <p class="mt-4 text-sm leading-8 text-slate-600 sm:text-base">در اپلیکیشن، ۳۰ ابزار را در دسته‌های مشخص پیدا کنید و برای مرور اسناد حقوقی یا یافتن عبارت موردنظر از کتابخانه و جست‌وجو کمک بگیرید.</p>
                </div>

                <div class="mt-12 grid gap-5 md:grid-cols-2">
                    <article class="rounded-3xl border border-slate-200/80 bg-slate-50/60 p-6 sm:p-7">
                        <span class="grid size-12 place-items-center rounded-2xl bg-indigo-100 text-indigo-700">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19.5h16M6.5 16V9m5.5 7V4.5m5.5 11.5v-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="6.5" cy="6" r="1.5" stroke="currentColor" stroke-width="1.5"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-bold text-slate-900">حقوق و مزایای مستمر</h3>
                        <p class="mt-2 text-sm leading-7 text-slate-600">مبالغی را که معمولاً در محاسبهٔ دریافتی ماهانه مطرح‌اند، جداگانه بررسی کنید؛ از حقوق پایه و پایه سنوات تا حق مسکن، بن کارگری، حق تأهل و حق عائله‌مندی.</p>
                        <a href="#tools" class="mt-5 inline-flex items-center gap-2 text-sm font-bold text-indigo-700 transition hover:text-indigo-900">
                            دیدن فهرست محاسبه‌گرها
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </article>
                    <article class="rounded-3xl border border-slate-200/80 bg-slate-50/60 p-6 sm:p-7">
                        <span class="grid size-12 place-items-center rounded-2xl bg-teal-100 text-teal-700">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-bold text-slate-900">کارکرد، پرداخت‌های متغیر و مزایا</h3>
                        <p class="mt-2 text-sm leading-7 text-slate-600">اضافه‌کاری، شب‌کاری، نوبت‌کاری، جمعه‌کاری، عیدی، سنوات پایان کار و مزد مرخصی را در کنار محاسبهٔ ساعت کار موظفی، تعطیلات رسمی، روزهای بیمه و مدت مقرری بیمهٔ بیکاری بررسی کنید.</p>
                        <a href="#tools" class="mt-5 inline-flex items-center gap-2 text-sm font-bold text-indigo-700 transition hover:text-indigo-900">
                            دیدن دسته‌بندی ابزارها
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </article>
                    <article class="rounded-3xl border border-slate-200/80 bg-slate-50/60 p-6 sm:p-7">
                        <span class="grid size-12 place-items-center rounded-2xl bg-amber-100 text-amber-700">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 4.5h12A1.5 1.5 0 0119.5 6v13.5h-15V6A1.5 1.5 0 016 4.5zM8 8h8m-8 4h2m3 0h3m-8 4h2m3 0h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-bold text-slate-900">کتابخانه و جست‌وجوی قوانین</h3>
                        <p class="mt-2 text-sm leading-7 text-slate-600">در هشت دسته، از قوانین و آیین‌نامه‌ها تا بخش‌نامه‌ها، دستورالعمل‌ها، تصویب‌نامه‌ها، نظریات مشورتی و آرای هیئت عمومی دیوان عدالت اداری و دیوان عالی کشور، مرور کنید. جست‌وجوی اپ نام قانون، شمارهٔ ماده یا عبارت را می‌پذیرد؛ اسناد نیز بر اساس عنوان، فصل، موضوع، ماده و پانوشت‌های مرتبط قابل پیگیری‌اند.</p>
                        <a href="#law-library" class="mt-5 inline-flex items-center gap-2 text-sm font-bold text-indigo-700 transition hover:text-indigo-900">
                            آشنایی با کتابخانهٔ قوانین
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </article>
                    <article class="rounded-3xl border border-slate-200/80 bg-slate-50/60 p-6 sm:p-7">
                        <span class="grid size-12 place-items-center rounded-2xl bg-violet-100 text-violet-700">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="6" y="3.5" width="12" height="17" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M9 7h6m-6 4h6m-6 4h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-bold text-slate-900">محاسبات جامع و سوابق مزدی</h3>
                        <p class="mt-2 text-sm leading-7 text-slate-600">برای محاسبهٔ چند قلم با هم، موارد لازم را انتخاب کنید و بازهٔ مشترک را یک‌بار وارد کنید. همچنین اطلاعات جامع مزدی و مصوبات شورای عالی کار از سال ۱۳۶۹ تاکنون در ابزارهای اپ ارائه می‌شود.</p>
                        <a href="#tools" class="mt-5 inline-flex items-center gap-2 text-sm font-bold text-indigo-700 transition hover:text-indigo-900">
                            مرور گروه‌های محاسباتی
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </article>
                </div>
                <p class="mx-auto mt-7 max-w-4xl text-center text-xs leading-7 text-slate-500">این صفحه معرفی امکانات است؛ محاسبه‌گرها، جست‌وجو و مرور اسناد در اپلیکیشن انجام می‌شوند. برای اسنادی که متن آزمایشی دارند، در اپ هشدار نمایش داده می‌شود؛ متن آزمایشی را نباید متن رسمی قانون تلقی کرد.</p>
            </div>
        </section>

        <section id="tools" class="scroll-mt-24 bg-slate-50 py-20 sm:py-24">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="mb-10 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div class="max-w-3xl">
                        <span class="text-xs font-bold text-indigo-600">ابزارهایی برای موقعیت‌های واقعی</span>
                        <h2 class="mt-3 text-3xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-4xl">محاسبه را از نقطهٔ درست شروع کنید</h2>
                        <p class="mt-3 text-sm leading-8 text-slate-600 sm:text-base">از برآورد مزایای ماهانه تا بررسی ساعت کار، ابزار مناسب را پیدا کنید و اطلاعات لازم را مرحله‌به‌مرحله وارد کنید.</p>
                    </div>
                    <a href="#plans" class="inline-flex shrink-0 items-center gap-2 text-sm font-bold text-indigo-700 transition hover:text-indigo-900">
                        مشاهدهٔ روش دسترسی
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </div>

                <div class="space-y-8">
                    @php
                        $toolGroups = [
                            [
                                'title' => 'اطلاعات سال کارکرد',
                                'tools' => [
                                    ['title' => 'اطلاعات جامع مزدی از سال ۱۳۶۹ تاکنون', 'description' => 'جزئیاتی جامع از مصوبات شورای عالی کار از سال ۱۳۶۹ تاکنون'],
                                ],
                            ],
                            [
                                'title' => 'اقلام مزدی مستمر',
                                'tools' => [
                                    ['title' => 'حقوق پایه', 'description' => 'محاسبه حقوق پایه و نمایش جزئیات آن'],
                                    ['title' => 'پایه سنوات استحقاقی', 'description' => 'محاسبه پایه سنوات بر اساس تاریخ استخدام، تصفیه حساب تا پایان ۱۳۹۱ و طرح طبقه‌بندی مشاغل'],
                                    ['title' => 'حق مسکن', 'description' => 'محاسبه حق مسکن ماهیانه موضوع مصوبه هیات وزیران به تناسب بازه زمانی انتخابی'],
                                    ['title' => 'بن کارگری', 'description' => 'محاسبه بن کارگری مصوبه شورای عالی کار به تناسب بازه زمانی انتخابی'],
                                    ['title' => 'حق تاهل', 'description' => 'محاسبه حق تاهل براساس تصریح مصوبات شورای عالی کار از سال ۱۴۰۳'],
                                    ['title' => 'حق عائله مندی', 'description' => 'محاسبه حق عائله مندی براساس بند۲ماده ۸۶قانون تامین اجتماعی'],
                                    ['title' => 'مبلغ مقرری بیمه بیکاری', 'description' => 'محاسبه مقرری بیمه بیکاری براساس بند ب ماده ۷ قانون بیمه بیکاری'],
                                ],
                            ],
                            [
                                'title' => 'اقلام مزدی غیرمستمر',
                                'tools' => [
                                    ['title' => 'اضافه کاری', 'description' => 'محاسبه فوق‌العاده اضافه‌کاری براساس شرح ماده ۵۹ قانون کار'],
                                    ['title' => 'شب کاری', 'description' => 'محاسبه فوق‌العاده شب‌کاری براساس شرح ماده ۵۸ قانون کار'],
                                    ['title' => 'نوبت‌کاری', 'description' => 'محاسبه نوبت‌کاری موضوع ماده ۵۵ قانون کار بر اساس ماده ۵۶ قانون کار'],
                                    ['title' => 'حداقل عیدی و پاداش', 'description' => 'محاسبه حداقل عیدی و پاداش ماهیانه براساس ماده واحده قانون تعیین عیدی و پاداش، مصوب مجلس در سال ۱۳۷۰'],
                                    ['title' => 'حداکثر عیدی و پاداش', 'description' => 'محاسبه حداکثر عیدی و پاداش ماهیانه براساس ماده واحده قانون تعیین عیدی و پاداش، مصوب مجلس در سال ۱۳۷۰'],
                                    ['title' => 'عیدی و پاداش استحقاقی', 'description' => 'محاسبه عیدی و پاداش ماهیانه بر اساس ماده واحده قانون تعیین عیدی و پاداش، مصوب مجلس در سال ۱۳۷۰'],
                                    ['title' => 'سنوات پایان کار', 'description' => 'محاسبه سنوات پایان کار براساس ماده ۲۴ قانون کار'],
                                    ['title' => 'مزد مرخصی ذخیره شده کارگر', 'description' => 'محاسبه مزد مرخصی ذخیره شده کارگر بر اساس آخرین ماه کارکرد'],
                                    ['title' => 'مبلغ تعطیل کاری', 'description' => 'محاسبه مبلغ تعطیل‌کاری‌های مندرج در ماده ۶۳ قانون کار بر اساس پایه سنوات هر دوره'],
                                    ['title' => 'حق‌السعی ایام تعلیق', 'description' => 'محاسبه حق‌السعی ایام تعلیق موضوع ماده ۳۴ قانون کار و ماده ۶۷ آیین دادرسی کار'],
                                    ['title' => 'فوق‌العاده مأموریت', 'description' => 'محاسبه حداقل مبلغ فوق‌العاده مأموریت براساس ماده ۴۶ قانون کار'],
                                    ['title' => 'جمعه کاری', 'description' => 'محاسبه مزد جمعه‌کاری‌های انجام‌شده براساس ماده ۶۲ قانون کار'],
                                ],
                            ],
                            [
                                'title' => 'اقلام غیر مزدی',
                                'tools' => [
                                    ['title' => 'میزان مرخصی ذخیره شده کارگر', 'description' => 'محاسبه تعداد مرخصی ذخیره شده کارگر براساس مواد ۶۴ و ۶۹ قانون کار'],
                                    ['title' => 'تعداد روزهای بیمه استحقاقی', 'description' => 'محاسبه تعداد روزهای بیمه موضوع مفاد مواد ۳۹ و ۱۴۸ قانون کار'],
                                    ['title' => 'مدت زمان پرداخت مقرری بیمه بیکاری', 'description' => 'محاسبه مدت زمان استحقاق دریافت مقرری بیمه بیکاری براساس ماده ۷ قانون بیمه بیکاری'],
                                    ['title' => 'کارکرد موظفی کارگر در مشاغل عادی', 'description' => 'محاسبه میزان ساعات کارکرد موظفی کارگر در مشاغل عادی طبق ماده ۵۱ قانون کار'],
                                    ['title' => 'کارکرد موظفی کارگر در مشاغل سخت و زیان‌آور', 'description' => 'تعیین ساعات کارکرد موظفی کارگر طبق ماده ۵۲ قانون کار'],
                                    ['title' => 'کارکرد موظفی کارگر نوجوان', 'description' => 'تعیین ساعات کارکرد کارگر نوجوان طبق ماده ۸۰ قانون کار'],
                                    ['title' => 'تعداد تعطیلات رسمی در بازه زمانی انتخاب شده', 'description' => 'تعیین تعداد روزهای تعطیل موضوع ماده ۶۳ قانون کار بر اساس تقویم رسمی کشور؛ جمعه‌کاری تعطیل‌کاری محسوب نمی‌شود'],
                                    ['title' => 'مبلغ جریمه به‌کارگیری اتباع بیگانه غیرمجاز', 'description' => 'محاسبه جریمه به‌کارگیری اتباع بیگانه غیرمجاز بر اساس تعداد کارگران، روزهای بازه و حداقل مزد همان سال'],
                                    ['title' => 'مبلغ اعمال ماده ۸۷ قانون کار', 'description' => 'محاسبه مبلغ اعمال ماده ۸۷ قانون کار برای صدور پروانه کسب یا بهره‌برداری بر اساس متراژ زیربنا'],
                                    ['title' => 'سقف حق بیمه تامین اجتماعی', 'description' => 'محاسبه سقف حق بیمه براساس حداقل مزد مصوب شورای عالی کار و تعداد روزهای ماه انتخابی'],
                                ],
                            ],
                        ];
                    @endphp
                    @foreach ($toolGroups as $group)
                        <section aria-label="{{ $group['title'] }}">
                            <h3 class="mb-3 text-lg font-bold text-slate-900">{{ $group['title'] }}</h3>
                            <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm shadow-slate-900/[0.02] divide-y divide-slate-100">
                                @foreach ($group['tools'] as $tool)
                                    <article class="flex items-start gap-3 px-4 py-4 sm:gap-4 sm:px-5">
                                        <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-700" aria-hidden="true">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none"><path d="M8 5h8m-8 4h8m-8 4h3m-6 7h14a2 2 0 002-2V4a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <h4 class="text-sm font-bold leading-6 text-slate-900">{{ $tool['title'] }}</h4>
                                            <p class="mt-0.5 text-xs leading-6 text-slate-500">{{ $tool['description'] }}</p>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                    <section aria-label="محاسبات جامع">
                        <h3 class="mb-3 text-lg font-bold text-slate-900">محاسبات جامع</h3>
                        <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm shadow-slate-900/[0.02]">
                            <article class="flex items-start gap-3 px-4 py-4 sm:gap-4 sm:px-5">
                                <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-700" aria-hidden="true">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none"><path d="M8 5h8m-8 4h8m-8 4h3m-6 7h14a2 2 0 002-2V4a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <div class="min-w-0">
                                    <h4 class="text-sm font-bold leading-6 text-slate-900">محاسبات جامع</h4>
                                    <p class="mt-0.5 text-xs leading-6 text-slate-500">موارد موردنیاز را انتخاب کنید و بازهٔ مشترک را یک‌بار وارد کنید.</p>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>
            </div>
        </section>

        <section id="law-library" class="scroll-mt-24 overflow-hidden bg-slate-950 py-20 text-white sm:py-24">
            <div class="mx-auto grid max-w-7xl items-center gap-12 px-5 sm:px-8 lg:grid-cols-2">
                <div>
                    <span class="text-xs font-bold text-teal-300">قوانین و مقررات در اپلیکیشن</span>
                    <h2 class="mt-3 text-3xl font-bold leading-[1.5] tracking-tight sm:text-4xl">منابع حقوق کار را منظم‌تر پیدا کنید</h2>
                    <p class="mt-5 text-sm leading-8 text-slate-300 sm:text-base">کتابخانهٔ اپلیکیشن اسناد را در دسته‌های مشخص ارائه می‌کند و مسیر مرور آن از عنوان و فصل تا موضوع و ماده ادامه دارد. برای یافتن مطلب هم می‌توانید در خود اپ نام سند، شمارهٔ ماده یا عبارت موردنظر را جست‌وجو کنید.</p>
                    <ul class="mt-7 space-y-4 text-sm text-slate-200">
                        <li class="flex items-start gap-3"><span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-teal-400/15 text-teal-300" aria-hidden="true">✓</span><span>هشت دستهٔ سند حقوقی، از قوانین و آیین‌نامه‌ها تا نظریات مشورتی و آرای دو دیوان</span></li>
                        <li class="flex items-start gap-3"><span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-teal-400/15 text-teal-300" aria-hidden="true">✓</span><span>جست‌وجوی درون اپ با نام سند، شمارهٔ ماده یا عبارت</span></li>
                        <li class="flex items-start gap-3"><span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-teal-400/15 text-teal-300" aria-hidden="true">✓</span><span>مرور ساختار اسناد و مشاهدهٔ پانوشت‌های مرتبط با مواد</span></li>
                    </ul>
                    <p class="mt-7 text-xs leading-7 text-slate-400">جست‌وجو و مرور اسناد در اپلیکیشن انجام می‌شود؛ فهرست کنار این توضیح فقط پیش‌نمایش دسته‌هاست و در این وب‌سایت قابلیت کلیک یا جست‌وجو ندارد.</p>
                </div>

                <div class="relative">
                    <div class="absolute -inset-8 rounded-full bg-teal-500/10 blur-3xl" aria-hidden="true"></div>
                    <div class="relative rounded-[2rem] border border-white/10 bg-white/[0.06] p-5 shadow-2xl backdrop-blur sm:p-7">
                        <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-5">
                            <div class="flex items-center gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-teal-400/10 text-teal-300" aria-hidden="true">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none"><path d="M3.5 7A2.5 2.5 0 016 4.5h4l2 2h6A2.5 2.5 0 0120.5 9v9a2.5 2.5 0 01-2.5 2.5H6A2.5 2.5 0 013.5 18V7zM8 11h8m-8 4h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-white">دسته‌های کتابخانه</h3>
                                    <p class="mt-1 text-xs text-slate-400">پیش‌نمایش فهرست اپلیکیشن</p>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full border border-white/10 bg-white/[0.06] px-3 py-1.5 text-xs font-semibold text-slate-300">۸ دسته</span>
                        </div>
                        <ul class="mt-5 grid gap-2 sm:grid-cols-2">
                            @foreach ([
                                'قوانین',
                                'آیین‌نامه‌ها',
                                'بخش‌نامه‌ها',
                                'دستورالعمل‌ها',
                                'تصویب‌نامه‌ها',
                                'نظریات مشورتی',
                                'آرای هیئت عمومی دیوان عدالت اداری',
                                'آرای هیئت عمومی دیوان عالی کشور',
                            ] as $index => $item)
                                <li class="flex min-h-14 items-center gap-3 rounded-xl border border-white/[0.08] bg-white/[0.04] px-3 py-3">
                                    <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-teal-400/10 text-xs font-bold text-teal-200" aria-hidden="true">{{ $index + 1 }}</span>
                                    <span class="text-xs font-medium leading-6 text-slate-200 sm:text-[13px]">{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-4 rounded-xl border border-amber-200/15 bg-amber-200/[0.06] px-4 py-3 text-xs leading-6 text-slate-300">
                            برخی اسناد اپ متن آزمایشی دارند. هشدار اپ تصریح می‌کند: «متن‌های این بخش آزمایشی هستند و متن رسمی قوانین نیستند.»
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section id="plans" class="scroll-mt-24 bg-white py-20 sm:py-24">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="text-xs font-bold text-indigo-600">دسترسی متناسب با نیاز شما</span>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">چهار سطح دسترسی، متناسب با نیاز شما</h2>
                    <p class="mt-4 text-sm leading-7 text-slate-600">پلن رایگان و پلن‌های برنزی، نقره‌ای و طلایی با بازه‌های اشتراک یک‌ماهه تا یک‌ساله.</p>
                </div>
                @php
                    $toPersianDigits = fn ($value) => strtr(number_format((int) $value), [
                        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
                    ]);
                    $tierStyles = [
                        'free' => ['card' => 'border-emerald-200 bg-emerald-50/70', 'title' => 'text-emerald-900', 'check' => 'text-emerald-600'],
                        'bronze' => ['card' => 'border-orange-300 bg-orange-50/70', 'title' => 'text-orange-950', 'check' => 'text-orange-700'],
                        'silver' => ['card' => 'border-slate-300 bg-slate-100/80', 'title' => 'text-slate-800', 'check' => 'text-slate-600'],
                        'gold' => ['card' => 'border-amber-300 bg-amber-50/70', 'title' => 'text-amber-950', 'check' => 'text-amber-700'],
                    ];
                @endphp
                <div class="mx-auto mt-12 grid max-w-7xl gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($plans['tiers'] as $tierKey => $tier)
                        @php $style = $tierStyles[$tierKey] ?? $tierStyles['free']; @endphp
                        <article class="plan-tier-card plan-tier-card--{{ $tierKey }} rounded-3xl border-2 {{ $style['card'] }} p-6 shadow-sm">
                            <h3 class="plan-tier-title text-base font-bold {{ $style['title'] }}">{{ $tier['name'] }}</h3>
                            @if ($tierKey === 'free')
                                <p class="mt-2 text-xs leading-6 text-slate-500">دسترسی رایگان با امکانات انتخاب‌شده برای این پلن.</p>
                                <div class="mt-6 text-2xl font-bold text-slate-950">رایگان</div>
                            @else
                                <p class="mt-2 text-xs leading-6 text-slate-500">انتخاب بازهٔ اشتراک و امکانات متناسب با پلن.</p>
                                <ul class="mt-5 space-y-3 border-t border-slate-100 pt-4">
                                    @foreach ($plans['durations'] as $durationKey => $duration)
                                        @php $offer = $plans['offers'][$tierKey.'_'.$durationKey]; @endphp
                                        <li class="flex items-center justify-between gap-2 text-xs">
                                            <span class="font-semibold text-slate-700">{{ $duration['name'] }}</span>
                                            @if ($offer['price'] > 0)
                                                <span class="font-bold text-slate-900">{{ $toPersianDigits($offer['price']) }} تومان</span>
                                            @else
                                                <span class="text-slate-400">قیمت به‌زودی</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @php
                                $enabledFeatures = collect($plans['features'])
                                    ->filter(fn (array $feature, string $featureKey): bool => (bool) ($tier['features'][$featureKey] ?? false));
                            @endphp
                            <div class="mt-5 border-t border-slate-100 pt-4">
                                <h4 class="text-xs font-bold text-slate-800">امکانات این پلن</h4>
                                @if ($enabledFeatures->isNotEmpty())
                                    <ul class="mt-3 space-y-2.5">
                                        @foreach ($enabledFeatures as $feature)
                                            <li class="flex items-start gap-2 text-xs leading-6 text-slate-600">
                                                <svg class="plan-tier-check mt-1 size-4 shrink-0 {{ $style['check'] }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                    <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                                <span>{{ $feature['label'] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-2 text-xs leading-6 text-slate-500">هنوز امکانی برای این پلن فعال نشده است.</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <p class="mx-auto mt-6 max-w-3xl text-center text-xs leading-6 text-slate-500">برای پلن‌هایی که قیمت آن‌ها تعیین نشده، خرید غیرفعال است. تنظیم دسترسی و قیمت‌ها از پنل مدیریت انجام می‌شود.</p>
            </div>
        </section>

        <section class="bg-indigo-50/70 py-16">
            <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-7 px-5 text-center sm:px-8 md:flex-row md:text-right">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">برای کارهای روزمره، آماده‌تر باشید</h2>
                    <p class="mt-3 text-sm leading-7 text-slate-600">محاسبه‌گرها و محتوای قانونی را در اپلیکیشن {{ $siteContent['brand_name'] }} ببینید.</p>
                </div>
                <a href="#features" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700">
                    آشنایی با امکانات
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>
        </section>
    </main>

    <footer class="site-footer overflow-hidden text-slate-300">
        <div class="h-1 bg-gradient-to-l from-emerald-400 via-indigo-500 to-amber-400"></div>
        <div class="mx-auto grid max-w-7xl gap-10 px-5 py-12 sm:px-8 sm:py-14 md:grid-cols-2 lg:grid-cols-[1.4fr_.8fr_.8fr] lg:gap-16">
            <div>
                <a href="#home" class="inline-flex items-center gap-3 rounded-2xl focus:outline-none focus:ring-4 focus:ring-indigo-400/30">
                    <img src="{{ asset('favicon.png') }}?v=3" alt="" aria-hidden="true" class="size-12 rounded-2xl object-cover ring-1 ring-white/15">
                    <span>
                        <span class="block text-base font-bold text-white">{{ $siteContent['brand_name'] }}</span>
                        <span class="mt-1 block text-xs text-slate-400">{{ $siteContent['tagline'] }}</span>
                    </span>
                </a>
                <p class="mt-5 max-w-md text-sm leading-7 text-slate-400">{{ $siteContent['meta_description'] }}</p>
                <a href="#home" class="mt-5 inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-xs font-semibold text-slate-200 transition hover:border-indigo-400/50 hover:bg-white/10 hover:text-white">
                    بازگشت به بالای صفحه
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 19V5m0 0L6 11m6-6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>

            <nav aria-label="پیوندهای امکانات" class="lg:pt-2">
                <h2 class="text-sm font-bold text-white">امکانات</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    <li><a class="transition hover:text-white" href="#features">معرفی امکانات</a></li>
                    <li><a class="transition hover:text-white" href="#tools">محاسبه‌گرها</a></li>
                    <li><a class="transition hover:text-white" href="#law-library">قوانین و مقررات کار</a></li>
                    <li><a class="transition hover:text-white" href="#plans">پلن‌ها و اشتراک</a></li>
                </ul>
            </nav>

            <div class="lg:pt-2">
                <h2 class="text-sm font-bold text-white">دربارهٔ محاسبات</h2>
                <p class="mt-4 text-sm leading-7 text-slate-400">اطلاعات و محاسبات برای آگاهی عمومی ارائه می‌شوند و جایگزین مشاورهٔ تخصصی حقوقی یا بررسی شرایط دقیق هر پرونده نیستند.</p>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-7xl flex-col gap-2 px-5 py-5 text-xs text-slate-500 sm:px-8 md:flex-row md:items-center md:justify-between">
                <p>© {{ now()->year }} {{ $siteContent['brand_name'] }}؛ همهٔ حقوق محفوظ است.</p>
                <p>ساخته‌شده برای دسترسی روشن‌تر به محاسبات و اطلاعات کاری</p>
            </div>
        </div>
    </footer>
</body>
</html>

@extends('layouts.public', [
    'pageTitle' => $siteContent['brand_name'].' | '.$siteContent['tagline'],
    'pageDescription' => $siteContent['meta_description'],
    'canonicalUrl' => route('home'),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section id="home" class="home-hero relative isolate overflow-hidden scroll-mt-24">
            <div class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[28rem] bg-[radial-gradient(ellipse_at_top,rgba(0,104,120,.12),transparent_68%)]"></div>
            <div class="mx-auto max-w-6xl px-5 pb-12 pt-10 sm:px-8 sm:pb-14 sm:pt-16 lg:pb-14 lg:pt-16">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="mb-3 text-base font-semibold tracking-wide text-indigo-700 sm:text-lg">{{ $siteContent['brand_name'] }}</p>
                    <h1 class="text-4xl font-bold leading-[1.45] tracking-tight text-slate-950 sm:text-5xl lg:text-6xl">
                        {{ $siteContent['hero_title'] }}
                        <span class="text-indigo-600">{{ $siteContent['hero_title_highlight'] }}</span>
                    </h1>
                    <div class="mx-auto mt-8 w-full max-w-4xl">
                        <div class="text-center">
                            <span class="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600">دانلود</span>
                            <p class="mt-4 text-base font-semibold leading-8 text-slate-800 sm:text-lg">دوست دارید از کجا شروع کنید؟</p>
                        </div>
                        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="دریافت و استفاده از پهناورکار">
                            <article class="flex flex-col rounded-2xl border border-slate-200 bg-white/80 p-5 text-right shadow-sm sm:p-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-700" aria-hidden="true">
                                        <i class="fa-brands fa-android text-xl" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-900">اپلیکیشن اندروید</h2>
                                        <p class="mt-1 text-xs text-slate-500">برای گوشی‌ها و تبلت‌های اندرویدی</p>
                                    </div>
                                </div>
                                <p class="mt-4 flex-1 text-sm leading-7 text-slate-600">ابزارهای حقوق و دستمزد و کتابخانهٔ قوانین را روی دستگاه اندرویدی دنبال کنید.</p>
                                <div id="android-download-status" role="status" class="mt-5 flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-600">
                                    <span class="size-2 rounded-full bg-amber-500" aria-hidden="true"></span>
                                    لینک دریافت هنوز منتشر نشده است
                                </div>
                            </article>

                            <article class="flex flex-col rounded-2xl border border-slate-200 bg-white/80 p-5 text-right shadow-sm sm:p-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-700" aria-hidden="true">
                                        <i class="fa-brands fa-apple text-xl" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-900">نسخهٔ آیفون</h2>
                                        <p class="mt-1 text-xs text-slate-500">برای گوشی‌ها و تبلت‌های اپل</p>
                                    </div>
                                </div>
                                <p class="mt-4 flex-1 text-sm leading-7 text-slate-600">وضعیت انتشار نسخهٔ پهناورکار برای iPhone و iPad از همین‌جا اعلام می‌شود.</p>
                                <div id="ios-download-status" role="status" class="mt-5 flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">
                                    <span class="size-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                                    لینک دریافت اعلام نشده است
                                </div>
                            </article>

                            <article class="flex flex-col rounded-2xl border border-slate-200 bg-white/80 p-5 text-right shadow-sm sm:p-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-700" aria-hidden="true">
                                        <i class="fa-solid fa-globe text-xl" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-900">نسخهٔ وب اپلیکیشن</h2>
                                        <p class="mt-1 text-xs text-slate-500">استفاده از اپلیکیشن در مرورگر</p>
                                    </div>
                                </div>
                                <p class="mt-4 flex-1 text-sm leading-7 text-slate-600">نسخهٔ وب اپلیکیشن هنوز در دسترس نیست؛ این وب‌سایت فعلاً برای معرفی امکانات و نمایش اطلاعات است.</p>
                                <div id="web-app-status" role="status" class="mt-5 flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">
                                    <span class="size-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                                    هنوز منتشر نشده است
                                </div>
                            </article>

                            <article class="flex flex-col rounded-2xl border border-slate-200 bg-white/80 p-5 text-right shadow-sm sm:p-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700" aria-hidden="true">
                                        <i class="fa-brands fa-windows text-xl" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-900">نسخهٔ ویندوز</h2>
                                        <p class="mt-1 text-xs text-slate-500">برای رایانه‌های ویندوزی</p>
                                    </div>
                                </div>
                                <p class="mt-4 flex-1 text-sm leading-7 text-slate-600">وضعیت انتشار نسخهٔ دسکتاپ پهناورکار برای Windows از همین‌جا اعلام می‌شود.</p>
                                <div id="windows-download-status" role="status" class="mt-5 flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">
                                    <span class="size-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                                    لینک دریافت اعلام نشده است
                                </div>
                            </article>

                            <article class="flex flex-col rounded-2xl border border-slate-200 bg-white/80 p-5 text-right shadow-sm sm:p-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-700" aria-hidden="true">
                                        <i class="fa-solid fa-laptop text-xl" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-900">نسخهٔ مک</h2>
                                        <p class="mt-1 text-xs text-slate-500">برای MacBook و iMac</p>
                                    </div>
                                </div>
                                <p class="mt-4 flex-1 text-sm leading-7 text-slate-600">وضعیت انتشار نسخهٔ پهناورکار برای macOS از همین‌جا اعلام می‌شود.</p>
                                <div id="mac-download-status" role="status" class="mt-5 flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">
                                    <span class="size-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                                    لینک دریافت اعلام نشده است
                                </div>
                            </article>

                            <article class="flex flex-col rounded-2xl border border-slate-200 bg-white/80 p-5 text-right shadow-sm sm:p-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700" aria-hidden="true">
                                        <i class="fa-brands fa-linux text-xl" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-900">نسخهٔ لینوکس</h2>
                                        <p class="mt-1 text-xs text-slate-500">برای رایانه‌های لینوکسی</p>
                                    </div>
                                </div>
                                <p class="mt-4 flex-1 text-sm leading-7 text-slate-600">وضعیت انتشار نسخهٔ پهناورکار برای Linux از همین‌جا اعلام می‌شود.</p>
                                <div id="linux-download-status" role="status" class="mt-5 flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">
                                    <span class="size-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                                    لینک دریافت اعلام نشده است
                                </div>
                            </article>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="scroll-mt-24 border-y border-slate-100 bg-white py-20 sm:py-24">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600">امکانات</span>
                    <p class="mt-4 text-base font-semibold leading-8 text-slate-800 sm:text-lg">دنبال چه چیزی هستید؟</p>
                </div>

                <div class="mx-auto mt-8 grid max-w-5xl gap-5 md:grid-cols-2">
                    <article data-feature-tile class="group relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 text-right transition duration-200 hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-950/[0.06] sm:p-8">
                        <div class="pointer-events-none absolute -left-12 -top-14 size-44 rounded-full bg-indigo-50/80 blur-2xl transition group-hover:bg-indigo-100/80" aria-hidden="true"></div>
                        <div class="relative">
                            <span class="grid size-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-700" aria-hidden="true">
                                <i class="fa-solid fa-chart-column text-2xl" aria-hidden="true"></i>
                            </span>
                            <h3 class="mt-7 text-xl font-bold text-slate-900 sm:text-2xl">ابزارهای محاسباتی</h3>
                            <p class="mt-3 min-h-20 text-sm leading-7 text-slate-600">از حقوق پایه و مزایا تا اضافه‌کاری، عیدی و سنوات؛ فهرست ابزارها را بر اساس موضوع مرور کنید و کاربرد هرکدام را بشناسید.</p>
                            <a href="{{ route('tools.index') }}" class="mt-5 inline-flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm font-bold text-indigo-800 transition hover:bg-indigo-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20">
                                دیدن فهرست ابزارها
                                <i class="fa-solid fa-arrow-left size-4 shrink-0 transition-transform group-hover:-translate-x-1" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                    <article data-feature-tile class="group relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 text-right transition duration-200 hover:-translate-y-1 hover:border-teal-200 hover:shadow-xl hover:shadow-teal-950/[0.06] sm:p-8">
                        <div class="pointer-events-none absolute -left-12 -top-14 size-44 rounded-full bg-teal-50/80 blur-2xl transition group-hover:bg-teal-100/80" aria-hidden="true"></div>
                        <div class="relative">
                            <span class="grid size-14 place-items-center rounded-2xl bg-teal-50 text-teal-700" aria-hidden="true">
                                <i class="fa-solid fa-scale-balanced text-2xl" aria-hidden="true"></i>
                            </span>
                            <h3 class="mt-7 text-xl font-bold text-slate-900 sm:text-2xl">قوانین و مقررات</h3>
                            <p class="mt-3 min-h-20 text-sm leading-7 text-slate-600">دسته‌های قوانین و اسناد کار را اینجا مرور کنید؛ برای جست‌وجو و خواندن متن اسناد، به کتابخانهٔ اپلیکیشن بروید.</p>
                            <a href="{{ route('laws.index') }}" class="feature-tile-cta-legal mt-5 inline-flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-teal-50 px-4 py-3 text-sm font-bold text-teal-800 transition hover:bg-teal-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-teal-500/20">
                                دیدن دسته‌های قوانین
                                <i class="fa-solid fa-arrow-left size-4 shrink-0 transition-transform group-hover:-translate-x-1" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section id="plans" class="scroll-mt-24 bg-white py-20 sm:py-24">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600">پلن‌ها و اشتراک</span>
                    <p class="mt-4 text-base font-semibold leading-8 text-slate-800 sm:text-lg">امکانات و قیمت‌ها را مقایسه کنید و ببینید کدام پلن به کارتان می‌آید.</p>
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
                <div class="mx-auto mt-8 grid max-w-7xl gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($plans['tiers'] as $tierKey => $tier)
                        @php $style = $tierStyles[$tierKey] ?? $tierStyles['free']; @endphp
                        <article class="plan-tier-card plan-tier-card--{{ $tierKey }} rounded-3xl border-2 {{ $style['card'] }} p-6 shadow-sm">
                            <h3 class="plan-tier-title text-base font-bold {{ $style['title'] }}">{{ $tier['name'] }}</h3>
                            @if ($tierKey === 'free')
                                <p class="mt-2 text-xs leading-6 text-slate-500">امکاناتی که در پلن رایگان در دسترس‌اند.</p>
                                <div class="mt-6 text-2xl font-bold text-slate-950">رایگان</div>
                            @else
                                <p class="mt-2 text-xs leading-6 text-slate-500">قیمت هر بازه و امکانات این پلن را مقایسه کنید.</p>
                                <ul class="mt-5 space-y-3 border-t border-slate-100 pt-4">
                                    @foreach ($plans['durations'] as $durationKey => $duration)
                                        @php $offer = $plans['offers'][$tierKey.'_'.$durationKey]; @endphp
                                        <li class="flex items-center justify-between gap-2 text-xs">
                                            <span class="font-semibold text-slate-700">{{ $duration['name'] }}</span>
                                            @if ($offer['price'] > 0)
                                                <span class="font-bold text-slate-900">{{ $toPersianDigits($offer['price']) }} تومان</span>
                                            @else
                                                <span class="text-slate-400">هنوز اعلام نشده</span>
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
                                <h4 class="text-xs font-bold text-slate-800">امکانات فعال</h4>
                                @if ($enabledFeatures->isNotEmpty())
                                    <ul class="mt-3 space-y-2.5">
                                        @foreach ($enabledFeatures as $feature)
                                            <li class="flex items-start gap-2 text-xs leading-6 text-slate-600">
                                                <i class="plan-tier-check fa-solid fa-check mt-1 size-4 shrink-0 {{ $style['check'] }}" aria-hidden="true"></i>
                                                <span>{{ $feature['label'] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-2 text-xs leading-6 text-slate-500">امکانی برای این پلن تعریف نشده است.</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        @include('partials.site-faq', ['faqs' => $faqs])
    </main>
@endsection

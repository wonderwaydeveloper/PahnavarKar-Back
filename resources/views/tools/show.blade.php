@extends('layouts.public', [
    'pageTitle' => $tool['label'].' | '.$siteContent['brand_name'],
    'pageDescription' => $tool['description'],
    'canonicalUrl' => route('tools.show', $toolKey),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section class="border-b border-slate-200 bg-white py-14 sm:py-20">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <nav aria-label="مسیر صفحه" class="text-xs text-slate-500">
                    <a href="{{ route('home') }}" class="transition hover:text-indigo-700">صفحهٔ اصلی</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <a href="{{ route('tools.index') }}" class="transition hover:text-indigo-700">فهرست ابزارها</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span aria-current="page">{{ $tool['label'] }}</span>
                </nav>

                <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                    <div class="max-w-3xl">
                        <span class="text-xs font-bold text-indigo-600">{{ $tool['group'] }}</span>
                        <h1 class="mt-3 text-3xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-5xl">{{ $tool['label'] }}</h1>
                        <p class="mt-5 text-base leading-8 text-slate-600">{{ $tool['description'] }}</p>
                    </div>

                    <aside class="rounded-3xl border border-slate-200 bg-slate-50 p-5 sm:p-6" aria-labelledby="tool-access-heading">
                        <h2 id="tool-access-heading" class="text-sm font-bold text-slate-900">وضعیت دسترسی در پلن‌ها</h2>
                        @if ($enabledTiers->isNotEmpty())
                            <p class="mt-2 text-xs leading-6 text-slate-600">این قابلیت در تنظیمات فعلی برای این سطح‌ها فعال است:</p>
                            <ul class="mt-3 flex flex-wrap gap-2">
                                @foreach ($enabledTiers as $tier)
                                    <li class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $tier['name'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-2 text-xs leading-7 text-slate-600">در تنظیمات فعلی، این قابلیت برای هیچ‌یک از سطح‌های دسترسی فعال نشده است.</p>
                        @endif
                        <a href="{{ route('home') }}#plans" class="mt-5 inline-flex items-center gap-2 text-sm font-bold text-indigo-700 transition hover:text-indigo-900">
                            مشاهدهٔ پلن‌ها
                            <i class="fa-solid fa-arrow-left size-4" aria-hidden="true"></i>
                        </a>
                    </aside>
                </div>
            </div>
        </section>

        <section class="py-12 sm:py-16" aria-labelledby="tool-details-heading">
            <div class="mx-auto max-w-4xl px-5 sm:px-8">
                <h2 id="tool-details-heading" class="text-xl font-bold text-slate-950 sm:text-2xl">دربارهٔ این قابلیت</h2>
                <div class="public-page-callout mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/70 px-5 py-5 text-sm leading-8 text-indigo-950">
                    این صفحه برای معرفی قابلیت است. استفاده از ابزار و انجام محاسبه در اپلیکیشن پهناورکار انجام می‌شود؛ در این وب‌سایت فرم یا محاسبه‌گر فعالی ارائه نشده است.
                </div>
                <p class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-5 text-sm leading-8 text-amber-950">
                    نتیجهٔ ابزارها برای آگاهی عمومی است و جایگزین مشاورهٔ تخصصی حقوقی یا بررسی شرایط دقیق هر پرونده نیست.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('tools.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">بازگشت به فهرست ابزارها</a>
                    <a href="{{ route('faq.index') }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-indigo-700">پرسش‌های پرتکرار</a>
                </div>
            </div>
        </section>
    </main>
@endsection

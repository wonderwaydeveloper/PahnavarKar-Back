@extends('layouts.public', [
    'pageTitle' => 'درباره ما | '.$siteContent['brand_name'],
    'pageDescription' => 'پهناورکار برای دسترسی ساده‌تر به ابزارهای حقوق و دستمزد و منابع قوانین کار.',
    'canonicalUrl' => route('about'),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section class="border-b border-slate-200 bg-white py-14 sm:py-20">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <nav aria-label="مسیر صفحه" class="text-xs text-slate-500">
                    <a href="{{ route('home') }}" class="transition hover:text-indigo-700">صفحهٔ اصلی</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span aria-current="page">درباره ما</span>
                </nav>
                <div class="mt-7 max-w-3xl">
                    <span class="text-xs font-bold text-indigo-600">{{ $siteContent['hero_badge'] }}</span>
                    <h1 class="mt-3 text-3xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-5xl">درباره ما</h1>
                    <p class="mt-4 text-sm leading-8 text-slate-600 sm:text-base">می‌خواهیم ابزارهای حقوق و دستمزد و منابع قوانین کار را ساده‌تر در دسترس شما بگذاریم.</p>
                </div>
            </div>
        </section>

        <section class="py-12 sm:py-16" aria-labelledby="about-purpose-heading">
            <div class="mx-auto max-w-4xl px-5 sm:px-8">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-9">
                    <h2 id="about-purpose-heading" class="text-xl font-bold text-slate-900 sm:text-2xl">چرا پهناورکار؟</h2>
                    <p class="mt-4 text-sm leading-8 text-slate-600 sm:text-base">
                        پهناورکار کمک می‌کند ابزارهای محاسبهٔ حقوق و دستمزد و منابع قوانین کار را راحت‌تر پیدا و دنبال کنید.
                    </p>
                    <p class="mt-4 text-sm leading-8 text-slate-600 sm:text-base">
                        این وب‌سایت برای آشنایی با اپلیکیشن است؛ محاسبه‌گرها و جست‌وجوی کتابخانهٔ قوانین در اپلیکیشن در دسترس‌اند. اطلاعات عمومی و نتیجهٔ محاسبه جای بررسی شرایط شخصی یا مشاورهٔ تخصصی حقوقی را نمی‌گیرد.
                    </p>
                    <div class="mt-7 flex flex-wrap gap-3">
                        <a href="{{ route('home') }}#features" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-indigo-700">دیدن امکانات</a>
                        <a href="{{ route('tools.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">دیدن فهرست ابزارها</a>
                    </div>
                </article>
            </div>
        </section>
    </main>
@endsection

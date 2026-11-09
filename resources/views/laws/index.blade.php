@extends('layouts.public', [
    'pageTitle' => 'قوانین و مقررات | '.$siteContent['brand_name'],
    'pageDescription' => 'دسته‌های قوانین و اسناد کار را پیدا کنید؛ جست‌وجو و مشاهدهٔ متن اسناد در اپلیکیشن پهناورکار انجام می‌شود.',
    'canonicalUrl' => route('laws.index'),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section class="border-b border-slate-200 bg-white py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <nav aria-label="مسیر صفحه" class="text-xs text-slate-500">
                    <a href="{{ route('home') }}" class="transition hover:text-indigo-700">صفحهٔ اصلی</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span aria-current="page">قوانین و مقررات</span>
                </nav>
                <div class="mt-7 max-w-3xl">
                    <span class="text-xs font-bold text-indigo-600">کتابخانهٔ قوانین پهناورکار</span>
                    <h1 class="mt-3 text-3xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-5xl">قوانین و مقررات</h1>
                    <p class="mt-4 text-sm leading-8 text-slate-600 sm:text-base">دنبال قانون یا آیین‌نامهٔ خاصی هستید؟ دسته‌های اسناد را از این فهرست پیدا کنید.</p>
                </div>
                <div class="public-page-callout mt-7 rounded-2xl border border-indigo-100 bg-indigo-50/70 px-5 py-4 text-sm leading-7 text-indigo-950">
                    برای جست‌وجو و خواندن متن اسناد، وارد اپلیکیشن پهناورکار شوید.
                </div>
            </div>
        </section>

        <section class="py-10 sm:py-14" aria-labelledby="legal-category-heading">
            <div class="mx-auto max-w-5xl px-5 sm:px-8">
                <div class="mb-5">
                    <h2 id="legal-category-heading" class="text-xl font-bold text-slate-900 sm:text-2xl">چه اسنادی پیدا می‌کنید؟</h2>
                    <p class="mt-2 text-sm leading-7 text-slate-600">{{ count($legalCategories) }} دسته برای مرور اسناد حقوقی</p>
                </div>

                <ul class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    @foreach ($legalCategories as $index => $category)
                        <li class="flex min-h-16 items-center gap-3 border-b border-slate-100 px-4 py-3 last:border-b-0 sm:gap-4 sm:px-5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-xs font-bold text-indigo-700" aria-hidden="true">{{ $index + 1 }}</span>
                            <span class="text-sm font-semibold leading-7 text-slate-900">{{ $category }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="public-page-warning mt-7 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-xs leading-7 text-amber-950">
                    این صفحه متن قانون یا مشاورهٔ حقوقی ارائه نمی‌کند. اگر کنار سندی در اپلیکیشن هشدار آزمایشی‌بودن دیدید، متن آن سند را رسمی تلقی نکنید.
                </p>
            </div>
        </section>
    </main>
@endsection

<footer class="site-footer border-t border-slate-200 bg-white text-slate-700">
    <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 sm:py-10">
        <div class="grid gap-8 md:grid-cols-[minmax(0,1fr)_auto] md:gap-12">
            <div class="max-w-lg">
                <a href="{{ route('home') }}#home" class="inline-flex items-center gap-2.5 rounded-xl focus:outline-none focus:ring-4 focus:ring-indigo-500/20">
                    <img src="{{ asset('images/pahnavarkar-logo-teal.png') }}?v=1" alt="" aria-hidden="true" class="size-10 rounded-full object-contain dark:hidden">
                    <img src="{{ asset('images/pahnavarkar-logo-white.png') }}?v=1" alt="" aria-hidden="true" class="hidden size-10 rounded-full object-contain dark:block">
                    <span class="text-sm font-bold text-slate-900">{{ $siteContent['brand_name'] }}</span>
                </a>
                <p class="mt-3 text-sm leading-7 text-slate-600">ابزارهای حقوق و دستمزد و منابع قوانین کار، ساده‌تر و در دسترس‌تر.</p>
            </div>

            <nav aria-label="پیوندهای مفید">
                <h2 class="text-sm font-bold text-slate-900">پیوندهای مفید</h2>
                <ul class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2.5 text-sm sm:grid-cols-3 md:grid-cols-2">
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('home') }}#features">امکانات</a></li>
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('tools.index') }}">ابزارها</a></li>
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('laws.index') }}">قوانین و مقررات</a></li>
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('home') }}#plans">پلن‌ها</a></li>
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('faq.index') }}">پرسش‌های پرتکرار</a></li>
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('blog.index') }}">بلاگ</a></li>
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('about') }}">درباره ما</a></li>
                    <li><a class="inline-flex min-h-8 items-center rounded-md text-slate-600 transition hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('terms') }}">شرایط استفاده از برنامه</a></li>
                </ul>
            </nav>
        </div>

        <div class="mt-7 flex flex-col gap-2 border-t border-slate-200 pt-4 text-xs leading-6 text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>اطلاعات و محاسبات، راهنمای عمومی‌اند و جای مشاورهٔ تخصصی حقوقی را نمی‌گیرند.</p>
            <p>© {{ now()->year }} {{ $siteContent['brand_name'] }}؛ همهٔ حقوق محفوظ است.</p>
        </div>
    </div>
</footer>

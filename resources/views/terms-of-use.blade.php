@extends('layouts.public', [
    'pageTitle' => 'شرایط استفاده از برنامه | '.$siteContent['brand_name'],
    'pageDescription' => 'شرایط استفاده از برنامهٔ پهناورکار، شامل حساب کاربری، اشتراک‌ها، محاسبات و استفادهٔ مجاز از خدمات.',
    'canonicalUrl' => route('terms'),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section class="border-b border-slate-200 bg-white py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <nav aria-label="مسیر صفحه" class="text-xs text-slate-500">
                    <a href="{{ route('home') }}" class="transition hover:text-indigo-700">صفحهٔ اصلی</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span aria-current="page">شرایط استفاده از برنامه</span>
                </nav>
                <div class="mt-7 max-w-3xl">
                    <span class="text-xs font-bold text-indigo-600">ضوابط استفاده</span>
                    <h1 class="mt-3 text-3xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-5xl">شرایط استفاده از برنامه</h1>
                    <p class="mt-4 text-sm leading-8 text-slate-600 sm:text-base">این شرایط، چارچوب استفاده از اپلیکیشن پهناورکار و خدمات آن را مشخص می‌کند. لطفاً پیش از استفاده، آن را با دقت مطالعه کنید.</p>
                </div>
            </div>
        </section>

        <section class="py-10 sm:py-14" aria-label="شرایط استفاده">
            <div class="mx-auto max-w-4xl space-y-4 px-5 sm:px-8">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                    <h2 class="text-lg font-bold text-slate-900">۱. پذیرش و دامنهٔ شرایط</h2>
                    <p class="mt-3 text-sm leading-8 text-slate-600">این شرایط بر استفاده از اپلیکیشن پهناورکار و امکاناتی که از طریق آن ارائه می‌شود حاکم است. نصب یا استفاده از برنامه به‌منزلهٔ پذیرش این شرایط است. در صورت عدم موافقت، از نصب یا استفاده از برنامه خودداری کنید.</p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                    <h2 class="text-lg font-bold text-slate-900">۲. حساب کاربری</h2>
                    <p class="mt-3 text-sm leading-8 text-slate-600">در صورت ایجاد حساب، اطلاعات درخواستی را صحیح و به‌روز وارد کنید و از اطلاعات و ابزارهای دسترسی به حساب خود محافظت کنید. شما مسئول اقداماتی هستید که از طریق حساب‌تان انجام می‌شود؛ در صورت آگاهی از دسترسی یا استفادهٔ غیرمجاز، موضوع را از مسیرهای ارتباطی اعلام‌شده در برنامه اطلاع دهید.</p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                    <h2 class="text-lg font-bold text-slate-900">۳. پلن‌ها و اشتراک‌ها</h2>
                    <p class="mt-3 text-sm leading-8 text-slate-600">پیش از ثبت سفارش، امکانات پلن، مدت اشتراک و مبلغ قابل پرداخت را که هنگام خرید نمایش داده می‌شود بررسی کنید. دسترسی اشتراکی، در حدود امکانات همان پلن و مدت انتخاب‌شده ارائه می‌شود. در صورت ابهام در اطلاعات خرید، پیش از پرداخت از مسیرهای ارتباطی اعلام‌شده در برنامه پیگیری کنید.</p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                    <h2 class="text-lg font-bold text-slate-900">۴. محاسبات و محتوای حقوقی</h2>
                    <p class="mt-3 text-sm leading-8 text-slate-600">نتایج محاسبات بر پایهٔ اطلاعات واردشده و داده‌ها و مقررات در دسترس برنامه تهیه می‌شود؛ بنابراین ممکن است با تغییر اطلاعات، شرایط یا مقررات، نتیجه نیز تغییر کند. این نتایج و محتوای برنامه صرفاً جنبهٔ اطلاع‌رسانی عمومی دارند و جایگزین بررسی منابع رسمی یا دریافت مشاورهٔ تخصصی حقوقی و مالی متناسب با وضعیت شما نیستند. پیش از اتکا به نتیجه، اطلاعات ورودی و منابع مربوط را بررسی کنید.</p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                    <h2 class="text-lg font-bold text-slate-900">۵. استفادهٔ مجاز از برنامه</h2>
                    <p class="mt-3 text-sm leading-8 text-slate-600">استفاده از برنامه برای مقاصد غیرقانونی، ایجاد اختلال در عملکرد آن یا دسترسی بدون مجوز به حساب‌ها و سامانه‌ها مجاز نیست. بازنشر، تغییر یا بهره‌برداری از محتوا و اجزای برنامه نیز نباید ناقض حقوق پهناورکار یا اشخاص دیگر باشد؛ مگر در مواردی که قانون اجازه می‌دهد یا صاحب حق اجازه داده است.</p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                    <h2 class="text-lg font-bold text-slate-900">۶. تغییر و دسترس‌پذیری خدمات</h2>
                    <p class="mt-3 text-sm leading-8 text-slate-600">ممکن است بخشی از خدمات برای نگهداری، به‌روزرسانی یا بهبود برنامه موقتاً در دسترس نباشد یا تغییر کند. پهناورکار برای حفظ و بهبود خدمات تلاش می‌کند؛ بااین‌حال، دسترسی پیوسته و بدون وقفه به همهٔ امکانات در تمام زمان‌ها قابل تضمین نیست.</p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                    <h2 class="text-lg font-bold text-slate-900">۷. به‌روزرسانی شرایط</h2>
                    <p class="mt-3 text-sm leading-8 text-slate-600">ممکن است با توجه به تغییر خدمات یا الزامات قانونی، این شرایط را به‌روزرسانی کنیم. نسخهٔ به‌روز در همین صفحه منتشر می‌شود؛ پیشنهاد می‌کنیم هر از گاهی آن را مرور کنید.</p>
                </article>

                <p class="rounded-2xl border border-indigo-100 bg-indigo-50/70 px-5 py-4 text-sm leading-7 text-indigo-950">
                    دربارهٔ ابزارها و امکانات برنامه پرسشی دارید؟ <a href="{{ route('faq.index') }}" class="font-bold underline decoration-indigo-300 underline-offset-4 hover:text-indigo-700">پرسش‌های پرتکرار را ببینید</a>.
                </p>
            </div>
        </section>
    </main>
@endsection

@extends('layouts.public', [
    'pageTitle' => 'بلاگ | '.$siteContent['brand_name'],
    'pageDescription' => 'مقالات و راهنماها، اطلاعیه‌ها و اخبار پهناورکار دربارهٔ حقوق و دستمزد و قوانین کار.',
    'canonicalUrl' => route('blog.index'),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section class="border-b border-slate-200 bg-gradient-to-b from-indigo-50/80 to-white py-9 sm:py-14">
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <nav aria-label="مسیر صفحه" class="text-xs text-slate-500">
                    <a href="{{ route('home') }}" class="transition hover:text-indigo-700">صفحهٔ اصلی</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span aria-current="page">بلاگ</span>
                </nav>
                <div class="mt-6 flex items-start gap-4 sm:mt-8">
                    <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-indigo-100 text-indigo-700 sm:size-14" aria-hidden="true">
                        <i class="fa-solid fa-book-open text-2xl sm:text-[1.75rem]" aria-hidden="true"></i>
                    </span>
                    <div class="max-w-3xl">
                        <span class="text-xs font-bold text-indigo-700">مقالات، آموزش و تازه‌های پهناورکار</span>
                        <h1 class="mt-1 text-2xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-4xl">بلاگ</h1>
                        <p class="mt-2 text-sm leading-7 text-slate-600 sm:text-base">راهنماهای کاربردی را بخوانید و از خبرها و اطلاعیه‌های پهناورکار باخبر شوید.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="py-7 sm:py-10" aria-label="مطالب بلاگ">
            <div id="blog-content" class="mx-auto max-w-6xl space-y-6 px-5 sm:px-8">
                <nav aria-label="دسته‌بندی مطالب بلاگ" class="rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
                    <ul class="grid grid-cols-3 gap-1">
                        <li>
                            <a href="#blog-content" aria-current="page" class="flex min-h-11 items-center justify-center rounded-xl bg-indigo-50 px-2 py-2 text-center text-xs font-bold text-indigo-800 transition hover:bg-indigo-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20 sm:text-sm">
                                همه
                            </a>
                        </li>
                        <li>
                            <a href="#articles" class="flex min-h-11 items-center justify-center rounded-xl px-2 py-2 text-center text-xs font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-indigo-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20 sm:text-sm">
                                مقالات و آموزش
                            </a>
                        </li>
                        <li>
                            <a href="#announcements" class="flex min-h-11 items-center justify-center rounded-xl px-2 py-2 text-center text-xs font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-indigo-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20 sm:text-sm">
                                اخبار و اطلاعیه‌ها
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="grid gap-5 lg:grid-cols-2">
                    <section id="articles" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="articles-heading">
                        <div class="flex items-start gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-700" aria-hidden="true">
                                <i class="fa-solid fa-file-lines text-xl" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h2 id="articles-heading" class="text-lg font-bold text-slate-900">مقالات و آموزش</h2>
                                <p class="mt-1 text-xs leading-6 text-slate-500">راهنماهایی دربارهٔ حقوق، دستمزد و قوانین کار</p>
                            </div>
                        </div>
                        <div class="mt-6 rounded-xl bg-slate-50 px-4 py-5 text-center">
                            <h3 class="text-sm font-bold text-slate-800">هنوز مقاله‌ای منتشر نشده</h3>
                            <p class="mt-2 text-xs leading-6 text-slate-600">وقتی مطلب تازه‌ای منتشر شود، همین‌جا می‌توانید آن را بخوانید.</p>
                            <a href="{{ route('tools.index') }}" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20">دیدن ابزارها</a>
                        </div>
                    </section>

                    <section id="announcements" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="announcements-heading">
                        <div class="flex items-start gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700" aria-hidden="true">
                                <i class="fa-solid fa-bell text-xl" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h2 id="announcements-heading" class="text-lg font-bold text-slate-900">اخبار و اطلاعیه‌ها</h2>
                                <p class="mt-1 text-xs leading-6 text-slate-500">خبرهای مهم و تغییرات خدمات پهناورکار</p>
                            </div>
                        </div>
                        <div class="mt-6 rounded-xl bg-slate-50 px-4 py-5 text-center">
                            <h3 class="text-sm font-bold text-slate-800">فعلاً اطلاعیه‌ای نداریم</h3>
                            <p class="mt-2 text-xs leading-6 text-slate-600">خبرها و اطلاعیه‌های عمومی پس از انتشار در این بخش قرار می‌گیرند.</p>
                        </div>
                    </section>
                </div>
            </div>
        </section>
    </main>
@endsection

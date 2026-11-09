@extends('layouts.public', [
    'pageTitle' => 'پرسش‌های پرتکرار | '.$siteContent['brand_name'],
    'pageDescription' => 'پاسخ پرسش‌های پرتکرار دربارهٔ ابزارها، کتابخانهٔ قوانین و استفاده از پهناورکار.',
    'canonicalUrl' => route('faq.index'),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section class="border-b border-slate-200 bg-white py-8 sm:py-12">
            <div class="mx-auto max-w-5xl px-5 sm:px-8">
                <nav aria-label="مسیر صفحه" class="text-xs text-slate-500">
                    <a href="{{ route('home') }}" class="transition hover:text-indigo-700">صفحهٔ اصلی</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span aria-current="page">پرسش‌های پرتکرار</span>
                </nav>
                <div class="mt-5 max-w-3xl">
                    <span class="text-xs font-bold text-indigo-600">راهنمای پهناورکار</span>
                    <h1 class="mt-2 text-2xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-4xl">پرسش‌های پرتکرار</h1>
                    <p class="mt-2 text-sm leading-7 text-slate-600 sm:text-base">سؤالی دربارهٔ ابزارها، قوانین یا محاسبه‌ها دارید؟ اینجا جوابش را پیدا می‌کنید.</p>
                </div>
            </div>
        </section>

        <section class="py-8 sm:py-12" aria-labelledby="faq-list-heading">
            <div class="mx-auto max-w-4xl px-5 sm:px-8">
                <h2 id="faq-list-heading" class="sr-only">فهرست پرسش‌ها و پاسخ‌ها</h2>
                @php($faqGroups = collect($faqs)->groupBy('category_id'))
                <div class="mb-7 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <label for="faq-search" class="mb-2 block text-sm font-bold text-slate-800">دنبال پاسخ چه چیزی هستید؟</label>
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-base text-slate-400" aria-hidden="true"></i>
                        <input
                            id="faq-search"
                            type="search"
                            class="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-4 pr-11 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
                            placeholder="مثلاً: محاسبه‌گرها یا قوانین"
                            autocomplete="off"
                            data-faq-search
                        >
                    </div>
                    <nav aria-label="فیلتر دسته‌بندی پرسش‌ها" class="mt-4 flex flex-wrap gap-2">
                        <button type="button" class="rounded-full border border-indigo-200 bg-indigo-50 px-3.5 py-2 text-xs font-bold text-indigo-800 transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20" data-faq-category="all" aria-pressed="true">همه</button>
                    @foreach ($faqGroups as $categoryId => $questions)
                        <button type="button" class="rounded-full border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition hover:border-indigo-200 hover:text-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20" data-faq-category="{{ $categoryId }}" aria-pressed="false">
                            {{ $questions->first()['category'] }}
                        </button>
                    @endforeach
                    </nav>
                    <p class="mt-3 text-xs text-slate-500" data-faq-results role="status" aria-live="polite">{{ count($faqs) }} پرسش</p>
                </div>

                <div class="space-y-6">
                    @foreach ($faqGroups as $categoryId => $questions)
                        <section id="faq-topic-{{ $categoryId }}" class="scroll-mt-24" aria-labelledby="faq-topic-heading-{{ $categoryId }}" data-faq-group="{{ $categoryId }}">
                            <h3 id="faq-topic-heading-{{ $categoryId }}" class="mb-2.5 text-base font-bold text-slate-900">{{ $questions->first()['category'] }}</h3>
                            <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                @foreach ($questions as $faq)
                                    <div id="{{ $faq['id'] }}" class="relative scroll-mt-24" data-faq-item>
                                        <details class="group px-4 py-4 sm:px-5">
                                            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 rounded-lg pl-9 text-sm font-bold leading-7 text-slate-800 marker:hidden focus:outline-none focus-visible:text-indigo-800 focus-visible:ring-2 focus-visible:ring-indigo-500/50 focus-visible:ring-offset-2">
                                                <span>{{ $faq['question'] }}</span>
                                                <i class="fa-solid fa-chevron-down mt-1 size-5 shrink-0 text-slate-400 transition-transform group-open:rotate-180 group-open:text-indigo-600" aria-hidden="true"></i>
                                            </summary>
                                            <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">{{ $faq['answer'] }}</p>
                                            @if ($faq['related'])
                                                <a href="{{ $faq['related']['url'] }}" class="mt-3 inline-flex min-h-10 items-center gap-1 text-xs font-bold text-indigo-700 transition hover:text-indigo-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                                    {{ $faq['related']['label'] }}
                                                    <i class="fa-solid fa-arrow-left size-3.5" aria-hidden="true"></i>
                                                </a>
                                            @endif
                                        </details>
                                        <a
                                            href="#{{ $faq['id'] }}"
                                            aria-label="پیوند مستقیم به پرسش: {{ $faq['question'] }}"
                                            title="پیوند مستقیم به این پرسش"
                                            class="absolute left-3 top-3 grid size-8 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:left-4 sm:top-3.5"
                                        >
                                            <i class="fa-solid fa-link size-4" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
                <p class="mt-6 rounded-xl border border-slate-200 bg-white px-5 py-6 text-center text-sm text-slate-600" data-faq-empty role="status" hidden>پاسخی با این عبارت پیدا نشد. عبارت دیگری را جست‌وجو کنید یا دستهٔ دیگری را انتخاب کنید.</p>
                <a href="{{ route('tools.index') }}" class="mt-6 inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-500/20">
                    مرور فهرست ابزارها
                    <i class="fa-solid fa-arrow-left size-4" aria-hidden="true"></i>
                </a>
            </div>
        </section>
    </main>
@endsection

@section('scripts')
    <script>
        (() => {
            const search = document.querySelector('[data-faq-search]');
            const categoryButtons = Array.from(document.querySelectorAll('[data-faq-category]'));
            const groups = Array.from(document.querySelectorAll('[data-faq-group]'));
            const resultStatus = document.querySelector('[data-faq-results]');
            const emptyState = document.querySelector('[data-faq-empty]');
            const totalCount = document.querySelectorAll('[data-faq-item]').length;
            const selectedClasses = ['border-indigo-200', 'bg-indigo-50', 'font-bold', 'text-indigo-800'];
            let selectedCategory = 'all';

            const normalize = (value) => value
                .normalize('NFKC')
                .replace(/[يى]/g, 'ی')
                .replace(/ك/g, 'ک')
                .replace(/[\u200c\u200f\u0640]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .toLocaleLowerCase('fa');

            const filterQuestions = () => {
                const query = normalize(search.value);
                let visibleCount = 0;

                groups.forEach((group) => {
                    let groupCount = 0;

                    group.querySelectorAll('[data-faq-item]').forEach((item) => {
                        const matches = (selectedCategory === 'all' || group.dataset.faqGroup === selectedCategory)
                            && (!query || normalize(item.textContent).includes(query));

                        item.hidden = !matches;
                        groupCount += Number(matches);
                    });

                    group.hidden = groupCount === 0;
                    visibleCount += groupCount;
                });

                resultStatus.textContent = query || selectedCategory !== 'all'
                    ? `${visibleCount} پرسش از ${totalCount} پرسش`
                    : `${totalCount} پرسش`;
                emptyState.hidden = visibleCount !== 0;
            };

            categoryButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    selectedCategory = button.dataset.faqCategory;
                    categoryButtons.forEach((categoryButton) => {
                        const isSelected = categoryButton === button;
                        categoryButton.setAttribute('aria-pressed', String(isSelected));
                        selectedClasses.forEach((className) => categoryButton.classList.toggle(className, isSelected));
                        if (!isSelected) {
                            categoryButton.classList.add('border-slate-200', 'bg-white', 'font-semibold', 'text-slate-700');
                        } else {
                            categoryButton.classList.remove('border-slate-200', 'bg-white', 'font-semibold', 'text-slate-700');
                        }
                    });
                    filterQuestions();
                });
            });

            search.addEventListener('input', filterQuestions);

            const openLinkedQuestion = () => {
                const target = document.getElementById(window.location.hash.slice(1));

                if (target) {
                    const disclosure = target.querySelector('details');
                    const group = target.closest('[data-faq-group]');
                    const categoryButton = categoryButtons.find((button) => button.dataset.faqCategory === group?.dataset.faqGroup);

                    if (disclosure) {
                        disclosure.open = true;
                    }

                    if (categoryButton && selectedCategory !== 'all') {
                        categoryButtons.find((button) => button.dataset.faqCategory === 'all')?.click();
                    }
                }
            };

            filterQuestions();
            openLinkedQuestion();
            window.addEventListener('hashchange', openLinkedQuestion);
        })();
    </script>
@endsection

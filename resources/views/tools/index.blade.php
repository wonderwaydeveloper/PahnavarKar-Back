@extends('layouts.public', [
    'pageTitle' => 'ابزارهای محاسباتی | '.$siteContent['brand_name'],
    'pageDescription' => 'با ابزارهای محاسبهٔ حقوق و دستمزد و زمان کار در اپلیکیشن پهناورکار آشنا شوید و ابزار موردنظرتان را پیدا کنید.',
    'canonicalUrl' => route('tools.index'),
])

@section('content')
    <main id="main-content" tabindex="-1">
        <section class="border-b border-slate-200 bg-white py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <nav aria-label="مسیر صفحه" class="text-xs text-slate-500">
                    <a href="{{ route('home') }}" class="transition hover:text-indigo-700">صفحهٔ اصلی</a>
                    <span class="mx-2" aria-hidden="true">/</span>
                    <span aria-current="page">ابزارهای محاسباتی</span>
                </nav>
                <div class="mt-7 max-w-3xl">
                    <span class="text-xs font-bold text-indigo-600">ابزارهای اپلیکیشن پهناورکار</span>
                    <h1 class="mt-3 text-3xl font-bold leading-[1.5] tracking-tight text-slate-950 sm:text-5xl">ابزارهای محاسباتی</h1>
                    <p class="mt-4 text-sm leading-8 text-slate-600 sm:text-base">دنبال ابزار خاصی هستید؟ جست‌وجو کنید یا دستهٔ مرتبط را انتخاب کنید.</p>
                </div>
                <div class="public-page-callout mt-7 rounded-2xl border border-indigo-100 bg-indigo-50/70 px-5 py-4 text-sm leading-7 text-indigo-950">
                    ابزارها در اپلیکیشن اجرا می‌شوند؛ اینجا می‌توانید با کاربرد هرکدام آشنا شوید.
                </div>
            </div>
        </section>

        <section class="py-10 sm:py-14" aria-labelledby="tool-list-heading">
            <div class="mx-auto max-w-5xl px-5 sm:px-8">
                <form method="GET" action="{{ route('tools.index') }}" class="mb-6 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end">
                    <input type="hidden" name="group" id="selected-tool-group" value="{{ $selectedGroup }}">
                    <div class="flex-1">
                        <label for="tool-search" class="text-sm font-bold text-slate-800">جست‌وجو در ابزارها</label>
                        <input
                            id="tool-search"
                            name="q"
                            type="search"
                            value="{{ $search }}"
                            maxlength="100"
                            placeholder="مثلاً حقوق پایه یا اضافه‌کاری"
                            class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                        >
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="inline-flex h-11 flex-1 items-center justify-center rounded-xl bg-indigo-600 px-5 text-sm font-bold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 sm:flex-none">جست‌وجو</button>
                        @if ($search !== '' || request()->query->has('group'))
                            <a href="{{ route('tools.index') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">پاک‌کردن</a>
                        @endif
                    </div>
                </form>

                @if ($filterErrors !== [])
                    <p role="alert" class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-7 text-rose-900">
                        فیلتر ارسالی معتبر نیست. دسته‌بندی را از گزینه‌های موجود انتخاب کنید و عبارت جست‌وجو را حداکثر در ۱۰۰ نویسه وارد کنید.
                    </p>
                @endif

                <h2 id="tool-list-heading" class="sr-only">فهرست ابزارها بر اساس دسته‌بندی</h2>
                <div id="tool-category-tabs" role="tablist" aria-label="دسته‌بندی ابزارهای محاسباتی" aria-orientation="horizontal" class="grid grid-cols-2 gap-2 rounded-2xl border border-slate-200 bg-slate-100/70 p-2 sm:flex sm:gap-1">
                    @foreach ($groups as $group)
                        <button
                            id="tool-tab-{{ $loop->index }}"
                            type="button"
                            role="tab"
                            aria-controls="tool-panel-{{ $loop->index }}"
                            aria-selected="{{ $selectedGroup === $group ? 'true' : 'false' }}"
                            tabindex="{{ $selectedGroup === $group ? '0' : '-1' }}"
                            data-tool-tab
                            data-group="{{ $group }}"
                            @class([
                                'min-h-11 rounded-xl px-3 py-2 text-xs font-semibold leading-5 transition focus:outline-none focus:ring-4 focus:ring-indigo-500/20 sm:flex-1 sm:text-sm',
                                'bg-white text-indigo-800 shadow-sm' => $selectedGroup === $group,
                                'text-slate-600 hover:bg-white/70 hover:text-slate-900' => $selectedGroup !== $group,
                            ])
                        >{{ $group }}</button>
                    @endforeach
                </div>

                <div class="mt-5">
                    @foreach ($groups as $group)
                        @php($groupTools = $tools->get($group, collect()))
                        @php($localizedToolCount = strtr((string) $groupTools->count(), [
                            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
                        ]))
                        <section
                            id="tool-panel-{{ $loop->index }}"
                            role="tabpanel"
                            aria-labelledby="tool-tab-{{ $loop->index }}"
                            tabindex="0"
                            data-tool-panel
                            @if ($selectedGroup !== $group) hidden @endif
                        >
                            <div class="mb-3 flex items-end justify-between gap-3">
                                <div>
                                    <h2 id="tool-panel-heading-{{ $loop->index }}" class="text-lg font-bold text-slate-900 sm:text-xl">{{ $group }}</h2>
                                    <p class="mt-1 text-xs text-slate-500">{{ $localizedToolCount }} ابزار</p>
                                </div>
                                <a href="{{ route('home') }}#plans" class="text-xs font-bold text-indigo-700 transition hover:text-indigo-900">سطوح دسترسی</a>
                            </div>

                            @if ($groupTools->isEmpty())
                                <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-10 text-center">
                                    <h3 class="text-sm font-bold text-slate-900">موردی پیدا نشد</h3>
                                    <p class="mt-2 text-xs leading-6 text-slate-600">با این عبارت ابزاری پیدا نشد. عبارت دیگری را جست‌وجو کنید یا دستهٔ دیگری را ببینید.</p>
                                </div>
                            @else
                                <ul class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                    @foreach ($groupTools as $tool)
                                        <li class="border-b border-slate-100 last:border-b-0">
                                            <a href="{{ route('tools.show', $tool['key']) }}" class="group flex min-h-20 items-center gap-3 px-4 py-3 transition hover:bg-slate-50 focus:outline-none focus:bg-indigo-50/70 sm:gap-4 sm:px-5">
                                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-700" aria-hidden="true">
                                                    <i class="fa-solid fa-calculator text-xl" aria-hidden="true"></i>
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-sm font-bold leading-6 text-slate-900">{{ $tool['label'] }}</span>
                                                    <span class="mt-1 block text-xs leading-6 text-slate-600">{{ $tool['description'] }}</span>
                                                </span>
                                                <i class="fa-solid fa-arrow-left size-4 shrink-0 text-slate-400 transition group-hover:-translate-x-1 group-hover:text-indigo-700" aria-hidden="true"></i>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    @endforeach
                </div>

                <p class="public-page-warning mt-8 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-xs leading-7 text-amber-950">
                    توضیحات هر ابزار برای آشنایی است و جای بررسی شرایط پرونده را نمی‌گیرد. جزئیات دسترسی ابزارها را در صفحهٔ پلن‌ها ببینید.
                </p>
            </div>
        </section>
    </main>
@endsection

@section('scripts')
    <script>
        (() => {
            const tablist = document.querySelector('#tool-category-tabs');
            const groupInput = document.querySelector('#selected-tool-group');

            if (!tablist || !groupInput) {
                return;
            }

            const tabs = Array.from(tablist.querySelectorAll('[data-tool-tab]'));
            const panels = Array.from(document.querySelectorAll('[data-tool-panel]'));

            const activateTab = (tab, moveFocus = false) => {
                const panelId = tab.getAttribute('aria-controls');

                tabs.forEach((candidate) => {
                    const selected = candidate === tab;
                    candidate.setAttribute('aria-selected', String(selected));
                    candidate.tabIndex = selected ? 0 : -1;
                    candidate.classList.toggle('bg-white', selected);
                    candidate.classList.toggle('text-indigo-800', selected);
                    candidate.classList.toggle('shadow-sm', selected);
                    candidate.classList.toggle('text-slate-600', !selected);
                    candidate.classList.toggle('hover:bg-white/70', !selected);
                    candidate.classList.toggle('hover:text-slate-900', !selected);
                });

                panels.forEach((panel) => {
                    panel.hidden = panel.id !== panelId;
                });

                groupInput.value = tab.dataset.group;

                if (moveFocus) {
                    tab.focus();
                }
            };

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => activateTab(tab));
                tab.addEventListener('keydown', (event) => {
                    const isRtl = document.documentElement.dir === 'rtl';
                    let nextIndex = null;

                    if (event.key === 'Home') {
                        nextIndex = 0;
                    } else if (event.key === 'End') {
                        nextIndex = tabs.length - 1;
                    } else if (event.key === 'ArrowRight') {
                        nextIndex = (index + (isRtl ? -1 : 1) + tabs.length) % tabs.length;
                    } else if (event.key === 'ArrowLeft') {
                        nextIndex = (index + (isRtl ? 1 : -1) + tabs.length) % tabs.length;
                    }

                    if (nextIndex !== null) {
                        event.preventDefault();
                        activateTab(tabs[nextIndex], true);
                    }
                });
            });
        })();
    </script>
@endsection

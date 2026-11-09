<section id="faq" class="scroll-mt-24 bg-white py-20 sm:py-24" aria-labelledby="faq-heading">
    <div class="mx-auto max-w-4xl px-5 sm:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <span class="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600">پرسش‌های پرتکرار</span>
            <h2 id="faq-heading" class="mt-4 text-base font-semibold leading-8 text-slate-800 sm:text-lg">سؤالی دارید؟ شاید پاسخ‌تان اینجا باشد.</h2>
        </div>

        <div class="mt-8 divide-y divide-slate-200 overflow-hidden rounded-3xl border border-slate-200 bg-white">
            @foreach ($faqs as $index => $faq)
                @if ($index < 4)
                    <details class="group px-5 py-5 sm:px-7">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 text-sm font-bold leading-7 text-slate-900 marker:hidden">
                            <span>{{ $faq['question'] }}</span>
                            <i class="fa-solid fa-chevron-down mt-1 size-5 shrink-0 text-indigo-600 transition-transform group-open:rotate-180" aria-hidden="true"></i>
                        </summary>
                        <p class="mt-3 max-w-3xl text-sm leading-8 text-slate-600">{{ $faq['answer'] }}</p>
                        <a href="{{ route('faq.index') }}#{{ $faq['id'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-indigo-700 transition hover:text-indigo-900">
                            ادامهٔ پاسخ و پیوند مستقیم
                            <i class="fa-solid fa-arrow-left size-3.5" aria-hidden="true"></i>
                        </a>
                    </details>
                @endif
            @endforeach
        </div>

        <div class="mt-7 text-center">
            <a href="{{ route('faq.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-indigo-700 transition hover:border-indigo-200 hover:bg-indigo-50">
                همهٔ پرسش‌ها و پاسخ‌ها
                <i class="fa-solid fa-arrow-left size-4" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>

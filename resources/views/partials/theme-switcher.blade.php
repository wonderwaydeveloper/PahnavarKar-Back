<details class="theme-switcher-control">
    <summary class="theme-switcher-trigger" title="تغییر قالب رنگی">
        <i data-theme-current-icon class="fa-solid fa-display text-slate-500" aria-hidden="true"></i>
        <span class="sr-only">قالب رنگی:</span>
        <span data-theme-label>مطابق سیستم</span>
        <i class="fa-solid fa-chevron-down theme-switcher-chevron text-slate-500" aria-hidden="true"></i>
    </summary>
    <fieldset class="theme-switcher-options">
        <legend class="sr-only">انتخاب قالب رنگی</legend>
        @foreach ([
            'light' => ['روشن', 'fa-sun', 'text-amber-500'],
            'dark' => ['تیره', 'fa-moon', 'text-indigo-600'],
            'system' => ['مطابق سیستم', 'fa-display', 'text-slate-500'],
        ] as $value => [$label, $icon, $iconColor])
            <label class="theme-switcher-option">
                <input
                    type="radio"
                    name="theme-preference-{{ $switcherId }}"
                    value="{{ $value }}"
                    data-theme-switcher
                    data-theme-icon="{{ $icon }}"
                    data-theme-icon-color="{{ $iconColor }}"
                    @checked($value === 'system')
                >
                <span>
                    <span class="theme-switcher-option-name">
                        <i class="fa-solid {{ $icon }} {{ $iconColor }} theme-switcher-option-icon" aria-hidden="true"></i>
                        <span data-theme-option-label>{{ $label }}</span>
                    </span>
                    <i class="fa-solid fa-check theme-switcher-check text-indigo-600" aria-hidden="true"></i>
                </span>
            </label>
        @endforeach
    </fieldset>
</details>

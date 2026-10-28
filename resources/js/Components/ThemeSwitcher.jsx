import { useState } from 'react';
import { getThemePreference, setThemePreference } from '../theme';

export default function ThemeSwitcher() {
    const [preference, setPreference] = useState(getThemePreference);

    function changePreference(event) {
        const nextPreference = event.target.value;
        setThemePreference(nextPreference);
        setPreference(nextPreference);
    }

    return (
        <label className="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
            <span>قالب</span>
            <select
                aria-label="انتخاب قالب رنگی"
                data-theme-switcher
                value={preference}
                onChange={changePreference}
                className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
            >
                <option value="light">روشن</option>
                <option value="dark">تیره</option>
                <option value="system">مطابق سیستم</option>
            </select>
        </label>
    );
}

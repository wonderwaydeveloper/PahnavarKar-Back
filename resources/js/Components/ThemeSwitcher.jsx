import { useId } from 'react';
import { getThemePreference } from '../theme';

export default function ThemeSwitcher() {
    const switcherId = useId();
    const preference = getThemePreference();
    const preferenceOptions = [
        ['light', 'روشن', 'fa-sun', 'text-amber-500'],
        ['dark', 'تیره', 'fa-moon', 'text-indigo-600'],
        ['system', 'مطابق سیستم', 'fa-display', 'text-slate-500'],
    ];
    const selectedOption = preferenceOptions.find(([value]) => value === preference);

    return (
        <details className="theme-switcher-control">
            <summary className="theme-switcher-trigger" title="تغییر قالب رنگی">
                <i className={`fa-solid ${selectedOption[2]} ${selectedOption[3]}`} aria-hidden="true" data-theme-current-icon />
                <span className="sr-only">قالب رنگی:</span>
                <span data-theme-label>{selectedOption[1]}</span>
                <i className="fa-solid fa-chevron-down theme-switcher-chevron text-slate-500" aria-hidden="true" />
            </summary>
            <fieldset className="theme-switcher-options">
                <legend className="sr-only">انتخاب قالب رنگی</legend>
                {preferenceOptions.map(([value, label, icon, iconColor]) => (
                    <label className="theme-switcher-option" key={value}>
                        <input
                            type="radio"
                            name={`theme-preference-${switcherId}`}
                            value={value}
                            data-theme-switcher
                            data-theme-icon={icon}
                            data-theme-icon-color={iconColor}
                            defaultChecked={preference === value}
                        />
                        <span>
                            <span className="theme-switcher-option-name">
                                <i className={`fa-solid ${icon} ${iconColor} theme-switcher-option-icon`} aria-hidden="true" />
                                <span data-theme-option-label>{label}</span>
                            </span>
                            <i className="fa-solid fa-check theme-switcher-check text-indigo-600" aria-hidden="true" />
                        </span>
                    </label>
                ))}
            </fieldset>
        </details>
    );
}

import { useId } from 'react';

export default function MultiSelectField({ label, options, value, onChange, error, hint }) {
    const selectId = useId();
    const selectedOptions = options.filter((option) => value.includes(option.id));
    const availableOptions = options.filter((option) => !value.includes(option.id));

    function addOption(event) {
        const id = Number(event.target.value);
        if (!Number.isInteger(id) || value.includes(id)) return;
        onChange([...value, id]);
    }

    function removeOption(id) {
        onChange(value.filter((selectedId) => selectedId !== id));
    }

    return (
        <div className="grid content-start gap-3">
            <label htmlFor={selectId} className="text-sm font-semibold text-slate-700">{label}</label>
            <select
                id={selectId}
                value=""
                onChange={addOption}
                disabled={availableOptions.length === 0}
                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:bg-slate-50"
            >
                <option value="" disabled>{availableOptions.length > 0 ? 'برای افزودن انتخاب کنید…' : 'همهٔ گزینه‌ها انتخاب شده‌اند'}</option>
                {availableOptions.map((option) => (
                    <option key={option.id} value={option.id}>{option.label}</option>
                ))}
            </select>
            {hint && <p className="text-xs leading-5 text-slate-500">{hint}</p>}
            {selectedOptions.length > 0 ? (
                <ul className="flex flex-wrap gap-2">
                    {selectedOptions.map((option) => (
                        <li key={option.id} className="flex max-w-full items-center gap-2 rounded-xl border border-indigo-100 bg-indigo-50 py-1.5 ps-3 pe-2 text-xs text-indigo-900">
                            <span className="min-w-0">
                                <span className="block font-semibold">{option.label}</span>
                                {option.description && <span dir="ltr" className="mt-0.5 block truncate text-[10px] text-indigo-700">{option.description}</span>}
                            </span>
                            <button
                                type="button"
                                onClick={() => removeOption(option.id)}
                                aria-label={`حذف ${option.label}`}
                                className="grid size-7 shrink-0 place-items-center rounded-lg text-indigo-700 transition hover:bg-indigo-100"
                            >
                                <span aria-hidden="true">×</span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-xs text-slate-500">گزینه‌ای انتخاب نشده است.</p>
            )}
            {error && <p role="alert" className="text-xs text-rose-700">{error}</p>}
        </div>
    );
}

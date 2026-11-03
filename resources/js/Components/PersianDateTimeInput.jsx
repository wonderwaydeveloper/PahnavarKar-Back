import * as DatePickerModule from 'react-multi-date-picker';
import DateObject from 'react-date-object';
import gregorian from 'react-date-object/calendars/gregorian';
import gregorianEn from 'react-date-object/locales/gregorian_en';
import persian from 'react-date-object/calendars/persian';
import persianFa from 'react-date-object/locales/persian_fa';

const DatePicker = DatePickerModule.default?.default ?? DatePickerModule.default;

function parseDateValue(value) {
    if (!value) return null;

    const localDateTime = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?$/.exec(value);
    if (localDateTime) {
        return new Date(
            Number(localDateTime[1]),
            Number(localDateTime[2]) - 1,
            Number(localDateTime[3]),
            Number(localDateTime[4] ?? 0),
            Number(localDateTime[5] ?? 0),
        );
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

function formatLocalDateValue(date) {
    const pad = (part) => String(part).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function getLocalTime(value) {
    const date = parseDateValue(value);
    if (!date) return '';
    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

export default function PersianDateTimeInput({
    value,
    onChange,
    includeTime = false,
    className = '',
}) {
    const selectedDate = parseDateValue(value);
    const inputClassName = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100';

    function updateDate(dateObject) {
        if (!dateObject) {
            onChange('');
            return;
        }

        const localDate = new DateObject(dateObject)
            .convert(gregorian, gregorianEn)
            .format('YYYY-MM-DD');
        if (!includeTime) {
            onChange(localDate);
            return;
        }

        onChange(`${localDate}T${getLocalTime(value) || '00:00'}`);
    }

    function updateTime(time) {
        if (!selectedDate) return;
        const date = formatLocalDateValue(selectedDate);
        onChange(`${date}T${time}`);
    }

    return (
        <div className={`flex w-full gap-2 ${className}`}>
            <DatePicker
                calendar={persian}
                calendarPosition="bottom-right"
                editable={false}
                format="YYYY/MM/DD"
                inputClass={inputClassName}
                locale={persianFa}
                onChange={updateDate}
                portal
                placeholder="انتخاب تاریخ شمسی"
                title="انتخاب تاریخ شمسی"
                value={selectedDate}
            />
            {includeTime ? (
                <input
                    aria-label="ساعت"
                    className={`${inputClassName} max-w-32`}
                    disabled={!selectedDate}
                    dir="ltr"
                    onChange={(event) => updateTime(event.target.value)}
                    type="time"
                    value={getLocalTime(value)}
                />
            ) : null}
        </div>
    );
}

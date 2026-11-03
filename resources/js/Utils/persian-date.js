function parseDate(value) {
    if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
    if (!value) return null;

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

export function formatPersianDate(value, options = { dateStyle: 'medium' }) {
    const date = parseDate(value);
    if (!date) return '—';

    return new Intl.DateTimeFormat('fa-IR', {
        ...options,
        calendar: 'persian',
        numberingSystem: 'arabext',
    }).format(date);
}

export function formatPersianDateTime(value, options = {}) {
    return formatPersianDate(value, {
        dateStyle: 'medium',
        timeStyle: 'short',
        ...options,
    });
}

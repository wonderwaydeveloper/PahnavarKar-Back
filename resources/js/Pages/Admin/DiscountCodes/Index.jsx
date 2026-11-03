import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';
import PersianDateTimeInput from '../../../Components/PersianDateTimeInput';
import { formatPersianDateTime } from '../../../Utils/persian-date';

const numberFormat = new Intl.NumberFormat('fa-IR');
const initialForm = {
    code: '',
    auto_generate_code: true,
    type: 'percentage',
    value: '',
    min_order_amount: 0,
    max_discount: '',
    usage_limit: '',
    user_phones: '',
    starts_at: '',
    expires_at: '',
    is_active: true,
};

function PageLinks({ links }) {
    return (
        <nav className="flex items-center justify-between border-t border-slate-100 px-4 py-4 sm:px-6" aria-label="صفحه‌بندی کدهای تخفیف">
            {links.map((link, index) => {
                const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›');
                return link.url ? <Link key={`${label}-${index}`} href={link.url} preserveScroll className={`rounded-xl px-3 py-2 text-xs font-semibold ${link.active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>{label}</Link> : <span key={`${label}-${index}`} className="px-3 py-2 text-xs text-slate-400">{label}</span>;
            })}
        </nav>
    );
}

function Field({ label, description, showDescription, error, children }) {
    return <label className="block text-xs font-semibold text-slate-700">
        {label}
        {children}
        {showDescription && description && <span className="mt-1.5 block text-[11px] font-normal leading-5 text-slate-500">{description}</span>}
        {error && <span className="mt-1 block font-normal text-rose-600">{error}</span>}
    </label>;
}

export default function Index({ admin, discounts, filters }) {
    const { app, flash } = usePage().props;
    const [query, setQuery] = useState(filters);
    const [editing, setEditing] = useState(null);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [showFieldDescriptions, setShowFieldDescriptions] = useState(true);
    const [copiedCode, setCopiedCode] = useState(null);
    const [copyErrorCode, setCopyErrorCode] = useState(null);
    const form = useForm(initialForm);

    useEffect(() => {
        if (!copiedCode) return undefined;

        const timeoutId = window.setTimeout(() => {
            setCopiedCode(current => current === copiedCode ? null : current);
        }, 1500);

        return () => window.clearTimeout(timeoutId);
    }, [copiedCode]);

    function copyWithLegacyApi(code) {
        const input = document.createElement('textarea');
        input.value = code;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();

        try {
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            input.remove();
        }
    }

    async function copyCode(code) {
        let copied = false;

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(code);
                copied = true;
            } else copied = copyWithLegacyApi(code);
        } catch {
            copied = copyWithLegacyApi(code);
        }

        if (copied) {
            setCopiedCode(code);
            setCopyErrorCode(null);
        } else {
            setCopyErrorCode(code);
            setCopiedCode(null);
        }
    }

    function applyFilters(event) {
        event.preventDefault();
        const params = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== ''));
        router.get('/admin/discount-codes', params, { preserveState: true, preserveScroll: true });
    }

    function openCreate() {
        setEditing(null);
        form.setData({ ...initialForm });
        form.clearErrors();
        setIsModalOpen(true);
    }

    function openEdit(discount) {
        setEditing(discount);
        form.setData({
            code: discount.code,
            auto_generate_code: false,
            type: discount.type,
            value: discount.value,
            min_order_amount: discount.minOrderAmount,
            max_discount: discount.maxDiscount ?? '',
            usage_limit: discount.usageLimit ?? '',
            user_phones: (discount.users?.length ? discount.users : discount.user ? [discount.user] : [])
                .map(user => user.phone)
                .filter(Boolean)
                .join('\n'),
            starts_at: discount.startsAt ?? '',
            expires_at: discount.expiresAt ?? '',
            is_active: discount.isActive,
        });
        form.clearErrors();
        setIsModalOpen(true);
    }

    function submit(event) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            code: data.auto_generate_code ? undefined : data.code.trim().toUpperCase(),
            auto_generate_code: editing ? undefined : data.auto_generate_code,
            value: Number(data.value),
            min_order_amount: Number(data.min_order_amount),
            max_discount: data.max_discount === '' ? null : Number(data.max_discount),
            usage_limit: data.usage_limit === '' ? null : Number(data.usage_limit),
            user_phones: data.user_phones.split(/[\n,،;]+/).map(phone => phone.trim()).filter(Boolean),
            starts_at: data.starts_at || null,
            expires_at: data.expires_at || null,
        }));
        if (editing) {
            form.put(`/admin/discount-codes/${editing.id}`, { preserveScroll: true, onSuccess: () => setIsModalOpen(false) });
        } else {
            form.post('/admin/discount-codes', { preserveScroll: true, onSuccess: () => setIsModalOpen(false) });
        }
    }

    return (
        <>
            <Head title={`کدهای تخفیف | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                    <div className="flex min-w-0 items-center gap-2"><AdminMobileNavigation className="lg:hidden" /><div className="min-w-0"><h1 className="text-sm font-bold text-slate-900">کدهای تخفیف</h1><p className="mt-0.5 hidden truncate text-[11px] text-slate-500 sm:block">{admin.name} · {numberFormat.format(discounts.total)} کد</p></div></div>
                    <div className="flex shrink-0 items-center gap-2 sm:gap-3"><div className="hidden sm:block"><ThemeSwitcher /></div><Link href="/admin" className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600 sm:inline-flex">داشبورد</Link></div>
                </header>
                <main className="mx-auto max-w-7xl px-4 py-8 sm:px-7 lg:px-10">
                    <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                        <div><h2 className="text-2xl font-bold tracking-tight text-slate-950">مدیریت تخفیف‌ها</h2><p className="mt-2 text-sm leading-7 text-slate-500">کد استفاده‌شده حذف نمی‌شود؛ برای توقف استفاده، آن را غیرفعال کنید.</p></div>
                        <button type="button" onClick={openCreate} className="h-11 rounded-xl bg-indigo-600 px-5 text-xs font-semibold text-white hover:bg-indigo-700">ایجاد کد جدید</button>
                    </div>
                    {flash?.status && <div role="status" className="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{flash.status}</div>}
                    {flash?.generatedCode && (
                        <div className="mt-3 flex flex-wrap items-center gap-2 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                            <span>کد یکتای تولیدشده:</span>
                            <code dir="ltr" className="font-mono font-bold">{flash.generatedCode}</code>
                            <button
                                type="button"
                                onClick={() => copyCode(flash.generatedCode)}
                                aria-label="کپی کد تخفیف"
                                title="کپی کد تخفیف"
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-indigo-200 bg-white text-indigo-700 transition hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-1"
                            >
                                {copiedCode === flash.generatedCode ? (
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="m5 12 4 4L19 6" />
                                    </svg>
                                ) : (
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-4 w-4">
                                        <rect width="14" height="14" x="8" y="8" rx="2" />
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3" />
                                    </svg>
                                )}
                            </button>
                            {copiedCode === flash.generatedCode && <span role="status" className="text-xs text-emerald-700">کپی شد</span>}
                            {copyErrorCode === flash.generatedCode && <span role="alert" className="text-xs text-rose-700">کپی انجام نشد.</span>}
                        </div>
                    )}
                    <form onSubmit={applyFilters} className="mt-6 grid gap-3 rounded-3xl border border-slate-200/80 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_12rem_12rem_auto]">
                        <label className="text-xs font-semibold text-slate-600">جست‌وجوی کد
                            <input value={query.search} onChange={e => setQuery({ ...query, search: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm font-normal outline-none focus:border-indigo-400" />
                        </label>
                        <label className="text-xs font-semibold text-slate-600">نوع
                            <select value={query.type} onChange={e => setQuery({ ...query, type: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal outline-none focus:border-indigo-400"><option value="">همه</option><option value="percentage">درصدی</option><option value="fixed">مبلغ ثابت</option></select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">وضعیت
                            <select value={query.status} onChange={e => setQuery({ ...query, status: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal outline-none focus:border-indigo-400"><option value="">همه</option><option value="active">فعال</option><option value="inactive">غیرفعال</option></select>
                        </label>
                        <button type="submit" className="h-11 self-end rounded-xl bg-slate-900 px-5 text-xs font-semibold text-white hover:bg-slate-800">فیلتر</button>
                    </form>
                    <section className="mt-6 overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm">
                        {!discounts.data.length ? <p className="px-6 py-16 text-center text-sm text-slate-500">کدی با این مشخصات وجود ندارد.</p> : <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] text-right">
                                <thead className="bg-slate-50/80 text-[11px] font-semibold text-slate-500"><tr className="[&>th]:px-4 [&>th]:py-3.5 [&>th]:font-semibold"><th>کد</th><th>مقدار</th><th>استفاده</th><th>کاربران مجاز</th><th>اعتبار</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                                <tbody className="divide-y divide-slate-100 text-xs text-slate-700 [&>tr>td]:px-4 [&>tr>td]:py-4">
                                    {discounts.data.map(discount => <tr key={discount.id} className="hover:bg-slate-50/60">
                                        <td dir="ltr" className="font-mono font-bold text-slate-900">
                                            <div className="inline-flex items-center gap-2">
                                                <code>{discount.code}</code>
                                                <button
                                                    type="button"
                                                    onClick={() => copyCode(discount.code)}
                                                    aria-label={`کپی کد ${discount.code}`}
                                                    title={copiedCode === discount.code ? 'کپی شد' : 'کپی کد'}
                                                    className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-1"
                                                >
                                                    {copiedCode === discount.code ? (
                                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4 text-emerald-600">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="m5 12 4 4L19 6" />
                                                        </svg>
                                                    ) : (
                                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-4 w-4">
                                                            <rect width="14" height="14" x="8" y="8" rx="2" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3" />
                                                        </svg>
                                                    )}
                                                </button>
                                                {copyErrorCode === discount.code && <span role="alert" className="font-sans text-[10px] font-normal text-rose-700">کپی نشد</span>}
                                            </div>
                                        </td>
                                        <td className="font-semibold">{discount.type === 'percentage' ? `${numberFormat.format(discount.value)}٪` : `${numberFormat.format(discount.value)} تومان`}</td>
                                        <td>{numberFormat.format(discount.usageCount)}{discount.usageLimit ? ` از ${numberFormat.format(discount.usageLimit)}` : ' بار'}</td>
                                        <td>
                                            {discount.users?.length ? (
                                                <div className="space-y-1">
                                                    {discount.users.map(user => (
                                                        <div key={user.id}>
                                                            <span className="font-semibold">{user.name}</span>
                                                            {user.phone && <div dir="ltr" className="mt-0.5 text-slate-500">{user.phone}</div>}
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : discount.isUserRestricted ? 'کاربر اختصاصی در دسترس نیست' : 'عمومی'}
                                        </td>
                                        <td className="whitespace-nowrap">{discount.startsAt ? formatPersianDateTime(discount.startsAt) : 'بدون شروع'}<div className="mt-1 text-slate-500">{discount.expiresAt ? formatPersianDateTime(discount.expiresAt) : 'بدون انقضا'}</div></td>
                                        <td><span className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${discount.isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{discount.isActive ? 'فعال' : 'غیرفعال'}</span></td>
                                        <td><button type="button" onClick={() => openEdit(discount)} className="rounded-lg bg-indigo-50 px-3 py-2 font-semibold text-indigo-700 hover:bg-indigo-100">ویرایش</button></td>
                                    </tr>)}
                                </tbody>
                            </table>
                        </div>}
                        {discounts.lastPage > 1 && <PageLinks links={discounts.links} />}
                    </section>
                </main>
                </div>
            </div>
            {isModalOpen && <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/45 p-4" role="presentation" onMouseDown={event => {
                if (event.target === event.currentTarget && !form.processing) setIsModalOpen(false);
            }}>
                <form onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="discount-dialog-title" className="admin-modal my-6 w-full max-w-2xl rounded-3xl bg-white p-6 shadow-2xl sm:p-7">
                    <div className="flex items-center justify-between gap-3">
                        <h2 id="discount-dialog-title" className="text-lg font-bold text-slate-900">{editing ? 'ویرایش کد تخفیف' : 'ایجاد کد تخفیف'}</h2>
                        <button
                            type="button"
                            aria-pressed={showFieldDescriptions}
                            onClick={() => setShowFieldDescriptions(visible => !visible)}
                            className="shrink-0 rounded-lg border border-slate-200 px-3 py-2 text-[11px] font-semibold text-slate-600 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-400"
                        >
                            {showFieldDescriptions ? 'پنهان‌کردن توضیحات' : 'نمایش توضیحات'}
                        </button>
                    </div>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        {editing ? (
                            <Field label="کد" description="کد باید یکتا باشد و فقط از حروف انگلیسی، عدد، خط تیره یا زیرخط تشکیل شود." showDescription={showFieldDescriptions} error={form.errors.code}><input required maxLength={64} value={form.data.code} onChange={e => form.setData('code', e.target.value.toUpperCase())} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 font-mono text-sm uppercase outline-none focus:border-indigo-400" /></Field>
                        ) : (
                            <div className="sm:col-span-2">
                                <label className="flex items-start gap-3 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4 text-xs leading-6 text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={form.data.auto_generate_code}
                                        onChange={event => form.setData({
                                            ...form.data,
                                            auto_generate_code: event.target.checked,
                                            code: '',
                                        })}
                                        className="mt-1 size-4 accent-indigo-600"
                                    />
                                    <span>
                                        <span className="block font-semibold text-slate-900">تولید خودکار کد یکتا</span>
                                        {showFieldDescriptions && <span className="mt-1 block font-normal text-slate-600">یک کد تصادفی امن با پیشوند PK ساخته می‌شود و پس از ثبت نمایش داده خواهد شد. برای واردکردن کد دلخواه، این گزینه را غیرفعال کنید.</span>}
                                    </span>
                                </label>
                                {!form.data.auto_generate_code && (
                                    <Field label="کد دلخواه" description="کد باید یکتا باشد و فقط از حروف انگلیسی، عدد، خط تیره یا زیرخط تشکیل شود." showDescription={showFieldDescriptions} error={form.errors.code}>
                                        <input required maxLength={64} value={form.data.code} onChange={event => form.setData('code', event.target.value.toUpperCase())} className="mt-3 h-11 w-full rounded-xl border border-slate-200 px-3 font-mono text-sm uppercase outline-none focus:border-indigo-400" />
                                    </Field>
                                )}
                            </div>
                        )}
                        <Field label="نوع تخفیف" description={form.data.type === 'percentage' ? 'تخفیف به‌صورت درصدی از مبلغ سفارش محاسبه می‌شود.' : 'مبلغ ثابتی از مبلغ سفارش کم می‌شود؛ واحد مبلغ تومان است.'} showDescription={showFieldDescriptions} error={form.errors.type}><select value={form.data.type} onChange={e => form.setData('type', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-400"><option value="percentage">درصدی</option><option value="fixed">مبلغ ثابت</option></select></Field>
                        <Field label={form.data.type === 'percentage' ? 'درصد (۱ تا ۱۰۰)' : 'مبلغ تخفیف (تومان)'} description={form.data.type === 'percentage' ? 'درصد تخفیف از مبلغ سفارش؛ برای کنترل مبلغ نهایی می‌توانید سقف تخفیف را هم تعیین کنید.' : 'مبلغی که از سفارش کم می‌شود؛ این مقدار به تومان وارد می‌شود.'} showDescription={showFieldDescriptions} error={form.errors.value}><input required type="number" min="1" max={form.data.type === 'percentage' ? 100 : undefined} value={form.data.value} onChange={e => form.setData('value', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="حداقل مبلغ سفارش (تومان)" description="کد فقط برای سفارش‌هایی با این مبلغ یا بیشتر قابل استفاده است؛ صفر یعنی حداقل مبلغی تعیین نشده." showDescription={showFieldDescriptions} error={form.errors.min_order_amount}><input required type="number" min="0" value={form.data.min_order_amount} onChange={e => form.setData('min_order_amount', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="سقف تخفیف (اختیاری)" description="حداکثر مبلغ تخفیف به تومان؛ بیشتر برای محدودکردن تخفیف‌های درصدی کاربرد دارد. خالی‌گذاشتن یعنی بدون سقف." showDescription={showFieldDescriptions} error={form.errors.max_discount}><input type="number" min="1" value={form.data.max_discount} onChange={e => form.setData('max_discount', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="سقف تعداد استفاده (اختیاری)" description="حداکثر دفعات استفاده از این کد برای همهٔ کاربران در مجموع؛ خالی‌گذاشتن یعنی بدون محدودیت تعداد." showDescription={showFieldDescriptions} error={form.errors.usage_limit}><input type="number" min="1" value={form.data.usage_limit} onChange={e => form.setData('usage_limit', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <div className="sm:col-span-2">
                            <Field
                                label="شماره تلفن کاربران مجاز (اختیاری)"
                                showDescription={showFieldDescriptions}
                                error={form.errors.user_phones || Object.entries(form.errors).find(([key]) => key.startsWith('user_phones.'))?.[1]}
                            >
                                <textarea
                                    dir="ltr"
                                    inputMode="tel"
                                    autoComplete="off"
                                    rows={3}
                                    placeholder={'09121234567\n+989121234568'}
                                    value={form.data.user_phones}
                                    onChange={event => form.setData('user_phones', event.target.value)}
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-indigo-400"
                                />
                            </Field>
                            {showFieldDescriptions && <p className="mt-1.5 text-[11px] leading-5 text-slate-500">
                                شمارهٔ هر کاربر باید متعلق به حساب ثبت‌شده باشد. شماره‌ها را در خط‌های جدا یا با ویرگول وارد کنید؛ شماره‌های فارسی و لاتین پذیرفته می‌شوند. خالی‌گذاشتن این بخش کد را برای همه قابل استفاده می‌کند.
                            </p>}
                        </div>
                        <Field label="شروع اعتبار (اختیاری)" description="تاریخ و ساعت آغاز اعتبار را به شمسی انتخاب کنید؛ خالی‌گذاشتن یعنی کد از همین حالا محدودیت شروع ندارد." showDescription={showFieldDescriptions} error={form.errors.starts_at}><PersianDateTimeInput includeTime value={form.data.starts_at} onChange={(value) => form.setData('starts_at', value)} /></Field>
                        <Field label="پایان اعتبار (اختیاری)" description="تاریخ و ساعت پایان اعتبار را به شمسی انتخاب کنید؛ خالی‌گذاشتن یعنی کد تاریخ انقضا ندارد." showDescription={showFieldDescriptions} error={form.errors.expires_at}><PersianDateTimeInput includeTime value={form.data.expires_at} onChange={(value) => form.setData('expires_at', value)} /></Field>
                        <div className="sm:col-span-2">
                            <label className="flex items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 text-xs font-semibold text-slate-700">
                                <input type="checkbox" checked={Boolean(form.data.is_active)} onChange={e => form.setData('is_active', e.target.checked)} className="mt-0.5 size-4 accent-indigo-600" />
                                <span>
                                    <span className="block">کد فعال باشد</span>
                                    {showFieldDescriptions && <span className="mt-1 block font-normal leading-5 text-slate-500">کد غیرفعال برای سفارش جدید پذیرفته نمی‌شود؛ هر زمان بخواهید می‌توانید دوباره آن را فعال کنید.</span>}
                                </span>
                            </label>
                        </div>
                    </div>
                    <div className="mt-6 flex justify-end gap-3">
                        <button type="button" onClick={() => setIsModalOpen(false)} disabled={form.processing} className="h-10 rounded-xl border border-slate-200 px-4 text-xs font-semibold text-slate-600">انصراف</button>
                        <button type="submit" disabled={form.processing} className="h-10 rounded-xl bg-indigo-600 px-4 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{form.processing ? 'در حال ثبت…' : editing ? 'ذخیرهٔ تغییرات' : 'ایجاد کد'}</button>
                    </div>
                </form>
            </div>}
        </>
    );
}

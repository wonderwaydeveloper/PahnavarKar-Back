import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';

const numberFormat = new Intl.NumberFormat('fa-IR');
const initialForm = {
    code: '',
    auto_generate_code: true,
    type: 'percentage',
    value: '',
    min_order_amount: 0,
    max_discount: '',
    usage_limit: '',
    user_id: '',
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

function Field({ label, error, children }) {
    return <label className="block text-xs font-semibold text-slate-700">
        {label}
        {children}
        {error && <span className="mt-1 block font-normal text-rose-600">{error}</span>}
    </label>;
}

export default function Index({ admin, discounts, filters }) {
    const { app, flash } = usePage().props;
    const [query, setQuery] = useState(filters);
    const [editing, setEditing] = useState(null);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const form = useForm(initialForm);

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
            user_id: discount.user?.id ?? '',
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
            user_id: data.user_id === '' ? null : Number(data.user_id),
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
                        <div className="mt-3 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                            کد یکتای تولیدشده:
                            {' '}
                            <code dir="ltr" className="font-mono font-bold">{flash.generatedCode}</code>
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
                                <thead className="bg-slate-50/80 text-[11px] font-semibold text-slate-500"><tr className="[&>th]:px-4 [&>th]:py-3.5 [&>th]:font-semibold"><th>کد</th><th>مقدار</th><th>استفاده</th><th>کاربر</th><th>اعتبار</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                                <tbody className="divide-y divide-slate-100 text-xs text-slate-700 [&>tr>td]:px-4 [&>tr>td]:py-4">
                                    {discounts.data.map(discount => <tr key={discount.id} className="hover:bg-slate-50/60">
                                        <td dir="ltr" className="font-mono font-bold text-slate-900">{discount.code}</td>
                                        <td className="font-semibold">{discount.type === 'percentage' ? `${numberFormat.format(discount.value)}٪` : `${numberFormat.format(discount.value)} تومان`}</td>
                                        <td>{numberFormat.format(discount.usageCount)}{discount.usageLimit ? ` از ${numberFormat.format(discount.usageLimit)}` : ' بار'}</td>
                                        <td>{discount.user?.name || 'عمومی'}{discount.user?.phone && <div dir="ltr" className="mt-1 text-slate-500">{discount.user.phone}</div>}</td>
                                        <td className="whitespace-nowrap">{discount.startsAt || 'بدون شروع'}<div className="mt-1 text-slate-500">{discount.expiresAt || 'بدون انقضا'}</div></td>
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
                    <h2 id="discount-dialog-title" className="text-lg font-bold text-slate-900">{editing ? 'ویرایش کد تخفیف' : 'ایجاد کد تخفیف'}</h2>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        {editing ? (
                            <Field label="کد" error={form.errors.code}><input required maxLength={64} value={form.data.code} onChange={e => form.setData('code', e.target.value.toUpperCase())} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 font-mono text-sm uppercase outline-none focus:border-indigo-400" /></Field>
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
                                        <span className="mt-1 block text-slate-600">یک کد تصادفی امن با پیشوند PK ساخته می‌شود و پس از ثبت نمایش داده خواهد شد.</span>
                                    </span>
                                </label>
                                {!form.data.auto_generate_code && (
                                    <Field label="کد دلخواه" error={form.errors.code}>
                                        <input required maxLength={64} value={form.data.code} onChange={event => form.setData('code', event.target.value.toUpperCase())} className="mt-3 h-11 w-full rounded-xl border border-slate-200 px-3 font-mono text-sm uppercase outline-none focus:border-indigo-400" />
                                    </Field>
                                )}
                            </div>
                        )}
                        <Field label="نوع تخفیف" error={form.errors.type}><select value={form.data.type} onChange={e => form.setData('type', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-400"><option value="percentage">درصدی</option><option value="fixed">مبلغ ثابت</option></select></Field>
                        <Field label={form.data.type === 'percentage' ? 'درصد (۱ تا ۱۰۰)' : 'مبلغ تخفیف (تومان)'} error={form.errors.value}><input required type="number" min="1" max={form.data.type === 'percentage' ? 100 : undefined} value={form.data.value} onChange={e => form.setData('value', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="حداقل مبلغ سفارش (تومان)" error={form.errors.min_order_amount}><input required type="number" min="0" value={form.data.min_order_amount} onChange={e => form.setData('min_order_amount', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="سقف تخفیف (اختیاری)" error={form.errors.max_discount}><input type="number" min="1" value={form.data.max_discount} onChange={e => form.setData('max_discount', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="سقف تعداد استفاده (اختیاری)" error={form.errors.usage_limit}><input type="number" min="1" value={form.data.usage_limit} onChange={e => form.setData('usage_limit', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="شناسهٔ کاربر (برای کد اختصاصی، اختیاری)" error={form.errors.user_id}><input type="number" min="1" value={form.data.user_id} onChange={e => form.setData('user_id', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                        <Field label="شروع اعتبار (اختیاری)" error={form.errors.starts_at}><input type="datetime-local" value={form.data.starts_at} onChange={e => form.setData('starts_at', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-indigo-400" /></Field>
                        <Field label="پایان اعتبار (اختیاری)" error={form.errors.expires_at}><input type="datetime-local" value={form.data.expires_at} onChange={e => form.setData('expires_at', e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-indigo-400" /></Field>
                        <label className="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-xs font-semibold text-slate-700 sm:col-span-2">
                            <input type="checkbox" checked={Boolean(form.data.is_active)} onChange={e => form.setData('is_active', e.target.checked)} className="size-4 accent-indigo-600" />
                            کد فعال باشد
                        </label>
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

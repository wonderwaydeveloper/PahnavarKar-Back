import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';

const numberFormat = new Intl.NumberFormat('fa-IR');
const dateFormat = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' });
const typeLabels = {
    deposit: 'افزایش موجودی',
    debit: 'برداشت از کیف پول',
    refund: 'بازپرداخت',
    subscription: 'اشتراک',
    withdrawal_hold: 'رزرو برداشت',
    withdrawal_release: 'آزادسازی برداشت',
};
const statusLabels = {
    succeeded: 'موفق',
    refunded: 'بازپرداخت‌شده',
    released: 'آزادشده',
    pending: 'در انتظار',
    processing: 'در حال پردازش',
    failed: 'ناموفق',
};

function PageLinks({ links }) {
    return (
        <nav className="flex items-center justify-between border-t border-slate-100 px-4 py-4 sm:px-6" aria-label="صفحه‌بندی تراکنش‌ها">
            {links.map((link, index) => {
                const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›');
                return link.url ? (
                    <Link key={`${label}-${index}`} href={link.url} preserveScroll className={`rounded-xl px-3 py-2 text-xs font-semibold ${link.active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>{label}</Link>
                ) : <span key={`${label}-${index}`} className="px-3 py-2 text-xs text-slate-400">{label}</span>;
            })}
        </nav>
    );
}

export default function Index({ admin, transactions, filters }) {
    const { app } = usePage().props;
    const [form, setForm] = useState(filters);

    function applyFilters(event) {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));
        router.get('/admin/transactions', query, { preserveState: true, preserveScroll: true });
    }

    return (
        <>
            <Head title={`تراکنش‌ها | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                    <div className="flex min-w-0 items-center gap-2">
                        <AdminMobileNavigation className="lg:hidden" />
                        <div className="min-w-0">
                        <h1 className="text-sm font-bold text-slate-900">تراکنش‌ها</h1>
                        <p className="mt-0.5 hidden truncate text-[11px] text-slate-500 sm:block">{admin.name} · {numberFormat.format(transactions.total)} رکورد</p>
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                        <div className="hidden sm:block"><ThemeSwitcher /></div>
                        <Link href="/admin" className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600 sm:inline-flex">داشبورد</Link>
                        <Link href="/admin/users" className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600 sm:inline-flex">کاربران</Link>
                    </div>
                </header>
                <main className="mx-auto max-w-7xl px-4 py-8 sm:px-7 lg:px-10">
                    <div className="mb-6">
                        <h2 className="text-2xl font-bold tracking-tight text-slate-950">گزارش تراکنش‌ها</h2>
                        <p className="mt-2 text-sm leading-7 text-slate-500">نمایش فقط‌خواندنی برای بررسی گردش‌های مالی؛ اصلاح یا حذف مستقیم تراکنش از این صفحه انجام نمی‌شود.</p>
                    </div>
                    <form onSubmit={applyFilters} className="grid gap-3 rounded-3xl border border-slate-200/80 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
                        <label className="text-xs font-semibold text-slate-600">جست‌وجوی کاربر یا مرجع
                            <input value={form.search} onChange={e => setForm({ ...form, search: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm font-normal outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100" />
                        </label>
                        <label className="text-xs font-semibold text-slate-600">نوع
                            <select value={form.type} onChange={e => setForm({ ...form, type: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal outline-none focus:border-indigo-400">
                                <option value="">همه</option>{Object.entries(typeLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">وضعیت
                            <select value={form.status} onChange={e => setForm({ ...form, status: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal outline-none focus:border-indigo-400">
                                <option value="">همه</option>{Object.entries(statusLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">از تاریخ
                            <input type="date" value={form.from} onChange={e => setForm({ ...form, from: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs font-normal outline-none focus:border-indigo-400" />
                        </label>
                        <div className="flex items-end gap-2">
                            <label className="min-w-0 flex-1 text-xs font-semibold text-slate-600">تا تاریخ
                                <input type="date" value={form.to} onChange={e => setForm({ ...form, to: e.target.value })} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs font-normal outline-none focus:border-indigo-400" />
                            </label>
                            <button type="submit" className="h-11 shrink-0 rounded-xl bg-indigo-600 px-4 text-xs font-semibold text-white hover:bg-indigo-700">اعمال</button>
                        </div>
                    </form>

                    <section className="mt-6 overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm">
                        {!transactions.data.length ? <p className="px-6 py-16 text-center text-sm text-slate-500">تراکنشی برای این فیلترها پیدا نشد.</p> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[980px] text-right">
                                    <thead className="bg-slate-50/80 text-[11px] font-semibold text-slate-500"><tr className="[&>th]:px-4 [&>th]:py-3.5 [&>th]:font-semibold"><th>کاربر</th><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>درگاه / مرجع</th><th>زمان</th></tr></thead>
                                    <tbody className="divide-y divide-slate-100 text-xs text-slate-700 [&>tr>td]:px-4 [&>tr>td]:py-4">
                                        {transactions.data.map(item => (
                                            <tr key={item.id} className="hover:bg-slate-50/60">
                                                <td><div className="font-semibold text-slate-900">{item.user?.name || 'حساب حذف‌شده'}</div><div dir="ltr" className="mt-1 text-slate-500">{item.user?.phone || '—'}</div></td>
                                                <td>{typeLabels[item.type] || item.type}</td>
                                                <td className="whitespace-nowrap font-bold tabular-nums text-slate-900">{numberFormat.format(item.amount)} {item.currency === 'TOMAN' ? 'تومان' : item.currency}</td>
                                                <td><span className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${item.status === 'succeeded' ? 'bg-emerald-50 text-emerald-700' : item.status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'}`}>{statusLabels[item.status] || item.status}</span></td>
                                                <td><div>{item.gateway || 'کیف پول'}</div><div dir="ltr" className="mt-1 max-w-48 truncate text-slate-500" title={item.reference || ''}>{item.reference || '—'}</div></td>
                                                <td className="whitespace-nowrap text-slate-500">{item.createdAt ? dateFormat.format(new Date(item.createdAt)) : '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {transactions.lastPage > 1 && <PageLinks links={transactions.links} />}
                    </section>
                </main>
                </div>
            </div>
        </>
    );
}

import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';

const numberFormat = new Intl.NumberFormat('fa-IR');
const dateFormat = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' });
const statusLabels = {
    pending: 'در انتظار بررسی',
    approved_pending_payout: 'تأییدشده؛ در انتظار پرداخت',
    rejected: 'ردشده',
    paid: 'پرداخت‌شده',
};

function PageLinks({ links }) {
    return (
        <nav className="flex items-center justify-between border-t border-slate-100 px-4 py-4 sm:px-6" aria-label="صفحه‌بندی درخواست‌های برداشت">
            {links.map((link, index) => {
                const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›');
                return link.url ? <Link key={`${label}-${index}`} href={link.url} preserveScroll className={`rounded-xl px-3 py-2 text-xs font-semibold ${link.active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>{label}</Link> : <span key={`${label}-${index}`} className="px-3 py-2 text-xs text-slate-400">{label}</span>;
            })}
        </nav>
    );
}

export default function Index({ admin, withdrawals, filters }) {
    const { app, flash } = usePage().props;
    const [status, setStatus] = useState(filters.status);
    const [selected, setSelected] = useState(null);
    const form = useForm({ decision: 'approve', note: '' });

    function applyFilter(event) {
        event.preventDefault();
        router.get('/admin/withdrawals', status ? { status } : {}, { preserveState: true, preserveScroll: true });
    }

    function openReview(item, decision) {
        setSelected(item);
        form.setData({ decision, note: '' });
        form.clearErrors();
    }

    function submitReview(event) {
        event.preventDefault();
        form.post(`/admin/withdrawals/${selected.id}/review`, {
            preserveScroll: true,
            onSuccess: () => setSelected(null),
        });
    }

    return (
        <>
            <Head title={`درخواست‌های برداشت | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                    <div className="flex min-w-0 items-center gap-2"><AdminMobileNavigation className="lg:hidden" /><div className="min-w-0"><h1 className="text-sm font-bold text-slate-900">درخواست‌های برداشت</h1><p className="mt-0.5 hidden truncate text-[11px] text-slate-500 sm:block">{admin.name} · {numberFormat.format(withdrawals.total)} درخواست</p></div></div>
                    <div className="flex shrink-0 items-center gap-2 sm:gap-3"><div className="hidden sm:block"><ThemeSwitcher /></div><Link href="/admin" className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600 sm:inline-flex">داشبورد</Link><Link href="/admin/transactions" className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600 sm:inline-flex">تراکنش‌ها</Link></div>
                </header>
                <main className="mx-auto max-w-7xl px-4 py-8 sm:px-7 lg:px-10">
                    <div className="mb-6"><h2 className="text-2xl font-bold tracking-tight text-slate-950">بررسی برداشت‌ها</h2><p className="mt-2 text-sm leading-7 text-slate-500">تأیید، فقط درخواست را برای پرداخت علامت‌گذاری می‌کند؛ انتقال وجه باید خارج از این پنل انجام شود.</p></div>
                    {flash?.status && <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{flash.status}</div>}
                    <form onSubmit={applyFilter} className="mb-6 flex flex-wrap items-end gap-3 rounded-3xl border border-slate-200/80 bg-white p-4">
                        <label className="min-w-52 text-xs font-semibold text-slate-600">وضعیت
                            <select value={status} onChange={e => setStatus(e.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal outline-none focus:border-indigo-400">
                                <option value="">همه</option>{Object.entries(statusLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </select>
                        </label>
                        <button type="submit" className="h-11 rounded-xl bg-indigo-600 px-5 text-xs font-semibold text-white hover:bg-indigo-700">اعمال فیلتر</button>
                    </form>
                    <section className="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm">
                        {!withdrawals.data.length ? <p className="px-6 py-16 text-center text-sm text-slate-500">درخواستی برای نمایش وجود ندارد.</p> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px] text-right">
                                    <thead className="bg-slate-50/80 text-[11px] font-semibold text-slate-500"><tr className="[&>th]:px-4 [&>th]:py-3.5 [&>th]:font-semibold"><th>کاربر</th><th>مبلغ</th><th>وضعیت</th><th>شناسه</th><th>ثبت / بررسی</th><th>اقدام</th></tr></thead>
                                    <tbody className="divide-y divide-slate-100 text-xs text-slate-700 [&>tr>td]:px-4 [&>tr>td]:py-4">
                                        {withdrawals.data.map(item => {
                                            const reviewable = ['pending', 'approved_pending_payout'].includes(item.status);
                                            return <tr key={item.id} className="hover:bg-slate-50/60">
                                                <td><div className="font-semibold text-slate-900">{item.user?.name || 'حساب حذف‌شده'}</div><div dir="ltr" className="mt-1 text-slate-500">{item.user?.phone || '—'}</div></td>
                                                <td className="whitespace-nowrap font-bold text-slate-900">{numberFormat.format(item.amount)} {item.currency === 'TOMAN' ? 'تومان' : item.currency}</td>
                                                <td><span className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${item.status === 'rejected' ? 'bg-rose-50 text-rose-700' : item.status === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>{statusLabels[item.status] || item.status}</span>{item.note && <p className="mt-1 max-w-48 text-slate-500">{item.note}</p>}</td>
                                                <td dir="ltr" className="max-w-48 truncate font-mono text-[10px] text-slate-500" title={item.reference}>{item.reference}</td>
                                                <td className="whitespace-nowrap text-slate-500">{item.createdAt ? dateFormat.format(new Date(item.createdAt)) : '—'}{item.reviewedAt && <div className="mt-1">بررسی: {dateFormat.format(new Date(item.reviewedAt))}</div>}</td>
                                                <td>{item.status === 'pending' ? <div className="flex gap-2"><button type="button" onClick={() => openReview(item, 'approve')} className="rounded-lg bg-emerald-50 px-3 py-2 font-semibold text-emerald-700 hover:bg-emerald-100">تأیید</button><button type="button" onClick={() => openReview(item, 'reject')} className="rounded-lg bg-rose-50 px-3 py-2 font-semibold text-rose-700 hover:bg-rose-100">رد</button></div> : item.status === 'approved_pending_payout' ? <button type="button" onClick={() => openReview(item, 'reject')} className="rounded-lg bg-rose-50 px-3 py-2 font-semibold text-rose-700 hover:bg-rose-100">رد درخواست</button> : '—'}</td>
                                            </tr>;
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {withdrawals.lastPage > 1 && <PageLinks links={withdrawals.links} />}
                    </section>
                </main>
                </div>
            </div>
            {selected && <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/45 p-4">
                <form onSubmit={submitReview} role="dialog" aria-modal="true" aria-labelledby="withdrawal-review-title" className="admin-modal w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl sm:p-7">
                    <h2 id="withdrawal-review-title" className="text-lg font-bold text-slate-900">{form.data.decision === 'approve' ? 'تأیید درخواست برداشت' : 'رد درخواست برداشت'}</h2>
                    <p className="mt-2 text-sm leading-7 text-slate-600">{numberFormat.format(selected.amount)} {selected.currency === 'TOMAN' ? 'تومان' : selected.currency} برای {selected.user?.name || 'حساب حذف‌شده'}.</p>
                    <label className="mt-5 block text-xs font-semibold text-slate-700">یادداشت بررسی (اختیاری)
                        <textarea value={form.data.note} onChange={e => form.setData('note', e.target.value)} maxLength={500} rows={3} className="mt-2 w-full resize-y rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-normal leading-6 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100" />
                    </label>
                    {form.errors.decision && <p role="alert" className="mt-3 text-xs text-rose-700">{form.errors.decision}</p>}
                    <div className="mt-6 flex justify-end gap-3">
                        <button type="button" onClick={() => setSelected(null)} disabled={form.processing} className="h-10 rounded-xl border border-slate-200 px-4 text-xs font-semibold text-slate-600">انصراف</button>
                        <button type="submit" disabled={form.processing} className={`h-10 rounded-xl px-4 text-xs font-semibold text-white disabled:opacity-50 ${form.data.decision === 'approve' ? 'bg-emerald-600' : 'bg-rose-600'}`}>{form.processing ? 'در حال ثبت…' : 'ثبت تصمیم'}</button>
                    </div>
                </form>
            </div>}
        </>
    );
}

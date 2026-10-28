import { Head, Link, usePage } from '@inertiajs/react';
import AdminLogoutButton from '../../Components/AdminLogoutButton';
import AdminMobileNavigation from '../../Components/AdminMobileNavigation';
import AdminSidebar from '../../Components/AdminSidebar';
import ThemeSwitcher from '../../Components/ThemeSwitcher';

const numberFormat = new Intl.NumberFormat('fa-IR');
const dateFormat = new Intl.DateTimeFormat('fa-IR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function formatAmount(amount) {
    return numberFormat.format(amount ?? 0);
}

export default function Dashboard({
    admin,
    stats,
    canManageUsers,
    canManageWallet,
    canManageDiscountCodes,
    canViewTransactions,
    canManageSettings,
    recentTransactions,
}) {
    const { app, flash } = usePage().props;
    return (
        <>
            <Head title={`داشبورد | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                    <header className="sticky top-0 z-10 flex h-[4.5rem] min-w-0 items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                        <div className="flex min-w-0 items-center gap-2 sm:gap-3 lg:hidden">
                            <AdminMobileNavigation className="lg:hidden" />
                            <img src="/favicon.png?v=3" alt="" aria-hidden="true" className="size-9 shrink-0 rounded-xl object-cover" />
                            <span className="truncate text-sm font-bold text-slate-900">{app.name}</span>
                        </div>
                        <div className="hidden text-sm font-semibold text-slate-700 lg:block">داشبورد</div>
                        <div className="flex shrink-0 items-center gap-2 sm:gap-3 lg:gap-4">
                            <div className="hidden sm:block"><ThemeSwitcher /></div>
                            <a
                                href="/"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="hidden h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-100 sm:inline-flex sm:px-3.5"
                            >
                                <svg className="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 5h5v5m0-5l-9 9m5-7v-2H6.5A1.5 1.5 0 005 6.5v11A1.5 1.5 0 006.5 19h11a1.5 1.5 0 001.5-1.5V13" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"/></svg>
                                <span className="hidden sm:inline">مشاهدهٔ سایت</span>
                            </a>
                            <Link href="/admin/profile" className="flex items-center gap-3 rounded-xl focus:outline-none focus:ring-4 focus:ring-indigo-100" aria-label="ویرایش پروفایل">
                                <div className="hidden text-left sm:block">
                                    <div className="text-xs font-semibold text-slate-800">{admin.name}</div>
                                    <div className="mt-0.5 text-[10px] text-slate-500">مدیر سامانه</div>
                                </div>
                                <div className="grid size-10 place-items-center overflow-hidden rounded-2xl bg-indigo-50 text-sm font-bold text-indigo-700 ring-1 ring-inset ring-indigo-100" aria-hidden="true">
                                    {admin.photoUrl
                                        ? <img src={admin.photoUrl} alt="" className="size-full object-cover" />
                                        : admin.name?.slice(0, 1)}
                                </div>
                            </Link>
                            <AdminLogoutButton
                                ariaLabel="خروج از پنل"
                                label={<span className="hidden sm:inline">خروج</span>}
                                className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus:ring-4 focus:ring-slate-100 disabled:cursor-wait disabled:opacity-60 sm:px-3.5"
                            />
                        </div>
                    </header>

                    <main className="mx-auto max-w-7xl px-4 py-8 sm:px-7 sm:py-10 lg:px-10">
                        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                            <div>
                                <div className="flex items-center gap-2 text-xs font-semibold text-indigo-700">
                                    <span className="size-1.5 rounded-full bg-indigo-500" />
                                    نمای کلی سامانه
                                </div>
                                <h1 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">سلام، {admin.name}</h1>
                                <p className="mt-2 text-sm leading-7 text-slate-500">وضعیت بخش‌هایی که مجوز مشاهدهٔ آن‌ها را دارید.</p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="inline-flex w-fit items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-500 shadow-sm">
                                    <svg className="size-4 text-indigo-500" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" strokeWidth="1.7"/><path d="M8 3.5v3m8-3v3M4 9.5h16" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/></svg>
                                    آخرین وضعیت سامانه
                                </div>
                                {canManageSettings && <Link href="/admin/settings" className="inline-flex h-10 items-center rounded-xl bg-indigo-600 px-4 text-xs font-semibold text-white transition hover:bg-indigo-700">تنظیمات سامانه</Link>}
                            </div>
                        </div>

                        {flash.status && (
                            <div className="mt-6 flex gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm leading-7 text-indigo-800" role="status">
                                <svg className="mt-1 size-5 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" strokeWidth="1.7"/><path d="M12 11v5m0-8h.01" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/></svg>
                                {flash.status}
                            </div>
                        )}

                        <section className="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="آمار سامانه">
                            {stats.length > 0 ? stats.map((stat, index) => (
                                <article className="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-900/[0.025] transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-900/[0.04] sm:p-6" key={stat.label}>
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <div className="text-sm font-semibold text-slate-600">{stat.label}</div>
                                            <div className="mt-5 text-3xl font-bold tracking-tight text-slate-950">{formatAmount(stat.value)}</div>
                                            <div className="mt-1.5 text-xs text-slate-500">{stat.description}</div>
                                        </div>
                                        <span className={`grid size-11 place-items-center rounded-2xl ${['bg-indigo-50 text-indigo-600', 'bg-teal-50 text-teal-600', 'bg-amber-50 text-amber-600', 'bg-violet-50 text-violet-600', 'bg-rose-50 text-rose-600'][index % 5]}`} aria-hidden="true">
                                            {[
                                                <svg key="users" className="size-5" viewBox="0 0 24 24" fill="none"><path d="M16 19v-1.5a3.5 3.5 0 00-3.5-3.5h-5A3.5 3.5 0 004 17.5V19m6-8a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm7-6.5a3.5 3.5 0 010 6.8m3 7.7v-1.5a3.5 3.5 0 00-2.5-3.35" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"/></svg>,
                                                <svg key="plan" className="size-5" viewBox="0 0 24 24" fill="none"><path d="M5 5.5A1.5 1.5 0 016.5 4h11A1.5 1.5 0 0119 5.5v13a1.5 1.5 0 01-1.5 1.5h-11A1.5 1.5 0 015 18.5v-13zM8 8h8m-8 4h8m-8 4h5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/></svg>,
                                                <svg key="payments" className="size-5" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" strokeWidth="1.7"/><path d="M12 7v5l3 2" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"/></svg>,
                                                <svg key="discount" className="size-5" viewBox="0 0 24 24" fill="none"><path d="M20 13l-7 7-9-9V4h7l9 9zM8 8h.01" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"/></svg>,
                                                <svg key="wallet" className="size-5" viewBox="0 0 24 24" fill="none"><path d="M4 7.5A2.5 2.5 0 016.5 5H19v14H6.5A2.5 2.5 0 014 16.5v-9zM4 8h15m-4 5h4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"/><circle cx="15" cy="13" r=".8" fill="currentColor"/></svg>,
                                            ][index % 5]}
                                        </span>
                                    </div>
                                </article>
                            )) : (
                                <div className="col-span-full rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm leading-7 text-slate-500">
                                    برای نمایش آمار، مجوزهای پنل به حساب شما تخصیص داده نشده است.
                                </div>
                            )}
                        </section>

                        {canViewTransactions && (
                            <section className="mt-8 overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-900/[0.025]">
                                <div className="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                                    <div>
                                        <h2 className="text-base font-bold text-slate-900">تراکنش‌های اخیر</h2>
                                        <p className="mt-1 text-xs text-slate-500">آخرین فعالیت‌های مالی ثبت‌شده</p>
                                    </div>
                                    <span className="rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-semibold text-slate-600">۶ مورد آخر</span>
                                </div>
                                {recentTransactions.length === 0 ? (
                                    <div className="px-6 py-14 text-center">
                                        <span className="mx-auto grid size-12 place-items-center rounded-2xl bg-slate-100 text-slate-500">
                                            <svg className="size-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 6.5A1.5 1.5 0 016.5 5h11A1.5 1.5 0 0119 6.5v11a1.5 1.5 0 01-1.5 1.5h-11A1.5 1.5 0 015 17.5v-11zM8 9h8m-8 3h8m-8 3h4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/></svg>
                                        </span>
                                        <p className="mt-3 text-sm font-semibold text-slate-700">تراکنشی برای نمایش وجود ندارد.</p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[680px] text-right">
                                            <thead className="bg-slate-50/80 text-[11px] font-semibold text-slate-500">
                                                <tr className="[&>th]:px-5 [&>th]:py-3.5 [&>th]:font-semibold sm:[&>th]:px-6">
                                                    <th scope="col">کاربر</th>
                                                    <th scope="col">نوع</th>
                                                    <th scope="col">مبلغ</th>
                                                    <th scope="col">وضعیت</th>
                                                    <th scope="col">زمان</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 text-xs text-slate-700 [&>tr>td]:px-5 [&>tr>td]:py-4 sm:[&>tr>td]:px-6">
                                                {recentTransactions.map((transaction, index) => (
                                                    <tr className="transition hover:bg-slate-50/70" key={`${transaction.createdAt}-${index}`}>
                                                        <td className="font-semibold text-slate-800">{transaction.userName}</td>
                                                        <td>{transaction.type}</td>
                                                        <td className="font-semibold tabular-nums text-slate-900">
                                                            {formatAmount(transaction.amount)} {transaction.currency === 'TOMAN' ? 'تومان' : transaction.currency}
                                                        </td>
                                                        <td><span className="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">{transaction.status}</span></td>
                                                        <td className="whitespace-nowrap text-slate-500">
                                                            {transaction.createdAt
                                                                ? dateFormat.format(new Date(transaction.createdAt))
                                                                : '—'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>
                        )}
                        <footer className="mt-10 border-t border-slate-200 pt-5 text-center text-[11px] text-slate-400">
                            پنل مدیریت پهناور کار
                        </footer>
                    </main>
                </div>
            </div>
        </>
    );
}

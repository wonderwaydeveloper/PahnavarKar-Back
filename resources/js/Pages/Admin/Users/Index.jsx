import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import MultiSelectField from '../../../Components/MultiSelectField';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';

const dateFormat = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium' });

export default function Index({
    admin,
    users,
    filters,
    canManageRoles,
    assignableRoles = [],
}) {
    const { app, flash } = usePage().props;
    const [selectedUser, setSelectedUser] = useState(null);
    const [accessUser, setAccessUser] = useState(null);
    const form = useForm({ is_active: false, reason: '' });
    const accessForm = useForm({ role_ids: [] });
    const filterForm = useForm({ search: filters.search, status: filters.status });
    const activeOnPage = users.data.filter((user) => user.isActive).length;
    const inactiveOnPage = users.data.length - activeOnPage;

    function applyFilters(event) {
        event.preventDefault();
        const filters = {};
        const search = filterForm.data.search.trim();
        if (search) filters.search = search;
        if (filterForm.data.status) filters.status = filterForm.data.status;

        router.get('/admin/users', filters, { preserveState: true, preserveScroll: true });
    }

    function openStatusDialog(user) {
        setSelectedUser(user);
        form.setData({ is_active: !user.isActive, reason: '' });
        form.clearErrors();
    }

    function updateStatus(event) {
        event.preventDefault();
        form.patch(`/admin/users/${selectedUser.id}/status`, {
            preserveScroll: true,
            onSuccess: () => setSelectedUser(null),
        });
    }

    function openAccessDialog(user) {
        setAccessUser(user);
        accessForm.setData({
            role_ids: user.roles.map((role) => role.id),
        });
        accessForm.clearErrors();
    }

    function updateAccess(event) {
        event.preventDefault();
        accessForm.patch(`/admin/users/${accessUser.id}/access`, {
            preserveScroll: true,
            onSuccess: () => setAccessUser(null),
        });
    }

    return (
        <>
            <Head title={`مدیریت کاربران | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                    <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                        <div className="flex min-w-0 items-center gap-2">
                            <AdminMobileNavigation className="lg:hidden" />
                            <div className="min-w-0">
                            <div className="text-sm font-semibold text-slate-800">مدیریت کاربران</div>
                            <div className="mt-0.5 hidden truncate text-[11px] text-slate-500 lg:hidden sm:block">{admin.name}</div>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            <div className="hidden sm:block"><ThemeSwitcher /></div>
                            <Link href="/admin" className="hidden h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 sm:inline-flex">داشبورد</Link>
                        </div>
                    </header>

                    <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-7 sm:py-9 lg:px-10">
                        <section className="relative overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-7">
                            <div className="pointer-events-none absolute -left-12 -top-16 size-48 rounded-full bg-cyan-100/60 blur-3xl" />
                            <div className="relative flex flex-col justify-between gap-6 md:flex-row md:items-end">
                                <div className="max-w-2xl">
                                    <span className="inline-flex items-center gap-2 rounded-full bg-cyan-50 px-3 py-1.5 text-[11px] font-bold text-cyan-800">
                                        <span className="size-1.5 rounded-full bg-cyan-500" />
                                        مدیریت حساب‌ها
                                    </span>
                                    <h1 className="mt-4 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">کاربران</h1>
                                    <p className="mt-2 text-sm leading-7 text-slate-500">حساب‌ها را جست‌وجو کنید و وضعیت، نقش‌ها و دسترسی‌ها را مدیریت کنید.</p>
                                </div>
                                <div className="grid grid-cols-3 gap-2 sm:gap-3">
                                    {[
                                        { label: 'کل حساب‌ها', value: users.total },
                                        { label: 'فعال در صفحه', value: activeOnPage },
                                        { label: 'غیرفعال در صفحه', value: inactiveOnPage },
                                    ].map((stat) => (
                                        <div key={stat.label} className="min-w-0 rounded-2xl border border-slate-200/80 bg-slate-50/70 px-3 py-2.5 text-center sm:min-w-28 sm:px-4">
                                            <div className="text-lg font-bold tabular-nums text-slate-900">{new Intl.NumberFormat('fa-IR').format(stat.value)}</div>
                                            <div className="mt-0.5 text-[10px] font-medium text-slate-500 sm:text-xs">{stat.label}</div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </section>

                        {flash?.status && (
                            <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{flash.status}</div>
                        )}

                        <form onSubmit={applyFilters} className="grid gap-4 rounded-3xl border border-slate-200/80 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_13rem_auto] sm:items-end sm:p-5">
                            <div className="sm:col-span-3">
                                <h2 className="text-sm font-bold text-slate-800">جست‌وجو و فیلتر</h2>
                                <p className="mt-1 text-xs text-slate-500">بر اساس نام، شمارهٔ تلفن یا وضعیت حساب</p>
                            </div>
                            <label className="block">
                                <span className="mb-2 block text-xs font-semibold text-slate-600">نام یا شمارهٔ تلفن</span>
                                <input
                                    value={filterForm.data.search}
                                    onChange={(event) => filterForm.setData('search', event.target.value)}
                                    maxLength={100}
                                    placeholder="برای مثال: نام کاربر"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                />
                            </label>
                            <label className="block">
                                <span className="mb-2 block text-xs font-semibold text-slate-600">وضعیت حساب</span>
                                <select
                                    value={filterForm.data.status}
                                    onChange={(event) => filterForm.setData('status', event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                >
                                    <option value="">همهٔ وضعیت‌ها</option>
                                    <option value="active">فعال</option>
                                    <option value="inactive">غیرفعال</option>
                                </select>
                            </label>
                            <button type="submit" disabled={filterForm.processing} className="h-11 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60">
                                {filterForm.processing ? 'در حال جست‌وجو…' : 'اعمال فیلتر'}
                            </button>
                        </form>

                        <section className="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm">
                            <div className="flex flex-col justify-between gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:px-6">
                                <div>
                                    <h2 className="font-bold text-slate-900">فهرست کاربران</h2>
                                    <p className="mt-1 text-xs text-slate-500">{new Intl.NumberFormat('fa-IR').format(users.data.length)} کاربر در این صفحه از {new Intl.NumberFormat('fa-IR').format(users.total)} حساب</p>
                                </div>
                                {filters.status && <span className="w-fit rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-semibold text-slate-600">فیلتر: {filters.status === 'active' ? 'فعال' : 'غیرفعال'}</span>}
                            </div>
                            {users.data.length === 0 ? (
                                <div className="px-6 py-16 text-center">
                                    <div className="mx-auto grid size-12 place-items-center rounded-2xl bg-slate-100 text-slate-500" aria-hidden="true">
                                        <svg className="size-6" viewBox="0 0 24 24" fill="none"><circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" strokeWidth="1.7"/><path d="m16 16 4 4M8 10.8h5.6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/></svg>
                                    </div>
                                    <p className="mt-3 text-sm font-semibold text-slate-700">کاربری با این مشخصات پیدا نشد.</p>
                                    <p className="mt-1 text-xs text-slate-500">عبارت جست‌وجو یا وضعیت انتخاب‌شده را تغییر دهید.</p>
                                </div>
                            ) : (
                                <>
                                    <div className="hidden overflow-x-auto md:block">
                                        <table className="w-full min-w-[760px] text-right">
                                        <thead className="bg-slate-50/80 text-[11px] font-semibold text-slate-500">
                                            <tr className="[&>th]:px-5 [&>th]:py-3.5 [&>th]:font-semibold">
                                                <th scope="col">کاربر</th>
                                                <th scope="col">نقش</th>
                                                <th scope="col">وضعیت</th>
                                                <th scope="col">تاریخ عضویت</th>
                                                <th scope="col">عملیات</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 text-xs text-slate-700 [&>tr>td]:px-5 [&>tr>td]:py-4">
                                            {users.data.map((user) => {
                                                const canChangeStatus = !user.isPrivileged || canManageRoles;
                                                return (
                                                    <tr key={user.id} className="transition hover:bg-slate-50/70">
                                                        <td>
                                                            <div className="font-semibold text-slate-900">{user.name}</div>
                                                            <div className="mt-1 text-slate-500" dir="ltr">{user.phone || '—'}</div>
                                                        </td>
                                                        <td>{user.roles.length ? user.roles.map((role) => role.nameFa || role.nameEn || role.key).join('، ') : '—'}</td>
                                                        <td>
                                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold ${user.isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
                                                                {user.isActive ? 'فعال' : 'غیرفعال'}
                                                            </span>
                                                            {!user.isActive && user.statusReason && <p className="mt-1 max-w-52 leading-5 text-slate-500">{user.statusReason}</p>}
                                                        </td>
                                                        <td className="whitespace-nowrap text-slate-500">
                                                            {user.createdAt ? dateFormat.format(new Date(user.createdAt)) : '—'}
                                                        </td>
                                                        <td>
                                                            <div className="flex flex-wrap gap-2">
                                                                {canManageRoles && !user.roles.some((role) => role.key === 'Owner') && (
                                                                    <button type="button" onClick={() => openAccessDialog(user)} className="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100">
                                                                        نقش و دسترسی
                                                                    </button>
                                                                )}
                                                                {canChangeStatus ? (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openStatusDialog(user)}
                                                                        className={`rounded-xl px-3 py-2 text-xs font-semibold transition focus:outline-none focus:ring-4 ${user.isActive ? 'bg-rose-50 text-rose-700 hover:bg-rose-100 focus:ring-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 focus:ring-emerald-100'}`}
                                                                    >
                                                                        {user.isActive ? 'غیرفعال‌کردن' : 'فعال‌سازی'}
                                                                    </button>
                                                                ) : <span className="text-[11px] text-slate-400">نیازمند مجوز مدیریت نقش</span>}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            </tbody>
                                        </table>
                                    </div>
                                    <div className="grid gap-3 p-3 sm:p-4 md:hidden">
                                        {users.data.map((user) => {
                                            const canChangeStatus = !user.isPrivileged || canManageRoles;
                                            return (
                                                <article key={user.id} className="rounded-2xl border border-slate-200/80 p-4">
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="min-w-0">
                                                            <h3 className="truncate font-bold text-slate-900">{user.name}</h3>
                                                            <p className="mt-1 text-xs text-slate-500" dir="ltr">{user.phone || '—'}</p>
                                                        </div>
                                                        <span className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-semibold ${user.isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
                                                            {user.isActive ? 'فعال' : 'غیرفعال'}
                                                        </span>
                                                    </div>
                                                    <div className="mt-3 grid gap-2 border-t border-slate-100 pt-3 text-xs">
                                                        <div className="flex items-start justify-between gap-3">
                                                            <span className="shrink-0 text-slate-500">نقش</span>
                                                            <span className="text-left font-medium text-slate-700">{user.roles.length ? user.roles.map((role) => role.nameFa || role.nameEn || role.key).join('، ') : '—'}</span>
                                                        </div>
                                                        <div className="flex items-center justify-between gap-3">
                                                            <span className="text-slate-500">تاریخ عضویت</span>
                                                            <span className="text-slate-600">{user.createdAt ? dateFormat.format(new Date(user.createdAt)) : '—'}</span>
                                                        </div>
                                                        {!user.isActive && user.statusReason && <p className="rounded-xl bg-rose-50 px-3 py-2 leading-5 text-rose-700">{user.statusReason}</p>}
                                                    </div>
                                                    <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                                                        {canManageRoles && !user.roles.some((role) => role.key === 'Owner') && (
                                                            <button type="button" onClick={() => openAccessDialog(user)} className="min-h-10 rounded-xl bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100">
                                                                نقش و دسترسی
                                                            </button>
                                                        )}
                                                        {canChangeStatus ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => openStatusDialog(user)}
                                                                className={`min-h-10 rounded-xl px-3 py-2 text-xs font-semibold transition ${user.isActive ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'}`}
                                                            >
                                                                {user.isActive ? 'غیرفعال‌کردن' : 'فعال‌سازی'}
                                                            </button>
                                                        ) : <span className="self-center text-[11px] text-slate-400">نیازمند مجوز مدیریت نقش</span>}
                                                    </div>
                                                </article>
                                            );
                                        })}
                                    </div>
                                </>
                            )}

                            {users.lastPage > 1 && (
                                <nav className="flex items-center justify-between border-t border-slate-100 px-4 py-4 sm:px-6" aria-label="صفحه‌بندی کاربران">
                                    {users.links.map((link, index) => (
                                        link.url ? (
                                            <Link
                                                key={`${link.label}-${index}`}
                                                href={link.url}
                                                preserveScroll
                                                className={`rounded-xl px-3 py-2 text-xs font-semibold transition ${link.active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
                                            >
                                                {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                                            </Link>
                                        ) : <span key={`${link.label}-${index}`} className="px-3 py-2 text-xs text-slate-400">{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</span>
                                    ))}
                                </nav>
                            )}
                        </section>
                    </main>
                </div>
            </div>

            {selectedUser && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/45 p-4" role="presentation" onMouseDown={(event) => {
                    if (event.target === event.currentTarget && !form.processing) setSelectedUser(null);
                }}>
                    <form onSubmit={updateStatus} role="dialog" aria-modal="true" aria-labelledby="user-status-title" className="admin-modal w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl sm:p-7">
                        <h2 id="user-status-title" className="text-lg font-bold text-slate-900">
                            {form.data.is_active ? 'فعال‌سازی حساب' : 'غیرفعال‌سازی حساب'}
                        </h2>
                        <p className="mt-2 text-sm leading-7 text-slate-600">
                            {form.data.is_active
                                ? `دسترسی ${selectedUser.name} دوباره فعال می‌شود.`
                                : `دسترسی ${selectedUser.name} قطع و نشست‌ها و توکن‌های فعال لغو می‌شوند. سوابق حساب حذف نخواهد شد.`}
                        </p>
                        {!form.data.is_active && (
                            <label className="mt-5 block">
                                <span className="mb-2 block text-xs font-semibold text-slate-700">علت غیرفعال‌سازی</span>
                                <textarea
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.target.value)}
                                    minLength={5}
                                    maxLength={500}
                                    required
                                    rows={3}
                                    className="w-full resize-y rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-6 text-slate-800 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                />
                                {form.errors.reason && <span className="mt-1 block text-xs text-rose-600">{form.errors.reason}</span>}
                            </label>
                        )}
                        {form.errors.is_active && <p role="alert" className="mt-3 text-xs leading-6 text-rose-700">{form.errors.is_active}</p>}
                        <div className="mt-6 flex justify-end gap-3">
                            <button type="button" onClick={() => setSelectedUser(null)} disabled={form.processing} className="h-10 rounded-xl border border-slate-200 px-4 text-xs font-semibold text-slate-600 disabled:opacity-50">انصراف</button>
                            <button type="submit" disabled={form.processing} className={`h-10 rounded-xl px-4 text-xs font-semibold text-white disabled:cursor-wait disabled:opacity-60 ${form.data.is_active ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'}`}>
                                {form.processing ? 'در حال ثبت…' : form.data.is_active ? 'تأیید فعال‌سازی' : 'تأیید غیرفعال‌سازی'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
            {accessUser && (
                <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/45 p-4" role="presentation" onMouseDown={(event) => {
                    if (event.target === event.currentTarget && !accessForm.processing) setAccessUser(null);
                }}>
                    <form onSubmit={updateAccess} role="dialog" aria-modal="true" aria-labelledby="user-access-title" className="admin-modal my-6 w-full max-w-3xl rounded-3xl bg-white p-6 shadow-2xl sm:p-8">
                        <h2 id="user-access-title" className="text-lg font-bold text-slate-900">مدیریت نقش و دسترسی</h2>
                        <p className="mt-2 text-sm text-slate-600">{accessUser.name} · دسترسی‌های این حساب تنها از نقش‌های انتخاب‌شده دریافت می‌شود.</p>
                        <div className="mt-6">
                            <MultiSelectField
                                label="نقش‌های کاربر"
                                options={assignableRoles.map((role) => ({
                                    id: role.id,
                                    label: role.nameFa || role.nameEn,
                                    description: `${role.nameEn} · ${role.key}`,
                                }))}
                                value={accessForm.data.role_ids}
                                onChange={(roleIds) => accessForm.setData('role_ids', roleIds)}
                                error={accessForm.errors.role_ids}
                                hint="با افزودن یا حذف نقش، دسترسی‌های همان نقش به حساب اعمال می‌شوند."
                            />
                        </div>
                        {accessForm.errors.permission_ids && <p role="alert" className="mt-3 text-xs text-rose-700">{accessForm.errors.permission_ids}</p>}
                        {accessForm.errors.access && <p role="alert" className="mt-3 text-xs text-rose-700">{accessForm.errors.access}</p>}
                        <div className="mt-7 flex justify-end gap-3">
                            <button type="button" onClick={() => setAccessUser(null)} disabled={accessForm.processing} className="h-11 rounded-xl border border-slate-200 px-4 text-xs font-semibold text-slate-600 disabled:opacity-50">انصراف</button>
                            <button type="submit" disabled={accessForm.processing} className="h-11 rounded-xl bg-indigo-600 px-5 text-xs font-semibold text-white disabled:cursor-wait disabled:opacity-60">
                                {accessForm.processing ? 'در حال ذخیره…' : 'ذخیرهٔ دسترسی‌ها'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </>
    );
}

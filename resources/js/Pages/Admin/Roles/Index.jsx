import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import MultiSelectField from '../../../Components/MultiSelectField';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';

export default function Index({ admin, roles, permissions }) {
    const { app, flash } = usePage().props;
    const [editingRole, setEditingRole] = useState(null);
    const [editingPermission, setEditingPermission] = useState(null);
    const roleForm = useForm({ key: '', name_fa: '', name_en: '', permission_ids: [] });
    const permissionForm = useForm({ key: '', name_fa: '', name_en: '' });

    function openRoleEditor(role) {
        setEditingRole(role);
        roleForm.setData({
            key: role.key,
            name_fa: role.nameFa ?? '',
            name_en: role.nameEn ?? '',
            permission_ids: role.permissionIds,
        });
        roleForm.clearErrors();
    }

    function submitRole(event) {
        event.preventDefault();
        if (editingRole) {
            roleForm.put(`/admin/roles/${editingRole.id}`, {
                preserveScroll: true,
                onSuccess: () => setEditingRole(null),
            });
        } else {
            roleForm.post('/admin/roles', {
                preserveScroll: true,
                onSuccess: () => roleForm.reset(),
            });
        }
    }

    function startPermissionEdit(permission) {
        setEditingPermission(permission);
        permissionForm.setData({
            key: permission.key,
            name_fa: permission.nameFa ?? '',
            name_en: permission.nameEn ?? '',
        });
        permissionForm.clearErrors();
    }

    function submitPermission(event) {
        event.preventDefault();
        if (editingPermission) {
            permissionForm.put(`/admin/permissions/${editingPermission.id}`, {
                preserveScroll: true,
                onSuccess: () => setEditingPermission(null),
            });
        } else {
            permissionForm.post('/admin/permissions', {
                preserveScroll: true,
                onSuccess: () => permissionForm.reset(),
            });
        }
    }

    function deleteRole(role) {
        if (role.protected || role.userCount > 0) return;
        if (window.confirm(`نقش «${role.nameFa || role.nameEn}» حذف شود؟`)) {
            router.delete(`/admin/roles/${role.id}`, { preserveScroll: true });
        }
    }

    function deletePermission(permission) {
        if (permission.system || permission.protected) return;
        if (window.confirm(`دسترسی سفارشی «${permission.nameFa}» حذف شود؟`)) {
            router.delete(`/admin/permissions/${permission.id}`, { preserveScroll: true });
        }
    }

    const assignablePermissions = permissions.filter((permission) => permission.key !== 'manage roles');
    const customPermissionCount = permissions.filter((permission) => !permission.system).length;

    return (
        <>
            <Head title={`نقش‌ها و دسترسی‌ها | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                    <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                        <div className="flex min-w-0 items-center gap-2">
                            <AdminMobileNavigation className="lg:hidden" />
                            <div className="min-w-0">
                                <div className="text-sm font-semibold text-slate-800">نقش‌ها و دسترسی‌ها</div>
                                <div className="mt-0.5 hidden truncate text-[11px] text-slate-500 lg:hidden sm:block">{admin.name}</div>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            <div className="hidden sm:block"><ThemeSwitcher /></div>
                        </div>
                    </header>

                    <main className="mx-auto max-w-7xl space-y-7 px-4 py-6 sm:px-7 sm:py-9 lg:px-10">
                        <section className="relative overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-7">
                            <div className="pointer-events-none absolute -left-12 -top-16 size-48 rounded-full bg-cyan-100/60 blur-3xl" />
                            <div className="relative flex flex-col justify-between gap-6 md:flex-row md:items-end">
                                <div className="max-w-2xl">
                                    <span className="inline-flex items-center gap-2 rounded-full bg-cyan-50 px-3 py-1.5 text-[11px] font-bold text-cyan-800">
                                        <span className="size-1.5 rounded-full bg-cyan-500" />
                                        مدیریت دسترسی
                                    </span>
                                    <h1 className="mt-4 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">نقش‌ها و دسترسی‌ها</h1>
                                    <p className="mt-2 text-sm leading-7 text-slate-500">دسترسی اعضای تیم را با نقش‌های مشخص و قابل‌کنترل مدیریت کنید.</p>
                                </div>
                                <div className="grid grid-cols-3 gap-2 sm:gap-3">
                                    {[
                                        { label: 'نقش', value: roles.length },
                                        { label: 'دسترسی', value: permissions.length },
                                        { label: 'سفارشی', value: customPermissionCount },
                                    ].map((stat) => (
                                        <div key={stat.label} className="min-w-0 rounded-2xl border border-slate-200/80 bg-slate-50/70 px-3 py-2.5 text-center sm:min-w-24 sm:px-4">
                                            <div className="text-lg font-bold tabular-nums text-slate-900">{new Intl.NumberFormat('fa-IR').format(stat.value)}</div>
                                            <div className="mt-0.5 text-[10px] font-medium text-slate-500 sm:text-xs">{stat.label}</div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </section>

                        {flash?.status && <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{flash.status}</div>}

                        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(19rem,0.85fr)]">
                            <section className="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm">
                                <div className="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                                    <div>
                                        <h2 className="font-bold text-slate-900">نقش‌های سامانه</h2>
                                        <p className="mt-1 text-xs text-slate-500">نقش‌ها و محدودهٔ دسترسی هرکدام</p>
                                    </div>
                                    <span className="rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-semibold text-slate-600">{new Intl.NumberFormat('fa-IR').format(roles.length)} نقش</span>
                                </div>
                                <div className="grid gap-3 p-4 sm:p-5">
                                    {roles.length === 0 ? (
                                        <div className="rounded-2xl border border-dashed border-slate-300 px-5 py-10 text-center text-sm text-slate-500">هنوز نقشی تعریف نشده است.</div>
                                    ) : roles.map((role) => (
                                        <article key={role.id} className="rounded-2xl border border-slate-200/80 p-4 transition hover:border-slate-300 hover:bg-slate-50/40 sm:p-5">
                                            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="font-bold text-slate-900">{role.nameFa || role.nameEn}</h3>
                                                        {role.protected && <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-semibold text-slate-600">نقش پایه</span>}
                                                    </div>
                                                    <p className="mt-1.5 text-xs text-slate-500"><span dir="ltr">{role.nameEn}</span></p>
                                                    <code dir="ltr" className="mt-2 inline-block max-w-full truncate rounded-lg bg-slate-50 px-2 py-1 font-mono text-[10px] text-slate-500">{role.key}</code>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-2">
                                                    {role.key !== 'Owner' && <button type="button" onClick={() => openRoleEditor(role)} className="h-9 rounded-xl bg-indigo-50 px-3.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100">ویرایش نقش</button>}
                                                    {!role.protected && (
                                                        <button type="button" disabled={role.userCount > 0} onClick={() => deleteRole(role)} title={role.userCount > 0 ? 'ابتدا نقش را از کاربران بردارید.' : undefined} className="h-9 rounded-xl bg-rose-50 px-3.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-40">حذف</button>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
                                                <span><strong className="font-bold text-slate-700">{new Intl.NumberFormat('fa-IR').format(role.permissionIds.length)}</strong> دسترسی</span>
                                                <span><strong className="font-bold text-slate-700">{new Intl.NumberFormat('fa-IR').format(role.userCount)}</strong> کاربر</span>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </section>

                            <section className="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6 xl:sticky xl:top-24">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <span className="text-[11px] font-bold text-indigo-600">{editingRole ? 'ویرایش نقش' : 'تنظیمات نقش'}</span>
                                        <h2 className="mt-1 text-lg font-bold text-slate-900">{editingRole ? (editingRole.nameFa || editingRole.key) : 'ایجاد نقش جدید'}</h2>
                                        <p className="mt-1 text-xs leading-5 text-slate-500">نام نقش را تعیین و دسترسی‌های موردنیاز را انتخاب کنید.</p>
                                    </div>
                                    {editingRole && <button type="button" onClick={() => { setEditingRole(null); roleForm.reset(); roleForm.clearErrors(); }} aria-label="لغو ویرایش نقش" className="grid size-9 shrink-0 place-items-center rounded-xl border border-slate-200 text-lg text-slate-500 transition hover:bg-slate-50">×</button>}
                                </div>
                                <form onSubmit={submitRole} className="mt-5 grid gap-4">
                                    {!editingRole && (
                                        <Field label="کلید فنی انگلیسی" error={roleForm.errors.key}>
                                            <input value={roleForm.data.key} onChange={(event) => roleForm.setData('key', event.target.value)} dir="ltr" placeholder="مثلاً FinanceOperator" maxLength={128} required className={inputClass} />
                                        </Field>
                                    )}
                                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                        <Field label="نام فارسی نقش" error={roleForm.errors.name_fa}>
                                            <input value={roleForm.data.name_fa} onChange={(event) => roleForm.setData('name_fa', event.target.value)} maxLength={120} required className={inputClass} />
                                        </Field>
                                        <Field label="نام انگلیسی نقش" error={roleForm.errors.name_en}>
                                            <input value={roleForm.data.name_en} onChange={(event) => roleForm.setData('name_en', event.target.value)} dir="ltr" maxLength={120} required className={inputClass} />
                                        </Field>
                                    </div>
                                    <MultiSelectField
                                        label="دسترسی‌های نقش"
                                        options={assignablePermissions.map((permission) => ({
                                            id: permission.id,
                                            label: permission.nameFa || permission.nameEn,
                                            description: permission.key,
                                        }))}
                                        value={roleForm.data.permission_ids}
                                        onChange={(permissionIds) => roleForm.setData('permission_ids', permissionIds)}
                                        error={roleForm.errors.permission_ids}
                                        hint="از فهرست انتخاب کنید؛ برای حذف، × را بزنید."
                                    />
                                    <p className="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-5 text-amber-900">
                                        دسترسی مدیریت نقش‌ها فقط در اختیار Owner است و قابل واگذاری نیست.
                                    </p>
                                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                        {editingRole && <button type="button" onClick={() => { setEditingRole(null); roleForm.reset(); roleForm.clearErrors(); }} className="h-11 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">انصراف</button>}
                                        <button type="submit" disabled={roleForm.processing} className="h-11 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60">
                                            {roleForm.processing ? 'در حال ذخیره…' : editingRole ? 'ذخیرهٔ تغییرات' : 'ایجاد نقش'}
                                        </button>
                                    </div>
                                </form>
                            </section>
                        </div>

                        <section className="rounded-3xl border border-amber-200/80 bg-amber-50/70 p-4 sm:p-5">
                            <h2 className="text-sm font-bold text-amber-950">دربارهٔ دسترسی‌های سفارشی</h2>
                            <p className="mt-1.5 text-xs leading-6 text-amber-900">
                                ثبت کلید فنی به‌تنهایی دسترسی اجرایی ایجاد نمی‌کند؛ کلید باید در کد به مسیر یا قابلیت مربوط متصل شده باشد.
                            </p>
                        </section>

                        <section className="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm">
                            <div className="flex flex-col justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:px-6">
                                <div>
                                    <h2 className="font-bold text-slate-900">{editingPermission ? `ویرایش دسترسی: ${editingPermission.nameFa}` : 'دسترسی‌ها'}</h2>
                                    <p className="mt-1 text-xs text-slate-500">مدیریت نام‌ها و کلیدهای دسترسی سیستم</p>
                                </div>
                                <span className="w-fit rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-semibold text-slate-600">{new Intl.NumberFormat('fa-IR').format(permissions.length)} مورد</span>
                            </div>
                            <div className="p-5 sm:p-6">
                                <form onSubmit={submitPermission} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    {!editingPermission && (
                                        <Field label="کلید فنی انگلیسی" error={permissionForm.errors.key}>
                                            <input value={permissionForm.data.key} onChange={(event) => permissionForm.setData('key', event.target.value)} dir="ltr" placeholder="مثلاً export reports" maxLength={128} required className={inputClass} />
                                        </Field>
                                    )}
                                    <Field label="نام فارسی دسترسی" error={permissionForm.errors.name_fa}>
                                        <input value={permissionForm.data.name_fa} onChange={(event) => permissionForm.setData('name_fa', event.target.value)} maxLength={120} required className={inputClass} />
                                    </Field>
                                    <Field label="نام انگلیسی دسترسی" error={permissionForm.errors.name_en}>
                                        <input value={permissionForm.data.name_en} onChange={(event) => permissionForm.setData('name_en', event.target.value)} dir="ltr" maxLength={120} required className={inputClass} />
                                    </Field>
                                    <div className="flex flex-col-reverse gap-2 sm:col-span-2 sm:flex-row sm:justify-end xl:col-span-1 xl:items-end">
                                        {editingPermission && <button type="button" onClick={() => { setEditingPermission(null); permissionForm.reset(); permissionForm.clearErrors(); }} className="h-11 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">انصراف</button>}
                                        <button type="submit" disabled={permissionForm.processing} className="h-11 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60">
                                            {permissionForm.processing ? 'در حال ذخیره…' : editingPermission ? 'ذخیرهٔ نام‌ها' : 'افزودن دسترسی'}
                                        </button>
                                    </div>
                                </form>

                                <div className="mt-6 hidden overflow-x-auto rounded-2xl border border-slate-200 sm:block">
                                    <table className="w-full min-w-[680px] text-right text-xs">
                                        <thead className="bg-slate-50 text-slate-500">
                                            <tr><th className="px-4 py-3">نام فارسی</th><th className="px-4 py-3">نام انگلیسی</th><th className="px-4 py-3">کلید فنی</th><th className="px-4 py-3">نوع</th><th className="px-4 py-3">عملیات</th></tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {permissions.map((permission) => (
                                                <tr key={permission.id} className="transition hover:bg-slate-50/70">
                                                    <td className="px-4 py-3 font-semibold text-slate-800">{permission.nameFa || '—'}</td>
                                                    <td dir="ltr" className="px-4 py-3 text-left text-slate-600">{permission.nameEn || '—'}</td>
                                                    <td dir="ltr" className="px-4 py-3 text-left font-mono text-slate-500">{permission.key}</td>
                                                    <td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${permission.system ? 'bg-slate-100 text-slate-600' : 'bg-cyan-50 text-cyan-800'}`}>{permission.system ? 'پایه' : 'سفارشی'}</span></td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex gap-2">
                                                            <button type="button" onClick={() => startPermissionEdit(permission)} className="rounded-lg bg-indigo-50 px-3 py-2 font-semibold text-indigo-700 transition hover:bg-indigo-100">ویرایش نام</button>
                                                            {!permission.system && !permission.protected && <button type="button" onClick={() => deletePermission(permission)} className="rounded-lg bg-rose-50 px-3 py-2 font-semibold text-rose-700 transition hover:bg-rose-100">حذف</button>}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="mt-5 grid gap-2 sm:hidden">
                                    {permissions.map((permission) => (
                                        <article key={permission.id} className="rounded-2xl border border-slate-200/80 p-3.5">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="text-sm font-semibold text-slate-800">{permission.nameFa || '—'}</h3>
                                                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${permission.system ? 'bg-slate-100 text-slate-600' : 'bg-cyan-50 text-cyan-800'}`}>{permission.system ? 'پایه' : 'سفارشی'}</span>
                                                    </div>
                                                    <p dir="ltr" className="mt-1 text-left text-xs text-slate-500">{permission.nameEn || '—'}</p>
                                                    <code dir="ltr" className="mt-2 block truncate text-left font-mono text-[10px] text-slate-400">{permission.key}</code>
                                                </div>
                                                <div className="flex shrink-0 flex-col gap-1.5">
                                                    <button type="button" onClick={() => startPermissionEdit(permission)} className="rounded-lg bg-indigo-50 px-2.5 py-1.5 text-[11px] font-semibold text-indigo-700">ویرایش</button>
                                                    {!permission.system && !permission.protected && <button type="button" onClick={() => deletePermission(permission)} className="rounded-lg bg-rose-50 px-2.5 py-1.5 text-[11px] font-semibold text-rose-700">حذف</button>}
                                                </div>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </div>
                        </section>
                    </main>
                </div>
            </div>
        </>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="grid gap-2">
            <span className="text-xs font-semibold text-slate-700">{label}</span>
            {children}
            {error && <span role="alert" className="text-xs text-rose-600">{error}</span>}
        </label>
    );
}

const inputClass = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100';

import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';
import { formatPersianDateTime } from '../../../Utils/persian-date';

const numberFormat = new Intl.NumberFormat('fa-IR');

const priorityLabels = {
    normal: 'عادی',
    important: 'مهم',
    urgent: 'فوری',
};

const deliveryLabels = {
    queued: 'در صف ارسال',
    sending: 'در حال تحویل',
    delivered: 'تحویل صندوق کامل شد',
    failed: 'تحویل ناموفق',
};

function PageLinks({ links }) {
    return (
        <nav className="flex items-center justify-between border-t border-slate-100 px-4 py-4 sm:px-6" aria-label="صفحه‌بندی اطلاعیه‌ها">
            {links.map((link, index) => {
                const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›');
                return link.url
                    ? <Link key={`${label}-${index}`} href={link.url} preserveScroll className={`rounded-xl px-3 py-2 text-xs font-semibold ${link.active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>{label}</Link>
                    : <span key={`${label}-${index}`} className="px-3 py-2 text-xs text-slate-400">{label}</span>;
            })}
        </nav>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block text-xs font-semibold text-slate-700">
            {label}
            {children}
            {error && <span role="alert" className="mt-1 block font-normal text-rose-600">{error}</span>}
        </label>
    );
}

export default function Index({ admin, announcements }) {
    const { app, flash } = usePage().props;
    const form = useForm({
        title: '',
        body: '',
        priority: 'normal',
        send_push: false,
    });

    function submit(event) {
        event.preventDefault();
        const confirmation = form.data.send_push
            ? 'این اطلاعیه برای همه حساب‌های موجود ثبت و به دستگاه‌های عضو‌شده نیز Push می‌شود. انتشار غیرقابل‌ویرایش است. ادامه می‌دهید؟'
            : 'این اطلاعیه برای همه حساب‌های موجود ثبت می‌شود و قابل‌ویرایش یا حذف نیست. از انتشار مطمئن هستید؟';
        if (!window.confirm(confirmation)) {
            return;
        }
        form.transform(data => ({
            ...data,
            title: data.title.trim(),
            body: data.body.trim(),
        }));
        form.post('/admin/announcements', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <>
            <Head title={`اطلاعیه‌های رسمی | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                    <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                        <div className="flex min-w-0 items-center gap-2">
                            <AdminMobileNavigation className="lg:hidden" />
                            <div className="min-w-0">
                                <h1 className="text-sm font-bold text-slate-900">اطلاعیه‌های رسمی</h1>
                                <p className="mt-0.5 hidden truncate text-[11px] text-slate-500">{admin.name} · {numberFormat.format(announcements.total)} اطلاعیه</p>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            <div className="hidden sm:block"><ThemeSwitcher /></div>
                            <Link href="/admin" className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600 sm:inline-flex">داشبورد</Link>
                        </div>
                    </header>

                    <main className="mx-auto max-w-7xl px-4 py-8 sm:px-7 lg:px-10">
                        <div>
                            <h2 className="text-2xl font-bold tracking-tight text-slate-950">انتشار اطلاعیه</h2>
                            <p className="mt-2 text-sm leading-7 text-slate-500">
                                اطلاعیه در صندوق اعلان حساب‌های موجود ثبت می‌شود. ارسال Push اختیاری و به‌صورت پیش‌فرض خاموش است.
                            </p>
                        </div>

                        {flash?.status && (
                            <div role="status" className="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{flash.status}</div>
                        )}

                        <form onSubmit={submit} className="mt-6 grid gap-5 rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-7">
                            <div className="grid gap-5 sm:grid-cols-[minmax(0,1fr)_13rem]">
                                <Field label="عنوان اطلاعیه" error={form.errors.title}>
                                    <input
                                        required
                                        maxLength={160}
                                        value={form.data.title}
                                        onChange={event => form.setData('title', event.target.value)}
                                        className="mt-2 h-12 w-full rounded-xl border border-slate-200 px-3 text-sm font-normal outline-none focus:border-indigo-400"
                                        placeholder="عنوان کوتاه و روشن"
                                    />
                                </Field>
                                <Field label="اهمیت" error={form.errors.priority}>
                                    <select
                                        value={form.data.priority}
                                        onChange={event => form.setData('priority', event.target.value)}
                                        className="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal outline-none focus:border-indigo-400"
                                    >
                                        <option value="normal">عادی</option>
                                        <option value="important">مهم</option>
                                        <option value="urgent">فوری</option>
                                    </select>
                                </Field>
                            </div>

                            <Field label="متن اطلاعیه" error={form.errors.body}>
                                <textarea
                                    required
                                    minLength={10}
                                    maxLength={5000}
                                    rows={6}
                                    value={form.data.body}
                                    onChange={event => form.setData('body', event.target.value)}
                                    className="mt-2 w-full resize-y rounded-xl border border-slate-200 px-3 py-3 text-sm font-normal leading-7 outline-none focus:border-indigo-400"
                                    placeholder="متن کامل اطلاعیه را وارد کنید."
                                />
                                <span className="mt-1 block text-left font-normal text-slate-400" dir="ltr">{form.data.body.length}/5000</span>
                            </Field>

                            <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                                <input
                                    type="checkbox"
                                    checked={form.data.send_push}
                                    onChange={event => form.setData('send_push', event.target.checked)}
                                    className="mt-1 size-4 accent-amber-700"
                                />
                                <span>
                                    <span className="block font-semibold">ارسال Push به دستگاه‌های عضو‌شده</span>
                                    <span className="mt-1 block text-xs text-amber-800">Push فقط برای کاربرانی ارسال می‌شود که اعلان دستگاه را فعال کرده‌اند. متن Push همان اطلاعیه است.</span>
                                </span>
                            </label>

                            <div className="flex flex-col-reverse justify-between gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center">
                                <p className="text-xs leading-6 text-slate-500">
                                    اطلاعیه پس از انتشار قابل ویرایش یا حذف نیست؛ قبل از انتشار متن را بررسی کنید.
                                </p>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="h-11 shrink-0 rounded-xl bg-indigo-600 px-5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {form.processing ? 'در حال انتشار…' : 'انتشار اطلاعیه'}
                                </button>
                            </div>
                        </form>

                        <section className="mt-10">
                            <div className="flex items-end justify-between gap-3">
                                <div>
                                    <h2 className="text-xl font-bold text-slate-950">سوابق انتشار</h2>
                                    <p className="mt-1 text-xs text-slate-500">وضعیت تحویل صندوق و ارسال Push در این فهرست ثبت می‌شود.</p>
                                </div>
                                <span className="text-xs text-slate-500">{numberFormat.format(announcements.total)} مورد</span>
                            </div>

                            <div className="mt-4 grid gap-3">
                                {announcements.data.length === 0 ? (
                                    <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center text-sm text-slate-500">
                                        هنوز اطلاعیه‌ای منتشر نشده است.
                                    </div>
                                ) : announcements.data.map(announcement => (
                                    <article key={announcement.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="text-sm font-bold text-slate-900">{announcement.title}</h3>
                                                    <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${announcement.priority === 'urgent' ? 'bg-rose-100 text-rose-700' : announcement.priority === 'important' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>
                                                        {priorityLabels[announcement.priority] ?? 'عادی'}
                                                    </span>
                                                </div>
                                                <p className="mt-3 whitespace-pre-wrap text-sm leading-7 text-slate-600">{announcement.body}</p>
                                            </div>
                                            <div className={`shrink-0 rounded-full px-3 py-1.5 text-[11px] font-semibold ${announcement.deliveryStatus === 'delivered' ? 'bg-emerald-100 text-emerald-700' : announcement.deliveryStatus === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-indigo-50 text-indigo-700'}`}>
                                                {deliveryLabels[announcement.deliveryStatus] ?? 'در صف ارسال'}
                                            </div>
                                        </div>
                                        {announcement.deliveryStatus === 'failed' && (
                                            <button
                                                type="button"
                                                onClick={() => router.post(`/admin/announcements/${announcement.id}/retry`, {}, { preserveScroll: true })}
                                                className="mt-4 rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                            >
                                                تلاش دوباره برای تحویل
                                            </button>
                                        )}
                                        <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
                                            <span>مخاطب: {numberFormat.format(announcement.recipientCount)} حساب</span>
                                            <span>{announcement.sendPush ? 'Push فعال' : 'فقط صندوق اعلان‌ها'}</span>
                                            <span>منتشرکننده: {announcement.publisher ?? 'حساب حذف‌شده'}</span>
                                            <time dateTime={announcement.publishedAt}>{formatPersianDateTime(announcement.publishedAt)}</time>
                                        </div>
                                    </article>
                                ))}
                            </div>

                            {announcements.lastPage > 1 && <div className="mt-4 rounded-2xl border border-slate-200 bg-white"><PageLinks links={announcements.links} /></div>}
                        </section>
                    </main>
                </div>
            </div>
        </>
    );
}

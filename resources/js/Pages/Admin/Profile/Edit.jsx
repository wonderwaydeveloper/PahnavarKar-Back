import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';

export default function Edit({ admin }) {
    const { app, flash } = usePage().props;
    const photoInput = useRef(null);
    const form = useForm({
        name: admin.name,
        photo: null,
        remove_photo: false,
    });
    const [previewUrl, setPreviewUrl] = useState(null);

    useEffect(() => {
        if (!form.data.photo) {
            setPreviewUrl(null);
            return undefined;
        }

        const url = URL.createObjectURL(form.data.photo);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [form.data.photo]);

    function submit(event) {
        event.preventDefault();
        form.post('/admin/profile', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.setData('photo', null);
                form.setData('remove_photo', false);
                if (photoInput.current) photoInput.current.value = '';
            },
        });
    }

    function choosePhoto(event) {
        const photo = event.target.files?.[0] ?? null;
        form.setData((data) => ({ ...data, photo, remove_photo: false }));
        form.clearErrors('photo');
    }

    function removePhoto() {
        form.setData((data) => ({ ...data, photo: null, remove_photo: true }));
        if (photoInput.current) photoInput.current.value = '';
    }

    function cancelPhotoChange() {
        form.setData((data) => ({ ...data, photo: null, remove_photo: false }));
        if (photoInput.current) photoInput.current.value = '';
    }

    const displayedPhoto = previewUrl || (form.data.remove_photo ? null : admin.photoUrl);
    const initials = (form.data.name?.trim().split(/\s+/).slice(0, 2).map((part) => part.slice(0, 1)).join('') ?? '');

    return (
        <>
            <Head title={`ویرایش پروفایل | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                    <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                        <div className="flex min-w-0 items-center gap-2">
                            <AdminMobileNavigation className="lg:hidden" />
                            <div className="truncate text-sm font-semibold text-slate-700">پروفایل مدیر</div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            <div className="hidden sm:block"><ThemeSwitcher /></div>
                            <Link href="/admin" className="hidden h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-semibold text-slate-600 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 sm:inline-flex">
                                بازگشت به داشبورد
                            </Link>
                        </div>
                    </header>

                    <main className="mx-auto max-w-4xl px-4 py-8 sm:px-7 sm:py-10 lg:px-10">
                        <div className="mb-7">
                            <div className="text-xs font-semibold text-indigo-700">تنظیمات حساب</div>
                            <h1 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">ویرایش پروفایل</h1>
                            <p className="mt-2 text-sm leading-7 text-slate-500">نام نمایشی و تصویر حساب مدیریتی خود را به‌روزرسانی کنید.</p>
                            <Link href="/admin/profile/two-factor" className="mt-4 inline-flex h-10 items-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100">
                                مدیریت تأیید دومرحله‌ای
                            </Link>
                        </div>

                        {flash.status && (
                            <div className="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-7 text-emerald-800" role="status">
                                {flash.status}
                            </div>
                        )}

                        <form onSubmit={submit} className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-100 p-5 sm:p-8">
                                <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
                                    <div className="grid size-24 shrink-0 place-items-center overflow-hidden rounded-3xl bg-indigo-50 text-2xl font-bold text-indigo-700 ring-1 ring-inset ring-indigo-100">
                                        {displayedPhoto
                                            ? <img src={displayedPhoto} alt="تصویر پروفایل مدیر" className="size-full object-cover" />
                                            : initials}
                                    </div>
                                    <div className="min-w-0">
                                        <h2 className="text-sm font-bold text-slate-900">تصویر پروفایل</h2>
                                        <p className="mt-1 text-xs leading-6 text-slate-500">JPG، PNG یا WebP؛ حداکثر ۵ مگابایت.</p>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                onClick={() => photoInput.current?.click()}
                                                className="inline-flex h-10 items-center rounded-xl bg-indigo-600 px-4 text-xs font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200"
                                            >
                                                انتخاب تصویر
                                            </button>
                                            {form.data.photo && (
                                                <button type="button" onClick={cancelPhotoChange} className="inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                                    لغو انتخاب
                                                </button>
                                            )}
                                            {!form.data.photo && admin.photoUrl && !form.data.remove_photo && (
                                                <button type="button" onClick={removePhoto} className="inline-flex h-10 items-center rounded-xl border border-rose-200 px-4 text-xs font-semibold text-rose-700 transition hover:bg-rose-50">
                                                    حذف تصویر
                                                </button>
                                            )}
                                        </div>
                                        <input
                                            ref={photoInput}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            className="sr-only"
                                            onChange={choosePhoto}
                                        />
                                        {form.errors.photo && <p className="mt-2 text-xs text-rose-600" role="alert">{form.errors.photo}</p>}
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-5 p-5 sm:p-8">
                                <div className="grid gap-2">
                                    <label htmlFor="name" className="text-sm font-semibold text-slate-700">نام و نام خانوادگی</label>
                                    <input
                                        id="name"
                                        type="text"
                                        autoComplete="name"
                                        maxLength={255}
                                        value={form.data.name}
                                        onChange={(event) => form.setData('name', event.target.value)}
                                        className={`h-12 rounded-xl border bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 ${form.errors.name ? 'border-rose-400' : 'border-slate-200'}`}
                                        required
                                    />
                                    {form.errors.name && <p className="text-xs text-rose-600" role="alert">{form.errors.name}</p>}
                                </div>
                            </div>

                            <div className="flex flex-col-reverse gap-3 border-t border-slate-100 bg-slate-50/70 p-5 sm:flex-row sm:items-center sm:justify-end sm:px-8">
                                <Link href="/admin" className="inline-flex h-12 items-center justify-center rounded-xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">
                                    انصراف
                                </Link>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="inline-flex h-12 items-center justify-center rounded-xl bg-indigo-600 px-6 text-sm font-bold text-white shadow-lg shadow-indigo-600/15 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60"
                                >
                                    {form.processing ? 'در حال ذخیره…' : 'ذخیرهٔ تغییرات'}
                                </button>
                            </div>
                        </form>
                    </main>
                </div>
            </div>
        </>
    );
}

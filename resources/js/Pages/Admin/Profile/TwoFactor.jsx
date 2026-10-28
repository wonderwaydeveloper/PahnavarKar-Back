import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthShell from '../../../Components/AuthShell';

export default function TwoFactor({ twoFactorEnabled, recoveryCodesRemaining, enrollment, recoveryCodes }) {
    const { app, flash } = usePage().props;
    const setupForm = useForm({});
    const confirmForm = useForm({ code: '' });
    const disableForm = useForm({ code: '' });

    function begin() {
        setupForm.post('/admin/profile/two-factor/setup');
    }

    function confirm(event) {
        event.preventDefault();
        confirmForm.post('/admin/profile/two-factor/confirm');
    }

    function disable(event) {
        event.preventDefault();
        disableForm.delete('/admin/profile/two-factor');
    }

    return (
        <>
            <Head title={`تأیید دومرحله‌ای | ${app.name}`} />
            <AuthShell>
                <h1 className="text-2xl font-bold tracking-tight text-slate-950">تأیید دومرحله‌ای</h1>
                <p className="mt-2 text-sm leading-7 text-slate-500">
                    ورود به پنل را با برنامهٔ احراز هویت و کدهای بازیابی ایمن‌تر کنید.
                </p>
                {flash.status && <div className="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{flash.status}</div>}

                {twoFactorEnabled ? (
                    <>
                        <p className="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-7 text-emerald-800">
                            تأیید دومرحله‌ای فعال است؛ {recoveryCodesRemaining} کد بازیابی باقی مانده است.
                        </p>
                        {recoveryCodes?.length > 0 && (
                            <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                <p className="text-sm font-bold text-amber-900">این کدهای بازیابی را ذخیره کنید؛ هرکدام یک‌بار قابل استفاده‌اند.</p>
                                <pre className="mt-3 select-all whitespace-pre-wrap text-center font-mono text-sm leading-7 text-slate-900" dir="ltr">{recoveryCodes.join('\n')}</pre>
                            </div>
                        )}
                        <form className="mt-6 grid gap-3" onSubmit={disable}>
                            <label className="text-sm font-semibold text-slate-700" htmlFor="disable-code">کد برنامه یا کد بازیابی برای خاموش‌کردن</label>
                            <input id="disable-code" dir="ltr" autoComplete="one-time-code" maxLength={64} value={disableForm.data.code} onChange={(event) => disableForm.setData('code', event.target.value)} className="h-12 rounded-xl border border-slate-200 px-4 text-center font-mono tracking-widest" />
                            {disableForm.errors.code && <p className="text-xs text-rose-600" role="alert">{disableForm.errors.code}</p>}
                            <button type="submit" disabled={disableForm.processing} className="h-12 rounded-xl border border-rose-200 font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60">
                                {disableForm.processing ? 'در حال بررسی…' : 'غیرفعال‌کردن'}
                            </button>
                        </form>
                    </>
                ) : enrollment ? (
                    <>
                        <p className="mt-6 text-sm leading-7 text-slate-600">این کلید را به‌صورت دستی در برنامهٔ احراز هویت وارد کنید، سپس کد شش‌رقمی را ثبت کنید.</p>
                        <code className="mt-3 block break-all rounded-xl bg-slate-100 p-4 text-center font-mono text-sm tracking-widest" dir="ltr">{enrollment.secret}</code>
                        <form className="mt-5 grid gap-3" onSubmit={confirm}>
                            <label className="text-sm font-semibold text-slate-700" htmlFor="confirm-code">کد شش‌رقمی برنامه</label>
                            <input id="confirm-code" dir="ltr" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={confirmForm.data.code} onChange={(event) => confirmForm.setData('code', event.target.value.replace(/\D/g, ''))} className="h-14 rounded-xl border border-slate-200 text-center text-xl tracking-[0.4em]" required />
                            {confirmForm.errors.code && <p className="text-xs text-rose-600" role="alert">{confirmForm.errors.code}</p>}
                            <button type="submit" disabled={confirmForm.processing || confirmForm.data.code.length !== 6} className="h-12 rounded-xl bg-indigo-600 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
                                {confirmForm.processing ? 'در حال بررسی…' : 'تأیید و فعال‌سازی'}
                            </button>
                        </form>
                    </>
                ) : (
                    <button type="button" onClick={begin} disabled={setupForm.processing} className="mt-6 h-12 w-full rounded-xl bg-indigo-600 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
                        {setupForm.processing ? 'در حال آماده‌سازی…' : 'راه‌اندازی تأیید دومرحله‌ای'}
                    </button>
                )}

                <Link className="mt-6 block text-center text-sm font-semibold text-indigo-700" href="/admin/profile">بازگشت به پروفایل</Link>
            </AuthShell>
        </>
    );
}

import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthShell from '../../../Components/AuthShell';

export default function Verify() {
    const { app, flash } = usePage().props;
    const verifyForm = useForm({ code: '' });
    const resendForm = useForm({});

    function verify(event) {
        event.preventDefault();
        verifyForm.post('/admin/login/verify');
    }

    function resend(event) {
        event.preventDefault();
        resendForm.post('/admin/login/resend', { preserveScroll: true });
    }

    const codeError = verifyForm.errors.code || resendForm.errors.code;

    return (
        <>
            <Head title={`تأیید کد ورود | ${app.name}`} />
            <AuthShell>
                <h1 className="text-2xl font-bold tracking-tight text-slate-950">تأیید کد ورود</h1>
                <p className="mt-2 text-sm leading-7 text-slate-500">کد شش‌رقمی ارسال‌شده را وارد کنید تا وارد پنل شوید.</p>

                {flash.status && (
                    <div className="mt-5 flex gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm leading-7 text-indigo-800" role="status">
                        <svg className="mt-1 size-5 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" strokeWidth="1.7"/><path d="M12 11v5m0-8h.01" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/></svg>
                        {flash.status}
                    </div>
                )}

                <form className="mt-7" onSubmit={verify}>
                    <div className="grid gap-2">
                        <label className="text-sm font-semibold text-slate-700" htmlFor="code">کد یک‌بارمصرف</label>
                        <input
                            id="code"
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            dir="ltr"
                            className={`h-14 w-full rounded-2xl border bg-white px-4 text-center text-xl font-bold tracking-[0.45em] text-slate-900 outline-none transition placeholder:tracking-[0.45em] placeholder:text-slate-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 ${codeError ? 'border-rose-400' : 'border-slate-200'}`}
                            maxLength={6}
                            value={verifyForm.data.code}
                            onChange={(event) => verifyForm.setData('code', event.target.value)}
                            placeholder="••••••"
                            autoFocus
                            required
                        />
                        {codeError && <span className="text-xs leading-6 text-rose-600" role="alert">{codeError}</span>}
                    </div>
                    <button className="mt-5 inline-flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-5 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60" type="submit" disabled={verifyForm.processing}>
                        {verifyForm.processing ? 'در حال بررسی…' : 'تأیید و ورود'}
                    </button>
                </form>

                <form onSubmit={resend} className="mt-3">
                    <button className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 transition hover:border-indigo-200 hover:bg-indigo-50/50 focus:outline-none focus:ring-4 focus:ring-indigo-100 disabled:cursor-wait disabled:opacity-60" type="submit" disabled={resendForm.processing}>
                        {resendForm.processing ? 'در حال ارسال…' : 'ارسال دوبارهٔ کد'}
                    </button>
                </form>
                <div className="mt-5 text-center text-sm">
                    <Link className="font-semibold text-indigo-700 transition hover:text-indigo-900" href="/admin/login">بازگشت و تغییر شماره</Link>
                </div>
            </AuthShell>
        </>
    );
}

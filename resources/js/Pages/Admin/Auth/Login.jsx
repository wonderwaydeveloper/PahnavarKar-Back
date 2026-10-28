import { Head, useForm, usePage } from '@inertiajs/react';
import AuthShell from '../../../Components/AuthShell';

export default function Login() {
    const { app, flash } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ phone: '' });

    function submit(event) {
        event.preventDefault();
        post('/admin/login/otp');
    }

    return (
        <>
            <Head title={`ورود مدیر | ${app.name}`} />
            <AuthShell>
                <h1 className="text-2xl font-bold tracking-tight text-slate-950">خوش آمدید</h1>
                <p className="mt-2 text-sm leading-7 text-slate-500">برای ورود به پنل، شمارهٔ موبایل حساب مدیریتی خود را وارد کنید.</p>

                {flash.status && (
                    <div className="mt-5 flex gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm leading-7 text-indigo-800" role="status">
                        <svg className="mt-1 size-5 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" strokeWidth="1.7"/><path d="M12 11v5m0-8h.01" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/></svg>
                        {flash.status}
                    </div>
                )}

                <form className="mt-7" onSubmit={submit}>
                    <div className="grid gap-2">
                        <label className="text-sm font-semibold text-slate-700" htmlFor="phone">شمارهٔ موبایل</label>
                        <input
                            id="phone"
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel"
                            dir="ltr"
                            className={`h-14 w-full rounded-2xl border bg-white px-4 text-left text-base text-slate-900 outline-none transition placeholder:text-slate-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 ${errors.phone ? 'border-rose-400' : 'border-slate-200'}`}
                            value={data.phone}
                            onChange={(event) => setData('phone', event.target.value)}
                            placeholder="09xx xxx xxxx"
                            autoFocus
                            required
                        />
                        {errors.phone && <span className="text-xs leading-6 text-rose-600" role="alert">{errors.phone}</span>}
                    </div>
                    <button className="mt-5 inline-flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-5 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60" type="submit" disabled={processing}>
                        {processing ? 'در حال ارسال…' : 'ارسال کد یک‌بارمصرف'}
                        {!processing && <svg className="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7M8 12h12" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/></svg>}
                    </button>
                </form>
            </AuthShell>
        </>
    );
}

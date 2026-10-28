import { usePage } from '@inertiajs/react';
import ThemeSwitcher from './ThemeSwitcher';

export default function AuthShell({ children }) {
    const { app } = usePage().props;

    return (
        <main className="admin-auth-page relative isolate flex min-h-dvh items-center justify-center overflow-hidden bg-slate-50 px-4 py-12 font-sans text-slate-900 sm:px-6">
            <div className="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_20%_10%,rgba(99,102,241,.32),transparent_42%),radial-gradient(ellipse_at_85%_80%,rgba(13,148,136,.2),transparent_38%)]" />
            <div className="absolute -left-24 top-1/3 -z-10 size-72 rounded-full border border-white/[0.06]" />
            <div className="absolute -left-14 top-[38%] -z-10 size-52 rounded-full border border-white/[0.06]" />
            <section className="w-full max-w-md overflow-hidden rounded-[2rem] border border-white/70 bg-white p-7 shadow-2xl shadow-black/20 sm:p-10">
                <div className="mb-9 flex items-center gap-3">
                    <img src="/favicon.png?v=3" alt="" aria-hidden="true" className="size-12 rounded-2xl object-cover shadow-lg shadow-indigo-600/20" />
                    <div>
                        <div className="font-bold tracking-tight text-slate-900">{app.name}</div>
                        <div className="mt-0.5 text-xs text-slate-500">پنل مدیریت · ورود امن</div>
                    </div>
                </div>
                {children}
                <div className="mt-7 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5">
                    <ThemeSwitcher />
                    <div className="flex items-start gap-2.5 text-[11px] leading-6 text-slate-500">
                        <svg className="mt-1 size-4 shrink-0 text-teal-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5l7 3v5.2c0 4.2-2.8 7.5-7 8.8-4.2-1.3-7-4.6-7-8.8V6.5l7-3zM9 12l2 2 4-4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"/></svg>
                        <span>دسترسی فقط برای حساب‌های دارای نقش مدیریتی مجاز است.</span>
                    </div>
                    <a
                        className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-4 focus:ring-indigo-100"
                        href="/"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        مشاهدهٔ سایت
                        <svg className="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 5h5v5m0-5l-9 9m5-7v-2H6.5A1.5 1.5 0 005 6.5v11A1.5 1.5 0 006.5 19h11a1.5 1.5 0 001.5-1.5V13" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"/></svg>
                    </a>
                </div>
            </section>
        </main>
    );
}

import { Head, usePage } from '@inertiajs/react';

const errorContent = {
    403: {
        title: 'دسترسی مجاز نیست',
        description: 'اجازهٔ مشاهدهٔ این صفحه را ندارید.',
    },
    404: {
        title: 'صفحه پیدا نشد',
        description: 'صفحه‌ای که دنبال آن هستید وجود ندارد یا جابه‌جا شده است.',
    },
    500: {
        title: 'خطای داخلی سرور',
        description: 'در پردازش درخواست مشکلی پیش آمد. لطفاً کمی بعد دوباره تلاش کنید.',
    },
};

export default function Show({ status }) {
    const appName = usePage().props.app?.name ?? 'پهناور کار';
    const content = errorContent[status] ?? errorContent[500];

    return (
        <>
            <Head title={`${status} | ${appName}`} />
            <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
                <section
                    className="w-full max-w-xl rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm sm:p-12"
                    aria-labelledby="error-title"
                >
                    <p className="text-sm font-bold tracking-widest text-indigo-600" dir="ltr">
                        {status}
                    </p>
                    <h1 id="error-title" className="mt-4 text-2xl font-bold text-slate-950 sm:text-3xl">
                        {content.title}
                    </h1>
                    <p className="mt-4 text-sm leading-8 text-slate-600">
                        {content.description}
                    </p>
                    <nav className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                        <a
                            className="inline-flex min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-6 text-sm font-bold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200"
                            href="/"
                        >
                            بازگشت به صفحهٔ اصلی
                        </a>
                        <a
                            className="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-300 px-6 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-slate-200"
                            href="/admin/login"
                        >
                            ورود به پنل
                        </a>
                    </nav>
                </section>
            </main>
        </>
    );
}

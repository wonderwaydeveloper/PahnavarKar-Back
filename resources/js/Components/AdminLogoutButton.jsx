import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export default function AdminLogoutButton({ className = '', label = 'خروج از پنل', ariaLabel = 'خروج از پنل' }) {
    const [isConfirmationOpen, setIsConfirmationOpen] = useState(false);
    const form = useForm({});
    const cancelButton = useRef(null);
    const triggerButton = useRef(null);

    useEffect(() => {
        if (!isConfirmationOpen) return undefined;

        cancelButton.current?.focus();
        function closeOnEscape(event) {
            if (event.key === 'Escape' && !form.processing) {
                setIsConfirmationOpen(false);
                triggerButton.current?.focus();
            }
        }

        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [isConfirmationOpen, form.processing]);

    function confirmLogout() {
        form.post('/admin/logout', {
            onSuccess: () => setIsConfirmationOpen(false),
        });
    }

    function closeConfirmation() {
        if (form.processing) return;
        setIsConfirmationOpen(false);
        triggerButton.current?.focus();
    }

    return (
        <>
            <button
                ref={triggerButton}
                type="button"
                aria-label={ariaLabel}
                onClick={() => setIsConfirmationOpen(true)}
                className={className}
            >
                <svg className="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M10 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H10m4-3 4-4-4-4m4 4H9" />
                </svg>
                {label}
            </button>
            {isConfirmationOpen && typeof document !== 'undefined' && createPortal(
                <div
                    className="fixed inset-0 z-[60] grid place-items-center bg-slate-950/55 p-4"
                    onMouseDown={event => {
                        if (event.target === event.currentTarget) closeConfirmation();
                    }}
                >
                    <section
                        role="alertdialog"
                        aria-modal="true"
                        aria-labelledby="admin-logout-title"
                        aria-describedby="admin-logout-description"
                        className="admin-logout-dialog w-full max-w-sm rounded-3xl border border-slate-200 bg-white p-6 text-right shadow-2xl"
                        dir="rtl"
                    >
                        <div className="mx-auto grid size-12 place-items-center rounded-2xl bg-rose-50 text-rose-600">
                            <svg className="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <path d="M10 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H10m4-3 4-4-4-4m4 4H9" />
                            </svg>
                        </div>
                        <h2 id="admin-logout-title" className="mt-4 text-center text-base font-bold text-slate-900">خروج از پنل مدیریت</h2>
                        <p id="admin-logout-description" className="mt-2 text-center text-sm leading-7 text-slate-600">آیا مطمئن هستید که می‌خواهید از حساب مدیریتی خود خارج شوید؟</p>
                        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-center">
                            <button
                                ref={cancelButton}
                                type="button"
                                onClick={closeConfirmation}
                                disabled={form.processing}
                                className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                            >
                                ماندن در پنل
                            </button>
                            <button
                                type="button"
                                onClick={confirmLogout}
                                disabled={form.processing}
                                className="h-11 rounded-xl bg-rose-600 px-5 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-50"
                            >
                                {form.processing ? 'در حال خروج…' : 'بله، خارج شو'}
                            </button>
                        </div>
                    </section>
                </div>,
                document.body,
            )}
        </>
    );
}

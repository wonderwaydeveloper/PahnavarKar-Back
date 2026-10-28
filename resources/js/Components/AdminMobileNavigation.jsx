import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import AdminLogoutButton from './AdminLogoutButton';
import AdminNavigationItems from './AdminNavigationItems';
import ThemeSwitcher from './ThemeSwitcher';

export default function AdminMobileNavigation({ className = '' }) {
    const { app } = usePage().props;
    const [isOpen, setIsOpen] = useState(false);
    useEffect(() => {
        if (!isOpen) return undefined;

        function closeOnEscape(event) {
            if (event.key === 'Escape') setIsOpen(false);
        }

        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [isOpen]);

    return (
        <>
            <button
                type="button"
                aria-label="باز کردن منوی مدیریت"
                aria-expanded={isOpen}
                aria-controls="admin-mobile-navigation"
                onClick={() => setIsOpen(true)}
                className={`grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-teal-100 ${className}`}
            >
                <svg className="size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
                </svg>
            </button>
            {isOpen && typeof document !== 'undefined' && createPortal(
                <div className="fixed inset-0 z-50 flex justify-start bg-slate-950/30 backdrop-blur-[2px]" onMouseDown={event => {
                    if (event.target === event.currentTarget) setIsOpen(false);
                }}>
                    <nav id="admin-mobile-navigation" aria-label="منوی پنل مدیریت" className="admin-mobile-panel admin-menu-scrollbar-hidden flex h-full w-[min(20rem,calc(100vw-2.5rem))] flex-col overflow-y-auto border-l border-slate-200 bg-slate-50 px-5 py-5 text-slate-900 shadow-2xl" dir="rtl">
                        <div className="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                            <div className="flex min-w-0 items-center gap-3">
                                <img src="/favicon.png?v=3" alt="" aria-hidden="true" className="size-10 shrink-0 rounded-xl object-cover shadow-sm" />
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-bold">{app.name}</div>
                                    <div className="mt-1 text-[11px] text-slate-500">پنل مدیریت</div>
                                </div>
                            </div>
                            <button type="button" onClick={() => setIsOpen(false)} aria-label="بستن منو" className="grid size-9 shrink-0 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-900">
                                <svg className="size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" /></svg>
                            </button>
                        </div>
                        <div className="mt-5 flex-1">
                            <AdminNavigationItems onNavigate={() => setIsOpen(false)} />
                        </div>
                        <div className="mt-5 border-t border-slate-200 pt-4">
                            <div className="mb-4 flex justify-center rounded-xl border border-slate-200 bg-white px-3 py-2">
                                <ThemeSwitcher />
                            </div>
                            <AdminLogoutButton className="flex h-11 w-full items-center justify-center gap-3 rounded-xl border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50" />
                        </div>
                    </nav>
                </div>,
                document.body,
            )}
        </>
    );
}

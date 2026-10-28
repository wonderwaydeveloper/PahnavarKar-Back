import { Link } from '@inertiajs/react';
import AdminNavigationItems from './AdminNavigationItems';

export default function AdminSidebar({ brandName }) {
    return (
        <aside className="admin-sidebar fixed inset-y-0 right-0 z-20 hidden w-72 flex-col border-l border-slate-200 bg-white px-5 py-6 text-slate-900 shadow-[0_0_32px_rgba(15,23,42,0.035)] lg:flex">
            <Link href="/admin" className="flex items-center gap-3 rounded-2xl px-2 py-1" aria-label={`${brandName}، داشبورد`}>
                <img src="/favicon.png?v=3" alt="" aria-hidden="true" className="size-11 shrink-0 rounded-2xl object-cover shadow-sm shadow-slate-900/10" />
                <span>
                    <span className="block text-sm font-bold">{brandName}</span>
                    <span className="mt-0.5 block text-xs text-slate-500">مدیریت سامانه</span>
                </span>
            </Link>

            <div className="mt-8 border-t border-slate-100 pt-5">
                <div className="px-3 text-[11px] font-semibold text-slate-500">فضای کاری</div>
            </div>
            <div className="admin-menu-scrollbar-hidden mt-3 min-h-0 flex-1 overflow-y-auto px-1">
                <AdminNavigationItems />
            </div>
        </aside>
    );
}

import { Link, usePage } from '@inertiajs/react';

const groups = [
    {
        id: 'management',
        label: 'مدیریت',
        icon: 'users',
        children: [
            { href: '/admin/users', label: 'مدیریت کاربران', permission: 'users', icon: 'users' },
            { href: '/admin/roles', label: 'نقش‌ها و دسترسی‌ها', permission: 'roles', icon: 'settings' },
            { href: '/admin/discount-codes', label: 'کدهای تخفیف', permission: 'discountCodes', icon: 'discount' },
        ],
    },
    {
        id: 'finance',
        label: 'مالی',
        icon: 'wallet',
        children: [
            { href: '/admin/transactions', label: 'تراکنش‌ها', permission: 'transactions', icon: 'transactions' },
            { href: '/admin/withdrawals', label: 'درخواست‌های برداشت', permission: 'wallet', icon: 'wallet' },
        ],
    },
    {
        id: 'settings',
        label: 'تنظیمات سامانه',
        icon: 'settings',
        children: [
            { href: '/admin/settings/plans', label: 'پلن‌ها و دسترسی امکانات', permission: 'plans', icon: 'plans' },
            { href: '/admin/settings/wallet', label: 'کیف پول و برداشت', permission: 'wallet', icon: 'wallet' },
            { href: '/admin/settings/gateways', label: 'درگاه‌های پرداخت', permission: 'gateways', icon: 'gateways' },
            { href: '/admin/settings/site-content', label: 'محتوای سایت', permission: 'siteContent', icon: 'site' },
        ],
    },
];

const pathOf = href => href.split(/[?#]/)[0];

export function AdminIcon({ name, className = 'size-5' }) {
    const paths = {
        dashboard: <><rect x="3.5" y="3.5" width="7" height="7" rx="1.5" /><rect x="13.5" y="3.5" width="7" height="7" rx="1.5" /><rect x="3.5" y="13.5" width="7" height="7" rx="1.5" /><rect x="13.5" y="13.5" width="7" height="7" rx="1.5" /></>,
        users: <><path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20" /><circle cx="10" cy="7.5" r="3.5" /><path d="M20 20v-1.5a3.5 3.5 0 0 0-2.7-3.4M16.5 4.2a3.5 3.5 0 0 1 0 6.6" /></>,
        transactions: <><rect x="3.5" y="5" width="17" height="14" rx="2" /><path d="M3.5 9h17M7 14h4m2 0h4" /></>,
        wallet: <><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H20v14H6.5A2.5 2.5 0 0 1 4 16.5zM4 8h16" /><path d="M15 13h5" /><circle cx="15" cy="13" r=".5" /></>,
        discount: <><path d="M20 13 13 20 4 11V4h7z" /><circle cx="8" cy="8" r="1" /><path d="m10 14 4-4" /></>,
        settings: <><circle cx="12" cy="12" r="3.5" /><path d="m19.4 15 .1.1a1.8 1.8 0 1 1-2.5 2.5l-.1-.1a1.8 1.8 0 0 0-3 .8v.2a1.8 1.8 0 1 1-3.6 0v-.2a1.8 1.8 0 0 0-3-.8l-.1.1a1.8 1.8 0 1 1-2.5-2.5l.1-.1a1.8 1.8 0 0 0-.8-3h-.2a1.8 1.8 0 1 1 0-3.6h.2a1.8 1.8 0 0 0 .8-3l-.1-.1a1.8 1.8 0 1 1 2.5-2.5l.1.1a1.8 1.8 0 0 0 3-.8v-.2a1.8 1.8 0 1 1 3.6 0v.2a1.8 1.8 0 0 0 3 .8l.1-.1a1.8 1.8 0 1 1 2.5 2.5l-.1.1a1.8 1.8 0 0 0 .8 3h.2a1.8 1.8 0 1 1 0 3.6H20a1.8 1.8 0 0 0-.6 3Z" /></>,
        profile: <><circle cx="12" cy="8" r="3.5" /><path d="M5 20v-1.5a7 7 0 0 1 14 0V20z" /></>,
        site: <><circle cx="12" cy="12" r="9" /><path d="M3.5 12h17M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" /></>,
        plans: <><path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v17H6.5A2.5 2.5 0 0 1 4 17.5v-11A2 2 0 0 1 6 4h13M8 8h7m-7 4h7" /></>,
        gateways: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 9h18M7 14h3" /></>,
    };

    return (
        <svg className={`${className} shrink-0`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {paths[name]}
        </svg>
    );
}

function MenuLink({ item, active, onNavigate }) {
    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={`group/link relative flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm transition ${active
                    ? 'bg-teal-50 font-semibold text-teal-800'
                    : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950'
                }`}
        >
            <span className={`grid size-7 shrink-0 place-items-center rounded-lg ${active ? 'text-teal-800' : 'text-slate-500 group-hover/link:text-slate-700'}`}>
                <AdminIcon name={item.icon} className="size-4" />
            </span>
            <span className="min-w-0 flex-1">{item.label}</span>
            {active && <span className="size-1.5 shrink-0 rounded-full bg-teal-600" />}
        </Link>
    );
}

export default function AdminNavigationItems({ onNavigate }) {
    const page = usePage();
    const { adminNavigation } = page.props;
    const currentPath = pathOf(page.url);
    const visibleGroups = groups.map(group => ({
        ...group,
        children: group.children.filter(item => adminNavigation?.[item.permission]),
    })).filter(group => group.children.length > 0);

    const dashboardActive = currentPath === '/admin';
    const profileActive = currentPath === '/admin/profile';

    return (
        <nav className="grid gap-4" aria-label="منوی پنل مدیریت">
            <MenuLink item={{ href: '/admin', label: 'داشبورد', icon: 'dashboard' }} active={dashboardActive} onNavigate={onNavigate} />
            {visibleGroups.map(group => (
                <section key={group.id} className="grid gap-1">
                    <h2 className="px-2.5 pb-1 text-xs font-semibold text-slate-400">{group.label}</h2>
                    {group.children.map(item => (
                        <MenuLink
                            key={item.href}
                            item={item}
                            active={pathOf(item.href) === currentPath}
                            onNavigate={onNavigate}
                        />
                    ))}
                </section>
            ))}
            <section className="grid gap-1 border-t border-slate-100 pt-3">
                <h2 className="px-2.5 pb-1 text-xs font-semibold text-slate-400">حساب و سایت</h2>
                <MenuLink item={{ href: '/admin/profile', label: 'پروفایل من', icon: 'profile' }} active={profileActive} onNavigate={onNavigate} />
                <a href="/" target="_blank" rel="noopener noreferrer" className="group flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-950">
                    <span className="grid size-7 shrink-0 place-items-center rounded-lg text-slate-500 transition group-hover:text-slate-700">
                        <AdminIcon name="site" className="size-4" />
                    </span>
                    <span className="min-w-0 flex-1">مشاهدهٔ سایت</span>
                    <svg className="size-3.5 text-slate-400 transition group-hover:-translate-x-0.5 group-hover:text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <path d="M14 5h5v5M19 5l-8 8" /><path d="M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
                    </svg>
                </a>
            </section>
        </nav>
    );
}

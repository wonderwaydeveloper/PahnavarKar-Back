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
            { href: '/admin/announcements', label: 'اطلاعیه‌های رسمی', permission: 'announcements', icon: 'announcements' },
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
            { href: '/admin/settings/sms', label: 'سرویس پیامک', permission: 'smsProviders', icon: 'settings' },
            { href: '/admin/settings/site-content', label: 'محتوای سایت', permission: 'siteContent', icon: 'site' },
        ],
    },
];

const pathOf = href => href.split(/[?#]/)[0];

export function AdminIcon({ name, className = 'size-5' }) {
    const icons = {
        dashboard: 'fa-table-cells-large',
        users: 'fa-users',
        transactions: 'fa-money-bill-transfer',
        wallet: 'fa-wallet',
        discount: 'fa-tag',
        announcements: 'fa-bullhorn',
        settings: 'fa-gear',
        profile: 'fa-user',
        site: 'fa-globe',
        plans: 'fa-list-check',
        gateways: 'fa-credit-card',
    };

    return <i className={`fa-solid ${icons[name] ?? 'fa-circle-question'} ${className} shrink-0`} aria-hidden="true" />;
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
                    <i className="fa-solid fa-arrow-up-right-from-square size-3.5 text-slate-400 transition group-hover:-translate-x-0.5 group-hover:text-slate-600" aria-hidden="true"></i>
                </a>
            </section>
        </nav>
    );
}

import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import AdminMobileNavigation from '../../../Components/AdminMobileNavigation';
import AdminSidebar from '../../../Components/AdminSidebar';
import ThemeSwitcher from '../../../Components/ThemeSwitcher';

function Panel({ id, title, description, children }) {
    return (
        <section id={id} className="scroll-mt-24 rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-7">
            <div className="mb-5">
                <h2 className="text-base font-bold text-slate-900">{title}</h2>
                <p className="mt-2 text-xs leading-6 text-slate-500">{description}</p>
            </div>
            {children}
        </section>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block text-xs font-semibold text-slate-700">
            {label}
            {children}
            {error && <span role="alert" className="mt-1 block font-normal text-rose-600">{error}</span>}
        </label>
    );
}

function SaveButton({ processing, label = 'ذخیرهٔ تنظیمات' }) {
    return (
        <button type="submit" disabled={processing} className="h-11 rounded-xl bg-indigo-600 px-5 text-xs font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
            {processing ? 'در حال ذخیره…' : label}
        </button>
    );
}

const sectionDetails = {
    plans: {
        title: 'پلن‌ها و دسترسی امکانات',
        description: 'مدیریت قیمت پلن‌ها و تعیین دسترسی امکانات برای هر سطح اشتراک.',
    },
    wallet: {
        title: 'کیف پول و برداشت',
        description: 'تنظیم سقف درخواست‌های برداشت و فعال یا غیرفعال‌کردن ثبت درخواست جدید.',
    },
    gateways: {
        title: 'درگاه‌های پرداخت',
        description: 'انتخاب درگاه پیش‌فرض و تنظیم ترتیب جایگزین برای پرداخت‌ها.',
    },
    'site-content': {
        title: 'محتوای سایت',
        description: 'ویرایش عنوان‌ها و محتوای اصلی صفحهٔ معرفی وب‌سایت.',
    },
};

export default function Index({ admin, section, permissions, plans, featureGroups, wallet, gateways, siteContent }) {
    const { app, flash } = usePage().props;
    const currentSection = sectionDetails[section];
    const plansForm = useForm({
        plans: {
            tiers: Object.fromEntries(
                Object.entries(plans?.tiers ?? {}).map(([tierKey, tier]) => [
                    tierKey,
                    { features: tier.features },
                ]),
            ),
            offers: Object.fromEntries(
                Object.entries(plans?.offers ?? {}).map(([offerKey, offer]) => [
                    offerKey,
                    { price: offer.price },
                ]),
            ),
        },
    });
    const walletForm = useForm(wallet ?? { enabled: false, max_per_request: 0, max_per_day: 0 });
    const gatewayForm = useForm({
        default_gateway: gateways?.default_gateway ?? '',
        enabled_gateways: gateways?.enabled_gateways ?? [],
        failover_order: gateways?.failover_order ?? [],
    });
    const siteForm = useForm(siteContent ?? {});
    const configuredGateways = gateways?.configured_gateways ?? [];
    const availableGateways = gateways?.available_gateways ?? [];

    function toggleGateway(field, gateway, checked) {
        const values = gatewayForm.data[field];
        const nextValues = checked
            ? [...values, gateway]
            : values.filter(value => value !== gateway);
        if (field === 'enabled_gateways' && !checked) {
            gatewayForm.setData({
                ...gatewayForm.data,
                enabled_gateways: nextValues,
                failover_order: gatewayForm.data.failover_order.filter(value => value !== gateway),
            });
            return;
        }
        gatewayForm.setData(field, nextValues);
    }

    return (
        <>
            <Head title={`${currentSection.title} | ${app.name}`} />
            <div className="admin-page min-h-dvh bg-slate-50 font-sans text-slate-900">
                <AdminSidebar brandName={app.name} />
                <div className="lg:pr-72">
                <header className="sticky top-0 z-10 flex h-[4.5rem] items-center justify-between gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-xl sm:px-7 lg:px-10">
                    <div className="flex min-w-0 items-center gap-2">
                        <AdminMobileNavigation className="lg:hidden" />
                        <div className="min-w-0">
                        <h1 className="text-sm font-bold text-slate-900">{currentSection.title}</h1>
                        <p className="mt-0.5 hidden truncate text-[11px] text-slate-500 sm:block">{admin.name}</p>
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                        <div className="hidden sm:block"><ThemeSwitcher /></div>
                        <Link href="/admin" className="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600 sm:inline-flex">داشبورد</Link>
                    </div>
                </header>
                <main className="mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-7 lg:px-10">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-950">{currentSection.title}</h2>
                        <p className="mt-2 text-sm leading-7 text-slate-500">{currentSection.description}</p>
                    </div>
                    {flash?.status && <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{flash.status}</div>}

                    {section === 'plans' && permissions.plans && <Panel id="plans" title="پلن‌ها و دسترسی امکانات" description="سطح دسترسی از مدت اشتراک جداست. قیمت صفر یعنی خرید آن ترکیب تا زمان تعیین قیمت غیرفعال است؛ قیمت‌های تغییر یافته فقط روی سفارش‌های جدید اعمال می‌شوند.">
                        <form onSubmit={event => { event.preventDefault(); plansForm.put('/admin/settings/plans', { preserveScroll: true }); }}>
                            <div className="grid gap-4 xl:grid-cols-3">
                                {['bronze', 'silver', 'gold'].map(tierKey => (
                                    <section key={tierKey} className="rounded-2xl border border-slate-200 p-4">
                                        <h3 className="text-sm font-bold text-slate-900">{plans.tiers[tierKey].name}</h3>
                                        <p className="mt-1 text-[11px] text-slate-500">قیمت هر بازه (تومان)</p>
                                        <div className="mt-4 grid gap-3">
                                            {Object.entries(plans.durations).map(([durationKey, duration]) => {
                                                const offerKey = `${tierKey}_${durationKey}`;
                                                const errorKey = `plans.offers.${offerKey}.price`;
                                                return (
                                                    <Field key={durationKey} label={duration.name} error={plansForm.errors[errorKey]}>
                                                        <input
                                                            required
                                                            type="number"
                                                            min="0"
                                                            value={plansForm.data.plans.offers[offerKey]?.price ?? 0}
                                                            onChange={event => plansForm.setData('plans', {
                                                                ...plansForm.data.plans,
                                                                offers: {
                                                                    ...plansForm.data.plans.offers,
                                                                    [offerKey]: { price: event.target.value },
                                                                },
                                                            })}
                                                            className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400"
                                                        />
                                                    </Field>
                                                );
                                            })}
                                        </div>
                                    </section>
                                ))}
                            </div>

                            <div className="mt-8">
                                <div>
                                    <h3 className="text-sm font-bold text-slate-900">ماتریس دسترسی امکانات</h3>
                                    <p className="mt-1 text-xs leading-6 text-slate-500">دسترسی هر قابلیت را برای هر سطح مستقل انتخاب کنید. کتابخانهٔ قوانین و مقررات یک قابلیت واحد است.</p>
                                </div>
                                <div className="mt-4 overflow-x-auto rounded-2xl border border-slate-200">
                                    <table className="w-full min-w-[680px] text-right">
                                        <thead className="bg-slate-50 text-xs font-semibold text-slate-600">
                                            <tr>
                                                <th scope="col" className="px-4 py-3">قابلیت برنامه</th>
                                                {Object.entries(plans.tiers).map(([tierKey, tier]) => (
                                                    <th key={tierKey} scope="col" className="px-3 py-3 text-center">{tier.name}</th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {Object.entries(featureGroups).map(([groupName, features]) => (
                                                <Fragment key={groupName}>
                                                    <tr className="bg-slate-50/70">
                                                        <th colSpan={5} scope="colgroup" className="px-4 py-2 text-xs font-bold text-slate-700">{groupName}</th>
                                                    </tr>
                                                    {features.map(feature => (
                                                        <tr key={feature.key} className="text-xs text-slate-700 hover:bg-slate-50/50">
                                                            <th scope="row" className="px-4 py-3 font-medium">{feature.label}</th>
                                                            {Object.entries(plans.tiers).map(([tierKey]) => (
                                                                <td key={tierKey} className="px-3 py-3 text-center">
                                                                    <input
                                                                        type="checkbox"
                                                                        aria-label={`${feature.label} برای پلن ${plans.tiers[tierKey].name}`}
                                                                        checked={Boolean(plansForm.data.plans.tiers[tierKey]?.features[feature.key])}
                                                                        onChange={event => plansForm.setData('plans', {
                                                                            ...plansForm.data.plans,
                                                                            tiers: {
                                                                            ...plansForm.data.plans.tiers,
                                                                            [tierKey]: {
                                                                                features: {
                                                                                    ...plansForm.data.plans.tiers[tierKey].features,
                                                                                    [feature.key]: event.target.checked,
                                                                                },
                                                                            },
                                                                            },
                                                                        })}
                                                                        className="size-4 accent-indigo-600"
                                                                    />
                                                                </td>
                                                            ))}
                                                        </tr>
                                                    ))}
                                                </Fragment>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="mt-5">
                                {plansForm.errors.plans && <p role="alert" className="mb-3 text-xs text-rose-700">{plansForm.errors.plans}</p>}
                                <SaveButton processing={plansForm.processing} label="ذخیرهٔ قیمت‌ها و دسترسی‌ها" />
                            </div>
                        </form>
                    </Panel>}

                    {section === 'wallet' && permissions.wallet && <Panel id="wallet" title="کیف پول و برداشت" description="غیرفعال‌کردن برداشت درخواست‌های تازه را می‌بندد؛ درخواست‌های موجود همچنان در صف بررسی باقی می‌مانند. سقف‌ها بر حسب تومان هستند.">
                        <form onSubmit={event => { event.preventDefault(); walletForm.put('/admin/settings/wallet', { preserveScroll: true }); }}>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="flex min-h-11 items-center gap-3 rounded-xl border border-slate-200 px-4 text-xs font-semibold text-slate-700 sm:col-span-2">
                                    <input type="checkbox" checked={Boolean(walletForm.data.enabled)} onChange={event => walletForm.setData('enabled', event.target.checked)} className="size-4 accent-indigo-600" />
                                    ثبت درخواست برداشت فعال باشد
                                </label>
                                <Field label="حداکثر مبلغ هر درخواست (تومان)" error={walletForm.errors.max_per_request}>
                                    <input required type="number" min="1" value={walletForm.data.max_per_request} onChange={event => walletForm.setData('max_per_request', event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" />
                                </Field>
                                <Field label="حداکثر مبلغ برداشت روزانه (تومان)" error={walletForm.errors.max_per_day}>
                                    <input required type="number" min="1" value={walletForm.data.max_per_day} onChange={event => walletForm.setData('max_per_day', event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" />
                                </Field>
                            </div>
                            <div className="mt-5"><SaveButton processing={walletForm.processing} /></div>
                        </form>
                    </Panel>}

                    {section === 'gateways' && permissions.gateways && <Panel id="gateways" title="درگاه‌های پرداخت" description={`محیط فعلی: ${gateways?.environment === 'production' ? 'عملیاتی' : 'آزمایشی'}. کلیدها و رمزها در این پنل نمایش یا ذخیره نمی‌شوند.`}>
                        {configuredGateways.length === 0 ? <p className="rounded-xl bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-800">هیچ درگاه قابل استفاده‌ای از طریق تنظیمات محیط سرور پیکربندی نشده است. ابتدا اعتبارنامه‌ها را در محیط امن سرور تنظیم کنید.</p> : <>
                            <form onSubmit={event => { event.preventDefault(); gatewayForm.put('/admin/settings/payment-gateways', { preserveScroll: true }); }}>
                                <Field label="درگاه پیش‌فرض" error={gatewayForm.errors.default_gateway}>
                                    <select required value={gatewayForm.data.default_gateway} onChange={event => {
                                        const nextDefault = event.target.value;
                                        gatewayForm.setData({
                                            ...gatewayForm.data,
                                            default_gateway: nextDefault,
                                            enabled_gateways: gatewayForm.data.enabled_gateways.includes(nextDefault)
                                                ? gatewayForm.data.enabled_gateways
                                                : [...gatewayForm.data.enabled_gateways, nextDefault],
                                            failover_order: gatewayForm.data.failover_order.filter(name => name !== nextDefault),
                                        });
                                    }} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-400 sm:max-w-md">
                                        {configuredGateways.map(name => <option key={name} value={name}>{name}</option>)}
                                    </select>
                                </Field>
                                <div className="mt-5 grid gap-5 sm:grid-cols-2">
                                    <fieldset>
                                        <legend className="text-xs font-semibold text-slate-700">درگاه‌های فعال</legend>
                                        <div className="mt-3 space-y-3">
                                            {configuredGateways.map(name => <label key={name} className="flex items-center gap-3 text-xs text-slate-700">
                                                <input type="checkbox" checked={gatewayForm.data.enabled_gateways.includes(name)} onChange={event => toggleGateway('enabled_gateways', name, event.target.checked)} className="size-4 accent-indigo-600" />
                                                <span>{name}</span>
                                                <span className={`text-[10px] ${availableGateways.includes(name) ? 'text-emerald-700' : 'text-amber-700'}`}>{availableGateways.includes(name) ? 'آماده' : 'غیرفعال در پیکربندی فعلی'}</span>
                                            </label>)}
                                        </div>
                                        {gatewayForm.errors.enabled_gateways && <p role="alert" className="mt-2 text-xs text-rose-600">{gatewayForm.errors.enabled_gateways}</p>}
                                    </fieldset>
                                    <fieldset>
                                        <legend className="text-xs font-semibold text-slate-700">ترتیب درگاه جایگزین</legend>
                                        <p className="mt-1 text-[11px] leading-5 text-slate-500">در صورت شکست درگاه پیش‌فرض، درگاه‌های انتخاب‌شده به ترتیب انتخاب بررسی می‌شوند.</p>
                                        <div className="mt-3 space-y-3">
                                            {configuredGateways.filter(name => name !== gatewayForm.data.default_gateway && gatewayForm.data.enabled_gateways.includes(name)).map(name => <label key={name} className="flex items-center gap-3 text-xs text-slate-700">
                                                <input type="checkbox" checked={gatewayForm.data.failover_order.includes(name)} onChange={event => toggleGateway('failover_order', name, event.target.checked)} className="size-4 accent-indigo-600" />
                                                {name}
                                            </label>)}
                                        </div>
                                        {gatewayForm.errors.failover_order && <p role="alert" className="mt-2 text-xs text-rose-600">{gatewayForm.errors.failover_order}</p>}
                                    </fieldset>
                                </div>
                                <p className="mt-4 text-[11px] leading-5 text-slate-500">درگاه‌هایی که اعتبارنامهٔ محیطی آن‌ها وجود ندارد، هرگز برای پرداخت انتخاب نمی‌شوند؛ وضعیت «آماده» صرفاً از پیکربندی سرور گزارش می‌شود.</p>
                                <div className="mt-5"><SaveButton processing={gatewayForm.processing} /></div>
                            </form>
                        </>}
                    </Panel>}

                    {section === 'site-content' && permissions.siteContent && <Panel id="site-content" title="محتوای اصلی سایت" description="این مقادیر در عنوان صفحه، توضیحات موتور جست‌وجو و بخش معرفی صفحهٔ اصلی استفاده می‌شوند.">
                        <form onSubmit={event => { event.preventDefault(); siteForm.put('/admin/settings/site-content', { preserveScroll: true }); }}>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="نام برند" error={siteForm.errors.brand_name}><input required maxLength="80" value={siteForm.data.brand_name ?? ''} onChange={event => siteForm.setData('brand_name', event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                                <Field label="شعار کوتاه" error={siteForm.errors.tagline}><input required maxLength="120" value={siteForm.data.tagline ?? ''} onChange={event => siteForm.setData('tagline', event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                                <Field label="نشان معرفی" error={siteForm.errors.hero_badge}><input required maxLength="120" value={siteForm.data.hero_badge ?? ''} onChange={event => siteForm.setData('hero_badge', event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                                <Field label="عنوان اصلی" error={siteForm.errors.hero_title}><input required maxLength="120" value={siteForm.data.hero_title ?? ''} onChange={event => siteForm.setData('hero_title', event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                                <Field label="بخش رنگی عنوان اصلی" error={siteForm.errors.hero_title_highlight}><input required maxLength="120" value={siteForm.data.hero_title_highlight ?? ''} onChange={event => siteForm.setData('hero_title_highlight', event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400" /></Field>
                                <Field label="توضیح کوتاه" error={siteForm.errors.hero_description}><textarea required maxLength="500" rows="3" value={siteForm.data.hero_description ?? ''} onChange={event => siteForm.setData('hero_description', event.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-6 outline-none focus:border-indigo-400" /></Field>
                                <Field label="توضیحات موتور جست‌وجو" error={siteForm.errors.meta_description}><textarea required maxLength="300" rows="3" value={siteForm.data.meta_description ?? ''} onChange={event => siteForm.setData('meta_description', event.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm leading-6 outline-none focus:border-indigo-400" /></Field>
                            </div>
                            <div className="mt-5"><SaveButton processing={siteForm.processing} /></div>
                        </form>
                    </Panel>}
                </main>
                </div>
            </div>
        </>
    );
}

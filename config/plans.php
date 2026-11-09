<?php

$featureGroups = [
    'اطلاعات سال کارکرد' => [
        'yearly-info' => 'اطلاعات جامع مزدی از سال ۱۳۶۹ تاکنون',
    ],
    'اقلام مزدی مستمر' => [
        'base-salary' => 'حقوق پایه',
        'entitled-seniority' => 'پایه سنوات استحقاقی',
        'housing-allowance' => 'حق مسکن',
        'monthly-allowance' => 'بن کارگری',
        'spousal-allowance' => 'حق تأهل',
        'family-allowance' => 'حق عائله‌مندی',
        'unemployment-insurance-allowance' => 'مبلغ مقرری بیمه بیکاری',
    ],
    'اقلام مزدی غیر مستمر' => [
        'overtime-entitlement' => 'اضافه‌کاری',
        'night-shift-entitlement' => 'شب‌کاری',
        'monthly-shift-work' => 'نوبت‌کاری',
        'minimum-bonus' => 'حداقل عیدی و پاداش',
        'maximum-bonus' => 'حداکثر عیدی و پاداش',
        'bonus-entitlement' => 'عیدی و پاداش استحقاقی',
        'end-of-service-years' => 'سنوات پایان کار',
        'unused-leave-wage' => 'مزد مرخصی ذخیره‌شده کارگر',
        'official-holiday-work' => 'مبلغ تعطیل‌کاری',
        'suspension-wage' => 'حق‌السعی ایام تعلیق',
        'mission-allowance' => 'فوق‌العاده مأموریت',
        'friday-work' => 'جمعه‌کاری',
    ],
    'اقلام غیر مزدی' => [
        'unused-leave-entitlement' => 'میزان مرخصی ذخیره‌شده کارگر',
        'insurance-days-entitlement' => 'تعداد روزهای بیمه استحقاقی',
        'unemployment-insurance-entitlement' => 'مدت زمان پرداخت مقرری بیمه بیکاری',
        'ordinary-work-hours' => 'کارکرد موظفی در مشاغل عادی',
        'hazardous-work-hours' => 'کارکرد موظفی در مشاغل سخت و زیان‌آور',
        'young-worker-work-hours' => 'کارکرد موظفی کارگر نوجوان',
        'official-holidays-in-range' => 'تعداد تعطیلات رسمی در بازه',
        'illegal-foreign-worker-penalty' => 'جریمه به‌کارگیری اتباع غیرمجاز',
        'article-87' => 'مبلغ اعمال ماده ۸۷ قانون کار',
        'social-security-premium-ceiling' => 'سقف حق بیمه تأمین اجتماعی',
    ],
    'محاسبات جامع و قوانین' => [
        'group-calculation' => 'محاسبات جامع',
        'legal_library' => 'قوانین و مقررات (کل کتابخانه)',
    ],
];

$features = [];
$featureDescriptions = [
    'yearly-info' => 'جزئیاتی جامع از مصوبات شورای عالی کار از سال ۱۳۶۹ تاکنون',
    'base-salary' => 'محاسبه حقوق پایه و نمایش جزئیات آن',
    'entitled-seniority' => 'محاسبه پایه سنوات بر اساس تاریخ استخدام، تصفیه حساب تا پایان ۱۳۹۱ و طرح طبقه‌بندی مشاغل',
    'housing-allowance' => 'محاسبه حق مسکن ماهیانه موضوع مصوبه هیات وزیران به تناسب بازه زمانی انتخابی',
    'monthly-allowance' => 'محاسبه بن کارگری مصوبه شورای عالی کار به تناسب بازه زمانی انتخابی',
    'spousal-allowance' => 'محاسبه حق تاهل براساس تصریح مصوبات شورای عالی کار از سال ۱۴۰۳',
    'family-allowance' => 'محاسبه حق عائله مندی براساس بند۲ماده ۸۶قانون تامین اجتماعی',
    'unemployment-insurance-allowance' => 'محاسبه مقرری بیمه بیکاری براساس بند ب ماده ۷ قانون بیمه بیکاری',
    'overtime-entitlement' => 'محاسبه فوق‌العاده اضافه‌کاری براساس شرح ماده ۵۹ قانون کار',
    'night-shift-entitlement' => 'محاسبه فوق‌العاده شب‌کاری براساس شرح ماده ۵۸ قانون کار',
    'monthly-shift-work' => 'محاسبه نوبت‌کاری موضوع ماده ۵۵ قانون کار بر اساس ماده ۵۶ قانون کار',
    'minimum-bonus' => 'محاسبه حداقل عیدی و پاداش ماهیانه براساس ماده واحده قانون تعیین عیدی و پاداش، مصوب مجلس در سال ۱۳۷۰',
    'maximum-bonus' => 'محاسبه حداکثر عیدی و پاداش ماهیانه براساس ماده واحده قانون تعیین عیدی و پاداش، مصوب مجلس در سال ۱۳۷۰',
    'bonus-entitlement' => 'محاسبه عیدی و پاداش ماهیانه بر اساس ماده واحده قانون تعیین عیدی و پاداش، مصوب مجلس در سال ۱۳۷۰',
    'end-of-service-years' => 'محاسبه سنوات پایان کار براساس ماده ۲۴ قانون کار',
    'unused-leave-wage' => 'محاسبه مزد مرخصی ذخیره شده کارگر بر اساس آخرین ماه کارکرد',
    'official-holiday-work' => 'محاسبه مبلغ تعطیل‌کاری‌های مندرج در ماده ۶۳ قانون کار بر اساس پایه سنوات هر دوره',
    'suspension-wage' => 'محاسبه حق‌السعی ایام تعلیق موضوع ماده ۳۴ قانون کار و ماده ۶۷ آیین دادرسی کار',
    'mission-allowance' => 'محاسبه حداقل مبلغ فوق‌العاده مأموریت براساس ماده ۴۶ قانون کار',
    'friday-work' => 'محاسبه مزد جمعه‌کاری‌های انجام‌شده براساس ماده ۶۲ قانون کار',
    'unused-leave-entitlement' => 'محاسبه تعداد مرخصی ذخیره شده کارگر براساس مواد ۶۴ و ۶۹ قانون کار',
    'insurance-days-entitlement' => 'محاسبه تعداد روزهای بیمه موضوع مفاد مواد ۳۹ و ۱۴۸ قانون کار',
    'unemployment-insurance-entitlement' => 'محاسبه مدت زمان استحقاق دریافت مقرری بیمه بیکاری براساس ماده ۷ قانون بیمه بیکاری',
    'ordinary-work-hours' => 'محاسبه میزان ساعات کارکرد موظفی کارگر در مشاغل عادی طبق ماده ۵۱ قانون کار',
    'hazardous-work-hours' => 'تعیین ساعات کارکرد موظفی کارگر طبق ماده ۵۲ قانون کار',
    'young-worker-work-hours' => 'تعیین ساعات کارکرد کارگر نوجوان طبق ماده ۸۰ قانون کار',
    'official-holidays-in-range' => 'تعیین تعداد روزهای تعطیل موضوع ماده ۶۳ قانون کار بر اساس تقویم رسمی کشور؛ جمعه‌کاری تعطیل‌کاری محسوب نمی‌شود',
    'illegal-foreign-worker-penalty' => 'محاسبه جریمه به‌کارگیری اتباع بیگانه غیرمجاز بر اساس تعداد کارگران، روزهای بازه و حداقل مزد همان سال',
    'article-87' => 'محاسبه مبلغ اعمال ماده ۸۷ قانون کار برای صدور پروانه کسب یا بهره‌برداری بر اساس متراژ زیربنا',
    'social-security-premium-ceiling' => 'محاسبه سقف حق بیمه براساس حداقل مزد مصوب شورای عالی کار و تعداد روزهای ماه انتخابی',
    'group-calculation' => 'محاسبهٔ هم‌زمان چند قلم مزدی برای بررسی جمعی.',
    'legal_library' => 'دسترسی به متن قوانین و مقررات مرتبط با کار و تأمین اجتماعی.',
];
foreach ($featureGroups as $group => $items) {
    foreach ($items as $key => $label) {
        $features[$key] = [
            'label' => $label,
            'description' => $featureDescriptions[$key] ?? "دسترسی به {$label} در این پلن فعال است.",
            'group' => $group,
        ];
    }
}

$tiers = [];
foreach ([
    'free' => 'رایگان',
    'bronze' => 'برنزی',
    'silver' => 'نقره‌ای',
    'gold' => 'طلایی',
] as $key => $name) {
    $tiers[$key] = [
        'name' => $name,
        'features' => array_fill_keys(array_keys($features), false),
    ];
}

$durations = [
    '1_month' => ['name' => 'یک‌ماهه', 'duration_days' => 30],
    '3_months' => ['name' => 'سه‌ماهه', 'duration_days' => 90],
    '6_months' => ['name' => 'شش‌ماهه', 'duration_days' => 180],
    '1_year' => ['name' => 'یک‌ساله', 'duration_days' => 365],
];

$offers = [];
foreach (['bronze', 'silver', 'gold'] as $tier) {
    foreach ($durations as $duration => $details) {
        $offers["{$tier}_{$duration}"] = [
            'tier' => $tier,
            'duration' => $duration,
            'duration_days' => $details['duration_days'],
            'price' => 0,
        ];
    }
}

return [
    'features' => $features,
    'tiers' => $tiers,
    'durations' => $durations,
    'offers' => $offers,
];

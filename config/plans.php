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
    'اقلام مزدی غیرمستمر' => [
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
    'اقلام غیرمزدی' => [
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
foreach ($featureGroups as $group => $items) {
    foreach ($items as $key => $label) {
        $features[$key] = ['label' => $label, 'group' => $group];
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

# اجرای Scheduler و Queue Worker روی Ubuntu در ArvanCloud

این راهنما و فایل‌های `deploy/ubuntu/*.example` الگوی آغازین برای یک سرور Ubuntu هستند؛ هیچ سرور ArvanCloud ایجاد یا تغییر داده نشده است. پیش از نصب، مسیر برنامه و کاربر سرویس را با چیدمان واقعی سرور جایگزین کنید. از اجرای Laravel با `root` خودداری کنید.

## تنظیم Queue Worker

صف فعلی برنامه از اتصال `database` استفاده می‌کند. OTP در صف `sms` و Push در صف پیش‌فرض قرار می‌گیرند؛ برای شروع یک worker صف‌های `sms,default` را به همین ترتیب پردازش می‌کند. تنظیمات jobها حداکثر سه تلاش دارند و `retry_after` صف دیتابیس ۹۰ ثانیه است. قالب Supervisor مقدار timeout را ۶۰ ثانیه می‌گذارد تا از `retry_after` کوتاه‌تر باشد.

1. در سرور Ubuntu، PHP و extensionهای موردنیاز برنامه، Composer، Cron و Supervisor را نصب و نسخه‌ها را با `composer.json` و مستندات Laravel تطبیق دهید.
2. مسیر ثابت برنامه در قالب Supervisor و Cron را به مسیر واقعی استقرار تغییر دهید. نمونه از `/var/www/pahnavarkar/current` استفاده می‌کند؛ اگر از symlink برای releaseها استفاده می‌کنید، این مسیر باید پس از هر استقرار همچنان به release فعال اشاره کند.
3. `www-data` را در هر دو قالب با کاربر غیرریشه‌ای که به کد، `.env` و `storage` دسترسی لازم دارد جایگزین کنید. دسترسی نوشتن را فقط به مسیرهای موردنیاز مثل `storage` و `bootstrap/cache` بدهید؛ از مجوزهای عمومی مانند `777` استفاده نکنید.
4. پس از تأیید `.env` امن و آماده‌سازی پایگاه‌داده و queue tables، کپی قالب Supervisor به `/etc/supervisor/conf.d/pahnavarkar-worker.conf` را انجام دهید و سپس `supervisorctl reread`, `supervisorctl update` و `supervisorctl status pahnavarkar-worker:*` را بررسی کنید.
5. هر بار که کد یا تنظیمات worker تغییر می‌کند، worker را با `php artisan queue:restart` به‌صورت graceful بازنشانی کنید و بررسی کنید Supervisor پردازه را دوباره بالا آورده باشد. `autorestart` جایگزین بررسی سلامت صف یا هشداردهی نیست.

قالب Worker عمداً یک پردازه دارد تا نقطهٔ شروع محافظه‌کارانه باشد؛ ظرفیت واقعی را با مشاهدهٔ CPU، حافظه و عقب‌ماندگی صف تعیین کنید. چند worker یا سرور برنامه را بدون اندازه‌گیری و تصمیم دربارهٔ صف، cache و lockهای مشترک اضافه نکنید.

## فعال‌کردن Scheduler

Cron باید هر دقیقه `php artisan schedule:run` را اجرا کند. مسیر Cron باید مطلق و پایدار باشد و کاربر آن بتواند در `storage/logs` بنویسد. در فایل نمونه، خروجی scheduler در همان مسیر ثبت می‌شود؛ برای فایل log نیز rotation و محدودیت دسترسی مناسب در نظر بگیرید.

پس از نصب نمونه در `/etc/cron.d/pahnavarkar-scheduler`، وضعیت سرویس Cron را بررسی و با `php artisan schedule:list` ثبت کارها را مشاهده کنید. این فرمان فهرست را نشان می‌دهد و task پاک‌سازی `failed_jobs` را اجرا نمی‌کند. رویدادهای برنامه از جمله pruning روزانهٔ jobهای ناموفق پس از ۷۲۰ ساعت فقط زمانی رخ می‌دهند که Cron فعال باشد.

برای چند میزبان Scheduler، قفل‌های `withoutOverlapping` باید از cache مشترک استفاده کنند. تا پیش از انتخاب و راه‌اندازی cache مشترک، فقط یک میزبان Scheduler را فعال نگه دارید.

## مرزهای تأیید

- این قالب‌ها مختص Supervisor و Cron روی Ubuntu هستند، نه پیکربندی اختصاصی API آروان‌کلاد؛ ایجاد firewall، DNS، TLS، دیتابیس مدیریت‌شده یا دسترسی شبکه در آن‌ها انجام نمی‌شود.
- `schedule:list` و وضعیت Supervisor فقط آماده‌بودن ثبت task/پردازه را نشان می‌دهند، نه تحویل پیامک یا Push. آزمون end-to-end باید در staging با credentialهای جداگانه و مقصدهای تأییدشده انجام شود.
- پیش از بارگذاری دادهٔ واقعی، نسخهٔ پشتیبان خارج از همان سرور و روش بازیابی را تأیید کنید. هیچ migration یا فرمان حذف `failed_jobs` را به‌عنوان آزمون دستی روی production اجرا نکنید.

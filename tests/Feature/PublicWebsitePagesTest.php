<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicWebsitePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_seo_metadata_navigation_and_cached_faq_content(): void
    {
        $question = config('site-faq.0.question');

        $response = $this->get('/');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>'.config('site.brand_name').' | '.config('site.tagline').'</title>', false)
            ->assertSee('<meta name="description" content="'.config('site.meta_description').'">', false)
            ->assertSee('<link rel="canonical" href="'.route('home').'">', false)
            ->assertSee('href="#main-content" class="skip-link"', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('<section id="home"', false)
            ->assertSee('>پهناور کار</p>', false)
            ->assertSee('در خدمت جامعه‌ی')
            ->assertSee('کار')
            ->assertDontSee('به پهناورکار بپیوندید؛ ابزارهای محاسبهٔ حقوق و دستمزد و منابع قوانین کار را ساده‌تر و در دسترس‌تر دنبال کنید.')
            ->assertSee('اندروید')
            ->assertSee('وب')
            ->assertSee('<span class="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600">دانلود</span>', false)
            ->assertSee('دوست دارید از کجا شروع کنید؟')
            ->assertDontSee('پهناورکار را در اندروید یا وب دنبال کنید')
            ->assertDontSee('روش دسترسی متناسب با دستگاه خود را انتخاب کنید.')
            ->assertSee('اپلیکیشن اندروید')
            ->assertSee('نسخهٔ آیفون')
            ->assertSee('نسخهٔ وب اپلیکیشن')
            ->assertSee('نسخهٔ ویندوز')
            ->assertSee('نسخهٔ مک')
            ->assertSee('نسخهٔ لینوکس')
            ->assertSee('class="fa-brands fa-android text-xl"', false)
            ->assertSee('class="fa-brands fa-apple text-xl"', false)
            ->assertSee('class="fa-solid fa-globe text-xl"', false)
            ->assertSee('class="fa-brands fa-windows text-xl"', false)
            ->assertSee('class="fa-solid fa-laptop text-xl"', false)
            ->assertSee('class="fa-brands fa-linux text-xl"', false)
            ->assertSee('ابزارهای حقوق و دستمزد و کتابخانهٔ قوانین را روی دستگاه اندرویدی دنبال کنید.')
            ->assertSee('لینک دریافت هنوز منتشر نشده است')
            ->assertSee('لینک دریافت اعلام نشده است')
            ->assertSee('این وب‌سایت فعلاً برای معرفی امکانات و نمایش اطلاعات است.')
            ->assertSee('هنوز منتشر نشده است')
            ->assertSee('id="android-download-status" role="status"', false)
            ->assertSee('id="ios-download-status" role="status"', false)
            ->assertSee('id="web-app-status" role="status"', false)
            ->assertSee('id="windows-download-status" role="status"', false)
            ->assertSee('id="mac-download-status" role="status"', false)
            ->assertSee('id="linux-download-status" role="status"', false)
            ->assertDontSee('<button type="button" disabled aria-describedby="android-download-status"', false)
            ->assertDontSee('<button type="button" disabled aria-describedby="web-app-status"', false)
            ->assertDontSee('امکانات پهناورکار')
            ->assertDontSee('ابزارهای محاسباتی و منابع قوانین و مقررات کار را در دو بخش دنبال کنید.')
            ->assertDontSee('این صفحه معرفی امکانات است؛ محاسبه‌گرها، جست‌وجو و مرور اسناد در اپلیکیشن انجام می‌شوند.')
            ->assertSee('<span class="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600">پلن‌ها و اشتراک</span>', false)
            ->assertDontSee('چهار سطح دسترسی، متناسب با نیاز شما')
            ->assertDontSee('پلن رایگان و پلن‌های برنزی، نقره‌ای و طلایی با بازه‌های اشتراک یک‌ماهه تا یک‌ساله.')
            ->assertDontSee('برای پلن‌هایی که قیمت آن‌ها تعیین نشده، خرید غیرفعال است.')
            ->assertSee('دنبال چه چیزی هستید؟')
            ->assertSee('امکانات و قیمت‌ها را مقایسه کنید و ببینید کدام پلن به کارتان می‌آید.')
            ->assertSee('امکاناتی که در پلن رایگان در دسترس‌اند.')
            ->assertSee('قیمت هر بازه و امکانات این پلن را مقایسه کنید.')
            ->assertSee('هنوز اعلام نشده')
            ->assertSee('امکانات فعال')
            ->assertSee('<span class="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600">پرسش‌های پرتکرار</span>', false)
            ->assertSee('سؤالی دارید؟ شاید پاسخ‌تان اینجا باشد.')
            ->assertDontSee('پاسخ‌های کوتاه دربارهٔ ابزارها، کتابخانهٔ قوانین و حدود استفاده از محاسبات.')
            ->assertDontSee('home-hero-preview')
            ->assertSee('property="og:title"', false)
            ->assertSee(route('tools.index'))
            ->assertSee(route('faq.index'))
            ->assertSee($question);

        $document = new \DOMDocument;
        $previousErrorState = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);
        $xpath = new \DOMXPath($document);

        $this->assertSame(2, $xpath->query('//*[@id="features"]//*[@data-feature-tile]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="features"]//h3[normalize-space()="ابزارهای محاسباتی"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="features"]//h3[normalize-space()="قوانین و مقررات"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="features"]/following-sibling::section[1][@id="plans"]')->length);
        $this->assertSame(
            1,
            $xpath->query('//*[@id="features"]//*[@data-feature-tile][.//h3[normalize-space()="ابزارهای محاسباتی"] and .//p[contains(., "از حقوق پایه و مزایا تا اضافه‌کاری")]]')->length,
        );
        $this->assertSame(
            1,
            $xpath->query('//*[@id="features"]//*[@data-feature-tile][.//h3[normalize-space()="قوانین و مقررات"] and .//p[contains(., "برای جست‌وجو و خواندن متن اسناد")]]')->length,
        );
        $this->assertSame(
            route('tools.index'),
            $xpath->query('//*[@id="features"]//*[@data-feature-tile][.//h3[normalize-space()="ابزارهای محاسباتی"]]//a')->item(0)?->getAttribute('href'),
        );
        $this->assertSame(
            'دیدن فهرست ابزارها',
            trim($xpath->query('//*[@id="features"]//*[@data-feature-tile][.//h3[normalize-space()="ابزارهای محاسباتی"]]//a')->item(0)?->textContent),
        );
        $this->assertSame(
            route('laws.index'),
            $xpath->query('//*[@id="features"]//*[@data-feature-tile][.//h3[normalize-space()="قوانین و مقررات"]]//a')->item(0)?->getAttribute('href'),
        );
        $this->assertSame(
            'دیدن دسته‌های قوانین',
            trim($xpath->query('//*[@id="features"]//*[@data-feature-tile][.//h3[normalize-space()="قوانین و مقررات"]]//a')->item(0)?->textContent),
        );
        $this->assertSame(2, $xpath->query('//*[@id="features"]//*[@data-feature-tile]//a[contains(@class, "min-h-12") and contains(@class, "w-full")]')->length);
        $this->assertSame(2, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]')->length);
        $this->assertSame(6, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]//input[@type="radio"][@data-theme-switcher]')->length);
        $this->assertSame(2, $xpath->query('//header//summary[contains(@class, "theme-switcher-trigger")]')->length);
        $this->assertSame(2, $xpath->query('//header//fieldset[contains(@class, "theme-switcher-options")]/legend[normalize-space()="انتخاب قالب رنگی"]')->length);
        $this->assertSame(2, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]//i[@data-theme-current-icon]')->length);
        $this->assertSame(2, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]//i[contains(@class, "fa-chevron-down")]')->length);
        $this->assertSame(2, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]//i[contains(@class, "theme-switcher-option-icon") and contains(@class, "fa-sun")]')->length);
        $this->assertSame(2, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]//i[contains(@class, "theme-switcher-option-icon") and contains(@class, "fa-moon")]')->length);
        $this->assertSame(2, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]//i[contains(@class, "theme-switcher-option-icon") and contains(@class, "fa-display")]')->length);
        $this->assertSame(6, $xpath->query('//header//details[contains(@class, "theme-switcher-control")]//input[@data-theme-icon and @data-theme-icon-color]')->length);
        $themeScript = file_get_contents(resource_path('js/theme.js'));
        $this->assertIsString($themeScript);
        $this->assertStringContainsString('icon.className = `fa-solid ${selectedRadio.dataset.themeIcon} ${selectedRadio.dataset.themeIconColor}`;', $themeScript);
        $this->assertSame(0, $xpath->query('//header//select[@data-theme-switcher]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="features"]/following-sibling::section[1][@id="plans"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="plans"]/following-sibling::section[1][@id="faq"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="tools" or @id="law-library"]')->length);
        $this->assertSame(0, $xpath->query('//h2[normalize-space()="برای کارهای روزمره، آماده‌تر باشید"]')->length);

        config(['site-faq.0.question' => 'پرسش پویای آزمون']);

        $this->get('/')
            ->assertOk()
            ->assertSee('پرسش پویای آزمون');
    }

    public function test_homepage_cache_keeps_asset_urls_on_the_request_origin(): void
    {
        $firstOrigin = $this->get('http://first-site.test/')
            ->assertOk()
            ->getContent();
        $secondOrigin = $this->get('http://second-site.test/')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/(?:src|href)="http:\/\/first-site\.test\/build\/assets\/[^"]+"/',
            $firstOrigin,
        );
        $this->assertMatchesRegularExpression(
            '/(?:src|href)="http:\/\/second-site\.test\/build\/assets\/[^"]+"/',
            $secondOrigin,
        );
        $this->assertStringNotContainsString('http://first-site.test/build/assets/', $secondOrigin);
    }

    public function test_public_site_uses_the_current_app_brand_assets_and_color_palette(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="theme-color" content="#FBFCFD">', false)
            ->assertSee(asset('favicon.png').'?v=4')
            ->assertSee(asset('images/pahnavarkar-logo-teal.png').'?v=1')
            ->assertSee(asset('images/pahnavarkar-logo-white.png').'?v=1')
            ->assertSee('<meta property="og:image" content="'.asset('favicon.png').'?v=4">', false);

        foreach ([
            public_path('favicon.png'),
            public_path('images/pahnavarkar-logo-teal.png'),
            public_path('images/pahnavarkar-logo-white.png'),
        ] as $assetPath) {
            $this->assertFileExists($assetPath);
        }

        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($styles);
        $this->assertStringContainsString('--brand-600: #006878;', $styles);
        $this->assertStringContainsString('--app-background: #fbfcfd;', $styles);
        $this->assertStringContainsString('--app-background: #191c1d;', $styles);
        $this->assertStringContainsString('background-color: #001f25;', $styles);
        $this->assertStringContainsString('color: #006878;', $styles);
        $this->assertStringContainsString('color: #53d7f1;', $styles);
        $this->assertStringContainsString('background-color: var(--app-surface);', $styles);
        $this->assertStringContainsString('[data-theme=\'dark\'] .public-content-page .public-page-callout', $styles);
        $this->assertStringNotContainsString('[class~=\'bg-[#eff8fa]\']', $styles);
        $this->assertStringNotContainsString('color: #006b78;', $styles);
        $this->assertStringNotContainsString('rgb(4 30 40 / 88%)', $styles);
    }

    public function test_public_brand_theme_colors_stay_in_sync_for_light_and_dark_modes(): void
    {
        $themeBootstrap = file_get_contents(resource_path('views/partials/theme-bootstrap.blade.php'));
        $themeScript = file_get_contents(resource_path('js/theme.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($themeBootstrap);
        $this->assertIsString($themeScript);
        $this->assertIsString($styles);
        $this->assertStringContainsString("isDark ? '#191C1D' : '#FBFCFD'", $themeBootstrap);
        $this->assertStringContainsString("theme === 'dark' ? '#191C1D' : '#FBFCFD'", $themeScript);
        $this->assertStringContainsString('--brand-600: #006878;', $styles);
        $this->assertStringContainsString('--brand-500: #53d7f1;', $styles);
        $this->assertStringContainsString('background-color: #53d7f1;', $styles);
        $this->assertStringContainsString('color: #000;', $styles);
    }

    public function test_tools_page_searches_and_filters_the_runtime_feature_catalog(): void
    {
        $this->get('/tools?'.http_build_query(['q' => 'حقوق پایه']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>ابزارهای محاسباتی | '.config('site.brand_name').'</title>', false)
            ->assertSee('<meta name="description" content="با ابزارهای محاسبهٔ حقوق و دستمزد و زمان کار در اپلیکیشن پهناورکار آشنا شوید و ابزار موردنظرتان را پیدا کنید.">', false)
            ->assertSee('<link rel="canonical" href="'.route('tools.index').'">', false)
            ->assertSee('href="#main-content" class="skip-link"', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('دنبال ابزار خاصی هستید؟ جست‌وجو کنید یا دستهٔ مرتبط را انتخاب کنید.')
            ->assertSee('ابزارها در اپلیکیشن اجرا می‌شوند؛ اینجا می‌توانید با کاربرد هرکدام آشنا شوید.')
            ->assertSee('role="tablist"', false)
            ->assertSee('role="tab"', false)
            ->assertSee('اطلاعات سال کارکرد')
            ->assertSee('اقلام مزدی مستمر')
            ->assertSee('اقلام مزدی غیر مستمر')
            ->assertSee('اقلام غیر مزدی')
            ->assertSee('حقوق پایه')
            ->assertSee('توضیحات هر ابزار برای آشنایی است و جای بررسی شرایط پرونده را نمی‌گیرد.')
            ->assertDontSee('جریمه به‌کارگیری اتباع غیرمجاز');

        $response = $this->get('/tools?'.http_build_query(['group' => 'اقلام مزدی مستمر']))
            ->assertOk()
            ->assertSee('aria-selected="true"', false)
            ->assertSee('حقوق پایه')
            ->assertSee('id="tool-panel-1"', false);

        $document = new \DOMDocument;
        $previousErrorState = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);
        $xpath = new \DOMXPath($document);
        $activePanel = $xpath->query('//*[@role="tabpanel" and not(@hidden)]')->item(0);

        $this->assertNotNull($activePanel);
        $this->assertStringContainsString('حقوق پایه', $activePanel->textContent);
        $this->assertStringNotContainsString('جریمه به‌کارگیری اتباع غیرمجاز', $activePanel->textContent);
    }

    public function test_public_navigation_identifies_the_current_page_or_section(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('home').'#home"', false)
            ->assertSee('aria-current="location"', false)
            ->assertSee('data-public-nav-section="home"', false)
            ->assertSee('data-public-nav-section="features"', false)
            ->assertSee('data-public-nav-target="#features"', false)
            ->assertSee('>امکانات', false)
            ->assertSee(route('tools.index'))
            ->assertSee(route('laws.index'))
            ->assertSee(route('home').'#plans')
            ->assertSee('href="'.route('faq.index').'"', false)
            ->assertSee('پلن‌ها و اشتراک')
            ->assertSee('پرسش‌های پرتکرار')
            ->assertSee('href="'.route('blog.index').'"', false)
            ->assertSee('href="'.route('about').'"', false)
            ->assertSee('href="'.route('terms').'"', false);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('href="'.route('blog.index').'"', false)
            ->assertSee('aria-current="page"', false);

        $this->get('/about')
            ->assertOk()
            ->assertSee('href="'.route('about').'"', false)
            ->assertSee('aria-current="page"', false);

        $this->get('/terms-of-use')
            ->assertOk()
            ->assertSee('href="'.route('terms').'"', false)
            ->assertSee('شرایط استفاده از برنامه')
            ->assertSee('aria-current="page"', false);

        $this->get('/faq')
            ->assertOk()
            ->assertSee('href="'.route('faq.index').'"', false)
            ->assertSee('aria-current="page"', false);

        $this->get('/tools')
            ->assertOk()
            ->assertSee(route('tools.index'))
            ->assertDontSee('href="'.route('tools.index').'" aria-current', false);
    }

    public function test_public_pages_use_font_awesome_instead_of_inline_svg_icons(): void
    {
        foreach (['/', '/tools', '/tools/base-salary', '/faq', '/blog', '/laws', '/about', '/terms-of-use'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertDontSee('<svg')
                ->assertSee('<i class="fa-', false);
        }
    }

    public function test_mobile_menu_toggle_uses_a_single_switching_icon(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-mobile-menu-toggle', false)
            ->assertSee('class="public-menu-toggle grid place-items-center md:hidden"', false)
            ->assertSee('<i data-menu-icon class="fa-solid fa-bars public-menu-toggle-icon" aria-hidden="true"></i>', false)
            ->assertDontSee('data-menu-open-icon', false)
            ->assertDontSee('data-menu-close-icon', false);

        $themeScript = file_get_contents(resource_path('js/theme.js'));
        $this->assertIsString($themeScript);
        $this->assertStringContainsString("menuIcon?.classList.toggle('fa-bars', !isOpen);", $themeScript);
        $this->assertStringContainsString("menuIcon?.classList.toggle('fa-xmark', isOpen);", $themeScript);
    }

    public function test_homepage_sections_follow_a_consistent_vertical_spacing_rhythm(): void
    {
        $response = $this->get('/')->assertOk();
        $document = new \DOMDocument;
        $previousErrorState = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);
        $xpath = new \DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//*[@id="home"]//div[@aria-label="دریافت و استفاده از پهناورکار" and contains(concat(" ", normalize-space(@class), " "), " mt-8 ")]')->length,
        );
        $this->assertSame(
            6,
            $xpath->query('//*[@id="home"]//div[@aria-label="دریافت و استفاده از پهناورکار"]/article')->length,
        );
        $this->assertSame(
            1,
            $xpath->query('//*[@id="features"]//div[contains(concat(" ", normalize-space(@class), " "), " max-w-5xl ") and contains(concat(" ", normalize-space(@class), " "), " mt-8 ")]')->length,
        );
        $this->assertSame(
            1,
            $xpath->query('//*[@id="plans"]//div[contains(concat(" ", normalize-space(@class), " "), " mt-8 ") and contains(concat(" ", normalize-space(@class), " "), " grid ")]')->length,
        );
        $this->assertSame(
            1,
            $xpath->query('//*[@id="faq"]//div[contains(concat(" ", normalize-space(@class), " "), " mt-8 ") and contains(concat(" ", normalize-space(@class), " "), " divide-y ")]')->length,
        );
        $this->assertSame(
            3,
            $xpath->query('//*[@id="features" or @id="plans" or @id="faq"][contains(concat(" ", normalize-space(@class), " "), " py-20 ") and contains(concat(" ", normalize-space(@class), " "), " sm:py-24 ")]')->length,
        );
        $this->assertSame(
            1,
            $xpath->query('//*[@id="home"][contains(concat(" ", normalize-space(@class), " "), " scroll-mt-24 ")]')->length,
        );
    }

    public function test_blog_and_about_pages_render_with_canonical_urls_and_navigation_state(): void
    {
        $this->get('/blog')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>بلاگ | '.config('site.brand_name').'</title>', false)
            ->assertSee('<meta name="description" content="مقالات و راهنماها، اطلاعیه‌ها و اخبار پهناورکار دربارهٔ حقوق و دستمزد و قوانین کار.">', false)
            ->assertSee('<link rel="canonical" href="'.route('blog.index').'">', false)
            ->assertSee('>بلاگ</h1>', false)
            ->assertSee('راهنماهای کاربردی را بخوانید و از خبرها و اطلاعیه‌های پهناورکار باخبر شوید.')
            ->assertSee('مقالات و آموزش')
            ->assertSee('اخبار و اطلاعیه‌ها')
            ->assertSee('<nav aria-label="دسته‌بندی مطالب بلاگ"', false)
            ->assertSee('href="#blog-content"', false)
            ->assertSee('href="#articles"', false)
            ->assertSee('href="#announcements"', false)
            ->assertSee('id="articles"', false)
            ->assertSee('id="announcements"', false)
            ->assertSee('هنوز مقاله‌ای منتشر نشده')
            ->assertSee('فعلاً اطلاعیه‌ای نداریم')
            ->assertSee('href="'.route('tools.index').'"', false)
            ->assertSee('دیدن ابزارها')
            ->assertSee('aria-current="page"', false);

        $this->get('/about')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>درباره ما | '.config('site.brand_name').'</title>', false)
            ->assertSee('<meta name="description" content="پهناورکار برای دسترسی ساده‌تر به ابزارهای حقوق و دستمزد و منابع قوانین کار.">', false)
            ->assertSee('<link rel="canonical" href="'.route('about').'">', false)
            ->assertSee('>درباره ما</h1>', false)
            ->assertSee('می‌خواهیم ابزارهای حقوق و دستمزد و منابع قوانین کار را ساده‌تر در دسترس شما بگذاریم.')
            ->assertSee('چرا پهناورکار؟')
            ->assertSee('محاسبه‌گرها و جست‌وجوی کتابخانهٔ قوانین در اپلیکیشن در دسترس‌اند.')
            ->assertSee('دیدن امکانات')
            ->assertSee('دیدن فهرست ابزارها')
            ->assertSee('aria-current="page"', false);
    }

    public function test_app_terms_page_renders_its_content_and_canonical_url(): void
    {
        $this->get('/terms-of-use')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>شرایط استفاده از برنامه | '.config('site.brand_name').'</title>', false)
            ->assertSee('<meta name="description" content="شرایط استفاده از برنامهٔ پهناورکار، شامل حساب کاربری، اشتراک‌ها، محاسبات و استفادهٔ مجاز از خدمات.">', false)
            ->assertSee('<link rel="canonical" href="'.route('terms').'">', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('این شرایط، چارچوب استفاده از اپلیکیشن پهناورکار و خدمات آن را مشخص می‌کند.')
            ->assertSee('۱. پذیرش و دامنهٔ شرایط')
            ->assertSee('۲. حساب کاربری')
            ->assertSee('۳. پلن‌ها و اشتراک‌ها')
            ->assertSee('۴. محاسبات و محتوای حقوقی')
            ->assertSee('۵. استفادهٔ مجاز از برنامه')
            ->assertSee('۶. تغییر و دسترس‌پذیری خدمات')
            ->assertSee('۷. به‌روزرسانی شرایط')
            ->assertSee('ممکن است با توجه به تغییر خدمات یا الزامات قانونی، این شرایط را به‌روزرسانی کنیم.')
            ->assertSee('این نتایج و محتوای برنامه صرفاً جنبهٔ اطلاع‌رسانی عمومی دارند')
            ->assertSee('مگر در مواردی که قانون اجازه می‌دهد یا صاحب حق اجازه داده است')
            ->assertSee(route('faq.index'));
    }

    public function test_legal_categories_page_renders_the_catalog_as_a_list(): void
    {
        $response = $this->get('/laws')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>قوانین و مقررات | '.config('site.brand_name').'</title>', false)
            ->assertSee('<meta name="description" content="دسته‌های قوانین و اسناد کار را پیدا کنید؛ جست‌وجو و مشاهدهٔ متن اسناد در اپلیکیشن پهناورکار انجام می‌شود.">', false)
            ->assertSee('<link rel="canonical" href="'.route('laws.index').'">', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('دنبال قانون یا آیین‌نامهٔ خاصی هستید؟ دسته‌های اسناد را از این فهرست پیدا کنید.')
            ->assertSee('برای جست‌وجو و خواندن متن اسناد، وارد اپلیکیشن پهناورکار شوید.')
            ->assertSee('چه اسنادی پیدا می‌کنید؟')
            ->assertSee('دسته برای مرور اسناد حقوقی')
            ->assertSee('اگر کنار سندی در اپلیکیشن هشدار آزمایشی‌بودن دیدید، متن آن سند را رسمی تلقی نکنید.');

        $document = new \DOMDocument;
        $previousErrorState = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);
        $xpath = new \DOMXPath($document);

        $this->assertSame(count(config('site-legal-categories')), $xpath->query('//*[@id="main-content"]//ul/li')->length);
        foreach (config('site-legal-categories') as $category) {
            $response->assertSee($category);
        }
    }

    public function test_public_header_and_footer_keep_simple_navigation_and_the_disclaimer(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="ناوبری اصلی"', false)
            ->assertSee('کتابخانهٔ قوانین')
            ->assertSee('href="'.route('tools.index').'"', false)
            ->assertSee('href="'.route('laws.index').'"', false)
            ->assertSee('href="'.route('faq.index').'"', false)
            ->assertSee('href="'.route('blog.index').'"', false)
            ->assertSee('href="'.route('about').'"', false)
            ->assertSee('aria-label="پیوندهای مفید"', false)
            ->assertSee('درباره ما')
            ->assertSee('جای مشاورهٔ تخصصی حقوقی را نمی‌گیرند.')
            ->assertSee('<footer class="site-footer border-t border-slate-200 bg-white text-slate-700">', false);
    }

    public function test_tools_page_handles_empty_results_and_rejects_unknown_groups(): void
    {
        $this->get('/tools?'.http_build_query(['q' => 'عبارت ناموجود']))
            ->assertOk()
            ->assertSee('موردی پیدا نشد');

        $this->get('/tools?'.http_build_query(['group' => 'دستهٔ نامعتبر']))
            ->assertStatus(422)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Vary')
            ->assertSee('فیلتر ارسالی معتبر نیست.')
            ->assertHeaderMissing('Set-Cookie')
            ->assertSee('موردی پیدا نشد');
    }

    public function test_cacheable_public_pages_are_stateless_and_do_not_issue_cookies(): void
    {
        foreach ([
            '/',
            '/tools',
            '/tools/base-salary',
            '/laws',
            '/faq',
            '/robots.txt',
            '/sitemap.xml',
        ] as $uri) {
            $this->withCookie(config('session.cookie'), 'existing-session')
                ->get($uri)
                ->assertOk()
                ->assertHeaderMissing('Vary')
                ->assertHeaderMissing('Set-Cookie');
        }
    }

    public function test_tool_detail_uses_the_catalog_metadata_and_current_plan_entitlements(): void
    {
        AdminSetting::query()->create([
            'setting_key' => 'plans',
            'value' => [
                'tiers' => [
                    'bronze' => [
                        'features' => ['base-salary' => true],
                    ],
                ],
            ],
        ]);
        Cache::forget('admin-runtime-settings:v2:plans');

        $this->get(route('tools.show', 'base-salary'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>حقوق پایه | '.config('site.brand_name').'</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('tools.show', 'base-salary').'">', false)
            ->assertSee('href="#main-content" class="skip-link"', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('اقلام مزدی مستمر')
            ->assertSee('محاسبه حقوق پایه و نمایش جزئیات آن')
            ->assertSee('برنزی')
            ->assertSee('در این وب‌سایت فرم یا محاسبه‌گر فعالی ارائه نشده است.')
            ->assertSee(route('tools.index'));
    }

    public function test_tool_detail_returns_not_found_for_unknown_or_non_tool_feature_keys(): void
    {
        $this->get('/tools/not-a-feature')->assertNotFound();
        $this->get('/tools/legal_library')->assertNotFound();
    }

    public function test_faq_page_renders_every_question_as_native_accessible_disclosures(): void
    {
        $response = $this->get('/faq');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300')
            ->assertSee('<title>پرسش‌های پرتکرار | '.config('site.brand_name').'</title>', false)
            ->assertSee('<meta name="description" content="پاسخ پرسش‌های پرتکرار دربارهٔ ابزارها، کتابخانهٔ قوانین و استفاده از پهناورکار.">', false)
            ->assertSee('<link rel="canonical" href="'.route('faq.index').'">', false)
            ->assertSee('href="#main-content" class="skip-link"', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('aria-label="مسیر صفحه"', false)
            ->assertSee('سؤالی دربارهٔ ابزارها، قوانین یا محاسبه‌ها دارید؟ اینجا جوابش را پیدا می‌کنید.')
            ->assertSee('مرور فهرست ابزارها');

        foreach (config('site-faq') as $faq) {
            $response->assertSee($faq['question'])
                ->assertSee($faq['answer'])
                ->assertSee('id="'.$faq['id'].'"', false)
                ->assertSee('href="#'.$faq['id'].'"', false)
                ->assertSee('پیوند مستقیم به پرسش: '.$faq['question']);
        }

        $response->assertSee('فیلتر دسته‌بندی پرسش‌ها')
            ->assertSee('id="faq-search"', false)
            ->assertSee('data-faq-category="tools"', false)
            ->assertSee('data-faq-category="legal-content"', false)
            ->assertSee('data-faq-empty', false)
            ->assertSee('href="'.route('tools.index').'"', false)
            ->assertSee('href="'.route('laws.index').'"', false)
            ->assertSee('href="'.route('tools.show', 'group-calculation').'"', false);
        $this->assertSame(count(config('site-faq')), substr_count($response->getContent(), 'aria-label="پیوند مستقیم به پرسش:'));
        $response->assertSee('window.addEventListener(\'hashchange\', openLinkedQuestion)', false)
            ->assertSee('search.addEventListener(\'input\', filterQuestions)', false);
    }

    public function test_homepage_faq_preview_links_to_the_matching_openable_answer(): void
    {
        $response = $this->get('/');

        foreach (array_slice(config('site-faq'), 0, 4) as $faq) {
            $response->assertSee(route('faq.index').'#'.$faq['id'])
                ->assertSee('ادامهٔ پاسخ و پیوند مستقیم');
        }
    }

    public function test_reduced_motion_preference_is_respected_by_shared_styles(): void
    {
        $this->get('/')
            ->assertOk();

        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('scroll-behavior: auto !important;', $styles);
        $this->assertStringContainsString('transition-duration: 0.01ms !important;', $styles);
    }

    public function test_public_pages_render_one_shared_html_document_shell(): void
    {
        foreach ([
            '/',
            '/tools',
            '/tools/base-salary',
            '/faq',
        ] as $uri) {
            $content = $this->get($uri)
                ->assertOk()
                ->getContent();

            $this->assertSame(1, substr_count($content, '<!DOCTYPE html>'), $uri);
            $this->assertSame(1, substr_count($content, '<html '), $uri);
            $this->assertSame(1, substr_count($content, '<head>'), $uri);
            $this->assertSame(1, substr_count($content, '<main '), $uri);
            $this->assertSame(1, substr_count($content, '<header '), $uri);
            $this->assertSame(1, substr_count($content, '<footer '), $uri);
            $this->assertStringContainsString('data-mobile-menu-toggle', $content);
            $this->assertStringContainsString('aria-controls="mobile-navigation"', $content);
            $this->assertStringContainsString('aria-expanded="false"', $content);
            $this->assertStringContainsString('<div id="mobile-navigation"', $content);
        }
    }

    public function test_public_page_internal_links_resolve_and_fragments_exist(): void
    {
        $publicPages = ['/', '/tools', '/tools/base-salary', '/faq'];
        $applicationUrl = parse_url(route('home'));
        $applicationOrigin = strtolower($applicationUrl['scheme'].'://'.$applicationUrl['host'])
            .(isset($applicationUrl['port']) ? ':'.$applicationUrl['port'] : '');
        $checkedTargets = [];

        foreach ($publicPages as $uri) {
            $content = $this->get($uri)
                ->assertOk()
                ->getContent();
            $document = new \DOMDocument;
            $previousErrorState = libxml_use_internal_errors(true);
            $document->loadHTML($content);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);

            foreach ($document->getElementsByTagName('a') as $link) {
                $href = trim($link->getAttribute('href'));

                if ($href === '') {
                    continue;
                }

                $target = parse_url($href);

                if (isset($target['host'])) {
                    $targetOrigin = strtolower(($target['scheme'] ?? 'http').'://'.$target['host'])
                        .(isset($target['port']) ? ':'.$target['port'] : '');

                    if ($targetOrigin !== $applicationOrigin) {
                        continue;
                    }
                }

                $targetPath = $target['path'] ?? (isset($target['host']) ? '/' : $uri);
                $targetPath = $targetPath === '' ? $uri : $targetPath;
                $targetUri = $targetPath.(isset($target['query']) ? '?'.$target['query'] : '');

                if (! array_key_exists($targetUri, $checkedTargets)) {
                    $response = $this->get($targetUri);
                    $this->assertLessThan(
                        400,
                        $response->getStatusCode(),
                        "Internal link from {$uri} does not resolve: {$href} (link text: {$link->textContent})",
                    );
                    $checkedTargets[$targetUri] = $response->getContent();
                }

                if (isset($target['fragment'])) {
                    $fragment = rawurldecode($target['fragment']);

                    $this->assertMatchesRegularExpression(
                        '/\bid="'.preg_quote($fragment, '/').'"/',
                        $checkedTargets[$targetUri],
                        "Internal link from {$uri} points to a missing fragment: {$href}",
                    );
                }
            }
        }
    }

    public function test_public_pages_have_accessible_headings_and_named_controls(): void
    {
        foreach (['/', '/tools', '/tools/base-salary', '/faq', '/blog', '/about'] as $uri) {
            $content = $this->get($uri)
                ->assertOk()
                ->getContent();
            $document = new \DOMDocument;
            $previousErrorState = libxml_use_internal_errors(true);
            $document->loadHTML($content);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);
            $xpath = new \DOMXPath($document);

            $html = $document->getElementsByTagName('html')->item(0);
            $this->assertSame('fa', $html?->getAttribute('lang'), $uri);
            $this->assertSame('rtl', $html?->getAttribute('dir'), $uri);
            $this->assertSame(1, $xpath->query('//h1')->length, $uri);
            $this->assertSame(0, $xpath->query('//img[not(@alt)]')->length, $uri);

            $previousLevel = 0;
            foreach ($xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6') as $heading) {
                $level = (int) substr($heading->nodeName, 1);
                $this->assertLessThanOrEqual(
                    $previousLevel + 1,
                    $level,
                    "Heading hierarchy skips a level on {$uri}: {$heading->textContent}",
                );
                $previousLevel = $level;
            }

            foreach ($xpath->query('//button | //input[not(@type="hidden")] | //select | //textarea') as $control) {
                $label = $control->getAttribute('aria-label')
                    ?: $control->getAttribute('title');
                if ($label === '' && $control->tagName === 'button') {
                    $label = trim($control->textContent);
                }
                if ($label === '' && $control->tagName === 'input') {
                    $label = $control->getAttribute('value');
                }
                $id = $control->getAttribute('id');

                if ($label === '' && $id !== '') {
                    foreach ($document->getElementsByTagName('label') as $candidate) {
                        if ($candidate->getAttribute('for') === $id) {
                            $label = trim($candidate->textContent);
                            break;
                        }
                    }
                }

                if ($label === '') {
                    $parent = $control->parentNode;
                    while ($parent instanceof \DOMElement && $parent->tagName !== 'label') {
                        $parent = $parent->parentNode;
                    }
                    if ($parent instanceof \DOMElement) {
                        $label = trim($parent->textContent);
                    }
                }

                $this->assertNotSame(
                    '',
                    trim($label),
                    "Form control has no accessible name on {$uri}: {$document->saveHTML($control)}",
                );
            }

            foreach ($document->getElementsByTagName('a') as $link) {
                $accessibleName = trim($link->textContent)
                    ?: $link->getAttribute('aria-label')
                    ?: $link->getAttribute('title');

                $this->assertNotSame(
                    '',
                    trim($accessibleName),
                    "Link has no accessible name on {$uri}: {$document->saveHTML($link)}",
                );
            }
        }
    }

    public function test_public_footer_uses_readable_muted_text_on_the_surface_background(): void
    {
        $this->get('/tools')
            ->assertOk()
            ->assertSee(
                'text-slate-500 sm:flex-row sm:items-center sm:justify-between',
                false,
            )
            ->assertSee('<footer class="site-footer border-t border-slate-200 bg-white text-slate-700">', false);
    }

    public function test_robots_file_points_to_the_dynamic_sitemap_and_excludes_nonpublic_areas(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=3600, public')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /api/')
            ->assertSee('Disallow: /sanctum/')
            ->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_sitemap_contains_only_stable_public_pages(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=3600, public')
            ->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false)
            ->assertSee('<loc>'.route('home').'</loc>', false)
            ->assertSee('<loc>'.route('about').'</loc>', false)
            ->assertSee('<loc>'.route('terms').'</loc>', false)
            ->assertSee('<loc>'.route('blog.index').'</loc>', false)
            ->assertSee('<loc>'.route('tools.index').'</loc>', false)
            ->assertSee('<loc>'.route('laws.index').'</loc>', false)
            ->assertSee('<loc>'.route('tools.show', 'base-salary').'</loc>', false)
            ->assertSee('<loc>'.route('faq.index').'</loc>', false)
            ->assertDontSee('/admin')
            ->assertDontSee('/api/')
            ->assertDontSee(route('tools.show', 'legal_library'))
            ->assertDontSee('/tools?');
    }
}

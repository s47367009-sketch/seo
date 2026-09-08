# هوش‌سئو — HooshSEO Studio

استودیوی سئوی هوشمند وردپرس: یک افزونه با رابط تک‌صفحه‌ای (SPA) فارسی و راست‌به‌چپ که به‌جای انباشتن صفحه‌های پراکنده در پیشخوان، همهٔ ابزارهای سئو را در یک برنامه جمع می‌کند.

```
hoosh-seo/
├── hoosh-seo.php          سربرگ افزونه، ثابت‌ها، قفل نسخهٔ PHP
├── uninstall.php          حذف تمیز (فقط با اجازهٔ کاربر در تنظیمات)
├── readme.txt             فرمت وردپرس.ارگ
├── docs/INSTALL.md        راهنمای نصب و عیب‌یابی (فارسی)
├── includes/
│   ├── autoloader.php     HooshSEO\Sub\Class_Name → includes/Sub/ClassName.php
│   ├── Plugin.php         کانتینر سرویس‌ها + بوت فرانت/ادمین
│   ├── Database.php       ۱۴ جدول با پیشوند wp_hoosh_
│   ├── Settings.php       یک آپشن، درخت گروه‌بندی‌شده، پیش‌فرض‌ها
│   ├── Rest.php           ~۱۲۰ مسیر تحت hoosh/v1
│   ├── App.php            مسیرهای عمومی (سایت‌مپ/رباتز/llms) + سرو استودیو
│   ├── Admin.php Editor.php Meta.php Reports.php Helpers.php Activator.php Deactivator.php Cron.php
│   ├── AI/                Providers، Gateway (صف، کش، سقف هزینه)، Tasks
│   ├── DB/Query.php       کوئری‌ساز سبک
│   └── Modules/           ۲۱ ماژول: Content، Sitemap، Redirects، Links، Schema، RankTracker، …
├── assets/
│   ├── app.js app.css      استودیو (ونیلای JS، بدون بیلد)
│   ├── admin.css           پیشخوان: متاباکس، ستون‌ها، ویجت داشبورد
│   ├── editor.css editor.js  جعبهٔ متای ویرایشگر کلاسیک
│   ├── gutenberg.js        پنل ویرایشگر بلوک (از همان کامپوننت استفاده می‌کند)
│   └── front.css           استایل بلوک‌های فرانت (جعبه پاسخ، breadcrumb، FAQ، HowTo)
└── tools/build-zip.sh     ساخت dist/hoosh-seo.zip با ساختار درست
```

## ساخت بسته نصبی

```bash
tools/build-zip.sh
# → dist/hoosh-seo.zip   (یک پوشهٔ hoosh-seo/ در ریشه، hoosh-seo.php داخل آن)
```

## حداقل‌ها

WordPress 5.8+ · PHP 7.3+ (تا 8.x) · MySQL 5.7 / MariaDB 10.2+ · `mbstring` · ترجیحاً `curl`.

جزئیات نصب، مجوزها و عیب‌یابی: [`docs/INSTALL.md`](docs/INSTALL.md).

## توسعه

* هیچ ابزار بیلدی لازم نیست؛ `assets/app.js` خودش کامل است و با `?ver=` لود می‌شود.
* هر تغییر دیتابیسی از راه `Audit::log()` ثبت می‌شود و در استودیو قابل بازگردانی است.
* ماژول تازه: کلاس `HooshSEO\Modules\X` با `instance()` و `bootstrap()` را به `Plugin::$module_classes` اضافه کنید؛ استودیو خودش مسیر/تب را از `App::nav()` می‌گیرد.
* تنظیمات جدید: کلید `group.key` را در `Settings::defaults()` بگذارید؛ برچسب فارسی را در `LABELS` داخل `assets/app.js`.
* بررسی‌های محتوایی: `Modules/Content::checks()` — هر بررسی `id/label/group/status/why/how/fix` دارد و `fix` می‌تواند `auto` یا `ai` باشد.

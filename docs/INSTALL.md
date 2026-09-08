# نصب و راه‌اندازی هوش‌سئو

این سند کوتاه، مسیر نصب از فایل ZIP و رایج‌ترین خطاهای نصب را توضیح می‌دهد. اگر جایی گیر کردید، بند «عیب‌یابی» را بخوانید.

## ۱) ساخت فایل ZIP

ساختار درست بسته نصبی این است — یک پوشه بیرونی به نام `hoosh-seo` که فایل اصلی افزونه داخل آن است:

```
hoosh-seo.zip
└── hoosh-seo/
    ├── hoosh-seo.php        ← سربرگ افزونه (Plugin Name…)
    ├── uninstall.php
    ├── readme.txt
    ├── includes/
    ├── assets/
    ├── docs/
    └── languages/
```

با اسکریپت موجود در مخزن:

```bash
tools/build-zip.sh            # خروجی: dist/hoosh-seo.zip
```

یا دستی:

```bash
git archive --format=zip --prefix=hoosh-seo/ -o dist/hoosh-seo.zip HEAD
```

> اگر مستقیم از «Download ZIP» گیت‌هاب استفاده می‌کنید، نام پوشه بیرونی `seo-main` یا `seo-<branch>` می‌شود و وردپرس افزونه را پیدا نمی‌کند. در این حالت فایل اصلی (`hoosh-seo.php`) باید در ریشهٔ همان پوشه باشد؛ نام پوشه مهم نیست ولی ساختار دو-لایه (`seo-main/hoosh-seo/…`) باعث خطای «افزونهٔ معتبر یافت نشد» می‌شود.

## ۲) نصب از پیشخوان

۱. پیشخوان → افزونه‌ها → «افزوده جدید» → «بارگذاری افزونه».
۲. فایل ZIP را انتخاب و «هم‌اکنون بارگذاری کن» را بزنید.
۳. «فعال‌سازی» را بزنید. جدول‌ها در همین مرحله به‌صورت خودکار ساخته می‌شوند.
۴. از منوی «هوش‌سئو» → «باز کردن استودیو». استودیو در تب جدید باز می‌شود.

## ۳) نصب با FTP

پوشهٔ `hoosh-seo` را در `wp-content/plugins/` بفرستید و از فهرست افزونه‌ها فعالش کنید. اگر از Git استفاده می‌کنید، پوشهٔ `.git` را داخل پیشخوان **ارسال نکنید**؛ یک مخزن ۲۰۰ مگابایتی با هزاران ریزفایل، هم زمان آپلود را بی‌نهایت طولانی می‌کند و هم در میزبان‌های اشتراکی خطای «ظرفیت inode» یا «ناکامی آپلود» می‌دهد.

```bash
rsync -a --exclude '.git' --exclude 'node_modules' ./your-site/wp-content/plugins/hoosh-seo/
```

## ۴) حداقل‌های سرور

| مورد | مقدار | نکته |
| --- | --- | --- |
| WordPress | ۵.۸ به بعد | برای REST fields و بلوک‌ساز |
| PHP | ۷.۳ به بعد | با ۸.x هم آزمون شده |
| MySQL | ۵.۷ / MariaDB ۱۰.۲ | برای `utf8mb4` و ایندکس‌ها |
| `upload_max_filesize` | ≥ ۸ مگابایت | بسته افزونه حدود ۱ تا ۲ مگابایت است |
| `post_max_size` | ≥ ۱۰ مگابایت | باید از `upload_max_filesize` بزرگ‌تر باشد |
| `max_execution_time` | ≥ ۶۰ ثانیه | برای ساخت نقشه سایت و مهاجرت |
| `memory_limit` | ≥ ۱۲۸M | در سایت‌های پرکانتنت ۲۵۶M |
| تابع `curl` یا `allow_url_fopen` | باز | برای سرچ کنسول، PSI و هوش مصنوعی |
| `mbstring` | فعال | برای متن فارسی ضروری است |

تغییر در `php.ini` یا از راه `.user.ini` و پنل میزبان (cPanel/DirectAdmin):

```ini
upload_max_filesize = 16M
post_max_size = 20M
max_execution_time = 120
memory_limit = 256M
```

## ۵) اتصال هوش مصنوعی

۱. استودیو → «هوش مصنوعی».
۲. سرویس را انتخاب کنید (OpenAI، Claude، Gemini، DeepSeek، Groq، Mistral، Perplexity، Cohere، Ollama محلی، یا هر سرویس سازگار با OpenAI).
۳. کلید API را وارد و «آزمون» را بزنید.
۴. سقف هزینه ماهانه را تعیین کنید؛ بعد از آن افزونه خودکار تولید را متوقف می‌کند.

اگر سرور شما خروج HTTPS محدود دارد، در همان صفحه «پراکسی» را پر کنید.

## ۶) عیب‌یابی

### «افزونهٔ معتبر یافت نشد» / «Plugin could not be activated because it triggered a fatal error»
- فایل `hoosh-seo.php` باید **یک پوشه زیر ریشهٔ ZIP** باشد، نه دو پوشه.
- اگر فایل‌ها را از Git کپی کرده‌اید، مطمئن شوید `includes/autoloader.php` هم منتقل شده است.
- خطای واقعی را در `wp-content/debug.log` ببینید (`WP_DEBUG` و `WP_DEBUG_LOG` را موقتاً `true` کنید).

### آپلود ZIP شکست خورد، صفحه سفید شد یا نوار پیشرفت گیر کرد
- `upload_max_filesize` و `post_max_size` را افزایش دهید (بند ۴).
- اگر میزبان «mod_security» دارد، موقتاً غیرفعالش کنید.
- راه‌حل همیشگی: ارسال با FTP یا `wp plugin install /path/to.zip --activate`.

### خطای «install_package» یا «Could not create install_package directory»
مسیر `wp-content/` باید برای کاربر PHP قابل‌نوشتن باشد:

```bash
chown -R www-data:www-data wp-content
find wp-content -type d -exec chmod 755 {} \;
find wp-content -type f -exec chmod 644 {} \;
```

روی NGINX بررسی کنید که `open_basedir` مانع نوشتن در `wp-content/tmp` نشود.

### جدول‌ها ساخته نشده‌اند
- افزونه را یک‌بار غیرفعال و دوباره فعال کنید (فعال‌سازی، `Activator::install()` را اجرا می‌کند).
- یا استودیو → ابزارها → «نصب/تعمیر جدول‌ها».
- اگر کاربر MySQL دستور `CREATE` ندارد، با مدیر سرور هماهنگ کنید.

### استودیو باز نمی‌شود یا «توکن منقضی شد»
- استودیو با یک توکن یک‌ساعته کار می‌کند؛ از منوی «هوش‌سئو» دوباره وارد شوید.
- اگر پرmalink‌ها روی «ساده» است، افزونه خودکار نشانی را به شکل `?hoosh-seo-studio=1` می‌سازد.
- کش صفحه (WP Rocket / LiteSpeed / CDN) را پاک کنید.

### نقشه سایت ۴۰۴ می‌دهد
- تنظیمات → پیوندهای یکتا → یک‌بار «ذخیره» تا قوانین بازنویسی بازسازی شود.
- روی NGINX به یک قانون لازم نیاز دارید: `rewrite ^/sitemap_index.xml$ /index.php last;` (افزونه خودش پیشنهاد می‌دهد و در ابزارها قابل کپی است).

### توضیحات متا اعمال نمی‌شود
- اگر قالب شما `wp_head()` را در `footer.php` صدا می‌زند، جابه‌جاش کنید؛ متا باید در `<head>` چاپ شود.
- افزونهٔ سئوی دیگری غیرفعال کنید (تداخل متا و اسکیما).

### سرعت سایت بعد از فعال‌سازی کم شد
- استودیو → سرعت → «اعمال همه تیک‌های بی‌خطر» را بزنید و کش را پاک کنید.
- اگر کش object (Redis/Memcached) دارید، کش افزونه را از بخش ابزارها پاک کنید.

## ۷) حذف کامل

اگر می‌خواهید همه‌چیز پاک شود: در استودیو → تنظیمات → عمومی، گزینهٔ «حذف داده‌ها هنگام حذف افزونه» را روشن کنید و بعد افزونه را حذف کنید. اگر این گزینه خاموش باشد، جدول‌ها و تنظیمات دست‌نخورده می‌مانند تا نصب دوباره همه‌چیز را برگرداند. در دسترس نبودن پیشخوان:

```php
define( 'HOOSH_SEO_REMOVE_ON_UNINSTALL', true ); // در wp-config.php
```

## ۸) نصب از خط فرمان

```bash
wp plugin install dist/hoosh-seo.zip --activate
wp option patch update hoosh_seo_settings general site_name "فروشگاه من"
wp cache flush

# اجرای کارهای زمان‌بندی‌شده بدون منتظر ماندن (کلید در استودیو → ابزارها نشان داده می‌شود):
curl -s "https://example.com/?hoosh_cron=daily&key=<KEY>"
curl -s "https://example.com/?hoosh_cron=batch&key=<KEY>"
crontab -e
0 3 * * * curl -s "https://example.com/?hoosh_cron=daily&key=<KEY>" >/dev/null 2>&1
```

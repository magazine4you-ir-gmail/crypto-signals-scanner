=== Crypto Signal Scanner ===
Requires at least: 5.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.9.2
License: GPLv2 or later

راهنمای نصب:
1. پوشه crypto-signal-scanner را زیپ کرده و از مسیر پیشخوان > افزونه‌ها > افزودن > بارگذاری افزونه، آپلود و فعال کنید.
2. به منوی «سیگنال ارزها > تنظیمات» بروید، اندیکاتورهای دلخواه را فعال و پارامترهایشان را تنظیم کنید.
3. روی دکمه «اسکن الان» در صفحه داشبورد بزنید تا اولین اسکن انجام شود (اسکن‌های بعدی به‌صورت خودکار هر ساعت انجام می‌شوند).
4. برای نمایش نتایج در هر صفحه/نوشته از سایت، شورت‌کد [crypto_signals] را قرار دهید.

نکات فنی:
- دیتای قیمت از API رایگان CoinGecko گرفته می‌شود (بدون نیاز اجباری به کلید API).
- اندیکاتورهای موجود: RSI, MACD, تقاطع میانگین متحرک (EMA/SMA)، و SuperTrend.
- تایم‌فریم قابل تنظیم: ساعتی، روزانه، هفتگی — چند تایم‌فریم می‌توانند همزمان فعال باشند و کاربر در شورت‌کد بینشان جابه‌جا شود (هر تایم‌فریم اضافه، یک بار دیگر دیتای هر ارز را می‌گیرد، پس مصرف API را بالا می‌برد).
- سنجش خودکار دقت سیگنال‌ها: هر سیگنال خرید/فروش تازه ذخیره و بعد از مهلت تعیین‌شده با قیمت جدید سنجیده می‌شود (منوی «دقت سیگنال‌ها»).
- شورت‌کد [crypto_user_panel]: فیلتر AJAX (تب + تایم‌فریم + اندیکاتور + جستجو + دکمه اعمال فیلتر)، و برای هر ارز یک آکاردئون «تاریخچه» که سیگنال‌های قبلی، تایم‌فریم، اندیکاتور صادرکننده و نتیجه دقت‌سنجی را نشان می‌دهد.
- SuperTrend و اندیکاتورهای مشابه به داده High/Low نیاز دارند و یک درخواست اضافی به ازای هر ارز مصرف می‌کنند.
- به دلیل محدودیت نرخ درخواست CoinGecko، اسکن ۱۰۰ ارز به‌صورت دسته‌ای (هر دقیقه ۵ ارز) از طریق WP-Cron انجام می‌شود که حدود ۲۰ دقیقه طول می‌کشد. با دکمه «اسکن الان» هم همین صف به‌صورت سریع‌تر (هر ۸۰۰ میلی‌ثانیه یک دسته) از طریق مرورگر پردازش می‌شود.
- برای افزودن اندیکاتور جدید، فایل includes/class-indicator-base.php را ببینید — یک الگوی آماده برای ساخت اندیکاتور سفارشی دارد.
- WP-Cron به بازدید سایت وابسته است؛ برای اجرای دقیق‌تر زمان‌بندی، فعال‌سازی Cron واقعی سرور (crontab با فراخوانی wp-cron.php) توصیه می‌شود.

سلب مسئولیت: این افزونه صرفاً یک ابزار تحلیل تکنیکال خودکار است و سیگنال‌های آن توصیه مالی محسوب نمی‌شود.

== Binance via Supabase Gateway (2.9.2) ==

Binance requests are routed through the included Supabase Edge Function instead of direct WordPress-to-Binance HTTP calls.

Deploy `supabase/functions/market-data/index.ts` as an Edge Function named `market-data`, optionally create `market_data_cache` using `supabase/migrations/20260902_market_data_cache.sql`, then configure the function URL and shared token in the plugin's Binance settings. See `supabase/README.md` for the exact flow.

== 2.9.2 ==
* Fixed Binance universe response mapping (`items`) in the WordPress provider.
* Kept backward compatibility with the older `coins` response key.

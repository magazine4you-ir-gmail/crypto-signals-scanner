<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ==========================================================================
 * الگوی ساخت یک اندیکاتور جدید (این فایل را تغییر ندهید)
 * ==========================================================================
 *
 * برای افزودن یک اندیکاتور جدید:
 * 1) یک فایل جدید در پوشه includes/indicators/ بسازید (مثلاً class-indicator-bollinger.php)
 * 2) یک کلاس بسازید که از CSS_Indicator_Base ارث‌بری کند و ۴ متد زیر را پیاده‌سازی کند
 * 3) در فایل اصلی افزونه (crypto-signal-scanner.php) خط require_once مربوطه را اضافه کنید
 * 4) در includes/class-indicator-registry.php آن را به لیست register شده اضافه کنید
 *
 * همین! بقیه‌ی سیستم (اسکن، ترکیب سیگنال‌ها، نمایش در پیشخوان و شورت‌کد)
 * به‌صورت خودکار اندیکاتور جدید را شناسایی می‌کند.
 */
abstract class CSS_Indicator_Base {

	/** شناسه یکتای اندیکاتور - فقط حروف انگلیسی و آندرلاین، مثل 'rsi' */
	abstract public function get_id(): string;

	/** عنوان قابل‌نمایش برای کاربر فارسی‌زبان، مثل 'RSI (شاخص قدرت نسبی)' */
	abstract public function get_label(): string;

	/** تنظیمات پیش‌فرض این اندیکاتور به‌صورت آرایه انجمنی */
	abstract public function get_default_settings(): array;

	/**
	 * تحلیل نهایی: بر اساس داده‌های بازار و تنظیمات کاربر باید یکی از سه مقدار
	 * زیر را برگرداند:
	 *   'buy'     -> سیگنال خرید
	 *   'sell'    -> سیگنال فروش
	 *   'neutral' -> بدون سیگنال / خنثی
	 *
	 * @param array $data     آرایه داده بازار با کلیدهای:
	 *                        'close' (همیشه موجود؛ آرایه قیمت پایانی)
	 *                        'high', 'low', 'ohlc_close' (فقط اگر get_requires_ohlc() == true باشد)
	 * @param array $settings تنظیمات این اندیکاتور (از get_default_settings یا مقادیر ذخیره‌شده کاربر)
	 * @return string buy|sell|neutral
	 */
	abstract public function evaluate( array $data, array $settings ): string;

	/**
	 * آیا این اندیکاتور به داده High/Low (کندل کامل) نیاز دارد؟
	 * اگر true باشد، هنگام اسکن یک درخواست اضافی برای دریافت OHLC زده می‌شود
	 * و مقادیر $data['high'], $data['low'], $data['ohlc_close'] پر می‌شوند.
	 * پیش‌فرض false (فقط قیمت Close کافی است).
	 */
	public function get_requires_ohlc(): bool {
		return false;
	}

	/**
	 * (اختیاری) فیلدهای تنظیمات این اندیکاتور برای نمایش در صفحه ادمین.
	 * هر آیتم: کلید => ['label' => ..., 'type' => 'number', 'min'=>, 'max'=>, 'step'=>]
	 * پیش‌فرض بر اساس get_default_settings ساخته می‌شود؛ در صورت نیاز override کنید.
	 */
	public function get_settings_fields(): array {
		$fields = array();
		foreach ( $this->get_default_settings() as $key => $value ) {
			$fields[ $key ] = array(
				'label' => ucfirst( str_replace( '_', ' ', $key ) ),
				'type'  => is_numeric( $value ) ? 'number' : 'text',
			);
		}
		return $fields;
	}

	/** حداقل تعداد کندل/قیمت لازم برای محاسبه صحیح - override کنید در صورت نیاز */
	public function get_min_data_points(): int {
		return 30;
	}

	/**
	 * (اختیاری) مقادیر عددی کلیدی که این اندیکاتور برای صدور آخرین سیگنالش استفاده
	 * کرده — مثلاً مقدار ATR و ضریب برای SuperTrend، یا مقدار RSI. این‌ها فقط برای
	 * نمایش (در تقویم تاریخچه) ذخیره می‌شوند، در تصمیم‌گیری نقشی ندارند.
	 * خروجی: آرایه انجمنی برچسب‌فارسی => مقدار عددی. پیش‌فرض خالی؛ در صورت نیاز override کنید.
	 */
	public function get_last_metrics( array $data, array $settings ): array {
		return array();
	}
}

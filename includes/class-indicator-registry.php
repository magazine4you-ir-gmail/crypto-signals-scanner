<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * لیست تمام اندیکاتورهای موجود در سیستم.
 * برای افزودن اندیکاتور جدید، بعد از ساختن کلاس آن (طبق الگوی class-indicator-base.php)
 * فقط کافیست یک خط به آرایه‌ی build_registry() اضافه کنید.
 *
 * توسعه‌دهندگان دیگر (مثلاً در یک افزونه/تم دیگر) هم می‌توانند از طریق فیلتر
 * 'css_register_indicators' اندیکاتور خودشان را بدون تغییر این فایل اضافه کنند.
 */
class CSS_Indicator_Registry {

	private static ?array $instances = null;

	/** @return CSS_Indicator_Base[] کلید = شناسه اندیکاتور */
	public static function get_all(): array {
		if ( null === self::$instances ) {
			self::$instances = self::build_registry();
		}
		return self::$instances;
	}

	public static function get( string $id ): ?CSS_Indicator_Base {
		$all = self::get_all();
		return $all[ $id ] ?? null;
	}

	private static function build_registry(): array {
		$indicators = array(
			new CSS_Indicator_RSI(),
			new CSS_Indicator_MACD(),
			new CSS_Indicator_MA_Cross(),
			new CSS_Indicator_SuperTrend(),
			new CSS_Indicator_Bollinger(),
			new CSS_Indicator_Ichimoku(),
			// اندیکاتور جدید خودتان را همین‌جا اضافه کنید، مثلاً:
			// new CSS_Indicator_Bollinger(),
		);

		/**
		 * سایر افزونه‌ها/تم‌ها می‌توانند اندیکاتور اضافه کنند:
		 *
		 * add_filter('css_register_indicators', function($indicators) {
		 *     $indicators[] = new My_Custom_Indicator();
		 *     return $indicators;
		 * });
		 */
		$indicators = apply_filters( 'css_register_indicators', $indicators );

		$map = array();
		foreach ( $indicators as $indicator ) {
			if ( $indicator instanceof CSS_Indicator_Base ) {
				$map[ $indicator->get_id() ] = $indicator;
			}
		}
		return $map;
	}
}

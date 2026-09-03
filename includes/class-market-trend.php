<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ردیابی روند کلی بازار (بر مبنای مارکت کپ کل، مثل چارت TOTAL) و دامیننس بیت‌کوین.
 * هر بار که اسکن اجرا می‌شود (هر ساعت)، آخرین وضعیت گرفته و روی «تاریخ امروز» ذخیره
 * می‌شود — یعنی مقدار هر روز، آخرین خوانش همان روز است (نزدیک به وضعیت پایان روز).
 * ذخیره‌سازی در یک آپشن (بدون محدودیت تعداد روز، سبک و سریع برای خواندن).
 */
class CSS_Market_Trend {

	/** خواندن دیتای روند کلی از Provider فعال و ثبت/به‌روزرسانی رکورد «امروز». Binance فعلاً Global Market Cap ندارد. */
	public static function track(): void {
		$fetcher = new CSS_Data_Fetcher();
		$data    = $fetcher->get_global_market_data();
		if ( null === $data ) {
			return; // Provider فعال داده Global Market Trend ارائه نمی‌کند.
		}

		$settings  = get_option( 'css_settings', array() );
		$bull_th   = (float) ( $settings['bullish_threshold'] ?? 2 );
		$bear_th   = (float) ( $settings['bearish_threshold'] ?? -2 );

		$trend = 'neutral';
		if ( $data['change_pct_24h'] >= $bull_th ) {
			$trend = 'bullish';
		} elseif ( $data['change_pct_24h'] <= $bear_th ) {
			$trend = 'bearish';
		}

		$date = current_time( 'Y-m-d' );
		$log  = get_option( 'css_market_trend_log', array() );

		$log[ $date ] = array(
			'total_market_cap' => $data['total_market_cap'],
			'change_pct_24h'   => $data['change_pct_24h'],
			'btc_dominance'    => $data['btc_dominance'],
			'trend'            => $trend,
			'recorded_at'      => current_time( 'mysql' ),
		);

		ksort( $log );
		update_option( 'css_market_trend_log', $log, false );
	}

	public static function get_log(): array {
		return get_option( 'css_market_trend_log', array() );
	}

	public static function get_trend_for_date( string $date ): ?array {
		$log = self::get_log();
		return $log[ $date ] ?? null;
	}

	public static function get_today(): ?array {
		return self::get_trend_for_date( current_time( 'Y-m-d' ) );
	}

	public static function trend_label( string $trend ): string {
		return array(
			'bullish' => 'صعودی',
			'bearish' => 'نزولی',
			'neutral' => 'خنثی',
		)[ $trend ] ?? $trend;
	}
}

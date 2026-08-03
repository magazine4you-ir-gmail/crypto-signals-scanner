<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ایچیموکو (نسخه ساده‌شده برای اسکرینینگ): بر مبنای موقعیت قیمت نسبت به ابر (کومو)
 * و تقاطع تنکان‌سن/کیجون‌سن.
 * خرید: قیمت بالای ابر + تنکان‌سن بالای کیجون‌سن
 * فروش: قیمت زیر ابر + تنکان‌سن زیر کیجون‌سن
 * به داده High/Low نیاز دارد و به تاریخچه نسبتاً بلندی (حداقل ~۸۰ کندل) محتاج است.
 */
class CSS_Indicator_Ichimoku extends CSS_Indicator_Base {

	public function get_id(): string {
		return 'ichimoku';
	}

	public function get_label(): string {
		return 'ایچیموکو (Ichimoku Cloud)';
	}

	public function get_default_settings(): array {
		return array(
			'tenkan_period'   => 9,
			'kijun_period'    => 26,
			'senkou_b_period' => 52,
		);
	}

	public function get_settings_fields(): array {
		return array(
			'tenkan_period'   => array( 'label' => 'دوره تنکان‌سن', 'type' => 'number', 'min' => 2, 'max' => 60 ),
			'kijun_period'    => array( 'label' => 'دوره کیجون‌سن', 'type' => 'number', 'min' => 5, 'max' => 120 ),
			'senkou_b_period' => array( 'label' => 'دوره سنکو اسپن B', 'type' => 'number', 'min' => 10, 'max' => 200 ),
		);
	}

	public function get_requires_ohlc(): bool {
		return true;
	}

	public function get_min_data_points(): int {
		return 80;
	}

	public function evaluate( array $data, array $settings ): string {
		$closes = $data['ohlc_close'] ?? array();
		$highs  = $data['high'] ?? array();
		$lows   = $data['low'] ?? array();
		$count  = count( $closes );

		if ( empty( $closes ) || empty( $highs ) || empty( $lows ) || $count !== count( $highs ) || $count !== count( $lows ) ) {
			return 'neutral';
		}

		$tenkan_p   = (int) ( $settings['tenkan_period'] ?? 9 );
		$kijun_p    = (int) ( $settings['kijun_period'] ?? 26 );
		$senkou_b_p = (int) ( $settings['senkou_b_period'] ?? 52 );

		if ( $count < $senkou_b_p + $kijun_p + 1 ) {
			return 'neutral';
		}

		$hl9  = CSS_MA_Helper::highest_lowest( $highs, $lows, $tenkan_p );
		$hl26 = CSS_MA_Helper::highest_lowest( $highs, $lows, $kijun_p );
		$hl52 = CSS_MA_Helper::highest_lowest( $highs, $lows, $senkou_b_p );

		$last        = $count - 1;
		// ایندکسی که ابر «امروز» از روی مقادیر همان ایندکس (۲۶ دوره قبل) ساخته شده
		$cloud_index = $last - $kijun_p;

		if ( $cloud_index < 0
			|| null === ( $hl9['highest'][ $last ] ?? null )
			|| null === ( $hl26['highest'][ $last ] ?? null )
			|| null === ( $hl9['highest'][ $cloud_index ] ?? null )
			|| null === ( $hl26['highest'][ $cloud_index ] ?? null )
			|| null === ( $hl52['highest'][ $cloud_index ] ?? null ) ) {
			return 'neutral';
		}

		$tenkan_now = ( $hl9['highest'][ $last ] + $hl9['lowest'][ $last ] ) / 2;
		$kijun_now  = ( $hl26['highest'][ $last ] + $hl26['lowest'][ $last ] ) / 2;

		$tenkan_cloud = ( $hl9['highest'][ $cloud_index ] + $hl9['lowest'][ $cloud_index ] ) / 2;
		$kijun_cloud  = ( $hl26['highest'][ $cloud_index ] + $hl26['lowest'][ $cloud_index ] ) / 2;
		$senkou_a     = ( $tenkan_cloud + $kijun_cloud ) / 2;
		$senkou_b     = ( $hl52['highest'][ $cloud_index ] + $hl52['lowest'][ $cloud_index ] ) / 2;

		$cloud_top    = max( $senkou_a, $senkou_b );
		$cloud_bottom = min( $senkou_a, $senkou_b );
		$price        = $closes[ $last ];

		if ( $price > $cloud_top && $tenkan_now > $kijun_now ) {
			return 'buy';
		}

		if ( $price < $cloud_bottom && $tenkan_now < $kijun_now ) {
			return 'sell';
		}

		return 'neutral';
	}

	public function get_last_metrics( array $data, array $settings ): array {
		$closes = $data['ohlc_close'] ?? array();
		$highs  = $data['high'] ?? array();
		$lows   = $data['low'] ?? array();
		$count  = count( $closes );
		if ( empty( $closes ) || empty( $highs ) || empty( $lows ) || $count !== count( $highs ) || $count !== count( $lows ) ) {
			return array();
		}

		$tenkan_p   = (int) ( $settings['tenkan_period'] ?? 9 );
		$kijun_p    = (int) ( $settings['kijun_period'] ?? 26 );
		$senkou_b_p = (int) ( $settings['senkou_b_period'] ?? 52 );
		if ( $count < $senkou_b_p + $kijun_p + 1 ) {
			return array();
		}

		$hl9  = CSS_MA_Helper::highest_lowest( $highs, $lows, $tenkan_p );
		$hl26 = CSS_MA_Helper::highest_lowest( $highs, $lows, $kijun_p );
		$last = $count - 1;
		if ( null === ( $hl9['highest'][ $last ] ?? null ) || null === ( $hl26['highest'][ $last ] ?? null ) ) {
			return array();
		}

		$tenkan = ( $hl9['highest'][ $last ] + $hl9['lowest'][ $last ] ) / 2;
		$kijun  = ( $hl26['highest'][ $last ] + $hl26['lowest'][ $last ] ) / 2;

		return array(
			'تنکان‌سن' => round( $tenkan, 6 ),
			'کیجون‌سن' => round( $kijun, 6 ),
		);
	}
}

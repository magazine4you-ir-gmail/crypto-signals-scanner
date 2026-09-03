<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SuperTrend یک اندیکاتور دنبال‌کننده روند است که بر پایه ATR (میانگین محدوده واقعی)
 * ساخته می‌شود و برخلاف RSI/MACD/MA-Cross به داده High و Low هر کندل نیاز دارد
 * (نه فقط قیمت Close). به همین دلیل get_requires_ohlc() این کلاس true است.
 */
class CSS_Indicator_SuperTrend extends CSS_Indicator_Base {

	public function get_id(): string {
		return 'supertrend';
	}

	public function get_label(): string {
		return 'SuperTrend (خرید/فروش بر اساس روند)';
	}

	public function get_default_settings(): array {
		return array(
			'period'     => 10,
			'multiplier' => 3,
		);
	}

	public function get_settings_fields(): array {
		return array(
			'period'     => array( 'label' => 'دوره ATR', 'type' => 'number', 'min' => 2, 'max' => 100 ),
			'multiplier' => array( 'label' => 'ضریب باند', 'type' => 'number', 'min' => 0.5, 'max' => 10 ),
		);
	}

	public function get_requires_ohlc(): bool {
		return true;
	}

	public function get_min_data_points(): int {
		return 40;
	}

	public function evaluate( array $data, array $settings ): string {
		$closes = $data['ohlc_close'] ?? array();
		$highs  = $data['high'] ?? array();
		$lows   = $data['low'] ?? array();

		$count = count( $closes );
		if ( empty( $closes ) || empty( $highs ) || empty( $lows )
			|| $count !== count( $highs ) || $count !== count( $lows ) ) {
			return 'neutral';
		}

		$period     = (int) ( $settings['period'] ?? 10 );
		$multiplier = (float) ( $settings['multiplier'] ?? 3 );

		if ( $count <= $period + 2 ) {
			return 'neutral';
		}

		$atr = CSS_MA_Helper::atr( $highs, $lows, $closes, $period );

		$final_upper = array_fill( 0, $count, null );
		$final_lower = array_fill( 0, $count, null );
		$direction   = array_fill( 0, $count, null ); // 'up' | 'down'

		for ( $i = $period; $i < $count; $i++ ) {
			if ( null === $atr[ $i ] ) {
				continue;
			}

			$mid         = ( $highs[ $i ] + $lows[ $i ] ) / 2;
			$basic_upper = $mid + $multiplier * $atr[ $i ];
			$basic_lower = $mid - $multiplier * $atr[ $i ];

			$prev_final_upper = $final_upper[ $i - 1 ] ?? $basic_upper;
			$prev_final_lower = $final_lower[ $i - 1 ] ?? $basic_lower;

			$final_upper[ $i ] = ( $basic_upper < $prev_final_upper || $closes[ $i - 1 ] > $prev_final_upper )
				? $basic_upper : $prev_final_upper;

			$final_lower[ $i ] = ( $basic_lower > $prev_final_lower || $closes[ $i - 1 ] < $prev_final_lower )
				? $basic_lower : $prev_final_lower;

			$prev_direction = $direction[ $i - 1 ] ?? 'up';

			if ( 'up' === $prev_direction ) {
				$direction[ $i ] = ( $closes[ $i ] < $final_lower[ $i ] ) ? 'down' : 'up';
			} else {
				$direction[ $i ] = ( $closes[ $i ] > $final_upper[ $i ] ) ? 'up' : 'down';
			}
		}

		// دو مقدار آخر جهت روند را برای تشخیص تغییر جهت (سیگنال) پیدا کن
		$last = null;
		$prev = null;
		for ( $i = $count - 1; $i >= $period; $i-- ) {
			if ( null === $direction[ $i ] ) {
				continue;
			}
			if ( null === $last ) {
				$last = $direction[ $i ];
			} elseif ( null === $prev ) {
				$prev = $direction[ $i ];
				break;
			}
		}

		if ( null === $last || null === $prev ) {
			return 'neutral';
		}

		if ( 'down' === $prev && 'up' === $last ) {
			return 'buy'; // تغییر روند از نزولی به صعودی
		}

		if ( 'up' === $prev && 'down' === $last ) {
			return 'sell'; // تغییر روند از صعودی به نزولی
		}

		return 'neutral';
	}

	public function get_last_metrics( array $data, array $settings ): array {
		$closes = $data['ohlc_close'] ?? array();
		$highs  = $data['high'] ?? array();
		$lows   = $data['low'] ?? array();
		if ( empty( $closes ) || empty( $highs ) || empty( $lows ) ) {
			return array();
		}

		$period     = (int) ( $settings['period'] ?? 10 );
		$multiplier = (float) ( $settings['multiplier'] ?? 3 );

		$metrics = array(
			'دوره ATR'  => $period,
			'ضریب باند' => $multiplier,
		);

		$atr  = CSS_MA_Helper::atr( $highs, $lows, $closes, $period );
		$last = CSS_MA_Helper::last_valid( $atr );
		if ( $last ) {
			$metrics['مقدار ATR'] = round( (float) $last['value'], 6 );
		}

		return $metrics;
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Indicator_MACD extends CSS_Indicator_Base {

	public function get_id(): string {
		return 'macd';
	}

	public function get_label(): string {
		return 'MACD (تقاطع خط سیگنال)';
	}

	public function get_default_settings(): array {
		return array(
			'fast_period'   => 12,
			'slow_period'   => 26,
			'signal_period' => 9,
		);
	}

	public function get_settings_fields(): array {
		return array(
			'fast_period'   => array( 'label' => 'دوره EMA سریع', 'type' => 'number', 'min' => 2, 'max' => 100 ),
			'slow_period'   => array( 'label' => 'دوره EMA کند', 'type' => 'number', 'min' => 3, 'max' => 200 ),
			'signal_period' => array( 'label' => 'دوره خط سیگنال', 'type' => 'number', 'min' => 2, 'max' => 100 ),
		);
	}

	public function get_min_data_points(): int {
		return 60;
	}

	public function evaluate( array $data, array $settings ): string {
		$closes = $data['close'] ?? array();
		$fast   = (int) ( $settings['fast_period'] ?? 12 );
		$slow   = (int) ( $settings['slow_period'] ?? 26 );
		$signal = (int) ( $settings['signal_period'] ?? 9 );

		if ( count( $closes ) < $slow + $signal ) {
			return 'neutral';
		}

		$ema_fast = CSS_MA_Helper::ema( $closes, $fast );
		$ema_slow = CSS_MA_Helper::ema( $closes, $slow );

		$macd_line = array();
		$count     = count( $closes );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( null !== $ema_fast[ $i ] && null !== $ema_slow[ $i ] ) {
				$macd_line[ $i ] = $ema_fast[ $i ] - $ema_slow[ $i ];
			} else {
				$macd_line[ $i ] = null;
			}
		}

		// خط سیگنال = EMA روی مقادیر معتبر خط MACD
		$valid_macd_values = array_values( array_filter( $macd_line, fn( $v ) => null !== $v ) );
		$signal_ema_raw     = CSS_MA_Helper::ema( $valid_macd_values, $signal );

		// آخرین دو مقدار برای تشخیص تقاطع
		$n = count( $valid_macd_values );
		if ( $n < 2 || count( $signal_ema_raw ) < 2 ) {
			return 'neutral';
		}

		$macd_last  = $valid_macd_values[ $n - 1 ];
		$macd_prev  = $valid_macd_values[ $n - 2 ];
		$sig_last   = $signal_ema_raw[ $n - 1 ];
		$sig_prev   = $signal_ema_raw[ $n - 2 ];

		if ( null === $sig_last || null === $sig_prev ) {
			return 'neutral';
		}

		// تقاطع رو به بالا -> خرید
		if ( $macd_prev <= $sig_prev && $macd_last > $sig_last ) {
			return 'buy';
		}

		// تقاطع رو به پایین -> فروش
		if ( $macd_prev >= $sig_prev && $macd_last < $sig_last ) {
			return 'sell';
		}

		return 'neutral';
	}

	public function get_last_metrics( array $data, array $settings ): array {
		$closes = $data['close'] ?? array();
		$fast   = (int) ( $settings['fast_period'] ?? 12 );
		$slow   = (int) ( $settings['slow_period'] ?? 26 );
		$signal = (int) ( $settings['signal_period'] ?? 9 );
		if ( count( $closes ) < $slow + $signal ) {
			return array();
		}

		$ema_fast = CSS_MA_Helper::ema( $closes, $fast );
		$ema_slow = CSS_MA_Helper::ema( $closes, $slow );
		$macd_line = array();
		foreach ( $ema_fast as $i => $v ) {
			$macd_line[ $i ] = ( null !== $v && null !== $ema_slow[ $i ] ) ? $v - $ema_slow[ $i ] : null;
		}
		$last_macd = CSS_MA_Helper::last_valid( $macd_line );

		return $last_macd ? array( 'خط MACD' => round( (float) $last_macd['value'], 6 ) ) : array();
	}
}

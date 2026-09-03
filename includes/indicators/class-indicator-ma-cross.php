<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Indicator_MA_Cross extends CSS_Indicator_Base {

	public function get_id(): string {
		return 'ma_cross';
	}

	public function get_label(): string {
		return 'تقاطع میانگین متحرک (EMA/SMA Cross)';
	}

	public function get_default_settings(): array {
		return array(
			'type'         => 'ema', // ema | sma
			'short_period' => 9,
			'long_period'  => 21,
		);
	}

	public function get_settings_fields(): array {
		return array(
			'type'         => array( 'label' => 'نوع میانگین متحرک', 'type' => 'select', 'options' => array( 'ema' => 'EMA', 'sma' => 'SMA' ) ),
			'short_period' => array( 'label' => 'دوره کوتاه', 'type' => 'number', 'min' => 2, 'max' => 100 ),
			'long_period'  => array( 'label' => 'دوره بلند', 'type' => 'number', 'min' => 3, 'max' => 300 ),
		);
	}

	public function get_min_data_points(): int {
		return 40;
	}

	public function evaluate( array $data, array $settings ): string {
		$closes = $data['close'] ?? array();
		$type  = $settings['type'] ?? 'ema';
		$short = (int) ( $settings['short_period'] ?? 9 );
		$long  = (int) ( $settings['long_period'] ?? 21 );

		if ( count( $closes ) < $long + 2 ) {
			return 'neutral';
		}

		$short_series = 'sma' === $type
			? CSS_MA_Helper::sma( $closes, $short )
			: CSS_MA_Helper::ema( $closes, $short );

		$long_series = 'sma' === $type
			? CSS_MA_Helper::sma( $closes, $long )
			: CSS_MA_Helper::ema( $closes, $long );

		$count = count( $closes );
		$last  = $count - 1;
		$prev  = $count - 2;

		if ( null === $short_series[ $last ] || null === $long_series[ $last ]
			|| null === $short_series[ $prev ] || null === $long_series[ $prev ] ) {
			return 'neutral';
		}

		// تقاطع رو به بالا -> خرید
		if ( $short_series[ $prev ] <= $long_series[ $prev ] && $short_series[ $last ] > $long_series[ $last ] ) {
			return 'buy';
		}

		// تقاطع رو به پایین -> فروش
		if ( $short_series[ $prev ] >= $long_series[ $prev ] && $short_series[ $last ] < $long_series[ $last ] ) {
			return 'sell';
		}

		return 'neutral';
	}

	public function get_last_metrics( array $data, array $settings ): array {
		$closes = $data['close'] ?? array();
		$type   = $settings['type'] ?? 'ema';
		$short  = (int) ( $settings['short_period'] ?? 9 );
		$long   = (int) ( $settings['long_period'] ?? 21 );
		if ( count( $closes ) < $long + 2 ) {
			return array();
		}

		$short_series = 'sma' === $type ? CSS_MA_Helper::sma( $closes, $short ) : CSS_MA_Helper::ema( $closes, $short );
		$long_series  = 'sma' === $type ? CSS_MA_Helper::sma( $closes, $long ) : CSS_MA_Helper::ema( $closes, $long );

		$last_short = CSS_MA_Helper::last_valid( $short_series );
		$last_long  = CSS_MA_Helper::last_valid( $long_series );

		$metrics = array();
		if ( $last_short ) { $metrics[ 'MA کوتاه (' . $short . ')' ] = round( (float) $last_short['value'], 6 ); }
		if ( $last_long ) { $metrics[ 'MA بلند (' . $long . ')' ] = round( (float) $last_long['value'], 6 ); }
		return $metrics;
	}
}

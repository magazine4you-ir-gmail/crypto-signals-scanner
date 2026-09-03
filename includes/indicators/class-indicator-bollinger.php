<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * بولینگر باند: باند بالا/پایین بر مبنای میانگین متحرک ± ضریبی از انحراف معیار.
 * وقتی قیمت به باند پایین برسد یا زیرش بره -> اشباع فروش -> پتانسیل خرید.
 * وقتی قیمت به باند بالا برسد یا بالاترش بره -> اشباع خرید -> پتانسیل فروش.
 */
class CSS_Indicator_Bollinger extends CSS_Indicator_Base {

	public function get_id(): string {
		return 'bollinger';
	}

	public function get_label(): string {
		return 'بولینگر باند (Bollinger Bands)';
	}

	public function get_default_settings(): array {
		return array(
			'period' => 20,
			'stddev' => 2,
		);
	}

	public function get_settings_fields(): array {
		return array(
			'period' => array( 'label' => 'دوره میانگین متحرک', 'type' => 'number', 'min' => 5, 'max' => 100 ),
			'stddev' => array( 'label' => 'ضریب انحراف معیار', 'type' => 'number', 'min' => 0.5, 'max' => 5 ),
		);
	}

	public function get_min_data_points(): int {
		return 30;
	}

	public function evaluate( array $data, array $settings ): string {
		$closes = $data['close'] ?? array();
		$period = (int) ( $settings['period'] ?? 20 );
		$mult   = (float) ( $settings['stddev'] ?? 2 );

		$count = count( $closes );
		if ( $count < $period + 1 ) {
			return 'neutral';
		}

		$sma  = CSS_MA_Helper::sma( $closes, $period );
		$last = $count - 1;
		if ( null === $sma[ $last ] ) {
			return 'neutral';
		}

		$slice    = array_slice( $closes, $last - $period + 1, $period );
		$mean     = $sma[ $last ];
		$variance = 0;
		foreach ( $slice as $v ) {
			$variance += ( $v - $mean ) ** 2;
		}
		$variance /= $period;
		$stddev    = sqrt( $variance );

		$upper = $mean + $mult * $stddev;
		$lower = $mean - $mult * $stddev;
		$price = $closes[ $last ];

		if ( $price <= $lower ) {
			return 'buy'; // قیمت روی/زیر باند پایین -> اشباع فروش
		}

		if ( $price >= $upper ) {
			return 'sell'; // قیمت روی/بالای باند بالا -> اشباع خرید
		}

		return 'neutral';
	}

	public function get_last_metrics( array $data, array $settings ): array {
		$closes = $data['close'] ?? array();
		$period = (int) ( $settings['period'] ?? 20 );
		$mult   = (float) ( $settings['stddev'] ?? 2 );
		$count  = count( $closes );
		if ( $count < $period + 1 ) {
			return array();
		}

		$sma  = CSS_MA_Helper::sma( $closes, $period );
		$last = $count - 1;
		if ( null === $sma[ $last ] ) {
			return array();
		}

		$slice    = array_slice( $closes, $last - $period + 1, $period );
		$mean     = $sma[ $last ];
		$variance = 0;
		foreach ( $slice as $v ) {
			$variance += ( $v - $mean ) ** 2;
		}
		$stddev = sqrt( $variance / $period );

		return array(
			'باند بالا'  => round( $mean + $mult * $stddev, 6 ),
			'میانگین'    => round( $mean, 6 ),
			'باند پایین' => round( $mean - $mult * $stddev, 6 ),
		);
	}
}

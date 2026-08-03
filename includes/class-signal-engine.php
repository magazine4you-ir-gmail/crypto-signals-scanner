<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * برای یک ارز مشخص، اندیکاتورهای فعال را اجرا کرده و طبق حالت ترکیب انتخابی
 * (any / all_agree / majority) یک سیگنال نهایی (buy/sell/neutral) تولید می‌کند.
 */
class CSS_Signal_Engine {

	private array $settings;

	public function __construct() {
		$this->settings = get_option( 'css_settings', array() );
	}

	/**
	 * @param array $data آرایه داده بازار: ['close'=>[...], 'high'=>[...]?, 'low'=>[...]?, 'ohlc_close'=>[...]?]
	 * @return array [
	 *   'signal' => 'buy'|'sell'|'neutral',
	 *   'details' => [ indicator_id => 'buy'|'sell'|'neutral', ... ]
	 * ]
	 */
	public function analyze( array $data ): array {
		$active_ids         = $this->settings['active_indicators'] ?? array();
		$indicator_settings = $this->settings['indicator_settings'] ?? array();

		$details    = array();
		$metrics    = array();
		$buy_votes  = 0;
		$sell_votes = 0;

		foreach ( $active_ids as $id ) {
			$indicator = CSS_Indicator_Registry::get( $id );
			if ( ! $indicator ) {
				continue;
			}

			// اندیکاتورهای OHLC از سری close مخصوص کندل خودشون استفاده می‌کنن، نه close معمولی
			$ref_closes = $indicator->get_requires_ohlc() ? ( $data['ohlc_close'] ?? array() ) : ( $data['close'] ?? array() );

			if ( count( $ref_closes ) < $indicator->get_min_data_points() ) {
				$details[ $id ] = 'neutral';
				continue;
			}

			$params = $indicator_settings[ $id ] ?? $indicator->get_default_settings();
			$result = $indicator->evaluate( $data, $params );
			$details[ $id ] = $result;

			$m = $indicator->get_last_metrics( $data, $params );
			if ( ! empty( $m ) ) {
				$metrics[ $id ] = $m;
			}

			if ( 'buy' === $result ) {
				$buy_votes++;
			} elseif ( 'sell' === $result ) {
				$sell_votes++;
			}
		}

		$mode   = $this->settings['combination_mode'] ?? 'majority';
		$total  = count( $active_ids );
		$signal = $this->combine( $mode, $buy_votes, $sell_votes, $total );

		return array(
			'signal'  => $signal,
			'details' => $details,
			'metrics' => $metrics,
		);
	}

	private function combine( string $mode, int $buy, int $sell, int $total ): string {
		if ( 0 === $total ) {
			return 'neutral';
		}

		switch ( $mode ) {
			case 'all_agree':
				if ( $buy === $total ) return 'buy';
				if ( $sell === $total ) return 'sell';
				return 'neutral';

			case 'any':
				if ( $buy > 0 && 0 === $sell ) return 'buy';
				if ( $sell > 0 && 0 === $buy ) return 'sell';
				return 'neutral'; // تناقض بین اندیکاتورها

			case 'majority':
			default:
				if ( $buy > $sell && $buy > 0 ) return 'buy';
				if ( $sell > $buy && $sell > 0 ) return 'sell';
				return 'neutral';
		}
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Indicator_RSI extends CSS_Indicator_Base {

	public function get_id(): string {
		return 'rsi';
	}

	public function get_label(): string {
		return 'RSI (شاخص قدرت نسبی)';
	}

	public function get_default_settings(): array {
		return array(
			'period'     => 14,
			'oversold'   => 30,
			'overbought' => 70,
		);
	}

	public function get_settings_fields(): array {
		return array(
			'period'     => array( 'label' => 'دوره RSI', 'type' => 'number', 'min' => 2, 'max' => 100 ),
			'oversold'   => array( 'label' => 'آستانه اشباع فروش (سیگنال خرید زیر این عدد)', 'type' => 'number', 'min' => 1, 'max' => 50 ),
			'overbought' => array( 'label' => 'آستانه اشباع خرید (سیگنال فروش بالای این عدد)', 'type' => 'number', 'min' => 50, 'max' => 99 ),
		);
	}

	public function get_min_data_points(): int {
		return 30;
	}

	public function evaluate( array $data, array $settings ): string {
		$closes     = $data['close'] ?? array();
		$period     = (int) ( $settings['period'] ?? 14 );
		$oversold   = (float) ( $settings['oversold'] ?? 30 );
		$overbought = (float) ( $settings['overbought'] ?? 70 );

		$rsi_series = CSS_MA_Helper::rsi( $closes, $period );
		$last       = CSS_MA_Helper::last_valid( $rsi_series );

		if ( null === $last ) {
			return 'neutral';
		}

		if ( $last['value'] <= $oversold ) {
			return 'buy'; // بازار در ناحیه اشباع فروش -> پتانسیل خرید
		}

		if ( $last['value'] >= $overbought ) {
			return 'sell'; // بازار در ناحیه اشباع خرید -> پتانسیل فروش
		}

		return 'neutral';
	}

	public function get_last_metrics( array $data, array $settings ): array {
		$closes = $data['close'] ?? array();
		if ( empty( $closes ) ) {
			return array();
		}
		$period = (int) ( $settings['period'] ?? 14 );
		$last   = CSS_MA_Helper::last_valid( CSS_MA_Helper::rsi( $closes, $period ) );
		return $last ? array( 'مقدار RSI' => round( (float) $last['value'], 2 ) ) : array();
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * توابع محاسباتی مشترک که همه اندیکاتورها از آن استفاده می‌کنند.
 * ورودی همه‌ی توابع: آرایه‌ای ساده از قیمت‌های Close به ترتیب زمانی (قدیم -> جدید)
 */
class CSS_MA_Helper {

	/**
	 * میانگین متحرک ساده - خروجی: آرایه هم‌طول با ورودی (ابتدای آن null است)
	 */
	public static function sma( array $closes, int $period ): array {
		$count  = count( $closes );
		$result = array_fill( 0, $count, null );

		for ( $i = $period - 1; $i < $count; $i++ ) {
			$slice          = array_slice( $closes, $i - $period + 1, $period );
			$result[ $i ]   = array_sum( $slice ) / $period;
		}
		return $result;
	}

	/**
	 * میانگین متحرک نمایی - خروجی: آرایه هم‌طول با ورودی
	 */
	public static function ema( array $closes, int $period ): array {
		$count  = count( $closes );
		$result = array_fill( 0, $count, null );

		if ( $count < $period ) {
			return $result;
		}

		$multiplier = 2 / ( $period + 1 );

		// مقدار اولیه EMA = میانگین ساده‌ی period اول
		$seed              = array_sum( array_slice( $closes, 0, $period ) ) / $period;
		$result[ $period - 1 ] = $seed;

		for ( $i = $period; $i < $count; $i++ ) {
			$result[ $i ] = ( ( $closes[ $i ] - $result[ $i - 1 ] ) * $multiplier ) + $result[ $i - 1 ];
		}
		return $result;
	}

	/**
	 * شاخص قدرت نسبی (RSI) - خروجی: آرایه هم‌طول با ورودی
	 */
	public static function rsi( array $closes, int $period = 14 ): array {
		$count  = count( $closes );
		$result = array_fill( 0, $count, null );

		if ( $count <= $period ) {
			return $result;
		}

		$gains   = array();
		$losses  = array();

		for ( $i = 1; $i < $count; $i++ ) {
			$change     = $closes[ $i ] - $closes[ $i - 1 ];
			$gains[ $i ]  = $change > 0 ? $change : 0;
			$losses[ $i ] = $change < 0 ? abs( $change ) : 0;
		}

		// میانگین اولیه (Wilder Smoothing)
		$avg_gain = array_sum( array_slice( $gains, 1, $period ) ) / $period;
		$avg_loss = array_sum( array_slice( $losses, 1, $period ) ) / $period;

		$rs               = $avg_loss == 0 ? 100 : $avg_gain / $avg_loss;
		$result[ $period ] = 100 - ( 100 / ( 1 + $rs ) );

		for ( $i = $period + 1; $i < $count; $i++ ) {
			$avg_gain = ( ( $avg_gain * ( $period - 1 ) ) + $gains[ $i ] ) / $period;
			$avg_loss = ( ( $avg_loss * ( $period - 1 ) ) + $losses[ $i ] ) / $period;

			$rs           = $avg_loss == 0 ? 100 : $avg_gain / $avg_loss;
			$result[ $i ] = 100 - ( 100 / ( 1 + $rs ) );
		}
		return $result;
	}

	/**
	 * میانگین محدوده واقعی (ATR) - برای اندیکاتورهایی مثل SuperTrend لازم است
	 * ورودی: آرایه‌های high, low, close هم‌طول - خروجی: آرایه هم‌طول ATR
	 */
	public static function atr( array $highs, array $lows, array $closes, int $period = 10 ): array {
		$count = count( $closes );
		$tr     = array_fill( 0, $count, null );
		$result = array_fill( 0, $count, null );

		if ( $count <= $period ) {
			return $result;
		}

		for ( $i = 1; $i < $count; $i++ ) {
			$tr[ $i ] = max(
				$highs[ $i ] - $lows[ $i ],
				abs( $highs[ $i ] - $closes[ $i - 1 ] ),
				abs( $lows[ $i ] - $closes[ $i - 1 ] )
			);
		}

		$sum = 0;
		for ( $i = 1; $i <= $period; $i++ ) {
			$sum += $tr[ $i ];
		}
		$result[ $period ] = $sum / $period;

		for ( $i = $period + 1; $i < $count; $i++ ) {
			$result[ $i ] = ( ( $result[ $i - 1 ] * ( $period - 1 ) ) + $tr[ $i ] ) / $period;
		}
		return $result;
	}

	/**
	 * درصد سود/ضرر یک سیگنال بر اساس استاندارد محاسبه بازار:
	 * برای خرید: (قیمت فعلی - قیمت سیگنال) / قیمت سیگنال × ۱۰۰
	 * برای فروش: عکس همین (چون در فروش، افت قیمت یعنی سود)
	 */
	public static function signal_pl_percent( string $signal, $price_at_signal, $price_at_check ) {
		if ( empty( $price_at_signal ) || null === $price_at_check || '' === $price_at_check ) {
			return null;
		}
		$change = ( ( (float) $price_at_check - (float) $price_at_signal ) / (float) $price_at_signal ) * 100;
		return 'buy' === $signal ? $change : -$change;
	}

	/**
	 * بالاترین و پایین‌ترین مقدار در هر پنجره N‌تایی (برای ایچیموکو لازم است)
	 * ورودی: آرایه‌های high, low هم‌طول - خروجی: ['highest'=>[], 'lowest'=>[]] هم‌طول با ورودی
	 */
	public static function highest_lowest( array $highs, array $lows, int $period ): array {
		$count   = count( $highs );
		$highest = array_fill( 0, $count, null );
		$lowest  = array_fill( 0, $count, null );

		for ( $i = $period - 1; $i < $count; $i++ ) {
			$h_slice = array_slice( $highs, $i - $period + 1, $period );
			$l_slice = array_slice( $lows, $i - $period + 1, $period );
			$highest[ $i ] = max( $h_slice );
			$lowest[ $i ]  = min( $l_slice );
		}
		return array( 'highest' => $highest, 'lowest' => $lowest );
	}

	/**
	 * آخرین مقدار غیر-null یک آرایه محاسباتی را برمی‌گرداند
	 */
	public static function last_valid( array $series ) {
		for ( $i = count( $series ) - 1; $i >= 0; $i-- ) {
			if ( null !== $series[ $i ] ) {
				return array( 'index' => $i, 'value' => $series[ $i ] );
			}
		}
		return null;
	}
}

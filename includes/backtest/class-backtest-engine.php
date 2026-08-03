<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * منطق محاسباتی معاملات بک‌تست: حجم پوزیشن، حد ضرر خودکار (بر پایه ATR)، حد سود
 * خودکار (بر پایه نسبت ریسک به ریوارد)، قیمت لیکویید (مارجین ایزوله)، کارمزد،
 * سود/زیان، و شبیه‌سازی روی داده تاریخی. همه متدها استاتیک و بدون وابستگی جانبی‌اند.
 */
class CSS_Backtest_Engine {

	/** تنظیمات پیش‌فرض بک‌تست را برمی‌گرداند (قابل تغییر از پیشخوان) */
	public static function get_settings(): array {
		$defaults = array(
			'max_leverage'            => 20,
			'fee_pct'                 => 0.1,   // درصد کارمزد هر طرف معامله (باز و بسته)
			'atr_period'              => 14,
			'atr_sl_multiplier'       => 1.5,   // فاصله حد ضرر خودکار از قیمت ورود = ATR × این عدد
			'maintenance_margin_pct'  => 0.5,   // برای محاسبه قیمت لیکویید (مارجین ایزوله)
			'min_initial_balance'     => 100,
			'max_initial_balance'     => 1000000,
			'max_accounts_per_user'   => 5,
			'max_open_trades_per_acc' => 10,
			'default_rr_ratio'        => 2,
			'enable_live'             => 'yes',
			'enable_historical'       => 'yes',
			'login_redirect_url'      => '',
			'module_enabled'          => 'no',
		);
		$saved = get_option( 'css_bt_settings', array() );
		return wp_parse_args( $saved, $defaults );
	}

	/** حجم پوزیشن (دلاری) = مارجین × لوریج */
	public static function position_size( float $margin_usd, float $leverage ): float {
		return $margin_usd * $leverage;
	}

	/** تعداد واحد ارز خریداری‌شده (برای محاسبه سود/زیان دلاری) */
	public static function qty( float $position_size_usd, float $entry_price ): float {
		return $entry_price > 0 ? $position_size_usd / $entry_price : 0.0;
	}

	/**
	 * حد ضرر خودکار بر پایه ATR — اگر دیتای کندل کافی نبود، بازگشت به یک درصد ثابت
	 * (۲ درصد) از قیمت ورود به‌عنوان جایگزین امن.
	 */
	public static function auto_stop_loss( string $direction, float $entry_price, ?float $atr ): float {
		$settings = self::get_settings();
		$distance = ( null !== $atr && $atr > 0 )
			? $atr * (float) $settings['atr_sl_multiplier']
			: $entry_price * 0.02;

		return 'sell' === $direction ? $entry_price + $distance : $entry_price - $distance;
	}

	/** حد سود بر پایه نسبت ریسک به ریوارد (R:R) — فاصله TP از ورود = فاصله SL از ورود × نسبت */
	public static function take_profit_from_rr( string $direction, float $entry_price, float $sl, float $rr_ratio ): float {
		$risk_distance = abs( $entry_price - $sl );
		$reward        = $risk_distance * max( 0.1, $rr_ratio );
		return 'sell' === $direction ? $entry_price - $reward : $entry_price + $reward;
	}

	/**
	 * قیمت لیکویید — مارجین ایزوله، ساده‌شده:
	 * لانگ:  liq = entry × (1 − 1/leverage + نگهداری%)
	 * شورت:  liq = entry × (1 + 1/leverage − نگهداری%)
	 */
	public static function liquidation_price( string $direction, float $entry_price, float $leverage ): float {
		$settings = self::get_settings();
		$mm       = (float) $settings['maintenance_margin_pct'] / 100;
		$ratio    = 1 / max( 1, $leverage );

		if ( 'sell' === $direction ) {
			return $entry_price * ( 1 + $ratio - $mm );
		}
		return $entry_price * ( 1 - $ratio + $mm );
	}

	/** کارمزد یک طرف معامله (باز یا بسته) بر اساس حجم پوزیشن */
	public static function fee( float $position_size_usd ): float {
		$settings = self::get_settings();
		return $position_size_usd * ( (float) $settings['fee_pct'] / 100 );
	}

	/** سود/زیان خام معامله (بدون کسر کارمزد) بر حسب دلار */
	public static function pnl( string $direction, float $entry_price, float $exit_price, float $qty ): float {
		return 'sell' === $direction ? $qty * ( $entry_price - $exit_price ) : $qty * ( $exit_price - $entry_price );
	}

	/**
	 * بررسی اینکه آیا یک قیمت لحظه‌ای (یا کندل High/Low) باعث برخورد به لیکویید/SL/TP
	 * شده یا نه. اولویت با بدترین حالت (لیکویید) است، چون در واقعیت زودتر اتفاق می‌افتد.
	 * ورودی high/low اختیاری‌اند (اگر ندهید همان یک قیمت لحظه‌ای هم برای high هم low
	 * در نظر گرفته می‌شود — یعنی بررسی لحظه‌ای ساده).
	 */
	public static function evaluate_trade( array $trade, float $price, ?float $high = null, ?float $low = null ): ?array {
		$direction = $trade['direction'];
		$high      = $high ?? $price;
		$low       = $low ?? $price;

		$liq = (float) ( $trade['liquidation_price'] ?? 0 );
		$sl  = (float) ( $trade['sl'] ?? 0 );
		$tp  = (float) ( $trade['tp'] ?? 0 );

		if ( 'sell' === $direction ) {
			if ( $liq > 0 && $high >= $liq ) {
				return array( 'reason' => 'liquidation', 'price' => $liq );
			}
			if ( $sl > 0 && $high >= $sl ) {
				return array( 'reason' => 'sl', 'price' => $sl );
			}
			if ( $tp > 0 && $low <= $tp ) {
				return array( 'reason' => 'tp', 'price' => $tp );
			}
		} else {
			if ( $liq > 0 && $low <= $liq ) {
				return array( 'reason' => 'liquidation', 'price' => $liq );
			}
			if ( $sl > 0 && $low <= $sl ) {
				return array( 'reason' => 'sl', 'price' => $sl );
			}
			if ( $tp > 0 && $high >= $tp ) {
				return array( 'reason' => 'tp', 'price' => $tp );
			}
		}
		return null;
	}

	/**
	 * شبیه‌سازی معامله روی داده تاریخی: از کندل‌های بعد از زمان ورود جلو می‌رود و
	 * اولین برخورد به SL/TP/لیکویید را پیدا می‌کند. اگر تا آخر داده هیچ‌کدام برخورد
	 * نکردند، معامله با آخرین قیمت موجود (به‌عنوان «پایان بازه تاریخی») بسته می‌شود.
	 * ورودی $series: آرایه‌ای از ['time'=>timestamp, 'high'=>, 'low'=>, 'close'=>]
	 * فقط کندل‌هایی با زمان بعد از $entry_time بررسی می‌شوند.
	 */
	public static function simulate_historical( array $trade, array $series, int $entry_time ): array {
		foreach ( $series as $candle ) {
			if ( $candle['time'] <= $entry_time ) {
				continue;
			}
			$hit = self::evaluate_trade( $trade, $candle['close'], $candle['high'], $candle['low'] );
			if ( $hit ) {
				return array(
					'reason' => $hit['reason'],
					'price'  => $hit['price'],
					'time'   => $candle['time'],
				);
			}
		}

		// هیچ برخوردی رخ نداد — با آخرین کندل موجود (پایان داده تاریخی) بسته می‌شود
		$last = end( $series );
		if ( $last ) {
			return array( 'reason' => 'historical_end', 'price' => (float) $last['close'], 'time' => (int) $last['time'] );
		}

		return array( 'reason' => 'historical_end', 'price' => (float) $trade['entry_price'], 'time' => $entry_time );
	}
}

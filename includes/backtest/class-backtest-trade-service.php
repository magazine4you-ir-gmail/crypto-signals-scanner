<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * منطق مشترک باز/بستن معامله — هم از AJAX (کلیک کاربر) و هم از کران (بررسی خودکار
 * قیمت زنده) استفاده می‌شود تا حساب‌وکتاب موجودی در یک‌جا و بدون تکرار انجام شود.
 */
class CSS_Backtest_Trade_Service {

	/**
	 * باز کردن معامله جدید. برای حالت تاریخی، بلافاصله شبیه‌سازی شده و با نتیجه بسته می‌شود.
	 * @return array{success:bool, message?:string, trade?:array, balance?:float}
	 */
	public static function open_trade( int $account_id, array $params ): array {
		$settings = CSS_Backtest_Engine::get_settings();

		$direction = 'sell' === $params['direction'] ? 'sell' : 'buy';
		$mode      = 'historical' === $params['mode'] ? 'historical' : 'live';

		if ( 'live' === $mode && 'yes' !== $settings['enable_live'] ) {
			return array( 'success' => false, 'message' => 'حالت پیپر تریدینگ زنده غیرفعال است.' );
		}
		if ( 'historical' === $mode && 'yes' !== $settings['enable_historical'] ) {
			return array( 'success' => false, 'message' => 'حالت شبیه‌سازی تاریخی غیرفعال است.' );
		}

		$leverage = max( 1, min( (float) $settings['max_leverage'], (float) $params['leverage'] ) );
		$margin   = (float) $params['margin_usd'];
		$rr_ratio = max( 0.5, min( 10, (float) $params['rr_ratio'] ) );

		if ( $margin <= 0 ) {
			return array( 'success' => false, 'message' => 'مقدار مارجین نامعتبر است.' );
		}

		$open_count = count( CSS_Backtest_Account::get_open_trades( $account_id ) );
		if ( $open_count >= (int) $settings['max_open_trades_per_acc'] ) {
			return array( 'success' => false, 'message' => 'به حداکثر تعداد معاملات باز هم‌زمان این اکانت رسیده‌اید.' );
		}

		$fetcher = new CSS_Data_Fetcher();

		if ( 'live' === $mode ) {
			$entry_price = $fetcher->get_current_price( $params['coin_id'] );
			if ( null === $entry_price ) {
				return array( 'success' => false, 'message' => 'دریافت قیمت لحظه‌ای ارز ناموفق بود، دوباره تلاش کنید.' );
			}
			$entry_time = time();
			$series     = $fetcher->get_ohlc_series( $params['coin_id'], 30 );
		} else {
			$historical_date = sanitize_text_field( $params['historical_date'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $historical_date ) ) {
				return array( 'success' => false, 'message' => 'تاریخ تاریخی نامعتبر است.' );
			}
			$requested_time = strtotime( $historical_date . ' 12:00:00' );
			if ( ! $requested_time || $requested_time > time() ) {
				return array( 'success' => false, 'message' => 'تاریخ انتخاب‌شده باید در گذشته باشد.' );
			}
			$days_back = min( 365, max( 2, (int) ceil( ( time() - $requested_time ) / DAY_IN_SECONDS ) + 3 ) );
			$series    = $fetcher->get_ohlc_series( $params['coin_id'], $days_back );
			if ( empty( $series ) ) {
				return array( 'success' => false, 'message' => 'دریافت داده تاریخی این ارز ناموفق بود.' );
			}

			// نزدیک‌ترین کندل به تاریخ درخواستی را به‌عنوان لحظه ورود انتخاب کن
			$entry_candle = $series[0];
			foreach ( $series as $candle ) {
				if ( $candle['time'] > $requested_time ) {
					break;
				}
				$entry_candle = $candle;
			}
			$entry_price = (float) $entry_candle['close'];
			$entry_time  = (int) $entry_candle['time'];
		}

		// ATR فقط از روی کندل‌های تا لحظه ورود محاسبه می‌شود (جلوگیری از نگاه به آینده)
		$series_up_to_entry = array_values( array_filter( $series, fn( $c ) => $c['time'] <= $entry_time ) );
		$atr_value          = null;
		if ( count( $series_up_to_entry ) > (int) $settings['atr_period'] ) {
			$highs  = array_column( $series_up_to_entry, 'high' );
			$lows   = array_column( $series_up_to_entry, 'low' );
			$closes = array_column( $series_up_to_entry, 'close' );
			$atr_series = CSS_MA_Helper::atr( $highs, $lows, $closes, (int) $settings['atr_period'] );
			$last_valid = CSS_MA_Helper::last_valid( $atr_series );
			$atr_value  = $last_valid ? (float) $last_valid['value'] : null;
		}

		$sl                = self::coerce_price( $params['sl'] ?? null, CSS_Backtest_Engine::auto_stop_loss( $direction, $entry_price, $atr_value ) );
		$tp                = self::coerce_price( $params['tp'] ?? null, CSS_Backtest_Engine::take_profit_from_rr( $direction, $entry_price, $sl, $rr_ratio ) );
		$liquidation_price = CSS_Backtest_Engine::liquidation_price( $direction, $entry_price, $leverage );

		$position_size = CSS_Backtest_Engine::position_size( $margin, $leverage );
		$qty           = CSS_Backtest_Engine::qty( $position_size, $entry_price );
		$open_fee      = CSS_Backtest_Engine::fee( $position_size );

		$balance = CSS_Backtest_Account::get_balance( $account_id );
		if ( $balance < ( $margin + $open_fee ) ) {
			return array( 'success' => false, 'message' => 'موجودی اکانت برای این مقدار مارجین و کارمزد کافی نیست.' );
		}

		$trade = array(
			'mode'              => $mode,
			'coin_id'           => sanitize_text_field( $params['coin_id'] ),
			'symbol'            => sanitize_text_field( $params['symbol'] ?? '' ),
			'direction'         => $direction,
			'entry_price'       => $entry_price,
			'sl'                => $sl,
			'tp'                => $tp,
			'liquidation_price' => $liquidation_price,
			'leverage'          => $leverage,
			'margin_usd'        => $margin,
			'position_size'     => $position_size,
			'qty'               => $qty,
			'fee_open'          => $open_fee,
			'rr_ratio'          => $rr_ratio,
			'status'            => 'open',
			'close_price'       => null,
			'close_reason'      => null,
			'fee_close'         => null,
			'pnl_usd'           => null,
			'opened_at'         => gmdate( 'Y-m-d H:i:s', $entry_time ),
			'closed_at'         => null,
		);

		CSS_Backtest_Account::adjust_balance( $account_id, -( $margin + $open_fee ) );
		$trade_id     = CSS_Backtest_Account::add_trade( $account_id, $trade );
		$trade['id'] = $trade_id;

		if ( 'historical' === $mode ) {
			$future_candles = array_values( array_filter( $series, fn( $c ) => $c['time'] > $entry_time ) );
			$result         = CSS_Backtest_Engine::simulate_historical( $trade, $future_candles, $entry_time );
			$closed         = self::finalize_close( $account_id, $trade, (float) $result['price'], $result['reason'], (int) $result['time'] );
			return array( 'success' => true, 'trade' => $closed['trade'], 'balance' => $closed['balance'] );
		}

		return array(
			'success' => true,
			'trade'   => $trade,
			'balance' => CSS_Backtest_Account::get_balance( $account_id ),
		);
	}

	/** اگر کاربر SL/TP دستی وارد کرده باشد همان استفاده شود، وگرنه مقدار خودکار */
	private static function coerce_price( $manual, float $auto ): float {
		if ( null !== $manual && '' !== $manual && is_numeric( $manual ) && (float) $manual > 0 ) {
			return (float) $manual;
		}
		return $auto;
	}

	/** بستن دستی یک معامله زنده با قیمت لحظه‌ای فعلی */
	public static function close_trade_manual( int $account_id, string $trade_id ): array {
		$trade = CSS_Backtest_Account::get_trade( $account_id, $trade_id );
		if ( ! $trade || 'open' !== ( $trade['status'] ?? '' ) ) {
			return array( 'success' => false, 'message' => 'معامله باز یافت نشد.' );
		}

		$fetcher = new CSS_Data_Fetcher();
		$price   = $fetcher->get_current_price( $trade['coin_id'] );
		if ( null === $price ) {
			return array( 'success' => false, 'message' => 'دریافت قیمت لحظه‌ای ناموفق بود، دوباره تلاش کنید.' );
		}

		// حتی در بستن دستی، اگر قیمت از SL/TP/لیکویید رد شده باشد همان دلیل واقعی ثبت شود
		$hit    = CSS_Backtest_Engine::evaluate_trade( $trade, $price );
		$reason = $hit['reason'] ?? 'manual';
		$price  = $hit['price'] ?? $price;

		$closed = self::finalize_close( $account_id, $trade, $price, $reason );
		return array( 'success' => true, 'trade' => $closed['trade'], 'balance' => $closed['balance'] );
	}

	/** بستن معامله به دلیل برخورد خودکار (از کران) */
	public static function close_trade_by_hit( int $account_id, array $trade, float $price, string $reason ): array {
		$closed = self::finalize_close( $account_id, $trade, $price, $reason );
		return $closed;
	}

	private static function finalize_close( int $account_id, array $trade, float $exit_price, string $reason, ?int $exit_time = null ): array {
		$qty        = (float) $trade['qty'];
		$margin     = (float) $trade['margin_usd'];
		$fee_close  = CSS_Backtest_Engine::fee( (float) $trade['position_size'] );
		$pnl_raw    = CSS_Backtest_Engine::pnl( $trade['direction'], (float) $trade['entry_price'], $exit_price, $qty );
		$net_pnl    = $pnl_raw - $fee_close;
		$return_amt = max( 0, $margin + $net_pnl ); // در لیکویید، حداکثر تا صفر می‌رسد؛ زیر صفر مارجین نمی‌رود

		$closed_at = $exit_time ? gmdate( 'Y-m-d H:i:s', $exit_time ) : current_time( 'mysql' );

		$fields = array(
			'status'       => 'liquidation' === $reason ? 'liquidated' : 'closed',
			'close_price'  => $exit_price,
			'close_reason' => $reason,
			'fee_close'    => $fee_close,
			'pnl_usd'      => round( $net_pnl, 4 ),
			'closed_at'    => $closed_at,
		);

		CSS_Backtest_Account::update_trade( $account_id, $trade['id'], $fields );
		$new_balance = CSS_Backtest_Account::adjust_balance( $account_id, $return_amt );

		return array( 'trade' => array_merge( $trade, $fields ), 'balance' => $new_balance );
	}
}

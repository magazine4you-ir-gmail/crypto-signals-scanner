<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * هر ۵ دقیقه یک‌بار همه معاملات «زنده» (mode=live) باز روی همه اکانت‌های همه
 * کاربران را با قیمت لحظه‌ای می‌سنجد؛ اگر به حد سود، حد ضرر یا قیمت لیکویید
 * برخورد کرده باشد، خودکار می‌بندد و موجودی اکانت را به‌روزرسانی می‌کند.
 */
class CSS_Backtest_Cron {

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'css_bt_check_open_trades', array( $this, 'check_open_trades' ) );
	}

	public function add_schedule( array $schedules ): array {
		$schedules['css_bt_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'هر ۵ دقیقه (بک‌تست Crypto Signal Scanner)',
		);
		return $schedules;
	}

	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( 'css_bt_check_open_trades' ) ) {
			wp_schedule_event( time() + 60, 'css_bt_five_minutes', 'css_bt_check_open_trades' );
		}
	}

	public function check_open_trades(): void {
		if ( 'yes' !== ( CSS_Backtest_Engine::get_settings()['module_enabled'] ?? 'no' ) ) {
			return; // ماژول بک‌تست خاموش است — حتی یک کال قیمت هم زده نشود
		}

		$open_trades = CSS_Backtest_Account::get_all_open_trades();
		if ( empty( $open_trades ) ) {
			return;
		}

		// فقط معاملات «زنده» را با کران می‌بندیم؛ معاملات تاریخی همان لحظه باز شدن حل می‌شوند
		$live_trades = array_filter( $open_trades, fn( $t ) => 'historical' !== ( $t['mode'] ?? 'live' ) );
		if ( empty( $live_trades ) ) {
			return;
		}

		// یک بار قیمت هر ارز را می‌گیریم تا برای چند معامله روی همان ارز درخواست تکراری نزنیم
		$coin_ids = array_values( array_unique( array_map( fn( $t ) => $t['coin_id'], $live_trades ) ) );
		$fetcher  = new CSS_Data_Fetcher();
		$prices   = array();
		foreach ( $coin_ids as $coin_id ) {
			$price = $fetcher->get_current_price( $coin_id );
			if ( null !== $price ) {
				$prices[ $coin_id ] = $price;
			}
			usleep( 300000 ); // فاصله کوچک بین درخواست‌ها برای رعایت سقف نرخ CoinGecko
		}

		foreach ( $live_trades as $trade ) {
			$price = $prices[ $trade['coin_id'] ] ?? null;
			if ( null === $price ) {
				continue;
			}

			$hit = CSS_Backtest_Engine::evaluate_trade( $trade, $price );
			if ( ! $hit ) {
				continue;
			}

			CSS_Backtest_Trade_Service::close_trade_by_hit( (int) $trade['account_id'], $trade, $hit['price'], $hit['reason'] );
		}
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * لایه دسترسی یکنواخت به داده بازار. Provider فعال از تنظیمات انتخاب می‌شود.
 */
class CSS_Data_Fetcher {

	private CSS_Data_Provider_Interface $provider;

	public function __construct() {
		$settings = get_option( 'css_settings', array() );
		$active   = sanitize_key( $settings['data_provider'] ?? 'coingecko' );

		if ( 'binance' === $active && class_exists( 'CSS_Binance_Provider' ) ) {
			$this->provider = new CSS_Binance_Provider();
		} else {
			$this->provider = new CSS_CoinGecko_Provider();
		}
	}

	public function get_active_provider(): string {
		return $this->provider instanceof CSS_Binance_Provider ? 'binance' : 'coingecko';
	}

	public function get_top_coins( int $limit = 100 ): array { return $this->provider->get_top_coins( $limit ); }
	public function get_coins_by_rank_range( int $start, int $end ): array { return $this->provider->get_coins_by_rank_range( $start, $end ); }
	public function get_price_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array { return $this->provider->get_price_history( $asset, $days, $timeframe ); }
	public function get_ohlc_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array { return $this->provider->get_ohlc_history( $asset, $days, $timeframe ); }
	public function get_ohlc_series( string $asset, int $days = 90 ): array { return $this->provider->get_ohlc_series( $asset, $days ); }
	public function get_current_price( string $asset ): ?float { return $this->provider->get_current_price( $asset ); }
	public function get_global_market_data(): ?array { return $this->provider->get_global_market_data(); }
	public function test_connection(): array { return $this->provider->test_connection(); }
}

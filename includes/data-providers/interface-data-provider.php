<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** قرارداد مشترک منابع داده بازار. */
interface CSS_Data_Provider_Interface {
	public function get_top_coins( int $limit = 100 ): array;
	public function get_coins_by_rank_range( int $start, int $end ): array;
	public function get_price_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array;
	public function get_ohlc_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array;
	public function get_ohlc_series( string $asset, int $days = 90 ): array;
	public function get_current_price( string $asset ): ?float;
	public function get_global_market_data(): ?array;
	public function test_connection(): array;
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Binance Spot provider through a Supabase Edge Function gateway.
 *
 * WordPress never connects to Binance directly. The gateway runs the Binance
 * requests from Supabase Edge and can cache public market data in Supabase.
 */
class CSS_Binance_Provider implements CSS_Data_Provider_Interface {

	private array $settings;

	public function __construct() {
		$this->settings = get_option( 'css_settings', array() );
	}

	private function get_gateway_url(): string {
		return untrailingslashit( trim( (string) ( $this->settings['binance_base_url'] ?? '' ) ) );
	}

	private function get_gateway_token(): string {
		return trim( (string) ( $this->settings['binance_worker_token'] ?? '' ) );
	}

	public function get_top_coins( int $limit = 100 ): array {
		return $this->get_coins_by_rank_range( 1, max( 1, $limit ) );
	}

	/**
	 * Binance market-cap rank ندارد؛ gateway جفت‌های فعال Spot/USDT را بر اساس
	 * quoteVolume رتبه‌بندی می‌کند و فقط بازه موردنیاز را برمی‌گرداند.
	 */
	public function get_coins_by_rank_range( int $start, int $end ): array {
		$start = max( 1, $start );
		$end   = max( $start, $end );
		$quote = strtoupper( trim( $this->settings['binance_quote_asset'] ?? 'USDT' ) );

		$response = $this->gateway_get( '/?action=universe&quote=' . rawurlencode( $quote ) . '&start=' . $start . '&end=' . $end );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		// Supabase universe endpoint returns `items` (not the legacy `coins` key).
		// Accept `coins` as a fallback so older gateway responses remain compatible.
		$coins = isset( $response['items'] ) && is_array( $response['items'] )
			? $response['items']
			: ( isset( $response['coins'] ) && is_array( $response['coins'] ) ? $response['coins'] : array() );

		if ( empty( $coins ) ) {
			return array();
		}

		return array_values( array_filter( $coins, static function ( $coin ) {
			if ( ! is_array( $coin ) || empty( $coin['id'] ) || empty( $coin['symbol'] ) ) {
				return false;
			}
			$price = isset( $coin['current_price'] ) ? (float) $coin['current_price'] : 0.0;
			return $price > 0;
		} ) );
	}

	private function interval_for_timeframe( string $timeframe ): string {
		return array(
			'hourly' => '1h',
			'daily'  => '1d',
			'weekly' => '1w',
		)[ $timeframe ] ?? '1d';
	}

	private function fetch_klines( string $symbol, int $days, string $timeframe ): array {
		$interval = $this->interval_for_timeframe( $timeframe );
		$minutes  = array( '1h' => 60, '1d' => 1440, '1w' => 10080 )[ $interval ];
		$limit    = min( 1000, max( 2, (int) ceil( ( $days * 1440 ) / $minutes ) + 5 ) );
		$start_ms = (int) ( ( time() - max( 1, $days ) * DAY_IN_SECONDS ) * 1000 );

		$query = http_build_query( array(
			'action'    => 'klines',
			'symbol'    => strtoupper( $symbol ),
			'interval'  => $interval,
			'limit'     => $limit,
			'startTime' => $start_ms,
		), '', '&', PHP_QUERY_RFC3986 );

		$response = $this->gateway_get( '/?' . $query );
		if ( is_wp_error( $response ) || empty( $response['candles'] ) || ! is_array( $response['candles'] ) ) {
			return array();
		}

		return array_values( array_filter( $response['candles'], static function ( $candle ) {
			if ( ! is_array( $candle ) ) {
				return false;
			}
			// Gateway v2 returns raw Binance arrays; the earlier gateway contract
			// returned named objects. Accept both so a gateway rollout cannot turn
			// valid prices into PHP undefined-index => 0 values.
			if ( isset( $candle['close'] ) ) {
				return isset( $candle['close'] ) && (float) $candle['close'] > 0;
			}
			return count( $candle ) >= 6 && isset( $candle[4] ) && (float) $candle[4] > 0;
		} ) );
	}

	public function get_price_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array {
		$klines = $this->fetch_klines( $asset, $days, $timeframe );
		$close = $volume = array();
		foreach ( $klines as $candle ) {
			if ( isset( $candle['close'] ) ) {
				$close[]  = (float) $candle['close'];
				$volume[] = isset( $candle['volume'] ) ? (float) $candle['volume'] : 0.0;
			} else {
				$close[]  = (float) $candle[4];
				$volume[] = isset( $candle[7] ) ? (float) $candle[7] : (float) ( $candle[5] ?? 0 );
			}
		}
		return array( 'close' => $close, 'volume' => $volume );
	}

	public function get_ohlc_history( string $asset, int $days = 30, string $timeframe = 'daily' ): array {
		$klines = $this->fetch_klines( $asset, $days, $timeframe );
		$high = $low = $close = array();
		foreach ( $klines as $candle ) {
			if ( isset( $candle['close'] ) ) {
				$high[]  = (float) ( $candle['high'] ?? 0 );
				$low[]   = (float) ( $candle['low'] ?? 0 );
				$close[] = (float) $candle['close'];
			} else {
				$high[]  = (float) $candle[2];
				$low[]   = (float) $candle[3];
				$close[] = (float) $candle[4];
			}
		}
		return array( 'high' => $high, 'low' => $low, 'close' => $close );
	}

	public function get_ohlc_series( string $asset, int $days = 90 ): array {
		$klines = $this->fetch_klines( $asset, $days, 'daily' );
		$series = array();
		foreach ( $klines as $candle ) {
			if ( isset( $candle['close'] ) ) {
				$series[] = array(
					'time'  => (int) floor( (int) ( $candle['openTime'] ?? 0 ) / 1000 ),
					'open'  => (float) ( $candle['open'] ?? 0 ),
					'high'  => (float) ( $candle['high'] ?? 0 ),
					'low'   => (float) ( $candle['low'] ?? 0 ),
					'close' => (float) $candle['close'],
				);
			} else {
				$series[] = array(
					'time'  => (int) floor( (int) $candle[0] / 1000 ),
					'open'  => (float) $candle[1],
					'high'  => (float) $candle[2],
					'low'   => (float) $candle[3],
					'close' => (float) $candle[4],
				);
			}
		}
		return $series;
	}

	public function get_current_price( string $asset ): ?float {
		$response = $this->gateway_get( '/?action=ticker&symbols=' . rawurlencode( strtoupper( $asset ) ) );
		if ( is_wp_error( $response ) || empty( $response['tickers'] ) || ! is_array( $response['tickers'] ) ) {
			return null;
		}
		$ticker = reset( $response['tickers'] );
		return is_array( $ticker ) && isset( $ticker['lastPrice'] ) ? (float) $ticker['lastPrice'] : null;
	}

	/** Binance معادل Global Market Cap/BTC Dominance را ارائه نمی‌کند. */
	public function get_global_market_data(): ?array {
		return null;
	}

	public function test_connection(): array {
		if ( '' === $this->get_gateway_url() ) {
			return array( 'success' => false, 'message' => 'آدرس Supabase Edge Function برای Binance تنظیم نشده است.' );
		}

		$response = $this->gateway_get( '/?action=status' );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		if ( isset( $response['binance']['reachable'] ) && $response['binance']['reachable'] ) {
			return array( 'success' => true, 'message' => 'Supabase Gateway و Binance در دسترس هستند.' );
		}

		return array( 'success' => false, 'message' => 'Supabase Gateway در دسترس است، اما Binance از سمت Edge قابل دسترسی نیست.' );
	}

	private function gateway_get( string $path ) {
		$base = $this->get_gateway_url();
		if ( '' === $base ) {
			return new WP_Error( 'css_binance_gateway_missing', 'آدرس Supabase Edge Function تنظیم نشده است.' );
		}

		$url = 0 === strpos( $path, 'http' ) ? $path : $base . '/' . ltrim( $path, '/' );
		$headers = array( 'Accept' => 'application/json' );
		$token = $this->get_gateway_token();
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array( 'timeout' => 30, 'headers' => $headers );
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			usleep( 500000 );
			$response = wp_remote_get( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			$this->log_error( 'Binance Gateway HTTP Error: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			$snippet = substr( wp_strip_all_tags( $body ), 0, 300 );
			$this->log_error( "Binance Gateway HTTP {$code} برای {$url} — پاسخ: {$snippet}" );
			return new WP_Error( 'css_binance_gateway_http_error', "Binance Gateway HTTP {$code}" );
		}

		$data = json_decode( $body, true );
		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error( 'css_binance_gateway_json_error', 'پاسخ Supabase Gateway JSON نامعتبر است.' );
		}
		if ( ! empty( $data['error'] ) ) {
			return new WP_Error( 'css_binance_gateway_error', (string) $data['error'] );
		}
		return $data;
	}

	private function log_error( string $message ): void {
		$log   = get_option( 'css_error_log', array() );
		$log[] = array( 'time' => current_time( 'mysql' ), 'message' => $message );
		update_option( 'css_error_log', array_slice( $log, -50 ), false );
	}
}

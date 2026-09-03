<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * دریافت دیتای بازار از CoinGecko (رایگان، بدون نیاز اجباری به کلید API).
 * در صورت پر کردن فیلد "کلید API" در تنظیمات، هدر x-cg-demo-api-key ارسال می‌شود
 * که سقف درخواست مجاز را بالاتر می‌برد.
 */
class CSS_CoinGecko_Provider implements CSS_Data_Provider_Interface {

	const BASE_URL = 'https://api.coingecko.com/api/v3';

	private array $settings;

	public function __construct() {
		$this->settings = get_option( 'css_settings', array() );
	}

	/**
	 * اگر آدرس پراکسی سفارشی در تنظیمات وارد شده باشد، از آن استفاده می‌شود
	 * (برای هاست‌هایی که دسترسی مستقیم به CoinGecko ندارند)
	 */
	private function get_base_url(): string {
		$custom = trim( $this->settings['api_base_url'] ?? '' );
		if ( ! empty( $custom ) ) {
			return untrailingslashit( $custom );
		}
		return self::BASE_URL;
	}

	/**
	 * لیست ارزهای برتر بر اساس مارکت کپ
	 * خروجی هر آیتم: id, symbol, name, market_cap_rank, current_price
	 */
	/**
	 * لیست ارزهای برتر تا هر تعدادی که بخواهید — چون کوین‌گکو حداکثر ۲۵۰ ارز در هر
	 * صفحه می‌دهد، برای مقادیر بالاتر خودکار چند صفحه پشت‌سرهم گرفته می‌شود (مثلاً برای
	 * ۵۰۰ ارز، ۲ صفحه‌ی ۲۵۰تایی). این فقط روی گرفتن «لیست» تأثیر دارد و هزینه کمی دارد؛
	 * بخش سنگین (دریافت تاریخچه قیمت هر ارز) طبق روال قبلی دسته‌به‌دسته انجام می‌شود.
	 */
	public function get_top_coins( int $limit = 100 ): array {
		$per_page     = 250; // سقف کوین‌گکو در هر صفحه
		$pages_needed = (int) ceil( $limit / $per_page );
		$coins        = array();

		for ( $page = 1; $page <= $pages_needed; $page++ ) {
			$remaining      = $limit - count( $coins );
			$this_page_size = min( $per_page, $remaining );
			if ( $this_page_size <= 0 ) {
				break;
			}

			$url = $this->get_base_url() . '/coins/markets?' . http_build_query( array(
				'vs_currency'              => 'usd',
				'order'                    => 'market_cap_desc',
				'per_page'                 => $this_page_size,
				'page'                     => $page,
				'sparkline'                => 'false',
				'price_change_percentage'  => '1h,24h,7d,30d',
			) );

			$response = $this->remote_get( $url );
			if ( is_wp_error( $response ) || ! is_array( $response ) ) {
				break; // هر چیزی که تا الان گرفته شده را برگردان، به‌جای شکست کامل
			}

			foreach ( $response as $item ) {
				$coins[] = array(
					'id'              => $item['id'] ?? '',
					'symbol'          => strtoupper( $item['symbol'] ?? '' ),
					'name'            => $item['name'] ?? '',
					'market_cap_rank' => $item['market_cap_rank'] ?? null,
					'current_price'   => $item['current_price'] ?? null,
					'market_data'     => self::extract_market_data( $item ),
				);
			}

			if ( count( $response ) < $this_page_size ) {
				break; // کوین‌گکو دیگر ارزی برای صفحه بعد ندارد
			}

			if ( $page < $pages_needed ) {
				usleep( 500000 ); // فاصله کوچک بین صفحات لیست (این فقط گرفتن لیست است، نه دیتای هر ارز)
			}
		}

		return $coins;
	}

	/**
	 * فیلدهای اضافه‌ای که کوین‌گکو در همین پاسخ /coins/markets برمی‌گرداند ولی قبلاً
	 * استفاده نمی‌شدند: حجم، مارکت‌کپ، تغییرات قیمت در بازه‌های مختلف، بیشینه/کمینه ۲۴
	 * ساعته، عرضه در گردش، و رکورد بیشینه/کمینه تاریخی (ATH/ATL). هیچ کال اضافه‌ای
	 * برای این فیلدها لازم نیست، چون همین الان در همین پاسخ حاضرند و دور ریخته می‌شدند.
	 */
	private static function extract_market_data( array $item ): array {
		return array(
			'image'                        => $item['image'] ?? '',
			'total_volume'                 => $item['total_volume'] ?? null,
			'market_cap'                   => $item['market_cap'] ?? null,
			'high_24h'                     => $item['high_24h'] ?? null,
			'low_24h'                      => $item['low_24h'] ?? null,
			'change_pct_1h'                => $item['price_change_percentage_1h_in_currency'] ?? null,
			'change_pct_24h'               => $item['price_change_percentage_24h_in_currency'] ?? ( $item['price_change_percentage_24h'] ?? null ),
			'change_pct_7d'                => $item['price_change_percentage_7d_in_currency'] ?? null,
			'change_pct_30d'               => $item['price_change_percentage_30d_in_currency'] ?? null,
			'circulating_supply'           => $item['circulating_supply'] ?? null,
			'total_supply'                 => $item['total_supply'] ?? null,
			'max_supply'                   => $item['max_supply'] ?? null,
			'ath'                          => $item['ath'] ?? null,
			'ath_change_percentage'        => $item['ath_change_percentage'] ?? null,
			'ath_date'                     => $item['ath_date'] ?? null,
			'atl'                          => $item['atl'] ?? null,
			'atl_change_percentage'        => $item['atl_change_percentage'] ?? null,
			'atl_date'                     => $item['atl_date'] ?? null,
		);
	}

	/**
	 * لیست ارزها فقط در یک بازه رتبه دلخواه (مثلاً رتبه ۳۰۱ تا ۴۰۰) — نه از اول.
	 * چون کوین‌گکو صفحه‌بندی ۲۵۰تایی دارد، خودکار صفحه(های) لازم را می‌گیرد و فقط
	 * ارزهایی که واقعاً در بازه خواسته‌شده هستند را نگه می‌دارد.
	 */
	public function get_coins_by_rank_range( int $start, int $end ): array {
		$start = max( 1, $start );
		$end   = max( $start, $end );

		$per_page   = 250;
		$first_page = (int) floor( ( $start - 1 ) / $per_page ) + 1;
		$last_page  = (int) ceil( $end / $per_page );

		$coins = array();

		for ( $page = $first_page; $page <= $last_page; $page++ ) {
			$url = $this->get_base_url() . '/coins/markets?' . http_build_query( array(
				'vs_currency'             => 'usd',
				'order'                   => 'market_cap_desc',
				'per_page'                => $per_page,
				'page'                    => $page,
				'sparkline'               => 'false',
				'price_change_percentage' => '1h,24h,7d,30d',
			) );

			$response = $this->remote_get( $url );
			if ( is_wp_error( $response ) || ! is_array( $response ) ) {
				break;
			}

			foreach ( $response as $item ) {
				$rank = $item['market_cap_rank'] ?? null;
				if ( null !== $rank && $rank >= $start && $rank <= $end ) {
					$coins[] = array(
						'id'              => $item['id'] ?? '',
						'symbol'          => strtoupper( $item['symbol'] ?? '' ),
						'name'            => $item['name'] ?? '',
						'market_cap_rank' => $rank,
						'current_price'   => $item['current_price'] ?? null,
						'market_data'     => self::extract_market_data( $item ),
					);
				}
			}

			if ( count( $response ) < $per_page ) {
				break; // کوین‌گکو دیگر ارزی برای صفحه بعد ندارد
			}

			if ( $page < $last_page ) {
				usleep( 500000 );
			}
		}

		return $coins;
	}

	/**
	 * تاریخچه قیمت یک ارز خاص (فقط قیمت‌های Close به ترتیب زمانی)
	 * @param string $timeframe hourly | daily | weekly
	 */
	public function get_price_history( string $coin_id, int $days = 30, string $timeframe = 'daily' ): array {
		if ( 'hourly' === $timeframe ) {
			$interval    = 'hourly';
			$fetch_days  = min( max( $days, 2 ), 90 ); // محدودیت CoinGecko برای دیتای ساعتی
		} elseif ( 'weekly' === $timeframe ) {
			$interval   = 'daily';
			$fetch_days = max( $days, 180 ); // برای کندل هفتگی به دیتای روزانه بیشتری نیاز داریم
		} else {
			$interval   = 'daily';
			$fetch_days = $days;
		}

		$url = $this->get_base_url() . "/coins/{$coin_id}/market_chart?" . http_build_query( array(
			'vs_currency' => 'usd',
			'days'        => $fetch_days,
			'interval'    => $interval,
		) );

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		if ( empty( $response['prices'] ) ) {
			$this->log_error( "prices خالی برای {$coin_id} — پاسخ: " . substr( wp_json_encode( $response ), 0, 200 ) );
			return array();
		}

		// هر آیتم prices/total_volumes یک زوج [timestamp, مقدار] است
		$closes  = array_map( fn( $p ) => (float) $p[1], $response['prices'] );
		$volumes = ! empty( $response['total_volumes'] ) ? array_map( fn( $v ) => (float) $v[1], $response['total_volumes'] ) : array();

		if ( 'weekly' === $timeframe ) {
			$closes  = $this->resample_weekly( $closes );
			$volumes = $this->resample_weekly_sum( $volumes );
		}

		return array( 'close' => $closes, 'volume' => $volumes );
	}

	/** برای هفتگی: مجموع حجم معاملات هر ۷ روز (نه آخرین مقدار، چون حجم تجمعی است نه لحظه‌ای) */
	private function resample_weekly_sum( array $daily_values ): array {
		$weekly = array();
		$chunk  = array();
		foreach ( $daily_values as $value ) {
			$chunk[] = $value;
			if ( 7 === count( $chunk ) ) {
				$weekly[] = array_sum( $chunk );
				$chunk    = array();
			}
		}
		return $weekly;
	}

	/** هر ۷ نقطه روزانه را به یک نقطه هفتگی (قیمت پایانی هفته) تبدیل می‌کند */
	private function resample_weekly( array $daily_values ): array {
		$weekly = array();
		$chunk  = array();
		foreach ( $daily_values as $value ) {
			$chunk[] = $value;
			if ( 7 === count( $chunk ) ) {
				$weekly[] = end( $chunk );
				$chunk    = array();
			}
		}
		return $weekly;
	}

	/**
	 * تاریخچه کندل کامل (High/Low/Close) - برای اندیکاتورهایی که فقط با قیمت Close کافی نیستند
	 * توجه: CoinGecko فقط مقادیر مشخصی برای days می‌پذیرد؛ نزدیک‌ترین مقدار مجاز انتخاب می‌شود
	 */
	public function get_ohlc_history( string $coin_id, int $days = 30, string $timeframe = 'daily' ): array {
		$allowed = array( 1, 7, 14, 30, 90, 180, 365 );

		if ( 'weekly' === $timeframe ) {
			$days = max( $days, 180 );
		}

		$closest = $allowed[0];
		foreach ( $allowed as $d ) {
			if ( $days >= $d ) {
				$closest = $d;
			}
		}

		$url = $this->get_base_url() . "/coins/{$coin_id}/ohlc?" . http_build_query( array(
			'vs_currency' => 'usd',
			'days'        => $closest,
		) );

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) || empty( $response ) || ! is_array( $response ) ) {
			return array();
		}

		$high  = array();
		$low   = array();
		$close = array();

		foreach ( $response as $candle ) {
			if ( count( $candle ) < 5 ) {
				continue;
			}
			$high[]  = (float) $candle[2];
			$low[]   = (float) $candle[3];
			$close[] = (float) $candle[4];
		}

		if ( 'weekly' === $timeframe ) {
			$high  = $this->resample_weekly_extreme( $high, 'max' );
			$low   = $this->resample_weekly_extreme( $low, 'min' );
			$close = $this->resample_weekly( $close );
		}

		return array( 'high' => $high, 'low' => $low, 'close' => $close );
	}

	/** برای هفتگی: بالاترین (یا پایین‌ترین) مقدار هر ۷ کندل را نگه می‌دارد */
	private function resample_weekly_extreme( array $daily_values, string $mode ): array {
		$weekly = array();
		$chunk  = array();
		foreach ( $daily_values as $value ) {
			$chunk[] = $value;
			if ( 7 === count( $chunk ) ) {
				$weekly[] = 'max' === $mode ? max( $chunk ) : min( $chunk );
				$chunk    = array();
			}
		}
		return $weekly;
	}

	/**
	 * سری کامل کندل با زمان (Timestamp/Open/High/Low/Close) — بر خلاف get_ohlc_history
	 * که فقط آرایه‌های جدا و بدون زمان می‌دهد، این متد برای ماژول بک‌تست لازم است:
	 * هم برای محاسبه ATR لحظه‌ای و هم برای بازپخش (شبیه‌سازی) روی داده تاریخی.
	 * خروجی: آرایه‌ای مرتب از قدیم به جدید از ['time'=>unix_ts,'open','high','low','close']
	 */
	public function get_ohlc_series( string $coin_id, int $days = 90 ): array {
		$allowed = array( 1, 7, 14, 30, 90, 180, 365 );
		$closest = $allowed[0];
		foreach ( $allowed as $d ) {
			if ( $days >= $d ) {
				$closest = $d;
			}
		}

		$url = $this->get_base_url() . "/coins/{$coin_id}/ohlc?" . http_build_query( array(
			'vs_currency' => 'usd',
			'days'        => $closest,
		) );

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) || empty( $response ) || ! is_array( $response ) ) {
			return array();
		}

		$series = array();
		foreach ( $response as $candle ) {
			if ( count( $candle ) < 5 ) {
				continue;
			}
			$series[] = array(
				'time'  => (int) round( $candle[0] / 1000 ), // کوین‌گکو میلی‌ثانیه می‌دهد
				'open'  => (float) $candle[1],
				'high'  => (float) $candle[2],
				'low'   => (float) $candle[3],
				'close' => (float) $candle[4],
			);
		}

		usort( $series, fn( $a, $b ) => $a['time'] <=> $b['time'] );
		return $series;
	}

	/** قیمت فعلی یک ارز - برای سنجش دقت سیگنال‌های قبلی (سبک‌تر از تاریخچه کامل) */
	public function get_current_price( string $coin_id ): ?float {
		$url = $this->get_base_url() . '/simple/price?' . http_build_query( array(
			'ids'           => $coin_id,
			'vs_currencies' => 'usd',
		) );

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) || empty( $response[ $coin_id ]['usd'] ) ) {
			return null;
		}
		return (float) $response[ $coin_id ]['usd'];
	}

	/** دیتای کلی بازار: مارکت کپ کل (Total)، درصد تغییر ۲۴ ساعته، و دامیننس بیت‌کوین — با یک درخواست */
	public function get_global_market_data(): ?array {
		$url = $this->get_base_url() . '/global';

		$response = $this->remote_get( $url );
		if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
			return null;
		}

		$data = $response['data'];
		return array(
			'total_market_cap' => (float) ( $data['total_market_cap']['usd'] ?? 0 ),
			'change_pct_24h'   => (float) ( $data['market_cap_change_percentage_24h_usd'] ?? 0 ),
			'btc_dominance'    => (float) ( $data['market_cap_percentage']['btc'] ?? 0 ),
		);
	}

	/**
	 * درخواست HTTP با مدیریت خطا و کلید API اختیاری
	 */
	private function remote_get( string $url ) {
		$headers = array();
		if ( ! empty( $this->settings['coingecko_api_key'] ) ) {
			$headers['x-cg-demo-api-key'] = $this->settings['coingecko_api_key'];
			// ارسال هم‌زمان به‌صورت query param برای اطمینان (بعضی پراکسی‌ها هدر سفارشی را حذف می‌کنند)
			$url = add_query_arg( 'x_cg_demo_api_key', $this->settings['coingecko_api_key'], $url );
		}

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => $headers,
		) );

		// شبکه ایران/هاست‌های داخلی گاهی یک درخواست را بی‌دلیل قطع می‌کنند؛ یک بار دیگر امتحان کن
		if ( is_wp_error( $response ) ) {
			usleep( 500000 );
			$response = wp_remote_get( $url, array(
				'timeout' => 20,
				'headers' => $headers,
			) );
		}

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'HTTP Error: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			// به سقف نرخ خورده‌ایم؛ برای ۹۰ ثانیه هیچ درخواست دیگری نفرست
			set_transient( 'css_rate_limited', 1, 90 );
		}

		if ( $code < 200 || $code >= 300 ) {
			$body_snippet = substr( wp_remote_retrieve_body( $response ), 0, 200 );
			$this->log_error( "HTTP {$code} برای {$url} — پاسخ: {$body_snippet}" );
			return new WP_Error( 'css_http_error', "HTTP {$code}" );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( null === $body ) {
			return new WP_Error( 'css_json_error', 'پاسخ نامعتبر JSON' );
		}

		return $body;
	}

	public function test_connection(): array {
		$response = $this->remote_get( $this->get_base_url() . '/ping' );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		return array( 'success' => true, 'message' => 'اتصال به CoinGecko برقرار است.' );
	}

	private function log_error( string $message ): void {
		$log   = get_option( 'css_error_log', array() );
		$log[] = array( 'time' => current_time( 'mysql' ), 'message' => $message );
		// فقط ۵۰ خطای آخر نگه‌داشته می‌شود
		$log = array_slice( $log, -50 );
		update_option( 'css_error_log', $log, false );
	}
}

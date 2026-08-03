<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * پست‌تایپ «ارزهای اسکن‌شده» (css_coin)
 * برای هر ارز یک پست ساخته می‌شود. داخل هر پست یک «تقویم» (شبیه تقویم رزرو/بوکینگ)
 * نگه‌داری می‌شود: برای هر روزی که اندیکاتور سیگنال خرید/فروش داده، یک رکورد کامل
 * (سیگنال، تایم‌فریم، قیمت لحظه سیگنال، قیمت لحظه بررسی، نتیجه دقت، اندیکاتور صادرکننده،
 * جزئیات همه اندیکاتورها) دقیقاً روی همان روز ثبت می‌شود. بدون محدودیت تعداد رکورد.
 *
 * ساختار ذخیره‌سازی (متا: _css_calendar) — یک JSON به شکل:
 * {
 *   "2026-07-24": [
 *      {
 *        "signal": "buy", "timeframe": "daily", "outcome": "pending",
 *        "price_at_signal": 1.234, "price_at_check": null,
 *        "source_indicators": "RSI، MACD", "indicators_detail": {...},
 *        "created_at": "2026-07-24 11:05:00", "evaluated_at": null
 *      }
 *   ]
 * }
 */
class CSS_Coin_CPT {

	const POST_TYPE = 'css_coin';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_action( 'admin_notices', array( $this, 'render_migrate_notice' ) );
		add_action( 'wp_ajax_css_migrate_batch', array( $this, 'ajax_migrate_batch' ) );
	}

	public function register_cpt(): void {
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name'          => 'ارزهای اسکن‌شده',
				'singular_name' => 'ارز',
				'menu_name'     => 'ارزهای اسکن‌شده',
				'edit_item'     => 'مشاهده ارز',
				'search_items'  => 'جستجوی ارز',
				'not_found'     => 'هنوز هیچ ارزی اسکن نشده',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'crypto-signal-scanner',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'menu_icon'       => 'dashicons-chart-area',
		) );
	}

	// ======================================================================
	// متدهای استاتیک — برای فراخوانی از CSS_Cron هنگام اسکن/سنجش دقت و از انتقال دستی
	// ======================================================================

	public static function get_or_create_post( string $coin_id, string $symbol, string $name ): ?int {
		$existing = self::find_post_id( $coin_id );
		if ( $existing ) {
			return $existing;
		}

		$post_id = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => $symbol . ' — ' . $name,
			'post_name'   => sanitize_title( $coin_id ),
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $post_id ) ) {
			return null;
		}

		update_post_meta( $post_id, '_css_coin_id', $coin_id );
		return $post_id;
	}

	public static function find_post_id( string $coin_id ): ?int {
		$post = get_page_by_path( sanitize_title( $coin_id ), OBJECT, self::POST_TYPE );
		return $post ? $post->ID : null;
	}

	/** به‌روزرسانی مشخصات اصلی ارز — هر بار اسکن می‌شود صدا زده شود (صرف‌نظر از سیگنال) */
	/**
	 * پاکسازی و یکپارچه‌سازی: ردیف‌های جدول اصلی (wp_css_signals) که به یک اسکن جدید تعلق
	 * ندارند (یعنی از ارزهایی که دیگر جزو لیست فعلی نیستند و از سری‌های اولیه افزونه باقی
	 * مانده‌اند) حذف می‌کند، و برای هر ارز باقی‌مانده که هنوز پست‌تایپ ندارد یکی می‌سازد.
	 * توجه: این کار فقط روی جدول «حافظه کاری» تأثیر دارد؛ تاریخچه و تقویم پست‌تایپ‌ها
	 * (که آرشیو دائمی هستند) دست‌نخورده باقی می‌مانند.
	 */
	public static function cleanup_and_consolidate(): array {
		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_SIGNALS;

		$deleted      = 0;
		$consolidated = 0;

		$timeframes = $wpdb->get_col( "SELECT DISTINCT timeframe FROM {$table}" );
		foreach ( $timeframes as $tf ) {
			$max_updated = $wpdb->get_var( $wpdb->prepare(
				"SELECT MAX(updated_at) FROM {$table} WHERE timeframe = %s", $tf
			) );
			if ( ! $max_updated ) {
				continue;
			}

			// هر ردیفی که در آخرین دور اسکن این تایم‌فریم به‌روزرسانی نشده، یتیم/قدیمی حساب می‌شود
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $max_updated ) - 6 * HOUR_IN_SECONDS );

			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE timeframe = %s AND updated_at < %s", $tf, $cutoff
			) );

			if ( $count > 0 ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$table} WHERE timeframe = %s AND updated_at < %s", $tf, $cutoff
				) );
				$deleted += $count;
			}
		}

		// برای هر ارز باقی‌مانده (تازه)، مطمئن شو پست‌تایپ متناظرش وجود دارد
		$rows = $wpdb->get_results(
			"SELECT DISTINCT coin_id, symbol, name, market_cap_rank, price FROM {$table}", ARRAY_A
		);
		foreach ( $rows as $row ) {
			if ( ! self::find_post_id( $row['coin_id'] ) ) {
				self::sync_coin( $row['coin_id'], $row['symbol'], $row['name'], $row['market_cap_rank'], (float) $row['price'] );
				$consolidated++;
			}
		}

		return array( 'deleted' => $deleted, 'consolidated' => $consolidated );
	}

	public static function sync_coin( string $coin_id, string $symbol, string $name, $rank, float $price, array $market_data = array(), ?float $volume_trend_pct = null ): void {
		$post_id = self::get_or_create_post( $coin_id, $symbol, $name );
		if ( ! $post_id ) {
			return;
		}
		update_post_meta( $post_id, '_css_symbol', $symbol );
		update_post_meta( $post_id, '_css_name', $name );
		update_post_meta( $post_id, '_css_rank', $rank );
		update_post_meta( $post_id, '_css_price', $price );
		update_post_meta( $post_id, '_css_updated_at', current_time( 'mysql' ) );

		// داده‌های اضافه‌ای که کوین‌گکو در همان پاسخ لیست ارزها می‌دهد (بدون کال اضافه)
		foreach ( $market_data as $key => $value ) {
			if ( null !== $value ) {
				update_post_meta( $post_id, '_css_md_' . sanitize_key( $key ), $value );
			}
		}

		// درصد استاندارد قدرت روند بر پایه حجم (RVOL نسبت به میانگین ۲۰ دوره‌ای)
		if ( null !== $volume_trend_pct ) {
			update_post_meta( $post_id, '_css_volume_trend_pct', $volume_trend_pct );
		}
	}

	/** ثبت یک سیگنال تازه روی «روز خودش» — مثل مهر ماشین چاپ، همراه با تمام جزئیات */
	public static function record_signal( string $coin_id, string $symbol, string $name, array $analysis, string $timeframe, float $price ): void {
		$source = array();
		foreach ( $analysis['details'] as $ind_id => $val ) {
			if ( $val === $analysis['signal'] ) {
				$indicator = CSS_Indicator_Registry::get( $ind_id );
				$source[]  = $indicator ? $indicator->get_label() : $ind_id;
			}
		}

		self::record_history_entry( $coin_id, $symbol, $name, array(
			'signal'            => $analysis['signal'],
			'timeframe'         => $timeframe,
			'outcome'           => 'pending',
			'price_at_signal'   => $price,
			'price_at_check'    => null,
			'source_indicators' => implode( '، ', $source ),
			'indicators_detail' => $analysis['details'],
			'indicator_metrics' => $analysis['metrics'] ?? array(),
			'created_at'        => current_time( 'mysql' ),
			'evaluated_at'      => null,
		) );
	}

	/** ثبت نتیجه دقت‌سنجی روی همان روزی که سیگنال اصلی صادر شده بود */
	public static function record_outcome( string $coin_id, string $date, string $timeframe, string $signal, string $outcome, ?float $price_at_check = null ): void {
		$post_id = self::find_post_id( $coin_id );
		if ( ! $post_id ) {
			return;
		}

		$calendar = self::get_calendar( $post_id );
		if ( empty( $calendar[ $date ] ) ) {
			return;
		}

		foreach ( $calendar[ $date ] as &$entry ) {
			if ( ( $entry['timeframe'] ?? '' ) === $timeframe
				&& ( $entry['signal'] ?? '' ) === $signal
				&& 'pending' === ( $entry['outcome'] ?? 'pending' ) ) {
				$entry['outcome']        = $outcome;
				$entry['price_at_check'] = $price_at_check;
				$entry['evaluated_at']   = current_time( 'mysql' );
				break;
			}
		}
		unset( $entry );

		self::save_calendar( $post_id, $calendar );
	}

	/**
	 * افزودن یک رکورد کامل به تقویم (روی تاریخ خودِ entry['created_at']، نه امروز)
	 * از سنجش دقت مستقیم و هم از انتقال تاریخچه قدیمی استفاده می‌شود؛ از رکورد تکراری جلوگیری می‌کند.
	 */
	public static function record_history_entry( string $coin_id, string $symbol, string $name, array $entry ): void {
		$post_id = self::get_or_create_post( $coin_id, $symbol, $name );
		if ( ! $post_id ) {
			return;
		}

		$date = substr( $entry['created_at'], 0, 10 );
		if ( ! $date ) {
			return;
		}

		$calendar = self::get_calendar( $post_id );
		if ( ! isset( $calendar[ $date ] ) ) {
			$calendar[ $date ] = array();
		}

		foreach ( $calendar[ $date ] as $existing ) {
			if ( ( $existing['created_at'] ?? '' ) === $entry['created_at']
				&& ( $existing['timeframe'] ?? '' ) === $entry['timeframe']
				&& ( $existing['signal'] ?? '' ) === $entry['signal'] ) {
				return; // قبلاً ثبت شده — از تکرار جلوگیری کن
			}
		}

		$calendar[ $date ][] = $entry;
		self::save_calendar( $post_id, $calendar );
	}

	private static function get_calendar( int $post_id ): array {
		$raw     = get_post_meta( $post_id, '_css_calendar', true );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function save_calendar( int $post_id, array $calendar ): void {
		ksort( $calendar ); // مرتب بر اساس تاریخ؛ بدون حذف هیچ رکوردی (بدون محدودیت تعداد)
		update_post_meta( $post_id, '_css_calendar', wp_json_encode( $calendar, JSON_UNESCAPED_UNICODE ) );
	}

	// ======================================================================
	// انتقال دستی تمام تاریخچه موجود (جدول wp_css_signal_history) به پست‌تایپ
	// ======================================================================

	/** پردازش یک دسته از جدول تاریخچه و انتقالشان با تاریخ اصلی خودشان */
	public static function migrate_batch( int $offset, int $batch_size = 50 ): array {
		global $wpdb;
		$history_table = $wpdb->prefix . CSS_TABLE_HISTORY;
		$signals_table = $wpdb->prefix . CSS_TABLE_SIGNALS;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$history_table}" );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$history_table} ORDER BY id ASC LIMIT %d OFFSET %d", $batch_size, $offset
		), ARRAY_A );

		if ( ! empty( $rows ) ) {
			$coin_ids     = array_values( array_unique( array_column( $rows, 'coin_id' ) ) );
			$coin_info    = array();
			if ( ! empty( $coin_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $coin_ids ), '%s' ) );
				$info_rows    = $wpdb->get_results( $wpdb->prepare(
					"SELECT coin_id, name, market_cap_rank, price FROM {$signals_table} WHERE coin_id IN ({$placeholders})",
					...$coin_ids
				), ARRAY_A );
				foreach ( $info_rows as $ir ) {
					$coin_info[ $ir['coin_id'] ] = $ir;
				}
			}

			foreach ( $rows as $row ) {
				$info = $coin_info[ $row['coin_id'] ] ?? array();
				$name = $info['name'] ?? $row['symbol'];

				self::record_history_entry( $row['coin_id'], $row['symbol'], $name, array(
					'signal'            => $row['trade_signal'],
					'timeframe'         => $row['timeframe'] ?? 'daily',
					'outcome'           => $row['outcome'],
					'price_at_signal'   => (float) $row['price_at_signal'],
					'price_at_check'    => null !== $row['price_at_check'] ? (float) $row['price_at_check'] : null,
					'source_indicators' => $row['source_indicators'] ?? '',
					'indicators_detail' => json_decode( $row['indicators_detail'], true ) ?: array(),
					'created_at'        => $row['created_at'],
					'evaluated_at'      => $row['evaluated_at'],
				) );

				if ( ! empty( $info ) ) {
					self::sync_coin( $row['coin_id'], $row['symbol'], $name, $info['market_cap_rank'], (float) $info['price'] );
				}

				// همین ردیف تاریخچه را به تقویم هر اندیکاتور صادرکننده هم منتقل کن
				if ( class_exists( 'CSS_Indicator_CPT' ) ) {
					CSS_Indicator_CPT::migrate_entry_for_row( $row, $name );
				}
			}
		}

		$processed = min( $offset + count( $rows ), $total );
		return array(
			'processed' => $processed,
			'total'     => $total,
			'done'      => $processed >= $total,
		);
	}

	public function ajax_migrate_batch(): void {
		check_ajax_referer( 'css_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$result = self::migrate_batch( $offset, 50 );
		wp_send_json_success( $result );
	}

	/** نمایش دکمه انتقال بالای لیست پست‌های این پست‌تایپ */
	public function render_migrate_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( self::POST_TYPE, 'css_indicator' ), true ) || 'edit' !== $screen->base ) {
			return;
		}
		?>
		<div class="notice notice-info" style="padding:14px;">
			<p><strong>انتقال تاریخچه قدیمی به پست‌تایپ‌ها</strong></p>
			<p>اگر سیگنال‌هایی از قبل (قبل از فعال شدن این پست‌تایپ‌ها، یا هر زمانی که این دکمه زده نشده) در جدول تاریخچه ثبت شده، با این دکمه همه‌شان دقیقاً روی تاریخ واقعی خودشان — هم در پست‌تایپ «ارزهای اسکن‌شده» و هم در پست‌تایپ «اندیکاتورها» — منتقل می‌شوند. اجرای چندباره مشکلی ندارد (رکورد تکراری ساخته نمی‌شود).</p>
			<button id="css-migrate-now" class="button button-primary">انتقال همه تاریخچه</button>
			<span id="css-migrate-progress" style="margin-right:10px;font-size:13px;"></span>
		</div>
		<script>
		(function() {
			var btn = document.getElementById('css-migrate-now');
			var progress = document.getElementById('css-migrate-progress');
			if (!btn) return;

			function step(offset) {
				var fd = new FormData();
				fd.append('action', 'css_migrate_batch');
				fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'css_admin_nonce' ) ); ?>');
				fd.append('offset', offset);

				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json.success) {
							progress.textContent = 'خطا در انتقال.';
							btn.disabled = false;
							return;
						}
						var d = json.data;
						progress.textContent = d.processed + ' از ' + d.total + ' رکورد منتقل شد...';
						if (d.done) {
							progress.textContent = 'انتقال کامل شد (' + d.total + ' رکورد). در حال بارگذاری...';
							setTimeout(function () { location.reload(); }, 1200);
						} else {
							setTimeout(function () { step(d.processed); }, 300);
						}
					})
					.catch(function () {
						progress.textContent = 'خطای شبکه در انتقال.';
						btn.disabled = false;
					});
			}

			btn.addEventListener('click', function () {
				btn.disabled = true;
				progress.textContent = 'در حال شروع انتقال...';
				step(0);
			});
		})();
		</script>
		<?php
	}

	// ======================================================================
	// نمایش در پیشخوان (متاباکس‌های صفحه هر ارز)
	// ======================================================================

	public function add_meta_boxes(): void {
		add_meta_box( 'css_coin_calendar', 'تقویم سیگنال‌ها (مثل تقویم رزرو) — روی هر روز کلیک کنید', array( $this, 'render_calendar_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'css_coin_stats', 'خلاصه عملکرد و نمودار', array( $this, 'render_stats_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'css_coin_details', 'مشخصات ارز', array( $this, 'render_details_box' ), self::POST_TYPE, 'side', 'default' );
	}

	public function render_details_box( $post ): void {
		echo self::render_details_html( $post->ID ); // phpcs:ignore
	}

	public static function render_details_html( int $post_id ): string {
		$symbol  = get_post_meta( $post_id, '_css_symbol', true );
		$coin_id = get_post_meta( $post_id, '_css_coin_id', true );
		$rank    = get_post_meta( $post_id, '_css_rank', true );
		$price   = get_post_meta( $post_id, '_css_price', true );
		$updated = get_post_meta( $post_id, '_css_updated_at', true );
		ob_start();
		?>
		<p><strong>نماد:</strong> <?php echo esc_html( $symbol ); ?></p>
		<p><strong>شناسه CoinGecko:</strong> <?php echo esc_html( $coin_id ); ?></p>
		<p><strong>رتبه مارکت کپ:</strong> <?php echo esc_html( $rank ); ?></p>
		<p><strong>آخرین قیمت:</strong> <?php echo esc_html( $price ); ?> $</p>
		<p><strong>آخرین بروزرسانی:</strong> <?php echo esc_html( $updated ); ?></p>
		<?php
		return ob_get_clean();
	}

	/**
	 * داده‌های بازار غنی (حجم، تغییرات قیمت، ATH/ATL، عرضه) — این داده‌ها از همان
	 * پاسخ لیست ارزها می‌آیند و قبلاً استفاده نمی‌شدند. عمداً فقط برای نمایش در پاپ‌آپ
	 * فرانت‌اند اضافه شده، نه در پیشخوان.
	 */
	public static function render_market_data_html( int $post_id ): string {
		$md = array();
		foreach ( array( 'image', 'total_volume', 'market_cap', 'high_24h', 'low_24h', 'change_pct_1h', 'change_pct_24h', 'change_pct_7d', 'change_pct_30d', 'circulating_supply', 'total_supply', 'max_supply', 'ath', 'ath_change_percentage', 'ath_date', 'atl', 'atl_change_percentage', 'atl_date' ) as $key ) {
			$val = get_post_meta( $post_id, '_css_md_' . $key, true );
			if ( '' !== $val ) {
				$md[ $key ] = $val;
			}
		}
		$volume_trend = get_post_meta( $post_id, '_css_volume_trend_pct', true );

		if ( empty( $md ) && '' === $volume_trend ) {
			return '';
		}

		$fmt_num = function ( $n ) {
			if ( null === $n || '' === $n ) return '—';
			$n = (float) $n;
			if ( abs( $n ) >= 1e9 ) return number_format( $n / 1e9, 2 ) . 'B';
			if ( abs( $n ) >= 1e6 ) return number_format( $n / 1e6, 2 ) . 'M';
			if ( abs( $n ) >= 1e3 ) return number_format( $n / 1e3, 2 ) . 'K';
			return number_format( $n, 4 );
		};
		$fmt_pct = function ( $n ) {
			if ( null === $n || '' === $n ) return '—';
			$n = (float) $n;
			return ( $n >= 0 ? '+' : '' ) . number_format( $n, 2 ) . '%';
		};
		$pct_class = function ( $n ) {
			if ( null === $n || '' === $n ) return '';
			return (float) $n >= 0 ? 'css-md-pos' : 'css-md-neg';
		};

		ob_start();
		?>
		<div class="css-md-grid">
			<?php if ( ! empty( $md['image'] ) ) : ?>
				<div class="css-md-icon"><img src="<?php echo esc_url( $md['image'] ); ?>" alt="" width="40" height="40"></div>
			<?php endif; ?>

			<?php if ( '' !== $volume_trend ) : ?>
				<div class="css-md-item css-md-highlight">
					<span class="css-md-label">قدرت روند (حجم نسبت به میانگین ۲۰ دوره‌ای)</span>
					<span class="css-md-value"><?php echo esc_html( number_format( (float) $volume_trend, 1 ) ); ?>٪</span>
				</div>
			<?php endif; ?>

			<?php foreach ( array( 'change_pct_1h' => 'تغییر ۱ ساعته', 'change_pct_24h' => 'تغییر ۲۴ ساعته', 'change_pct_7d' => 'تغییر ۷ روزه', 'change_pct_30d' => 'تغییر ۳۰ روزه' ) as $key => $label ) :
				if ( ! isset( $md[ $key ] ) ) continue; ?>
				<div class="css-md-item">
					<span class="css-md-label"><?php echo esc_html( $label ); ?></span>
					<span class="css-md-value <?php echo esc_attr( $pct_class( $md[ $key ] ) ); ?>"><?php echo esc_html( $fmt_pct( $md[ $key ] ) ); ?></span>
				</div>
			<?php endforeach; ?>

			<?php if ( isset( $md['total_volume'] ) ) : ?>
				<div class="css-md-item"><span class="css-md-label">حجم معاملات ۲۴ ساعته</span><span class="css-md-value">$<?php echo esc_html( $fmt_num( $md['total_volume'] ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( isset( $md['market_cap'] ) ) : ?>
				<div class="css-md-item"><span class="css-md-label">مارکت کپ</span><span class="css-md-value">$<?php echo esc_html( $fmt_num( $md['market_cap'] ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( isset( $md['high_24h'] ) || isset( $md['low_24h'] ) ) : ?>
				<div class="css-md-item"><span class="css-md-label">بازه ۲۴ ساعته</span><span class="css-md-value"><?php echo esc_html( $fmt_num( $md['low_24h'] ?? null ) . ' – ' . $fmt_num( $md['high_24h'] ?? null ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( isset( $md['circulating_supply'] ) ) : ?>
				<div class="css-md-item"><span class="css-md-label">عرضه در گردش</span><span class="css-md-value"><?php echo esc_html( $fmt_num( $md['circulating_supply'] ) ); ?><?php echo isset( $md['max_supply'] ) ? ' / ' . esc_html( $fmt_num( $md['max_supply'] ) ) : ''; ?></span></div>
			<?php endif; ?>
			<?php if ( isset( $md['ath'] ) ) : ?>
				<div class="css-md-item">
					<span class="css-md-label">بیشینه تاریخی (ATH)</span>
					<span class="css-md-value">$<?php echo esc_html( $fmt_num( $md['ath'] ) ); ?>
						<?php if ( isset( $md['ath_change_percentage'] ) ) : ?><span class="<?php echo esc_attr( $pct_class( $md['ath_change_percentage'] ) ); ?>">(<?php echo esc_html( $fmt_pct( $md['ath_change_percentage'] ) ); ?>)</span><?php endif; ?>
					</span>
				</div>
			<?php endif; ?>
			<?php if ( isset( $md['atl'] ) ) : ?>
				<div class="css-md-item">
					<span class="css-md-label">کمینه تاریخی (ATL)</span>
					<span class="css-md-value">$<?php echo esc_html( $fmt_num( $md['atl'] ) ); ?>
						<?php if ( isset( $md['atl_change_percentage'] ) ) : ?><span class="<?php echo esc_attr( $pct_class( $md['atl_change_percentage'] ) ); ?>">(<?php echo esc_html( $fmt_pct( $md['atl_change_percentage'] ) ); ?>)</span><?php endif; ?>
					</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_stats_html( int $post_id ): string {
		$calendar = self::get_calendar( $post_id );
		$total = 0; $buy = 0; $sell = 0; $correct = 0; $incorrect = 0; $pending = 0;

		foreach ( $calendar as $entries ) {
			foreach ( $entries as $entry ) {
				$total++;
				if ( 'buy' === ( $entry['signal'] ?? '' ) ) { $buy++; } else { $sell++; }
				$outcome = $entry['outcome'] ?? 'pending';
				if ( 'correct' === $outcome ) { $correct++; }
				elseif ( 'incorrect' === $outcome ) { $incorrect++; }
				else { $pending++; }
			}
		}

		$evaluated = $correct + $incorrect;
		$accuracy  = $evaluated > 0 ? round( ( $correct / $evaluated ) * 100, 1 ) : null;
		$buy_pct   = $total > 0 ? round( ( $buy / $total ) * 100 ) : 0;
		ob_start();
		?>
		<p>مجموع سیگنال‌های ثبت‌شده: <strong><?php echo (int) $total; ?></strong> (خرید: <?php echo (int) $buy; ?> / فروش: <?php echo (int) $sell; ?>)</p>
		<p>دقت کلی: <strong><?php echo null === $accuracy ? '—' : esc_html( $accuracy ) . '%'; ?></strong>
			(از <?php echo (int) $evaluated; ?> سیگنال بررسی‌شده، <?php echo (int) $pending; ?> مورد در انتظار)</p>

		<?php if ( $total > 0 ) : ?>
			<div style="display:flex;height:22px;border-radius:6px;overflow:hidden;margin:10px 0 4px;">
				<div style="width:<?php echo (int) $buy_pct; ?>%;background:#0e9f5a;"></div>
				<div style="width:<?php echo (int) ( 100 - $buy_pct ); ?>%;background:#e0343f;"></div>
			</div>
			<p style="font-size:11px;color:#888;">سبز = سهم سیگنال‌های خرید، قرمز = سهم سیگنال‌های فروش، از مجموع <?php echo (int) $total; ?> سیگنال</p>

			<?php if ( $evaluated > 0 ) :
				$correct_pct = round( ( $correct / $evaluated ) * 100 );
				?>
				<div style="display:flex;height:22px;border-radius:6px;overflow:hidden;margin:14px 0 4px;">
					<div style="width:<?php echo (int) $correct_pct; ?>%;background:#0d6efd;"></div>
					<div style="width:<?php echo (int) ( 100 - $correct_pct ); ?>%;background:#f2b93d;"></div>
				</div>
				<p style="font-size:11px;color:#888;">آبی = سیگنال‌های درست، زرد = سیگنال‌های غلط (فقط از موارد بررسی‌شده)</p>
			<?php endif; ?>
		<?php else : ?>
			<p>هنوز هیچ سیگنالی برای این ارز ثبت نشده.</p>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	public function render_calendar_box( $post ): void {
		echo self::render_calendar_html( $post->ID ); // phpcs:ignore
	}

	public static function render_calendar_html( int $post_id, ?string $month = null ): string {
		$calendar = self::get_calendar( $post_id );

		if ( null === $month ) {
			$month = isset( $_GET['css_month'] ) ? sanitize_text_field( wp_unslash( $_GET['css_month'] ) ) : current_time( 'Y-m' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}

		$timestamp         = strtotime( $month . '-01' );
		$days_in_month     = (int) gmdate( 't', $timestamp );
		$first_day_weekday = (int) gmdate( 'N', $timestamp ); // 1=دوشنبه ... 7=یکشنبه
		$prev_month        = gmdate( 'Y-m', strtotime( '-1 month', $timestamp ) );
		$next_month        = gmdate( 'Y-m', strtotime( '+1 month', $timestamp ) );
		$base_url          = remove_query_arg( 'css_month' );

		$tf_labels     = array( 'hourly' => 'ساعتی', 'daily' => 'روزانه', 'weekly' => 'هفتگی' );
		$signal_labels = array( 'buy' => 'خرید', 'sell' => 'فروش' );
		$outcome_labels = array( 'pending' => 'در انتظار بررسی', 'correct' => 'درست بود', 'incorrect' => 'غلط بود' );
		ob_start();
		?>
		<style>
			.css-coin-cal-nav{margin-bottom:10px;font-size:13px}
			.css-coin-cal-nav a{margin:0 8px;text-decoration:none}
			.css-coin-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}
			.css-coin-cal-head{text-align:center;font-size:11px;color:#888;font-weight:700;padding:4px 0}
			.css-coin-cal-cell{border:1px solid #eee;border-radius:6px;min-height:58px;padding:4px;font-size:11px}
			.css-coin-cal-empty{border:none;background:transparent}
			.css-coin-cal-has-entries{cursor:pointer;transition:background .12s}
			.css-coin-cal-has-entries:hover{background:#f7f9fc}
			.css-coin-cal-day{font-size:10px;color:#999;display:block;margin-bottom:3px}
			.css-coin-cal-dot{display:inline-block;margin:1px;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:700}
			.css-coin-cal-dot-correct{background:#e7f9ee;color:#0e9f5a}
			.css-coin-cal-dot-incorrect{background:#feecec;color:#e0343f}
			.css-coin-cal-dot-pending{background:#f2f4f7;color:#888}
			.css-coin-cal-details{border:1px solid #e2e5e9;border-radius:8px;padding:12px 14px;margin-top:14px;background:#fafbfc;font-size:12px;display:none}
			.css-coin-cal-details-title{font-weight:700;margin-bottom:8px;font-size:13px}
			.css-coin-cal-detail-item{padding:8px 0;border-top:1px solid #eee}
			.css-coin-cal-detail-item:first-of-type{border-top:none}
		</style>

		<div class="css-coin-cal-nav">
			<a href="<?php echo esc_url( add_query_arg( 'css_month', $prev_month, $base_url ) ); ?>">« ماه قبل</a>
			<strong><?php echo esc_html( $month ); ?></strong>
			<a href="<?php echo esc_url( add_query_arg( 'css_month', $next_month, $base_url ) ); ?>">ماه بعد »</a>
			<span style="color:#999;font-size:11px;">(تقویم میلادی — روی روزهایی که نقطه دارند کلیک کنید)</span>
		</div>

		<div class="css-coin-cal-grid">
			<?php foreach ( array( 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه', 'یکشنبه' ) as $wl ) : ?>
				<div class="css-coin-cal-head"><?php echo esc_html( mb_substr( $wl, 0, 2 ) ); ?></div>
			<?php endforeach; ?>

			<?php for ( $i = 1; $i < $first_day_weekday; $i++ ) : ?>
				<div class="css-coin-cal-cell css-coin-cal-empty"></div>
			<?php endfor; ?>

			<?php for ( $d = 1; $d <= $days_in_month; $d++ ) :
				$date_str = $month . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
				$entries  = $calendar[ $date_str ] ?? array();
				$has_entries = ! empty( $entries );
				?>
				<div class="css-coin-cal-cell<?php echo $has_entries ? ' css-coin-cal-has-entries' : ''; ?>"
					<?php if ( $has_entries ) : ?>onclick="cssToggleCalDay('<?php echo esc_js( $date_str ); ?>')"<?php endif; ?>>
					<span class="css-coin-cal-day"><?php echo (int) $d; ?></span>
					<?php foreach ( $entries as $entry ) :
						$outcome        = $entry['outcome'] ?? 'pending';
						$dot_class      = in_array( $outcome, array( 'correct', 'incorrect' ), true ) ? $outcome : 'pending';
						$outcome_symbol = array( 'pending' => '•', 'correct' => '✓', 'incorrect' => '✗' )[ $outcome ] ?? '•';
						$title          = ( $tf_labels[ $entry['timeframe'] ?? '' ] ?? ( $entry['timeframe'] ?? '' ) ) . ' - ' . ( $signal_labels[ $entry['signal'] ?? '' ] ?? '' );
						?>
						<span class="css-coin-cal-dot css-coin-cal-dot-<?php echo esc_attr( $dot_class ); ?>" title="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $outcome_symbol ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endfor; ?>
		</div>

		<?php
		// جزئیات کامل هر روز (پیش‌فرض مخفی، با کلیک روی خانه‌ی همان روز باز/بسته می‌شود)
		foreach ( $calendar as $date_str => $entries ) :
			if ( 0 !== strpos( $date_str, $month ) || empty( $entries ) ) {
				continue;
			}
			?>
			<div class="css-coin-cal-details" id="css-cal-details-<?php echo esc_attr( $date_str ); ?>">
				<div class="css-coin-cal-details-title">جزئیات <?php echo esc_html( $date_str ); ?></div>
				<?php foreach ( $entries as $entry ) :
					$signal_fa  = $signal_labels[ $entry['signal'] ?? '' ] ?? ( $entry['signal'] ?? '' );
					$tf_fa      = $tf_labels[ $entry['timeframe'] ?? '' ] ?? ( $entry['timeframe'] ?? '' );
					$outcome_fa = $outcome_labels[ $entry['outcome'] ?? 'pending' ] ?? ( $entry['outcome'] ?? '' );
					$market     = class_exists( 'CSS_Market_Trend' ) ? CSS_Market_Trend::get_trend_for_date( $date_str ) : null;
					?>
					<div class="css-coin-cal-detail-item">
						<strong><?php echo esc_html( $signal_fa ); ?></strong> (تایم‌فریم: <?php echo esc_html( $tf_fa ); ?>) — <?php echo esc_html( $outcome_fa ); ?>
						<?php if ( $market ) : ?>
							— روند بازار آن روز: <strong style="color:<?php echo 'bullish' === $market['trend'] ? '#15803d' : ( 'bearish' === $market['trend'] ? '#b91c1c' : '#666' ); ?>;"><?php echo esc_html( CSS_Market_Trend::trend_label( $market['trend'] ) ); ?></strong>
							(دامیننس BTC: <?php echo esc_html( round( $market['btc_dominance'], 1 ) ); ?>%)
						<?php endif; ?>
						<br>
						قیمت زمان سیگنال: <?php echo esc_html( $entry['price_at_signal'] ?? '—' ); ?> $
						<?php if ( isset( $entry['price_at_check'] ) && null !== $entry['price_at_check'] ) : ?>
							| قیمت زمان بررسی: <?php echo esc_html( $entry['price_at_check'] ); ?> $
						<?php endif; ?>
						<?php if ( ! empty( $entry['source_indicators'] ) ) : ?>
							<br>اندیکاتور صادرکننده: <?php echo esc_html( $entry['source_indicators'] ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $entry['indicators_detail'] ) && is_array( $entry['indicators_detail'] ) ) :
							$parts = array();
							foreach ( $entry['indicators_detail'] as $ind_id => $val ) {
								$ind     = CSS_Indicator_Registry::get( $ind_id );
								$label   = $ind ? $ind->get_label() : $ind_id;
								$val_fa  = $signal_labels[ $val ] ?? ( 'neutral' === $val ? 'خنثی' : $val );
								$parts[] = $label . ': ' . $val_fa;
							}
							?>
							<br>جزئیات همه اندیکاتورها: <?php echo esc_html( implode( '، ', $parts ) ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $entry['indicator_metrics'] ) && is_array( $entry['indicator_metrics'] ) ) :
							$m_parts = array();
							foreach ( $entry['indicator_metrics'] as $ind_id => $vals ) {
								$ind   = CSS_Indicator_Registry::get( $ind_id );
								$label = $ind ? $ind->get_label() : $ind_id;
								$vs    = array();
								foreach ( $vals as $mk => $mv ) {
									$vs[] = $mk . '=' . $mv;
								}
								if ( $vs ) {
									$m_parts[] = $label . ' (' . implode( '، ', $vs ) . ')';
								}
							}
							if ( $m_parts ) : ?>
								<br>مقادیر عددی: <?php echo esc_html( implode( ' | ', $m_parts ) ); ?>
							<?php endif;
						endif; ?>
						<br><span style="color:#999;font-size:10px;">
							ثبت: <?php echo esc_html( $entry['created_at'] ?? '' ); ?>
							<?php if ( ! empty( $entry['evaluated_at'] ) ) : ?>
								| بررسی: <?php echo esc_html( $entry['evaluated_at'] ); ?>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>

		<script>
		function cssToggleCalDay(date) {
			var el = document.getElementById('css-cal-details-' + date);
			if (!el) return;
			var isOpen = el.style.display === 'block';
			document.querySelectorAll('.css-coin-cal-details').forEach(function (d) { d.style.display = 'none'; });
			el.style.display = isOpen ? 'none' : 'block';
		}
		</script>
		<?php
		return ob_get_clean();
	}

	// ======================================================================
	// ستون‌های سفارشی در لیست پست‌ها
	// ======================================================================

	public function add_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['css_symbol']   = 'نماد';
				$new['css_rank']     = 'رتبه';
				$new['css_price']    = 'قیمت';
				$new['css_signals']  = 'تعداد سیگنال';
				$new['css_accuracy'] = 'دقت';
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'css_symbol':
				echo esc_html( get_post_meta( $post_id, '_css_symbol', true ) );
				break;
			case 'css_rank':
				echo esc_html( get_post_meta( $post_id, '_css_rank', true ) );
				break;
			case 'css_price':
				echo esc_html( get_post_meta( $post_id, '_css_price', true ) ) . ' $';
				break;
			case 'css_signals':
			case 'css_accuracy':
				$calendar = self::get_calendar( $post_id );
				$total = 0; $correct = 0; $incorrect = 0;
				foreach ( $calendar as $entries ) {
					foreach ( $entries as $entry ) {
						$total++;
						$outcome = $entry['outcome'] ?? 'pending';
						if ( 'correct' === $outcome ) { $correct++; }
						elseif ( 'incorrect' === $outcome ) { $incorrect++; }
					}
				}
				if ( 'css_signals' === $column ) {
					echo (int) $total;
				} else {
					$evaluated = $correct + $incorrect;
					echo $evaluated > 0 ? esc_html( round( ( $correct / $evaluated ) * 100, 1 ) ) . '%' : '—';
				}
				break;
		}
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * پست‌تایپ «اندیکاتورها» (css_indicator)
 * برای هر اندیکاتور موجود در سیستم (RSI, MACD, ...) یک پست ساخته می‌شود که خودکار
 * از روی رجیستری اندیکاتورها ساخته می‌شود. هر بار که این اندیکاتور در تشخیص یک سیگنال
 * نهایی (خرید/فروش) روی یک ارز «سهیم» بوده (هم‌جهت با سیگنال نهایی بوده)، یک رکورد در
 * تقویم همان روز این اندیکاتور ثبت می‌شود — دقیقاً مثل پست‌تایپ ارزها، اما این‌بار محور
 * اصلی خود اندیکاتور است و هر رکورد مشخص می‌کند برای کدام ارز بوده.
 */
class CSS_Indicator_CPT {

	const POST_TYPE = 'css_indicator';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ), 11 ); // بعد از رجیستری اندیکاتورها
		add_action( 'init', array( $this, 'ensure_posts_exist' ), 12 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	public function register_cpt(): void {
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name'          => 'اندیکاتورها',
				'singular_name' => 'اندیکاتور',
				'menu_name'     => 'اندیکاتورها',
				'edit_item'     => 'مشاهده اندیکاتور',
				'search_items'  => 'جستجوی اندیکاتور',
				'not_found'     => 'اندیکاتوری یافت نشد',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'crypto-signal-scanner',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'menu_icon'       => 'dashicons-chart-line',
		) );
	}

	/** برای هر اندیکاتور ثبت‌شده در رجیستری، در صورت نبود، یک پست بساز (لیست از همون اول کامل باشه) */
	public function ensure_posts_exist(): void {
		if ( ! class_exists( 'CSS_Indicator_Registry' ) ) {
			return;
		}
		foreach ( CSS_Indicator_Registry::get_all() as $id => $indicator ) {
			self::get_or_create_post( $id, $indicator->get_label() );
		}
	}

	// ======================================================================
	// متدهای استاتیک — برای فراخوانی از CSS_Cron
	// ======================================================================

	public static function get_or_create_post( string $ind_id, string $label ): ?int {
		$existing = self::find_post_id( $ind_id );
		if ( $existing ) {
			return $existing;
		}

		$post_id = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => $label,
			'post_name'   => sanitize_title( $ind_id ),
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $post_id ) ) {
			return null;
		}

		update_post_meta( $post_id, '_css_ind_id', $ind_id );
		return $post_id;
	}

	public static function find_post_id( string $ind_id ): ?int {
		$post = get_page_by_path( sanitize_title( $ind_id ), OBJECT, self::POST_TYPE );
		return $post ? $post->ID : null;
	}

	/** ثبت یک معامله/سیگنال این اندیکاتور روی یک ارز خاص، در تقویم «روز خودش» */
	public static function record_signal( string $ind_id, string $ind_label, string $coin_id, string $coin_symbol, string $coin_name, string $signal, string $timeframe, float $price, array $metrics = array() ): void {
		$post_id = self::get_or_create_post( $ind_id, $ind_label );
		if ( ! $post_id ) {
			return;
		}

		self::add_entry( $post_id, array(
			'coin_id'         => $coin_id,
			'coin_symbol'     => $coin_symbol,
			'coin_name'       => $coin_name,
			'signal'          => $signal,
			'timeframe'       => $timeframe,
			'outcome'         => 'pending',
			'price_at_signal' => $price,
			'price_at_check'  => null,
			'metrics'         => $metrics,
			'created_at'      => current_time( 'mysql' ),
			'evaluated_at'    => null,
		) );
	}

	/** ثبت نتیجه دقت‌سنجی روی همان روزی که سیگنال اصلی صادر شده بود */
	public static function record_outcome( string $ind_id, string $date, string $timeframe, string $coin_id, string $signal, string $outcome, ?float $price_at_check = null ): void {
		$post_id = self::find_post_id( $ind_id );
		if ( ! $post_id ) {
			return;
		}

		$calendar = self::get_calendar( $post_id );
		if ( empty( $calendar[ $date ] ) ) {
			return;
		}

		foreach ( $calendar[ $date ] as &$entry ) {
			if ( ( $entry['timeframe'] ?? '' ) === $timeframe
				&& ( $entry['coin_id'] ?? '' ) === $coin_id
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

	/** استفاده در انتقال تاریخچه قدیمی: از روی یک ردیف تاریخچه، رکورد کامل را به تقویم هر اندیکاتور صادرکننده اضافه می‌کند */
	public static function migrate_entry_for_row( array $row, string $coin_name ): void {
		$details = json_decode( $row['indicators_detail'], true ) ?: array();
		foreach ( $details as $ind_id => $val ) {
			if ( $val !== $row['trade_signal'] ) {
				continue;
			}
			$indicator = class_exists( 'CSS_Indicator_Registry' ) ? CSS_Indicator_Registry::get( $ind_id ) : null;
			$label     = $indicator ? $indicator->get_label() : $ind_id;
			$post_id   = self::get_or_create_post( $ind_id, $label );
			if ( ! $post_id ) {
				continue;
			}

			self::add_entry( $post_id, array(
				'coin_id'         => $row['coin_id'],
				'coin_symbol'     => $row['symbol'],
				'coin_name'       => $coin_name,
				'signal'          => $row['trade_signal'],
				'timeframe'       => $row['timeframe'] ?? 'daily',
				'outcome'         => $row['outcome'],
				'price_at_signal' => (float) $row['price_at_signal'],
				'price_at_check'  => null !== $row['price_at_check'] ? (float) $row['price_at_check'] : null,
				'created_at'      => $row['created_at'],
				'evaluated_at'    => $row['evaluated_at'],
			) );
		}
	}

	private static function add_entry( int $post_id, array $entry ): void {
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
				&& ( $existing['coin_id'] ?? '' ) === $entry['coin_id']
				&& ( $existing['signal'] ?? '' ) === $entry['signal'] ) {
				return; // قبلاً ثبت شده
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
		ksort( $calendar );
		update_post_meta( $post_id, '_css_calendar', wp_json_encode( $calendar, JSON_UNESCAPED_UNICODE ) );
	}

	// ======================================================================
	// نمایش در پیشخوان
	// ======================================================================

	public function add_meta_boxes(): void {
		add_meta_box( 'css_ind_calendar', 'تقویم معاملات این اندیکاتور — روی هر روز کلیک کنید', array( $this, 'render_calendar_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'css_ind_stats', 'خلاصه عملکرد و نمودار به‌تفکیک ارز', array( $this, 'render_stats_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public function render_stats_box( $post ): void {
		echo self::render_stats_html( $post->ID ); // phpcs:ignore
	}

	public static function render_stats_html( int $post_id ): string {
		$calendar = self::get_calendar( $post_id );
		$total = 0; $buy = 0; $sell = 0; $correct = 0; $incorrect = 0; $pending = 0;
		$per_coin  = array(); // coin_symbol => ['total'=>N,'correct'=>N,'incorrect'=>N]
		$per_trend = array( // دقت این اندیکاتور به‌تفکیک روند بازار همان روز
			'bullish' => array( 'total' => 0, 'correct' => 0, 'incorrect' => 0 ),
			'bearish' => array( 'total' => 0, 'correct' => 0, 'incorrect' => 0 ),
			'neutral' => array( 'total' => 0, 'correct' => 0, 'incorrect' => 0 ),
		);
		$has_market_class = class_exists( 'CSS_Market_Trend' );

		foreach ( $calendar as $date_str => $entries ) {
			$market = $has_market_class ? CSS_Market_Trend::get_trend_for_date( $date_str ) : null;
			foreach ( $entries as $entry ) {
				$total++;
				if ( 'buy' === ( $entry['signal'] ?? '' ) ) { $buy++; } else { $sell++; }
				$outcome = $entry['outcome'] ?? 'pending';
				if ( 'correct' === $outcome ) { $correct++; }
				elseif ( 'incorrect' === $outcome ) { $incorrect++; }
				else { $pending++; }

				$sym = $entry['coin_symbol'] ?? '؟';
				if ( ! isset( $per_coin[ $sym ] ) ) {
					$per_coin[ $sym ] = array( 'total' => 0, 'correct' => 0, 'incorrect' => 0 );
				}
				$per_coin[ $sym ]['total']++;
				if ( 'correct' === $outcome ) { $per_coin[ $sym ]['correct']++; }
				elseif ( 'incorrect' === $outcome ) { $per_coin[ $sym ]['incorrect']++; }

				if ( $market && isset( $per_trend[ $market['trend'] ] ) ) {
					$per_trend[ $market['trend'] ]['total']++;
					if ( 'correct' === $outcome ) { $per_trend[ $market['trend'] ]['correct']++; }
					elseif ( 'incorrect' === $outcome ) { $per_trend[ $market['trend'] ]['incorrect']++; }
				}
			}
		}

		$evaluated = $correct + $incorrect;
		$accuracy  = $evaluated > 0 ? round( ( $correct / $evaluated ) * 100, 1 ) : null;
		$buy_pct   = $total > 0 ? round( ( $buy / $total ) * 100 ) : 0;
		ob_start();
		?>
		<p>مجموع سیگنال‌های این اندیکاتور (روی همه ارزها): <strong><?php echo (int) $total; ?></strong> (خرید: <?php echo (int) $buy; ?> / فروش: <?php echo (int) $sell; ?>)</p>
		<p>برایند کلی دقت این اندیکاتور: <strong style="font-size:15px;"><?php echo null === $accuracy ? '—' : esc_html( $accuracy ) . '%'; ?></strong>
			(از <?php echo (int) $evaluated; ?> سیگنال بررسی‌شده، <?php echo (int) $pending; ?> مورد در انتظار)</p>

		<?php if ( $total > 0 ) : ?>
			<div style="display:flex;height:22px;border-radius:6px;overflow:hidden;margin:10px 0 4px;">
				<div style="width:<?php echo (int) $buy_pct; ?>%;background:#0e9f5a;"></div>
				<div style="width:<?php echo (int) ( 100 - $buy_pct ); ?>%;background:#e0343f;"></div>
			</div>
			<p style="font-size:11px;color:#888;">سبز = سهم سیگنال‌های خرید، قرمز = سهم سیگنال‌های فروش</p>

			<h4 style="margin-top:18px;">دقت این اندیکاتور به‌تفکیک روند بازار</h4>
			<p style="font-size:11px;color:#888;margin-bottom:8px;">این بخش نشان می‌دهد این اندیکاتور در روزهای صعودی، نزولی یا خنثی بازار چقدر قابل‌اعتماد بوده.</p>
			<table class="widefat striped" style="margin-bottom:16px;">
				<thead><tr><th>روند بازار</th><th>تعداد سیگنال</th><th>دقت</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $per_trend as $trend_key => $stat ) :
						$t_evaluated = $stat['correct'] + $stat['incorrect'];
						$t_pct = $t_evaluated > 0 ? round( ( $stat['correct'] / $t_evaluated ) * 100 ) : null;
						$t_color = 'bullish' === $trend_key ? '#15803d' : ( 'bearish' === $trend_key ? '#b91c1c' : '#666' );
						?>
						<tr>
							<td><strong style="color:<?php echo esc_attr( $t_color ); ?>;"><?php echo esc_html( CSS_Market_Trend::trend_label( $trend_key ) ); ?></strong></td>
							<td><?php echo (int) $stat['total']; ?></td>
							<td><?php echo null === $t_pct ? '—' : esc_html( $t_pct ) . '%'; ?></td>
							<td style="min-width:120px;">
								<?php if ( null !== $t_pct ) : ?>
									<div style="height:10px;border-radius:5px;background:#eee;overflow:hidden;">
										<div style="height:100%;width:<?php echo (int) $t_pct; ?>%;background:<?php echo esc_attr( $t_color ); ?>;"></div>
									</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h4 style="margin-top:18px;">دقت به‌تفکیک هر ارز (بهترین عملکرد بالا)</h4>
			<table class="widefat striped" style="margin-top:6px;">
				<thead><tr><th>ارز</th><th>تعداد</th><th>دقت</th><th></th></tr></thead>
				<tbody>
					<?php
					// مرتب‌سازی بر اساس درصد دقت (بیشترین موفقیت بالا)؛ ارزهای هنوز بدون نتیجه پایین‌تر می‌آیند
					uasort( $per_coin, function ( $a, $b ) {
						$eval_a = $a['correct'] + $a['incorrect'];
						$eval_b = $b['correct'] + $b['incorrect'];
						$pct_a  = $eval_a > 0 ? $a['correct'] / $eval_a : -1;
						$pct_b  = $eval_b > 0 ? $b['correct'] / $eval_b : -1;
						return $pct_b <=> $pct_a ?: $b['total'] <=> $a['total'];
					} );
					foreach ( $per_coin as $sym => $stat ) :
						$coin_evaluated = $stat['correct'] + $stat['incorrect'];
						$coin_pct = $coin_evaluated > 0 ? round( ( $stat['correct'] / $coin_evaluated ) * 100 ) : null;
						?>
						<tr>
							<td><strong><?php echo esc_html( $sym ); ?></strong></td>
							<td><?php echo (int) $stat['total']; ?></td>
							<td><?php echo null === $coin_pct ? '—' : esc_html( $coin_pct ) . '%'; ?></td>
							<td style="min-width:120px;">
								<?php if ( null !== $coin_pct ) : ?>
									<div style="height:10px;border-radius:5px;background:#eee;overflow:hidden;">
										<div style="height:100%;width:<?php echo (int) $coin_pct; ?>%;background:#0d6efd;"></div>
									</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p>هنوز هیچ سیگنالی از این اندیکاتور ثبت نشده.</p>
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
		$first_day_weekday = (int) gmdate( 'N', $timestamp );
		$prev_month        = gmdate( 'Y-m', strtotime( '-1 month', $timestamp ) );
		$next_month        = gmdate( 'Y-m', strtotime( '+1 month', $timestamp ) );
		$base_url          = remove_query_arg( 'css_month' );

		$tf_labels      = array( 'hourly' => 'ساعتی', 'daily' => 'روزانه', 'weekly' => 'هفتگی' );
		$signal_labels  = array( 'buy' => 'خرید', 'sell' => 'فروش' );
		$outcome_labels = array( 'pending' => 'در انتظار بررسی', 'correct' => 'درست بود', 'incorrect' => 'غلط بود' );
		ob_start();
		?>
		<style>
			.css-ind-cal-nav{margin-bottom:10px;font-size:13px}
			.css-ind-cal-nav a{margin:0 8px;text-decoration:none}
			.css-ind-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}
			.css-ind-cal-head{text-align:center;font-size:11px;color:#888;font-weight:700;padding:4px 0}
			.css-ind-cal-cell{border:1px solid #eee;border-radius:6px;min-height:58px;padding:4px;font-size:11px}
			.css-ind-cal-empty{border:none;background:transparent}
			.css-ind-cal-has-entries{cursor:pointer;transition:background .12s}
			.css-ind-cal-has-entries:hover{background:#f7f9fc}
			.css-ind-cal-day{font-size:10px;color:#999;display:block;margin-bottom:3px}
			.css-ind-cal-dot{display:inline-block;margin:1px;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:700}
			.css-ind-cal-dot-correct{background:#e7f9ee;color:#0e9f5a}
			.css-ind-cal-dot-incorrect{background:#feecec;color:#e0343f}
			.css-ind-cal-dot-pending{background:#f2f4f7;color:#888}
			.css-ind-cal-details{border:1px solid #e2e5e9;border-radius:8px;padding:12px 14px;margin-top:14px;background:#fafbfc;font-size:12px;display:none}
			.css-ind-cal-details-title{font-weight:700;margin-bottom:8px;font-size:13px}
			.css-ind-cal-detail-item{padding:8px 0;border-top:1px solid #eee}
			.css-ind-cal-detail-item:first-of-type{border-top:none}
		</style>

		<div class="css-ind-cal-nav">
			<a href="<?php echo esc_url( add_query_arg( 'css_month', $prev_month, $base_url ) ); ?>">« ماه قبل</a>
			<strong><?php echo esc_html( $month ); ?></strong>
			<a href="<?php echo esc_url( add_query_arg( 'css_month', $next_month, $base_url ) ); ?>">ماه بعد »</a>
			<span style="color:#999;font-size:11px;">(هر نقطه یک ارز است — روی روزهای دارای نقطه کلیک کنید)</span>
		</div>

		<div class="css-ind-cal-grid">
			<?php foreach ( array( 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه', 'یکشنبه' ) as $wl ) : ?>
				<div class="css-ind-cal-head"><?php echo esc_html( mb_substr( $wl, 0, 2 ) ); ?></div>
			<?php endforeach; ?>

			<?php for ( $i = 1; $i < $first_day_weekday; $i++ ) : ?>
				<div class="css-ind-cal-cell css-ind-cal-empty"></div>
			<?php endfor; ?>

			<?php for ( $d = 1; $d <= $days_in_month; $d++ ) :
				$date_str = $month . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
				$entries  = $calendar[ $date_str ] ?? array();
				$has_entries = ! empty( $entries );
				?>
				<div class="css-ind-cal-cell<?php echo $has_entries ? ' css-ind-cal-has-entries' : ''; ?>"
					<?php if ( $has_entries ) : ?>onclick="cssToggleIndDay('<?php echo esc_js( $date_str ); ?>')"<?php endif; ?>>
					<span class="css-ind-cal-day"><?php echo (int) $d; ?></span>
					<?php foreach ( $entries as $entry ) :
						$outcome        = $entry['outcome'] ?? 'pending';
						$dot_class      = in_array( $outcome, array( 'correct', 'incorrect' ), true ) ? $outcome : 'pending';
						$outcome_symbol = array( 'pending' => '•', 'correct' => '✓', 'incorrect' => '✗' )[ $outcome ] ?? '•';
						$title = ( $entry['coin_symbol'] ?? '' ) . ' - ' . ( $signal_labels[ $entry['signal'] ?? '' ] ?? '' );
						?>
						<span class="css-ind-cal-dot css-ind-cal-dot-<?php echo esc_attr( $dot_class ); ?>" title="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $outcome_symbol ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endfor; ?>
		</div>

		<?php
		foreach ( $calendar as $date_str => $entries ) :
			if ( 0 !== strpos( $date_str, $month ) || empty( $entries ) ) {
				continue;
			}
			?>
			<div class="css-ind-cal-details" id="css-ind-cal-details-<?php echo esc_attr( $date_str ); ?>">
				<div class="css-ind-cal-details-title">جزئیات <?php echo esc_html( $date_str ); ?></div>
				<?php foreach ( $entries as $entry ) :
					$signal_fa  = $signal_labels[ $entry['signal'] ?? '' ] ?? ( $entry['signal'] ?? '' );
					$tf_fa      = $tf_labels[ $entry['timeframe'] ?? '' ] ?? ( $entry['timeframe'] ?? '' );
					$outcome_fa = $outcome_labels[ $entry['outcome'] ?? 'pending' ] ?? ( $entry['outcome'] ?? '' );
					$coin_post  = class_exists( 'CSS_Coin_CPT' ) ? CSS_Coin_CPT::find_post_id( $entry['coin_id'] ?? '' ) : null;
					$pl         = class_exists( 'CSS_MA_Helper' ) ? CSS_MA_Helper::signal_pl_percent( $entry['signal'] ?? '', $entry['price_at_signal'] ?? null, $entry['price_at_check'] ?? null ) : null;
					$market     = class_exists( 'CSS_Market_Trend' ) ? CSS_Market_Trend::get_trend_for_date( $date_str ) : null;
					?>
					<div class="css-ind-cal-detail-item">
						<strong>
							<?php if ( $coin_post && current_user_can( 'manage_options' ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $coin_post ) ); ?>"><?php echo esc_html( $entry['coin_symbol'] ?? '' ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $entry['coin_symbol'] ?? '' ); ?>
							<?php endif; ?>
						</strong>
						— <?php echo esc_html( $signal_fa ); ?> (تایم‌فریم: <?php echo esc_html( $tf_fa ); ?>) — <?php echo esc_html( $outcome_fa ); ?>
						<?php if ( $market ) : ?>
							— روند بازار: <strong style="color:<?php echo 'bullish' === $market['trend'] ? '#15803d' : ( 'bearish' === $market['trend'] ? '#b91c1c' : '#666' ); ?>;"><?php echo esc_html( CSS_Market_Trend::trend_label( $market['trend'] ) ); ?></strong>
						<?php endif; ?>
						<br>
						قیمت زمان سیگنال: <?php echo esc_html( $entry['price_at_signal'] ?? '—' ); ?> $
						<?php if ( isset( $entry['price_at_check'] ) && null !== $entry['price_at_check'] ) : ?>
							| قیمت زمان بررسی: <?php echo esc_html( $entry['price_at_check'] ); ?> $
						<?php endif; ?>
						<?php if ( null !== $pl ) : ?>
							| سود/ضرر: <strong style="color:<?php echo $pl >= 0 ? '#15803d' : '#b91c1c'; ?>;"><?php echo ( $pl >= 0 ? '+' : '' ) . esc_html( round( $pl, 2 ) ); ?>%</strong>
						<?php endif; ?>
						<?php if ( ! empty( $entry['metrics'] ) && is_array( $entry['metrics'] ) ) :
							$m_parts = array();
							foreach ( $entry['metrics'] as $mk => $mv ) {
								$m_parts[] = $mk . '=' . $mv;
							}
							?>
							<br>مقادیر عددی این سیگنال: <?php echo esc_html( implode( '، ', $m_parts ) ); ?>
						<?php endif; ?>
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
		function cssToggleIndDay(date) {
			var el = document.getElementById('css-ind-cal-details-' + date);
			if (!el) return;
			var isOpen = el.style.display === 'block';
			document.querySelectorAll('.css-ind-cal-details').forEach(function (d) { d.style.display = 'none'; });
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
				$new['css_ind_signals']  = 'تعداد سیگنال';
				$new['css_ind_accuracy'] = 'دقت';
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( ! in_array( $column, array( 'css_ind_signals', 'css_ind_accuracy' ), true ) ) {
			return;
		}
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
		if ( 'css_ind_signals' === $column ) {
			echo (int) $total;
		} else {
			$evaluated = $correct + $incorrect;
			echo $evaluated > 0 ? esc_html( round( ( $correct / $evaluated ) * 100, 1 ) ) . '%' : '—';
		}
	}
}

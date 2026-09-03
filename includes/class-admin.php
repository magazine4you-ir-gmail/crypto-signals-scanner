<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_menu', array( $this, 'reorder_submenu' ), 999 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			'اسکنر سیگنال ارز دیجیتال',
			'سیگنال ارزها',
			'manage_options',
			'crypto-signal-scanner',
			array( $this, 'render_dashboard_page' ),
			'dashicons-chart-line',
			26
		);

		add_submenu_page(
			'crypto-signal-scanner',
			'داشبورد',
			'داشبورد',
			'manage_options',
			'crypto-signal-scanner',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'crypto-signal-scanner',
			'دقت سیگنال‌ها',
			'دقت سیگنال‌ها',
			'manage_options',
			'crypto-signal-scanner-accuracy',
			array( $this, 'render_accuracy_page' )
		);

		add_submenu_page(
			'crypto-signal-scanner',
			'تنظیمات',
			'تنظیمات',
			'manage_options',
			'crypto-signal-scanner-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'crypto-signal-scanner',
			'تاریخچه اسکن',
			'تاریخچه اسکن',
			'manage_options',
			'crypto-signal-scanner-scan-log',
			array( $this, 'render_scan_log_page' )
		);

		add_submenu_page(
			'crypto-signal-scanner',
			'روند بازار',
			'روند بازار',
			'manage_options',
			'crypto-signal-scanner-market-trend',
			array( $this, 'render_market_trend_page' )
		);
	}

	/**
	 * وردپرس زیرمنوهای پست‌تایپ‌ها (ارزها/اندیکاتورها) را خودکار و بدون ترتیب مشخص
	 * وسط زیرمنوهای ما اضافه می‌کند. اینجا با اولویت خیلی دیر (۹۹۹، بعد از همه)
	 * کل لیست را به ترتیب دلخواه بازچینی می‌کنیم تا داشبورد و دقت سیگنال‌ها همیشه اول باشند.
	 */
	public function reorder_submenu(): void {
		global $submenu;
		$parent = 'crypto-signal-scanner';
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		$desired_order = array(
			'crypto-signal-scanner',
			'crypto-signal-scanner-accuracy',
			'crypto-signal-scanner-settings',
			'crypto-signal-scanner-scan-log',
			'crypto-signal-scanner-market-trend',
			'edit.php?post_type=css_coin',
			'edit.php?post_type=css_indicator',
		);

		$items   = $submenu[ $parent ];
		$ordered = array();

		foreach ( $desired_order as $slug ) {
			foreach ( $items as $key => $item ) {
				if ( isset( $item[2] ) && $item[2] === $slug ) {
					$ordered[] = $item;
					unset( $items[ $key ] );
					break;
				}
			}
		}

		// هر آیتم پیش‌بینی‌نشده‌ای (مثلاً از یک افزونه دیگر) را انتهای لیست نگه دار
		foreach ( $items as $item ) {
			$ordered[] = $item;
		}

		$submenu[ $parent ] = $ordered;
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'crypto-signal-scanner' ) === false ) {
			return;
		}
		$css_ver = css_asset_ver( 'assets/css/admin.css' );
		$js_ver  = css_asset_ver( 'assets/js/admin.js' );

		wp_enqueue_style( 'css-admin', CSS_PLUGIN_URL . 'assets/css/admin.css', array(), $css_ver );
		wp_enqueue_script( 'css-admin', CSS_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), $js_ver, true );
		wp_localize_script( 'css-admin', 'CSS_Admin_Data', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'css_admin_nonce' ),
		) );
	}

	// ==================================================================
	// صفحه داشبورد: جدول نتایج آخرین اسکن
	// ==================================================================
	private function render_error_log(): void {
		$log = get_option( 'css_error_log', array() );
		if ( empty( $log ) ) {
			return;
		}
		$recent = array_slice( array_reverse( $log ), 0, 8 );
		?>
		<div class="notice notice-error css-error-log">
			<p><strong>گزارش خطاهای اخیر در دریافت دیتا (این بخش برای عیب‌یابی است):</strong></p>
			<ul>
				<?php foreach ( $recent as $entry ) : ?>
					<li><code><?php echo esc_html( $entry['time'] ); ?></code> — <?php echo esc_html( $entry['message'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="description">
				اگر خطاها شامل عبارت <code>429</code> یا <code>401</code> هستند، یعنی CoinGecko درخواست‌های شما را به‌دلیل محدودیت نرخ یا نیاز به کلید API رد کرده — در این صورت یک «Demo API Key» رایگان از سایت coingecko.com بسازید و در تنظیمات افزونه وارد کنید.
			</p>
		</div>
		<?php
	}

	public function render_dashboard_page(): void {
		global $wpdb;
		$table    = $wpdb->prefix . CSS_TABLE_SIGNALS;
		$settings = get_option( 'css_settings', array() );
		$active_tf = ! empty( $settings['active_timeframes'] ) ? (array) $settings['active_timeframes'] : array( 'daily' );
		$default_tf = $active_tf[0] ?? 'daily';

		$rows   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY market_cap_rank ASC", ARRAY_A );
		$status = get_option( 'css_scan_status', array() );

		$tf_labels = array( 'hourly' => 'ساعتی', 'daily' => 'روزانه', 'weekly' => 'هفتگی' );

		// شمارش‌ها فقط برای تایم‌فریم پیش‌فرض محاسبه می‌شود (چون جدول ممکن است چند تایم‌فریم را همزمان نگه دارد)
		$counts = array( 'buy' => 0, 'sell' => 0, 'neutral' => 0 );
		foreach ( $rows as $r ) {
			if ( $r['timeframe'] === $default_tf && isset( $counts[ $r['trade_signal'] ] ) ) {
				$counts[ $r['trade_signal'] ]++;
			}
		}
		$count_default_tf = count( array_filter( $rows, fn( $r ) => $r['timeframe'] === $default_tf ) );
		?>
		<div class="wrap css-wrap">
			<h1>اسکنر سیگنال ارز دیجیتال</h1>

			<?php $this->render_error_log(); ?>

			<div class="css-toolbar">
				<button id="css-scan-now" class="button button-primary">اسکن الان</button>
				<button id="css-cleanup-now" class="button">پاکسازی و یکپارچه‌سازی</button>
				<span id="css-scan-progress"></span>
				<span id="css-cleanup-progress"></span>
				<?php if ( ! empty( $status['finished_at'] ) ) : ?>
					<span class="css-last-scan">آخرین اسکن کامل: <?php echo esc_html( $status['finished_at'] ); ?></span>
				<?php endif; ?>
			</div>
			<p class="description" style="margin-top:-6px;">دکمه «پاکسازی و یکپارچه‌سازی» ارزهای قدیمی/یتیم (که از سری‌های اول افزونه باقی مانده و دیگر اسکن نمی‌شوند) را از این جدول حذف می‌کند و برای بقیه ارزها، در صورت نبود، پست‌تایپشان را می‌سازد تا لینک شوند. تاریخچه و آرشیو پست‌تایپ‌ها دست‌نخورده می‌ماند.</p>

			<h2 style="margin-top:20px;font-size:15px;">نتیجه تست سیستم</h2>
			<?php echo CSS_Accuracy_Stats::render_compact_summary_html( CSS_Accuracy_Stats::get_summary() ); // phpcs:ignore ?>
			<p class="description">این نتیجه بر اساس مقایسه قیمت زمان صدور هر سیگنال با قیمت بعد از گذشت مهلت بررسی به‌دست می‌آید. برای جزئیات کامل به «دقت سیگنال‌ها» مراجعه کنید.</p>

			<?php if ( empty( $rows ) ) : ?>
				<p>هنوز هیچ اسکنی انجام نشده. روی «اسکن الان» کلیک کنید یا منتظر اجرای خودکار بمانید.</p>
			<?php else : ?>

				<div class="css-summary-cards">
					<div class="css-summary-card css-summary-all" data-filter-target="all">
						<span class="css-summary-num"><?php echo (int) $count_default_tf; ?></span>
						<span class="css-summary-label">همه ارزها (<?php echo esc_html( $tf_labels[ $default_tf ] ?? $default_tf ); ?>)</span>
					</div>
					<div class="css-summary-card css-summary-buy" data-filter-target="buy">
						<span class="css-summary-num"><?php echo (int) $counts['buy']; ?></span>
						<span class="css-summary-label">سیگنال خرید</span>
					</div>
					<div class="css-summary-card css-summary-sell" data-filter-target="sell">
						<span class="css-summary-num"><?php echo (int) $counts['sell']; ?></span>
						<span class="css-summary-label">سیگنال فروش</span>
					</div>
					<div class="css-summary-card css-summary-neutral" data-filter-target="neutral">
						<span class="css-summary-num"><?php echo (int) $counts['neutral']; ?></span>
						<span class="css-summary-label">خنثی</span>
					</div>
				</div>

				<div class="css-filters">
					<div class="css-filter-tabs" id="css-admin-filter-tabs">
						<button type="button" class="css-tab-btn active" data-filter="all">همه</button>
						<button type="button" class="css-tab-btn" data-filter="buy">خرید</button>
						<button type="button" class="css-tab-btn" data-filter="sell">فروش</button>
						<button type="button" class="css-tab-btn" data-filter="neutral">خنثی</button>
					</div>
					<?php if ( count( $active_tf ) > 1 ) : ?>
						<select id="css-admin-timeframe" class="css-search-input" style="min-width:auto;">
							<?php foreach ( $active_tf as $tf ) : ?>
								<option value="<?php echo esc_attr( $tf ); ?>" <?php selected( $tf, $default_tf ); ?>><?php echo esc_html( $tf_labels[ $tf ] ?? $tf ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<input type="text" id="css-admin-search" class="css-search-input" placeholder="جستجوی نماد یا نام ارز...">
				</div>

				<div class="css-table-wrap">
				<table class="widefat striped css-table" id="css-admin-table" data-default-timeframe="<?php echo esc_attr( $default_tf ); ?>">
					<thead>
						<tr>
								<th class="css-col-rank">رتبه</th>
							<th class="css-col-coin">نماد</th>
							<th class="css-col-name">نام</th>
							<th>تایم‌فریم</th>
							<th>قیمت (USD)</th>
							<th>سیگنال نهایی</th>
							<th>جزئیات اندیکاتورها</th>
							<th class="css-col-updated">آخرین بروزرسانی</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) :
							$details    = json_decode( $row['indicators_detail'], true ) ?: array();
							$detail_str = array();
							foreach ( $details as $ind_id => $val ) {
								$indicator    = CSS_Indicator_Registry::get( $ind_id );
								$label        = $indicator ? $indicator->get_label() : $ind_id;
								$ind_post     = class_exists( 'CSS_Indicator_CPT' ) ? CSS_Indicator_CPT::find_post_id( $ind_id ) : null;
								$label_html   = $ind_post ? '<a href="' . esc_url( get_edit_post_link( $ind_post ) ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
								$detail_str[] = $label_html . ': ' . esc_html( $this->signal_fa( $val ) );
							}
							$search_key = mb_strtolower( $row['symbol'] . ' ' . $row['name'] );
							$coin_post  = CSS_Coin_CPT::find_post_id( $row['coin_id'] );
							?>
							<tr data-signal="<?php echo esc_attr( $row['trade_signal'] ); ?>" data-timeframe="<?php echo esc_attr( $row['timeframe'] ); ?>" data-search="<?php echo esc_attr( $search_key ); ?>">
								<td class="css-col-rank"><?php echo esc_html( $row['market_cap_rank'] ); ?></td>
								<td class="css-col-coin">
									<?php if ( $coin_post ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $coin_post ) ); ?>"><strong><?php echo esc_html( $row['symbol'] ); ?></strong></a>
									<?php else : ?>
										<strong><?php echo esc_html( $row['symbol'] ); ?></strong>
									<?php endif; ?>
								</td>
								<td class="css-col-name"><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo esc_html( $tf_labels[ $row['timeframe'] ] ?? $row['timeframe'] ); ?></td>
								<td><?php echo esc_html( number_format( (float) $row['price'], 4 ) ); ?></td>
								<td><span class="css-badge css-badge-<?php echo esc_attr( $row['trade_signal'] ); ?>"><?php echo esc_html( $this->signal_fa( $row['trade_signal'] ) ); ?></span></td>
								<td class="css-details"><?php echo implode( '<br>', $detail_str ); ?></td>
								<td class="css-col-updated"><?php echo esc_html( $row['updated_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ==================================================================
	// صفحه دقت سیگنال‌ها (تاریخچه + آمار درست/غلط بودن)
	// ==================================================================
	public function render_accuracy_page(): void {
		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_HISTORY;

		$total_evaluated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE outcome IN ('correct','incorrect')" );
		$total_correct   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE outcome = 'correct'" );
		$total_pending    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE outcome = 'pending'" );
		$accuracy_pct     = $total_evaluated > 0 ? round( ( $total_correct / $total_evaluated ) * 100, 1 ) : null;

		// آمار به تفکیک هر اندیکاتور (بر اساس جزئیاتی که موقع صدور سیگنال ذخیره شده)
		$evaluated_rows = $wpdb->get_results( "SELECT trade_signal, indicators_detail, outcome FROM {$table} WHERE outcome IN ('correct','incorrect')", ARRAY_A );
		$per_indicator  = array(); // ind_id => ['correct'=>N,'total'=>N]
		foreach ( $evaluated_rows as $row ) {
			$details = json_decode( $row['indicators_detail'], true ) ?: array();
			foreach ( $details as $ind_id => $val ) {
				if ( $val !== $row['trade_signal'] ) {
					continue; // فقط اندیکاتورهایی که با سیگنال نهایی هم‌جهت بودن رو حساب کن
				}
				if ( ! isset( $per_indicator[ $ind_id ] ) ) {
					$per_indicator[ $ind_id ] = array( 'correct' => 0, 'total' => 0 );
				}
				$per_indicator[ $ind_id ]['total']++;
				if ( 'correct' === $row['outcome'] ) {
					$per_indicator[ $ind_id ]['correct']++;
				}
			}
		}

		$recent = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50", ARRAY_A );

		$now      = current_time( 'mysql' );
		$due_now  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE outcome = 'pending' AND check_after <= %s", $now
		) );
		?>
		<div class="wrap css-wrap">
			<h1>دقت سیگنال‌ها</h1>
			<p class="description">هر بار که یک ارز سیگنال خرید/فروش تازه‌ای می‌گیرد، قیمت آن لحظه ذخیره می‌شود. بعد از مهلت تعیین‌شده در تنظیمات، قیمت دوباره چک می‌شود تا ببینیم سیگنال درست بوده یا نه.</p>

			<div class="css-toolbar">
				<button id="css-evaluate-now" class="button button-primary">بررسی دقت سیگنال‌ها الان (<?php echo (int) $due_now; ?> مورد آماده)</button>
				<span id="css-evaluate-progress"></span>
			</div>

			<div class="css-summary-cards">
				<div class="css-summary-card">
					<span class="css-summary-num"><?php echo null === $accuracy_pct ? '—' : $accuracy_pct . '%'; ?></span>
					<span class="css-summary-label">دقت کلی (از <?php echo (int) $total_evaluated; ?> سیگنال بررسی‌شده)</span>
				</div>
				<div class="css-summary-card css-summary-buy">
					<span class="css-summary-num"><?php echo (int) $total_correct; ?></span>
					<span class="css-summary-label">سیگنال‌های درست</span>
				</div>
				<div class="css-summary-card css-summary-sell">
					<span class="css-summary-num"><?php echo (int) ( $total_evaluated - $total_correct ); ?></span>
					<span class="css-summary-label">سیگنال‌های غلط</span>
				</div>
				<div class="css-summary-card css-summary-neutral">
					<span class="css-summary-num"><?php echo (int) $total_pending; ?></span>
					<span class="css-summary-label">در انتظار بررسی</span>
				</div>
			</div>

			<?php if ( ! empty( $per_indicator ) ) : ?>
				<h2>دقت هر اندیکاتور به‌تنهایی</h2>
				<div class="css-table-wrap">
				<table class="widefat striped css-table">
					<thead><tr><th class="css-col-indicator-name">اندیکاتور</th><th>تعداد سیگنال هم‌جهت</th><th>درست</th><th>درصد دقت</th></tr></thead>
					<tbody>
						<?php foreach ( $per_indicator as $ind_id => $stat ) :
							$indicator = CSS_Indicator_Registry::get( $ind_id );
							$label     = $indicator ? $indicator->get_label() : $ind_id;
							$pct       = $stat['total'] > 0 ? round( ( $stat['correct'] / $stat['total'] ) * 100, 1 ) : 0;
							$ind_post  = class_exists( 'CSS_Indicator_CPT' ) ? CSS_Indicator_CPT::find_post_id( $ind_id ) : null;
							?>
							<tr>
								<td class="css-col-indicator-name">
									<?php if ( $ind_post ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $ind_post ) ); ?>"><?php echo esc_html( $label ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $label ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo (int) $stat['total']; ?></td>
								<td><?php echo (int) $stat['correct']; ?></td>
								<td><strong><?php echo esc_html( $pct ); ?>%</strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>

			<h2>تاریخچه اخیر سیگنال‌ها</h2>
			<?php if ( empty( $recent ) ) : ?>
				<p>هنوز هیچ سیگنال خرید/فروشی صادر نشده.</p>
			<?php else :
				$tf_labels = array( 'hourly' => 'ساعتی', 'daily' => 'روزانه', 'weekly' => 'هفتگی' );

				$out_counts = array( 'all' => count( $recent ), 'correct' => 0, 'incorrect' => 0, 'pending' => 0 );
				foreach ( $recent as $r ) {
					if ( isset( $out_counts[ $r['outcome'] ] ) ) {
						$out_counts[ $r['outcome'] ]++;
					}
				}
				?>
				<div class="css-filters">
					<div class="css-filter-tabs" id="css-accuracy-filter-tabs">
						<button type="button" class="css-tab-btn active" data-filter="all">همه (<?php echo (int) $out_counts['all']; ?>)</button>
						<button type="button" class="css-tab-btn" data-filter="correct">درست (<?php echo (int) $out_counts['correct']; ?>)</button>
						<button type="button" class="css-tab-btn" data-filter="incorrect">غلط (<?php echo (int) $out_counts['incorrect']; ?>)</button>
						<button type="button" class="css-tab-btn" data-filter="pending">در انتظار (<?php echo (int) $out_counts['pending']; ?>)</button>
					</div>
					<input type="text" id="css-accuracy-search" class="css-search-input" placeholder="جستجوی نماد ارز...">
				</div>

				<div class="css-table-wrap">
				<table class="widefat striped css-table" id="css-accuracy-table">
					<thead>
						<tr><th class="css-col-coin">نماد</th><th>سیگنال</th><th>تایم‌فریم</th><th>اندیکاتور صادرکننده</th><th>قیمت زمان سیگنال</th><th>قیمت زمان بررسی</th><th>درصد سود/ضرر</th><th>نتیجه</th><th>روند بازار آن روز</th><th class="css-col-updated">تاریخ صدور</th></tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $row ) :
							$outcome_fa = array(
								'pending'   => 'در انتظار',
								'correct'   => 'درست بود',
								'incorrect' => 'غلط بود',
							)[ $row['outcome'] ] ?? $row['outcome'];

							$pl = CSS_MA_Helper::signal_pl_percent( $row['trade_signal'], $row['price_at_signal'], $row['price_at_check'] );
							$coin_post = CSS_Coin_CPT::find_post_id( $row['coin_id'] );
							$search_key = mb_strtolower( $row['symbol'] );
							$market = CSS_Market_Trend::get_trend_for_date( substr( $row['created_at'], 0, 10 ) );
							?>
							<tr data-outcome="<?php echo esc_attr( $row['outcome'] ); ?>" data-search="<?php echo esc_attr( $search_key ); ?>">
								<td class="css-col-coin">
									<?php if ( $coin_post ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $coin_post ) ); ?>"><strong><?php echo esc_html( $row['symbol'] ); ?></strong></a>
									<?php else : ?>
										<strong><?php echo esc_html( $row['symbol'] ); ?></strong>
									<?php endif; ?>
								</td>
								<td><span class="css-badge css-badge-<?php echo esc_attr( $row['trade_signal'] ); ?>"><?php echo esc_html( $this->signal_fa( $row['trade_signal'] ) ); ?></span></td>
								<td><?php echo esc_html( $tf_labels[ $row['timeframe'] ] ?? $row['timeframe'] ); ?></td>
								<td class="css-details"><?php echo esc_html( $row['source_indicators'] ?: '—' ); ?></td>
								<td><?php echo esc_html( number_format( (float) $row['price_at_signal'], 4 ) ); ?></td>
								<td><?php echo null !== $row['price_at_check'] ? esc_html( number_format( (float) $row['price_at_check'], 4 ) ) : '—'; ?></td>
								<td>
									<?php if ( null === $pl ) : ?>
										—
									<?php else : ?>
										<strong style="color:<?php echo $pl >= 0 ? '#15803d' : '#b91c1c'; ?>;"><?php echo ( $pl >= 0 ? '+' : '' ) . esc_html( round( $pl, 2 ) ); ?>%</strong>
									<?php endif; ?>
								</td>
								<td><span class="css-outcome css-outcome-<?php echo esc_attr( $row['outcome'] ); ?>"><?php echo esc_html( $outcome_fa ); ?></span></td>
								<td>
									<?php if ( $market ) : ?>
										<span style="color:<?php echo 'bullish' === $market['trend'] ? '#15803d' : ( 'bearish' === $market['trend'] ? '#b91c1c' : '#666' ); ?>;">
											<?php echo esc_html( CSS_Market_Trend::trend_label( $market['trend'] ) ); ?>
										</span>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td class="css-col-updated"><?php echo esc_html( $row['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ==================================================================
	// صفحه تاریخچه اسکن — برای اطمینان از اینکه اسکن واقعاً در حال اجراست
	// ==================================================================
	public function render_scan_log_page(): void {
		$log = array_reverse( get_option( 'css_scan_log', array() ) );
		$next_scan = wp_next_scheduled( 'css_start_scan' );
		$next_worker = wp_next_scheduled( 'css_queue_worker' );
		?>
		<div class="wrap css-wrap">
			<h1>تاریخچه اسکن</h1>

			<div class="css-summary-cards">
				<div class="css-summary-card">
					<span class="css-summary-num" style="font-size:14px;"><?php echo $next_scan ? esc_html( wp_date( 'Y-m-d H:i:s', $next_scan ) ) : 'زمان‌بندی نشده'; ?></span>
					<span class="css-summary-label">زمان شروع دور بعدی اسکن</span>
				</div>
				<div class="css-summary-card">
					<span class="css-summary-num" style="font-size:14px;"><?php echo $next_worker ? esc_html( wp_date( 'Y-m-d H:i:s', $next_worker ) ) : 'زمان‌بندی نشده'; ?></span>
					<span class="css-summary-label">پردازش دسته بعدی صف</span>
				</div>
			</div>
			<p class="description">اگر «زمان‌بندی نشده» می‌بینید، یعنی Cron وردپرس فعال نیست — معمولاً یعنی سایت شما بازدید کافی ندارد تا wp-cron.php خودکار اجرا شود، یا یک افزونه/تنظیم سرور آن را غیرفعال کرده. در این صورت باید یک Cron واقعی سرور (crontab) برای فراخوانی wp-cron.php تنظیم کنید.</p>

			<?php if ( empty( $log ) ) : ?>
				<p>هنوز هیچ اسکنی (حتی ناموفق) ثبت نشده. یعنی یا هنوز هیچ‌وقت اسکن اجرا نشده، یا این نسخه از افزونه تازه نصب شده.</p>
			<?php else : ?>
				<div class="css-table-wrap">
				<table class="widefat striped css-table">
					<thead>
						<tr><th>شروع</th><th>پایان</th><th>مدت</th><th>کل ارزها</th><th>ذخیره‌شده</th><th>وضعیت</th><th>یادداشت</th></tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) :
							$status_fa = array(
								'running'   => 'در حال اجرا',
								'completed' => 'کامل شد',
								'failed'    => 'ناموفق',
							)[ $entry['status'] ?? '' ] ?? ( $entry['status'] ?? '—' );

							$duration = '—';
							if ( ! empty( $entry['started_at'] ) && ! empty( $entry['finished_at'] ) ) {
								$diff = strtotime( $entry['finished_at'] ) - strtotime( $entry['started_at'] );
								if ( $diff >= 0 ) {
									$duration = gmdate( 'H:i:s', $diff );
								}
							}
							?>
							<tr>
								<td><?php echo esc_html( $entry['started_at'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $entry['finished_at'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $duration ); ?></td>
								<td><?php echo (int) ( $entry['total'] ?? 0 ); ?></td>
								<td><?php echo (int) ( $entry['saved'] ?? 0 ); ?></td>
								<td>
									<span class="css-outcome css-outcome-<?php echo 'completed' === ( $entry['status'] ?? '' ) ? 'correct' : ( 'failed' === ( $entry['status'] ?? '' ) ? 'incorrect' : 'pending' ); ?>">
										<?php echo esc_html( $status_fa ); ?>
									</span>
								</td>
								<td class="css-details"><?php echo esc_html( $entry['note'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ==================================================================
	// صفحه روند بازار (مارکت کپ کل + دامیننس بیت‌کوین)
	// ==================================================================
	public function render_market_trend_page(): void {
		$log   = array_reverse( CSS_Market_Trend::get_log(), true );
		$today = CSS_Market_Trend::get_today();
		?>
		<div class="wrap css-wrap">
			<h1>روند بازار (بر مبنای مارکت کپ کل — TOTAL)</h1>
			<p class="description">این صفحه با هر بار اسکن (هر ساعت) به‌روز می‌شود — مقدار هر روز، آخرین خوانش همان روز است. این اطلاعات کنار سیگنال‌ها و تقویم اندیکاتورها هم نمایش داده می‌شود تا بشود فهمید هر اندیکاتور در چه روندی بهتر عمل کرده.</p>

			<?php if ( $today ) : ?>
				<div class="css-summary-cards">
					<div class="css-summary-card">
						<span class="css-summary-num" style="color:<?php echo 'bullish' === $today['trend'] ? '#15803d' : ( 'bearish' === $today['trend'] ? '#b91c1c' : '#555' ); ?>">
							<?php echo esc_html( CSS_Market_Trend::trend_label( $today['trend'] ) ); ?>
						</span>
						<span class="css-summary-label">روند بازار امروز</span>
					</div>
					<div class="css-summary-card">
						<span class="css-summary-num" style="font-size:16px;"><?php echo esc_html( number_format( $today['total_market_cap'] / 1e9, 1 ) ); ?>B $</span>
						<span class="css-summary-label">مارکت کپ کل بازار</span>
					</div>
					<div class="css-summary-card">
						<span class="css-summary-num" style="color:<?php echo $today['change_pct_24h'] >= 0 ? '#15803d' : '#b91c1c'; ?>">
							<?php echo ( $today['change_pct_24h'] >= 0 ? '+' : '' ) . esc_html( round( $today['change_pct_24h'], 2 ) ); ?>%
						</span>
						<span class="css-summary-label">تغییر ۲۴ ساعته مارکت کپ کل</span>
					</div>
					<div class="css-summary-card">
						<span class="css-summary-num"><?php echo esc_html( round( $today['btc_dominance'], 1 ) ); ?>%</span>
						<span class="css-summary-label">دامیننس بیت‌کوین</span>
					</div>
				</div>
			<?php else : ?>
				<p>هنوز داده‌ای ثبت نشده — بعد از اولین اسکن، این بخش پر می‌شود.</p>
			<?php endif; ?>

			<?php if ( ! empty( $log ) ) : ?>
				<h2>تاریخچه روزانه</h2>
				<div class="css-table-wrap">
				<table class="widefat striped css-table">
					<thead><tr><th>تاریخ</th><th>روند</th><th>مارکت کپ کل</th><th>تغییر ۲۴ ساعته</th><th>دامیننس بیت‌کوین</th></tr></thead>
					<tbody>
						<?php foreach ( $log as $date => $entry ) :
							$trend_color = 'bullish' === $entry['trend'] ? '#15803d' : ( 'bearish' === $entry['trend'] ? '#b91c1c' : '#555' );
							?>
							<tr>
								<td><?php echo esc_html( $date ); ?></td>
								<td><strong style="color:<?php echo esc_attr( $trend_color ); ?>;"><?php echo esc_html( CSS_Market_Trend::trend_label( $entry['trend'] ) ); ?></strong></td>
								<td><?php echo esc_html( number_format( $entry['total_market_cap'] / 1e9, 1 ) ); ?>B $</td>
								<td style="color:<?php echo $entry['change_pct_24h'] >= 0 ? '#15803d' : '#b91c1c'; ?>;"><?php echo ( $entry['change_pct_24h'] >= 0 ? '+' : '' ) . esc_html( round( $entry['change_pct_24h'], 2 ) ); ?>%</td>
								<td><?php echo esc_html( round( $entry['btc_dominance'], 1 ) ); ?>%</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function signal_fa( string $signal ): string {
		return array(
			'buy'     => 'خرید',
			'sell'    => 'فروش',
			'neutral' => 'خنثی',
		)[ $signal ] ?? $signal;
	}

	// ==================================================================
	// صفحه تنظیمات
	// ==================================================================
	public function register_settings(): void {
		register_setting( 'css_settings_group', 'css_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( array $input ): array {
		$current = get_option( 'css_settings', array() );

		$output                     = $current;
		$output['rank_start'] = max( 1, min( 5000, (int) ( $input['rank_start'] ?? 1 ) ) );
		$output['rank_end']   = max( $output['rank_start'], min( 5000, (int) ( $input['rank_end'] ?? 100 ) ) );
		$output['auto_scan_enabled'] = ! empty( $input['auto_scan_enabled'] );
		$output['combination_mode'] = in_array( $input['combination_mode'] ?? '', array( 'any', 'all_agree', 'majority' ), true )
			? $input['combination_mode'] : 'majority';
		$output['history_days']     = max( 5, min( 365, (int) ( $input['history_days'] ?? 30 ) ) );
		$output['data_provider'] = in_array( $input['data_provider'] ?? '', array( 'coingecko', 'binance' ), true ) ? $input['data_provider'] : 'coingecko';
		$output['coingecko_api_key'] = sanitize_text_field( $input['coingecko_api_key'] ?? '' );
		$output['api_base_url']      = esc_url_raw( trim( $input['api_base_url'] ?? '' ) );
		$output['binance_base_url']  = esc_url_raw( trim( $input['binance_base_url'] ?? '' ) );
		$output['binance_worker_token'] = sanitize_text_field( trim( $input['binance_worker_token'] ?? '' ) );
		$quote = strtoupper( preg_replace( '/[^A-Z0-9]/', '', (string) ( $input['binance_quote_asset'] ?? 'USDT' ) ) );
		$output['binance_quote_asset'] = $quote ?: 'USDT';
		$output['request_delay_ms'] = max( 500, min( 10000, (int) ( $input['request_delay_ms'] ?? 2500 ) ) );
		$output['active_timeframes'] = array_values( array_intersect(
			(array) ( $input['active_timeframes'] ?? array( 'daily' ) ),
			array( 'hourly', 'daily', 'weekly' )
		) );
		if ( empty( $output['active_timeframes'] ) ) {
			$output['active_timeframes'] = array( 'daily' );
		}
		$output['timeframe']        = $output['active_timeframes'][0];
		$output['evaluation_hours'] = max( 1, min( 720, (int) ( $input['evaluation_hours'] ?? 24 ) ) );
		$output['bullish_threshold'] = (float) ( $input['bullish_threshold'] ?? 2 );
		$output['bearish_threshold'] = (float) ( $input['bearish_threshold'] ?? -2 );
		$output['active_indicators'] = array_map( 'sanitize_key', $input['active_indicators'] ?? array() );

		$raw_blacklist = (string) ( $input['blacklist_coin_ids'] ?? '' );
		$blacklist     = array_values( array_unique( array_filter( array_map(
			fn( $s ) => sanitize_key( trim( $s ) ),
			preg_split( '/[\r\n,]+/', $raw_blacklist )
		) ) ) );
		$output['blacklist_coin_ids'] = $blacklist;

		// اگر ارزی تازه به لیست سیاه اضافه شده، سیگنال‌های فعلاً ثبت‌شده‌اش را از جدول پاک کن
		$previously_blacklisted = $current['blacklist_coin_ids'] ?? array();
		$newly_blacklisted      = array_diff( $blacklist, $previously_blacklisted );
		if ( ! empty( $newly_blacklisted ) ) {
			global $wpdb;
			$table        = $wpdb->prefix . CSS_TABLE_SIGNALS;
			$placeholders = implode( ',', array_fill( 0, count( $newly_blacklisted ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE coin_id IN ({$placeholders})", $newly_blacklisted ) ); // phpcs:ignore
		}

		foreach ( CSS_Indicator_Registry::get_all() as $id => $indicator ) {
			foreach ( $indicator->get_default_settings() as $key => $default_val ) {
				$posted = $input['indicator_settings'][ $id ][ $key ] ?? $default_val;
				$output['indicator_settings'][ $id ][ $key ] = is_numeric( $default_val ) ? (float) $posted : sanitize_text_field( $posted );
			}
		}

		return $output;
	}

	public function render_settings_page(): void {
		$settings = get_option( 'css_settings', array() );
		?>
		<div class="wrap css-wrap">
			<h1>تنظیمات اسکنر سیگنال</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'css_settings_group' ); ?>

				<h2>تنظیمات عمومی</h2>
				<table class="form-table">
					<tr>
						<th><label for="auto_scan_enabled">اسکن خودکار</label></th>
						<td>
							<label>
								<input type="checkbox" id="auto_scan_enabled" name="css_settings[auto_scan_enabled]" value="1" <?php checked( ! empty( $settings['auto_scan_enabled'] ) ); ?>>
								فعال باشد
							</label>
							<p class="description">اگر خاموش کنید، اسکن‌های خودکار زمان‌بندی‌شده (هر ساعت) دیگر اجرا نمی‌شوند — ولی همچنان می‌توانید هر وقت خواستید از دکمه «اسکن الان» در داشبورد به‌صورت دستی اسکن بگیرید.</p>
						</td>
					</tr>
					<tr>
						<th><label for="rank_start">بازه رتبه ارزها (بر اساس Provider فعال)</label></th>
						<td>
							از رتبه
							<input type="number" id="rank_start" name="css_settings[rank_start]" value="<?php echo esc_attr( $settings['rank_start'] ?? 1 ); ?>" min="1" max="5000" style="width:90px;">
							تا رتبه
							<input type="number" id="rank_end" name="css_settings[rank_end]" value="<?php echo esc_attr( $settings['rank_end'] ?? 100 ); ?>" min="1" max="5000" style="width:90px;">
							<p class="description">
								مثال: برای اسکن فقط ارزهای رتبه ۳۰۱ تا ۴۰۰، «از رتبه» را ۳۰۱ و «تا رتبه» را ۴۰۰ بگذارید — این‌جوری
								ارزهای ۱ تا ۳۰۰ که قبلاً اسکن کرده بودید هم پاک نمی‌شوند و همچنان تو داشبورد و پست‌تایپ‌ها می‌مانند
								(چون هر اسکن جدید فقط همین بازه را به‌روزرسانی می‌کند، بقیه دست‌نخورده باقی می‌مانند مگر خودتان
								دکمه «پاکسازی و یکپارچه‌سازی» را بزنید). یعنی می‌توانید هر بار بازه رو عوض کنید و اسکن بگیرید تا
								کم‌کم پوشش کل بازار رو تجمعی بسازید.
								⚠️ هرچه این بازه بزرگ‌تر باشد، همون اسکن بزرگ‌تر زمان بیشتری می‌برد (هر ارز حدود ۲.۵ ثانیه، طبق
								تنظیم «فاصله بین درخواست‌ها»). برای بازه‌های بزرگ، تکه‌تکه اسکن کردن (مثلاً هر بار ۱۰۰ تا) و بررسی
								«تاریخچه اسکن» توصیه می‌شود.
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="blacklist_coin_ids">لیست سیاه ارزها (حذف از اسکن)</label></th>
						<td>
							<textarea id="blacklist_coin_ids" name="css_settings[blacklist_coin_ids]" rows="4" style="width:100%;max-width:480px;font-family:monospace;" placeholder="tether&#10;usd-coin&#10;dai"><?php echo esc_textarea( implode( "\n", $settings['blacklist_coin_ids'] ?? array() ) ); ?></textarea>
							<br>
							<button type="button" class="button" id="css-fill-stablecoins">+ افزودن استیبل‌کوین‌های رایج</button>
							<p class="description">
								شناسه/نماد ارز Provider فعال را در هر خط (یا با کاما جدا) بنویسید — نه نماد، بلکه همان id که در آدرس شناسه بازار Provider می‌آید (مثلاً برای تتر: <code>tether</code>).
								ارزهای این لیست از اسکن حذف می‌شوند و دیگر هیچ کالی برایشان زده نمی‌شود (نه برای قیمت، نه OHLC) — برای جلوگیری از مصرف بی‌جهت کریدیت روی استیبل‌کوین‌ها که معمولاً سیگنال معناداری ندارند، مناسب است.
								با ذخیره این تنظیمات، سیگنال‌های فعلاً ثبت‌شده همین ارزها هم از جدول پاک می‌شوند.
							</p>
						</td>
					</tr>
					<script>
					document.getElementById('css-fill-stablecoins')?.addEventListener('click', function () {
						var ta = document.getElementById('blacklist_coin_ids');
						var provider = document.getElementById('data_provider')?.value || 'coingecko';
						var common = provider === 'binance' ? ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'DAIUSDT', 'USDPUSDT', 'PYUSDUSDT'] : ['tether', 'usd-coin', 'dai', 'binance-usd', 'true-usd', 'usdd', 'first-digital-usd', 'paypal-usd', 'ethena-usde', 'frax', 'gemini-dollar', 'paxos-standard'];
						var existing = ta.value.split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
						var merged = existing.concat(common.filter(function (c) { return existing.indexOf(c) === -1; }));
						ta.value = merged.join('\n');
					});
					</script>
					<tr>
						<th><label for="history_days">بازه تاریخچه قیمت (روز)</label></th>
						<td><input type="number" id="history_days" name="css_settings[history_days]" value="<?php echo esc_attr( $settings['history_days'] ?? 30 ); ?>" min="5" max="365">
						<p class="description">برای محاسبه دقیق اندیکاتورهایی مثل MACD حداقل ۳۰-۶۰ روز پیشنهاد می‌شود.</p></td>
					</tr>
					<tr>
						<th><label for="combination_mode">حالت ترکیب سیگنال‌ها</label></th>
						<td>
							<select id="combination_mode" name="css_settings[combination_mode]">
								<option value="majority" <?php selected( $settings['combination_mode'] ?? '', 'majority' ); ?>>اکثریت اندیکاتورها</option>
								<option value="all_agree" <?php selected( $settings['combination_mode'] ?? '', 'all_agree' ); ?>>همه اندیکاتورها موافق باشند</option>
								<option value="any" <?php selected( $settings['combination_mode'] ?? '', 'any' ); ?>>حداقل یکی سیگنال بدهد (بدون تناقض)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label>تایم‌فریم‌های فعال</label></th>
						<td>
							<?php
							$tf_labels = array( 'hourly' => 'ساعتی', 'daily' => 'روزانه', 'weekly' => 'هفتگی' );
							$active_tf = $settings['active_timeframes'] ?? array( 'daily' );
							foreach ( $tf_labels as $tf_key => $tf_label ) :
								?>
								<label style="margin-left:16px; display:inline-flex; align-items:center; gap:5px;">
									<input type="checkbox" name="css_settings[active_timeframes][]" value="<?php echo esc_attr( $tf_key ); ?>" <?php checked( in_array( $tf_key, $active_tf, true ) ); ?>>
									<?php echo esc_html( $tf_label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">⚠️ هر تایم‌فریم اضافه یعنی یک بار دیگر دیتای هر ارز جداگانه گرفته می‌شود — یعنی با ۳ تایم‌فریم فعال، اسکن تقریباً ۳ برابر کندتر می‌شود و درخواست بیشتری مصرف می‌کند. برای «ساعتی» بازه تاریخچه را کم نگه دارید، برای «هفتگی» حداقل ۶ ماه پیشنهاد می‌شود. کاربر نهایی می‌تواند در شورت‌کد بین این تایم‌فریم‌ها جابه‌جا شود.</p>
						</td>
					</tr>
					<tr>
						<th><label for="evaluation_hours">مهلت سنجش دقت سیگنال (ساعت)</label></th>
						<td><input type="number" id="evaluation_hours" name="css_settings[evaluation_hours]" value="<?php echo esc_attr( $settings['evaluation_hours'] ?? 24 ); ?>" min="1" max="720">
						<p class="description">بعد از صدور هر سیگنال خرید/فروش، افزونه بعد از این‌همه ساعت قیمت را دوباره چک می‌کند تا ببیند سیگنال درست بوده یا نه (نتیجه در صفحه «دقت سیگنال‌ها» نمایش داده می‌شود).</p></td>
					</tr>
					<tr>
						<th><label for="bullish_threshold">آستانه روند صعودی/نزولی بازار (٪)</label></th>
						<td>
							اگر تغییر ۲۴ ساعته مارکت کپ کل بیشتر یا مساوی
							<input type="number" step="0.1" id="bullish_threshold" name="css_settings[bullish_threshold]" value="<?php echo esc_attr( $settings['bullish_threshold'] ?? 2 ); ?>" style="width:70px;">
							٪ باشد، «صعودی»؛ اگر کمتر یا مساوی
							<input type="number" step="0.1" id="bearish_threshold" name="css_settings[bearish_threshold]" value="<?php echo esc_attr( $settings['bearish_threshold'] ?? -2 ); ?>" style="width:70px;">
							٪ باشد، «نزولی»؛ در غیر این صورت «خنثی» در نظر گرفته می‌شود.
						</td>
					</tr>
					<tr>
						<th><label for="data_provider">منبع داده بازار</label></th>
						<td>
							<select id="data_provider" name="css_settings[data_provider]">
								<option value="coingecko" <?php selected( $settings['data_provider'] ?? 'coingecko', 'coingecko' ); ?>>CoinGecko (از طریق Worker / API)</option>
								<option value="binance" <?php selected( $settings['data_provider'] ?? 'coingecko', 'binance' ); ?>>Binance Spot</option>
							</select>
							<p class="description">فقط Provider انتخاب‌شده در Scanner استفاده می‌شود. وقتی Binance فعال باشد، CoinGecko برای دریافت داده اسکن صدا زده نمی‌شود.</p>
						</td>
					</tr>
					<tr class="css-provider-row css-provider-coingecko">
						<th><label for="coingecko_api_key">کلید API کوین‌گکو (اختیاری)</label></th>
						<td><input type="text" id="coingecko_api_key" style="width:350px" name="css_settings[coingecko_api_key]" value="<?php echo esc_attr( $settings['coingecko_api_key'] ?? '' ); ?>">
						<p class="description">فقط زمانی استفاده می‌شود که Provider روی CoinGecko باشد.</p></td>
					</tr>
					<tr class="css-provider-row css-provider-coingecko">
						<th><label for="api_base_url">آدرس پایه CoinGecko / Worker</label></th>
						<td><input type="text" id="api_base_url" style="width:350px" placeholder="https://...workers.dev/api/v3" name="css_settings[api_base_url]" value="<?php echo esc_attr( $settings['api_base_url'] ?? '' ); ?>">
						<p class="description">این فیلد فقط برای CoinGecko است و مسیر فعلی Worker شما را حفظ می‌کند.</p></td>
					</tr>
					<tr class="css-provider-row css-provider-binance">
						<th><label for="binance_quote_asset">Binance Quote Asset</label></th>
						<td><input type="text" id="binance_quote_asset" style="width:120px" name="css_settings[binance_quote_asset]" value="<?php echo esc_attr( $settings['binance_quote_asset'] ?? 'USDT' ); ?>" maxlength="10">
						<p class="description">فعلاً پیشنهاد می‌شود USDT باشد. رتبه‌بندی Binance بر اساس حجم ۲۴ ساعته Quote Asset انجام می‌شود.</p></td>
					</tr>
					<tr class="css-provider-row css-provider-binance">
						<th><label for="binance_base_url">آدرس Supabase Binance Gateway</label></th>
						<td><input type="text" id="binance_base_url" style="width:350px" placeholder="https://YOUR_PROJECT.supabase.co/functions/v1/market-data" name="css_settings[binance_base_url]" value="<?php echo esc_attr( $settings['binance_base_url'] ?? '' ); ?>">
						<p class="description">آدرس کامل Supabase Edge Function را وارد کنید؛ افزونه فقط به Gateway وصل می‌شود و Gateway داده را از Binance می‌گیرد.</p></td>
					</tr>
					<tr class="css-provider-row css-provider-binance">
						<th><label for="binance_worker_token">Supabase Gateway Token</label></th>
						<td><input type="password" id="binance_worker_token" style="width:350px" autocomplete="new-password" name="css_settings[binance_worker_token]" value="<?php echo esc_attr( $settings['binance_worker_token'] ?? '' ); ?>">
						<p class="description">اختیاری است؛ اگر در Edge Function متغیر CSS_BINANCE_GATEWAY_TOKEN تنظیم کنید، همین مقدار را اینجا قرار دهید.</p></td>
					</tr>
					<tr>
						<th><label for="request_delay_ms">فاصله بین درخواست‌ها (میلی‌ثانیه)</label></th>
						<td><input type="number" id="request_delay_ms" name="css_settings[request_delay_ms]" value="<?php echo esc_attr( $settings['request_delay_ms'] ?? 2500 ); ?>" min="500" max="10000" step="100">
						<p class="description">برای جلوگیری از خطای ۴۲۹ (Rate Limit) از Provider فعال جلوگیری شود. اگر کلید API پولی دارید می‌توانید کمترش کنید.</p></td>
					</tr>
				</table>

				<h2>اندیکاتورهای فعال</h2>
				<p class="description">⚠️ اندیکاتور SuperTrend به داده High/Low نیاز دارد و ممکن است برای هر ارز یک درخواست اضافی به Provider فعال بزند — یعنی با فعال‌بودنش، اسکن کندتر می‌شود و احتمال برخورد به Rate Limit بیشتر می‌شود. اگر با ۴۲۹ مواجه شدید، عدد «فاصله بین درخواست‌ها» را بالاتر ببرید.</p>

				<div class="css-indicator-grid">
					<?php foreach ( CSS_Indicator_Registry::get_all() as $id => $indicator ) :
						$is_active = in_array( $id, $settings['active_indicators'] ?? array(), true );
						?>
						<div class="css-indicator-card <?php echo $is_active ? 'is-active' : ''; ?>">
							<div class="css-indicator-card-header">
								<label class="css-indicator-toggle">
									<input type="checkbox" name="css_settings[active_indicators][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $is_active ); ?>>
									<span class="css-indicator-title"><?php echo esc_html( $indicator->get_label() ); ?></span>
								</label>
								<?php if ( $indicator->get_requires_ohlc() ) : ?>
									<span class="css-indicator-flag">نیاز به High/Low</span>
								<?php endif; ?>
							</div>
							<div class="css-indicator-card-body">
								<?php foreach ( $indicator->get_settings_fields() as $field_key => $field ) :
									$val  = $settings['indicator_settings'][ $id ][ $field_key ] ?? '';
									$name = "css_settings[indicator_settings][{$id}][{$field_key}]";
									?>
									<div class="css-indicator-field">
										<label><?php echo esc_html( $field['label'] ); ?></label>
										<?php if ( 'select' === ( $field['type'] ?? '' ) ) : ?>
											<select name="<?php echo esc_attr( $name ); ?>">
												<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
													<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $val, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<input type="number" step="any" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $val ); ?>">
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<div class="css-indicator-card css-indicator-card-add">
						<div class="css-indicator-add-icon">+</div>
						<div class="css-indicator-add-title">افزودن اندیکاتور جدید</div>
						<p class="css-indicator-add-desc">
							برای افزودن یک اندیکاتور سفارشی، فایل
							<code>includes/class-indicator-base.php</code>
							را ببینید — یک الگوی آماده دارد. کافیست یک کلاس جدید در
							<code>includes/indicators/</code>
							بسازید و در
							<code>class-indicator-registry.php</code>
							یک خط ثبتش کنید؛ بعد از آن به‌صورت خودکار همین‌جا، در پنل کاربری و در فیلترها ظاهر می‌شود.
						</p>
					</div>
				</div>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<h2>راهنمای شورت‌کد جدول ساده</h2>
			<p>برای نمایش نتایج در هر صفحه یا نوشته از سایت از شورت‌کد زیر استفاده کنید:</p>
			<code>[crypto_signals]</code> — نمایش همه ارزها<br>
			<code>[crypto_signals signal="buy"]</code> — فقط سیگنال‌های خرید<br>
			<code>[crypto_signals signal="sell" limit="20"]</code> — ۲۰ سیگنال فروش برتر

			<h2>راهنمای شورت‌کد پنل کاربری (تب فیلتر + جستجوی زنده)</h2>
			<p>برای صفحه پروفایل یا هر جای دیگری که ظاهر شیک‌تر و تعاملی‌تر می‌خواهید:</p>
			<code>[crypto_user_panel]</code> — پنل کامل با فیلتر همه/خرید/فروش/خنثی<br>
			<code>[crypto_user_panel default_filter="buy"]</code> — پنل با فیلتر پیش‌فرض روی خرید<br>
			<code>[crypto_user_panel require_login="yes"]</code> — فقط برای کاربران واردشده نمایش داده شود<br>
			<code>[crypto_user_panel limit="30"]</code> — فقط ۳۰ ارز برتر
		</div>
		<?php
	}
}

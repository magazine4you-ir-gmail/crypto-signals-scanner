<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * شورت‌کد [crypto_signals_table]
 * جایگزین کامل پنل قبلی — دقیقاً همان اطلاعاتی که در جدول داشبورد پیشخوان
 * («اسکنر سیگنال ارز دیجیتال») نمایش داده می‌شود، بدون بخش‌های مخصوص مدیریت
 * (دکمه اسکن، پاکسازی، تنظیمات). عمداً هیچ AJAX ای برای فیلتر کردن استفاده
 * نمی‌شود: همه ردیف‌ها یک‌بار در همان بار اول توسط سرور رندر می‌شوند و فیلتر
 * تب/تایم‌فریم/جستجو صرفاً با نمایش/مخفی کردن ردیف‌ها در مرورگر انجام می‌شود
 * (دقیقاً همان روشی که جدول پیشخوان از او استفاده می‌کند). این یعنی هیچ رفت‌وبرگشت
 * شبکه‌ای در کار نیست که بخواهد محتوای درست را با یک پاسخ ناقص جایگزین کند.
 *
 * پارامترها:
 *   default_filter  فیلتر اولیه: all | buy | sell | neutral (پیش‌فرض all)
 *   require_login   اگر "yes" باشد فقط به کاربران واردشده نمایش داده می‌شود (پیش‌فرض no)
 */
class CSS_Signals_Table_Shortcode {

	public function __construct() {
		add_shortcode( 'crypto_signals_table', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		wp_register_style( 'css-signals-table', CSS_PLUGIN_URL . 'assets/css/signals-table.css', array(), css_asset_ver( 'assets/css/signals-table.css' ) );
		wp_register_script( 'css-signals-table', CSS_PLUGIN_URL . 'assets/js/signals-table.js', array(), css_asset_ver( 'assets/js/signals-table.js' ), true );
		wp_localize_script( 'css-signals-table', 'CSS_ST_Data', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( CSS_Frontend_History::NONCE_ACTION ),
		) );
	}

	private function get_timeframe_labels(): array {
		return array( 'hourly' => 'ساعتی', 'daily' => 'روزانه', 'weekly' => 'هفتگی' );
	}

	private function get_signal_label( string $signal ): string {
		return array( 'buy' => 'خرید', 'sell' => 'فروش', 'neutral' => 'خنثی' )[ $signal ] ?? $signal;
	}

	public function render( $atts ): string {
		$atts = shortcode_atts( array(
			'default_filter' => 'all',
			'require_login'  => 'no',
			'per_page'       => 100,
		), $atts, 'crypto_signals_table' );
		$per_page = max( 10, (int) $atts['per_page'] );

		if ( 'yes' === strtolower( $atts['require_login'] ) && ! is_user_logged_in() ) {
			return '<p class="css-empty">برای مشاهده این بخش ابتدا وارد حساب کاربری خود شوید.</p>';
		}

		wp_enqueue_style( 'css-signals-table' );
		wp_enqueue_script( 'css-signals-table' );

		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_SIGNALS;

		$plugin_settings = get_option( 'css_settings', array() );
		$active_tf       = ! empty( $plugin_settings['active_timeframes'] ) ? (array) $plugin_settings['active_timeframes'] : array( 'daily' );
		$default_tf      = $active_tf[0];
		$tf_labels       = $this->get_timeframe_labels();

		$active_indicator_ids = $plugin_settings['active_indicators'] ?? array();
		$indicator_labels     = array();
		foreach ( $active_indicator_ids as $ind_id ) {
			$indicator = CSS_Indicator_Registry::get( $ind_id );
			if ( $indicator ) {
				$indicator_labels[ $ind_id ] = $indicator->get_label();
			}
		}

		$default_filter = in_array( $atts['default_filter'], array( 'all', 'buy', 'sell', 'neutral' ), true )
			? $atts['default_filter'] : 'all';

		// دقیقاً مثل جدول پیشخوان: همه ردیف‌ها (همه تایم‌فریم‌ها) یک‌جا واکشی می‌شوند
		$rows   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY market_cap_rank ASC", ARRAY_A );
		$status = get_option( 'css_scan_status', array() );

		$counts = array( 'buy' => 0, 'sell' => 0, 'neutral' => 0 );
		foreach ( $rows as $r ) {
			if ( $r['timeframe'] === $default_tf && isset( $counts[ $r['trade_signal'] ] ) ) {
				$counts[ $r['trade_signal'] ]++;
			}
		}
		$count_default_tf = count( array_filter( $rows, fn( $r ) => $r['timeframe'] === $default_tf ) );

		// آیکون ارزها را یک‌جا (با یک کوئری) از پست‌تایپ ارزها می‌گیریم — نه به‌ازای هر ردیف،
		// تا هیچ کوئری/کال اضافه‌ای به ازای هر سطر جدول زده نشود.
		$icons = array();
		$coin_ids = array_unique( array_column( $rows, 'coin_id' ) );
		if ( ! empty( $coin_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $coin_ids ), '%s' ) );
			$icon_rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT cid.meta_value AS coin_id, img.meta_value AS image
					 FROM {$wpdb->postmeta} cid
					 INNER JOIN {$wpdb->postmeta} img ON img.post_id = cid.post_id AND img.meta_key = '_css_md_image'
					 WHERE cid.meta_key = '_css_coin_id' AND cid.meta_value IN ({$placeholders})", // phpcs:ignore
					$coin_ids
				),
				ARRAY_A
			);
			foreach ( $icon_rows as $ir ) {
				$icons[ $ir['coin_id'] ] = $ir['image'];
			}
		}

		$panel_id = 'css-st-' . wp_unique_id();
		$acc_stats = class_exists( 'CSS_Accuracy_Stats' ) ? CSS_Accuracy_Stats::get_summary() : null;

		ob_start();
		?>
		<div class="css-st-panel" id="<?php echo esc_attr( $panel_id ); ?>">

			<div class="css-st-main-tabs">
				<button type="button" class="css-st-main-tab active" data-main-target="list">لیست سیگنال‌ها</button>
				<button type="button" class="css-st-main-tab" data-main-target="accuracy">تاریخچه دقت سیگنال</button>
				<button type="button" class="css-st-main-tab" data-main-target="trend">روند بازار</button>
			</div>

			<div class="css-st-main-panel" data-main-panel="list">

			<?php if ( ! empty( $status['finished_at'] ) ) : ?>
				<div class="css-st-meta">🕒 آخرین بروزرسانی اسکنر: <?php echo esc_html( $status['finished_at'] ); ?></div>
			<?php endif; ?>

			<?php if ( $acc_stats ) : ?>
				<h4 class="css-st-section-title">نتیجه تست سیستم</h4>
				<?php echo CSS_Accuracy_Stats::render_compact_summary_html( $acc_stats ); // phpcs:ignore ?>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p class="css-st-empty-state">هنوز هیچ سیگنالی ثبت نشده.</p>
			<?php else : ?>

				<div class="css-st-summary">
					<button type="button" class="css-st-summary-card css-st-summary-all" data-filter-target="all">
						<span class="css-st-summary-num"><?php echo (int) $count_default_tf; ?></span>
						<span class="css-st-summary-label">همه ارزها (<?php echo esc_html( $tf_labels[ $default_tf ] ?? $default_tf ); ?>)</span>
					</button>
					<button type="button" class="css-st-summary-card css-st-summary-buy" data-filter-target="buy">
						<span class="css-st-summary-num"><?php echo (int) $counts['buy']; ?></span>
						<span class="css-st-summary-label">سیگنال خرید</span>
					</button>
					<button type="button" class="css-st-summary-card css-st-summary-sell" data-filter-target="sell">
						<span class="css-st-summary-num"><?php echo (int) $counts['sell']; ?></span>
						<span class="css-st-summary-label">سیگنال فروش</span>
					</button>
					<button type="button" class="css-st-summary-card css-st-summary-neutral" data-filter-target="neutral">
						<span class="css-st-summary-num"><?php echo (int) $counts['neutral']; ?></span>
						<span class="css-st-summary-label">خنثی</span>
					</button>
				</div>

				<div class="css-st-toolbar">
					<div class="css-st-tabs" id="<?php echo esc_attr( $panel_id ); ?>-tabs">
						<button type="button" class="css-st-tab <?php echo 'all' === $default_filter ? 'active' : ''; ?>" data-filter="all">همه</button>
						<button type="button" class="css-st-tab <?php echo 'buy' === $default_filter ? 'active' : ''; ?>" data-filter="buy">خرید</button>
						<button type="button" class="css-st-tab <?php echo 'sell' === $default_filter ? 'active' : ''; ?>" data-filter="sell">فروش</button>
						<button type="button" class="css-st-tab <?php echo 'neutral' === $default_filter ? 'active' : ''; ?>" data-filter="neutral">خنثی</button>
					</div>
					<?php if ( count( $active_tf ) > 1 ) : ?>
						<select class="css-st-select" data-role="timeframe">
							<?php foreach ( $active_tf as $tf ) : ?>
								<option value="<?php echo esc_attr( $tf ); ?>" <?php selected( $tf, $default_tf ); ?>><?php echo esc_html( $tf_labels[ $tf ] ?? $tf ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<input type="text" class="css-st-search" placeholder="جستجوی نماد یا نام ارز...">
				</div>

				<div class="css-st-table-wrap">
				<table class="css-st-table" data-default-timeframe="<?php echo esc_attr( $default_tf ); ?>" data-default-filter="<?php echo esc_attr( $default_filter ); ?>" data-per-page="<?php echo esc_attr( $per_page ); ?>">
					<thead>
						<tr>
							<th class="css-st-col-rank">رتبه</th>
							<th class="css-st-col-coin">نماد</th>
							<th class="css-st-col-name">نام</th>
							<th>تایم‌فریم</th>
							<th>قیمت (USD)</th>
							<th>سیگنال نهایی</th>
							<th>جزئیات اندیکاتورها</th>
							<th class="css-st-col-updated">آخرین بروزرسانی</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) :
							$details    = json_decode( $row['indicators_detail'], true ) ?: array();
							$detail_str = array();
							foreach ( $details as $ind_id => $val ) {
								$label = $indicator_labels[ $ind_id ] ?? $ind_id;
								$detail_str[] = '<button type="button" class="css-st-ind-link" data-ind-id="' . esc_attr( $ind_id ) . '">' . esc_html( $label ) . '</button>: ' . esc_html( $this->get_signal_label( $val ) );
							}
							$search_key  = mb_strtolower( $row['symbol'] . ' ' . $row['name'] );
							$eff_signal  = $row['trade_signal'];
							$row_for_hook = array(
								'coin_id' => $row['coin_id'],
								'symbol'  => $row['symbol'],
								'name'    => $row['name'],
								'price'   => $row['price'],
							);
							?>
							<tr data-signal="<?php echo esc_attr( $eff_signal ); ?>" data-timeframe="<?php echo esc_attr( $row['timeframe'] ); ?>" data-search="<?php echo esc_attr( $search_key ); ?>">
								<td class="css-st-col-rank"><?php echo esc_html( (string) $row['market_cap_rank'] ); ?></td>
								<td class="css-st-col-coin">
									<button type="button" class="css-st-symbol-link" data-coin-id="<?php echo esc_attr( $row['coin_id'] ); ?>">
										<?php if ( ! empty( $icons[ $row['coin_id'] ] ) ) : ?>
											<img class="css-st-coin-icon" src="<?php echo esc_url( $icons[ $row['coin_id'] ] ); ?>" alt="" width="18" height="18" loading="lazy">
										<?php else : ?>
											<span class="css-st-coin-icon css-st-coin-icon-fallback"><?php echo esc_html( mb_substr( $row['symbol'], 0, 1 ) ); ?></span>
										<?php endif; ?>
										<?php echo esc_html( $row['symbol'] ); ?>
									</button>
								</td>
								<td class="css-st-col-name" title="<?php echo esc_attr( $row['name'] ); ?>"><?php echo esc_html( $row['name'] ); ?></td>
								<td><span class="css-st-tf-badge"><?php echo esc_html( $tf_labels[ $row['timeframe'] ] ?? $row['timeframe'] ); ?></span></td>
								<td class="css-st-price"><?php echo esc_html( number_format( (float) $row['price'], 4 ) ); ?></td>
								<td><span class="css-st-badge css-st-badge-<?php echo esc_attr( $eff_signal ); ?>"><?php echo esc_html( $this->get_signal_label( $eff_signal ) ); ?></span></td>
								<td class="css-st-details"><?php echo implode( '<br>', $detail_str ); // phpcs:ignore ?></td>
								<td class="css-st-col-updated"><?php echo esc_html( $row['updated_at'] ); ?></td>
								<td><?php do_action( 'css_after_signal_row_actions', $row_for_hook, $eff_signal ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<div class="css-st-pagination" id="<?php echo esc_attr( $panel_id ); ?>-pagination"></div>
				<p class="css-st-empty-state" id="<?php echo esc_attr( $panel_id ); ?>-no-match" style="display:none;">ارزی با این مشخصات پیدا نشد.</p>
			<?php endif; ?>
			</div>

			<div class="css-st-main-panel" data-main-panel="accuracy" style="display:none;">
				<?php echo class_exists( 'CSS_Accuracy_Shortcode' ) ? CSS_Accuracy_Shortcode::render_panel() : ''; // phpcs:ignore ?>
			</div>

			<div class="css-st-main-panel" data-main-panel="trend" style="display:none;">
				<?php echo class_exists( 'CSS_Market_Trend_Shortcode' ) ? CSS_Market_Trend_Shortcode::render_panel() : ''; // phpcs:ignore ?>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}
}

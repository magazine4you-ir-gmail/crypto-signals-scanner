<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * شورت‌کد [crypto_accuracy_history] — دقیقاً همان چیزی که در «دقت سیگنال‌ها»ی پیشخوان
 * است (کارت‌های خلاصه، جدول دقت هر اندیکاتور، جدول تاریخچه اخیر با فیلتر/جستجو)،
 * بدون دکمه «بررسی دقت سیگنال‌ها الان» که مخصوص مدیریت است. همان طراحی/کلاس‌های
 * [crypto_signals_table] (پیشوند css-st-) استفاده می‌شود تا ظاهر یکدست بماند.
 * متد render_panel() جدا هم قابل فراخوانی است تا در شورت‌کد اصلی به‌عنوان یک تب جاسازی شود.
 */
class CSS_Accuracy_Shortcode {

	public function __construct() {
		add_shortcode( 'crypto_accuracy_history', array( $this, 'render' ) );
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

	public function render( $atts ): string {
		wp_enqueue_style( 'css-signals-table' );
		wp_enqueue_script( 'css-signals-table' );
		return '<div class="css-st-panel">' . self::render_panel() . '</div>';
	}

	/** خروجی فقط محتوای داخلی (بدون کارت بیرونی) — برای جاسازی به‌عنوان یک تب */
	public static function render_panel(): string {
		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_HISTORY;

		$stats         = CSS_Accuracy_Stats::get_summary();
		$per_indicator = CSS_Accuracy_Stats::get_per_indicator();
		$recent        = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100", ARRAY_A );
		$signal_labels = array( 'buy' => 'خرید', 'sell' => 'فروش' );

		$icons    = array();
		$coin_ids = array_unique( array_column( $recent, 'coin_id' ) );
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

		$panel_id = 'css-st-acc-' . wp_unique_id();

		ob_start();
		?>
		<div class="css-st-summary">
			<div class="css-st-summary-card">
				<span class="css-st-summary-num"><?php echo null === $stats['accuracy_pct'] ? '—' : esc_html( $stats['accuracy_pct'] ) . '%'; ?></span>
				<span class="css-st-summary-label">دقت کلی (از <?php echo (int) $stats['evaluated']; ?> سیگنال بررسی‌شده)</span>
			</div>
			<div class="css-st-summary-card css-st-summary-buy">
				<span class="css-st-summary-num"><?php echo (int) $stats['correct']; ?></span>
				<span class="css-st-summary-label">سیگنال‌های درست</span>
			</div>
			<div class="css-st-summary-card css-st-summary-sell">
				<span class="css-st-summary-num"><?php echo (int) $stats['incorrect']; ?></span>
				<span class="css-st-summary-label">سیگنال‌های غلط</span>
			</div>
			<div class="css-st-summary-card">
				<span class="css-st-summary-num"><?php echo (int) $stats['pending']; ?></span>
				<span class="css-st-summary-label">در انتظار بررسی</span>
			</div>
		</div>

		<?php if ( ! empty( $per_indicator ) ) : ?>
			<h4 class="css-st-section-title">دقت هر اندیکاتور به‌تنهایی</h4>
			<div class="css-st-table-wrap">
			<table class="css-st-table">
				<thead><tr><th class="css-st-col-indicator-name">اندیکاتور</th><th>تعداد سیگنال هم‌جهت</th><th>درست</th><th>درصد دقت</th></tr></thead>
				<tbody>
					<?php foreach ( $per_indicator as $ind_id => $stat ) :
						$indicator = CSS_Indicator_Registry::get( $ind_id );
						$label     = $indicator ? $indicator->get_label() : $ind_id;
						$pct       = $stat['total'] > 0 ? round( ( $stat['correct'] / $stat['total'] ) * 100, 1 ) : 0;
						?>
						<tr>
							<td class="css-st-col-indicator-name"><button type="button" class="css-st-ind-link" data-ind-id="<?php echo esc_attr( $ind_id ); ?>"><?php echo esc_html( $label ); ?></button></td>
							<td><?php echo (int) $stat['total']; ?></td>
							<td><?php echo (int) $stat['correct']; ?></td>
							<td><strong><?php echo esc_html( $pct ); ?>%</strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<h4 class="css-st-section-title">تاریخچه اخیر سیگنال‌ها</h4>
		<?php if ( empty( $recent ) ) : ?>
			<p class="css-st-empty-state">هنوز هیچ سیگنال خرید/فروشی صادر نشده.</p>
		<?php else :
			$tf_labels = array( 'hourly' => 'ساعتی', 'daily' => 'روزانه', 'weekly' => 'هفتگی' );
			?>
			<div class="css-st-toolbar">
				<div class="css-st-tabs" data-role="accuracy-tabs">
					<button type="button" class="css-st-acc-tab active" data-filter="all">همه</button>
					<button type="button" class="css-st-acc-tab" data-filter="correct">درست</button>
					<button type="button" class="css-st-acc-tab" data-filter="incorrect">غلط</button>
					<button type="button" class="css-st-acc-tab" data-filter="pending">در انتظار</button>
				</div>
				<input type="text" class="css-st-search" data-role="accuracy-search" placeholder="جستجوی نماد ارز...">
			</div>

			<div class="css-st-table-wrap">
			<table class="css-st-table" data-role="accuracy-table">
				<thead>
					<tr><th class="css-st-col-coin">نماد</th><th>سیگنال</th><th>تایم‌فریم</th><th>اندیکاتور صادرکننده</th><th>قیمت زمان سیگنال</th><th>قیمت زمان بررسی</th><th>سود/ضرر</th><th>نتیجه</th><th class="css-st-col-updated">تاریخ صدور</th></tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $row ) :
						$outcome_fa = array( 'pending' => 'در انتظار', 'correct' => 'درست بود', 'incorrect' => 'غلط بود' )[ $row['outcome'] ] ?? $row['outcome'];
						$pl         = class_exists( 'CSS_MA_Helper' ) ? CSS_MA_Helper::signal_pl_percent( $row['trade_signal'], $row['price_at_signal'], $row['price_at_check'] ) : null;
						$search_key = mb_strtolower( $row['symbol'] );
						?>
						<tr data-outcome="<?php echo esc_attr( $row['outcome'] ); ?>" data-search="<?php echo esc_attr( $search_key ); ?>">
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
							<td><span class="css-st-badge css-st-badge-<?php echo esc_attr( $row['trade_signal'] ); ?>"><?php echo esc_html( $signal_labels[ $row['trade_signal'] ] ?? $row['trade_signal'] ); ?></span></td>
							<td><?php echo esc_html( $tf_labels[ $row['timeframe'] ] ?? $row['timeframe'] ); ?></td>
							<td class="css-st-details"><?php echo esc_html( $row['source_indicators'] ?: '—' ); ?></td>
							<td><?php echo esc_html( number_format( (float) $row['price_at_signal'], 4 ) ); ?></td>
							<td><?php echo null !== $row['price_at_check'] ? esc_html( number_format( (float) $row['price_at_check'], 4 ) ) : '—'; ?></td>
							<td><?php if ( null === $pl ) : ?>—<?php else : ?><strong class="<?php echo $pl >= 0 ? 'css-st-pos' : 'css-st-neg'; ?>"><?php echo ( $pl >= 0 ? '+' : '' ) . esc_html( round( $pl, 2 ) ); ?>%</strong><?php endif; ?></td>
							<td><span class="css-st-outcome css-st-outcome-<?php echo esc_attr( $row['outcome'] ); ?>"><?php echo esc_html( $outcome_fa ); ?></span></td>
							<td class="css-st-col-updated"><?php echo esc_html( $row['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}
}

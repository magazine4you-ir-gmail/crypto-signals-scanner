<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * شورت‌کد [crypto_market_trend] — دقیقاً همان اطلاعاتی که در «روند بازار»ی پیشخوان
 * است (روند امروز، مارکت کپ کل، تغییر ۲۴ ساعته، دامیننس بیت‌کوین، تاریخچه روزانه)،
 * با همان طراحی تیره [crypto_signals_table]. متد render_panel() هم جدا قابل
 * فراخوانی است تا در شورت‌کد اصلی به‌عنوان یک تب جاسازی شود.
 */
class CSS_Market_Trend_Shortcode {

	public function __construct() {
		add_shortcode( 'crypto_market_trend', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		wp_register_style( 'css-signals-table', CSS_PLUGIN_URL . 'assets/css/signals-table.css', array(), css_asset_ver( 'assets/css/signals-table.css' ) );
	}

	public function render( $atts ): string {
		wp_enqueue_style( 'css-signals-table' );
		return '<div class="css-st-panel">' . self::render_panel() . '</div>';
	}

	/** خروجی فقط محتوای داخلی (بدون کارت بیرونی) — برای جاسازی به‌عنوان یک تب */
	public static function render_panel(): string {
		$log   = array_reverse( CSS_Market_Trend::get_log(), true );
		$today = CSS_Market_Trend::get_today();

		ob_start();
		?>
		<p class="css-st-meta">این اطلاعات با هر بار اسکن به‌روز می‌شود. مقدار هر روز، آخرین خوانش همان روز است.</p>

		<?php if ( $today ) :
			$trend_class = 'bullish' === $today['trend'] ? 'css-st-pos' : ( 'bearish' === $today['trend'] ? 'css-st-neg' : '' );
			?>
			<div class="css-st-summary">
				<div class="css-st-summary-card">
					<span class="css-st-summary-num <?php echo esc_attr( $trend_class ); ?>"><?php echo esc_html( CSS_Market_Trend::trend_label( $today['trend'] ) ); ?></span>
					<span class="css-st-summary-label">روند بازار امروز</span>
				</div>
				<div class="css-st-summary-card">
					<span class="css-st-summary-num" style="font-size:16px;"><?php echo esc_html( number_format( $today['total_market_cap'] / 1e9, 1 ) ); ?>B $</span>
					<span class="css-st-summary-label">مارکت کپ کل بازار</span>
				</div>
				<div class="css-st-summary-card">
					<span class="css-st-summary-num <?php echo $today['change_pct_24h'] >= 0 ? 'css-st-pos' : 'css-st-neg'; ?>">
						<?php echo ( $today['change_pct_24h'] >= 0 ? '+' : '' ) . esc_html( round( $today['change_pct_24h'], 2 ) ); ?>%
					</span>
					<span class="css-st-summary-label">تغییر ۲۴ ساعته مارکت کپ کل</span>
				</div>
				<div class="css-st-summary-card">
					<span class="css-st-summary-num"><?php echo esc_html( round( $today['btc_dominance'], 1 ) ); ?>%</span>
					<span class="css-st-summary-label">دامیننس بیت‌کوین</span>
				</div>
			</div>
		<?php else : ?>
			<p class="css-st-empty-state">هنوز داده‌ای ثبت نشده — بعد از اولین اسکن این بخش پر می‌شود.</p>
		<?php endif; ?>

		<?php if ( ! empty( $log ) ) : ?>
			<h4 class="css-st-section-title">تاریخچه روزانه</h4>
			<div class="css-st-table-wrap">
			<table class="css-st-table">
				<thead><tr><th>تاریخ</th><th>روند</th><th>مارکت کپ کل</th><th>تغییر ۲۴ ساعته</th><th>دامیننس بیت‌کوین</th></tr></thead>
				<tbody>
					<?php foreach ( $log as $date => $entry ) :
						$trend_class = 'bullish' === $entry['trend'] ? 'css-st-pos' : ( 'bearish' === $entry['trend'] ? 'css-st-neg' : '' );
						?>
						<tr>
							<td><?php echo esc_html( $date ); ?></td>
							<td><strong class="<?php echo esc_attr( $trend_class ); ?>"><?php echo esc_html( CSS_Market_Trend::trend_label( $entry['trend'] ) ); ?></strong></td>
							<td><?php echo esc_html( number_format( $entry['total_market_cap'] / 1e9, 1 ) ); ?>B $</td>
							<td class="<?php echo $entry['change_pct_24h'] >= 0 ? 'css-st-pos' : 'css-st-neg'; ?>"><?php echo ( $entry['change_pct_24h'] >= 0 ? '+' : '' ) . esc_html( round( $entry['change_pct_24h'], 2 ) ); ?>%</td>
							<td><?php echo esc_html( round( $entry['btc_dominance'], 1 ) ); ?>%</td>
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

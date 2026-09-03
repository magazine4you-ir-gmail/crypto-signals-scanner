<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * شورت‌کد [crypto_signals]
 * پارامترها:
 *   signal="all|buy|sell|neutral"  (پیش‌فرض: all)
 *   limit="100"                    (پیش‌فرض: 100)
 */
class CSS_Shortcode {

	public function __construct() {
		add_shortcode( 'crypto_signals', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		wp_register_style( 'css-frontend', CSS_PLUGIN_URL . 'assets/css/frontend.css', array(), css_asset_ver( 'assets/css/frontend.css' ) );
	}

	public function render( $atts ): string {
		wp_enqueue_style( 'css-frontend' );

		$plugin_settings = get_option( 'css_settings', array() );
		$active_tf       = ! empty( $plugin_settings['active_timeframes'] ) ? (array) $plugin_settings['active_timeframes'] : array( 'daily' );

		$atts = shortcode_atts( array(
			'signal'    => 'all',
			'limit'     => 100,
			'timeframe' => $active_tf[0],
		), $atts, 'crypto_signals' );

		$timeframe = in_array( $atts['timeframe'], array( 'hourly', 'daily', 'weekly' ), true ) ? $atts['timeframe'] : $active_tf[0];

		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_SIGNALS;

		$where = $wpdb->prepare( ' WHERE timeframe = %s', $timeframe );
		if ( in_array( $atts['signal'], array( 'buy', 'sell', 'neutral' ), true ) ) {
			$where .= $wpdb->prepare( ' AND trade_signal = %s', $atts['signal'] );
		}

		$limit = max( 1, (int) $atts['limit'] );
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table}{$where} ORDER BY market_cap_rank ASC LIMIT {$limit}",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return '<p class="css-empty">در حال حاضر نتیجه‌ای موجود نیست.</p>';
		}

		ob_start();
		?>
		<div class="css-frontend-table-wrap">
			<table class="css-frontend-table">
				<thead>
					<tr>
						<th>رتبه</th>
						<th>نماد</th>
						<th>نام ارز</th>
						<th>قیمت (USD)</th>
						<th>سیگنال</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['market_cap_rank'] ); ?></td>
							<td><strong><?php echo esc_html( $row['symbol'] ); ?></strong></td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( number_format( (float) $row['price'], 4 ) ); ?></td>
							<td>
								<span class="css-front-badge css-front-badge-<?php echo esc_attr( $row['trade_signal'] ); ?>">
									<?php
									echo esc_html( array(
										'buy'     => 'خرید',
										'sell'    => 'فروش',
										'neutral' => 'خنثی',
									)[ $row['trade_signal'] ] ?? $row['trade_signal'] );
									?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}
}

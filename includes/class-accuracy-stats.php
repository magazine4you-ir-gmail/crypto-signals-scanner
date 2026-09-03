<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * آمار کلی «نتیجه تست دقت سیستم» — چند سیگنال تا الان بررسی شده، چندتاش درست از آب
 * درآمده، چندتا غلط، و چندتا هنوز در انتظار بررسی است. هم در پیشخوان (داشبورد و صفحه
 * دقت سیگنال‌ها) و هم در شورت‌کدهای فرانت‌اند از همین یک منبع استفاده می‌شود.
 */
class CSS_Accuracy_Stats {

	public static function get_summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_HISTORY;

		$total_evaluated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE outcome IN ('correct','incorrect')" );
		$total_correct   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE outcome = 'correct'" );
		$total_pending    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE outcome = 'pending'" );
		$total_incorrect  = $total_evaluated - $total_correct;
		$accuracy_pct     = $total_evaluated > 0 ? round( ( $total_correct / $total_evaluated ) * 100, 1 ) : null;

		return array(
			'evaluated'    => $total_evaluated,
			'correct'      => $total_correct,
			'incorrect'    => $total_incorrect,
			'pending'      => $total_pending,
			'accuracy_pct' => $accuracy_pct,
		);
	}

	/** آمار دقت به تفکیک هر اندیکاتور (فقط رکوردهای بررسی‌شده و هم‌جهت با سیگنال نهایی) */
	public static function get_per_indicator(): array {
		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_HISTORY;

		$rows = $wpdb->get_results( "SELECT trade_signal, indicators_detail, outcome FROM {$table} WHERE outcome IN ('correct','incorrect')", ARRAY_A );

		$per_indicator = array();
		foreach ( $rows as $row ) {
			$details = json_decode( $row['indicators_detail'], true ) ?: array();
			foreach ( $details as $ind_id => $val ) {
				if ( $val !== $row['trade_signal'] ) {
					continue;
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
		return $per_indicator;
	}

	/** بلوک فشرده «نتیجه تست سیستم» — قابل استفاده هم در پیشخوان و هم فرانت‌اند */
	public static function render_compact_summary_html( array $stats ): string {
		ob_start();
		?>
		<div class="css-test-summary">
			<div class="css-test-summary-item"><strong><?php echo null === $stats['accuracy_pct'] ? '—' : esc_html( $stats['accuracy_pct'] ) . '%'; ?></strong>دقت کلی سیستم</div>
			<div class="css-test-summary-item"><strong><?php echo (int) $stats['evaluated']; ?></strong>سیگنال بررسی‌شده</div>
			<div class="css-test-summary-item"><strong style="color:#0e9f5a"><?php echo (int) $stats['correct']; ?></strong>درست بودند</div>
			<div class="css-test-summary-item"><strong style="color:#e0343f"><?php echo (int) $stats['incorrect']; ?></strong>غلط بودند</div>
			<div class="css-test-summary-item"><strong><?php echo (int) $stats['pending']; ?></strong>در انتظار بررسی</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

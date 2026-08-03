<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_css_manual_start_scan', array( $this, 'manual_start_scan' ) );
		add_action( 'wp_ajax_css_manual_process_batch', array( $this, 'manual_process_batch' ) );
		add_action( 'wp_ajax_css_get_status', array( $this, 'get_status' ) );
		add_action( 'wp_ajax_css_manual_evaluate_batch', array( $this, 'manual_evaluate_batch' ) );
		add_action( 'wp_ajax_css_cleanup_consolidate', array( $this, 'cleanup_consolidate' ) );
	}

	private function verify() {
		check_ajax_referer( 'css_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
	}

	/** شروع دستی یک دور اسکن جدید (توسط دکمه «اسکن الان») */
	public function manual_start_scan(): void {
		$this->verify();
		$cron = new CSS_Cron();
		$cron->start_scan( true );
		wp_send_json_success( get_option( 'css_scan_status', array() ) );
	}

	/** پردازش یک دسته از صف؛ فرانت‌اند تا خالی‌شدن صف این را پیاپی صدا می‌زند */
	public function manual_process_batch(): void {
		$this->verify();
		$cron = new CSS_Cron();
		$cron->process_queue_batch( true );
		$status = get_option( 'css_scan_status', array() );
		$status['rate_limited'] = (bool) get_transient( 'css_rate_limited' );
		wp_send_json_success( $status );
	}

	public function get_status(): void {
		$this->verify();
		wp_send_json_success( get_option( 'css_scan_status', array() ) );
	}

	/** پردازش دستی یک دسته از سیگنال‌های در انتظار بررسی (دکمه «بررسی دقت الان») */
	public function manual_evaluate_batch(): void {
		$this->verify();
		$cron = new CSS_Cron();
		$cron->evaluate_pending_signals();
		wp_send_json_success( array(
			'remaining'    => $cron->count_due_pending(),
			'rate_limited' => (bool) get_transient( 'css_rate_limited' ),
		) );
	}

	/** پاکسازی ردیف‌های یتیم/قدیمی داشبورد و ساخت پست‌تایپ برای ارزهایی که ندارند */
	public function cleanup_consolidate(): void {
		$this->verify();
		$result = CSS_Coin_CPT::cleanup_and_consolidate();
		wp_send_json_success( $result );
	}
}

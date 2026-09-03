<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_css_manual_start_scan', array( $this, 'manual_start_scan' ) );
        add_action( 'wp_ajax_css_manual_process_batch', array( $this, 'manual_process_batch' ) );
        add_action( 'wp_ajax_css_get_status', array( $this, 'get_status' ) );
        add_action( 'wp_ajax_css_manual_evaluate_batch', array( $this, 'manual_evaluate_batch' ) );
        add_action( 'wp_ajax_css_cleanup_consolidate', array( $this, 'cleanup_consolidate' ) );
        add_action( 'wp_ajax_css_cancel_scan', array( $this, 'cancel_scan' ) );
    }

    private function verify() {
        check_ajax_referer( 'css_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز', 403 );
        }
    }

    /** شروع دستی یک دور اسکن جدید */
    public function manual_start_scan(): void {
        $this->verify();
        $cron = new CSS_Cron();

        if ( $cron->scan_already_in_progress() ) {
            $status = get_option( 'css_scan_status', array() );
            wp_send_json_success( array_merge( $status, array(
                'already_running' => true,
                'message'         => 'یک اسکن دیگر همین الان در حال اجراست. صبر کنید تمام شود یا آن را لغو کنید.',
            ) ) );
            return;
        }

        $cron->start_scan( true );
        wp_send_json_success( get_option( 'css_scan_status', array() ) );
    }

    /** پردازش یک دسته از صف */
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

    /** لغو اسکن در حال اجرا */
    public function cancel_scan(): void {
        $this->verify();
        $cron = new CSS_Cron();
        $cron->cancel_scan();
        wp_send_json_success( array(
            'message' => 'اسکن با موفقیت لغو شد.',
        ) );
    }

    /** پردازش دستی سیگنال‌های در انتظار */
    public function manual_evaluate_batch(): void {
        $this->verify();
        $cron = new CSS_Cron();
        $cron->evaluate_pending_signals();
        wp_send_json_success( array(
            'remaining'    => $cron->count_due_pending(),
            'rate_limited' => (bool) get_transient( 'css_rate_limited' ),
        ) );
    }

    /** پاکسازی و یکپارچه‌سازی */
    public function cleanup_consolidate(): void {
        $this->verify();
        $result = CSS_Coin_CPT::cleanup_and_consolidate();
        wp_send_json_success( $result );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * وقتی کاربر روی نام یک ارز یا یک اندیکاتور در شورت‌کد کلیک می‌کند، از همین‌جا همان
 * پنل‌هایی که در پیشخوان (صفحه ویرایش پست‌تایپ ارز/اندیکاتور) نشان داده می‌شود عیناً
 * واکشی و در یک مودال نمایش داده می‌شود — چون بازدیدکنندگان عادی به پیشخوان دسترسی ندارند.
 */
class CSS_Frontend_History {

	const NONCE_ACTION = 'css_public_history_nonce';

	public function __construct() {
		add_action( 'wp_ajax_css_coin_history_full', array( $this, 'coin_history' ) );
		add_action( 'wp_ajax_nopriv_css_coin_history_full', array( $this, 'coin_history' ) );
		add_action( 'wp_ajax_css_indicator_history_full', array( $this, 'indicator_history' ) );
		add_action( 'wp_ajax_nopriv_css_indicator_history_full', array( $this, 'indicator_history' ) );
	}

	private function verify(): bool {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'درخواست نامعتبر، صفحه را رفرش کنید.' ) );
			return false;
		}
		return true;
	}

	private function sanitize_month(): ?string {
		if ( empty( $_POST['month'] ) ) {
			return null;
		}
		$month = sanitize_text_field( wp_unslash( $_POST['month'] ) );
		return preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : null;
	}

	public function coin_history(): void {
		if ( ! $this->verify() ) return;

		$coin_id = isset( $_POST['coin_id'] ) ? sanitize_text_field( wp_unslash( $_POST['coin_id'] ) ) : '';
		$post_id = $coin_id ? CSS_Coin_CPT::find_post_id( $coin_id ) : null;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'تاریخچه‌ای برای این ارز پیدا نشد.' ) );
			return;
		}

		$month = $this->sanitize_month();
		$title = get_the_title( $post_id );

		$html  = '<h4 class="css-hist-title">' . esc_html( $title ) . '</h4>';
		$html .= '<div class="css-hist-block">' . CSS_Coin_CPT::render_details_html( $post_id ) . '</div>';
		$md_html = CSS_Coin_CPT::render_market_data_html( $post_id );
		if ( $md_html ) {
			$html .= '<div class="css-hist-block"><h5>اطلاعات بازار</h5>' . $md_html . '</div>';
		}
		$html .= '<div class="css-hist-block"><h5>خلاصه عملکرد و نمودار</h5>' . CSS_Coin_CPT::render_stats_html( $post_id ) . '</div>';
		$html .= '<div class="css-hist-block"><h5>تقویم سیگنال‌ها</h5>' . CSS_Coin_CPT::render_calendar_html( $post_id, $month ) . '</div>';

		wp_send_json_success( array( 'html' => $html ) );
	}

	public function indicator_history(): void {
		if ( ! $this->verify() ) return;

		$ind_id  = isset( $_POST['ind_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ind_id'] ) ) : '';
		$post_id = $ind_id ? CSS_Indicator_CPT::find_post_id( $ind_id ) : null;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'تاریخچه‌ای برای این اندیکاتور پیدا نشد.' ) );
			return;
		}

		$month = $this->sanitize_month();
		$title = get_the_title( $post_id );

		$html  = '<h4 class="css-hist-title">اندیکاتور ' . esc_html( $title ) . '</h4>';
		$html .= '<div class="css-hist-block"><h5>خلاصه عملکرد به‌تفکیک ارز</h5>' . CSS_Indicator_CPT::render_stats_html( $post_id ) . '</div>';
		$html .= '<div class="css-hist-block"><h5>تقویم معاملات</h5>' . CSS_Indicator_CPT::render_calendar_html( $post_id, $month ) . '</div>';

		wp_send_json_success( array( 'html' => $html ) );
	}
}

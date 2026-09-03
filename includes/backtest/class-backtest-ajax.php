<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Backtest_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_css_bt_create_account', array( $this, 'create_account' ) );
		add_action( 'wp_ajax_css_bt_delete_account', array( $this, 'delete_account' ) );
		add_action( 'wp_ajax_css_bt_list_accounts', array( $this, 'list_accounts' ) );
		add_action( 'wp_ajax_css_bt_account_panel', array( $this, 'account_panel' ) );
		add_action( 'wp_ajax_css_bt_open_trade', array( $this, 'open_trade' ) );
		add_action( 'wp_ajax_css_bt_close_trade', array( $this, 'close_trade' ) );
	}

	private function auth_or_die(): ?int {
		if ( 'yes' !== ( CSS_Backtest_Engine::get_settings()['module_enabled'] ?? 'no' ) ) {
			wp_send_json_error( array( 'message' => 'ماژول بک‌تست غیرفعال است.' ) );
			return null;
		}
		if ( ! check_ajax_referer( 'css_bt_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'nonce نامعتبر یا منقضی‌شده — صفحه را رفرش کنید.' ) );
			return null;
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'برای این کار باید وارد حساب کاربری خود شوید.' ) );
			return null;
		}
		return get_current_user_id();
	}

	public function create_account(): void {
		$user_id = $this->auth_or_die();
		if ( ! $user_id ) return;

		$settings = CSS_Backtest_Engine::get_settings();

		if ( CSS_Backtest_Account::count_user_accounts( $user_id ) >= (int) $settings['max_accounts_per_user'] ) {
			wp_send_json_error( array( 'message' => 'به حداکثر تعداد اکانت بک‌تست مجاز رسیده‌اید.' ) );
			return;
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$initial = isset( $_POST['initial_balance'] ) ? (float) $_POST['initial_balance'] : 0;

		if ( $initial < (float) $settings['min_initial_balance'] || $initial > (float) $settings['max_initial_balance'] ) {
			wp_send_json_error( array( 'message' => sprintf(
				'موجودی اولیه باید بین %s تا %s دلار باشد.',
				number_format( (float) $settings['min_initial_balance'] ),
				number_format( (float) $settings['max_initial_balance'] )
			) ) );
			return;
		}

		$post_id = CSS_Backtest_Account::create_account( $user_id, $name, $initial );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'ساخت اکانت ناموفق بود.' ) );
			return;
		}

		wp_send_json_success( array(
			'account' => CSS_Backtest_Account::get_account_summary( $post_id ),
			'html'    => CSS_Backtest_Shortcode::render_accounts_list( $user_id ),
		) );
	}

	public function delete_account(): void {
		$user_id = $this->auth_or_die();
		if ( ! $user_id ) return;

		$account_id = isset( $_POST['account_id'] ) ? (int) $_POST['account_id'] : 0;
		if ( ! CSS_Backtest_Account::user_owns_account( $account_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
			return;
		}

		CSS_Backtest_Account::delete_account( $account_id, $user_id );
		wp_send_json_success( array( 'html' => CSS_Backtest_Shortcode::render_accounts_list( $user_id ) ) );
	}

	public function list_accounts(): void {
		$user_id = $this->auth_or_die();
		if ( ! $user_id ) return;

		wp_send_json_success( array(
			'html'     => CSS_Backtest_Shortcode::render_accounts_list( $user_id ),
			'accounts' => CSS_Backtest_Account::get_user_accounts( $user_id ),
		) );
	}

	public function account_panel(): void {
		$user_id = $this->auth_or_die();
		if ( ! $user_id ) return;

		$account_id = isset( $_POST['account_id'] ) ? (int) $_POST['account_id'] : 0;
		if ( ! CSS_Backtest_Account::user_owns_account( $account_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
			return;
		}

		wp_send_json_success( array( 'html' => CSS_Backtest_Shortcode::render_account_panel( $account_id ) ) );
	}

	public function open_trade(): void {
		$user_id = $this->auth_or_die();
		if ( ! $user_id ) return;

		$account_id = isset( $_POST['account_id'] ) ? (int) $_POST['account_id'] : 0;
		if ( ! CSS_Backtest_Account::user_owns_account( $account_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
			return;
		}

		$coin_id = isset( $_POST['coin_id'] ) ? sanitize_text_field( wp_unslash( $_POST['coin_id'] ) ) : '';
		if ( empty( $coin_id ) ) {
			wp_send_json_error( array( 'message' => 'ارز نامعتبر است.' ) );
			return;
		}

		$params = array(
			'coin_id'          => $coin_id,
			'symbol'           => isset( $_POST['symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['symbol'] ) ) : '',
			'direction'        => isset( $_POST['direction'] ) && 'sell' === $_POST['direction'] ? 'sell' : 'buy',
			'mode'             => isset( $_POST['mode'] ) && 'historical' === $_POST['mode'] ? 'historical' : 'live',
			'margin_usd'       => isset( $_POST['margin_usd'] ) ? (float) $_POST['margin_usd'] : 0,
			'leverage'         => isset( $_POST['leverage'] ) ? (float) $_POST['leverage'] : 1,
			'rr_ratio'         => isset( $_POST['rr_ratio'] ) ? (float) $_POST['rr_ratio'] : 2,
			'sl'               => isset( $_POST['sl'] ) ? sanitize_text_field( $_POST['sl'] ) : null,
			'tp'               => isset( $_POST['tp'] ) ? sanitize_text_field( $_POST['tp'] ) : null,
			'historical_date'  => isset( $_POST['historical_date'] ) ? sanitize_text_field( $_POST['historical_date'] ) : '',
		);

		$result = CSS_Backtest_Trade_Service::open_trade( $account_id, $params );
		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
			return;
		}

		wp_send_json_success( array(
			'trade'   => $result['trade'],
			'balance' => $result['balance'],
			'html'    => CSS_Backtest_Shortcode::render_account_panel( $account_id ),
		) );
	}

	public function close_trade(): void {
		$user_id = $this->auth_or_die();
		if ( ! $user_id ) return;

		$account_id = isset( $_POST['account_id'] ) ? (int) $_POST['account_id'] : 0;
		$trade_id   = isset( $_POST['trade_id'] ) ? sanitize_text_field( $_POST['trade_id'] ) : '';

		if ( ! CSS_Backtest_Account::user_owns_account( $account_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
			return;
		}

		$result = CSS_Backtest_Trade_Service::close_trade_manual( $account_id, $trade_id );
		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
			return;
		}

		wp_send_json_success( array(
			'trade'   => $result['trade'],
			'balance' => $result['balance'],
			'html'    => CSS_Backtest_Shortcode::render_account_panel( $account_id ),
		) );
	}
}

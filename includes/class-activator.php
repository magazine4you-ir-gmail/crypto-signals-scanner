<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * کارهایی که هنگام نصب/فعال‌سازی و حذف افزونه باید انجام شود
 */
class CSS_Activator {

	const DB_VERSION = '3.0';

	public static function maybe_upgrade_db(): void {
		if ( get_option( 'css_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			self::set_default_options();
			update_option( 'css_db_version', self::DB_VERSION, false );
		}
	}

	public static function activate() {
		self::create_tables();
		update_option( 'css_db_version', self::DB_VERSION, false );
		self::set_default_options();

		if ( ! wp_next_scheduled( 'css_queue_worker' ) ) {
			wp_schedule_event( time(), 'css_every_minute', 'css_queue_worker' );
		}
		if ( ! wp_next_scheduled( 'css_start_scan' ) ) {
			wp_schedule_event( time(), 'hourly', 'css_start_scan' );
		}
		if ( ! wp_next_scheduled( 'css_evaluate_signals' ) ) {
			wp_schedule_event( time(), 'hourly', 'css_evaluate_signals' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'css_queue_worker' );
		wp_clear_scheduled_hook( 'css_start_scan' );
		wp_clear_scheduled_hook( 'css_evaluate_signals' );
		wp_clear_scheduled_hook( 'css_bt_check_open_trades' );
	}

	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_signals = $wpdb->prefix . CSS_TABLE_SIGNALS;

		// اگر جدول از نسخه‌های قبلی افزونه از قبل وجود دارد ولی ستون timeframe را ندارد، مهاجرتش کن
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_signals ) ) === $table_signals;
		if ( $table_exists ) {
			self::migrate_signals_table();
			self::migrate_history_table();
		}

		$sql1 = "CREATE TABLE {$table_signals} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			coin_id VARCHAR(100) NOT NULL,
			symbol VARCHAR(20) NOT NULL,
			name VARCHAR(100) NOT NULL,
			market_cap_rank INT UNSIGNED DEFAULT NULL,
			price DOUBLE DEFAULT NULL,
			trade_signal VARCHAR(10) NOT NULL DEFAULT 'neutral',
			timeframe VARCHAR(10) NOT NULL DEFAULT 'daily',
			indicators_detail LONGTEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY coin_timeframe (coin_id, timeframe),
			KEY trade_signal (trade_signal)
		) {$charset_collate};";
		dbDelta( $sql1 );

		// تاریخچه: هر بار سیگنال خرید/فروش تازه‌ای صادر شود یک ردیف اینجا ثبت می‌شود
		$table_history = $wpdb->prefix . CSS_TABLE_HISTORY;
		$sql2 = "CREATE TABLE {$table_history} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			coin_id VARCHAR(100) NOT NULL,
			symbol VARCHAR(20) NOT NULL,
			trade_signal VARCHAR(10) NOT NULL,
			timeframe VARCHAR(10) NOT NULL DEFAULT 'daily',
			source_indicators VARCHAR(255) DEFAULT NULL,
			price_at_signal DOUBLE NOT NULL,
			price_at_check DOUBLE DEFAULT NULL,
			indicators_detail LONGTEXT NULL,
			outcome VARCHAR(10) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			check_after DATETIME NOT NULL,
			evaluated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY coin_id (coin_id),
			KEY outcome (outcome),
			KEY check_after (check_after)
		) {$charset_collate};";
		dbDelta( $sql2 );
	}

	/** اضافه‌کردن ستون timeframe و کلید یکتای مرکب به جدول سیگنال‌های نسخه‌های قدیمی‌تر */
	private static function migrate_signals_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_SIGNALS;

		$has_col = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'timeframe' ) );
		if ( empty( $has_col ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN timeframe VARCHAR(10) NOT NULL DEFAULT 'daily' AFTER trade_signal" );

			$old_key = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'coin_id'" );
			if ( ! empty( $old_key ) ) {
				$wpdb->query( "ALTER TABLE {$table} DROP INDEX coin_id" );
			}

			$new_key = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'coin_timeframe'" );
			if ( empty( $new_key ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY coin_timeframe (coin_id, timeframe)" );
			}
		}
	}

	/** اضافه‌کردن ستون‌های timeframe و source_indicators به جدول تاریخچه نسخه‌های قدیمی‌تر */
	private static function migrate_history_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . CSS_TABLE_HISTORY;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		if ( ! $exists ) {
			return;
		}

		$has_timeframe = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'timeframe' ) );
		if ( empty( $has_timeframe ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN timeframe VARCHAR(10) NOT NULL DEFAULT 'daily' AFTER trade_signal" );
		}

		$has_source = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'source_indicators' ) );
		if ( empty( $has_source ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN source_indicators VARCHAR(255) DEFAULT NULL AFTER timeframe" );
		}
	}

	private static function set_default_options() {
		$defaults = array(
			'rank_start'         => 1,
			'rank_end'           => 100,
			'auto_scan_enabled'  => true,
			'combination_mode'   => 'majority', // any | all_agree | majority
			'history_days'       => 30,
			'timeframe'          => 'daily',    // تایم‌فریم پیش‌فرض برای پنل ادمین
			'active_timeframes'  => array( 'daily' ), // تایم‌فریم‌هایی که هنگام اسکن محاسبه و ذخیره می‌شوند
			'evaluation_hours'   => 24,
			'bullish_threshold'  => 2,
			'bearish_threshold'  => -2,
			'coingecko_api_key'  => '',
			'api_base_url'       => '',
			'request_delay_ms'   => 2500,
			'active_indicators'  => array( 'rsi', 'macd', 'ma_cross' ),
			'indicator_settings' => array(
				'rsi' => array(
					'period'     => 14,
					'oversold'   => 30,
					'overbought' => 70,
				),
				'macd' => array(
					'fast_period'   => 12,
					'slow_period'   => 26,
					'signal_period' => 9,
				),
				'ma_cross' => array(
					'type'        => 'ema', // ema | sma
					'short_period' => 9,
					'long_period'  => 21,
				),
			),
		);

		if ( false === get_option( 'css_settings' ) ) {
			add_option( 'css_settings', $defaults );
		} else {
			$existing = get_option( 'css_settings' );
			$merged   = array_merge( $defaults, $existing );
			update_option( 'css_settings', $merged, false );
		}
	}
}

<?php
/**
 * Plugin Name:       Crypto Signal Scanner
 * Plugin URI:        https://example.com
 * Description:       اسکن خودکار ۱۰۰ ارز برتر بازار (بر اساس مارکت کپ) و نمایش سیگنال خرید/فروش بر اساس اندیکاتورهای فنی قابل تنظیم (RSI, MACD, تقاطع میانگین متحرک و ...). قابل توسعه با اندیکاتورهای دلخواه.
 * Version:           2.7.2
 * Author:            Your Site
 * Text Domain:       crypto-signal-scanner
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // دسترسی مستقیم ممنوع
}

// ==========================================================================
// ثابت‌های کلی افزونه
// ==========================================================================
define( 'CSS_VERSION', '2.7.2' );
define( 'CSS_PLUGIN_FILE', __FILE__ );
define( 'CSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CSS_TABLE_SIGNALS', 'css_signals' ); // نام جدول اصلی (بدون پیشوند wpdb)
define( 'CSS_TABLE_HISTORY', 'css_signal_history' ); // نام جدول تاریخچه سیگنال‌ها (برای سنجش دقت)

// ==========================================================================
// بارگذاری فایل‌های کلاس
// ==========================================================================
require_once CSS_PLUGIN_DIR . 'includes/class-activator.php';
require_once CSS_PLUGIN_DIR . 'includes/class-ma-helper.php';
require_once CSS_PLUGIN_DIR . 'includes/class-indicator-base.php';
require_once CSS_PLUGIN_DIR . 'includes/indicators/class-indicator-rsi.php';
require_once CSS_PLUGIN_DIR . 'includes/indicators/class-indicator-macd.php';
require_once CSS_PLUGIN_DIR . 'includes/indicators/class-indicator-ma-cross.php';
require_once CSS_PLUGIN_DIR . 'includes/indicators/class-indicator-supertrend.php';
require_once CSS_PLUGIN_DIR . 'includes/indicators/class-indicator-bollinger.php';
require_once CSS_PLUGIN_DIR . 'includes/indicators/class-indicator-ichimoku.php';
require_once CSS_PLUGIN_DIR . 'includes/class-indicator-registry.php';
require_once CSS_PLUGIN_DIR . 'includes/class-data-fetcher.php';
require_once CSS_PLUGIN_DIR . 'includes/class-market-trend.php';
require_once CSS_PLUGIN_DIR . 'includes/class-signal-engine.php';
require_once CSS_PLUGIN_DIR . 'includes/class-coin-cpt.php';
require_once CSS_PLUGIN_DIR . 'includes/class-indicator-cpt.php';
require_once CSS_PLUGIN_DIR . 'includes/class-cron.php';
require_once CSS_PLUGIN_DIR . 'includes/class-admin.php';
require_once CSS_PLUGIN_DIR . 'includes/class-ajax.php';
require_once CSS_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once CSS_PLUGIN_DIR . 'includes/class-accuracy-stats.php';
require_once CSS_PLUGIN_DIR . 'includes/class-frontend-history.php';
require_once CSS_PLUGIN_DIR . 'includes/class-signals-table-shortcode.php';
require_once CSS_PLUGIN_DIR . 'includes/class-accuracy-shortcode.php';
require_once CSS_PLUGIN_DIR . 'includes/class-market-trend-shortcode.php';

// ماژول بک‌تست (اکانت مجازی، معامله زنده/تاریخی، لوریج، حد ضرر/سود خودکار)
require_once CSS_PLUGIN_DIR . 'includes/backtest/class-backtest-account.php';
require_once CSS_PLUGIN_DIR . 'includes/backtest/class-backtest-engine.php';
require_once CSS_PLUGIN_DIR . 'includes/backtest/class-backtest-trade-service.php';
require_once CSS_PLUGIN_DIR . 'includes/backtest/class-backtest-ajax.php';
require_once CSS_PLUGIN_DIR . 'includes/backtest/class-backtest-admin.php';
require_once CSS_PLUGIN_DIR . 'includes/backtest/class-backtest-shortcode.php';
require_once CSS_PLUGIN_DIR . 'includes/backtest/class-backtest-cron.php';

// ==========================================================================
// فعال‌سازی / غیرفعال‌سازی
// ==========================================================================
register_activation_hook( __FILE__, array( 'CSS_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CSS_Activator', 'deactivate' ) );

// ==========================================================================
// تابع کمکی: نسخه فایل بر اساس زمان آخرین ویرایش (کش‌باستینگ خودکار مرورگر)
// ==========================================================================
function css_asset_ver( string $relative_path ) {
	$full = CSS_PLUGIN_DIR . ltrim( $relative_path, '/' );
	return file_exists( $full ) ? filemtime( $full ) : CSS_VERSION;
}

// ==========================================================================
// راه‌اندازی افزونه
// ==========================================================================
function css_run_plugin() {
	CSS_Activator::maybe_upgrade_db();
	new CSS_Coin_CPT();
	new CSS_Indicator_CPT();
	new CSS_Cron();
	new CSS_Admin();
	new CSS_Ajax();
	new CSS_Shortcode();
	new CSS_Frontend_History();
	new CSS_Signals_Table_Shortcode();
	new CSS_Accuracy_Shortcode();
	new CSS_Market_Trend_Shortcode();

	// ماژول بک‌تست
	new CSS_Backtest_Account();
	new CSS_Backtest_Ajax();
	new CSS_Backtest_Admin();
	new CSS_Backtest_Shortcode();
	new CSS_Backtest_Cron();
}
add_action( 'plugins_loaded', 'css_run_plugin' );

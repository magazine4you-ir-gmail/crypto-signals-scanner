<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * پست‌تایپ «اکانت‌های بک‌تست» (css_bt_account)
 * هر کاربر می‌تواند چند اکانت مجازی (موجودی دلاری قابل تنظیم) بسازد. معاملات هر
 * اکانت دقیقاً به همان سبک «تقویم بوکینگ» پست‌تایپ ارزها (_css_calendar) ذخیره
 * می‌شوند: یک متای JSON به نام _css_bt_calendar که کلیدش تاریخ بازشدن معامله
 * (Y-m-d) و مقدارش آرایه‌ای از رکوردهای کامل معامله است. بدون محدودیت تعداد رکورد.
 */
class CSS_Backtest_Account {

	const POST_TYPE = 'css_bt_account';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
	}

	public function register_cpt(): void {
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name'          => 'اکانت‌های بک‌تست',
				'singular_name' => 'اکانت بک‌تست',
				'menu_name'     => 'اکانت‌های بک‌تست',
				'not_found'     => 'هنوز هیچ اکانت بک‌تستی ساخته نشده',
			),
			'public'          => false,
			'show_ui'         => false, // مدیریت از صفحه اختصاصی خودمان در پیشخوان انجام می‌شود
			'show_in_menu'    => false,
			'supports'        => array( 'title', 'author' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		) );
	}

	// ======================================================================
	// مدیریت اکانت
	// ======================================================================

	/** ساخت اکانت جدید برای یک کاربر — عدد موجودی اولیه از قبل باید اعتبارسنجی شده باشد */
	public static function create_account( int $user_id, string $name, float $initial_balance ): ?int {
		$name = trim( $name ) !== '' ? trim( $name ) : 'اکانت بک‌تست';

		$post_id = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => sanitize_text_field( $name ),
			'post_author' => $user_id,
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $post_id ) ) {
			return null;
		}

		update_post_meta( $post_id, '_css_bt_initial_balance', $initial_balance );
		update_post_meta( $post_id, '_css_bt_balance', $initial_balance );
		update_post_meta( $post_id, '_css_bt_currency', 'USD' );
		update_post_meta( $post_id, '_css_bt_created_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_css_bt_calendar', wp_json_encode( array(), JSON_UNESCAPED_UNICODE ) );

		return $post_id;
	}

	/** آیا این پست واقعاً یک اکانت بک‌تست است و مالکش همین کاربر است؟ */
	public static function user_owns_account( int $post_id, int $user_id ): bool {
		$post = get_post( $post_id );
		return $post && self::POST_TYPE === $post->post_type && (int) $post->post_author === $user_id;
	}

	public static function get_account_summary( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$calendar = self::get_calendar( $post_id );
		$open = 0; $closed = 0; $realized_pnl = 0.0;
		foreach ( $calendar as $entries ) {
			foreach ( $entries as $entry ) {
				if ( 'open' === ( $entry['status'] ?? '' ) ) {
					$open++;
				} else {
					$closed++;
					$realized_pnl += (float) ( $entry['pnl_usd'] ?? 0 );
				}
			}
		}

		return array(
			'id'               => $post_id,
			'name'             => $post->post_title,
			'initial_balance'  => (float) get_post_meta( $post_id, '_css_bt_initial_balance', true ),
			'balance'          => (float) get_post_meta( $post_id, '_css_bt_balance', true ),
			'created_at'       => get_post_meta( $post_id, '_css_bt_created_at', true ),
			'open_trades'      => $open,
			'closed_trades'    => $closed,
			'realized_pnl'     => round( $realized_pnl, 2 ),
		);
	}

	/** لیست همه اکانت‌های یک کاربر */
	public static function get_user_accounts( int $user_id ): array {
		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'author'         => $user_id,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$out = array();
		foreach ( $posts as $p ) {
			$summary = self::get_account_summary( $p->ID );
			if ( $summary ) {
				$out[] = $summary;
			}
		}
		return $out;
	}

	public static function count_user_accounts( int $user_id ): int {
		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'author'         => $user_id,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		return count( $posts );
	}

	/** برای صفحه ادمین: لیست همه اکانت‌های همه کاربران */
	public static function get_all_accounts(): array {
		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$out = array();
		foreach ( $posts as $p ) {
			$summary = self::get_account_summary( $p->ID );
			if ( $summary ) {
				$summary['user_id']   = (int) $p->post_author;
				$user                 = get_userdata( $summary['user_id'] );
				$summary['user_name'] = $user ? $user->display_name : '—';
				$out[]                = $summary;
			}
		}
		return $out;
	}

	public static function delete_account( int $post_id, int $user_id ): bool {
		if ( ! self::user_owns_account( $post_id, $user_id ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return (bool) wp_delete_post( $post_id, true );
	}

	public static function adjust_balance( int $post_id, float $delta ): float {
		$balance = (float) get_post_meta( $post_id, '_css_bt_balance', true );
		$balance += $delta;
		update_post_meta( $post_id, '_css_bt_balance', $balance );
		return $balance;
	}

	public static function get_balance( int $post_id ): float {
		return (float) get_post_meta( $post_id, '_css_bt_balance', true );
	}

	// ======================================================================
	// تقویم معاملات (دقیقاً هم‌سبک با _css_calendar در پست‌تایپ ارزها)
	// ======================================================================

	public static function get_calendar( int $post_id ): array {
		$raw     = get_post_meta( $post_id, '_css_bt_calendar', true );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function save_calendar( int $post_id, array $calendar ): void {
		ksort( $calendar );
		update_post_meta( $post_id, '_css_bt_calendar', wp_json_encode( $calendar, JSON_UNESCAPED_UNICODE ) );
	}

	/** افزودن یک معامله تازه به تقویم — روی تاریخ opened_at خودش */
	public static function add_trade( int $post_id, array $trade ): string {
		$trade_id            = uniqid( 'bt_', true );
		$trade['id']          = $trade_id;
		$date                = substr( $trade['opened_at'], 0, 10 );

		$calendar = self::get_calendar( $post_id );
		if ( ! isset( $calendar[ $date ] ) ) {
			$calendar[ $date ] = array();
		}
		$calendar[ $date ][] = $trade;
		self::save_calendar( $post_id, $calendar );

		return $trade_id;
	}

	/** پیدا کردن یک معامله با شناسه‌اش — خروجی [تاریخ، ایندکس] یا null */
	private static function locate_trade( array $calendar, string $trade_id ): ?array {
		foreach ( $calendar as $date => $entries ) {
			foreach ( $entries as $i => $entry ) {
				if ( ( $entry['id'] ?? '' ) === $trade_id ) {
					return array( $date, $i );
				}
			}
		}
		return null;
	}

	public static function get_trade( int $post_id, string $trade_id ): ?array {
		$calendar = self::get_calendar( $post_id );
		$loc      = self::locate_trade( $calendar, $trade_id );
		return $loc ? $calendar[ $loc[0] ][ $loc[1] ] : null;
	}

	/** به‌روزرسانی فیلدهای یک معامله (مثلاً بستن معامله) */
	public static function update_trade( int $post_id, string $trade_id, array $fields ): bool {
		$calendar = self::get_calendar( $post_id );
		$loc      = self::locate_trade( $calendar, $trade_id );
		if ( ! $loc ) {
			return false;
		}
		list( $date, $i ) = $loc;
		$calendar[ $date ][ $i ] = array_merge( $calendar[ $date ][ $i ], $fields );
		self::save_calendar( $post_id, $calendar );
		return true;
	}

	/** همه معاملات باز یک اکانت (برای پنل کاربری و برای کران زنده) */
	public static function get_open_trades( int $post_id ): array {
		$calendar = self::get_calendar( $post_id );
		$open     = array();
		foreach ( $calendar as $entries ) {
			foreach ( $entries as $entry ) {
				if ( 'open' === ( $entry['status'] ?? '' ) ) {
					$entry['account_id'] = $post_id;
					$open[]               = $entry;
				}
			}
		}
		return $open;
	}

	/** همه معاملات باز روی همه اکانت‌های همه کاربران — برای کران بررسی قیمت زنده */
	public static function get_all_open_trades(): array {
		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$all = array();
		foreach ( $posts as $post_id ) {
			foreach ( self::get_open_trades( $post_id ) as $t ) {
				$all[] = $t;
			}
		}
		return $all;
	}

	/** لیست مسطح معاملات (باز و بسته) به ترتیب جدید به قدیم — برای نمایش تاریخچه */
	public static function get_trades_flat( int $post_id, int $limit = 100 ): array {
		$calendar = self::get_calendar( $post_id );
		$flat     = array();
		foreach ( $calendar as $entries ) {
			foreach ( $entries as $entry ) {
				$flat[] = $entry;
			}
		}
		usort( $flat, function ( $a, $b ) {
			return strcmp( $b['opened_at'] ?? '', $a['opened_at'] ?? '' );
		} );
		return array_slice( $flat, 0, $limit );
	}
}

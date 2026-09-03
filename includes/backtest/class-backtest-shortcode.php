<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * شورت‌کد [crypto_backtest_panel] — پنل کاربری بک‌تست: ساخت/حذف اکانت، معاملات باز،
 * تاریخچه. همچنین دکمه «معامله آزمایشی» را به هر ردیف سیگنال در [crypto_user_panel]
 * اضافه می‌کند (از طریق هوک css_after_signal_row_actions).
 */
class CSS_Backtest_Shortcode {

	private static bool $assets_prepared = false;

	public function __construct() {
		add_shortcode( 'crypto_backtest_panel', array( $this, 'render' ) );
		add_action( 'css_after_signal_row_actions', array( $this, 'render_quick_trade_button' ), 10, 2 );
	}

	// ======================================================================
	// آماده‌سازی اسکریپت/استایل + مودال‌های مشترک (فقط یک بار در هر صفحه چاپ می‌شوند)
	// ======================================================================

	public static function prepare_assets(): void {
		if ( self::$assets_prepared ) {
			return;
		}
		self::$assets_prepared = true;

		wp_register_style( 'css-backtest', CSS_PLUGIN_URL . 'assets/css/backtest.css', array(), css_asset_ver( 'assets/css/backtest.css' ) );
		wp_register_script( 'css-backtest', CSS_PLUGIN_URL . 'assets/js/backtest.js', array(), css_asset_ver( 'assets/js/backtest.js' ), true );

		$settings = CSS_Backtest_Engine::get_settings();

		wp_localize_script( 'css-backtest', 'CSS_BT_Data', array(
			'ajax_url'         => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'css_bt_nonce' ),
			'logged_in'        => is_user_logged_in(),
			'max_leverage'     => (float) $settings['max_leverage'],
			'fee_pct'          => (float) $settings['fee_pct'],
			'default_rr_ratio' => (float) $settings['default_rr_ratio'],
			'enable_live'      => 'yes' === $settings['enable_live'],
			'enable_historical'=> 'yes' === $settings['enable_historical'],
			'labels'           => array(
				'buy'          => 'خرید (لانگ)',
				'sell'         => 'فروش (شورت)',
				'loading'      => 'در حال پردازش...',
				'confirm'      => 'ثبت معامله',
				'chooseAccount'=> 'یک اکانت انتخاب کنید',
				'noAccounts'   => 'هنوز اکانتی نساخته‌اید. اول یک اکانت بک‌تست بسازید.',
				'closeConfirm' => 'آیا از بستن این معامله مطمئن هستید؟',
				'deleteConfirm'=> 'این اکانت و همه تاریخچه معاملاتش برای همیشه حذف می‌شود. ادامه می‌دهید؟',
			),
		) );

		wp_enqueue_style( 'css-backtest' );
		wp_enqueue_script( 'css-backtest' );

		add_action( 'wp_footer', array( __CLASS__, 'print_shared_modals' ) );
	}

	public static function print_shared_modals(): void {
		?>
		<div id="css-bt-modal-overlay" class="css-bt-overlay" style="display:none;">
			<div class="css-bt-modal" id="css-bt-trade-modal">
				<button type="button" class="css-bt-modal-close" data-close-modal>×</button>
				<h3 class="css-bt-modal-title">ثبت معامله آزمایشی</h3>
				<div class="css-bt-modal-body" id="css-bt-trade-modal-body"></div>
			</div>
		</div>
		<div id="css-bt-account-modal-overlay" class="css-bt-overlay" style="display:none;">
			<div class="css-bt-modal">
				<button type="button" class="css-bt-modal-close" data-close-account-modal>×</button>
				<h3 class="css-bt-modal-title">ساخت اکانت بک‌تست جدید</h3>
				<div class="css-bt-modal-body">
					<label class="css-bt-field">
						<span>نام اکانت</span>
						<input type="text" id="css-bt-new-account-name" placeholder="مثلاً: استراتژی RSI">
					</label>
					<label class="css-bt-field">
						<span>موجودی اولیه (دلار)</span>
						<input type="number" id="css-bt-new-account-balance" value="1000" min="1">
					</label>
					<button type="button" class="css-bt-btn css-bt-btn-primary" id="css-bt-create-account-submit">ساخت اکانت</button>
					<div class="css-bt-form-msg" id="css-bt-create-account-msg"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/** دکمه «معامله آزمایشی» که در هر ردیف سیگنال پنل کاربری تزریق می‌شود */
	public function render_quick_trade_button( array $row, string $eff_signal ): void {
		if ( ! in_array( $eff_signal, array( 'buy', 'sell' ), true ) ) {
			return;
		}
		if ( 'yes' !== ( CSS_Backtest_Engine::get_settings()['module_enabled'] ?? 'no' ) ) {
			return; // ماژول بک‌تست از تنظیمات خاموش است — کاملاً بی‌اثر بماند
		}
		self::prepare_assets();

		if ( ! is_user_logged_in() ) {
			$settings  = CSS_Backtest_Engine::get_settings();
			$login_url = ! empty( $settings['login_redirect_url'] )
				? add_query_arg( 'redirect_to', urlencode( get_permalink() ?: home_url() ), $settings['login_redirect_url'] )
				: wp_login_url( get_permalink() ?: home_url() );
			printf(
				'<a href="%s" class="css-bt-quick-trade css-bt-quick-trade-guest">معامله آزمایشی</a>',
				esc_url( $login_url )
			);
			return;
		}
		?>
		<button type="button" class="css-bt-quick-trade"
			data-coin-id="<?php echo esc_attr( $row['coin_id'] ); ?>"
			data-symbol="<?php echo esc_attr( $row['symbol'] ); ?>"
			data-price="<?php echo esc_attr( $row['price'] ); ?>"
			data-signal="<?php echo esc_attr( $eff_signal ); ?>">معامله آزمایشی</button>
		<?php
	}

	// ======================================================================
	// شورت‌کد اصلی
	// ======================================================================

	public function render( $atts ): string {
		if ( 'yes' !== ( CSS_Backtest_Engine::get_settings()['module_enabled'] ?? 'no' ) ) {
			return '<p class="css-empty">بخش بک‌تست فعلاً غیرفعال است.</p>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="css-empty">برای ساخت اکانت بک‌تست و ثبت معامله آزمایشی ابتدا وارد حساب کاربری خود شوید.</p>';
		}

		self::prepare_assets();
		$user_id = get_current_user_id();

		ob_start();
		?>
		<div class="css-bt-panel" id="css-bt-panel">
			<div class="css-bt-panel-header">
				<h3>اکانت‌های بک‌تست من</h3>
				<button type="button" class="css-bt-btn css-bt-btn-primary" id="css-bt-open-create-account">+ اکانت جدید</button>
			</div>
			<div class="css-bt-accounts-list" id="css-bt-accounts-list">
				<?php echo self::render_accounts_list( $user_id ); // phpcs:ignore ?>
			</div>
			<div class="css-bt-account-detail" id="css-bt-account-detail"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ======================================================================
	// رندر فرگمنت‌های HTML — هم برای بارگذاری اول شورت‌کد، هم برای پاسخ AJAX
	// ======================================================================

	public static function render_accounts_list( int $user_id ): string {
		$accounts = CSS_Backtest_Account::get_user_accounts( $user_id );

		ob_start();
		if ( empty( $accounts ) ) {
			echo '<p class="css-bt-empty">هنوز اکانتی نساخته‌اید. با دکمه «اکانت جدید» شروع کنید.</p>';
		}
		foreach ( $accounts as $acc ) {
			$pnl_class = $acc['realized_pnl'] >= 0 ? 'css-bt-pos' : 'css-bt-neg';
			?>
			<div class="css-bt-account-card" data-account-id="<?php echo esc_attr( $acc['id'] ); ?>">
				<div class="css-bt-account-card-main">
					<strong><?php echo esc_html( $acc['name'] ); ?></strong>
					<span class="css-bt-account-balance"><?php echo esc_html( number_format( $acc['balance'], 2 ) ); ?> $</span>
				</div>
				<div class="css-bt-account-card-meta">
					<span>موجودی اولیه: <?php echo esc_html( number_format( $acc['initial_balance'], 2 ) ); ?> $</span>
					<span>باز: <?php echo (int) $acc['open_trades']; ?></span>
					<span>بسته‌شده: <?php echo (int) $acc['closed_trades']; ?></span>
					<span class="<?php echo esc_attr( $pnl_class ); ?>">سود/زیان محقق‌شده: <?php echo esc_html( number_format( $acc['realized_pnl'], 2 ) ); ?> $</span>
				</div>
				<div class="css-bt-account-card-actions">
					<button type="button" class="css-bt-btn css-bt-btn-small css-bt-view-account" data-account-id="<?php echo esc_attr( $acc['id'] ); ?>">مشاهده</button>
					<button type="button" class="css-bt-btn css-bt-btn-small css-bt-btn-danger css-bt-delete-account" data-account-id="<?php echo esc_attr( $acc['id'] ); ?>">حذف</button>
				</div>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	public static function render_account_panel( int $account_id ): string {
		$summary = CSS_Backtest_Account::get_account_summary( $account_id );
		if ( ! $summary ) {
			return '<p class="css-bt-empty">اکانت یافت نشد.</p>';
		}

		$open_trades   = CSS_Backtest_Account::get_open_trades( $account_id );
		$history       = array_filter( CSS_Backtest_Account::get_trades_flat( $account_id, 100 ), fn( $t ) => 'open' !== ( $t['status'] ?? '' ) );

		ob_start();
		?>
		<div class="css-bt-account-panel" data-account-id="<?php echo esc_attr( $account_id ); ?>">
			<h4><?php echo esc_html( $summary['name'] ); ?> — موجودی فعلی: <span class="css-bt-live-balance"><?php echo esc_html( number_format( $summary['balance'], 2 ) ); ?></span> $</h4>

			<h5>معاملات باز (<?php echo count( $open_trades ); ?>)</h5>
			<?php if ( empty( $open_trades ) ) : ?>
				<p class="css-bt-empty">معامله باز فعلاً وجود ندارد.</p>
			<?php else : ?>
				<div class="css-bt-table-wrap">
				<table class="css-bt-table">
					<thead>
						<tr>
							<th>ارز</th><th>جهت</th><th>حالت</th><th>ورود</th><th>SL</th><th>TP</th>
							<th>لیکویید</th><th>لوریج</th><th>مارجین</th><th>تاریخ باز شدن</th><th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $open_trades as $t ) : ?>
						<tr>
							<td><?php echo esc_html( $t['symbol'] ); ?></td>
							<td><span class="css-bt-dir css-bt-dir-<?php echo esc_attr( $t['direction'] ); ?>"><?php echo 'sell' === $t['direction'] ? 'شورت' : 'لانگ'; ?></span></td>
							<td><?php echo 'historical' === ( $t['mode'] ?? '' ) ? 'تاریخی' : 'زنده'; ?></td>
							<td><?php echo esc_html( number_format( (float) $t['entry_price'], 4 ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $t['sl'], 4 ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $t['tp'], 4 ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $t['liquidation_price'], 4 ) ); ?></td>
							<td><?php echo esc_html( (float) $t['leverage'] ); ?>x</td>
							<td><?php echo esc_html( number_format( (float) $t['margin_usd'], 2 ) ); ?> $</td>
							<td><?php echo esc_html( $t['opened_at'] ); ?></td>
							<td><button type="button" class="css-bt-btn css-bt-btn-small css-bt-btn-danger css-bt-close-trade" data-account-id="<?php echo esc_attr( $account_id ); ?>" data-trade-id="<?php echo esc_attr( $t['id'] ); ?>">بستن</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>

			<h5>تاریخچه معاملات</h5>
			<?php if ( empty( $history ) ) : ?>
				<p class="css-bt-empty">هنوز معامله‌ای بسته نشده.</p>
			<?php else : ?>
				<div class="css-bt-table-wrap">
				<table class="css-bt-table">
					<thead>
						<tr>
							<th>ارز</th><th>جهت</th><th>حالت</th><th>ورود</th><th>خروج</th><th>لوریج</th>
							<th>دلیل بسته‌شدن</th><th>سود/زیان خالص</th><th>تاریخ بسته‌شدن</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $history as $t ) :
						$reason_fa = array(
							'tp'             => 'برخورد به TP',
							'sl'             => 'برخورد به SL',
							'liquidation'    => 'لیکویید شد',
							'manual'         => 'بستن دستی',
							'historical_end' => 'پایان بازه تاریخی',
						)[ $t['close_reason'] ?? '' ] ?? ( $t['close_reason'] ?? '—' );
						$pnl       = (float) ( $t['pnl_usd'] ?? 0 );
						$pnl_class = $pnl >= 0 ? 'css-bt-pos' : 'css-bt-neg';
						?>
						<tr>
							<td><?php echo esc_html( $t['symbol'] ); ?></td>
							<td><span class="css-bt-dir css-bt-dir-<?php echo esc_attr( $t['direction'] ); ?>"><?php echo 'sell' === $t['direction'] ? 'شورت' : 'لانگ'; ?></span></td>
							<td><?php echo 'historical' === ( $t['mode'] ?? '' ) ? 'تاریخی' : 'زنده'; ?></td>
							<td><?php echo esc_html( number_format( (float) $t['entry_price'], 4 ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) ( $t['close_price'] ?? 0 ), 4 ) ); ?></td>
							<td><?php echo esc_html( (float) $t['leverage'] ); ?>x</td>
							<td><?php echo esc_html( $reason_fa ); ?></td>
							<td class="<?php echo esc_attr( $pnl_class ); ?>"><?php echo esc_html( number_format( $pnl, 2 ) ); ?> $</td>
							<td><?php echo esc_html( $t['closed_at'] ?? '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

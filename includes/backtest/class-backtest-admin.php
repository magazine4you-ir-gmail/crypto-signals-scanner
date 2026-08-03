<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Backtest_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'crypto-signal-scanner',
			'اکانت‌های بک‌تست',
			'بک‌تست',
			'manage_options',
			'crypto-signal-scanner-backtest',
			array( $this, 'render_accounts_page' )
		);

		add_submenu_page(
			'crypto-signal-scanner',
			'تنظیمات بک‌تست',
			'تنظیمات بک‌تست',
			'manage_options',
			'crypto-signal-scanner-backtest-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings(): void {
		register_setting( 'css_bt_settings_group', 'css_bt_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ): array {
		$defaults = CSS_Backtest_Engine::get_settings();
		$out      = array();

		$out['max_leverage']            = max( 1, min( 125, (float) ( $input['max_leverage'] ?? $defaults['max_leverage'] ) ) );
		$out['fee_pct']                 = max( 0, min( 5, (float) ( $input['fee_pct'] ?? $defaults['fee_pct'] ) ) );
		$out['atr_period']              = max( 2, min( 100, (int) ( $input['atr_period'] ?? $defaults['atr_period'] ) ) );
		$out['atr_sl_multiplier']       = max( 0.1, min( 10, (float) ( $input['atr_sl_multiplier'] ?? $defaults['atr_sl_multiplier'] ) ) );
		$out['maintenance_margin_pct']  = max( 0, min( 20, (float) ( $input['maintenance_margin_pct'] ?? $defaults['maintenance_margin_pct'] ) ) );
		$out['min_initial_balance']     = max( 1, (float) ( $input['min_initial_balance'] ?? $defaults['min_initial_balance'] ) );
		$out['max_initial_balance']     = max( $out['min_initial_balance'], (float) ( $input['max_initial_balance'] ?? $defaults['max_initial_balance'] ) );
		$out['max_accounts_per_user']   = max( 1, min( 50, (int) ( $input['max_accounts_per_user'] ?? $defaults['max_accounts_per_user'] ) ) );
		$out['max_open_trades_per_acc'] = max( 1, min( 100, (int) ( $input['max_open_trades_per_acc'] ?? $defaults['max_open_trades_per_acc'] ) ) );
		$out['default_rr_ratio']        = max( 0.5, min( 10, (float) ( $input['default_rr_ratio'] ?? $defaults['default_rr_ratio'] ) ) );
		$out['enable_live']             = ! empty( $input['enable_live'] ) ? 'yes' : 'no';
		$out['enable_historical']       = ! empty( $input['enable_historical'] ) ? 'yes' : 'no';
		$out['login_redirect_url']      = ! empty( $input['login_redirect_url'] ) ? esc_url_raw( trim( $input['login_redirect_url'] ) ) : '';
		$out['module_enabled']          = ! empty( $input['module_enabled'] ) ? 'yes' : 'no';

		return $out;
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$s = CSS_Backtest_Engine::get_settings();
		?>
		<div class="wrap">
			<h1>تنظیمات بک‌تست</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'css_bt_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th><label>ماژول بک‌تست</label></th>
						<td>
							<label><input type="checkbox" name="css_bt_settings[module_enabled]" <?php checked( 'yes', $s['module_enabled'] ?? 'no' ); ?>> فعال باشد</label>
							<p class="description">اگر خاموش باشد، هیچ‌جای سایت (نه دکمه «معامله آزمایشی» کنار سیگنال‌ها، نه شورت‌کد پنل بک‌تست) نمایش داده نمی‌شود و هیچ کالی برای بررسی قیمت معاملات باز هم زده نمی‌شود — یعنی این ماژول کاملاً بی‌اثر و بدون مصرف کریدیت می‌ماند تا هروقت خواستید روشنش کنید.</p>
						</td>
					</tr>
					<tr>
						<th><label>حداکثر لوریج مجاز</label></th>
						<td><input type="number" step="1" min="1" max="125" name="css_bt_settings[max_leverage]" value="<?php echo esc_attr( $s['max_leverage'] ); ?>"> برابر</td>
					</tr>
					<tr>
						<th><label>کارمزد هر طرف معامله</label></th>
						<td><input type="number" step="0.01" min="0" max="5" name="css_bt_settings[fee_pct]" value="<?php echo esc_attr( $s['fee_pct'] ); ?>"> درصد (باز و بسته هرکدام جداگانه کسر می‌شود)</td>
					</tr>
					<tr>
						<th><label>دوره ATR برای حد ضرر خودکار</label></th>
						<td><input type="number" step="1" min="2" max="100" name="css_bt_settings[atr_period]" value="<?php echo esc_attr( $s['atr_period'] ); ?>"> کندل</td>
					</tr>
					<tr>
						<th><label>ضریب فاصله حد ضرر از ATR</label></th>
						<td><input type="number" step="0.1" min="0.1" max="10" name="css_bt_settings[atr_sl_multiplier]" value="<?php echo esc_attr( $s['atr_sl_multiplier'] ); ?>"> × ATR
							<p class="description">حد ضرر خودکار = قیمت ورود ∓ (ATR × این عدد). اگر کاربر خودش SL دستی وارد کند، این محاسبه نادیده گرفته می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th><label>نسبت پیش‌فرض ریسک به ریوارد (R:R)</label></th>
						<td><input type="number" step="0.1" min="0.5" max="10" name="css_bt_settings[default_rr_ratio]" value="<?php echo esc_attr( $s['default_rr_ratio'] ); ?>"></td>
					</tr>
					<tr>
						<th><label>درصد مارجین نگهداری (برای محاسبه لیکویید)</label></th>
						<td><input type="number" step="0.1" min="0" max="20" name="css_bt_settings[maintenance_margin_pct]" value="<?php echo esc_attr( $s['maintenance_margin_pct'] ); ?>"> درصد</td>
					</tr>
					<tr>
						<th><label>محدوده موجودی اولیه اکانت</label></th>
						<td>
							از <input type="number" step="1" min="1" name="css_bt_settings[min_initial_balance]" value="<?php echo esc_attr( $s['min_initial_balance'] ); ?>" style="width:120px">
							تا <input type="number" step="1" min="1" name="css_bt_settings[max_initial_balance]" value="<?php echo esc_attr( $s['max_initial_balance'] ); ?>" style="width:120px"> دلار
						</td>
					</tr>
					<tr>
						<th><label>حداکثر تعداد اکانت به ازای هر کاربر</label></th>
						<td><input type="number" step="1" min="1" max="50" name="css_bt_settings[max_accounts_per_user]" value="<?php echo esc_attr( $s['max_accounts_per_user'] ); ?>"></td>
					</tr>
					<tr>
						<th><label>حداکثر معاملات باز هم‌زمان هر اکانت</label></th>
						<td><input type="number" step="1" min="1" max="100" name="css_bt_settings[max_open_trades_per_acc]" value="<?php echo esc_attr( $s['max_open_trades_per_acc'] ); ?>"></td>
					</tr>
					<tr>
						<th><label>پیپر تریدینگ زنده</label></th>
						<td><label><input type="checkbox" name="css_bt_settings[enable_live]" <?php checked( 'yes', $s['enable_live'] ); ?>> فعال باشد</label></td>
					</tr>
					<tr>
						<th><label>شبیه‌سازی روی داده تاریخی</label></th>
						<td><label><input type="checkbox" name="css_bt_settings[enable_historical]" <?php checked( 'yes', $s['enable_historical'] ); ?>> فعال باشد</label></td>
					</tr>
					<tr>
						<th><label>آدرس صفحه ورود سایت</label></th>
						<td>
							<input type="url" style="width:100%;max-width:420px;" name="css_bt_settings[login_redirect_url]" value="<?php echo esc_attr( $s['login_redirect_url'] ); ?>" placeholder="https://example.com/login/">
							<p class="description">وقتی یک کاربر مهمان (واردنشده) روی «معامله آزمایشی» کلیک کند، به این آدرس هدایت می‌شود. اگر خالی بگذارید، از صفحه ورود پیش‌فرض وردپرس استفاده می‌شود.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_accounts_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$accounts = CSS_Backtest_Account::get_all_accounts();
		?>
		<div class="wrap">
			<h1>اکانت‌های بک‌تست (همه کاربران)</h1>
			<?php if ( empty( $accounts ) ) : ?>
				<p>هنوز هیچ کاربری اکانت بک‌تستی نساخته است.</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>کاربر</th><th>نام اکانت</th><th>موجودی اولیه</th><th>موجودی فعلی</th>
							<th>معاملات باز</th><th>معاملات بسته‌شده</th><th>سود/زیان محقق‌شده</th><th>تاریخ ساخت</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $accounts as $acc ) : ?>
						<tr>
							<td><?php echo esc_html( $acc['user_name'] ); ?></td>
							<td><?php echo esc_html( $acc['name'] ); ?> <span style="color:#999">(#<?php echo (int) $acc['id']; ?>)</span></td>
							<td><?php echo esc_html( number_format( $acc['initial_balance'], 2 ) ); ?> $</td>
							<td><?php echo esc_html( number_format( $acc['balance'], 2 ) ); ?> $</td>
							<td><?php echo (int) $acc['open_trades']; ?></td>
							<td><?php echo (int) $acc['closed_trades']; ?></td>
							<td style="color:<?php echo $acc['realized_pnl'] >= 0 ? '#0e9f5a' : '#e0343f'; ?>"><?php echo esc_html( number_format( $acc['realized_pnl'], 2 ) ); ?> $</td>
							<td><?php echo esc_html( $acc['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

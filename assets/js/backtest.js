(function () {
	'use strict';

	var DATA = window.CSS_BT_Data || {};
	var L    = DATA.labels || {};
	var currentAccountId = null;
	var refreshTimer      = null;

	document.addEventListener('DOMContentLoaded', function () {
		document.addEventListener('click', onClick);
	});

	function ajax(action, params) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', DATA.nonce);
		Object.keys(params || {}).forEach(function (k) {
			if (params[k] !== null && params[k] !== undefined) fd.append(k, params[k]);
		});
		return fetch(DATA.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); });
	}

	function showOverlay(id) {
		var el = document.getElementById(id);
		if (el) el.style.display = 'flex';
	}
	function hideOverlay(id) {
		var el = document.getElementById(id);
		if (el) el.style.display = 'none';
	}

	function onClick(e) {
		var t = e.target;

		if (t.closest('#css-bt-open-create-account')) {
			showOverlay('css-bt-account-modal-overlay');
			return;
		}
		if (t.closest('[data-close-account-modal]')) {
			hideOverlay('css-bt-account-modal-overlay');
			return;
		}
		if (t.closest('[data-close-modal]')) {
			hideOverlay('css-bt-modal-overlay');
			return;
		}
		if (t.closest('#css-bt-create-account-submit')) {
			return submitCreateAccount();
		}
		if (t.closest('.css-bt-quick-trade')) {
			return openTradeModal(t.closest('.css-bt-quick-trade'));
		}
		if (t.closest('.css-bt-view-account')) {
			var vId = t.closest('.css-bt-view-account').getAttribute('data-account-id');
			return viewAccount(vId);
		}
		if (t.closest('.css-bt-delete-account')) {
			var dId = t.closest('.css-bt-delete-account').getAttribute('data-account-id');
			if (!window.confirm(L.deleteConfirm || 'حذف شود؟')) return;
			return ajax('css_bt_delete_account', { account_id: dId }).then(function (json) {
				if (json.success) {
					var list = document.getElementById('css-bt-accounts-list');
					if (list) list.innerHTML = json.data.html;
					if (String(currentAccountId) === String(dId)) {
						currentAccountId = null;
						stopAutoRefresh();
						var detail = document.getElementById('css-bt-account-detail');
						if (detail) detail.innerHTML = '';
					}
				}
			});
		}
		if (t.closest('.css-bt-close-trade')) {
			var btn = t.closest('.css-bt-close-trade');
			if (!window.confirm(L.closeConfirm || 'بسته شود؟')) return;
			var accId = btn.getAttribute('data-account-id');
			var tradeId = btn.getAttribute('data-trade-id');
			btn.disabled = true;
			return ajax('css_bt_close_trade', { account_id: accId, trade_id: tradeId }).then(function (json) {
				btn.disabled = false;
				if (json.success) {
					renderAccountDetail(json.data.html);
				} else {
					window.alert(json.data && json.data.message ? json.data.message : 'خطا');
				}
			});
		}
		if (t.closest('#css-bt-trade-submit')) {
			return submitOpenTrade();
		}
		if (t.closest('[data-bt-mode]')) {
			var modeBtn = t.closest('[data-bt-mode]');
			var body = document.getElementById('css-bt-trade-modal-body');
			if (body) {
				body.querySelectorAll('[data-bt-mode]').forEach(function (b) { b.classList.remove('active'); });
				modeBtn.classList.add('active');
				body.querySelectorAll('.css-bt-historical-only').forEach(function (el) {
					el.style.display = modeBtn.getAttribute('data-bt-mode') === 'historical' ? 'block' : 'none';
				});
			}
			return;
		}
	}

	function submitCreateAccount() {
		var name = (document.getElementById('css-bt-new-account-name') || {}).value || '';
		var balance = (document.getElementById('css-bt-new-account-balance') || {}).value || 0;
		var msgEl = document.getElementById('css-bt-create-account-msg');
		if (msgEl) msgEl.textContent = L.loading || '...';

		ajax('css_bt_create_account', { name: name, initial_balance: balance }).then(function (json) {
			if (json.success) {
				if (msgEl) msgEl.textContent = '';
				var list = document.getElementById('css-bt-accounts-list');
				if (list) list.innerHTML = json.data.html;
				hideOverlay('css-bt-account-modal-overlay');
			} else {
				if (msgEl) msgEl.textContent = json.data && json.data.message ? json.data.message : 'خطا';
			}
		});
	}

	function viewAccount(accountId) {
		currentAccountId = accountId;
		ajax('css_bt_account_panel', { account_id: accountId }).then(function (json) {
			if (json.success) {
				renderAccountDetail(json.data.html);
				startAutoRefresh();
			}
		});
	}

	function renderAccountDetail(html) {
		var detail = document.getElementById('css-bt-account-detail');
		if (detail) detail.innerHTML = html;
	}

	function startAutoRefresh() {
		stopAutoRefresh();
		refreshTimer = setInterval(function () {
			if (!currentAccountId) return;
			ajax('css_bt_account_panel', { account_id: currentAccountId }).then(function (json) {
				if (json.success) renderAccountDetail(json.data.html);
			});
		}, 30000);
	}
	function stopAutoRefresh() {
		if (refreshTimer) clearInterval(refreshTimer);
		refreshTimer = null;
	}

	function openTradeModal(btn) {
		if (!DATA.logged_in) return;

		var coinId = btn.getAttribute('data-coin-id');
		var symbol = btn.getAttribute('data-symbol');
		var price = btn.getAttribute('data-price');
		var signal = btn.getAttribute('data-signal'); // buy | sell
		var body = document.getElementById('css-bt-trade-modal-body');
		if (!body) return;

		body.innerHTML = '<p>' + (L.loading || '...') + '</p>';
		showOverlay('css-bt-modal-overlay');

		ajax('css_bt_list_accounts', {}).then(function (json) {
			var accounts = (json.success && json.data.accounts) || [];
			if (!accounts.length) {
				body.innerHTML = '<p class="css-bt-empty">' + (L.noAccounts || '') + '</p>';
				return;
			}

			var dirLabel = signal === 'sell' ? (L.sell || 'فروش') : (L.buy || 'خرید');
			var optionsHtml = accounts.map(function (a) {
				return '<option value="' + a.id + '">' + escapeHtml(a.name) + ' (' + Number(a.balance).toFixed(2) + ' $)</option>';
			}).join('');

			body.innerHTML =
				'<p class="css-bt-trade-summary">' + escapeHtml(symbol) + ' — جهت: <strong>' + dirLabel + '</strong> — قیمت فعلی: ' + Number(price).toFixed(4) + ' $</p>' +
				'<div class="css-bt-mode-toggle">' +
					(DATA.enable_live ? '<button type="button" class="css-bt-btn active" data-bt-mode="live">پیپر تریدینگ زنده</button>' : '') +
					(DATA.enable_historical ? '<button type="button" class="css-bt-btn" data-bt-mode="historical">شبیه‌سازی تاریخی</button>' : '') +
				'</div>' +
				'<label class="css-bt-field"><span>اکانت</span><select id="css-bt-trade-account">' + optionsHtml + '</select></label>' +
				'<label class="css-bt-field css-bt-historical-only" style="display:none;"><span>تاریخ ورود (گذشته)</span><input type="date" id="css-bt-trade-hist-date" max="' + todayStr() + '"></label>' +
				'<label class="css-bt-field"><span>مارجین (دلار)</span><input type="number" id="css-bt-trade-margin" min="1" value="100"></label>' +
				'<label class="css-bt-field"><span>لوریج (حداکثر ' + DATA.max_leverage + 'x)</span><input type="number" id="css-bt-trade-leverage" min="1" max="' + DATA.max_leverage + '" value="1"></label>' +
				'<label class="css-bt-field"><span>نسبت ریسک به ریوارد (R:R)</span><input type="number" step="0.1" id="css-bt-trade-rr" min="0.5" max="10" value="' + DATA.default_rr_ratio + '"></label>' +
				'<p class="css-bt-note">حد ضرر به‌صورت خودکار بر پایه ATR محاسبه می‌شود و حد سود از روی نسبت R:R به‌دست می‌آید. در صورت نیاز می‌توانید دستی وارد کنید.</p>' +
				'<label class="css-bt-field"><span>حد ضرر دستی (اختیاری)</span><input type="number" step="any" id="css-bt-trade-sl"></label>' +
				'<label class="css-bt-field"><span>حد سود دستی (اختیاری)</span><input type="number" step="any" id="css-bt-trade-tp"></label>' +
				'<button type="button" class="css-bt-btn css-bt-btn-primary" id="css-bt-trade-submit" ' +
					'data-coin-id="' + escapeHtml(coinId) + '" data-symbol="' + escapeHtml(symbol) + '" data-direction="' + (signal === 'sell' ? 'sell' : 'buy') + '">' +
					(L.confirm || 'ثبت معامله') + '</button>' +
				'<div class="css-bt-form-msg" id="css-bt-trade-msg"></div>';
		});
	}

	function submitOpenTrade() {
		var btn = document.getElementById('css-bt-trade-submit');
		var msgEl = document.getElementById('css-bt-trade-msg');
		var mode = (document.querySelector('[data-bt-mode].active') || {}).getAttribute
			? document.querySelector('[data-bt-mode].active').getAttribute('data-bt-mode') : 'live';

		var params = {
			account_id: (document.getElementById('css-bt-trade-account') || {}).value,
			coin_id: btn.getAttribute('data-coin-id'),
			symbol: btn.getAttribute('data-symbol'),
			direction: btn.getAttribute('data-direction'),
			mode: mode,
			historical_date: (document.getElementById('css-bt-trade-hist-date') || {}).value || '',
			margin_usd: (document.getElementById('css-bt-trade-margin') || {}).value,
			leverage: (document.getElementById('css-bt-trade-leverage') || {}).value,
			rr_ratio: (document.getElementById('css-bt-trade-rr') || {}).value,
			sl: (document.getElementById('css-bt-trade-sl') || {}).value,
			tp: (document.getElementById('css-bt-trade-tp') || {}).value,
		};

		if (msgEl) msgEl.textContent = L.loading || '...';
		btn.disabled = true;

		ajax('css_bt_open_trade', params).then(function (json) {
			btn.disabled = false;
			if (json.success) {
				if (msgEl) msgEl.textContent = '';
				hideOverlay('css-bt-modal-overlay');
				currentAccountId = params.account_id;
				renderAccountDetail(json.data.html);
				var detail = document.getElementById('css-bt-account-detail');
				if (detail) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
				startAutoRefresh();
			} else if (msgEl) {
				msgEl.textContent = json.data && json.data.message ? json.data.message : 'خطا';
			}
		});
	}

	function todayStr() {
		var d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
})();

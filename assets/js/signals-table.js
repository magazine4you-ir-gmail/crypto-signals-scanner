document.addEventListener('DOMContentLoaded', function () {
	document.querySelectorAll('.css-st-panel').forEach(function (panel) {
		initMainTabs(panel);
		initSignalsTable(panel);
	});
	document.querySelectorAll('[data-role="accuracy-table"]').forEach(initAccuracyPanel);
	initHistoryModal();
});

// ===================== سوییچ تب اصلی (لیست سیگنال‌ها / تاریخچه دقت) =====================
function initMainTabs(panel) {
	var tabs = panel.querySelectorAll(':scope > .css-st-main-tabs > .css-st-main-tab');
	if (!tabs.length) return;

	tabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			tabs.forEach(function (t) { t.classList.remove('active'); });
			this.classList.add('active');
			var target = this.getAttribute('data-main-target');
			panel.querySelectorAll(':scope > [data-main-panel]').forEach(function (p) {
				p.style.display = p.getAttribute('data-main-panel') === target ? '' : 'none';
			});
		});
	});
}

// ===================== لیست سیگنال‌ها (فیلتر کاملاً سمت مرورگر) =====================
function initSignalsTable(panel) {
	var listPanel = panel.querySelector('[data-main-panel="list"]') || panel;
	var table = listPanel.querySelector('.css-st-table');
	if (!table) return;

	var noMatchEl = listPanel.querySelector('[id$="-no-match"]');
	var paginationEl = listPanel.querySelector('[id$="-pagination"]');
	var perPage = parseInt(table.getAttribute('data-per-page'), 10) || 100;
	var activeFilter = table.getAttribute('data-default-filter') || 'all';
	var activeTimeframe = table.getAttribute('data-default-timeframe') || '';
	var searchTerm = '';
	var currentPage = 1;

	function renderPagination(matchedRows) {
		if (!paginationEl) return;
		var totalPages = Math.max(1, Math.ceil(matchedRows.length / perPage));
		if (currentPage > totalPages) currentPage = totalPages;

		if (totalPages <= 1) {
			paginationEl.innerHTML = '';
			return;
		}

		var html = '';
		var addBtn = function (page, label, disabled, active) {
			html += '<button type="button" class="css-st-page-btn' + (active ? ' active' : '') + '"' +
				(disabled ? ' disabled' : '') + ' data-page="' + page + '">' + label + '</button>';
		};

		addBtn(currentPage - 1, '‹ قبلی', currentPage === 1, false);

		var start = Math.max(1, currentPage - 2);
		var end = Math.min(totalPages, start + 4);
		start = Math.max(1, end - 4);

		if (start > 1) { addBtn(1, '1', false, currentPage === 1); if (start > 2) html += '<span class="css-st-page-dots">…</span>'; }
		for (var p = start; p <= end; p++) {
			if (p === 1 && start > 1) continue;
			addBtn(p, String(p), false, p === currentPage);
		}
		if (end < totalPages) { if (end < totalPages - 1) html += '<span class="css-st-page-dots">…</span>'; addBtn(totalPages, String(totalPages), false, currentPage === totalPages); }

		addBtn(currentPage + 1, 'بعدی ›', currentPage === totalPages, false);

		paginationEl.innerHTML = html;
		paginationEl.querySelectorAll('.css-st-page-btn:not([disabled])').forEach(function (btn) {
			btn.addEventListener('click', function () {
				currentPage = parseInt(this.getAttribute('data-page'), 10);
				applyFilters(true);
				var wrap = table.closest('.css-st-table-wrap');
				if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			});
		});
	}

	function applyFilters(keepPage) {
		if (!keepPage) currentPage = 1;

		var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
		var matched = rows.filter(function (row) {
			var signal = row.getAttribute('data-signal');
			var timeframe = row.getAttribute('data-timeframe') || '';
			var search = row.getAttribute('data-search') || '';
			var matchesFilter = activeFilter === 'all' || signal === activeFilter;
			var matchesTimeframe = !activeTimeframe || timeframe === activeTimeframe;
			var matchesSearch = !searchTerm || search.indexOf(searchTerm) !== -1;
			return matchesFilter && matchesTimeframe && matchesSearch;
		});

		var totalPages = Math.max(1, Math.ceil(matched.length / perPage));
		if (currentPage > totalPages) currentPage = totalPages;
		var pageStart = (currentPage - 1) * perPage;
		var pageEnd = pageStart + perPage;

		rows.forEach(function (row) { row.style.display = 'none'; });
		matched.slice(pageStart, pageEnd).forEach(function (row) { row.style.display = ''; });

		if (noMatchEl) noMatchEl.style.display = matched.length === 0 ? 'block' : 'none';
		table.parentNode.style.display = matched.length === 0 && rows.length > 0 ? 'none' : '';

		renderPagination(matched);
	}

	var tabs = listPanel.querySelectorAll('.css-st-tab');
	tabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			tabs.forEach(function (t) { t.classList.remove('active'); });
			this.classList.add('active');
			activeFilter = this.getAttribute('data-filter');
			applyFilters();
		});
	});

	listPanel.querySelectorAll('.css-st-summary-card').forEach(function (card) {
		card.addEventListener('click', function () {
			var target = this.getAttribute('data-filter-target');
			var matchingTab = listPanel.querySelector('.css-st-tab[data-filter="' + target + '"]');
			if (matchingTab) matchingTab.click();
		});
	});

	var timeframeSelect = listPanel.querySelector('[data-role="timeframe"]');
	if (timeframeSelect) {
		timeframeSelect.addEventListener('change', function () {
			activeTimeframe = this.value;
			applyFilters();
		});
	}

	var searchInput = listPanel.querySelector('.css-st-search');
	if (searchInput) {
		searchInput.addEventListener('input', function () {
			searchTerm = this.value.trim().toLowerCase();
			applyFilters();
		});
	}

	applyFilters();
}

// ===================== تاریخچه دقت سیگنال (فیلتر کاملاً سمت مرورگر) =====================
function initAccuracyPanel(table) {
	var container = table.closest('.css-st-panel') || table.parentNode;
	var tabsWrap = container.querySelector('[data-role="accuracy-tabs"]');
	var searchInput = container.querySelector('[data-role="accuracy-search"]');
	var activeFilter = 'all';
	var searchTerm = '';

	function applyFilters() {
		table.querySelectorAll('tbody tr').forEach(function (row) {
			var outcome = row.getAttribute('data-outcome');
			var search = row.getAttribute('data-search') || '';
			var matchesFilter = activeFilter === 'all' || outcome === activeFilter;
			var matchesSearch = !searchTerm || search.indexOf(searchTerm) !== -1;
			row.style.display = (matchesFilter && matchesSearch) ? '' : 'none';
		});
	}

	if (tabsWrap) {
		var tabs = tabsWrap.querySelectorAll('.css-st-acc-tab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				tabs.forEach(function (t) { t.classList.remove('active'); });
				this.classList.add('active');
				activeFilter = this.getAttribute('data-filter');
				applyFilters();
			});
		});
	}

	if (searchInput) {
		searchInput.addEventListener('input', function () {
			searchTerm = this.value.trim().toLowerCase();
			applyFilters();
		});
	}
}

// ===================== مودال تاریخچه ارز / اندیکاتور =====================
// این دو تابع سراسری‌اند چون HTML تقویم (که از پیشخوان عیناً واکشی می‌شود) با
// onclick="cssToggleCalDay(...)" به آن‌ها ارجاع می‌دهد. تعریفشان اینجا تضمین می‌کند
// حتی اگر تگ <script> داخل HTML واکشی‌شده توسط innerHTML اجرا نشود (که طبیعتاً
// نمی‌شود)، این توابع در دسترس باشند.
window.cssToggleCalDay = window.cssToggleCalDay || function (date) {
	var el = document.getElementById('css-cal-details-' + date);
	if (!el) return;
	var isOpen = el.style.display === 'block';
	document.querySelectorAll('.css-coin-cal-details').forEach(function (d) { d.style.display = 'none'; });
	el.style.display = isOpen ? 'none' : 'block';
};
window.cssToggleIndDay = window.cssToggleIndDay || function (date) {
	var el = document.getElementById('css-ind-cal-details-' + date);
	if (!el) return;
	var isOpen = el.style.display === 'block';
	document.querySelectorAll('.css-ind-cal-details').forEach(function (d) { d.style.display = 'none'; });
	el.style.display = isOpen ? 'none' : 'block';
};

function initHistoryModal() {
	var DATA = window.CSS_ST_Data || {};
	if (!DATA.ajax_url) return;

	var overlay = document.getElementById('css-st-hist-overlay');
	if (!overlay) {
		overlay = document.createElement('div');
		overlay.id = 'css-st-hist-overlay';
		overlay.className = 'css-st-hist-overlay';
		overlay.style.display = 'none';
		overlay.innerHTML =
			'<div class="css-st-hist-modal">' +
				'<button type="button" class="css-st-hist-close" aria-label="بستن">×</button>' +
				'<div class="css-st-hist-body"><div class="css-st-hist-loading">در حال بارگذاری...</div></div>' +
			'</div>';
		document.body.appendChild(overlay);

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay || e.target.closest('.css-st-hist-close')) {
				overlay.style.display = 'none';
			}
		});
	}

	var body = overlay.querySelector('.css-st-hist-body');
	var currentRequest = null; // {type:'coin'|'indicator', id:'...'}

	function fetchHistory(action, params) {
		body.innerHTML = '<div class="css-st-hist-loading">در حال بارگذاری...</div>';
		overlay.style.display = 'flex';

		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', DATA.nonce);
		Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });

		fetch(DATA.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json.success) {
					body.innerHTML = json.data.html;
				} else {
					body.innerHTML = '<p style="color:#e0343f;">' + (json.data && json.data.message ? json.data.message : 'خطا در بارگذاری تاریخچه.') + '</p>';
				}
			})
			.catch(function () {
				body.innerHTML = '<p style="color:#e0343f;">ارتباط با سرور برقرار نشد.</p>';
			});
	}

	document.addEventListener('click', function (e) {
		var coinBtn = e.target.closest('.css-st-symbol-link');
		if (coinBtn) {
			var coinId = coinBtn.getAttribute('data-coin-id');
			currentRequest = { type: 'coin', id: coinId };
			fetchHistory('css_coin_history_full', { coin_id: coinId });
			return;
		}

		var indBtn = e.target.closest('.css-st-ind-link');
		if (indBtn) {
			var indId = indBtn.getAttribute('data-ind-id');
			currentRequest = { type: 'indicator', id: indId };
			fetchHistory('css_indicator_history_full', { ind_id: indId });
			return;
		}

		// جلوگیری از رفتن به لینک ماه قبل/بعد داخل مودال — به‌جایش با AJAX همان لحظه بازخوانی کن
		if (overlay.style.display === 'flex' && currentRequest) {
			var navLink = e.target.closest('.css-coin-cal-nav a, .css-ind-cal-nav a');
			if (navLink) {
				e.preventDefault();
				var url = new URL(navLink.href, window.location.href);
				var month = url.searchParams.get('css_month');
				if (!month) return;
				if ('coin' === currentRequest.type) {
					fetchHistory('css_coin_history_full', { coin_id: currentRequest.id, month: month });
				} else {
					fetchHistory('css_indicator_history_full', { ind_id: currentRequest.id, month: month });
				}
			}
		}
	});

	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key) overlay.style.display = 'none';
	});
}

jQuery(function ($) {
    // ---- نمایش تنظیمات Provider فعال ----
    const providerSelect = $('#data_provider');
    function syncProviderSettings() {
        if (!providerSelect.length) return;
        const provider = providerSelect.val();
        $('.css-provider-row').hide();
        $('.css-provider-' + provider).show();
    }
    providerSelect.on('change', syncProviderSettings);
    syncProviderSettings();

    // ---- فیلتر تب، تایم‌فریم و جستجو در جدول پیشخوان ----
    const table = document.getElementById('css-admin-table');
    if (table) {
        let activeFilter = 'all';
        let searchTerm = '';
        let activeTimeframe = table.getAttribute('data-default-timeframe') || '';

        function applyFilters() {
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach((row) => {
                const signal = row.getAttribute('data-signal');
                const timeframe = row.getAttribute('data-timeframe') || '';
                const search = row.getAttribute('data-search') || '';
                const matchesFilter = activeFilter === 'all' || signal === activeFilter;
                const matchesTimeframe = !activeTimeframe || timeframe === activeTimeframe;
                const matchesSearch = !searchTerm || search.indexOf(searchTerm) !== -1;
                row.style.display = (matchesFilter && matchesTimeframe && matchesSearch) ? '' : 'none';
            });
        }

        const tabsContainer = document.getElementById('css-admin-filter-tabs');
        const tabButtons = tabsContainer ? tabsContainer.querySelectorAll('.css-tab-btn') : [];
        tabButtons.forEach((tab) => {
            tab.addEventListener('click', function () {
                tabButtons.forEach((t) => t.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.getAttribute('data-filter');
                applyFilters();
            });
        });

        document.querySelectorAll('.css-summary-card').forEach((card) => {
            card.addEventListener('click', function () {
                const target = this.getAttribute('data-filter-target');
                const matchingTab = document.querySelector('.css-tab-btn[data-filter="' + target + '"]');
                if (matchingTab) matchingTab.click();
            });
        });

        const timeframeSelect = document.getElementById('css-admin-timeframe');
        if (timeframeSelect) {
            timeframeSelect.addEventListener('change', function () {
                activeTimeframe = this.value;
                applyFilters();
            });
        }

        const searchInput = document.getElementById('css-admin-search');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                searchTerm = this.value.trim().toLowerCase();
                applyFilters();
            });
        }

        applyFilters();
    }

    // ---- فیلتر تب و جستجو در جدول «دقت سیگنال‌ها» ----
    const accuracyTable = document.getElementById('css-accuracy-table');
    if (accuracyTable) {
        let accActiveFilter = 'all';
        let accSearchTerm = '';
        const accTabsContainer = document.getElementById('css-accuracy-filter-tabs');
        const accTabButtons = accTabsContainer ? accTabsContainer.querySelectorAll('.css-tab-btn') : [];

        function applyAccuracyFilters() {
            const rows = accuracyTable.querySelectorAll('tbody tr');
            rows.forEach((row) => {
                const outcome = row.getAttribute('data-outcome');
                const search = row.getAttribute('data-search') || '';
                const matchesFilter = accActiveFilter === 'all' || outcome === accActiveFilter;
                const matchesSearch = !accSearchTerm || search.indexOf(accSearchTerm) !== -1;
                row.style.display = (matchesFilter && matchesSearch) ? '' : 'none';
            });
        }

        accTabButtons.forEach((tab) => {
            tab.addEventListener('click', function () {
                accTabButtons.forEach((t) => t.classList.remove('active'));
                this.classList.add('active');
                accActiveFilter = this.getAttribute('data-filter');
                applyAccuracyFilters();
            });
        });

        const accSearchInput = document.getElementById('css-accuracy-search');
        if (accSearchInput) {
            accSearchInput.addEventListener('input', function () {
                accSearchTerm = this.value.trim().toLowerCase();
                applyAccuracyFilters();
            });
        }
    }

    function callAjax(action) {
        return $.post(CSS_Admin_Data.ajax_url, {
            action: action,
            nonce: CSS_Admin_Data.nonce,
        });
    }

    // ---- دکمه اسکن الان + لغو اسکن ----
    const btn = $('#css-scan-now');
    const cancelBtn = $('#css-cancel-scan');
    const progress = $('#css-scan-progress');
    let scanning = false;

    if (btn.length) {
        function pollBatches() {
            if (!scanning) return;

            callAjax('css_manual_process_batch').done(function (res) {
                if (!scanning) return;

                if (!res.success) {
                    progress.text('خطا در پردازش صف.');
                    scanning = false;
                    btn.prop('disabled', false);
                    cancelBtn.hide();
                    return;
                }

                const status = res.data;
                const remaining = status.remaining || 0;
                const total = status.total || 0;
                const coinsDone = status.coins_done !== undefined ? status.coins_done : (total - remaining);

                if (status.rate_limited) {
                    progress.text('محدودیت نرخ Provider — در حال مکث... (' + coinsDone + ' از ' + total + ' ارز)');
                } else {
                    progress.text('در حال اسکن... ' + coinsDone + ' از ' + total + ' ارز پردازش شد');
                }

                if (remaining > 0) {
                    setTimeout(pollBatches, 800);
                } else {
                    progress.text('اسکن کامل شد. در حال بارگذاری نتایج...');
                    scanning = false;
                    cancelBtn.hide();
                    setTimeout(function () { location.reload(); }, 1200);
                }
            }).fail(function () {
                progress.text('خطا در ارتباط با سرور.');
                scanning = false;
                btn.prop('disabled', false);
                cancelBtn.hide();
            });
        }

        btn.on('click', function () {
            btn.prop('disabled', true);
            cancelBtn.show().prop('disabled', false);
            scanning = true;
            progress.text('در حال شروع اسکن...');

            callAjax('css_manual_start_scan').done(function (res) {
                if (!res.success) {
                    progress.text('خطا در شروع اسکن.');
                    scanning = false;
                    btn.prop('disabled', false);
                    cancelBtn.hide();
                    return;
                }
                if (res.data && res.data.already_running) {
                    progress.text(res.data.message || 'یک اسکن دیگر در حال اجراست...');
                }
                pollBatches();
            });
        });

        cancelBtn.on('click', function () {
            if (!confirm('آیا مطمئن هستید که می‌خواهید اسکن را لغو کنید؟')) {
                return;
            }
            scanning = false;
            cancelBtn.prop('disabled', true);
            progress.text('در حال لغو اسکن...');

            callAjax('css_cancel_scan').done(function (res) {
                if (res.success) {
                    progress.text('اسکن لغو شد.');
                } else {
                    progress.text('خطا در لغو اسکن.');
                }
                setTimeout(function () { location.reload(); }, 1000);
            });
        });
    }

    // ---- دکمه بررسی دقت سیگنال‌ها الان ----
    const evalBtn = $('#css-evaluate-now');
    const evalProgress = $('#css-evaluate-progress');

    if (evalBtn.length) {
        function pollEvaluate() {
            callAjax('css_manual_evaluate_batch').done(function (res) {
                if (!res.success) {
                    evalProgress.text('خطا در بررسی دقت.');
                    evalBtn.prop('disabled', false);
                    return;
                }
                const data = res.data;
                if (data.rate_limited) {
                    evalProgress.text('به محدودیت نرخ Provider فعال خورده‌ایم؛ در حال مکث... (' + data.remaining + ' مورد باقی مانده)');
                    setTimeout(pollEvaluate, 3000);
                    return;
                }
                if (data.remaining > 0) {
                    evalProgress.text(data.remaining + ' مورد دیگر باقی مانده، در حال بررسی...');
                    setTimeout(pollEvaluate, 500);
                } else {
                    evalProgress.text('بررسی دقت تمام سیگنال‌های آماده انجام شد. در حال بارگذاری نتایج...');
                    setTimeout(function () { location.reload(); }, 1200);
                }
            });
        }

        evalBtn.on('click', function () {
            evalBtn.prop('disabled', true);
            evalProgress.text('در حال شروع بررسی...');
            pollEvaluate();
        });
    }

    // ---- دکمه پاکسازی و یکپارچه‌سازی ----
    const cleanupBtn = $('#css-cleanup-now');
    const cleanupProgress = $('#css-cleanup-progress');

    if (cleanupBtn.length) {
        cleanupBtn.on('click', function () {
            if (!confirm('ارزهای قدیمی/یتیم از جدول داشبورد حذف می‌شوند (تاریخچه و پست‌تایپ‌ها دست‌نخورده می‌مانند). ادامه می‌دهید؟')) {
                return;
            }
            cleanupBtn.prop('disabled', true);
            cleanupProgress.text('در حال پاکسازی...');

            callAjax('css_cleanup_consolidate').done(function (res) {
                if (!res.success) {
                    cleanupProgress.text('خطا در پاکسازی.');
                    cleanupBtn.prop('disabled', false);
                    return;
                }
                const d = res.data;
                cleanupProgress.text(d.deleted + ' ردیف قدیمی حذف شد، ' + d.consolidated + ' پست‌تایپ جدید ساخته شد. در حال بارگذاری...');
                setTimeout(function () { location.reload(); }, 1500);
            });
        });
    }
});

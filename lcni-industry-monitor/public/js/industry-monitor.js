(function () {
    function postData(metric, limit, timeframe) {
        var form = new FormData();
        form.append('action', 'lcni_industry_data');
        form.append('nonce', LCNIIndustryMonitor.nonce);
        form.append('metric', metric);
        form.append('limit', String(limit));
        form.append('timeframe', timeframe || '1D');

        return fetch(LCNIIndustryMonitor.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: form
        }).then(function (response) {
            return response.json();
        });
    }

    function buildFilterUrl(industryName) {
        var base = LCNIIndustryMonitor.filterBaseUrl || window.location.origin;
        var url;
        try {
            url = new URL(base, window.location.origin);
        } catch (e) {
            url = new URL(window.location.href);
        }
        url.searchParams.set('apply_filter', '1');
        url.searchParams.set('name_icb2', industryName || '');
        return url.toString();
    }

    function passesRule(value, rule) {
        if (typeof value !== 'number' || !isFinite(value)) return false;
        var target = Number(rule.value);
        if (!isFinite(target)) return false;

        if (rule.operator === '>') return value > target;
        if (rule.operator === '<') return value < target;
        return value === target;
    }

    function applyCellRules(td, value, metric) {
        var rules = Array.isArray(LCNIIndustryMonitor.cellRules) ? LCNIIndustryMonitor.cellRules : [];
        rules.forEach(function (rule) {
            if (!rule || rule.field !== metric) return;
            if (passesRule(value, rule)) {
                td.style.backgroundColor = String(rule.bg_color || '');
                td.style.color = String(rule.text_color || '');
            }
        });
    }

    function renderTable(data, metric) {
        var headerRow = document.getElementById('lcni-industry-header-row');
        var body = document.getElementById('lcni-industry-body');
        if (!headerRow || !body) return;

        var industryHead = headerRow.querySelector('.lcni-industry-monitor__sticky-industry');
        headerRow.innerHTML = '';
        if (industryHead) {
            headerRow.appendChild(industryHead);
        }
        body.innerHTML = '';

        (data.columns || []).slice().reverse().forEach(function (eventTime) {
            var th = document.createElement('th');
            th.className = 'lcni-industry-monitor__event-time';
            th.textContent = String(eventTime);
            headerRow.appendChild(th);
        });

        var rows = data.rows || [];
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.className = 'lcni-industry-monitor__row';

            if (LCNIIndustryMonitor.rowHoverEnabled) {
                tr.classList.add('is-hoverable');
            }

            var industryName = row.industry || '';
            tr.dataset.industry = industryName;

            var industryCell = document.createElement('th');
            industryCell.className = 'lcni-industry-monitor__sticky-col';
            industryCell.textContent = industryName;
            tr.appendChild(industryCell);

            (row.values || []).slice().reverse().forEach(function (value) {
                var td = document.createElement('td');
                if (value === null || typeof value === 'undefined') {
                    td.textContent = '-';
                } else {
                    td.textContent = String(value);
                    applyCellRules(td, Number(value), metric);
                }
                tr.appendChild(td);
            });

            tr.addEventListener('click', function () {
                if (!industryName) return;
                window.location.href = buildFilterUrl(industryName);
            });

            body.appendChild(tr);
        });
    }

    function loadData() {
        var metricEl = document.getElementById('lcni-industry-metric');
        if (!metricEl || !metricEl.value) return;

        var limit = parseInt(LCNIIndustryMonitor.defaultSessionLimit, 10);
        if (isNaN(limit) || limit < 1) {
            limit = 30;
        }

        var timeframe = String(LCNIIndustryMonitor.defaultTimeframe || '1D').toUpperCase();

        postData(metricEl.value, limit, timeframe)
            .then(function (payload) {
                if (!payload || !payload.success) return;
                renderTable(payload.data || {}, metricEl.value);
            })
            .catch(function () {
                // no-op
            });
    }

    function setupMetricDropdown() {
        var dropdown = document.getElementById('lcni-metric-dropdown');
        var toggle = document.getElementById('lcni-industry-metric-toggle');
        var menu = document.getElementById('lcni-industry-metric-menu');
        var search = document.getElementById('lcni-industry-metric-search');
        var metricEl = document.getElementById('lcni-industry-metric');
        var optionsWrap = document.getElementById('lcni-industry-metric-options');
        if (!dropdown || !toggle || !menu || !search || !metricEl || !optionsWrap) return;

        function selectMetric(value, label) {
            metricEl.value = value;
            toggle.textContent = label;
            menu.hidden = true;
            loadData();
        }

        toggle.addEventListener('click', function () {
            menu.hidden = !menu.hidden;
            if (!menu.hidden) search.focus();
        });

        document.addEventListener('click', function (event) {
            if (!dropdown.contains(event.target)) {
                menu.hidden = true;
            }
        });

        optionsWrap.addEventListener('click', function (event) {
            var target = event.target;
            if (!target.classList.contains('lcni-industry-monitor__metric-option')) return;
            selectMetric(String(target.dataset.value || ''), target.textContent || '');
        });

        search.addEventListener('input', function () {
            var query = String(search.value || '').toLowerCase();
            optionsWrap.querySelectorAll('.lcni-industry-monitor__metric-option').forEach(function (option) {
                var matched = option.textContent.toLowerCase().indexOf(query) !== -1;
                option.hidden = !matched;
            });
        });

        var defaultMetric = LCNIIndustryMonitor.defaultMetric;
        var firstOption = optionsWrap.querySelector('.lcni-industry-monitor__metric-option');
        var selected = optionsWrap.querySelector('.lcni-industry-monitor__metric-option[data-value="' + defaultMetric + '"]') || firstOption;
        if (selected) {
            selectMetric(String(selected.dataset.value || ''), selected.textContent || '');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupMetricDropdown();
    });
})();

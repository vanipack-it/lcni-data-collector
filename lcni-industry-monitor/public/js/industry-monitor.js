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

    function shouldApplyFormatter() {
        if (!window.LCNIFormatter) {
            return false;
        }

        if (typeof window.LCNIFormatter.shouldApply !== 'function') {
            return true;
        }

        return window.LCNIFormatter.shouldApply('industry_monitor');
    }

    function formatMetricValue(value, metric) {
        if (!shouldApplyFormatter()) {
            return String(value);
        }

        if (typeof window.LCNIFormatter.formatByField === 'function') {
            return window.LCNIFormatter.formatByField(value, metric);
        }

        if (typeof window.LCNIFormatter.formatByColumn === 'function') {
            return window.LCNIFormatter.formatByColumn(value, metric);
        }

        if (typeof window.LCNIFormatter.format === 'function') {
            return window.LCNIFormatter.format(value, 'price');
        }

        return String(value);
    }

    function formatEventTimeValue(rawValue, fallbackValue) {
        if (!shouldApplyFormatter()) {
            return String(fallbackValue || rawValue || '');
        }

        if (typeof window.LCNIFormatter.formatByField === 'function') {
            return window.LCNIFormatter.formatByField(rawValue, 'event_time');
        }

        return String(fallbackValue || rawValue || '');
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

    function findRowGradientRule(metric) {
        var rules = Array.isArray(LCNIIndustryMonitor.rowGradientRules) ? LCNIIndustryMonitor.rowGradientRules : [];
        for (var i = 0; i < rules.length; i += 1) {
            if (rules[i] && rules[i].field === metric) {
                return rules[i];
            }
        }
        return null;
    }

    function hexToRgb(hex) {
        var value = String(hex || '').trim().replace('#', '');
        if (value.length === 3) {
            value = value.split('').map(function (char) { return char + char; }).join('');
        }
        if (!/^[0-9a-fA-F]{6}$/.test(value)) {
            return null;
        }
        return {
            r: parseInt(value.slice(0, 2), 16),
            g: parseInt(value.slice(2, 4), 16),
            b: parseInt(value.slice(4, 6), 16)
        };
    }

    function blendColor(start, end, ratio) {
        return {
            r: Math.round(start.r + (end.r - start.r) * ratio),
            g: Math.round(start.g + (end.g - start.g) * ratio),
            b: Math.round(start.b + (end.b - start.b) * ratio)
        };
    }

    function rgbToCss(color) {
        return 'rgb(' + color.r + ', ' + color.g + ', ' + color.b + ')';
    }

    function gradientColorForValue(value, min, max, rule) {
        if (!isFinite(value) || !isFinite(min) || !isFinite(max) || max <= min || !rule) {
            return '';
        }

        var start = hexToRgb(rule.start_color);
        var mid = hexToRgb(rule.mid_color);
        var end = hexToRgb(rule.end_color);
        if (!start || !mid || !end) {
            return '';
        }

        var normalized = (value - min) / (max - min);
        var smooth = normalized * normalized * (3 - (2 * normalized));
        var color;

        if (smooth <= 0.5) {
            color = blendColor(start, mid, smooth / 0.5);
        } else {
            color = blendColor(mid, end, (smooth - 0.5) / 0.5);
        }

        return rgbToCss(color);
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

        var displayColumns = Array.isArray(data.columns) ? data.columns : [];
        var rawColumns = Array.isArray(data.rawColumns) ? data.rawColumns : [];
        var columnCount = Math.max(displayColumns.length, rawColumns.length);

        for (var colIndex = columnCount - 1; colIndex >= 0; colIndex -= 1) {
            var th = document.createElement('th');
            th.className = 'lcni-industry-monitor__event-time';
            th.textContent = formatEventTimeValue(rawColumns[colIndex], displayColumns[colIndex]);
            headerRow.appendChild(th);
        }

        var rows = data.rows || [];
        var rowGradientRule = findRowGradientRule(metric);

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

            var orderedValues = (row.values || []).slice().reverse();
            var numericValues = orderedValues
                .map(function (value) { return Number(value); })
                .filter(function (value) { return isFinite(value); });
            var rowMin = numericValues.length ? Math.min.apply(Math, numericValues) : NaN;
            var rowMax = numericValues.length ? Math.max.apply(Math, numericValues) : NaN;

            orderedValues.forEach(function (value) {
                var td = document.createElement('td');
                if (value === null || typeof value === 'undefined') {
                    td.textContent = '-';
                } else {
                    var numericValue = Number(value);
                    td.textContent = formatMetricValue(numericValue, metric);

                    var gradientColor = gradientColorForValue(numericValue, rowMin, rowMax, rowGradientRule);
                    if (gradientColor) {
                        td.style.backgroundColor = gradientColor;
                    }

                    applyCellRules(td, numericValue, metric);
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

(function () {
    function hexToRgb(hex) {
        if (!hex) return null;
        var normalized = String(hex).replace('#', '');
        if (normalized.length === 3) {
            normalized = normalized.split('').map(function (c) { return c + c; }).join('');
        }
        if (normalized.length !== 6) return null;
        var intVal = parseInt(normalized, 16);
        if (isNaN(intVal)) return null;
        return {
            r: (intVal >> 16) & 255,
            g: (intVal >> 8) & 255,
            b: intVal & 255
        };
    }

    function blendColor(startHex, endHex, ratio) {
        var start = hexToRgb(startHex);
        var end = hexToRgb(endHex);
        if (!start || !end) return '';
        var clamp = Math.max(0, Math.min(1, ratio));
        var r = Math.round(start.r + (end.r - start.r) * clamp);
        var g = Math.round(start.g + (end.g - start.g) * clamp);
        var b = Math.round(start.b + (end.b - start.b) * clamp);
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    function postData(metric, timeframe, limit) {
        var form = new FormData();
        form.append('action', 'lcni_industry_data');
        form.append('nonce', LCNIIndustryMonitor.nonce);
        form.append('metric', metric);
        form.append('timeframe', timeframe);
        form.append('limit', String(limit));

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

    function applyGradient(tr, rowIndex, colIndex, totalRows, totalCols) {
        var mode = LCNIIndustryMonitor.gradientMode || 'none';
        if (mode === 'none') {
            return;
        }
        var ratio = 0;
        if (mode === 'row') {
            ratio = totalRows > 1 ? rowIndex / (totalRows - 1) : 0;
        } else if (mode === 'column') {
            ratio = totalCols > 1 ? colIndex / (totalCols - 1) : 0;
        }
        var color = blendColor(LCNIIndustryMonitor.gradientStartColor, LCNIIndustryMonitor.gradientEndColor, ratio);
        if (color) {
            tr.children[colIndex + 1].style.backgroundColor = color;
        }
    }

    function filterMetricOptions() {
        var metricEl = document.getElementById('lcni-industry-metric');
        var searchEl = document.getElementById('lcni-industry-metric-search');
        if (!metricEl || !searchEl) {
            return;
        }

        var query = String(searchEl.value || '').toLowerCase();
        var firstVisible = null;
        Array.prototype.forEach.call(metricEl.options, function (option) {
            var matched = option.text.toLowerCase().indexOf(query) !== -1;
            option.hidden = !matched;
            if (matched && !firstVisible) {
                firstVisible = option;
            }
        });

        if (firstVisible && firstVisible !== metricEl.selectedOptions[0]) {
            metricEl.value = firstVisible.value;
            loadData();
        }
    }

    function renderTable(data) {
        var headerRow = document.getElementById('lcni-industry-header-row');
        var body = document.getElementById('lcni-industry-body');
        if (!headerRow || !body) {
            return;
        }

        headerRow.innerHTML = '<th class="lcni-industry-monitor__sticky-industry">Industry</th>';
        body.innerHTML = '';

        (data.columns || []).slice().reverse().forEach(function (eventTime) {
            var th = document.createElement('th');
            th.textContent = String(eventTime);
            headerRow.appendChild(th);
        });

        var rows = data.rows || [];
        rows.forEach(function (row, rowIndex) {
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

            (row.values || []).slice().reverse().forEach(function (value, colIndex, rowValues) {
                var td = document.createElement('td');
                td.textContent = (value === null || typeof value === 'undefined') ? '-' : String(value);
                tr.appendChild(td);
                applyGradient(tr, rowIndex, colIndex, rows.length, rowValues.length);
            });

            tr.addEventListener('click', function () {
                if (!industryName) {
                    return;
                }
                window.location.href = buildFilterUrl(industryName);
            });

            body.appendChild(tr);
        });
    }

    function loadData() {
        var metricEl = document.getElementById('lcni-industry-metric');
        var timeframeEl = document.getElementById('lcni-industry-timeframe');
        var sessionLimitEl = document.getElementById('lcni-industry-session-limit');
        if (!metricEl || !timeframeEl || !sessionLimitEl) {
            return;
        }

        var selected = metricEl.selectedOptions[0];
        if (!selected || selected.hidden) {
            return;
        }

        var limit = parseInt(sessionLimitEl.value, 10);
        if (isNaN(limit) || limit < 1) {
            limit = parseInt(LCNIIndustryMonitor.defaultSessionLimit, 10) || 30;
            sessionLimitEl.value = String(limit);
        }

        postData(metricEl.value, timeframeEl.value, limit)
            .then(function (payload) {
                if (!payload || !payload.success) {
                    return;
                }
                renderTable(payload.data || {});
            })
            .catch(function () {
                // no-op
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var metricEl = document.getElementById('lcni-industry-metric');
        var timeframeEl = document.getElementById('lcni-industry-timeframe');
        var sessionLimitEl = document.getElementById('lcni-industry-session-limit');
        var metricSearchEl = document.getElementById('lcni-industry-metric-search');
        if (!metricEl || !timeframeEl || !sessionLimitEl || !metricSearchEl) {
            return;
        }

        if (LCNIIndustryMonitor.defaultMetric) {
            metricEl.value = LCNIIndustryMonitor.defaultMetric;
        }
        if (LCNIIndustryMonitor.defaultTimeframe) {
            timeframeEl.value = LCNIIndustryMonitor.defaultTimeframe;
        }
        if (LCNIIndustryMonitor.defaultSessionLimit) {
            sessionLimitEl.value = String(LCNIIndustryMonitor.defaultSessionLimit);
        }

        metricEl.addEventListener('change', loadData);
        timeframeEl.addEventListener('change', loadData);
        sessionLimitEl.addEventListener('change', loadData);
        metricSearchEl.addEventListener('input', filterMetricOptions);
        loadData();
    });
})();

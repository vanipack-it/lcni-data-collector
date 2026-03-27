(function () {
    function shouldApplyFormatter() {
        if (!window.LCNIFormatter) return false;
        if (typeof window.LCNIFormatter.shouldApply !== 'function') return true;
        return window.LCNIFormatter.shouldApply('industry_monitor');
    }

    function formatMetricValue(value, metric) {
        if (!shouldApplyFormatter()) return String(value);
        if (typeof window.LCNIFormatter.formatByField === 'function') return window.LCNIFormatter.formatByField(value, metric);
        if (typeof window.LCNIFormatter.formatByColumn === 'function') return window.LCNIFormatter.formatByColumn(value, metric);
        if (typeof window.LCNIFormatter.format === 'function') return window.LCNIFormatter.format(value, 'price');
        return String(value);
    }

    function formatEventTimeValue(rawValue, fallbackValue) {
        if (!shouldApplyFormatter()) return String(fallbackValue || rawValue || '');
        if (typeof window.LCNIFormatter.formatByField === 'function') return window.LCNIFormatter.formatByField(rawValue, 'event_time');
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

    function hexToRgb(hex) {
        var value = String(hex || '').trim().replace('#', '');
        if (value.length === 3) value = value.split('').map(function (char) { return char + char; }).join('');
        if (!/^[0-9a-fA-F]{6}$/.test(value)) return null;
        return { r: parseInt(value.slice(0, 2), 16), g: parseInt(value.slice(2, 4), 16), b: parseInt(value.slice(4, 6), 16) };
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

    function initMonitor(root) {
        var monitorId = root.getAttribute('data-monitor-id') || '';
        var config = window.LCNIIndustryMonitors && window.LCNIIndustryMonitors[monitorId];
        if (!config) return;

        var wrap = root.querySelector('.lcni-industry-monitor__table-wrap');
        var table = root.querySelector('.lcni-industry-monitor__table');
        var metricEl = root.querySelector('.lcni-industry-metric');
        var metricChipsEl = root.querySelector('.lcni-im-metric-chips');
        var headerRow = root.querySelector('.lcni-industry-header-row');
        var body = root.querySelector('.lcni-industry-body');
        var fullLinkWrap = root.querySelector('.lcni-industry-monitor__full-link-wrap');
        var fullLink = root.querySelector('.lcni-industry-monitor__full-link');

        function postData(metric, limit, timeframe) {
            var form = new FormData();
            form.append('action', config.ajaxAction || 'lcni_im_data');
            form.append('nonce', config.nonce);
            form.append('metric', metric);
            form.append('limit', String(limit));
            form.append('timeframe', timeframe || '1D');
            if (Array.isArray(config.idIcb2) && config.idIcb2.length) {
                form.append('id_icb2', config.idIcb2.join(','));
            }
            if (config.monitorId) {
                form.append('monitorId', String(config.monitorId));
            }
            if (Array.isArray(config.symbols) && config.symbols.length) {
                form.append('symbols', config.symbols.join(','));
            }
            // icb mode: gửi sync_symbols để server convert sang id_icb2
            if (Array.isArray(config.syncSymbols) && config.syncSymbols.length) {
                form.append('sync_symbols', config.syncSymbols.join(','));
            }

            return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form }).then(function (response) {
                return response.json();
            });
        }

        function buildFilterUrl(rowLabel) {
            var base = config.filterBaseUrl || window.location.origin;
            var url;
            try { url = new URL(base, window.location.origin); } catch (e) { url = new URL(window.location.href); }
            url.searchParams.set('apply_filter', '1');
            var paramKey = config.filterParamKey || 'name_icb2';
            url.searchParams.set(paramKey, rowLabel || '');
            return url.toString();
        }

        // Broadcast symbol/industry khi click row — các module khác (filter, watchlist) có thể lắng nghe
        function broadcastRowClick(rowLabel) {
            var paramKey = config.filterParamKey || 'name_icb2';
            var detail = { label: rowLabel, paramKey: paramKey, mode: config.mode || 'icb' };
            // Custom event cho cùng page
            document.dispatchEvent(new CustomEvent('lcni:im:rowclick', { detail: detail, bubbles: true }));
            // Nếu mode=symbol: đồng bộ sang filter/watchlist shortcode trên cùng page
            if (config.mode === 'symbol' && rowLabel) {
                document.dispatchEvent(new CustomEvent('lcni:symbol:select', {
                    detail: { symbol: rowLabel },
                    bubbles: true
                }));
            }
        }

        function applyCellRules(td, value, metric) {
            var rules = Array.isArray(config.cellRules) ? config.cellRules : [];
            rules.forEach(function (rule) {
                if (!rule || rule.field !== metric) return;
                if (passesRule(value, rule)) {
                    td.style.backgroundColor = String(rule.bg_color || '');
                    td.style.color = String(rule.text_color || '');
                }
            });
        }

        function findRowGradientRule(metric) {
            var rules = Array.isArray(config.rowGradientRules) ? config.rowGradientRules : [];
            for (var i = 0; i < rules.length; i += 1) {
                if (rules[i] && rules[i].field === metric) return rules[i];
            }
            return null;
        }

        function gradientColorForValue(value, min, max, rule) {
            if (!isFinite(value) || !isFinite(min) || !isFinite(max) || max <= min || !rule) return '';
            // Hỗ trợ cả 2 naming conventions
            var start = hexToRgb(rule.color_negative || rule.start_color);
            var mid   = hexToRgb(rule.color_neutral  || rule.mid_color);
            var end   = hexToRgb(rule.color_positive  || rule.end_color);
            if (!start || !mid || !end) return '';
            var normalized = (value - min) / (max - min);
            var smooth = normalized * normalized * (3 - (2 * normalized));
            if (smooth <= 0.5) return rgbToCss(blendColor(start, mid, smooth / 0.5));
            return rgbToCss(blendColor(mid, end, (smooth - 0.5) / 0.5));
        }

        function adjustTableViewport() {
            if (!wrap || !table || !headerRow || !body) return;
            var rows = body.querySelectorAll('tr');
            if (!rows.length) {
                wrap.style.maxHeight = 'var(--lcni-table-height)';
                return;
            }

            var headerHeight = headerRow.getBoundingClientRect().height;
            var rowsHeight = 0;
            var visibleRows = Math.min(20, rows.length);
            for (var i = 0; i < visibleRows; i += 1) rowsHeight += rows[i].getBoundingClientRect().height;
            var borderWidth = parseFloat(getComputedStyle(wrap).borderTopWidth || '0') + parseFloat(getComputedStyle(wrap).borderBottomWidth || '0');
            wrap.style.maxHeight = Math.ceil(headerHeight + rowsHeight + borderWidth) + 'px';
        }

        /**
         * applyStickyColumnOffsets — tính và gán left chính xác cho multi sticky col.
         * Chuẩn hệ thống LCNI: JS override inline style, CSS chỉ cần left:0 fallback.
         */
        function applyStickyColumnOffsets(host) {
            var table = host ? host.querySelector('.lcni-table') : null;
            if (!table) return;
            var headerRow = table.querySelector('thead tr');
            if (!headerRow) return;
            var stickyThs = Array.prototype.slice.call(headerRow.querySelectorAll('th.is-sticky-col'));
            if (!stickyThs.length) return;

            // Reset trước để offsetWidth chính xác
            stickyThs.forEach(function (th) { th.style.left = ''; });
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.querySelectorAll('th.is-sticky-col, td.is-sticky-col').forEach(function (cell) { cell.style.left = ''; });
            });

            var acc = 0;
            var colOffsets = stickyThs.map(function (th) {
                var off = acc;
                acc += th.offsetWidth || 0;
                return off;
            });

            stickyThs.forEach(function (th, i) { th.style.left = colOffsets[i] + 'px'; });
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                var cells = Array.prototype.slice.call(tr.querySelectorAll('th.is-sticky-col, td.is-sticky-col'));
                cells.forEach(function (cell, i) {
                    if (i < colOffsets.length) cell.style.left = colOffsets[i] + 'px';
                });
            });
        }

        function renderTable(data, metric) {
            if (!headerRow || !body) return;

            var displayColumns = Array.isArray(data.columns) ? data.columns : [];
            var rawColumns = Array.isArray(data.rawColumns) ? data.rawColumns : [];
            var columnCount = Math.max(displayColumns.length, rawColumns.length);

            // Tránh reset header mỗi lần fetch → giữ sticky top + scroll position
            var prevColumnCount = Number(headerRow.getAttribute('data-col-count') || -1);
            var prevMetric = headerRow.getAttribute('data-metric') || '';

            if (columnCount !== prevColumnCount || metric !== prevMetric) {
                var industryHead = headerRow.querySelector('.lcni-industry-monitor__sticky-industry');
                headerRow.innerHTML = '';
                if (industryHead) headerRow.appendChild(industryHead);

                for (var colIndex = columnCount - 1; colIndex >= 0; colIndex -= 1) {
                    var th = document.createElement('th');
                    th.className = 'lcni-industry-monitor__event-time';
                    th.textContent = formatEventTimeValue(rawColumns[colIndex], displayColumns[colIndex]);
                    headerRow.appendChild(th);
                }

                headerRow.setAttribute('data-col-count', String(columnCount));
                headerRow.setAttribute('data-metric', metric);
            }

            // ── Always reset only tbody rows ─────────────────────────────────
            // KHÔNG reset header → sticky top được giữ nguyên
            body.innerHTML = '';

            var rows = data.rows || [];
            var rowGradientRule = findRowGradientRule(metric);
            rows.forEach(function (row) {
                var tr = document.createElement('tr');
                tr.className = 'lcni-industry-monitor__row';
                if (config.rowHoverEnabled) tr.classList.add('is-hoverable');

                var industryName = row.industry || '';
                var industryCell = document.createElement('th');
                industryCell.className = 'lcni-industry-monitor__sticky-col is-sticky-col';
                industryCell.textContent = industryName;
                tr.appendChild(industryCell);

                var orderedValues = (row.values || []).slice().reverse();
                var numericValues = orderedValues.map(function (value) { return Number(value); }).filter(function (value) { return isFinite(value); });
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
                        if (gradientColor) td.style.backgroundColor = gradientColor;
                        applyCellRules(td, numericValue, metric);
                    }
                    tr.appendChild(td);
                });

                tr.addEventListener('click', function () {
                    if (!industryName) return;
                    broadcastRowClick(industryName);
                    // Chỉ navigate nếu filterBaseUrl được cấu hình
                    if (config.filterBaseUrl && config.filterBaseUrl !== window.location.origin) {
                        window.location.href = buildFilterUrl(industryName);
                    }
                });

                body.appendChild(tr);
            });

            adjustTableViewport();
            requestAnimationFrame(function () {
                applyStickyColumnOffsets(root);
            });
        }

        function loadData() {
            if (!metricEl || !metricEl.value) return;
            var limit = parseInt(config.defaultSessionLimit, 10);
            if (isNaN(limit) || limit < 1) limit = 30;
            var timeframe = String(config.defaultTimeframe || '1D').toUpperCase();

            postData(metricEl.value, limit, timeframe)
                .then(function (payload) {
                    if (!payload || !payload.success) return;
                    renderTable(payload.data || {}, metricEl.value);
                })
                .catch(function () {});
        }

        function setupCompactFullLink() {
            if (!fullLinkWrap || !fullLink) return;
            if (!config.showFullTableButton || !config.fullTableUrl) return;
            fullLink.href = String(config.fullTableUrl);
            fullLinkWrap.hidden = false;
        }

        function setupMetricChips() {
            if (!metricChipsEl || !metricEl) return;

            function selectMetric(value) {
                metricEl.value = value;

                // Update active state
                metricChipsEl.querySelectorAll('.lcni-im-mchip').forEach(function (chip) {
                    chip.classList.toggle('is-active', chip.dataset.value === value);
                });

                // Dùng initialPayload nếu đây là metric mặc định VÀ payload có dữ liệu
                var payload = config.initialPayload;
                if (payload && value === String(config.defaultMetric || '')) {
                    var hasData = Array.isArray(payload.columns) && payload.columns.length > 0;
                    config.initialPayload = null;
                    if (hasData) {
                        renderTable(payload, value);
                        return;
                    }
                }
                loadData();
            }

            metricChipsEl.addEventListener('click', function (e) {
                var chip = e.target.closest('.lcni-im-mchip');
                if (!chip) return;
                selectMetric(String(chip.dataset.value || ''));
            });

            // Select default metric on init
            var defaultValue = String(config.defaultMetric || '');
            var defaultChip = metricChipsEl.querySelector('.lcni-im-mchip[data-value="' + defaultValue + '"]')
                           || metricChipsEl.querySelector('.lcni-im-mchip');
            if (defaultChip) selectMetric(String(defaultChip.dataset.value || ''));

            // ── Symbol sync — hoạt động với cả mode icb và symbol ──
            function onSymbolsChanged(symbols) {
                if (!Array.isArray(symbols) || !symbols.length) return;
                var newList = symbols
                    .map(function(s) { return String(s || '').toUpperCase().trim(); })
                    .filter(Boolean);
                var key = config.mode === 'symbol' ? 'symbols' : 'syncSymbols';
                var oldStr = (config[key] || []).slice().sort().join(',');
                var newStr = newList.slice().sort().join(',');
                if (oldStr === newStr) return;
                config[key] = newList;
                if (metricEl.value) loadData();
            }

            document.addEventListener('lcni:symbolsChanged', function(e) {
                if (e.detail && Array.isArray(e.detail.symbols)) onSymbolsChanged(e.detail.symbols);
            });
            window.addEventListener('lcniWatchlistSymbolsChanged', function(e) {
                if (Array.isArray(e.detail)) onSymbolsChanged(e.detail);
            });
        }

        setupMetricChips();
        setupCompactFullLink();
        window.addEventListener('resize', adjustTableViewport);

        // Touch scroll handler — sticky col/header hoạt động trên mobile
        if (wrap && !wrap.dataset.touchBound) {
            wrap.dataset.touchBound = '1';
            var _sx=0, _sy=0, _sleft=0, _stop=0, _locked=null;
            wrap.addEventListener('touchstart', function(e) {
                var t = e.touches[0];
                _sx = t.clientX; _sy = t.clientY;
                _sleft = wrap.scrollLeft; _stop = wrap.scrollTop;
                _locked = null;
            }, {passive: true});
            wrap.addEventListener('touchmove', function(e) {
                if (e.touches.length !== 1) return;
                var t = e.touches[0];
                var dx = t.clientX - _sx, dy = t.clientY - _sy;
                if (_locked === null) {
                    if (Math.sqrt(dx*dx + dy*dy) < 8) return;
                    _locked = Math.abs(dx) > Math.abs(dy);
                }
                if (_locked) {
                    e.preventDefault();
                    wrap.scrollLeft = _sleft - dx;
                } else {
                    var atTop = wrap.scrollTop <= 0;
                    var atBot = wrap.scrollTop >= wrap.scrollHeight - wrap.clientHeight;
                    if ((dy > 0 && atTop) || (dy < 0 && atBot)) return;
                    e.preventDefault();
                    wrap.scrollTop = _stop - dy;
                }
            }, {passive: false});
            wrap.addEventListener('touchend',    function() { _locked = null; }, {passive: true});
            wrap.addEventListener('touchcancel', function() { _locked = null; }, {passive: true});
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.lcni-industry-monitor').forEach(initMonitor);
    });
})();

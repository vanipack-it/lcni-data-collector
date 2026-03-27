/**
 * custom-index-chart.js
 * ECharts candlestick cho chỉ số tùy chỉnh (Value-Weighted)
 * REST: GET /lcni/v1/custom-indexes/{id}/candles?timeframe=1D&limit=200
 */
(function () {
    'use strict';

    var INSTANCES = {};  // uid → { chart_main, chart_vol, config, data }

    /* ── Khởi tạo tất cả widget trên trang ───────────────────── */
    function initAll() {
        document.querySelectorAll('.lcni-ci-wrap').forEach(function (wrap) {
            if (wrap.dataset._init) return;
            wrap.dataset._init = '1';
            var uid = wrap.id;
            var cfg;
            try { cfg = JSON.parse(wrap.dataset.config); } catch (e) { return; }
            INSTANCES[uid] = { config: cfg, data: null, chart_main: null, chart_vol: null };
            loadData(uid);
        });
    }

    /* ── Load data từ REST API ────────────────────────────────── */
    function loadData(uid) {
        var inst = INSTANCES[uid];
        var cfg  = inst.config;
        showLoader(uid, true);

        var url = cfg.api_url
            + '?timeframe=' + encodeURIComponent(cfg.timeframe)
            + '&limit='     + encodeURIComponent(cfg.limit);

        fetch(url, {
            headers: { 'X-WP-Nonce': cfg.nonce || '' }
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
        .then(function (res) {
            inst.data = (res.candles || []);
            showLoader(uid, false);
            renderChart(uid);
            updateBreadth(uid);
            updatePct(uid);
        })
        .catch(function (err) {
            showLoader(uid, false);
            showError(uid, 'Không tải được dữ liệu: ' + err);
        });
    }

    /* ── Render ECharts ──────────────────────────────────────── */
    function renderChart(uid) {
        var inst = INSTANCES[uid];
        var data = inst.data;
        var cfg  = inst.config;

        if (!data || !data.length) {
            showError(uid, 'Không có dữ liệu để hiển thị.');
            return;
        }

        /* ── Format dữ liệu ─────────────────────────────────── */
        var dates      = data.map(function (r) {
            return formatDate(parseInt(r.event_time, 10) * 1000);
        });
        var ohlc       = data.map(function (r) {
            return [
                parseFloat(r.open)  || 0,
                parseFloat(r.close) || 0,
                parseFloat(r.low)   || 0,
                parseFloat(r.high)  || 0,
            ];
        });
        var vol        = data.map(function (r) {
            // value_traded in nghìn đồng → chuyển sang tỷ đồng cho dễ đọc
            return +(parseFloat(r.value) / 1e6).toFixed(2);
        });
        var so_tang    = data.map(function (r) { return parseInt(r.so_tang, 10) || 0; });
        var so_giam    = data.map(function (r) { return parseInt(r.so_giam, 10) || 0; });

        var up_color   = '#3fb950';
        var down_color = '#f85149';

        /* ── Dark theme colors ───────────────────────────────── */
        var BG        = '#0d1117';
        var GRID_LINE = 'rgba(255,255,255,.06)';
        var AXIS_LINE = 'rgba(255,255,255,.12)';
        var TEXT_MUTED= '#8b949e';
        var TEXT_MAIN = '#e6edf3';
        var TOOLTIP_BG= '#1c2128';

        /* ── Main chart (line close + MA20/MA50) ────────────── */
        var mainEl = document.getElementById(uid + '-main');
        if (!mainEl || !window.echarts) return;

        var existing = window.echarts.getInstanceByDom(mainEl);
        if (existing) existing.dispose();
        inst.chart_main = window.echarts.init(mainEl, null, { renderer: 'canvas', backgroundColor: BG });

        // close values cho line + MA
        var closes = data.map(function (r) { return parseFloat(r.close) || 0; });
        var ma20 = calcMA(closes, 20);
        var ma50 = calcMA(closes, 50);

        var mainOption = {
            animation:       false,
            backgroundColor: BG,
            tooltip:    {
                trigger: 'axis',
                axisPointer: { type: 'cross', lineStyle: { color: AXIS_LINE }, crossStyle: { color: AXIS_LINE } },
                backgroundColor: TOOLTIP_BG,
                borderColor:     AXIS_LINE,
                textStyle:       { color: TEXT_MAIN, fontSize: 12 },
                formatter: function (params) {
                    var date  = params[0] && params[0].name ? params[0].name : '';
                    var lines = ['<b>' + cfg.title + '</b> ' + date];
                    params.forEach(function (p) {
                        if (p.value !== '-' && p.value != null) {
                            lines.push(p.seriesName + ': ' + fmt(p.value));
                        }
                    });
                    return lines.join('<br>');
                },
            },
            legend:  {
                data: [cfg.title, 'MA20', 'MA50'],
                bottom: 8,
                textStyle: { fontSize: 11, color: TEXT_MUTED }
            },
            grid:    { left: 60, right: 20, top: 20, bottom: 50 },
            dataZoom: [
                { type: 'inside', start: Math.max(0, 100 - Math.round(120 / data.length * 100)), end: 100 },
                {
                    type: 'slider', bottom: 0, height: 22,
                    backgroundColor: 'rgba(255,255,255,.04)',
                    dataBackground:  { lineStyle: { color: AXIS_LINE }, areaStyle: { color: 'rgba(255,255,255,.04)' } },
                    selectedDataBackground: { lineStyle: { color: up_color }, areaStyle: { color: 'rgba(63,185,80,.15)' } },
                    fillerColor:    'rgba(88,166,255,.08)',
                    borderColor:    AXIS_LINE,
                    handleStyle:    { color: '#58a6ff', borderColor: '#58a6ff' },
                    textStyle:      { color: TEXT_MUTED },
                },
            ],
            xAxis:   {
                data: dates,
                boundaryGap: false,
                axisLine:  { lineStyle: { color: AXIS_LINE } },
                axisTick:  { show: false },
                axisLabel: { fontSize: 10, color: TEXT_MUTED },
                splitLine: { show: false },
            },
            yAxis:   {
                scale: true,
                splitLine: { lineStyle: { color: GRID_LINE, type: 'dashed' } },
                axisLine:  { show: false },
                axisTick:  { show: false },
                axisLabel: { fontSize: 10, color: TEXT_MUTED, formatter: function (v) { return v.toFixed(1); } },
            },
            series:  [
                {
                    name: cfg.title,
                    type: 'line',
                    data: closes,
                    smooth:     false,
                    showSymbol: false,
                    lineStyle:  { color: up_color, width: 2 },
                    itemStyle:  { color: up_color },
                    areaStyle:  { color: 'rgba(63,185,80,0.08)' },
                },
                {
                    name: 'MA20',
                    type: 'line',
                    data: ma20,
                    smooth:     true,
                    showSymbol: false,
                    lineStyle:  { color: '#e8b84b', width: 1.5 },
                    itemStyle:  { color: '#e8b84b' },
                },
                {
                    name: 'MA50',
                    type: 'line',
                    data: ma50,
                    smooth:     true,
                    showSymbol: false,
                    lineStyle:  { color: '#58a6ff', width: 1.5 },
                    itemStyle:  { color: '#58a6ff' },
                },
            ],
        };
        inst.chart_main.setOption(mainOption, true);
        addResizeObserver(inst.chart_main, mainEl);

        /* ── Volume panel (value_traded tỷ đồng) ────────────── */
        if (cfg.show_volume) {
            var volEl = document.getElementById(uid + '-vol');
            if (volEl) {
                var existVol = window.echarts.getInstanceByDom(volEl);
                if (existVol) existVol.dispose();
                inst.chart_vol = window.echarts.init(volEl, null, { renderer: 'canvas', backgroundColor: BG });

                // Màu bar theo nến tăng/giảm
                var volColors = data.map(function (r, i) {
                    return closes[i] >= (closes[i - 1] || closes[i]) ? up_color : down_color;
                });

                var volOption = {
                    animation:       false,
                    backgroundColor: BG,
                    tooltip:   {
                        trigger: 'axis',
                        backgroundColor: TOOLTIP_BG,
                        borderColor:     AXIS_LINE,
                        textStyle:       { color: TEXT_MAIN, fontSize: 11 },
                        formatter: function (p) {
                            return p[0].name + '<br>GTGD: ' + p[0].value + ' tỷ đ';
                        }
                    },
                    grid:  { left: 60, right: 20, top: 4, bottom: 20 },
                    dataZoom: [{ type: 'inside', start: mainOption.dataZoom[0].start, end: 100 }],
                    xAxis: {
                        data: dates,
                        axisLine:  { lineStyle: { color: AXIS_LINE } },
                        axisLabel: { show: false },
                        axisTick:  { show: false },
                    },
                    yAxis: {
                        splitLine: { show: false },
                        axisLine:  { show: false },
                        axisTick:  { show: false },
                        axisLabel: { fontSize: 9, color: TEXT_MUTED, formatter: function (v) {
                            return v >= 1000 ? (v/1000).toFixed(0) + 'k' : v;
                        }}
                    },
                    series: [{
                        type: 'bar',
                        data: vol,
                        barMaxWidth: 6,
                        itemStyle: { color: function (p) { return volColors[p.dataIndex]; } },
                    }],
                };
                inst.chart_vol.setOption(volOption, true);
                addResizeObserver(inst.chart_vol, volEl);

                // Sync dataZoom
                inst.chart_main.on('datazoom', function (e) {
                    inst.chart_vol && inst.chart_vol.dispatchAction({
                        type: 'dataZoom', start: e.start || 0, end: e.end || 100
                    });
                });
            }
        }
    }

    /* ── Breadth: cập nhật số mã tăng/giảm từ phiên cuối ─────── */
    function updateBreadth(uid) {
        var inst = INSTANCES[uid];
        if (!inst.data || !inst.data.length) return;
        var last = inst.data[inst.data.length - 1];
        setText(uid + '-so-tang', formatNum(last.so_tang));
        setText(uid + '-so-giam', formatNum(last.so_giam));
        setText(uid + '-so-ma',   formatNum(last.so_ma));
    }

    /* ── % thay đổi so với phiên trước ──────────────────────── */
    function updatePct(uid) {
        var inst = INSTANCES[uid];
        var data = inst.data;
        if (!data || data.length < 2) return;
        var cur  = parseFloat(data[data.length - 1].close);
        var prev = parseFloat(data[data.length - 2].close);
        if (!prev) return;
        var pct  = (cur - prev) / prev * 100;
        var sign = pct >= 0 ? '+' : '';
        var el   = document.getElementById(uid + '-pct');
        if (!el) return;
        el.textContent = sign + pct.toFixed(2) + '%';
        el.style.color = pct >= 0 ? '#3fb950' : '#f85149';
    }

    /* ── Public: đổi timeframe ───────────────────────────────── */
    window.lcniCiSetTf = function (uid, tf) {
        var inst = INSTANCES[uid];
        if (!inst) return;
        inst.config.timeframe = tf;

        // Update button states
        var wrap = document.getElementById(uid);
        if (wrap) {
            wrap.querySelectorAll('.lcni-ci-tf-btn').forEach(function (btn) {
                btn.classList.toggle('active', btn.dataset.tf === tf);
            });
        }
        loadData(uid);
    };

    /* ── Helpers ─────────────────────────────────────────────── */
    function calcMA(values, period) {
        return values.map(function (_, i) {
            if (i < period - 1) return '-';
            var sum = 0;
            for (var j = i - period + 1; j <= i; j++) sum += values[j];
            return +(sum / period).toFixed(2);
        });
    }

    function formatDate(ms) {
        var d = new Date(ms);
        return d.getFullYear() + '-'
            + pad(d.getMonth() + 1) + '-'
            + pad(d.getDate());
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function fmt(v) {
        return typeof v === 'number' ? v.toFixed(2) : (parseFloat(v) || 0).toFixed(2);
    }

    function formatNum(v) { return parseInt(v, 10) || 0; }

    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    function showLoader(uid, show) {
        var el = document.getElementById(uid + '-loader');
        if (el) el.style.display = show ? '' : 'none';
    }

    function showError(uid, msg) {
        var el = document.getElementById(uid + '-error');
        if (el) { el.style.display = ''; el.textContent = msg; }
    }

    function addResizeObserver(chart, el) {
        if (!window.ResizeObserver) return;
        new ResizeObserver(function () { chart && chart.resize(); }).observe(el);
    }

    /* ── Boot ────────────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

})();

(() => {
  'use strict';

  const SELECTOR = '[data-lcni-chart]';
  const DEFAULT_LIMIT = 200;
  const MAX_LIMIT = 1000;
  const DEFAULT_HEIGHT = 420;
  const MIN_HEIGHT = 260;
  const MAX_HEIGHT = 1600;
  const STORAGE_KEY = (window.LCNI_CHART_CONFIG && window.LCNI_CHART_CONFIG.storage_key) || 'lcni_chart_settings';
  const DEFAULT_INDICATORS = Object.assign({
    ma20: true,
    ma50: true,
    ma100: false,
    ma200: false,
    rsi: true,
    macd: false,
    rs_1w_by_exchange: true,
    rs_1m_by_exchange: true,
    rs_3m_by_exchange: false
  }, (window.LCNI_CHART_CONFIG && window.LCNI_CHART_CONFIG.default_indicators) || {});
  const instances = new WeakMap();

  // ── Signal config ──────────────────────────────────────────────────
  const SIGNALS_API_BASE = (window.LCNI_CHART_CONFIG && window.LCNI_CHART_CONFIG.signals_api_base) || '';
  const REST_NONCE       = (window.LCNI_CHART_CONFIG && window.LCNI_CHART_CONFIG.nonce) || '';

  function fetchSignals(symbol) {
    if (!SIGNALS_API_BASE || !REST_NONCE) return Promise.resolve([]);
    const url = new URL(SIGNALS_API_BASE, window.location.origin);
    url.searchParams.set('symbol', symbol);
    return fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-WP-Nonce': REST_NONCE }
    })
      .then((r) => (r.ok ? r.json() : { signals: [] }))
      .then((payload) => (Array.isArray(payload.signals) ? payload.signals : []))
      .catch(() => []);
  }

  function formatByType(value, type) {
    if (window.LCNIFormatter && typeof window.LCNIFormatter.format === 'function') {
      return window.LCNIFormatter.format(value, type);
    }

    const number = Number(value);
    return Number.isFinite(number) ? String(number) : '-';
  }

  function buildTooltipFormatter(sourceRows) {
    return (params) => {
      const rows = [];
      const items = Array.isArray(params) ? params : [];
      if (!items.length) return '';

      rows.push(String(items[0].axisValueLabel || items[0].name || ''));
      items.forEach((item) => {
        const series = String(item.seriesName || '');
        const marker = item.marker || '';
        let value = item.value;
        let type = 'price';

        if (series === 'Volume') type = 'volume';
        if (series.indexOf('RSI') === 0) type = 'rsi';
        if (series === 'MACD' || series === 'Signal' || series === 'Histogram') type = 'macd';
        if (series.indexOf('RS ') === 0) type = 'rs';

        if (item.seriesType === 'candlestick' && Array.isArray(sourceRows) && sourceRows[item.dataIndex]) {
          const candle = sourceRows[item.dataIndex];
          const open = formatByType(candle.open, 'price');
          const close = formatByType(candle.close, 'price');
          const low = formatByType(candle.low, 'price');
          const high = formatByType(candle.high, 'price');
          rows.push(`${marker}${series}: O ${open} | C ${close} | L ${low} | H ${high}`);
          return;
        }

        if (Array.isArray(value) && value.length >= 4) {
          const open = formatByType(value[0], 'price');
          const close = formatByType(value[1], 'price');
          const low = formatByType(value[2], 'price');
          const high = formatByType(value[3], 'price');
          rows.push(`${marker}${series}: O ${open} | C ${close} | L ${low} | H ${high}`);
          return;
        }

        rows.push(`${marker}${series}: ${formatByType(value, type)}`);
      });

      return rows.join('<br/>');
    };
  }

  function sanitizeSymbol(value) { const s = String(value || '').trim().toUpperCase(); return /^[A-Z0-9._-]{1,20}$/.test(s) ? s : ''; }
  function parseLimit(v) { const n = Number.parseInt(String(v || ''), 10); return Number.isFinite(n) && n > 0 ? Math.min(n, MAX_LIMIT) : DEFAULT_LIMIT; }
  function parseHeight(v) { const n = Number.parseInt(String(v || ''), 10); return Number.isFinite(n) ? Math.max(MIN_HEIGHT, Math.min(n, MAX_HEIGHT)) : DEFAULT_HEIGHT; }

  function parseApiResponse(payload) {
    if (Array.isArray(payload)) return payload;
    if (!payload || typeof payload !== 'object') return [];
    if (Array.isArray(payload.data)) return payload.data;
    if (Array.isArray(payload.candles)) return payload.candles;
    if (payload.success && payload.data && Array.isArray(payload.data.rows)) return payload.data.rows;
    return [];
  }

  function toNumberOrNull(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }

  function normalizeRows(rows) {
    const normalized = [];
    rows.forEach((row) => {
      const date = String((row && row.date) || '').trim();
      const open = Number(row && row.open);
      const high = Number(row && row.high);
      const low = Number(row && row.low);
      const close = Number(row && row.close);
      if (!date || !Number.isFinite(open) || !Number.isFinite(high) || !Number.isFinite(low) || !Number.isFinite(close)) return;
      normalized.push({
        date,
        open,
        high,
        low,
        close,
        volume: Number.isFinite(Number(row && row.volume)) ? Number(row.volume) : 0,
        rs_1w_by_exchange: toNumberOrNull(row && row.rs_1w_by_exchange),
        rs_1m_by_exchange: toNumberOrNull(row && row.rs_1m_by_exchange),
        rs_3m_by_exchange: toNumberOrNull(row && row.rs_3m_by_exchange)
      });
    });
    return normalized;
  }

  function calculateMA(data, period) {
    const result = new Array(data.length).fill(null);
    if (!Array.isArray(data) || period <= 0) return result;
    let sum = 0;
    for (let i = 0; i < data.length; i += 1) {
      const value = Number(data[i]);
      if (!Number.isFinite(value)) continue;
      sum += value;
      if (i >= period) sum -= Number(data[i - period]) || 0;
      if (i >= period - 1) result[i] = Number((sum / period).toFixed(4));
    }
    return result;
  }

  function calculateRSI(data, period = 14) {
    const result = new Array(data.length).fill(null);
    if (!Array.isArray(data) || data.length <= period) return result;
    let gains = 0; let losses = 0;
    for (let i = 1; i <= period; i += 1) {
      const diff = (data[i] || 0) - (data[i - 1] || 0);
      if (diff >= 0) gains += diff; else losses += Math.abs(diff);
    }
    let avgGain = gains / period;
    let avgLoss = losses / period;
    result[period] = avgLoss === 0 ? 100 : Number((100 - (100 / (1 + (avgGain / avgLoss)))).toFixed(4));
    for (let i = period + 1; i < data.length; i += 1) {
      const diff = (data[i] || 0) - (data[i - 1] || 0);
      const gain = diff > 0 ? diff : 0;
      const loss = diff < 0 ? Math.abs(diff) : 0;
      avgGain = ((avgGain * (period - 1)) + gain) / period;
      avgLoss = ((avgLoss * (period - 1)) + loss) / period;
      result[i] = avgLoss === 0 ? 100 : Number((100 - (100 / (1 + (avgGain / avgLoss)))).toFixed(4));
    }
    return result;
  }

  function ema(values, period) {
    const result = new Array(values.length).fill(null);
    const multiplier = 2 / (period + 1);
    let prev = null;
    values.forEach((value, i) => {
      if (!Number.isFinite(value)) return;
      if (prev === null) prev = value;
      else prev = ((value - prev) * multiplier) + prev;
      result[i] = Number(prev.toFixed(4));
    });
    return result;
  }

  function calculateMACD(data, fast = 12, slow = 26, signal = 9) {
    const fastEma = ema(data, fast);
    const slowEma = ema(data, slow);
    const macd = data.map((_, i) => (fastEma[i] !== null && slowEma[i] !== null ? Number((fastEma[i] - slowEma[i]).toFixed(4)) : null));
    const signalLine = ema(macd.map((v) => (v === null ? NaN : v)), signal).map((v) => (Number.isFinite(v) ? v : null));
    const histogram = macd.map((v, i) => (v !== null && signalLine[i] !== null ? Number((v - signalLine[i]).toFixed(4)) : null));
    return { macd, signal: signalLine, histogram };
  }

  function fetchData(apiBase, symbol, limit) {
    const endpoint = new URL(apiBase, window.location.origin);
    endpoint.searchParams.set('symbol', symbol);
    endpoint.searchParams.set('limit', String(limit));
    return fetch(endpoint.toString(), { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => { if (!r.ok) throw new Error('REST request failed'); return r.json(); })
      .then((payload) => ({ symbol: sanitizeSymbol(payload.symbol) || symbol, rows: normalizeRows(parseApiResponse(payload)) }));
  }

  function loadSettings() {
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return Object.assign({}, DEFAULT_INDICATORS);
      return Object.assign({}, DEFAULT_INDICATORS, JSON.parse(raw));
    } catch (_e) {
      return Object.assign({}, DEFAULT_INDICATORS);
    }
  }

  function saveSettings(settings) {
    try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(settings)); } catch (_e) { /* noop */ }
  }

  // ── Signal markers ─────────────────────────────────────────────────
  /**
   * Chuyển signals thành markPoint data cho ECharts.
   * - B (Buy)  = entry → mũi tên xanh lá, vị trí dưới cây nến entry
   * - S (Sell) = exit  → mũi tên đỏ,    vị trí trên cây nến exit
   * Trả về { markPointData: [], markLineData: [] }
   */
  function buildSignalMarkers(signals, dateIndex) {
    const markPointData = [];

    signals.forEach((sig) => {
      // ── BUY marker ──────────────────────────────────────────────
      if (sig.entry_date && Object.prototype.hasOwnProperty.call(dateIndex, sig.entry_date)) {
        const idx = dateIndex[sig.entry_date];

        // Xây tooltip HTML chi tiết
        let tooltip = `<b style="color:#16a34a">▲ BUY — ${sig.rule_name || 'Rule #' + sig.rule_id}</b><br/>`;
        tooltip += `Ngày vào: ${sig.entry_date}<br/>`;
        tooltip += `Giá vào: ${formatByType(sig.entry_price, 'price')}<br/>`;
        tooltip += `Stop Loss: ${formatByType(sig.initial_sl, 'price')}<br/>`;
        if (sig.status === 'open') {
          tooltip += `R hiện tại: ${Number(sig.r_multiple).toFixed(2)}R<br/>`;
          tooltip += `<span style="color:#16a34a;font-weight:bold">● Đang mở</span>`;
        }

        markPointData.push({
          name: 'B',
          coord: [idx, sig.entry_price],
          value: 'B',
          symbolOffset: [0, '120%'],   // đặt dưới cây nến
          symbol: 'pin',
          symbolSize: 26,
          itemStyle: { color: '#16a34a', borderColor: '#fff', borderWidth: 1.5 },
          label: {
            show: true,
            formatter: 'B',
            color: '#fff',
            fontSize: 10,
            fontWeight: 'bold',
            offset: [0, 1],
          },
          tooltip: { formatter: () => tooltip },
          // lưu raw data để dùng trong custom tooltip formatter
          _lcni: { type: 'buy', sig },
        });
      }

      // ── SELL marker ─────────────────────────────────────────────
      if (sig.exit_date && Object.prototype.hasOwnProperty.call(dateIndex, sig.exit_date)) {
        const idx = dateIndex[sig.exit_date];

        const exitPrice = sig.exit_price || 0;
        const finalR    = sig.final_r !== null ? Number(sig.final_r).toFixed(2) : '–';
        const exitReasonMap = {
          sl: 'Stop Loss',
          tp: 'Take Profit',
          time: 'Hết thời gian',
          manual: 'Manual',
        };
        const reasonLabel = exitReasonMap[sig.exit_reason] || sig.exit_reason || '–';

        let tooltip = `<b style="color:#dc2626">▼ SELL — ${sig.rule_name || 'Rule #' + sig.rule_id}</b><br/>`;
        tooltip += `Ngày ra: ${sig.exit_date}<br/>`;
        tooltip += `Giá ra: ${formatByType(exitPrice, 'price')}<br/>`;
        tooltip += `Lý do: ${reasonLabel}<br/>`;
        tooltip += `Final R: <b style="color:${Number(finalR) >= 0 ? '#16a34a' : '#dc2626'}">${finalR}R</b><br/>`;
        tooltip += `Holding: ${sig.holding_days} ngày`;

        markPointData.push({
          name: 'S',
          coord: [idx, exitPrice],
          value: 'S',
          symbolOffset: [0, '-120%'],  // đặt trên cây nến
          symbol: 'pin',
          symbolSize: 26,
          itemStyle: { color: '#dc2626', borderColor: '#fff', borderWidth: 1.5 },
          label: {
            show: true,
            formatter: 'S',
            color: '#fff',
            fontSize: 10,
            fontWeight: 'bold',
            offset: [0, 1],
          },
          tooltip: { formatter: () => tooltip },
          _lcni: { type: 'sell', sig },
        });
      }
    });

    return markPointData;
  }

  function buildOption(symbol, source, indicators, cache, signals) {
    const dates = source.map((r) => r.date);
    const closes = source.map((r) => r.close);
    const candles = source.map((r) => [r.open, r.close, r.low, r.high]);
    const volumes = source.map((r) => r.volume);

    // Index date → array index để map signals vào đúng nến
    const dateIndex = {};
    dates.forEach((d, i) => { dateIndex[d] = i; });

    // Build signal markers (B/S pins)
    const signalMarkPoints = (Array.isArray(signals) && signals.length)
      ? buildSignalMarkers(signals, dateIndex)
      : [];

 cache.ma20 = calculateMA(closes, 20);
    if (!cache.ma50) cache.ma50 = calculateMA(closes, 50);
    if (!cache.ma100) cache.ma100 = calculateMA(closes, 100);
    if (!cache.ma200) cache.ma200 = calculateMA(closes, 200);
    if (!cache.rsi) cache.rsi = calculateRSI(closes, 14);
    if (!cache.macd) cache.macd = calculateMACD(closes, 12, 26, 9);

    const rs1w = source.map((r) => r.rs_1w_by_exchange);
    const rs1m = source.map((r) => r.rs_1m_by_exchange);
    const rs3m = source.map((r) => r.rs_3m_by_exchange);

    const showRsi = !!indicators.rsi;
    const showMacd = !!indicators.macd;
    const showRs = !!(indicators.rs_1w_by_exchange || indicators.rs_1m_by_exchange || indicators.rs_3m_by_exchange);

    const panels = [{ key: 'price', height: 44 }, { key: 'volume', height: 14 }];
    if (showRsi) panels.push({ key: 'rsi', height: 12 });
    if (showMacd) panels.push({ key: 'macd', height: 14 });
    if (showRs) panels.push({ key: 'rs', height: 12 });

    let top = 6;
    const grid = [];
    const xAxis = [];
    const yAxis = [];
    const xIndices = [];
    const panelIndexes = {};

    panels.forEach((panel, idx) => {
      panelIndexes[panel.key] = idx;
      grid.push({ left: '8%', right: '3%', top: `${top}%`, height: `${panel.height}%` });
      xAxis.push({ type: 'category', gridIndex: idx, data: dates, boundaryGap: false, axisLine: { onZero: false }, min: 'dataMin', max: 'dataMax', axisLabel: { show: panel.key === 'rs' || idx === panels.length - 1 } });
      const yAxisConfig = {
        scale: true,
        gridIndex: idx,
        splitLine: { show: panel.key !== 'macd' },
        axisLabel: { formatter: (value) => formatByType(value, panel.key === 'volume' ? 'volume' : panel.key) }
      };
      yAxis.push(yAxisConfig);
      xIndices.push(idx);
      top += panel.height + 2;
    });

    const series = [
      {
        name: symbol,
        type: 'candlestick',
        xAxisIndex: panelIndexes.price,
        yAxisIndex: panelIndexes.price,
        data: candles,
        itemStyle: {
          color: '#16a34a',
          borderColor: '#16a34a',
          color0: '#dc2626',
          borderColor0: '#dc2626'
        },
        // Signal markers B/S
        markPoint: signalMarkPoints.length ? {
          silent: false,
          animation: false,
          tooltip: {
            trigger: 'item',
            enterable: false,
            confine: true,
            backgroundColor: 'rgba(15,23,42,0.92)',
            borderColor: 'transparent',
            textStyle: { color: '#f8fafc', fontSize: 12, lineHeight: 18 },
            formatter: (p) => {
              if (p.data && typeof p.data.tooltip === 'object' && typeof p.data.tooltip.formatter === 'function') {
                return p.data.tooltip.formatter(p);
              }
              return String(p.name || '');
            },
          },
          data: signalMarkPoints,
        } : undefined,
      },
      { name: 'Volume', type: 'bar', xAxisIndex: panelIndexes.volume, yAxisIndex: panelIndexes.volume, data: volumes, itemStyle: { color: '#94a3b8' }, barMaxWidth: 10 }
    ];

    [['ma20', 'MA20', '#2563eb'], ['ma50', 'MA50', '#f59e0b'], ['ma100', 'MA100', '#8b5cf6'], ['ma200', 'MA200', '#111827']].forEach(([key, label, color]) => {
      if (indicators[key]) series.push({ name: label, type: 'line', xAxisIndex: panelIndexes.price, yAxisIndex: panelIndexes.price, data: cache[key], showSymbol: false, smooth: true, lineStyle: { width: 1.5, color } });
    });

    if (showRsi) series.push({ name: 'RSI(14)', type: 'line', xAxisIndex: panelIndexes.rsi, yAxisIndex: panelIndexes.rsi, data: cache.rsi, showSymbol: false, lineStyle: { color: '#0891b2', width: 1.5 } });

    if (showMacd) {
      series.push({ name: 'MACD', type: 'line', xAxisIndex: panelIndexes.macd, yAxisIndex: panelIndexes.macd, data: cache.macd.macd, showSymbol: false, lineStyle: { color: '#7c3aed', width: 1.2 } });
      series.push({ name: 'Signal', type: 'line', xAxisIndex: panelIndexes.macd, yAxisIndex: panelIndexes.macd, data: cache.macd.signal, showSymbol: false, lineStyle: { color: '#f97316', width: 1.2 } });
      series.push({ name: 'Histogram', type: 'bar', xAxisIndex: panelIndexes.macd, yAxisIndex: panelIndexes.macd, data: cache.macd.histogram, itemStyle: { color: (p) => ((p.value || 0) >= 0 ? '#16a34a' : '#dc2626') } });
    }

    if (showRs) {
      if (indicators.rs_1w_by_exchange) series.push({ name: 'RS 1W', type: 'line', xAxisIndex: panelIndexes.rs, yAxisIndex: panelIndexes.rs, data: rs1w, showSymbol: false, connectNulls: false, lineStyle: { color: '#ef4444', width: 1.4 } });
      if (indicators.rs_1m_by_exchange) series.push({ name: 'RS 1M', type: 'line', xAxisIndex: panelIndexes.rs, yAxisIndex: panelIndexes.rs, data: rs1m, showSymbol: false, connectNulls: false, lineStyle: { color: '#0ea5e9', width: 1.4 } });
      if (indicators.rs_3m_by_exchange) series.push({ name: 'RS 3M', type: 'line', xAxisIndex: panelIndexes.rs, yAxisIndex: panelIndexes.rs, data: rs3m, showSymbol: false, connectNulls: false, lineStyle: { color: '#22c55e', width: 1.4 } });
    }

    return {
      animation: false,
      legend: { top: 0 },
      tooltip: { trigger: 'axis', axisPointer: { type: 'cross' }, formatter: buildTooltipFormatter(source) },
      axisPointer: { link: [{ xAxisIndex: xIndices }] },
      grid,
      xAxis,
      yAxis,
      dataZoom: [
        { type: 'inside', xAxisIndex: xIndices, start: 70, end: 100 },
        { type: 'slider', xAxisIndex: xIndices, bottom: 8, start: 70, end: 100 }
      ],
      series
    };
  }

  function buildControls(el, state, rerender) {
    const wrap = document.createElement('div');
    wrap.style.marginBottom = '8px';
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = '⚙ Indicators';
    const panel = document.createElement('div');
    panel.style.display = 'none';
    panel.style.padding = '8px';
    panel.style.border = '1px solid #d1d5db';
    panel.style.marginTop = '6px';

    const labels = {
      ma20: 'MA20', ma50: 'MA50', ma100: 'MA100', ma200: 'MA200',
      rsi: 'RSI', macd: 'MACD', rs_1w_by_exchange: 'RS 1W', rs_1m_by_exchange: 'RS 1M', rs_3m_by_exchange: 'RS 3M'
    };

    Object.keys(labels).forEach((key) => {
      const label = document.createElement('label');
      label.style.marginRight = '12px';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = !!state.indicators[key];
      input.addEventListener('change', () => {
        state.indicators[key] = input.checked;
        saveSettings(state.indicators);
        rerender();
      });
      label.appendChild(input);
      label.appendChild(document.createTextNode(` ${labels[key]}`));
      panel.appendChild(label);
    });

    button.addEventListener('click', () => {
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });

    wrap.appendChild(button);
    wrap.appendChild(panel);
    el.appendChild(wrap);
  }

  function initChart(container) {
    if (!container || instances.has(container) || !window.echarts) return;
    const symbol = sanitizeSymbol(container.dataset.symbol);
    const apiBase = String(container.dataset.apiBase || '').trim();
    const limit = parseLimit(container.dataset.limit);
    const height = parseHeight(container.dataset.height || container.dataset.mainHeight);
    if (!symbol || !apiBase) return;

    container.innerHTML = '';
    const state = { indicators: loadSettings(), cache: {}, rows: [], signals: [], symbol };
    buildControls(container, state, () => {
      if (!state.chart) return;
      state.chart.setOption(buildOption(state.symbol, state.rows, state.indicators, state.cache, state.signals), { notMerge: false, lazyUpdate: true });
    });

    const canvas = document.createElement('div');
    canvas.className = 'lcni-chart-canvas';
    canvas.style.height = `${height}px`;
    container.appendChild(canvas);

    const chart = window.echarts.init(canvas);
    state.chart = chart;
    instances.set(container, state);

    // Fetch OHLC và signals song song
    Promise.all([
      fetchData(apiBase, symbol, limit),
      fetchSignals(symbol),
    ]).then(([result, signals]) => {
      state.rows    = result.rows;
      state.symbol  = result.symbol;
      state.signals = signals;
      if (!state.rows.length) throw new Error('No rows');
      chart.setOption(buildOption(state.symbol, state.rows, state.indicators, state.cache, state.signals), { notMerge: true, lazyUpdate: true });
    }).catch(() => {
      container.innerHTML = '<div class="lcni-chart-error">No data available</div>';
    });

    const onResize = () => { if (state.chart) state.chart.resize(); };
    window.addEventListener('resize', onResize, { passive: true });
    state.destroy = () => {
      window.removeEventListener('resize', onResize);
      if (state.chart && !state.chart.isDisposed()) state.chart.dispose();
    };
  }

  document.addEventListener('DOMContentLoaded', () => { document.querySelectorAll(SELECTOR).forEach(initChart); });
})();

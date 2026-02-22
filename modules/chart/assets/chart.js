(() => {
  'use strict';

  const SELECTOR = '[data-lcni-chart]';
  const MAX_LIMIT = 500;
  const FETCH_TIMEOUT_MS = 12000;
  const initialized = new WeakSet();
  const chartStates = new WeakMap();
  const trackedElements = new Set();
  let observerStarted = false;

  const normalizeSymbol = (raw) => String(raw || '').trim().toUpperCase();

  const parseLimit = (raw) => {
    const value = Number.parseInt(String(raw || ''), 10);
    if (Number.isNaN(value) || value <= 0) {
      return 200;
    }

    return Math.min(value, MAX_LIMIT);
  };

  const parseHeight = (raw) => {
    const value = Number.parseInt(String(raw || ''), 10);
    if (Number.isNaN(value) || value < 240) {
      return 420;
    }

    return Math.min(value, 1200);
  };

  const createShell = (el) => {
    const root = document.createElement('div');
    root.className = 'lcni-chart-shell';
    root.style.height = `${parseHeight(el.dataset.height)}px`;

    const canvas = document.createElement('div');
    canvas.className = 'lcni-chart-canvas';

    const loadingEl = document.createElement('div');
    loadingEl.className = 'lcni-chart-loading';
    loadingEl.textContent = 'Loading chart...';
    loadingEl.hidden = true;

    const messageEl = document.createElement('div');
    messageEl.className = 'lcni-chart-message';
    messageEl.hidden = true;

    root.appendChild(canvas);
    root.appendChild(loadingEl);
    root.appendChild(messageEl);
    el.replaceChildren(root);

    return { canvas, loadingEl, messageEl };
  };

  const setLoading = (el, isLoading) => {
    const state = chartStates.get(el);
    if (!state) {
      return;
    }

    state.loadingEl.hidden = !isLoading;
  };

  const setMessage = (el, message, isError = false) => {
    const state = chartStates.get(el);
    if (!state) {
      return;
    }

    state.messageEl.textContent = message || '';
    state.messageEl.hidden = !message;
    state.messageEl.dataset.level = isError ? 'error' : 'info';
  };

  const parseApiResponse = (payload) => {
    if (Array.isArray(payload)) {
      return payload;
    }

    if (payload && Array.isArray(payload.data)) {
      return payload.data;
    }

    if (payload && payload.data && Array.isArray(payload.data.candles)) {
      return payload.data.candles;
    }

    if (payload && Array.isArray(payload.candles)) {
      return payload.candles;
    }

    return [];
  };

  const fetchData = async (apiBase, symbol, limit, controller) => {
    const endpoint = new URL(apiBase, window.location.origin);
    endpoint.searchParams.set('symbol', symbol);
    endpoint.searchParams.set('limit', String(limit));

    const timeoutId = window.setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

    try {
      const response = await fetch(endpoint.toString(), {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: controller.signal
      });

      if (!response.ok) {
        throw new Error(`REST request failed (HTTP ${response.status})`);
      }

      let json;
      try {
        json = await response.json();
      } catch (_error) {
        throw new Error('Invalid JSON received from API.');
      }

      return parseApiResponse(json);
    } finally {
      window.clearTimeout(timeoutId);
    }
  };

  const toSeriesData = (rows) => {
    const categoryData = [];
    const values = [];
    const volumes = [];

    (Array.isArray(rows) ? rows : []).forEach((row) => {
      const date = String(row && row.date ? row.date : '').trim();
      const open = Number(row && row.open);
      const high = Number(row && row.high);
      const low = Number(row && row.low);
      const close = Number(row && row.close);
      const volume = Number(row && row.volume);

      if (!date || !Number.isFinite(open) || !Number.isFinite(high) || !Number.isFinite(low) || !Number.isFinite(close)) {
        return;
      }

      categoryData.push(date);
      values.push([open, close, low, high]);
      volumes.push(Number.isFinite(volume) ? volume : 0);
    });

    return { categoryData, values, volumes };
  };

  const buildOption = ({ categoryData, values, volumes }) => ({
    animation: true,
    tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
    axisPointer: { link: [{ xAxisIndex: [0, 1] }] },
    grid: [
      { left: '8%', right: '4%', top: 20, height: '60%' },
      { left: '8%', right: '4%', top: '76%', height: '16%' }
    ],
    xAxis: [
      { type: 'category', data: categoryData, boundaryGap: false, axisLine: { onZero: false }, min: 'dataMin', max: 'dataMax' },
      { type: 'category', gridIndex: 1, data: categoryData, boundaryGap: false, axisLine: { onZero: false }, axisTick: { show: false }, axisLabel: { show: false }, min: 'dataMin', max: 'dataMax' }
    ],
    yAxis: [
      { scale: true, splitArea: { show: true } },
      { scale: true, gridIndex: 1, splitNumber: 2 }
    ],
    dataZoom: [
      { type: 'inside', xAxisIndex: [0, 1], start: 70, end: 100 },
      { type: 'slider', xAxisIndex: [0, 1], bottom: 0, start: 70, end: 100 }
    ],
    series: [
      { name: 'Price', type: 'candlestick', data: values },
      { name: 'Volume', type: 'bar', xAxisIndex: 1, yAxisIndex: 1, data: volumes, barMaxWidth: 12 }
    ]
  });

  const renderChart = (el, rows) => {
    const state = chartStates.get(el);
    if (!state || !state.chart) {
      return;
    }

    const seriesData = toSeriesData(rows);
    if (!seriesData.categoryData.length) {
      state.chart.clear();
      setMessage(el, 'No chart data available for this symbol.');
      return;
    }

    setMessage(el, '');
    state.chart.setOption(buildOption(seriesData), true);
  };

  const disposeIfDetached = (el) => {
    if (document.body.contains(el)) {
      return;
    }

    const state = chartStates.get(el);
    if (!state) {
      trackedElements.delete(el);
      return;
    }

    if (state.abortController) {
      state.abortController.abort();
    }

    if (state.resizeHandler) {
      window.removeEventListener('resize', state.resizeHandler);
    }

    if (state.chart && !state.chart.isDisposed()) {
      state.chart.dispose();
    }

    chartStates.delete(el);
    initialized.delete(el);
    trackedElements.delete(el);
  };

  const initChart = (el) => {
    if (!el || initialized.has(el)) {
      return;
    }

    initialized.add(el);
    const shell = createShell(el);

    if (!window.echarts || typeof window.echarts.init !== 'function') {
      chartStates.set(el, { ...shell, chart: null });
      setMessage(el, 'ECharts runtime not available.', true);
      return;
    }

    const symbol = normalizeSymbol(el.dataset.symbol);
    const limit = parseLimit(el.dataset.limit);
    const apiBase = String(el.dataset.apiBase || '').trim();

    const chart = window.echarts.init(shell.canvas);
    const resizeHandler = () => {
      if (!chart.isDisposed()) {
        chart.resize();
      }
    };

    window.addEventListener('resize', resizeHandler, { passive: true });

    const state = {
      ...shell,
      chart,
      resizeHandler,
      abortController: null
    };
    chartStates.set(el, state);
    trackedElements.add(el);

    if (!symbol || !apiBase) {
      setMessage(el, 'Invalid chart configuration.', true);
      return;
    }

    state.abortController = new AbortController();
    setLoading(el, true);

    fetchData(apiBase, symbol, limit, state.abortController)
      .then((rows) => renderChart(el, rows))
      .catch((error) => {
        if (error && error.name === 'AbortError') {
          setMessage(el, 'Chart request timed out.', true);
          return;
        }

        setMessage(el, (error && error.message) ? error.message : 'Unable to load chart data.', true);
      })
      .finally(() => setLoading(el, false));
  };

  const initAllCharts = () => {
    document.querySelectorAll(SELECTOR).forEach(initChart);

    if (observerStarted || typeof MutationObserver === 'undefined') {
      return;
    }

    observerStarted = true;
    const observer = new MutationObserver(() => {
      document.querySelectorAll(SELECTOR).forEach(initChart);
      trackedElements.forEach((el) => disposeIfDetached(el));
    });

    observer.observe(document.body, { childList: true, subtree: true });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllCharts, { once: true });
  } else {
    initAllCharts();
  }
})();

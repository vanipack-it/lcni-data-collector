(function initLcniChart(windowObject, documentObject) {
  'use strict';

  if (!windowObject || !documentObject || windowObject.__lcniChartInitialized) {
    return;
  }
  windowObject.__lcniChartInitialized = true;

  const MAX_LIMIT = 500;
  const stateMap = new WeakMap();

  const parseLimit = function parseLimit(rawLimit) {
    const parsed = Number.parseInt(rawLimit, 10);
    if (Number.isNaN(parsed) || parsed <= 0) {
      return 200;
    }

    return Math.min(parsed, MAX_LIMIT);
  };

  const ensureState = function ensureState(container) {
    let state = stateMap.get(container);
    if (state) {
      return state;
    }

    state = {
      engine: null,
      abortController: null,
      requestId: 0,
      currentSymbol: '',
      overlay: null,
      errorNode: null,
      loadingNode: null
    };

    const overlay = documentObject.createElement('div');
    overlay.style.position = 'relative';
    overlay.style.minHeight = '420px';

    const chartNode = documentObject.createElement('div');
    chartNode.style.height = '420px';
    chartNode.style.width = '100%';

    const loadingNode = documentObject.createElement('div');
    loadingNode.textContent = 'Loading...';
    loadingNode.style.position = 'absolute';
    loadingNode.style.inset = '0';
    loadingNode.style.display = 'none';
    loadingNode.style.alignItems = 'center';
    loadingNode.style.justifyContent = 'center';
    loadingNode.style.background = 'rgba(255,255,255,0.7)';
    loadingNode.style.zIndex = '2';

    const errorNode = documentObject.createElement('div');
    errorNode.style.position = 'absolute';
    errorNode.style.inset = '0';
    errorNode.style.display = 'none';
    errorNode.style.alignItems = 'center';
    errorNode.style.justifyContent = 'center';
    errorNode.style.padding = '12px';
    errorNode.style.color = '#b91c1c';
    errorNode.style.background = 'rgba(255,255,255,0.92)';
    errorNode.style.zIndex = '3';

    overlay.appendChild(chartNode);
    overlay.appendChild(loadingNode);
    overlay.appendChild(errorNode);
    container.innerHTML = '';
    container.appendChild(overlay);

    state.overlay = chartNode;
    state.errorNode = errorNode;
    state.loadingNode = loadingNode;
    stateMap.set(container, state);

    return state;
  };

  const setLoading = function setLoading(state, isLoading) {
    state.loadingNode.style.display = isLoading ? 'flex' : 'none';
  };

  const setError = function setError(state, message) {
    if (!message) {
      state.errorNode.textContent = '';
      state.errorNode.style.display = 'none';
      return;
    }

    state.errorNode.textContent = message;
    state.errorNode.style.display = 'flex';
  };

  const getCandles = async function getCandles(symbol, limit, signal) {
    const context = windowObject.LCNIStockContext;
    const safeLimit = parseLimit(limit);
    const endpoint = '/wp-json/lcni/v1/candles?symbol=' + encodeURIComponent(symbol) + '&limit=' + safeLimit;

    if (context && typeof context.fetchJson === 'function') {
      const cacheKey = 'candles:' + symbol + ':' + safeLimit;
      const payload = await context.fetchJson(cacheKey, endpoint, { signal: signal });
      return Array.isArray(payload) ? payload : (Array.isArray(payload && payload.candles) ? payload.candles : []);
    }

    const response = await windowObject.fetch(endpoint, { signal: signal });
    if (!response.ok) {
      throw new Error('Fetch failed');
    }

    const payload = await response.json();
    return Array.isArray(payload) ? payload : (Array.isArray(payload && payload.candles) ? payload.candles : []);
  };

  const renderContainer = async function renderContainer(container, symbol) {
    const engineFactory = windowObject.LCNIChartEchartsEngine;
    const echarts = windowObject.echarts;
    if (!engineFactory || typeof engineFactory.createChart !== 'function' || !echarts) {
      return;
    }

    const state = ensureState(container);
    const limit = parseLimit(container.dataset.lcniLimit || '200');

    if (state.abortController) {
      state.abortController.abort();
    }

    state.abortController = new AbortController();
    state.requestId += 1;
    const requestId = state.requestId;

    setError(state, '');
    setLoading(state, true);

    try {
      const candles = await getCandles(symbol, limit, state.abortController.signal);

      if (requestId !== state.requestId) {
        return;
      }

      if (!state.engine) {
        state.engine = engineFactory.createChart(state.overlay);
      }

      if (!state.engine || !candles.length) {
        setError(state, 'No data');
        return;
      }

      state.engine.updateData(candles.slice(-MAX_LIMIT));
      state.currentSymbol = symbol;
    } catch (error) {
      if (error && error.name === 'AbortError') {
        return;
      }
      if (requestId === state.requestId) {
        setError(state, 'Không thể tải dữ liệu biểu đồ.');
      }
    } finally {
      if (requestId === state.requestId) {
        setLoading(state, false);
      }
    }
  };

  const init = function init() {
    const context = windowObject.LCNIStockContext;
    const containers = documentObject.querySelectorAll('[data-lcni-chart]');
    if (!containers.length) {
      return;
    }

    const symbol = context && typeof context.getCurrentSymbol === 'function'
      ? context.getCurrentSymbol()
      : '';

    if (symbol) {
      containers.forEach(function (container) {
        renderContainer(container, symbol);
      });
    }

    documentObject.addEventListener('lcni:symbolChange', function onSymbolChange(event) {
      const raw = event && event.detail ? event.detail.symbol : '';
      const nextSymbol = context && typeof context.normalizeSymbol === 'function'
        ? context.normalizeSymbol(raw || '')
        : String(raw || '').trim().toUpperCase();

      if (!nextSymbol) {
        return;
      }

      containers.forEach(function (container) {
        renderContainer(container, nextSymbol);
      });
    }, { passive: true });

    windowObject.addEventListener('beforeunload', function destroyAll() {
      containers.forEach(function (container) {
        const state = stateMap.get(container);
        if (!state) {
          return;
        }

        if (state.abortController) {
          state.abortController.abort();
        }

        if (state.engine && typeof state.engine.destroy === 'function') {
          state.engine.destroy();
        }

        stateMap.delete(container);
      });
    }, { once: true });
  };

  if (documentObject.readyState === 'loading') {
    documentObject.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})(window, document);

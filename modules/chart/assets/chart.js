(function initLcniChart(windowObject, documentObject) {
  'use strict';

  if (!windowObject || !documentObject || windowObject.__lcniChartInitialized) {
    return;
  }
  windowObject.__lcniChartInitialized = true;

  const MAX_LIMIT = 500;
  const ECHARTS_CDN_URL = 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js';
  const stateMap = new WeakMap();
  let echartsLoaderPromise = null;

  const parseLimit = function parseLimit(rawLimit) {
    const parsed = Number.parseInt(rawLimit, 10);
    if (Number.isNaN(parsed) || parsed <= 0) {
      return 200;
    }

    return Math.min(parsed, MAX_LIMIT);
  };

  const parseHeight = function parseHeight(rawHeight) {
    const parsed = Number.parseInt(rawHeight, 10);
    if (Number.isNaN(parsed) || parsed < 240) {
      return 420;
    }

    return Math.min(parsed, 1200);
  };

  const loadEchartsRuntime = function loadEchartsRuntime() {
    if (windowObject.echarts && typeof windowObject.echarts.init === 'function') {
      return Promise.resolve(windowObject.echarts);
    }

    if (echartsLoaderPromise) {
      return echartsLoaderPromise;
    }

    echartsLoaderPromise = new Promise(function (resolve, reject) {
      const script = documentObject.createElement('script');
      script.src = ECHARTS_CDN_URL;
      script.async = true;
      script.onload = function onLoad() {
        if (windowObject.echarts && typeof windowObject.echarts.init === 'function') {
          resolve(windowObject.echarts);
          return;
        }

        reject(new Error('ECharts runtime unavailable after loading script.'));
      };
      script.onerror = function onError() {
        reject(new Error('Failed to load ECharts runtime.'));
      };

      documentObject.head.appendChild(script);
    }).catch(function (error) {
      echartsLoaderPromise = null;
      throw error;
    });

    return echartsLoaderPromise;
  };

  const unwrapCandlesPayload = function unwrapCandlesPayload(payload) {
    if (Array.isArray(payload)) {
      return payload;
    }

    if (Array.isArray(payload && payload.candles)) {
      return payload.candles;
    }

    const payloadData = payload && typeof payload === 'object' ? payload.data : null;
    if (Array.isArray(payloadData)) {
      return payloadData;
    }

    if (Array.isArray(payloadData && payloadData.candles)) {
      return payloadData.candles;
    }

    return [];
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

    const chartHeight = parseHeight(container.dataset.lcniHeight || '420');

    const overlay = documentObject.createElement('div');
    overlay.style.position = 'relative';
    overlay.style.minHeight = chartHeight + 'px';

    const chartNode = documentObject.createElement('div');
    chartNode.style.height = chartHeight + 'px';
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

  const getErrorMessage = function getErrorMessage(error, fallback) {
    if (!error) {
      return fallback;
    }

    if (typeof error === 'string' && error.trim()) {
      return error.trim();
    }

    if (typeof error.message === 'string' && error.message.trim()) {
      return error.message.trim();
    }

    const restMessage = error && error.data && typeof error.data.message === 'string'
      ? error.data.message
      : '';

    if (restMessage.trim()) {
      return restMessage.trim();
    }

    return fallback;
  };

  const buildCandlesEndpoint = function buildCandlesEndpoint(container, symbol, limit) {
    const safeLimit = parseLimit(limit);
    const explicitEndpoint = String(container.dataset.lcniCandlesEndpoint || '').trim();
    const endpointBase = explicitEndpoint || '/wp-json/lcni/v1/candles';

    try {
      const url = new URL(endpointBase, windowObject.location.origin);
      url.searchParams.set('symbol', symbol);
      url.searchParams.set('limit', String(safeLimit));

      return {
        cacheKey: 'candles:' + symbol + ':' + safeLimit,
        requestPath: url.pathname + url.search
      };
    } catch (_error) {
      return {
        cacheKey: 'candles:' + symbol + ':' + safeLimit,
        requestPath: '/wp-json/lcni/v1/candles?symbol=' + encodeURIComponent(symbol) + '&limit=' + safeLimit
      };
    }
  };

  const getCandles = async function getCandles(container, symbol, limit, signal) {
    const context = windowObject.LCNIStockContext;
    const requestInfo = buildCandlesEndpoint(container, symbol, limit);

    if (context && typeof context.fetchJson === 'function') {
      const payload = await context.fetchJson(requestInfo.cacheKey, requestInfo.requestPath, { signal: signal });
      return unwrapCandlesPayload(payload);
    }

    const response = await windowObject.fetch(requestInfo.requestPath, { signal: signal });
    if (!response.ok) {
      let serverMessage = '';
      try {
        const errorPayload = await response.json();
        serverMessage = getErrorMessage(errorPayload, '');
      } catch (_error) {
        serverMessage = '';
      }

      throw new Error(serverMessage || ('HTTP ' + response.status));
    }

    const payload = await response.json();
    return unwrapCandlesPayload(payload);
  };

  const renderContainer = async function renderContainer(container, symbol) {
    await loadEchartsRuntime();
    const engineFactory = windowObject.LCNIChartEchartsEngine;
    const echarts = windowObject.echarts;
    const state = ensureState(container);

    if (!engineFactory || typeof engineFactory.createChart !== 'function' || !echarts) {
      setLoading(state, false);
      setError(state, 'Không thể khởi tạo biểu đồ. Vui lòng tải lại trang.');
      return;
    }

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
      const candles = await getCandles(container, symbol, limit, state.abortController.signal);

      if (requestId !== state.requestId) {
        return;
      }

      if (!state.engine) {
        state.engine = engineFactory.createChart(state.overlay);
      }

      if (!state.engine || !candles.length) {
        setError(state, 'Không có dữ liệu để hiển thị biểu đồ cho mã này.');
        return;
      }

      state.engine.updateData(candles.slice(-MAX_LIMIT));
      state.currentSymbol = symbol;
    } catch (error) {
      if (error && error.name === 'AbortError') {
        return;
      }
      if (requestId === state.requestId) {
        setError(state, getErrorMessage(error, 'Không thể tải dữ liệu biểu đồ.'));
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

    containers.forEach(function (container) {
      const containerSymbol = context && typeof context.normalizeSymbol === 'function'
        ? context.normalizeSymbol(container.dataset.lcniSymbol || '')
        : String(container.dataset.lcniSymbol || '').trim().toUpperCase();
      const initialSymbol = containerSymbol || (context && typeof context.getCurrentSymbol === 'function' ? context.getCurrentSymbol() : '');

      if (initialSymbol) {
        renderContainer(container, initialSymbol);
      } else {
        const state = ensureState(container);
        setLoading(state, false);
        setError(state, 'Thiếu mã cổ phiếu để tải biểu đồ.');
      }
    });

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

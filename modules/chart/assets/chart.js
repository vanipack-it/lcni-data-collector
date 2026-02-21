document.addEventListener('DOMContentLoaded', async () => {
  const containers = document.querySelectorAll('[data-lcni-chart]');
  if (!containers.length) return;

  const stockSyncUtils = window.LCNIStockSyncUtils || null;
  const sanitizeSymbol = stockSyncUtils
    ? stockSyncUtils.sanitizeSymbol
    : (value) => (/^[A-Z0-9._-]{1,15}$/.test(String(value || '').toUpperCase().trim()) ? String(value || '').toUpperCase().trim() : '');

  const stockSync = stockSyncUtils
    ? stockSyncUtils.createStockSync()
    : { subscribe() {}, setSymbol() {}, getCurrentSymbol() { return ''; }, configureQueryParam() {} };

  function createLightweightEngine(container, candles) {
    if (typeof LightweightCharts === 'undefined') return null;
    const chart = LightweightCharts.createChart(container, {
      layout: { background: { color: '#fff' }, textColor: '#333' },
      grid: { vertLines: { color: '#efefef' }, horzLines: { color: '#efefef' } }
    });
    const candleSeries = chart.addCandlestickSeries();
    const volumeSeries = chart.addHistogramSeries({ priceFormat: { type: 'volume' }, priceScaleId: '' });

    const apply = (rows) => {
      candleSeries.setData(rows);
      volumeSeries.setData(rows.map((item) => ({ time: item.time, value: Number(item.volume || 0), color: item.close >= item.open ? '#16a34a' : '#dc2626' })));
      chart.timeScale().fitContent();
    };

    apply(candles);

    return {
      updateData(rows) { apply(rows || []); },
      updateSymbol() {},
      updateTimeframe() {},
      resize() {
        const width = Math.floor(container.clientWidth || 0);
        const height = Math.floor(container.clientHeight || 0);
        if (width > 0 && height > 0) chart.resize(width, height);
      },
      destroy() { chart.remove(); }
    };
  }

  async function mountChart(container) {
    const apiBase = container.dataset.apiBase;
    const queryParam = container.dataset.queryParam;
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const fallbackSymbol = sanitizeSymbol(container.dataset.fallbackSymbol);
    stockSync.configureQueryParam(queryParam || 'symbol');

    const resolveSymbol = () => {
      if (fixedSymbol) return fixedSymbol;
      const query = new URLSearchParams(window.location.search);
      return stockSync.getCurrentSymbol() || sanitizeSymbol(query.get(queryParam || 'symbol')) || fallbackSymbol;
    };

    const root = document.createElement('div');
    root.style.width = '100%';
    root.style.height = `${Number(container.dataset.mainHeight || 420)}px`;
    root.style.minHeight = '280px';
    container.innerHTML = '';
    container.appendChild(root);

    let engine = null;

    const render = async (symbol) => {
      if (!apiBase || !symbol) {
        container.textContent = 'NO DATA';
        return;
      }
      const response = await fetch(`${apiBase}?symbol=${encodeURIComponent(symbol)}&limit=${Number(container.dataset.limit || 200)}`, { credentials: 'same-origin' });
      if (!response.ok) throw new Error('request failed');
      const payload = await response.json();
      const candles = Array.isArray(payload) ? payload : payload?.candles;
      if (!Array.isArray(candles) || !candles.length) throw new Error('empty');

      if (!engine) {
        const requestedEngine = String(window.lcniChartEngineType || 'echarts');
        const echartsEngine = window.LCNIChartEngine && typeof window.LCNIChartEngine.init === 'function'
          ? window.LCNIChartEngine.init(root, { data: candles, symbol, timeframe: '1D' })
          : null;
        engine = (requestedEngine === 'echarts' && echartsEngine) ? echartsEngine : createLightweightEngine(root, candles);
      }

      if (!engine) {
        container.textContent = 'NO DATA';
        return;
      }

      engine.updateSymbol(symbol);
      engine.updateData(candles);
    };

    await render(resolveSymbol());

    let resizeTimer = 0;
    const onResize = () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => { if (engine) engine.resize(); }, 200);
    };
    window.addEventListener('resize', onResize);
    onResize();

    stockSync.subscribe(async (nextSymbol) => {
      if (fixedSymbol || !nextSymbol) return;
      await render(nextSymbol).catch(() => { container.textContent = 'NO DATA'; });
    });

    return () => {
      window.removeEventListener('resize', onResize);
      if (engine) engine.destroy();
    };
  }

  await Promise.all(Array.from(containers).map((container) => mountChart(container).catch(() => { container.textContent = 'NO DATA'; })));
});

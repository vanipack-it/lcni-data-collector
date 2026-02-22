(function initLcniChart() {
  if (window.__lcniChartInitialized) {
    return;
  }
  window.__lcniChartInitialized = true;

  const initChart = async (symbol) => {
    const containers = document.querySelectorAll('[data-lcni-chart]');
    if (!containers.length) {
      return;
    }

    const context = window.LCNIStockContext;
    const cacheKey = `candles:${symbol}`;

    try {
      const payload = await context.fetchJson(cacheKey, `/wp-json/lcni/v1/candles?symbol=${encodeURIComponent(symbol)}&limit=200`);
      const candles = Array.isArray(payload) ? payload : (Array.isArray(payload?.candles) ? payload.candles : []);

      containers.forEach((container) => {
        if (!candles.length || typeof LightweightCharts === 'undefined') {
          container.textContent = 'No data';
          return;
        }

        container.innerHTML = '';
        const chartRoot = document.createElement('div');
        chartRoot.style.width = '100%';
        chartRoot.style.height = '420px';
        container.appendChild(chartRoot);

        const chart = LightweightCharts.createChart(chartRoot, {
          layout: { background: { color: '#ffffff' }, textColor: '#1f2937' },
          grid: { vertLines: { color: '#f3f4f6' }, horzLines: { color: '#f3f4f6' } }
        });

        const candleSeries = chart.addCandlestickSeries();
        candleSeries.setData(candles);
        chart.timeScale().fitContent();
      });
    } catch (error) {
      containers.forEach((container) => {
        container.textContent = 'No data';
      });
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    const context = window.LCNIStockContext;
    if (!context) {
      return;
    }

    const symbol = context.getCurrentSymbol();
    if (!symbol) return;

    initChart(symbol);

    document.addEventListener('lcni:symbolChange', (event) => {
      const nextSymbol = context.normalizeSymbol(event?.detail?.symbol || '');
      if (!nextSymbol) {
        return;
      }
      initChart(nextSymbol);
    });
  });
})();

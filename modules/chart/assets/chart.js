(function initLcniChart() {
  if (window.__lcniChartInitialized) {
    return;
  }
  window.__lcniChartInitialized = true;

  document.addEventListener('DOMContentLoaded', async () => {
    const containers = document.querySelectorAll('[data-lcni-chart]');
    if (!containers.length) {
      return;
    }

    const renderNoData = (container) => {
      container.textContent = 'No data';
    };

    const parseCandles = (payload) => {
      if (Array.isArray(payload)) {
        return payload;
      }
      if (Array.isArray(payload?.candles)) {
        return payload.candles;
      }
      return [];
    };

    await Promise.all(Array.from(containers).map(async (container) => {
      if (container.dataset.lcniInitialized === '1') {
        return;
      }
      container.dataset.lcniInitialized = '1';

      const symbol = String(container.dataset.symbol || '').toUpperCase().trim();
      const limit = Number(container.dataset.limit || 200);

      if (!symbol) {
        renderNoData(container);
        return;
      }

      try {
        const response = await fetch(`/wp-json/lcni/v1/candles?symbol=${encodeURIComponent(symbol)}&limit=${encodeURIComponent(limit)}`, {
          credentials: 'same-origin'
        });

        if (!response.ok) {
          renderNoData(container);
          return;
        }

        const payload = await response.json();
        const candles = parseCandles(payload);
        if (!candles.length || typeof LightweightCharts === 'undefined') {
          renderNoData(container);
          return;
        }

        container.innerHTML = '';
        const chartRoot = document.createElement('div');
        chartRoot.style.width = '100%';
        chartRoot.style.height = `${Number(container.dataset.mainHeight || 420)}px`;
        container.appendChild(chartRoot);

        const chart = LightweightCharts.createChart(chartRoot, {
          layout: { background: { color: '#ffffff' }, textColor: '#1f2937' },
          grid: { vertLines: { color: '#f3f4f6' }, horzLines: { color: '#f3f4f6' } }
        });

        const candleSeries = chart.addCandlestickSeries();
        candleSeries.setData(candles);
        chart.timeScale().fitContent();
      } catch (error) {
        renderNoData(container);
      }
    }));
  });
})();

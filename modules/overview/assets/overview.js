(function initLcniOverview() {
  if (window.__lcniOverviewInitialized) {
    return;
  }
  window.__lcniOverviewInitialized = true;

  const initOverview = async (symbol) => {
    const containers = document.querySelectorAll('[data-lcni-overview]');
    if (!containers.length) {
      return;
    }

    const context = window.LCNIStockContext;
    const cacheKey = `overview:${symbol}`;

    try {
      const payload = await context.fetchJson(cacheKey, `/wp-json/lcni/v1/stock-overview?symbol=${encodeURIComponent(symbol)}`);
      containers.forEach((container) => {
        container.innerHTML = `<pre class="lcni-overview-json">${JSON.stringify(payload || {}, null, 2)}</pre>`;
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

    initOverview(symbol);

    document.addEventListener('lcni:symbolChange', (event) => {
      const nextSymbol = context.normalizeSymbol(event?.detail?.symbol || '');
      if (!nextSymbol) {
        return;
      }
      initOverview(nextSymbol);
    });
  });
})();

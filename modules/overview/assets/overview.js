(function initLcniOverview() {
  if (window.__lcniOverviewInitialized) {
    return;
  }
  window.__lcniOverviewInitialized = true;

  const initOverview = async (symbol) => {
    const normalizedSymbol = String(symbol || '').trim().toUpperCase();
    if (!normalizedSymbol) {
      return;
    }

    const containers = document.querySelectorAll('[data-lcni-overview]');
    if (!containers.length) {
      return;
    }

    const context = window.LCNIStockContext;
    const cacheKey = `overview:${normalizedSymbol}`;

    try {
      const payload = context && typeof context.fetchJson === 'function'
        ? await context.fetchJson(cacheKey, `/wp-json/lcni/v1/stock-overview?symbol=${encodeURIComponent(normalizedSymbol)}`)
        : await fetch(`/wp-json/lcni/v1/stock-overview?symbol=${encodeURIComponent(normalizedSymbol)}`, { credentials: 'same-origin' }).then((response) => response.json());

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
    const firstContainer = document.querySelector('[data-lcni-overview]');
    const fallbackSymbol = firstContainer ? String(firstContainer.getAttribute('data-symbol') || '') : '';
    const symbolFromContext = context && typeof context.getCurrentSymbol === 'function' ? context.getCurrentSymbol() : '';
    const symbol = symbolFromContext || fallbackSymbol;

    if (symbol) {
      initOverview(symbol);
    }

    document.addEventListener('lcni:symbolChange', (event) => {
      const raw = event && event.detail ? event.detail.symbol : '';
      const nextSymbol = context && typeof context.normalizeSymbol === 'function'
        ? context.normalizeSymbol(raw || '')
        : String(raw || '').trim().toUpperCase();
      if (!nextSymbol) {
        return;
      }
      initOverview(nextSymbol);
    });
  });
})();

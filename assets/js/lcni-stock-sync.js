(function initLcniStockContext() {
  if (window.LCNIStockContext) {
    return;
  }

  const normalizeSymbol = (value) => String(value || '').toUpperCase().trim();
  const lcniCache = {};

  const getDomSymbol = () => normalizeSymbol(document.querySelector('.lcni-stock-detail')?.dataset.symbol || '');

  const getCurrentSymbol = () => {
    const globalSymbol = normalizeSymbol(window.LCNI_CURRENT_SYMBOL || '');
    return globalSymbol || getDomSymbol() || '';
  };

  const fetchJson = async (cacheKey, url, options = {}) => {
    const signal = options && options.signal ? options.signal : undefined;

    if (lcniCache[cacheKey]) {
      return lcniCache[cacheKey];
    }

    lcniCache[cacheKey] = fetch(url, { credentials: 'same-origin', signal }).then(async (response) => {
      if (!response.ok) {
        throw new Error('Request failed');
      }

      return response.json();
    }).catch((error) => {
      delete lcniCache[cacheKey];
      throw error;
    });

    return lcniCache[cacheKey];
  };

  const setSymbol = (symbol) => {
    const next = normalizeSymbol(symbol);
    if (!next || next === getCurrentSymbol()) {
      return;
    }

    window.LCNI_CURRENT_SYMBOL = next;
    document.dispatchEvent(new CustomEvent('lcni:symbolChange', { detail: { symbol: next } }));
  };

  window.LCNIStockContext = {
    lcniCache,
    normalizeSymbol,
    getCurrentSymbol,
    fetchJson,
    setSymbol
  };
})();

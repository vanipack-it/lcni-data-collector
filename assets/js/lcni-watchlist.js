(function () {
  const jsonFetch = async (url, options = {}) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error((data && data.message) || 'Request failed');
    }
    return data;
  };

  const renderWatchlistNode = async (node) => {
    const watchlistApi = node.dataset.watchlistApi;
    const stockApi = node.dataset.stockApi;
    const emptyMessage = node.dataset.emptyMessage || 'Watchlist rỗng.';

    const state = await jsonFetch(watchlistApi);
    const symbols = Array.isArray(state.symbols) ? state.symbols : [];

    if (!symbols.length) {
      node.innerHTML = `<div>${emptyMessage}</div>`;
      return;
    }

    const rows = await Promise.all(symbols.map(async (symbol) => {
      try {
        const stock = await jsonFetch(`${stockApi}/${encodeURIComponent(symbol)}`);
        return {
          symbol,
          price: stock.price ?? '-',
          change: stock.change ?? '-',
          volume: stock.volume ?? '-',
        };
      } catch (_err) {
        return { symbol, price: '-', change: '-', volume: '-' };
      }
    }));

    node.innerHTML = `
      <table class="lcni-watchlist-table">
        <thead><tr><th>Symbol</th><th>Price</th><th>Change</th><th>Volume</th><th></th></tr></thead>
        <tbody>
          ${rows.map((row) => `
            <tr>
              <td>${row.symbol}</td><td>${row.price}</td><td>${row.change}</td><td>${row.volume}</td>
              <td><button type="button" data-remove-symbol="${row.symbol}">Xóa</button></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;

    node.querySelectorAll('[data-remove-symbol]').forEach((button) => {
      button.addEventListener('click', async () => {
        const symbol = button.getAttribute('data-remove-symbol') || '';
        await jsonFetch(watchlistApi, { method: 'DELETE', body: JSON.stringify({ symbol }) });
        renderWatchlistNode(node);
      });
    });
  };

  const bindAddButton = (button) => {
    if (button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', async () => {
      const symbol = button.getAttribute('data-symbol') || '';
      const watchlistApi = button.getAttribute('data-watchlist-api') || '';
      if (!symbol || !watchlistApi) return;
      try {
        await jsonFetch(watchlistApi, { method: 'POST', body: JSON.stringify({ symbol }) });
        button.classList.add('is-added');
      } catch (_err) {
        button.classList.add('is-error');
      }
    });
  };

  document.querySelectorAll('[data-lcni-watchlist]').forEach((node) => {
    renderWatchlistNode(node);
  });

  document.querySelectorAll('[data-lcni-watchlist-add="1"]').forEach((button) => {
    bindAddButton(button);
  });
})();

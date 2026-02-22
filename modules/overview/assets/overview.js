(function initLcniOverview() {
  if (window.__lcniOverviewInitialized) {
    return;
  }
  window.__lcniOverviewInitialized = true;

  document.addEventListener('DOMContentLoaded', async () => {
    const containers = document.querySelectorAll('[data-lcni-overview]');
    if (!containers.length) {
      return;
    }

    const labels = {
      symbol: 'Mã',
      exchange: 'Sàn',
      icb2_name: 'Ngành',
      eps: 'EPS',
      roe: 'ROE',
      pe_ratio: 'P/E',
      pb_ratio: 'P/B',
      volume: 'KL'
    };

    const renderNoData = (container) => {
      container.textContent = 'No data';
    };

    const renderOverview = (container, payload) => {
      const entries = Object.entries(payload || {}).filter(([, value]) => value !== null && value !== '');
      if (!entries.length) {
        renderNoData(container);
        return;
      }

      const html = entries.map(([key, value]) => {
        const label = labels[key] || key;
        return `<div class="lcni-overview-item"><strong>${label}:</strong> <span>${String(value)}</span></div>`;
      }).join('');

      container.innerHTML = `<div class="lcni-overview">${html}</div>`;
    };

    await Promise.all(Array.from(containers).map(async (container) => {
      if (container.dataset.lcniInitialized === '1') {
        return;
      }
      container.dataset.lcniInitialized = '1';

      const symbol = String(container.dataset.symbol || '').toUpperCase().trim();
      if (!symbol) {
        renderNoData(container);
        return;
      }

      try {
        const response = await fetch(`/wp-json/lcni/v1/stock-overview?symbol=${encodeURIComponent(symbol)}`, {
          credentials: 'same-origin'
        });
        if (!response.ok) {
          renderNoData(container);
          return;
        }

        const payload = await response.json();
        if (!payload || (Array.isArray(payload) && payload.length === 0) || (typeof payload === 'object' && Object.keys(payload).length === 0)) {
          renderNoData(container);
          return;
        }

        renderOverview(container, payload);
      } catch (error) {
        renderNoData(container);
      }
    }));
  });
})();

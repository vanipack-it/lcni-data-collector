(function () {
  const cfg = window.lcniWatchlistConfig || {};

  function api(path, options) {
    return fetch((cfg.restBase || '').replace(/\/$/, '') + path, {
      method: (options && options.method) || 'GET',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
      },
      body: options && options.body ? JSON.stringify(options.body) : undefined,
      credentials: 'same-origin',
    }).then((r) => r.json());
  }

  function showToast(msg) {
    const node = document.createElement('div');
    node.className = 'lcni-watchlist-toast';
    node.textContent = msg;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 2400);
  }

  function applyStyles(host) {
    const styles = (cfg.settingsOption && cfg.settingsOption.styles) || {};
    host.style.fontFamily = styles.font || '';
    host.style.color = styles.text_color || '';
    host.style.background = styles.background || '';
    host.style.border = styles.border || '';
    host.style.borderRadius = ((styles.border_radius || 0) + 'px');
    host.style.padding = '8px';
  }

  function renderTable(host, data) {
    applyStyles(host);
    const columns = data.columns || [];
    const items = data.items || [];

    const toggles = columns.map((c) => `<label><input type="checkbox" data-col-toggle value="${c}" checked> ${c}</label>`).join('');

    host.innerHTML = `
      <div class="lcni-watchlist-controls">${toggles}</div>
      <table class="lcni-watchlist-table"><thead><tr>${columns.map((c) => `<th>${c}</th>`).join('')}</tr></thead>
      <tbody>${items.map((row) => `<tr data-row-symbol="${row.symbol || ''}">${columns.map((c) => `<td>${row[c] == null ? '' : row[c]}</td>`).join('')}</tr>`).join('')}</tbody></table>
      <div class="lcni-watchlist-popup" hidden></div>
    `;

    host.querySelectorAll('[data-col-toggle]').forEach((input) => {
      input.addEventListener('change', () => {
        const name = input.value;
        const index = columns.indexOf(name);
        host.querySelectorAll(`th:nth-child(${index + 1}), td:nth-child(${index + 1})`).forEach((el) => {
          el.style.display = input.checked ? '' : 'none';
        });
      });
    });

    host.querySelectorAll('tbody tr').forEach((row) => {
      row.addEventListener('click', () => {
        const symbol = row.getAttribute('data-row-symbol');
        openPopup(host, symbol);
      });
    });
  }

  function openPopup(host, symbol) {
    const popup = host.querySelector('.lcni-watchlist-popup');
    popup.hidden = false;
    popup.innerHTML = `<div class="lcni-watchlist-popup-content"><button data-close>x</button><h3>${symbol}</h3><p>Loading...</p></div>`;
    popup.querySelector('[data-close]').addEventListener('click', () => (popup.hidden = true));

    fetch((cfg.stockApiBase || '') + encodeURIComponent(symbol), { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => {
        popup.innerHTML = `<div class="lcni-watchlist-popup-content">
          <button data-close>x</button>
          <h3>${symbol}</h3>
          <h4>Overview</h4><pre>${JSON.stringify(data.data || data, null, 2)}</pre>
          <h4>Lightweight chart</h4><div>${renderSparkline((data.data && data.data.ohlc) || [])}</div>
          <h4>LCNI signal</h4><pre>${JSON.stringify((data.data && data.data.indicators) || {}, null, 2)}</pre>
        </div>`;
        popup.querySelector('[data-close]').addEventListener('click', () => (popup.hidden = true));
      });
  }

  function renderSparkline(ohlc) {
    const closes = Array.isArray(ohlc) ? ohlc.map((i) => Number(i.close_price || i.close || 0)).filter(Boolean) : [];
    if (!closes.length) return 'No chart data';
    const min = Math.min.apply(null, closes);
    const max = Math.max.apply(null, closes);
    const points = closes.map((v, i) => `${(i / (closes.length - 1)) * 180},${40 - ((v - min) / (max - min || 1)) * 30}`).join(' ');
    return `<svg width="180" height="40" viewBox="0 0 180 40"><polyline fill="none" stroke="#2563eb" stroke-width="2" points="${points}" /></svg>`;
  }

  function bindAddButtons() {
    document.querySelectorAll('[data-lcni-watchlist-add]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!cfg.isLoggedIn) {
          document.dispatchEvent(new CustomEvent('lcni:watchlist:require-login'));
          if (cfg.loginUrl) { window.location.href = cfg.loginUrl; }
          return;
        }

        const symbol = btn.getAttribute('data-symbol');
        const icon = btn.querySelector('i');
        btn.disabled = true;
        btn.classList.add('is-loading');

        api('/add', { method: 'POST', body: { symbol } })
          .then((res) => {
            if (res && !res.code) {
              icon.className = 'fa-solid fa-check';
            } else {
              showToast((res && res.message) || 'Không thể thêm vào watchlist');
            }
          })
          .catch(() => showToast('Không thể thêm vào watchlist'))
          .finally(() => {
            btn.classList.remove('is-loading');
            btn.disabled = false;
          });
      });
    });
  }

  function boot() {
    document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => {
      if (!cfg.isLoggedIn) {
        host.innerHTML = '<a href="' + (cfg.loginUrl || '#') + '">Đăng nhập để xem watchlist</a>';
        return;
      }
      api('/list')
        .then((data) => renderTable(host, data))
        .catch(() => {
          host.innerHTML = '<p>Không thể tải watchlist</p>';
        });
    });

    bindAddButtons();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

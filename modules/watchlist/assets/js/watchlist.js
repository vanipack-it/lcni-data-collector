(function () {
  const cfg = window.lcniWatchlistConfig || {};

  function api(path, options) {
    return fetch((cfg.restBase || '').replace(/\/$/, '') + path, {
      method: (options && options.method) || 'GET',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || ''
      },
      body: options && options.body ? JSON.stringify(options.body) : undefined,
      credentials: 'same-origin'
    }).then(async (response) => {
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw payload;
      return payload;
    });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function isMobile() {
    return window.matchMedia('(max-width: 767px)').matches;
  }

  function getDevice() {
    return isMobile() ? 'mobile' : 'desktop';
  }

  function getStorageKey(device) {
    return `${cfg.settingsStorageKey || 'lcni_watchlist_settings_v1'}:${device}`;
  }

  function loadCachedColumns(device) {
    try {
      const raw = window.localStorage.getItem(getStorageKey(device));
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed.columns) ? parsed.columns : [];
    } catch (e) {
      return [];
    }
  }

  function saveCachedColumns(device, columns) {
    try {
      window.localStorage.setItem(getStorageKey(device), JSON.stringify({ columns, updatedAt: Date.now() }));
    } catch (e) {}
  }

  function showToast(msg) {
    const node = document.createElement('div');
    node.className = 'lcni-watchlist-toast';
    node.textContent = msg;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 2400);
  }

  function setHostLoading(host, isLoading) {
    const overlay = host.querySelector('[data-watchlist-overlay]');
    if (!overlay) return;
    overlay.hidden = !isLoading;
  }

  function buildStockDetailUrl(symbol) {
    const pageSlug = String(cfg.stockDetailPageSlug || '').replace(/^\/+|\/+$/g, '');
    const encodedSymbol = encodeURIComponent(String(symbol || '').trim());

    if (!pageSlug || !encodedSymbol) {
      return '';
    }

    return `/${pageSlug}/?symbol=${encodedSymbol}`;
  }

  function renderTable(host, data) {
    const allowedColumns = Array.isArray(data.allowed_columns) ? data.allowed_columns : [];
    const columns = Array.isArray(data.columns) ? data.columns : [];
    const items = Array.isArray(data.items) ? data.items : [];

    const toggles = allowedColumns.map((c) => `<label class="lcni-watchlist-col-item"><input type="checkbox" data-col-toggle value="${escapeHtml(c)}" ${columns.includes(c) ? 'checked' : ''}> ${escapeHtml(c)}</label>`).join('');

    host.innerHTML = `
      <div class="lcni-watchlist-header">
        <strong>Watchlist</strong>
        <div class="lcni-watchlist-dropdown">
          <button type="button" class="lcni-watchlist-settings-btn" data-watchlist-settings aria-expanded="false">⚙</button>
          <div class="lcni-watchlist-controls" data-watchlist-settings-panel>
            <div class="lcni-watchlist-col-grid">${toggles}</div>
            <button type="button" data-watchlist-save>Lưu</button>
          </div>
        </div>
      </div>
      <div class="lcni-watchlist-table-wrap">
        <table class="lcni-watchlist-table"><thead><tr>${columns.map((c, idx) => `<th class="${idx === 0 && c === 'symbol' ? 'is-sticky-col' : ''}">${escapeHtml(c)}</th>`).join('')}</tr></thead>
        <tbody>${items.map((row) => {
          const rowSymbol = row.symbol || '';
          return `<tr data-row-symbol="${escapeHtml(rowSymbol)}">${columns.map((c, idx) => {
            const cls = idx === 0 && c === 'symbol' ? ' class="is-sticky-col"' : '';
            if (c === 'symbol') {
              return `<td${cls}><span class="lcni-watchlist-symbol">${escapeHtml(rowSymbol)}</span><button type="button" class="lcni-watchlist-add" data-lcni-watchlist-add data-symbol="${escapeHtml(rowSymbol)}" aria-label="Add to watchlist"><i class="fa-solid fa-heart-circle-plus" aria-hidden="true"></i></button></td>`;
            }
            return `<td${cls}>${escapeHtml(row[c])}</td>`;
          }).join('')}</tr>`;
        }).join('')}</tbody></table>
        <div class="lcni-watchlist-overlay" data-watchlist-overlay hidden>Loading...</div>
      </div>`;
  }

  function bindDelegation(host) {
    if (host.dataset.boundWatchlist === '1') return;
    host.dataset.boundWatchlist = '1';

    host.addEventListener('click', async (event) => {
      const settingsBtn = event.target.closest('[data-watchlist-settings]');
      if (settingsBtn) {
        const dropdown = host.querySelector('.lcni-watchlist-dropdown');
        const expanded = settingsBtn.getAttribute('aria-expanded') === 'true';
        settingsBtn.setAttribute('aria-expanded', String(!expanded));
        if (dropdown) dropdown.classList.toggle('open', !expanded);
        return;
      }

      const saveBtn = event.target.closest('[data-watchlist-save]');
      if (saveBtn) {
        const selected = Array.from(host.querySelectorAll('[data-col-toggle]:checked')).map((input) => input.value);
        const device = getDevice();
        setHostLoading(host, true);
        try {
          saveCachedColumns(device, selected);
          const payload = await api('/settings?device=' + encodeURIComponent(device), { method: 'POST', body: { columns: selected } });
          const cols = Array.isArray(payload.columns) ? payload.columns : selected;
          const refreshed = await api('/list?device=' + encodeURIComponent(device) + '&' + cols.map((value) => `columns[]=${encodeURIComponent(value)}`).join('&'));
          renderTable(host, refreshed);
        } catch (error) {
          showToast((error && error.message) || 'Không thể lưu cài đặt.');
        } finally {
          setHostLoading(host, false);
        }
        return;
      }

      const row = event.target.closest('tbody tr[data-row-symbol]');
      if (row) {
        if (event.target.closest('[data-lcni-watchlist-add],button,a,i,svg,[role="button"]')) {
          return;
        }

        const symbol = row.getAttribute('data-row-symbol');
        const targetUrl = buildStockDetailUrl(symbol);
        if (targetUrl) {
          window.location.href = targetUrl;
        }
        return;
      }

      const addBtn = event.target.closest('[data-lcni-watchlist-add]');
      if (!addBtn || !host.contains(addBtn)) return;
      if (!cfg.isLoggedIn) {
        document.dispatchEvent(new CustomEvent('lcni:watchlist:require-login'));
        if (cfg.loginUrl) window.location.href = cfg.loginUrl;
        return;
      }

      const symbol = addBtn.getAttribute('data-symbol');
      const icon = addBtn.querySelector('i');
      addBtn.disabled = true;
      addBtn.classList.remove('is-success', 'is-error');
      addBtn.classList.add('is-loading');
      if (icon) icon.className = 'fa-solid fa-spinner fa-spin';

      api('/add', { method: 'POST', body: { symbol } })
        .then(() => {
          addBtn.classList.add('is-success');
          if (icon) icon.className = 'fa-solid fa-check';
        })
        .catch((error) => {
          addBtn.classList.add('is-error');
          if (icon) icon.className = 'fa-solid fa-heart-circle-plus';
          showToast((error && error.message) || 'Không thể thêm vào watchlist');
        })
        .finally(() => {
          addBtn.classList.remove('is-loading');
          addBtn.disabled = false;
        });
    });
    document.addEventListener('click', (event) => {
      if (host.contains(event.target)) {
        return;
      }

      const dropdown = host.querySelector('.lcni-watchlist-dropdown');
      const settingsBtn = host.querySelector('[data-watchlist-settings]');
      if (dropdown) dropdown.classList.remove('open');
      if (settingsBtn) settingsBtn.setAttribute('aria-expanded', 'false');
    });

  }

  async function bootHost(host) {
    bindDelegation(host);

    if (!cfg.isLoggedIn) {
      host.innerHTML = '<a href="' + (cfg.loginUrl || '#') + '">Đăng nhập để xem watchlist</a>';
      return;
    }

    const device = getDevice();
    const defaults = device === 'mobile' ? (cfg.defaultColumnsMobile || []) : (cfg.defaultColumnsDesktop || []);
    const cached = loadCachedColumns(device);
    const selected = cached.length ? cached : defaults;

    try {
      const query = selected.length ? '&' + selected.map((value) => `columns[]=${encodeURIComponent(value)}`).join('&') : '';
      const fastData = await api('/list?device=' + encodeURIComponent(device) + query);
      renderTable(host, fastData);
      saveCachedColumns(device, fastData.columns || []);
      api('/settings?device=' + encodeURIComponent(device)).then((settings) => {
        const serverColumns = Array.isArray(settings.columns) ? settings.columns : [];
        if (!serverColumns.length) return;
        saveCachedColumns(device, serverColumns);
      }).catch(() => {});
    } catch (error) {
      host.innerHTML = '<p>Không thể tải watchlist</p>';
    }
  }

  function boot() {
    document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => {
      bootHost(host);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

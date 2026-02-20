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
    }).then(async (response) => {
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw payload;
      }
      return payload;
    });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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

  function setHostLoading(host, isLoading) {
    const overlay = host.querySelector('[data-watchlist-overlay]');
    if (!overlay) return;
    overlay.hidden = !isLoading;
    overlay.style.pointerEvents = isLoading ? 'auto' : 'none';
  }

  function renderSymbolCell(symbol) {
    const safeSymbol = escapeHtml(symbol);
    return `<span class="lcni-watchlist-symbol">${safeSymbol}</span><button type="button" class="lcni-watchlist-add" data-lcni-watchlist-add data-symbol="${safeSymbol}" aria-label="Add to watchlist"><i class="fa-solid fa-heart-circle-plus" aria-hidden="true"></i></button>`;
  }

  function buildStockDetailUrl(symbol) {
    const base = (cfg.stockDetailBase || '/stock/').replace(/\/?$/, '/');
    return base + encodeURIComponent(symbol);
  }

  function renderTable(host, data) {
    applyStyles(host);
    const allowedColumns = Array.isArray(data.allowed_columns) ? data.allowed_columns : [];
    const columns = Array.isArray(data.columns) ? data.columns : [];
    const items = Array.isArray(data.items) ? data.items : [];

    const toggles = allowedColumns.map((c) => `<label class="lcni-watchlist-col-item"><input type="checkbox" data-col-toggle value="${escapeHtml(c)}" ${columns.includes(c) ? 'checked' : ''}> ${escapeHtml(c)}</label>`).join('');

    host.innerHTML = `
      <div class="lcni-watchlist-header">
        <strong>Watchlist</strong>
        <div class="lcni-watchlist-dropdown">
          <button type="button" class="lcni-watchlist-settings-btn" data-watchlist-settings aria-expanded="false">⚙</button>
          <div class="lcni-watchlist-controls" data-watchlist-settings-panel hidden>
            <div class="lcni-watchlist-col-grid">${toggles}</div>
            <button type="button" data-watchlist-save>Lưu</button>
          </div>
        </div>
      </div>
      <div class="lcni-watchlist-table-wrap">
        <table class="lcni-watchlist-table"><thead><tr>${columns.map((c) => `<th>${escapeHtml(c)}</th>`).join('')}</tr></thead>
        <tbody>${items.map((row) => {
          const rowSymbol = row.symbol || '';
          return `<tr data-row-symbol="${escapeHtml(rowSymbol)}">${columns.map((c) => {
            if (c === 'symbol') {
              return `<td>${renderSymbolCell(rowSymbol)}</td>`;
            }
            return `<td>${escapeHtml(row[c])}</td>`;
          }).join('')}</tr>`;
        }).join('')}</tbody></table>
        <div class="lcni-watchlist-overlay" data-watchlist-overlay hidden>Loading...</div>
      </div>
    `;

    const panel = host.querySelector('[data-watchlist-settings-panel]');
    const settingsBtn = host.querySelector('[data-watchlist-settings]');
    const saveBtn = host.querySelector('[data-watchlist-save]');

    settingsBtn.addEventListener('click', () => {
      const isExpanded = settingsBtn.getAttribute('aria-expanded') === 'true';
      settingsBtn.setAttribute('aria-expanded', String(!isExpanded));
      panel.hidden = isExpanded;
    });

    saveBtn.addEventListener('click', async () => {
      const selected = Array.from(host.querySelectorAll('[data-col-toggle]:checked')).map((input) => input.value);
      setHostLoading(host, true);
      try {
        await api('/settings', { method: 'POST', body: { columns: selected } });
        const query = selected.map((value) => `columns[]=${encodeURIComponent(value)}`).join('&');
        const refreshed = await api('/list' + (query ? '?' + query : ''));
        renderTable(host, refreshed);
        bindAddButtons(host);
      } catch (error) {
        showToast((error && error.message) || 'Không thể lưu cài đặt.');
      } finally {
        setHostLoading(host, false);
      }
    });

    if (!host._watchlistRowDelegated) {
      host.addEventListener('click', (event) => {
        const row = event.target.closest('tbody tr[data-row-symbol]');
        if (!row || !host.contains(row)) {
          return;
        }

        if (event.target.closest('[data-lcni-watchlist-add]')) {
          return;
        }

        const symbol = row.getAttribute('data-row-symbol');
        if (symbol) {
          window.location.href = buildStockDetailUrl(symbol);
        }
      });
      host._watchlistRowDelegated = true;
    }
  }

  function bindAddButtons(scope) {
    (scope || document).querySelectorAll('[data-lcni-watchlist-add]').forEach((btn) => {
      if (btn.dataset.boundWatchlist === '1') return;
      btn.dataset.boundWatchlist = '1';

      btn.addEventListener('click', () => {
        if (!cfg.isLoggedIn) {
          document.dispatchEvent(new CustomEvent('lcni:watchlist:require-login'));
          if (cfg.loginUrl) { window.location.href = cfg.loginUrl; }
          return;
        }

        const symbol = btn.getAttribute('data-symbol');
        const icon = btn.querySelector('i');
        btn.disabled = true;
        btn.classList.remove('is-success', 'is-error');
        btn.classList.add('is-loading');
        if (icon) {
          icon.className = 'fa-solid fa-spinner fa-spin';
        }

        api('/add', { method: 'POST', body: { symbol } })
          .then(() => {
            btn.classList.add('is-success');
            if (icon) {
              icon.className = 'fa-solid fa-check';
            }
          })
          .catch((error) => {
            btn.classList.add('is-error');
            if (icon) {
              icon.className = 'fa-solid fa-heart-circle-plus';
            }
            showToast((error && error.message) || 'Không thể thêm vào watchlist');
          })
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
        .then((data) => {
          renderTable(host, data);
          bindAddButtons(host);
        })
        .catch(() => {
          host.innerHTML = '<p>Không thể tải watchlist</p>';
        });
    });

    bindAddButtons(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

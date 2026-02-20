(function () {
  const cfg = window.lcniFilterConfig || {};
  const state = { page: 1, filters: [], criteria: [], visibleColumns: [] };
  let loadTimer = null;

  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const sessionKey = cfg.tableSettingsStorageKey || 'lcni_filter_visible_columns_v1';

  function buildStockDetailUrl(symbol) {
    const slug = String(cfg.stockDetailPageSlug || '').replace(/^\/+|\/+$/g, '');
    const encoded = encodeURIComponent(String(symbol || '').trim());
    return slug && encoded ? `/${slug}/?symbol=${encoded}` : '';
  }

  function api(body) {
    return fetch(cfg.restUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(async (response) => {
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw payload;
      return payload;
    });
  }

  function watchlistApi(path, options) {
    return fetch((cfg.watchlistRestBase || '').replace(/\/$/, '') + path, {
      method: options.method || 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      credentials: 'same-origin',
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(async (response) => {
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw payload;
      return payload;
    });
  }

  function loadVisibleColumns(defaultColumns) {
    try {
      const raw = JSON.parse(sessionStorage.getItem(sessionKey) || '[]');
      return Array.isArray(raw) && raw.length ? raw : defaultColumns;
    } catch (e) {
      return defaultColumns;
    }
  }

  function saveVisibleColumns(cols) {
    try { sessionStorage.setItem(sessionKey, JSON.stringify(cols)); } catch (e) {}
  }

  function collectFilters(host) {
    return state.criteria.map((item) => {
      if (item.type === 'number') {
        const min = host.querySelector(`[data-range-min="${item.column}"]`);
        const max = host.querySelector(`[data-range-max="${item.column}"]`);
        return { column: item.column, operator: 'between', value: [min ? min.value : '', max ? max.value : ''] };
      }
      const checked = Array.from(host.querySelectorAll(`[data-text-check="${item.column}"]:checked`)).map((n) => n.value);
      return { column: item.column, operator: 'in', value: checked };
    }).filter((f) => Array.isArray(f.value) ? f.value.join('') !== '' : String(f.value || '') !== '');
  }

  function render(host, response) {
    const settings = response.settings || cfg.settings || {};
    const data = response.data || {};
    state.criteria = response.criteria || [];

    if (!host.dataset.ready) {
      state.visibleColumns = loadVisibleColumns(settings.table_columns || data.columns || []);
      host.innerHTML = `<div class="lcni-filter-toolbar"><button type="button" data-filter-toggle>Filter</button><button type="button" data-column-toggle-btn>⚙</button></div>
      <div class="lcni-filter-panel" data-filter-panel hidden></div>
      <div class="lcni-column-pop" data-column-pop hidden></div>
      <div class="lcni-watchlist-table-wrap"><table class="lcni-watchlist-table"><thead></thead><tbody></tbody></table></div>
      <div data-filter-pagination></div>`;
      host.dataset.ready = '1';
    }

    const labels = data.column_labels || settings.column_labels || {};
    const columns = data.columns || [];
    host.querySelector('thead').innerHTML = `<tr>${columns.map((c, i) => `<th class="${i===0?'is-sticky-col':''}">${esc(labels[c] || c)}</th>`).join('')}</tr>`;

    const addBtn = settings.add_button || {};
    host.querySelector('tbody').innerHTML = (data.items || []).map((row) => `<tr data-row-symbol="${esc(row.symbol || '')}">${columns.map((c, i) => {
      if (c === 'symbol') {
        const icon = addBtn.icon || 'fas fa-heart';
        return `<td class="${i===0?'is-sticky-col':''}"><span>${esc(row.symbol || '')}</span> <button type="button" data-lcni-watchlist-add data-symbol="${esc(row.symbol || '')}"><i class="${esc(icon)}" aria-hidden="true"></i></button></td>`;
      }
      return `<td>${esc(row[c])}</td>`;
    }).join('')}</tr>`).join('');

    const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.limit || 50)));
    host.querySelector('[data-filter-pagination]').innerHTML = `<button type="button" data-prev ${data.page <= 1 ? 'disabled' : ''}>Prev</button> <span>${data.page}/${totalPages}</span> <button type="button" data-next ${data.page >= totalPages ? 'disabled' : ''}>Next</button>`;

    host.querySelector('[data-filter-panel]').innerHTML = `${state.criteria.map((item) => {
      if (item.type === 'number') {
        return `<div><strong>${esc(item.label)}</strong><div><input type="number" data-range-min="${esc(item.column)}" value="${esc(item.min)}"> - <input type="number" data-range-max="${esc(item.column)}" value="${esc(item.max)}"></div></div>`;
      }
      return `<div><strong>${esc(item.label)}</strong><div class="lcni-filter-check-list">${(item.values || []).map((v) => `<label><input type="checkbox" data-text-check="${esc(item.column)}" value="${esc(v)}"> ${esc(v)}</label>`).join('')}</div></div>`;
    }).join('')}<button type="button" data-apply-filter>Apply Filter</button>`;

    const selectable = settings.table_columns || columns;
    host.querySelector('[data-column-pop]').innerHTML = `${selectable.map((c) => `<label><input type="checkbox" data-visible-col value="${esc(c)}" ${state.visibleColumns.includes(c) ? 'checked' : ''}> ${esc(labels[c] || c)}</label>`).join('')}<button type="button" data-save-columns>Save</button>`;
  }

  async function load(host) {
    const response = await api({ mode: 'filter', page: state.page, limit: 50, filters: state.filters, visible_columns: state.visibleColumns });
    render(host, response);
  }

  function debounceLoad(host) {
    window.clearTimeout(loadTimer);
    loadTimer = window.setTimeout(() => load(host).catch(() => {}), 300);
  }

  function bind(host) {
    host.addEventListener('click', async (event) => {
      const toggle = event.target.closest('[data-filter-toggle]');
      if (toggle) {
        const panel = host.querySelector('[data-filter-panel]');
        panel.hidden = !panel.hidden;
      }

      const settingBtn = event.target.closest('[data-column-toggle-btn]');
      if (settingBtn) {
        const pop = host.querySelector('[data-column-pop]');
        pop.hidden = !pop.hidden;
      }

      const saveColumns = event.target.closest('[data-save-columns]');
      if (saveColumns) {
        state.visibleColumns = Array.from(host.querySelectorAll('[data-visible-col]:checked')).map((n) => n.value);
        if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol');
        saveVisibleColumns(state.visibleColumns);
        state.page = 1;
        await load(host);
      }

      const apply = event.target.closest('[data-apply-filter]');
      if (apply) {
        state.filters = collectFilters(host);
        state.page = 1;
        await load(host);
      }

      const prev = event.target.closest('[data-prev]');
      if (prev) {
        state.page = Math.max(1, state.page - 1);
        await load(host);
      }
      const next = event.target.closest('[data-next]');
      if (next) {
        state.page += 1;
        await load(host);
      }

      const addBtn = event.target.closest('[data-lcni-watchlist-add]');
      if (addBtn) {
        event.preventDefault();
        event.stopPropagation();
        if (!cfg.isLoggedIn) {
          if (cfg.loginUrl) window.location.href = cfg.loginUrl;
          return;
        }
        const symbol = addBtn.getAttribute('data-symbol');
        const icon = addBtn.querySelector('i');
        addBtn.disabled = true;
        if (icon) icon.className = 'fas fa-spinner fa-spin';
        try {
          await watchlistApi('/add', { method: 'POST', body: { symbol } });
          addBtn.classList.add('is-active');
          if (icon) icon.className = 'fas fa-check';
        } catch (e) {
          if (icon) icon.className = 'fas fa-exclamation-circle';
        } finally {
          addBtn.disabled = false;
        }
      }

      const row = event.target.closest('tbody tr[data-row-symbol]');
      if (row && !event.target.closest('button,a,[role="button"],svg,i')) {
        const url = buildStockDetailUrl(row.getAttribute('data-row-symbol'));
        if (url) window.location.href = url;
      }
    });

    host.addEventListener('input', (event) => {
      if (event.target.matches('[data-range-min],[data-range-max]')) {
        state.filters = collectFilters(host);
        state.page = 1;
        debounceLoad(host);
      }
    });

    host.addEventListener('change', (event) => {
      if (event.target.matches('[data-text-check]')) {
        state.filters = collectFilters(host);
        state.page = 1;
        debounceLoad(host);
      }
    });
  }

  function boot() {
    document.querySelectorAll('[data-lcni-stock-filter]').forEach(async (host) => {
      await load(host);
      bind(host);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

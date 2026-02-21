(function () {
  const cfg = window.lcniFilterConfig || {};
  const state = {
    page: 1,
    limit: 50,
    filters: [],
    visibleColumns: [],
    criteria: Array.isArray(cfg.criteria) ? cfg.criteria : [],
    total: 0
  };

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

  function applyDefaultFilters(host) {
    const defaults = cfg.defaultFilterValues || {};
    if (!defaults || typeof defaults !== 'object') return false;

    let hasAny = false;
    state.criteria.forEach((item) => {
      const defaultValue = defaults[item.column];
      if (typeof defaultValue === 'undefined' || defaultValue === null) return;

      if (item.type === 'number') {
        const min = host.querySelector(`[data-range-min="${item.column}"]`);
        const max = host.querySelector(`[data-range-max="${item.column}"]`);
        const range = Array.isArray(defaultValue) ? defaultValue : [];
        if (min && typeof range[0] !== 'undefined') min.value = range[0];
        if (max && typeof range[1] !== 'undefined') max.value = range[1];
        if ((min && String(min.value) !== '') || (max && String(max.value) !== '')) hasAny = true;
        return;
      }

      const selected = Array.isArray(defaultValue) ? defaultValue.map((v) => String(v)) : [String(defaultValue)];
      host.querySelectorAll(`[data-text-check="${item.column}"]`).forEach((input) => {
        input.checked = selected.includes(String(input.value));
      });
      if (selected.length) hasAny = true;
    });

    return hasAny;
  }

  function renderStatic(host) {
    const settings = cfg.settings || {};
    const labels = settings.column_labels || {};
    const columns = state.visibleColumns;

    host.innerHTML = `<div class="lcni-filter-toolbar"><button type="button" class="lcni-btn" data-filter-toggle>Filter</button><button type="button" class="lcni-btn" data-column-toggle-btn>⚙</button></div>
      <div class="lcni-filter-panel" data-filter-panel hidden></div>
      <div class="lcni-column-pop" data-column-pop hidden></div>
      <div class="lcni-watchlist-table-wrap lcni-table-wrapper"><table class="lcni-watchlist-table lcni-table"><thead><tr>${columns.map((c, i) => `<th class="${i === 0 ? 'is-sticky-col' : ''}">${esc(labels[c] || c)}</th>`).join('')}</tr></thead><tbody></tbody></table></div>
      <div data-filter-pagination></div>`;

    host.querySelector('[data-filter-panel]').innerHTML = `${state.criteria.map((item) => {
      if (item.type === 'number') {
        return `<div><strong>${esc(item.label)}</strong><div><input type="number" data-range-min="${esc(item.column)}" value="${esc(item.min)}"> - <input type="number" data-range-max="${esc(item.column)}" value="${esc(item.max)}"></div></div>`;
      }
      return `<div><strong>${esc(item.label)}</strong><div class="lcni-filter-check-list">${(item.values || []).map((v) => `<label><input type="checkbox" data-text-check="${esc(item.column)}" value="${esc(v)}"> ${esc(v)}</label>`).join('')}</div></div>`;
    }).join('')}<button type="button" class="lcni-btn btn-apply-filter" data-apply-filter>Apply Filter</button>`;

    const selectable = settings.table_columns || columns;
    host.querySelector('[data-column-pop]').innerHTML = `${selectable.map((c) => `<label><input type="checkbox" data-visible-col value="${esc(c)}" ${state.visibleColumns.includes(c) ? 'checked' : ''}> ${esc(labels[c] || c)}</label>`).join('')}<button type="button" class="lcni-btn" data-save-columns>Save</button>`;
  }

  function renderTbody(host, payload) {
    const tbody = host.querySelector('tbody');
    if (tbody) {
      tbody.innerHTML = payload.rows || '';
    }
    state.total = Number(payload.total || 0);
    const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
    host.querySelector('[data-filter-pagination]').innerHTML = `<button type="button" class="lcni-btn" data-prev ${state.page <= 1 ? 'disabled' : ''}>Prev</button> <span>${state.page}/${totalPages}</span> <button type="button" class="lcni-btn" data-next ${state.page >= totalPages ? 'disabled' : ''}>Next</button>`;
  }

  async function load(host) {
    const payload = await api({ mode: 'filter', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns });
    renderTbody(host, payload || {});
  }

  async function refreshOnly(host) {
    const tbody = host.querySelector('tbody');
    if (!tbody) return;
    const payload = await api({ mode: 'refresh', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns });
    tbody.innerHTML = payload.rows || '';
  }

  function startAutoRefresh(host) {
    window.clearInterval(host._lcniRefreshTimer);
    host._lcniRefreshTimer = window.setInterval(() => {
      refreshOnly(host).catch(() => {});
    }, 15000);
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
        renderStatic(host);
        await load(host);
        return;
      }

      const apply = event.target.closest('.btn-apply-filter,[data-apply-filter]');
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

      const row = event.target.closest('tr[data-symbol]');
      if (row && !event.target.closest('button')) {
        const url = buildStockDetailUrl(row.getAttribute('data-symbol'));
        if (url) window.location.href = url;
      }
    });
  }

  function boot() {
    const defaultColumns = ((cfg.settings || {}).table_columns || []).slice();
    state.visibleColumns = loadVisibleColumns(defaultColumns);
    if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol');

    document.querySelectorAll('[data-lcni-stock-filter]').forEach(async (host) => {
      renderStatic(host);
      bind(host);
      const hasDefaults = applyDefaultFilters(host);
      if (hasDefaults) {
        state.filters = collectFilters(host);
      }
      await load(host);
      startAutoRefresh(host);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

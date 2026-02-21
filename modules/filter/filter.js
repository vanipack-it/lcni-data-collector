(function () {
  const cfg = window.lcniFilterConfig || {};
  window.lcniData = window.lcniData || {};
  if (!window.lcniData.stockDetailUrl && cfg.stockDetailUrl) window.lcniData.stockDetailUrl = cfg.stockDetailUrl;

  const state = {
    page: 1,
    limit: 50,
    filters: [],
    visibleColumns: [],
    criteria: Array.isArray(cfg.criteria) ? cfg.criteria : [],
    total: 0,
    savedFilters: [],
    selectedSavedFilterId: 0,
    lastAppliedTotal: 0
  };

  const sessionKey = cfg.tableSettingsStorageKey || 'lcni_filter_visible_columns_v1';
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  function getButtonConfig(key) { return (cfg.buttonConfig || {})[key] || {}; }
  function renderButtonContent(key, fallbackLabel, forceLabel) {
    const conf = getButtonConfig(key);
    const icon = conf.icon_class ? `<i class=\"${esc(conf.icon_class)}\" aria-hidden=\"true\"></i>` : '';
    const text = typeof forceLabel === 'string' ? forceLabel : (conf.label_text || fallbackLabel || '');
    const label = `<span>${esc(text)}</span>`;
    return conf.icon_position === 'right' ? `${label}${icon}` : `${icon}${label}`;
  }

  function api(body) {
    return fetch(cfg.restUrl, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }, credentials: 'same-origin', body: JSON.stringify(body)
    }).then(async (r) => {
      const payload = await r.json().catch(() => ({}));
      if (!r.ok) throw payload;
      return payload;
    });
  }

  function savedFilterApi(path, options) {
    return fetch((cfg.savedFilterBase || '').replace(/\/$/, '') + path, {
      method: (options && options.method) || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      credentials: 'same-origin',
      body: options && options.body ? JSON.stringify(options.body) : undefined
    }).then(async (r) => {
      const payload = await r.json().catch(() => ({}));
      if (!r.ok) throw payload;
      return payload;
    });
  }

  function watchlistApi(path, options) {
    return fetch((cfg.watchlistRestBase || '').replace(/\/$/, '') + path, {
      method: (options && options.method) || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      credentials: 'same-origin',
      body: options && options.body ? JSON.stringify(options.body) : undefined
    }).then(async (r) => {
      const payload = await r.json().catch(() => ({}));
      if (!r.ok) throw payload;
      return payload;
    });
  }

  function showToast(message) {
    const node = document.createElement('div');
    node.className = 'lcni-filter-toast';
    node.textContent = message;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 2500);
  }

  function closeModal() {
    const existing = document.querySelector('.lcni-filter-modal-backdrop');
    if (existing) existing.remove();
  }

  function showModal(html) {
    closeModal();
    const wrap = document.createElement('div');
    wrap.className = 'lcni-filter-modal-backdrop';
    wrap.innerHTML = `<div class="lcni-filter-modal">${html}</div>`;
    wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('[data-modal-close]')) closeModal(); });
    document.body.appendChild(wrap);
  }

  function showAuthModal(message) {
    showModal(`<h3>${esc(message)}</h3><div class="lcni-filter-modal-actions"><a class="lcni-btn lcni-btn-btn_filter_apply" href="${esc(cfg.loginUrl || '#')}">Login</a><a class="lcni-btn lcni-btn-btn_filter_open" href="${esc((cfg.registerUrl || cfg.loginUrl || '#'))}">Register</a><button type="button" class="lcni-btn lcni-btn-btn_filter_delete" data-modal-close>Close</button></div>`);
  }

  async function openWatchlistSelector(symbol) {
    if (!cfg.isLoggedIn) {
      showAuthModal('Vui lòng đăng nhập hoặc đăng ký để thêm vào watchlist');
      return;
    }

    const data = await watchlistApi('/list?device=desktop', { method: 'GET' });
    const watchlists = Array.isArray(data.watchlists) ? data.watchlists : [];
    const activeId = Number(data.active_watchlist_id || 0);

    if (!watchlists.length) {
      showModal(`<h3>Tạo watchlist mới</h3><form data-create-watchlist-form><input type="text" name="name" placeholder="Tên watchlist" required><div class="lcni-filter-modal-actions"><button type="submit" class="lcni-btn lcni-btn-btn_watchlist_new">${renderButtonContent('btn_watchlist_new', '+ New')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_delete" data-modal-close>Close</button></div></form>`);
      const form = document.querySelector('[data-create-watchlist-form]');
      if (form) {
        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          const input = form.querySelector('input[name="name"]');
          const name = String(input && input.value || '').trim();
          if (!name) return;
          await watchlistApi('/create', { method: 'POST', body: { name } });
          closeModal();
          openWatchlistSelector(symbol).catch(() => {});
        }, { once: true });
      }
      return;
    }

    showModal(`<h3>Chọn watchlist cho ${esc(symbol)}</h3><form data-select-watchlist-form><div class="lcni-filter-watchlist-options">${watchlists.map((w) => `<label><input type="radio" name="watchlist_id" value="${Number(w.id || 0)}" ${(Number(w.id || 0) === activeId) ? 'checked' : ''}> ${esc(w.name || '')}</label>`).join('')}</div><div class="lcni-filter-modal-actions"><button type="submit" class="lcni-btn lcni-btn-btn_apply_filter">Confirm</button><button type="button" class="lcni-btn lcni-btn-btn_filter_delete" data-modal-close>Close</button></div></form>`);
    const form = document.querySelector('[data-select-watchlist-form]');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const selected = form.querySelector('input[name="watchlist_id"]:checked');
      const watchlistId = Number(selected ? selected.value : 0);
      if (!watchlistId) return;
      await watchlistApi('/add-symbol', { method: 'POST', body: { symbol, watchlist_id: watchlistId } });
      closeModal();
      showToast('Đã thêm vào watchlist');
    }, { once: true });
  }

  function loadVisibleColumns(defaultColumns) {
    try {
      const raw = JSON.parse(sessionStorage.getItem(sessionKey) || '[]');
      return Array.isArray(raw) && raw.length ? raw : defaultColumns;
    } catch (e) { return defaultColumns; }
  }
  function saveVisibleColumns(cols) { try { sessionStorage.setItem(sessionKey, JSON.stringify(cols)); } catch (e) {} }

  function collectFilters(host) {
    return state.criteria.map((item) => {
      if (item.type === 'number') {
        return {
          column: item.column,
          operator: 'between',
          value: [
            (host.querySelector(`[data-range-min="${item.column}"]`) || {}).value || '',
            (host.querySelector(`[data-range-max="${item.column}"]`) || {}).value || ''
          ]
        };
      }

      return {
        column: item.column,
        operator: 'in',
        value: Array.from(host.querySelectorAll(`[data-text-check="${item.column}"]:checked`)).map((n) => n.value)
      };
    }).filter((f) => Array.isArray(f.value) ? f.value.join('') !== '' : String(f.value || '') !== '');
  }

  async function loadSavedFilters() {
    if (!cfg.isLoggedIn) {
      state.savedFilters = [];
      return;
    }
    const payload = await savedFilterApi('/list', { method: 'GET' });
    state.savedFilters = Array.isArray(payload.items) ? payload.items : [];
  }

  function applySavedFilterConfig(host, config) {
    const filters = Array.isArray((config || {}).filters) ? config.filters : [];
    state.filters = filters;
    state.criteria.forEach((item) => {
      const found = filters.find((f) => f.column === item.column);
      if (item.type === 'number') {
        const min = host.querySelector(`[data-range-min="${item.column}"]`);
        const max = host.querySelector(`[data-range-max="${item.column}"]`);
        const range = found && Array.isArray(found.value) ? found.value : ['', ''];
        if (min) min.value = range[0] || '';
        if (max) max.value = range[1] || '';
      } else {
        const selected = found && Array.isArray(found.value) ? found.value.map(String) : [];
        host.querySelectorAll(`[data-text-check="${item.column}"]`).forEach((i) => { i.checked = selected.includes(String(i.value)); });
      }
    });
  }

  function applyDefaultFilterConfig(host) {
    const defaults = cfg.defaultFilterValues || {};
    state.criteria.forEach((item) => {
      const value = defaults[item.column];
      if (item.type === 'number') {
        const min = host.querySelector(`[data-range-min="${item.column}"]`);
        const max = host.querySelector(`[data-range-max="${item.column}"]`);
        if (Array.isArray(value)) {
          if (min) min.value = value[0] || '';
          if (max) max.value = value[1] || '';
        }
      } else if (Array.isArray(value)) {
        const selected = value.map(String);
        host.querySelectorAll(`[data-text-check="${item.column}"]`).forEach((i) => { i.checked = selected.includes(String(i.value)); });
      }
    });
  }

  function getApplyLabel() {
    const fallback = (getButtonConfig('btn_filter_apply').label_text || getButtonConfig('btn_apply_filter').label_text || 'Apply Filter');
    return `${fallback} (${Number(state.lastAppliedTotal || 0)})`;
  }

  function renderStatic(host) {
    const settings = cfg.settings || {};
    const labels = settings.column_labels || {};
    const columns = state.visibleColumns;
    const selectedId = Number(state.selectedSavedFilterId || 0);

    host.innerHTML = `<div class="lcni-filter-toolbar"><button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-filter-toggle>${renderButtonContent('btn_filter_open', 'Filter')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_setting" data-column-toggle-btn>${renderButtonContent('btn_filter_setting', '')}</button></div><div class="lcni-filter-panel" data-filter-panel hidden><div class="lcni-filter-criteria">${state.criteria.map((item) => item.type === 'number' ? `<div><strong>${esc(item.label)}</strong><div><input type="number" data-range-min="${esc(item.column)}" value=""> - <input type="number" data-range-max="${esc(item.column)}" value=""></div></div>` : `<div><strong>${esc(item.label)}</strong><div class="lcni-filter-check-list">${(item.values || []).map((v) => `<label><input type="checkbox" data-text-check="${esc(item.column)}" value="${esc(v)}"> ${esc(v)}</label>`).join('')}</div></div>`).join('')}</div><div class="lcni-filter-panel-actions"><select data-saved-filter-select><option value="">Saved filters</option>${(state.savedFilters || []).map((f) => `<option value="${Number(f.id || 0)}" ${Number(f.id || 0) === selectedId ? 'selected' : ''}>${esc(f.filter_name || '')}</option>`).join('')}</select><button type="button" class="lcni-btn lcni-btn-btn_filter_reload" data-reload-filter>${renderButtonContent('btn_filter_reload', 'Reload')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_save" data-save-current-filter>${renderButtonContent('btn_filter_save', 'Save')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_delete" data-delete-current-filter>${renderButtonContent('btn_filter_delete', 'Delete')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_apply" data-apply-filter>${renderButtonContent('btn_filter_apply', 'Apply Filter', getApplyLabel())}</button></div></div><div class="lcni-column-pop" data-column-pop hidden></div><div class="lcni-table-scroll"><table class="lcni-table"><thead><tr>${columns.map((c) => `<th>${esc(labels[c] || c)}</th>`).join('')}</tr></thead><tbody></tbody></table></div><div data-filter-pagination></div>`;

    const selectable = settings.table_columns || columns;
    host.querySelector('[data-column-pop]').innerHTML = `${selectable.map((c) => `<label><input type="checkbox" data-visible-col value="${esc(c)}" ${state.visibleColumns.includes(c) ? 'checked' : ''}> ${esc(labels[c] || c)}</label>`).join('')}<button type="button" class="lcni-btn lcni-btn-btn_save_filter" data-save-columns>${renderButtonContent('btn_save_filter', 'Save')}</button>`;

    applyDefaultFilterConfig(host);
    if (state.filters.length) applySavedFilterConfig(host, { filters: state.filters });
  }

  function updateApplyButtonLabel(host) {
    const btn = host.querySelector('[data-apply-filter]');
    if (!btn) return;
    btn.innerHTML = renderButtonContent('btn_filter_apply', 'Apply Filter', getApplyLabel());
  }

  function renderTbody(host, payload) {
    const tbody = host.querySelector('tbody');
    if (tbody) tbody.innerHTML = payload.rows || '';
    state.total = Number(payload.total || state.total || 0);
    state.lastAppliedTotal = state.total;
    updateApplyButtonLabel(host);
    const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
    host.querySelector('[data-filter-pagination]').innerHTML = `<button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-prev ${state.page <= 1 ? 'disabled' : ''}>Prev</button> <span>${state.page}/${totalPages}</span> <button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-next ${state.page >= totalPages ? 'disabled' : ''}>Next</button>`;
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

  function bind(host) {
    host.addEventListener('click', async (event) => {
      if (event.target.closest('[data-filter-toggle]')) {
        host.querySelector('[data-filter-panel]').hidden = !host.querySelector('[data-filter-panel]').hidden;
        return;
      }
      if (event.target.closest('[data-column-toggle-btn]')) {
        host.querySelector('[data-column-pop]').hidden = !host.querySelector('[data-column-pop]').hidden;
        return;
      }
      if (event.target.closest('[data-save-columns]')) {
        state.visibleColumns = Array.from(host.querySelectorAll('[data-visible-col]:checked')).map((n) => n.value);
        if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol');
        saveVisibleColumns(state.visibleColumns);
        state.page = 1;
        renderStatic(host);
        await load(host);
        return;
      }

      if (event.target.closest('[data-save-current-filter]')) {
        if (!cfg.isLoggedIn) {
          showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
          return;
        }
        const name = window.prompt('Tên bộ lọc');
        if (!name) return;
        state.filters = collectFilters(host);
        await savedFilterApi('/save', { method: 'POST', body: { filter_name: name, filters: state.filters } });
        await loadSavedFilters();
        renderStatic(host);
        await load(host);
        return;
      }

      if (event.target.closest('[data-delete-current-filter]')) {
        if (!cfg.isLoggedIn) {
          showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
          return;
        }
        const id = Number(state.selectedSavedFilterId || 0);
        if (!id) return;
        await savedFilterApi('/delete', { method: 'POST', body: { id } });
        state.selectedSavedFilterId = 0;
        await loadSavedFilters();
        renderStatic(host);
        return;
      }

      if (event.target.closest('[data-reload-filter]')) {
        if (!cfg.isLoggedIn) {
          showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
          return;
        }
        const id = Number(state.selectedSavedFilterId || 0);
        if (!id) return;
        const payload = await savedFilterApi('/load?id=' + encodeURIComponent(id), { method: 'GET' });
        applySavedFilterConfig(host, payload.config || {});
        return;
      }

      if (event.target.closest('[data-apply-filter]')) {
        state.filters = collectFilters(host);
        state.page = 1;
        await load(host);
        return;
      }
      if (event.target.closest('[data-prev]')) { state.page = Math.max(1, state.page - 1); await load(host); return; }
      if (event.target.closest('[data-next]')) { state.page += 1; await load(host); return; }

      const addBtn = event.target.closest('[data-lcni-watchlist-add]');
      if (addBtn) {
        const symbol = String(addBtn.getAttribute('data-symbol') || '').trim().toUpperCase();
        if (!symbol) return;
        try {
          await openWatchlistSelector(symbol);
          addBtn.classList.add('is-active');
        } catch (e) {
          showToast((e && e.message) || 'Không thể cập nhật watchlist');
        }
      }
    });

    host.addEventListener('change', (event) => {
      const select = event.target.closest('[data-saved-filter-select]');
      if (!select) return;
      state.selectedSavedFilterId = Number(select.value || 0);
    });
  }

  function bindRowNavigation() {
    if (document.body.dataset.lcniFilterRowNavBound === '1') return;
    document.body.dataset.lcniFilterRowNavBound = '1';

    document.addEventListener('click', function (e) {
      if (e.target.closest('.lcni-btn')) return;

      const row = e.target.closest('tr[data-symbol]');
      if (!row || !row.closest('[data-lcni-stock-filter]')) return;

      const detailBase = String((window.lcniData && window.lcniData.stockDetailUrl) || cfg.stockDetailUrl || '');
      if (!detailBase) return;

      const symbol = encodeURIComponent(row.dataset.symbol || '');
      if (!symbol) return;
      window.location.href = detailBase + '?symbol=' + symbol;
    });
  }

  function boot() {
    bindRowNavigation();
    const defaultColumns = ((cfg.settings || {}).table_columns || []).slice();
    state.visibleColumns = loadVisibleColumns(defaultColumns);
    if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol');

    document.querySelectorAll('[data-lcni-stock-filter]').forEach(async (host) => {
      await loadSavedFilters();
      renderStatic(host);
      bind(host);
      state.filters = collectFilters(host);
      await load(host);
      window.clearInterval(host._lcniRefreshTimer);
      host._lcniRefreshTimer = window.setInterval(() => refreshOnly(host).catch(() => {}), 15000);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

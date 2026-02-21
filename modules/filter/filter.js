(function () {
  const cfg = window.lcniFilterConfig || {};
  const state = { page: 1, limit: 50, filters: [], visibleColumns: [], criteria: Array.isArray(cfg.criteria) ? cfg.criteria : [], total: 0, savedFilters: [], selectedSavedFilterId: 0 };
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const sessionKey = cfg.tableSettingsStorageKey || 'lcni_filter_visible_columns_v1';

  function getButtonConfig(key) { return (cfg.buttonConfig || {})[key] || {}; }
  function renderButtonContent(key, fallbackLabel) {
    const conf = getButtonConfig(key);
    const icon = conf.icon_class ? `<i class="${esc(conf.icon_class)}" aria-hidden="true"></i>` : '';
    const label = `<span>${esc(conf.label_text || fallbackLabel || '')}</span>`;
    return conf.icon_position === 'right' ? `${label}${icon}` : `${icon}${label}`;
  }

  function api(body) {
    return fetch(cfg.restUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(async (r) => {
      const payload = await r.json().catch(() => ({})); if (!r.ok) throw payload; return payload;
    });
  }
  function savedFilterApi(path, options) {
    return fetch((cfg.savedFilterBase || '').replace(/\/$/, '') + path, { method: (options && options.method) || 'GET', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }, credentials: 'same-origin', body: options && options.body ? JSON.stringify(options.body) : undefined }).then(async (r) => {
      const payload = await r.json().catch(() => ({})); if (!r.ok) throw payload; return payload;
    });
  }
  function watchlistApi(path, options) {
    return fetch((cfg.watchlistRestBase || '').replace(/\/$/, '') + path, { method: options.method || 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }, credentials: 'same-origin', body: JSON.stringify(options.body || {}) }).then(async (r) => {
      const payload = await r.json().catch(() => ({})); if (!r.ok) throw payload; return payload;
    });
  }

  function loadVisibleColumns(defaultColumns) { try { const raw = JSON.parse(sessionStorage.getItem(sessionKey) || '[]'); return Array.isArray(raw) && raw.length ? raw : defaultColumns; } catch (e) { return defaultColumns; } }
  function saveVisibleColumns(cols) { try { sessionStorage.setItem(sessionKey, JSON.stringify(cols)); } catch (e) {} }

  function collectFilters(host) {
    return state.criteria.map((item) => {
      if (item.type === 'number') {
        return { column: item.column, operator: 'between', value: [(host.querySelector(`[data-range-min="${item.column}"]`) || {}).value || '', (host.querySelector(`[data-range-max="${item.column}"]`) || {}).value || ''] };
      }
      return { column: item.column, operator: 'in', value: Array.from(host.querySelectorAll(`[data-text-check="${item.column}"]:checked`)).map((n) => n.value) };
    }).filter((f) => Array.isArray(f.value) ? f.value.join('') !== '' : String(f.value || '') !== '');
  }

  async function loadSavedFilters() {
    if (!cfg.isLoggedIn) return;
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

  function renderStatic(host) {
    const settings = cfg.settings || {}; const labels = settings.column_labels || {}; const columns = state.visibleColumns;
    host.innerHTML = `<div class="lcni-filter-toolbar"><button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-filter-toggle>${renderButtonContent('btn_filter_open', 'Filter')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_setting" data-column-toggle-btn>${renderButtonContent('btn_filter_setting', '')}</button><select data-saved-filter-select><option value="">Saved filters</option>${(state.savedFilters || []).map((f) => `<option value="${Number(f.id || 0)}">${esc(f.filter_name || '')}</option>`).join('')}</select><button type="button" class="lcni-btn lcni-btn-btn_save_filter" data-save-current-filter>Lưu bộ lọc</button><button type="button" class="lcni-btn lcni-btn-btn_save_filter" data-delete-current-filter>Xóa bộ lọc</button></div><div class="lcni-filter-panel" data-filter-panel hidden></div><div class="lcni-column-pop" data-column-pop hidden></div><div class="lcni-watchlist-table-wrap lcni-table-wrapper"><table class="lcni-watchlist-table lcni-table"><thead><tr>${columns.map((c, i) => `<th class="${i === 0 ? 'is-sticky-col' : ''}">${esc(labels[c] || c)}</th>`).join('')}</tr></thead><tbody></tbody></table></div><div data-filter-pagination></div>`;
    host.querySelector('[data-filter-panel]').innerHTML = `${state.criteria.map((item) => item.type === 'number' ? `<div><strong>${esc(item.label)}</strong><div><input type="number" data-range-min="${esc(item.column)}" value="${esc(item.min)}"> - <input type="number" data-range-max="${esc(item.column)}" value="${esc(item.max)}"></div></div>` : `<div><strong>${esc(item.label)}</strong><div class="lcni-filter-check-list">${(item.values || []).map((v) => `<label><input type="checkbox" data-text-check="${esc(item.column)}" value="${esc(v)}"> ${esc(v)}</label>`).join('')}</div></div>`).join('')}<button type="button" class="lcni-btn lcni-btn-btn_apply_filter" data-apply-filter>${renderButtonContent('btn_apply_filter', 'Apply Filter')}</button>`;
    const selectable = settings.table_columns || columns;
    host.querySelector('[data-column-pop]').innerHTML = `${selectable.map((c) => `<label><input type="checkbox" data-visible-col value="${esc(c)}" ${state.visibleColumns.includes(c) ? 'checked' : ''}> ${esc(labels[c] || c)}</label>`).join('')}<button type="button" class="lcni-btn lcni-btn-btn_save_filter" data-save-columns>${renderButtonContent('btn_save_filter', 'Save')}</button>`;
  }

  function renderTbody(host, payload) {
    const tbody = host.querySelector('tbody'); if (tbody) tbody.innerHTML = payload.rows || '';
    state.total = Number(payload.total || 0);
    const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
    host.querySelector('[data-filter-pagination]').innerHTML = `<button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-prev ${state.page <= 1 ? 'disabled' : ''}>Prev</button> <span>${state.page}/${totalPages}</span> <button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-next ${state.page >= totalPages ? 'disabled' : ''}>Next</button>`;
  }

  async function load(host) { renderTbody(host, await api({ mode: 'filter', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns })); }
  async function refreshOnly(host) { const tbody = host.querySelector('tbody'); if (!tbody) return; const payload = await api({ mode: 'refresh', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns }); tbody.innerHTML = payload.rows || ''; }

  function bind(host) {
    host.addEventListener('click', async (event) => {
      if (event.target.closest('[data-filter-toggle]')) host.querySelector('[data-filter-panel]').hidden = !host.querySelector('[data-filter-panel]').hidden;
      if (event.target.closest('[data-column-toggle-btn]')) host.querySelector('[data-column-pop]').hidden = !host.querySelector('[data-column-pop]').hidden;
      if (event.target.closest('[data-save-columns]')) { state.visibleColumns = Array.from(host.querySelectorAll('[data-visible-col]:checked')).map((n) => n.value); if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol'); saveVisibleColumns(state.visibleColumns); state.page = 1; renderStatic(host); await load(host); return; }
      if (event.target.closest('[data-save-current-filter]')) { if (!cfg.isLoggedIn) return; const name = window.prompt('Tên bộ lọc'); if (!name) return; state.filters = collectFilters(host); await savedFilterApi('/save', { method: 'POST', body: { filter_name: name, filters: state.filters } }); await loadSavedFilters(); renderStatic(host); await load(host); return; }
      if (event.target.closest('[data-delete-current-filter]')) { if (!cfg.isLoggedIn) return; const select = host.querySelector('[data-saved-filter-select]'); const id = Number(select ? select.value : 0); if (!id) return; await savedFilterApi('/delete', { method: 'POST', body: { id } }); await loadSavedFilters(); renderStatic(host); await load(host); return; }
      if (event.target.closest('[data-apply-filter]')) { state.filters = collectFilters(host); state.page = 1; await load(host); return; }
      if (event.target.closest('[data-prev]')) { state.page = Math.max(1, state.page - 1); await load(host); return; }
      if (event.target.closest('[data-next]')) { state.page += 1; await load(host); return; }
      const addBtn = event.target.closest('[data-lcni-watchlist-add]');
      if (addBtn) {
        if (!cfg.isLoggedIn) return;
        const symbol = addBtn.getAttribute('data-symbol'); const icon = addBtn.querySelector('i'); addBtn.disabled = true; if (icon) icon.className = 'fa-solid fa-spinner fa-spin';
        try { await watchlistApi('/add-symbol', { method: 'POST', body: { symbol } }); addBtn.classList.add('is-active'); if (icon) icon.className = 'fa-solid fa-check'; }
        catch (e) { if (icon) icon.className = 'fa-solid fa-exclamation-circle'; }
        finally { addBtn.disabled = false; }
      }
    });

    host.addEventListener('change', async (event) => {
      const select = event.target.closest('[data-saved-filter-select]');
      if (!select) return;
      const id = Number(select.value || 0);
      if (!id) return;
      const payload = await savedFilterApi('/load?id=' + encodeURIComponent(id), { method: 'GET' });
      applySavedFilterConfig(host, payload.config || {});
      state.filters = collectFilters(host);
      state.page = 1;
      await load(host);
    });
  }

  function boot() {
    const defaultColumns = ((cfg.settings || {}).table_columns || []).slice();
    state.visibleColumns = loadVisibleColumns(defaultColumns); if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol');
    document.querySelectorAll('[data-lcni-stock-filter]').forEach(async (host) => {
      await loadSavedFilters();
      renderStatic(host);
      bind(host);
      await load(host);
      window.clearInterval(host._lcniRefreshTimer);
      host._lcniRefreshTimer = window.setInterval(() => refreshOnly(host).catch(() => {}), 15000);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

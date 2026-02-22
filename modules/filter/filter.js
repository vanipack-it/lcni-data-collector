(function () {
  const cfg = window.lcniFilterConfig || {};
  window.lcniData = window.lcniData || {};
  if (!window.lcniData.stockDetailUrl && cfg.stockDetailUrl) window.lcniData.stockDetailUrl = cfg.stockDetailUrl;

  const state = {
    page: 1,
    limit: 5000,
    filters: [],
    visibleColumns: [],
    criteria: Array.isArray(cfg.criteria) ? cfg.criteria : [],
    total: 0,
    savedFilters: [],
    selectedSavedFilterId: 0,
    lastAppliedTotal: 0,
    sortKey: '',
    sortDir: 'asc',
    dataset: [],
    activeCriteriaColumn: '',
    panelHidden: true,
    columnPanelOpen: false
  };

  const sessionKey = cfg.tableSettingsStorageKey || 'lcni_filter_visible_columns_v1';
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  function getButtonConfig(key) { return (cfg.buttonConfig || {})[key] || {}; }
  function renderButtonContent(key, fallbackLabel, forceLabel) {
    const conf = getButtonConfig(key);
    const icon = conf.icon_class ? `<i class="${esc(conf.icon_class)}" aria-hidden="true"></i>` : '';
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

  function loadVisibleColumns(defaultColumns) {
    try {
      const raw = JSON.parse(sessionStorage.getItem(sessionKey) || '[]');
      return Array.isArray(raw) && raw.length ? raw : defaultColumns;
    } catch (e) { return defaultColumns; }
  }
  function saveVisibleColumns(cols) { try { sessionStorage.setItem(sessionKey, JSON.stringify(cols)); } catch (e) {} }


  function inferFormatType(column) {
    const key = String(column || '').toLowerCase();
    if (key.indexOf('volume') !== -1 || key === 'vol') return 'volume';
    if (key.indexOf('rsi') !== -1) return 'rsi';
    if (key.indexOf('macd') !== -1) return 'macd';
    if (key.indexOf('percent') !== -1 || key.indexOf('_pct') !== -1 || key.indexOf('change') !== -1) return 'percent';
    if (key.indexOf('rs') !== -1) return 'rs';
    if (key === 'pe' || key.indexOf('pe_') === 0) return 'pe';
    if (key === 'pb' || key.indexOf('pb_') === 0) return 'pb';
    return 'price';
  }

  function formatCellValue(column, value) {
    if (value === null || value === undefined || value === '') return '-';
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return String(value);
    if (window.LCNIFormatter && typeof window.LCNIFormatter.format === 'function') {
      return window.LCNIFormatter.format(numeric, inferFormatType(column));
    }
    return String(numeric);
  }

  function renderColumnPositionItems(columns, labels) {
    return columns.map((column) => {
      const checked = state.visibleColumns.includes(column);
      return `<label class="lcni-column-option"><input type="checkbox" data-visible-col value="${esc(column)}" ${checked ? 'checked' : ''}> <span>${esc(labels[column] || column)}</span></label>`;
    }).join('');
  }

  function collectVisibleColumns(host) {
    const selected = Array.from(host.querySelectorAll('[data-visible-col]:checked')).map((node) => node.value);
    if (!selected.includes('symbol')) selected.unshift('symbol');
    return selected;
  }

  function showToast(message) {
    const node = document.createElement('div');
    node.className = 'lcni-filter-toast';
    node.textContent = message;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 2500);
  }

  function closeModal() { const existing = document.querySelector('.lcni-filter-modal-backdrop'); if (existing) existing.remove(); }

  function showModal(html) {
    closeModal();
    const wrap = document.createElement('div');
    wrap.className = 'lcni-filter-modal-backdrop';
    wrap.innerHTML = `<div class="lcni-filter-modal">${html}</div>`;
    wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('[data-modal-close]')) closeModal(); });
    document.body.appendChild(wrap);
  }

  function showAuthModal(message) {
    showModal(`<h3>${esc(message)}</h3><div class="lcni-filter-modal-actions"><a class="lcni-btn lcni-btn-btn_filter_apply" href="${esc(cfg.loginUrl || '#')}">Login</a><a class="lcni-btn lcni-btn-btn_filter_open" href="${esc((cfg.registerUrl || cfg.loginUrl || '#'))}">Register</a><button type="button" class="lcni-btn lcni-btn-btn_popup_close" data-modal-close>Close</button></div>`);
  }

  async function openWatchlistSelector(symbol) {
    if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để thêm vào watchlist');
    const data = await watchlistApi('/list?device=desktop', { method: 'GET' });
    const watchlists = Array.isArray(data.watchlists) ? data.watchlists : [];
    const activeId = Number(data.active_watchlist_id || 0);

    if (!watchlists.length) {
      showModal(`<h3>Tạo watchlist mới</h3><form data-create-watchlist-form><input type="text" name="name" placeholder="Tên watchlist" required><div class="lcni-filter-modal-actions"><button type="submit" class="lcni-btn lcni-btn-btn_popup_confirm">${renderButtonContent('btn_popup_confirm', '+ New')}</button><button type="button" class="lcni-btn lcni-btn-btn_popup_close" data-modal-close>Close</button></div></form>`);
      const form = document.querySelector('[data-create-watchlist-form]');
      if (!form) return;
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = String((form.querySelector('input[name="name"]') || {}).value || '').trim();
        if (!name) return;
        await watchlistApi('/create', { method: 'POST', body: { name } });
        closeModal();
        openWatchlistSelector(symbol).catch(() => {});
      }, { once: true });
      return;
    }

    showModal(`<h3>Chọn watchlist cho ${esc(symbol)}</h3><form data-select-watchlist-form><div class="lcni-filter-watchlist-options">${watchlists.map((w) => `<label><input type="radio" name="watchlist_id" value="${Number(w.id || 0)}" ${(Number(w.id || 0) === activeId) ? 'checked' : ''}> ${esc(w.name || '')}</label>`).join('')}</div><div class="lcni-filter-modal-actions"><button type="submit" class="lcni-btn lcni-btn-btn_popup_confirm">${renderButtonContent('btn_popup_confirm', 'Confirm')}</button><button type="button" class="lcni-btn lcni-btn-btn_popup_close" data-modal-close>Close</button></div></form>`);
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

  function collectFilters(host) {
    return state.criteria.map((item) => {
      if (item.type === 'number') {
        return {
          column: item.column,
          operator: 'between',
          value: [(host.querySelector(`[data-range-min="${item.column}"]`) || {}).value || '', (host.querySelector(`[data-range-max="${item.column}"]`) || {}).value || '']
        };
      }
      return {
        column: item.column,
        operator: 'in',
        value: Array.from(host.querySelectorAll(`[data-text-check="${item.column}"]:checked`)).map((n) => n.value)
      };
    }).filter((f) => Array.isArray(f.value) ? f.value.join('') !== '' : String(f.value || '') !== '');
  }

  function hasSelection(column, host) {
    const criterion = state.criteria.find((item) => item.column === column);
    if (!criterion) return false;
    if (criterion.type === 'number') {
      const min = (host.querySelector(`[data-range-min="${column}"]`) || {}).value || '';
      const max = (host.querySelector(`[data-range-max="${column}"]`) || {}).value || '';
      return String(min).trim() !== '' || String(max).trim() !== '';
    }
    return host.querySelectorAll(`[data-text-check="${column}"]:checked`).length > 0;
  }

  async function loadSavedFilters() {
    if (!cfg.isLoggedIn) {
      state.savedFilters = [];
      state.selectedSavedFilterId = 0;
      return;
    }
    const payload = await savedFilterApi('/list', { method: 'GET' });
    state.savedFilters = Array.isArray(payload.items) ? payload.items : [];
    const defaultId = Number(payload.default_filter_id || 0);
    if (!state.selectedSavedFilterId && defaultId > 0) state.selectedSavedFilterId = defaultId;
  }

  function applySavedFilterConfig(host, config) {
    const filters = Array.isArray((config || {}).filters) ? config.filters : [];
    state.filters = filters;
    state.criteria.forEach((item) => {
      const found = filters.find((f) => f.column === item.column);
      if (item.type === 'number') {
        const range = found && Array.isArray(found.value) ? found.value : ['', ''];
        const min = host.querySelector(`[data-range-min="${item.column}"]`);
        const max = host.querySelector(`[data-range-max="${item.column}"]`);
        if (min) min.value = range[0] || '';
        if (max) max.value = range[1] || '';
      } else {
        const selected = found && Array.isArray(found.value) ? found.value.map(String) : [];
        host.querySelectorAll(`[data-text-check="${item.column}"]`).forEach((i) => { i.checked = selected.includes(String(i.value)); });
      }
    });
    updateCriteriaSelectionState(host);
  }

  function applyDefaultFilterConfig(host) {
    const defaults = cfg.defaultFilterValues || {};
    const defaultSaved = Array.isArray((cfg.settings || {}).default_saved_filters) ? (cfg.settings || {}).default_saved_filters : [];
    if (!state.filters.length && defaultSaved.length) {
      applySavedFilterConfig(host, { filters: defaultSaved });
      return;
    }
    state.criteria.forEach((item) => {
      const value = defaults[item.column];
      if (item.type === 'number' && Array.isArray(value)) {
        const min = host.querySelector(`[data-range-min="${item.column}"]`);
        const max = host.querySelector(`[data-range-max="${item.column}"]`);
        if (min) min.value = value[0] || '';
        if (max) max.value = value[1] || '';
      }
      if (item.type !== 'number' && Array.isArray(value)) {
        const selected = value.map(String);
        host.querySelectorAll(`[data-text-check="${item.column}"]`).forEach((i) => { i.checked = selected.includes(String(i.value)); });
      }
    });
    updateCriteriaSelectionState(host);
  }

  function getApplyLabel() {
    const fallback = (getButtonConfig('btn_filter_apply').label_text || getButtonConfig('btn_apply_filter').label_text || 'Apply Filter');
    return `${fallback} (${Number(state.lastAppliedTotal || 0)})`;
  }

  function criteriaControlHtml(item) {
    if (item.type === 'number') {
      return `<div class="lcni-filter-range-wrap"><input type="number" data-range-min="${esc(item.column)}" value="" placeholder="Min"><input type="number" data-range-max="${esc(item.column)}" value="" placeholder="Max"></div>`;
    }
    return `<div class="lcni-filter-check-list">${(item.values || []).map((v) => `<label><input type="checkbox" data-text-check="${esc(item.column)}" value="${esc(v)}"> ${esc(v)}</label>`).join('')}</div>`;
  }

  function renderCriteriaPanel() {
    const active = state.activeCriteriaColumn || (state.criteria[0] && state.criteria[0].column) || '';
    return `<div class="lcni-filter-criteria-grid"><div class="lcni-filter-criteria-tabs">${state.criteria.map((item) => `<button type="button" class="lcni-filter-tab ${item.column === active ? 'is-active' : ''}" data-criteria-tab="${esc(item.column)}"><span class="lcni-filter-tab-check" data-criteria-check="${esc(item.column)}">\u2713</span><span>${esc(item.label)}</span></button>`).join('')}</div><div class="lcni-filter-criteria-values">${state.criteria.map((item) => `<div class="lcni-filter-value-panel ${item.column === active ? 'is-active' : ''}" data-criteria-content="${esc(item.column)}"><strong>${esc(item.label)}</strong>${criteriaControlHtml(item)}</div>`).join('')}</div></div>`;
  }

  function renderStatic(host) {
    const settings = cfg.settings || {};
    const style = settings.style || {};
    const labels = settings.column_labels || {};
    const columns = state.visibleColumns;
    const selectedId = Number(state.selectedSavedFilterId || 0);

    host.style.setProperty('--lcni-panel-label-size', `${Number(style.panel_label_font_size || 13)}px`);
    host.style.setProperty('--lcni-panel-value-size', `${Number(style.panel_value_font_size || 13)}px`);
    host.style.setProperty('--lcni-panel-label-color', String(style.panel_label_color || '#111827'));
    host.style.setProperty('--lcni-panel-value-color', String(style.panel_value_color || '#374151'));
    host.style.setProperty('--lcni-table-header-size', `${Number(style.table_header_font_size || 12)}px`);
    host.style.setProperty('--lcni-table-header-color', String(style.table_header_text_color || '#111827'));
    host.style.setProperty('--lcni-table-header-bg', String(style.table_header_background || '#f3f4f6'));
    host.style.setProperty('--lcni-table-value-size', `${Number(style.table_value_font_size || 13)}px`);
    host.style.setProperty('--lcni-table-value-color', String(style.table_value_text_color || '#111827'));
    host.style.setProperty('--lcni-table-value-bg', String(style.table_value_background || '#ffffff'));
    host.style.setProperty('--lcni-row-divider-color', String(style.table_row_divider_color || '#e5e7eb'));
    host.style.setProperty('--lcni-row-divider-width', `${Number(style.table_row_divider_width || 1)}px`);
    host.style.setProperty('--lcni-row-hover-bg', String(style.row_hover_background || '#eef2ff'));
    host.style.setProperty('--lcni-table-header-height', `${Number(style.table_header_row_height || 42)}px`);
    host.style.setProperty('--lcni-table-scroll-speed', String(Number(style.table_scroll_speed || 1)));
    host.classList.toggle('lcni-disable-sticky-header', Number(style.sticky_header_rows || 1) < 1);

    const hideBtn = style.enable_hide_button ? `<button type="button" class="lcni-btn lcni-btn-btn_filter_hide lcni-filter-hide-btn" data-filter-hide>${renderButtonContent('btn_filter_hide', 'Ẩn')}</button>` : '';
    const savedFilterLabel = esc(style.saved_filter_label || 'Saved filters');

    host.innerHTML = `<div class="lcni-filter-toolbar"><button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-filter-toggle>${renderButtonContent('btn_filter_open', 'Filter')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_setting" data-column-toggle-btn>${renderButtonContent('btn_filter_setting', '')}</button></div><div class="lcni-filter-panel ${state.panelHidden ? 'is-collapsed' : ''}" data-filter-panel><div class="lcni-filter-panel-body">${renderCriteriaPanel()}</div><div class="lcni-filter-panel-actions"><label class="lcni-saved-filter-label">${savedFilterLabel}</label><select data-saved-filter-select><option value="">${savedFilterLabel}</option>${(state.savedFilters || []).map((f) => `<option value="${Number(f.id || 0)}" ${Number(f.id || 0) === selectedId ? 'selected' : ''}>${esc(f.filter_name || '')}</option>`).join('')}</select><button type="button" class="lcni-btn lcni-btn-btn_filter_reload" data-reload-filter>${renderButtonContent('btn_filter_reload', 'Reload')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_save" data-save-current-filter>${renderButtonContent('btn_filter_save', 'Save')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_delete" data-delete-current-filter>${renderButtonContent('btn_filter_delete', 'Delete')}</button><button type="button" class="lcni-btn lcni-btn-btn_set_default_filter" data-set-default-filter>${renderButtonContent('btn_set_default_filter', 'Set Default')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_apply" data-apply-filter>${renderButtonContent('btn_filter_apply', 'Apply Filter', getApplyLabel())}</button>${hideBtn}</div></div><div class="lcni-column-pop" data-column-pop ${state.columnPanelOpen ? '' : 'hidden'}></div><div class="lcni-table-scroll"><table class="lcni-table"><thead><tr>${columns.map((c, idx) => `<th data-sort-key="${esc(c)}" class="${idx < Number(style.sticky_column_count || 1) ? 'is-sticky-col' : ''}">${esc(labels[c] || c)} <span data-sort-icon>${state.sortKey === c ? (state.sortDir === 'asc' ? '↑' : '↓') : ''}</span></th>`).join('')}</tr></thead><tbody></tbody></table></div>`;

    const selectable = settings.table_columns || columns;
    host.querySelector('[data-column-pop]').innerHTML = `${renderColumnPositionItems(selectable, labels)}<button type="button" class="lcni-btn lcni-btn-btn_save_filter" data-save-columns>${renderButtonContent('btn_save_filter', 'Save')}</button>`;

    bindHorizontalScrollLock(host);
    applyDefaultFilterConfig(host);
    if (state.filters.length) applySavedFilterConfig(host, { filters: state.filters });
  }

  function updateCriteriaSelectionState(host) {
    host.querySelectorAll('[data-criteria-check]').forEach((node) => {
      const col = node.getAttribute('data-criteria-check') || '';
      node.classList.toggle('is-visible', hasSelection(col, host));
    });
  }

  function updateApplyButtonLabel(host) {
    const btn = host.querySelector('[data-apply-filter]');
    if (btn) btn.innerHTML = renderButtonContent('btn_filter_apply', 'Apply Filter', getApplyLabel());
  }

  function sortDataset(items) {
    if (!state.sortKey) return items;
    const dir = state.sortDir === 'desc' ? -1 : 1;
    return [...items].sort((a, b) => {
      const av = a[state.sortKey];
      const bv = b[state.sortKey];
      const an = Number(av);
      const bn = Number(bv);
      if (Number.isFinite(an) && Number.isFinite(bn)) return (an - bn) * dir;
      return String(av || '').localeCompare(String(bv || '')) * dir;
    });
  }

  function renderTbody(host, payload) {
    const tbody = host.querySelector('tbody');
    state.dataset = Array.isArray(payload.items) ? payload.items : state.dataset;
    if (tbody) {
      if (payload.rows && !state.sortKey) {
        tbody.innerHTML = payload.rows;
      } else {
        const style = ((cfg.settings || {}).style || {});
        const stickyCount = Number(style.sticky_column_count || 1);
        const columns = state.visibleColumns.length ? state.visibleColumns : ((cfg.settings && cfg.settings.table_columns) || []);
        const sorted = sortDataset(state.dataset);
        tbody.innerHTML = sorted.map((row) => `<tr data-symbol="${esc(row.symbol || '')}">${columns.map((column, idx) => `<td class="${idx < stickyCount ? 'is-sticky-col' : ''}">${esc(column === 'symbol' ? row[column] : formatCellValue(column, row[column]))}</td>`).join('')}</tr>`).join('');
      }
    }
    state.total = Number(payload.total || state.total || 0);
    state.lastAppliedTotal = state.total;
    updateApplyButtonLabel(host);
  }

  async function load(host) { renderTbody(host, await api({ mode: 'filter', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns }) || {}); }
  async function refreshOnly(host) {
    const tbody = host.querySelector('tbody');
    if (!tbody) return;
    const payload = await api({ mode: 'refresh', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns });
    tbody.innerHTML = payload.rows || '';
  }


  function bindHorizontalScrollLock(host) {
    const wrap = host.querySelector('.lcni-table-scroll');
    if (!wrap || wrap.dataset.scrollLockBound === '1') return;
    wrap.dataset.scrollLockBound = '1';
    wrap.addEventListener('wheel', (event) => {
      const speed = Number((cfg.settings && cfg.settings.style && cfg.settings.style.table_scroll_speed) || host.style.getPropertyValue('--lcni-table-scroll-speed') || 1) || 1;
      const deltaX = (event.deltaX || 0) + ((event.shiftKey ? event.deltaY : 0) * speed);
      if (!deltaX) return;
      const maxLeft = wrap.scrollWidth - wrap.clientWidth;
      const nextLeft = wrap.scrollLeft + deltaX;
      if (maxLeft > 0 && nextLeft >= 0 && nextLeft <= maxLeft) {
        wrap.scrollLeft = nextLeft;
        event.preventDefault();
      }
    }, { passive: false });
  }

  function bind(host) {
    host.addEventListener('click', async (event) => {
      if (event.target.closest('[data-filter-hide]')) {
        state.panelHidden = true;
        const panel = host.querySelector('[data-filter-panel]');
        if (panel) panel.classList.add('is-collapsed');
        return;
      }
      if (event.target.closest('[data-filter-toggle]')) {
        state.panelHidden = !state.panelHidden;
        const panel = host.querySelector('[data-filter-panel]');
        if (panel) panel.classList.toggle('is-collapsed', state.panelHidden);
        return;
      }
      if (event.target.closest('[data-criteria-tab]')) {
        state.activeCriteriaColumn = event.target.closest('[data-criteria-tab]').getAttribute('data-criteria-tab') || '';
        host.querySelectorAll('[data-criteria-tab]').forEach((btn) => btn.classList.toggle('is-active', btn.getAttribute('data-criteria-tab') === state.activeCriteriaColumn));
        host.querySelectorAll('[data-criteria-content]').forEach((pane) => pane.classList.toggle('is-active', pane.getAttribute('data-criteria-content') === state.activeCriteriaColumn));
        return;
      }
      if (event.target.closest('[data-column-toggle-btn]')) {
        state.columnPanelOpen = !state.columnPanelOpen;
        const panel = host.querySelector('[data-column-pop]');
        if (panel) panel.hidden = !state.columnPanelOpen;
        return;
      }
      if (event.target.closest('[data-save-columns]')) {
        state.visibleColumns = collectVisibleColumns(host);
        saveVisibleColumns(state.visibleColumns);
        state.columnPanelOpen = false;
        state.page = 1;
        renderStatic(host);
        await load(host);
        return;
      }
      if (event.target.closest('[data-save-current-filter]')) {
        if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
        const name = window.prompt('Tên bộ lọc');
        if (!name) return;
        state.filters = collectFilters(host);
      if (!state.filters.length && Array.isArray((cfg.settings || {}).default_saved_filters)) state.filters = (cfg.settings || {}).default_saved_filters;
        await savedFilterApi('/save', { method: 'POST', body: { filter_name: name, filters: state.filters } });
        await loadSavedFilters();
        renderStatic(host);
        await load(host);
        return;
      }
      if (event.target.closest('[data-delete-current-filter]')) {
        if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
        const id = Number(state.selectedSavedFilterId || 0);
        if (!id) return;
        await savedFilterApi('/delete', { method: 'POST', body: { id } });
        state.selectedSavedFilterId = 0;
        await loadSavedFilters();
        renderStatic(host);
        return;
      }
      if (event.target.closest('[data-reload-filter]')) {
        if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
        const id = Number(state.selectedSavedFilterId || 0);
        if (!id) return;
        const payload = await savedFilterApi('/load?id=' + encodeURIComponent(id), { method: 'GET' });
        applySavedFilterConfig(host, payload.config || {});
        return;
      }
      if (event.target.closest('[data-set-default-filter]')) {
        if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
        const id = Number(state.selectedSavedFilterId || 0);
        await savedFilterApi('/default', { method: 'POST', body: { id } });
        showToast(id > 0 ? 'Đã đặt bộ lọc mặc định' : 'Đã bỏ bộ lọc mặc định');
        return;
      }
      if (event.target.closest('[data-apply-filter]')) {
        state.filters = collectFilters(host);
      if (!state.filters.length && Array.isArray((cfg.settings || {}).default_saved_filters)) state.filters = (cfg.settings || {}).default_saved_filters;
        state.page = 1;
        state.columnPanelOpen = false;
        await load(host);
        return;
      }

      const sortTh = event.target.closest('th[data-sort-key]');
      if (sortTh) {
        const key = sortTh.getAttribute('data-sort-key');
        if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; else { state.sortKey = key; state.sortDir = 'asc'; }
        renderStatic(host);
        renderTbody(host, { items: state.dataset, total: state.total });
        return;
      }
      const addBtn = event.target.closest('[data-lcni-watchlist-add]');
      if (addBtn) {
        const symbol = String(addBtn.getAttribute('data-symbol') || '').trim().toUpperCase();
        if (!symbol) return;
        try { await openWatchlistSelector(symbol); addBtn.classList.add('is-active'); } catch (e) { showToast((e && e.message) || 'Không thể cập nhật watchlist'); }
      }
    });

    host.addEventListener('change', async (event) => {
      const select = event.target.closest('[data-saved-filter-select]');
      if (select) {
        state.selectedSavedFilterId = Number(select.value || 0);
        if (state.selectedSavedFilterId > 0) {
          const payload = await savedFilterApi('/load?id=' + encodeURIComponent(state.selectedSavedFilterId), { method: 'GET' });
          applySavedFilterConfig(host, payload.config || {});
          state.filters = collectFilters(host);
      if (!state.filters.length && Array.isArray((cfg.settings || {}).default_saved_filters)) state.filters = (cfg.settings || {}).default_saved_filters;
          state.page = 1;
          await load(host);
        }
      }
      if (event.target.matches('[data-visible-col]')) state.columnPanelOpen = true;
      if (event.target.matches('input[type="checkbox"]')) updateCriteriaSelectionState(host);
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
      const symbol = encodeURIComponent(row.dataset.symbol || '');
      if (detailBase && symbol) window.location.href = detailBase + '?symbol=' + symbol;
    });
  }

  function boot() {
    bindRowNavigation();
    const defaultColumns = ((cfg.settings || {}).table_columns || []).slice();
    state.visibleColumns = loadVisibleColumns(defaultColumns);
    if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol');
    state.activeCriteriaColumn = (state.criteria[0] && state.criteria[0].column) || '';

    document.querySelectorAll('[data-lcni-stock-filter]').forEach(async (host) => {
      await loadSavedFilters();
      renderStatic(host);
      bind(host);
      updateCriteriaSelectionState(host);
      state.filters = collectFilters(host);
      if (!state.filters.length && Array.isArray((cfg.settings || {}).default_saved_filters)) state.filters = (cfg.settings || {}).default_saved_filters;
      await load(host);
      window.clearInterval(host._lcniRefreshTimer);
      host._lcniRefreshTimer = window.setInterval(() => refreshOnly(host).catch(() => {}), 15000);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

(function () {
  const cfg = window.lcniFilterConfig || {};
  window.lcniData = window.lcniData || {};
  if (!window.lcniData.stockDetailUrl && cfg.stockDetailUrl) window.lcniData.stockDetailUrl = cfg.stockDetailUrl;

  function normalizeConfiguredCriteriaOrder() {
    const order = Array.isArray(cfg.filterCriteriaColumns) ? cfg.filterCriteriaColumns.map((item) => String(item || '').trim()).filter(Boolean) : [];
    if (!order.length || !Array.isArray(cfg.criteria)) return;
    const rank = new Map(order.map((column, index) => [column, index]));
    cfg.criteria = [...cfg.criteria].sort((a, b) => {
      const aRank = rank.has(a.column) ? rank.get(a.column) : Number.MAX_SAFE_INTEGER;
      const bRank = rank.has(b.column) ? rank.get(b.column) : Number.MAX_SAFE_INTEGER;
      return aRank - bRank;
    });
  }

  normalizeConfiguredCriteriaOrder();

  const state = {
    page: 1,
    limit: 5000,
    filters: [],
    visibleColumns: [],
    criteria: Array.isArray(cfg.criteria) ? cfg.criteria : [],
    total: 0,
    savedFilters: [],
    selectedSavedFilterId: 0,
    adminTemplates: [],
    selectedTemplateId: 0,
    lastAppliedTotal: 0,
    sortKey: '',
    sortDir: 'asc',
    dataset: [],
    activeCriteriaColumn: '',
    panelHidden: true,
    columnPanelOpen: false,
    tableLoaded: false,
    countRequestId: 0,
    isApplying: false,
    applyStartedAt: 0,
    hoverRequestId: 0,
    hoverCellKey: ""
  };

  function isMobileViewport() {
    return window.matchMedia('(max-width: 767px)').matches;
  }

  const sessionKey = cfg.tableSettingsStorageKey || 'lcni_filter_visible_columns_v1';
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));


  function buildFilterUrl(field, value) {
    const base = String(cfg.filterPageUrl || '').trim();
    const safeField = String(field || '').trim();
    const safeValue = String(value == null ? '' : value).trim();
    if (!base || !safeField || !safeValue) return '';
    const criteria = Array.isArray(cfg.filterCriteriaColumns) ? cfg.filterCriteriaColumns.map((item) => String(item || '').trim()) : [];
    if (criteria.length && !criteria.includes(safeField)) return '';
    const url = new URL(base, window.location.origin);
    url.searchParams.set('apply_filter', '1');
    url.searchParams.set(safeField, safeValue);
    return url.toString();
  }


  function parseCellValueForCriteria(rawValue, criterion) {
    const value = String(rawValue == null ? '' : rawValue).trim();
    if (!value) return null;
    if ((criterion || {}).type === 'number') {
      const numeric = Number(value.replace(/,/g, ''));
      if (!Number.isFinite(numeric)) return null;
      return { operator: '=', value: [numeric, numeric] };
    }
    return { operator: 'in', value: [value] };
  }

  function findCriteriaByColumn(column) {
    return state.criteria.find((item) => item && item.column === column) || null;
  }

  function getButtonConfig(key) { return (cfg.buttonConfig || {})[key] || {}; }
  function renderButtonContent(key, fallbackLabel, forceLabel) {
    const conf = getButtonConfig(key);
    const icon = conf.icon_class ? `<i class="${esc(conf.icon_class)}" aria-hidden="true"></i>` : '';
    const text = typeof forceLabel === 'string' ? forceLabel : (conf.label_text || fallbackLabel || '');
    const label = `<span>${esc(text)}</span>`;
    return conf.icon_position === 'right' ? `${label}${icon}` : `${icon}${label}`;
  }

  function api(body) {
    const payloadBody = Object.assign({ skip_defaults: true }, body || {});
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), 20000);
    return fetch(cfg.restUrl, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }, credentials: 'same-origin', body: JSON.stringify(payloadBody), signal: controller.signal
    }).then(async (r) => {
      const payload = await r.json().catch(() => ({}));
      if (!r.ok) throw payload;
      return payload;
    }).finally(() => {
      window.clearTimeout(timeoutId);
    });
  }

  function buildExportRows(columns) {
    const sortedRows = sortDataset(state.dataset || []);
    if (sortedRows.length) return sortedRows;

    const tableRows = Array.from(document.querySelectorAll('.lcni-filter-module tbody tr[data-symbol]'));
    return tableRows.map((tr) => {
      const row = {};
      const cells = Array.from(tr.querySelectorAll('td'));
      columns.forEach((column, index) => {
        const cell = cells[index];
        if (!cell) {
          row[column] = '';
          return;
        }
        const value = cell.getAttribute('data-cell-value');
        row[column] = value !== null ? value : (cell.textContent || '').trim();
      });
      return row;
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



  function isNumericValue(value) {
    if (value === null || value === undefined || value === '') return false;
    return Number.isFinite(Number(value));
  }

  function resolveOperatorMatch(rawValue, operator, expected) {
    const numericRaw = Number(rawValue);
    const numericExpected = Number(expected);
    const bothNumeric = Number.isFinite(numericRaw) && Number.isFinite(numericExpected);
    const left = bothNumeric ? numericRaw : String(rawValue);
    const right = bothNumeric ? numericExpected : String(expected);
    if (operator === '>') return left > right;
    if (operator === '>=') return left >= right;
    if (operator === '<') return left < right;
    if (operator === '<=') return left <= right;
    if (operator === '=') return left === right;
    if (operator === '!=') return left !== right;
    if (operator === 'contains') return String(rawValue).toLowerCase().includes(String(expected).toLowerCase());
    if (operator === 'not_contains') return !String(rawValue).toLowerCase().includes(String(expected).toLowerCase());
    return false;
  }

  function resolveCellToCellMeta(column, row) {
    const rules = Array.isArray((cfg.settings || {}).cell_to_cell_rules) ? (cfg.settings || {}).cell_to_cell_rules : [];
    for (let i = 0; i < rules.length; i += 1) {
      const rule = rules[i] || {};
      if (rule.target_field !== column) continue;
      if (!resolveOperatorMatch(row[rule.source_field], rule.operator, rule.value)) continue;
      return rule;
    }
    return null;
  }



  function resolveValueColorRule(column, value) {
    const rules = Array.isArray((cfg.settings || {}).value_color_rules) ? (cfg.settings || {}).value_color_rules : [];
    for (let i = 0; i < rules.length; i += 1) {
      const rule = rules[i] || {};
      if (rule.column !== column) continue;
      if (!resolveOperatorMatch(value, rule.operator, rule.value)) continue;
      return rule;
    }
    return null;
  }

  function formatCellValue(column, value) {
    if (value === null || value === undefined || value === '') return '-';
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return String(value);
    if (window.LCNIFormatter) {
      const canApply = typeof window.LCNIFormatter.shouldApply !== 'function' || window.LCNIFormatter.shouldApply('screener');
      if (!canApply) return String(numeric);
      if (typeof window.LCNIFormatter.formatByField === 'function') {
        return window.LCNIFormatter.formatByField(numeric, column);
      }
      if (typeof window.LCNIFormatter.formatByColumn === 'function') {
        return window.LCNIFormatter.formatByColumn(numeric, column);
      }
      if (typeof window.LCNIFormatter.format === 'function') {
        return window.LCNIFormatter.format(numeric, 'price');
      }
    }
    return String(numeric);
  }

  function formatRangeLabel(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return String(value == null ? '' : value);
    if (Math.abs(numeric % 1) < 1e-8) return String(Math.trunc(numeric));
    return numeric.toLocaleString('vi-VN', { maximumFractionDigits: 4 });
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
    showModal(`<h3>${esc(message)}</h3><div class="lcni-filter-modal-actions"><a class="lcni-btn lcni-btn-btn_filter_watchlist_login" href="${esc(cfg.loginUrl || '#')}">${renderButtonContent('btn_filter_watchlist_login', 'Login')}</a><a class="lcni-btn lcni-btn-btn_filter_watchlist_register" href="${esc((cfg.registerUrl || cfg.loginUrl || '#'))}">${renderButtonContent('btn_filter_watchlist_register', 'Register')}</a><button type="button" class="lcni-btn lcni-btn-btn_filter_watchlist_close" data-modal-close>${renderButtonContent('btn_filter_watchlist_close', 'Close')}</button></div>`);
  }

  function normalizeFilterName(raw) {
    const source = String(raw || '').trim();
    const normalized = source
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
    return normalized || 'ket-qua';
  }

  function getCurrentFilterName() {
    const selectedSaved = (state.savedFilters || []).find((item) => Number(item.id || 0) === Number(state.selectedSavedFilterId || 0));
    if (selectedSaved && selectedSaved.filter_name) return String(selectedSaved.filter_name);
    const selectedTemplate = (state.adminTemplates || []).find((item) => Number(item.id || 0) === Number(state.selectedTemplateId || 0));
    if (selectedTemplate && selectedTemplate.filter_name) return String(selectedTemplate.filter_name);
    return 'ket-qua-loc';
  }

  function formatExportDateValue(column, value) {
    const raw = value == null ? '' : String(value).trim();
    if (!raw) return '';
    const field = String(column || '').toLowerCase();
    const isDateField = /(^|_)(date|ngay|event_time|trading_date)(_|$)/.test(field);
    if (!isDateField) return raw;

    const toYmd = (dateObj) => {
      if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) return raw;
      const year = dateObj.getFullYear();
      const month = String(dateObj.getMonth() + 1).padStart(2, '0');
      const day = String(dateObj.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    };

    if (/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.slice(0, 10);

    const dmy = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (dmy) return `${dmy[3]}-${String(dmy[2]).padStart(2, '0')}-${String(dmy[1]).padStart(2, '0')}`;

    const ymdSlash = raw.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (ymdSlash) return `${ymdSlash[1]}-${String(ymdSlash[2]).padStart(2, '0')}-${String(ymdSlash[3]).padStart(2, '0')}`;

    if (/^\d{10,13}$/.test(raw)) {
      const numeric = Number(raw);
      if (Number.isFinite(numeric)) {
        const epoch = raw.length === 13 ? numeric : (numeric * 1000);
        return toYmd(new Date(epoch));
      }
    }

    return toYmd(new Date(raw));
  }

  function exportCurrentResultToExcel() {
    if (!state.tableLoaded) {
      showToast('Chưa có dữ liệu để xuất. Vui lòng bấm Apply Filter trước.');
      return;
    }

    const columns = state.visibleColumns.length ? state.visibleColumns : (((cfg.settings || {}).table_columns) || []);
    if (!columns.length) {
      showToast('Không có cột dữ liệu để xuất.');
      return;
    }

    const labels = (cfg.settings || {}).column_labels || {};
    const sortedRows = buildExportRows(columns);
    if (!sortedRows.length) {
      showToast('Không có dữ liệu hợp lệ để xuất.');
      return;
    }
    const header = columns.map((column) => `"${String(labels[column] || column).replace(/"/g, '""')}"`).join(',');
    const rows = sortedRows.map((row) => columns.map((column) => {
      const value = formatExportDateValue(column, row[column]);
      return `"${String(value).replace(/"/g, '""')}"`;
    }).join(','));

    const csvContent = '\uFEFF' + [header].concat(rows).join('\r\n');
    const blob = new Blob([csvContent], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const fileName = `LCNi_Filter_${normalizeFilterName(getCurrentFilterName())}.csv`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
    showToast('Đã xuất file: ' + fileName);
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
      try {
        const payload = await watchlistApi('/add-symbol', { method: 'POST', body: { symbol, watchlist_id: watchlistId } });
        const name = String((payload && (payload.watchlist_name || payload.name)) || (selected && selected.parentElement ? selected.parentElement.textContent : '') || '').trim();
        closeModal();
        showToast('Đã thêm mã ' + symbol + ' thành công vào watchlist: ' + name + '.');
      } catch (error) {
        if (error && error.code === 'duplicate_symbol') {
          const info = error && error.data ? error.data : {};
          const inName = String(info.watchlist_name || '').trim();
          showToast('Mã ' + symbol + ' đã có trong watchlist: ' + inName + '.');
          return;
        }
        showToast((error && error.message) || 'Không thể thêm vào watchlist');
      }
    }, { once: true });
  }


  async function openBulkWatchlistSelector(symbols) {
    if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để thêm vào watchlist');

    const normalizedSymbols = Array.isArray(symbols)
      ? Array.from(new Set(symbols.map((symbol) => String(symbol || '').trim().toUpperCase()).filter(Boolean)))
      : [];

    if (!normalizedSymbols.length) {
      showToast('Không có cổ phiếu để thêm vào watchlist.');
      return;
    }

    const data = await watchlistApi('/list?device=desktop', { method: 'GET' });
    const watchlists = Array.isArray(data.watchlists) ? data.watchlists : [];
    const activeId = Number(data.active_watchlist_id || 0);

    showModal(`<h3>Thêm ${normalizedSymbols.length} mã vào Watchlist</h3><form data-bulk-watchlist-form><div class="lcni-filter-watchlist-options">${watchlists.map((w) => `<label><input type="radio" name="watchlist_id" value="${Number(w.id || 0)}" ${(Number(w.id || 0) === activeId) ? 'checked' : ''}> ${esc(w.name || '')}</label>`).join('')}</div><div class="lcni-filter-watchlist-create"><input type="text" name="new_watchlist_name" placeholder="Hoặc nhập tên Watchlist mới"></div><div class="lcni-filter-modal-actions"><button type="submit" class="lcni-btn lcni-btn-btn_filter_add_watchlist_bulk">${renderButtonContent('btn_filter_add_watchlist_bulk', 'Thêm vào Watchlist')}</button><button type="button" class="lcni-btn lcni-btn-btn_popup_close" data-modal-close>Close</button></div></form>`);

    const form = document.querySelector('[data-bulk-watchlist-form]');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const newName = String((form.querySelector('input[name="new_watchlist_name"]') || {}).value || '').trim();
      let watchlistId = Number((form.querySelector('input[name="watchlist_id"]:checked') || {}).value || 0);

      try {
        if (newName) {
          const created = await watchlistApi('/create', { method: 'POST', body: { name: newName } });
          watchlistId = Number(created.id || 0);
        }
        if (!watchlistId) {
          showToast('Vui lòng chọn watchlist hoặc nhập tên watchlist mới.');
          return;
        }

        const result = await watchlistApi('/add-symbols', { method: 'POST', body: { watchlist_id: watchlistId, symbols: normalizedSymbols } });
        const watchlistName = String((result && result.watchlist_name) || newName || '').trim();
        closeModal();
        showToast(`Đã thêm ${Number((result && result.added_count) || 0)}/${normalizedSymbols.length} mã vào watchlist${watchlistName ? ': ' + watchlistName : ''}.`);
      } catch (error) {
        showToast((error && error.message) || 'Không thể thêm danh sách vào watchlist');
      }
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
    const payload = await savedFilterApi('/list', { method: 'GET' });
    state.savedFilters = cfg.isLoggedIn && Array.isArray(payload.items) ? payload.items : [];
    state.adminTemplates = Array.isArray(payload.admin_templates) ? payload.admin_templates : [];

    const defaultId = Number(payload.default_filter_id || 0);
    const lastViewedId = Number(payload.last_viewed_filter_id || 0);
    const defaultTemplateId = Number(payload.default_admin_template_id || 0);

    if (cfg.isLoggedIn && !state.selectedSavedFilterId) {
      if (defaultId > 0) state.selectedSavedFilterId = defaultId;
      else if (lastViewedId > 0) state.selectedSavedFilterId = lastViewedId;
    }
    if (!state.selectedTemplateId && defaultTemplateId > 0) {
      state.selectedTemplateId = defaultTemplateId;
    }
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
    syncAllNumberRanges(host, 'input');
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
    syncAllNumberRanges(host, 'input');
    updateCriteriaSelectionState(host);
  }

  function getApplyLabel() {
    const fallback = (getButtonConfig('btn_filter_apply').label_text || getButtonConfig('btn_apply_filter').label_text || 'Apply Filter');
    return `${fallback} (${Number(state.lastAppliedTotal || 0)})`;
  }

  function describeActiveFilters(host) {
    const activeFilters = collectFilters(host);
    if (!activeFilters.length) {
      return 'Chưa có tiêu chí lọc nào được chọn, hãy mở Bộ lọc và chọn điều kiện sau lọc đó Chạy bộ lọc.';
    }

    const parts = activeFilters.map((filter) => {
      const criterion = state.criteria.find((item) => item.column === filter.column) || {};
      const label = criterion.label || filter.column;
      if (Array.isArray(filter.value)) {
        if (filter.operator === 'between') {
          const from = String(filter.value[0] || '').trim() || '-∞';
          const to = String(filter.value[1] || '').trim() || '+∞';
          return `${label}=${from}..${to}`;
        }
        return `${label}=${filter.value.map((value) => String(value || '').trim()).filter(Boolean).join(', ')}`;
      }
      return `${label}=${String(filter.value || '').trim()}`;
    }).filter(Boolean);

    return `Bộ lọc có ${Number(state.total || 0)} cổ phiếu thỏa mãn các tiêu chí ${parts.join(' + ')}`;
  }

  function renderFilterSummary(host) {
    const summary = describeActiveFilters(host);
    host.querySelectorAll('[data-filter-result-summary]').forEach((node) => {
      node.textContent = summary;
    });
  }

  function criteriaControlHtml(item) {
    if (item.type === 'number') {
      const min = Number(item.min || 0);
      const max = Number(item.max || 0);
      const safeMin = Number.isFinite(min) ? min : 0;
      const safeMax = Number.isFinite(max) && max >= safeMin ? max : safeMin;
      return `<div class="lcni-filter-range-wrap lcni-filter-range-dual" data-range-wrap="${esc(item.column)}" data-range-bound-min="${esc(safeMin)}" data-range-bound-max="${esc(safeMax)}"><div class="lcni-filter-range-track"><div class="lcni-filter-range-fill" data-range-fill="${esc(item.column)}"></div><input type="range" data-range-slider-min="${esc(item.column)}" min="${esc(safeMin)}" max="${esc(safeMax)}" step="any" value="${esc(safeMin)}"><input type="range" data-range-slider-max="${esc(item.column)}" min="${esc(safeMin)}" max="${esc(safeMax)}" step="any" value="${esc(safeMax)}"></div><div class="lcni-filter-range-values"><span data-range-min-label="${esc(item.column)}">${esc(formatRangeLabel(safeMin))}</span><span data-range-max-label="${esc(item.column)}">${esc(formatRangeLabel(safeMax))}</span></div><input type="hidden" data-range-min="${esc(item.column)}" value=""><input type="hidden" data-range-max="${esc(item.column)}" value=""></div>`;
    }
    return `<div class="lcni-filter-check-list">${(item.values || []).map((v) => `<label><input type="checkbox" data-text-check="${esc(item.column)}" value="${esc(v)}"> ${esc(v)}</label>`).join('')}</div>`;
  }

  function syncNumberRange(host, column, source) {
    const wrap = host.querySelector(`[data-range-wrap="${column}"]`);
    if (!wrap) return;
    const minBound = Number(wrap.getAttribute('data-range-bound-min') || 0);
    const maxBound = Number(wrap.getAttribute('data-range-bound-max') || minBound);
    const sliderMin = host.querySelector(`[data-range-slider-min="${column}"]`);
    const sliderMax = host.querySelector(`[data-range-slider-max="${column}"]`);
    const inputMin = host.querySelector(`[data-range-min="${column}"]`);
    const inputMax = host.querySelector(`[data-range-max="${column}"]`);
    const labelMin = host.querySelector(`[data-range-min-label="${column}"]`);
    const labelMax = host.querySelector(`[data-range-max-label="${column}"]`);
    const fill = host.querySelector(`[data-range-fill="${column}"]`);
    if (!sliderMin || !sliderMax || !inputMin || !inputMax) return;

    let valueMin = Number(sliderMin.value || minBound);
    let valueMax = Number(sliderMax.value || maxBound);

    if (source === 'input') {
      const nextMin = Number(inputMin.value);
      const nextMax = Number(inputMax.value);
      valueMin = Number.isFinite(nextMin) ? nextMin : minBound;
      valueMax = Number.isFinite(nextMax) ? nextMax : maxBound;
    }

    valueMin = Math.max(minBound, Math.min(valueMin, maxBound));
    valueMax = Math.max(minBound, Math.min(valueMax, maxBound));
    if (source === 'min' && valueMin > valueMax) {
      valueMax = valueMin;
    }
    if (source === 'max' && valueMax < valueMin) {
      valueMin = valueMax;
    }

    sliderMin.value = String(valueMin);
    sliderMax.value = String(valueMax);

    inputMin.value = valueMin <= minBound ? '' : String(valueMin);
    inputMax.value = valueMax >= maxBound ? '' : String(valueMax);

    if (labelMin) labelMin.textContent = formatRangeLabel(valueMin);
    if (labelMax) labelMax.textContent = formatRangeLabel(valueMax);

    if (fill) {
      if (maxBound > minBound) {
        const left = ((valueMin - minBound) / (maxBound - minBound)) * 100;
        const right = ((valueMax - minBound) / (maxBound - minBound)) * 100;
        fill.style.left = `${Math.max(0, Math.min(100, left))}%`;
        fill.style.width = `${Math.max(0, Math.min(100, right) - Math.max(0, Math.min(100, left)))}%`;
      } else {
        fill.style.left = '0%';
        fill.style.width = '100%';
      }
    }
  }

  function syncAllNumberRanges(host, source) {
    state.criteria.filter((item) => item.type === 'number').forEach((item) => {
      syncNumberRange(host, item.column, source);
    });
  }

  function renderCriteriaPanel() {
    const active = state.activeCriteriaColumn || (state.criteria[0] && state.criteria[0].column) || '';
    return `<div class="lcni-filter-criteria-grid"><div class="lcni-filter-criteria-tabs">${state.criteria.map((item) => `<button type="button" class="lcni-filter-tab ${item.column === active ? 'is-active' : ''}" data-criteria-tab="${esc(item.column)}"><span class="lcni-filter-tab-check" data-criteria-check="${esc(item.column)}">\u2713</span><span>${esc(item.label)}</span></button>`).join('')}</div><div class="lcni-filter-criteria-values">${state.criteria.map((item) => `<div class="lcni-filter-value-panel ${item.column === active ? 'is-active' : ''}" data-criteria-content="${esc(item.column)}"><strong>${esc(item.label)}</strong>${criteriaControlHtml(item)}</div>`).join('')}<div class="lcni-filter-summary lcni-filter-summary-desktop" data-filter-result-summary></div></div></div>`;
  }

  function renderStatic(host) {
    const settings = cfg.settings || {};
    const style = settings.style || {};
    const labels = settings.column_labels || {};
    const columns = state.visibleColumns;
    const selectedId = Number(state.selectedSavedFilterId || 0);
    const selectedTemplateId = Number(state.selectedTemplateId || 0);

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
    host.style.setProperty('--lcni-saved-filter-bg', String(style.saved_filter_dropdown_bg || '#ffffff'));
    host.style.setProperty('--lcni-saved-filter-color', String(style.saved_filter_dropdown_text || '#111827'));
    host.style.setProperty('--lcni-saved-filter-border', String(style.saved_filter_dropdown_border || '#d1d5db'));
    host.style.setProperty('--lcni-template-filter-bg', String(style.template_filter_dropdown_bg || '#ffffff'));
    host.style.setProperty('--lcni-template-filter-color', String(style.template_filter_dropdown_text || '#111827'));
    host.style.setProperty('--lcni-template-filter-border', String(style.template_filter_dropdown_border || '#d1d5db'));
    host.style.setProperty('--lcni-table-header-height', `${Number(style.table_header_row_height || 42)}px`);
    host.style.setProperty('--lcni-table-row-height', `${Number(style.row_height || 36)}px`);
    host.style.setProperty('--lcni-table-scroll-speed', String(Number(style.table_scroll_speed || 1)));
    host.classList.toggle('lcni-disable-sticky-header', Number(style.sticky_header_rows || 1) < 1);

    const hideBtn = style.enable_hide_button ? `<button type="button" class="lcni-btn lcni-btn-btn_filter_hide lcni-filter-hide-btn" data-filter-hide>${renderButtonContent('btn_filter_hide', 'Ẩn')}</button>` : '';
    const savedFilterLabel = esc(style.saved_filter_label || 'Saved Filter');
    const templateLabel = esc(style.template_filter_label || 'LCNi Filter Template');

    host.innerHTML = `<div class="lcni-filter-toolbar"><button type="button" class="lcni-btn lcni-btn-btn_filter_open" data-filter-toggle>${renderButtonContent('btn_filter_open', 'Filter')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_setting" data-column-toggle-btn>${renderButtonContent('btn_filter_setting', '')}</button></div><div class="lcni-filter-summary lcni-filter-summary-mobile" data-filter-result-summary></div><div class="lcni-filter-panel ${state.panelHidden ? 'is-collapsed' : ''}" data-filter-panel><div class="lcni-filter-panel-body">${renderCriteriaPanel()}</div><div class="lcni-filter-panel-actions"><label class="lcni-saved-filter-label">${savedFilterLabel}</label><select data-saved-filter-select class="lcni-select-saved-filter"><option value="">${savedFilterLabel}</option>${(state.savedFilters || []).map((f) => `<option value="${Number(f.id || 0)}" ${Number(f.id || 0) === selectedId ? 'selected' : ''}>${esc(f.filter_name || '')}</option>`).join('')}</select><label class="lcni-saved-filter-label">${templateLabel}</label><select data-template-filter-select class="lcni-select-template-filter"><option value="">${templateLabel}</option>${(state.adminTemplates || []).map((f) => `<option value="${Number(f.id || 0)}" ${Number(f.id || 0) === selectedTemplateId ? 'selected' : ''}>${esc(f.filter_name || '')}</option>`).join('')}</select><button type="button" class="lcni-btn lcni-btn-btn_filter_reload" data-reload-filter>${renderButtonContent('btn_filter_reload', 'Reload')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_save" data-save-current-filter>${renderButtonContent('btn_filter_save', 'Save')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_delete" data-delete-current-filter>${renderButtonContent('btn_filter_delete', 'Delete')}</button><button type="button" class="lcni-btn lcni-btn-btn_set_default_filter" data-set-default-filter>${renderButtonContent('btn_set_default_filter', 'Set Default')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_clear" data-clear-filter>${renderButtonContent('btn_filter_clear', 'Clear')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_apply" data-apply-filter>${renderButtonContent('btn_filter_apply', 'Apply Filter', getApplyLabel())}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_add_watchlist_bulk" data-add-filter-result-watchlist>${renderButtonContent('btn_filter_add_watchlist_bulk', 'Thêm vào Watchlist')}</button><button type="button" class="lcni-btn lcni-btn-btn_filter_export_excel" data-export-filter-excel>${renderButtonContent('btn_filter_export_excel', 'Xuất Excel')}</button>${hideBtn}</div></div><div class="lcni-column-pop" data-column-pop ${state.columnPanelOpen ? '' : 'hidden'}></div><div class="lcni-table-scroll"><table class="lcni-table"><thead><tr>${columns.map((c, idx) => `<th data-sort-key="${esc(c)}" class="${idx < Number(style.sticky_column_count || 1) ? 'is-sticky-col' : ''} ${isNumericValue((state.dataset[0] || {})[c]) ? 'lcni-cell-number' : 'lcni-cell-text'}">${esc(labels[c] || c)} <span data-sort-icon>${state.sortKey === c ? (state.sortDir === 'asc' ? '↑' : '↓') : ''}</span></th>`).join('')}</tr></thead><tbody><tr><td colspan="${columns.length}" class="lcni-cell-text">Nhấn Apply Filter để tải dữ liệu.</td></tr></tbody></table></div>`;

    const selectable = settings.table_columns || columns;
    host.querySelector('[data-column-pop]').innerHTML = `${renderColumnPositionItems(selectable, labels)}<button type="button" class="lcni-btn lcni-btn-btn_save_filter" data-save-columns>${renderButtonContent('btn_save_filter', 'Save')}</button>`;

    bindHorizontalScrollLock(host);
    applyDefaultFilterConfig(host);
    if (state.filters.length) applySavedFilterConfig(host, { filters: state.filters });
    renderFilterSummary(host);
  }


  function clearAllFilters(host) {
    state.criteria.forEach((item) => {
      if (item.type === 'number') {
        const min = host.querySelector(`[data-range-min="${item.column}"]`);
        const max = host.querySelector(`[data-range-max="${item.column}"]`);
        if (min) min.value = '';
        if (max) max.value = '';
      } else {
        host.querySelectorAll(`[data-text-check="${item.column}"]`).forEach((input) => { input.checked = false; });
      }
    });
    syncAllNumberRanges(host, 'input');
    updateCriteriaSelectionState(host);
  }

  async function updateEligibleCount(host) {
    const requestId = state.countRequestId + 1;
    state.countRequestId = requestId;
    const currentFilters = collectFilters(host);
    const payload = await api({ mode: 'count_preview', page: 1, limit: 1, filters: currentFilters, visible_columns: ['symbol'] });
    if (requestId !== state.countRequestId) return;
    state.lastAppliedTotal = Number((payload && payload.total) || 0);
    updateApplyButtonLabel(host);
  }

  function queueEligibleCount(host) {
    if (state.isApplying) {
      if (state.applyStartedAt > 0 && (Date.now() - state.applyStartedAt) > 26000) {
        state.isApplying = false;
        state.applyStartedAt = 0;
      } else {
        return;
      }
    }
    window.clearTimeout(host._lcniCountTimer);
    host._lcniCountTimer = window.setTimeout(() => {
      updateEligibleCount(host).catch(() => {});
    }, 300);
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
    renderFilterSummary(host);
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
      const style = ((cfg.settings || {}).style || {});
      const stickyCount = Number(style.sticky_column_count || 1);
      const columns = state.visibleColumns.length ? state.visibleColumns : ((cfg.settings && cfg.settings.table_columns) || []);
      const sorted = sortDataset(state.dataset);

      if (!sorted.length) {
        tbody.innerHTML = `<tr><td colspan="${columns.length}" class="lcni-cell-text">Không có dữ liệu phù hợp. Vui lòng chỉnh điều kiện và bấm Apply Filter.</td></tr>`;
      } else {
        tbody.innerHTML = sorted.map((row) => `<tr data-symbol="${esc(row.symbol || '')}">${columns.map((column, idx) => {
        const stickyClass = idx < stickyCount ? 'is-sticky-col' : '';
        if (column === 'symbol') {
          return `<td class="${stickyClass} lcni-cell-text" data-cell-field="symbol" data-cell-value="${esc(row[column] || '')}"><span>${esc(row[column] || '')}</span> <button type="button" class="lcni-btn lcni-btn-btn_add_filter_row" data-lcni-watchlist-add data-symbol="${esc(row.symbol || '')}" aria-label="Add to watchlist">${renderButtonContent('btn_add_filter_row', '')}</button></td>`;
        }
        const typeClass = isNumericValue(row[column]) ? 'lcni-cell-number' : 'lcni-cell-text';
        const cellRule = resolveCellToCellMeta(column, row);
        const valueRule = resolveValueColorRule(column, row[column]);
        const iconRule = cellRule && cellRule.icon_class ? cellRule : valueRule;
        const iconHtml = iconRule && iconRule.icon_class ? `<i class="${esc(iconRule.icon_class)}" style="color:${esc(iconRule.icon_color || '#dc2626')};font-size:${Number(iconRule.icon_size || 12)}px;"></i>` : '';
        const valueHtml = esc(formatCellValue(column, row[column]));
        const iconPosition = String((iconRule && iconRule.icon_position) || 'left');
        const content = iconHtml ? (iconPosition === 'right' ? `${valueHtml} ${iconHtml}` : `${iconHtml} ${valueHtml}`) : valueHtml;
        const styleParts = [];
        if (valueRule) styleParts.push(`background:${esc(valueRule.bg_color || '')};color:${esc(valueRule.text_color || '')};`);
        if (cellRule && cellRule.text_color) styleParts.push(`color:${esc(cellRule.text_color)};`);
        const styleAttr = styleParts.length ? ` style="${styleParts.join('')}"` : '';
        return `<td class="${stickyClass} ${typeClass}" data-cell-field="${esc(column)}" data-cell-value="${esc(row[column])}"${styleAttr}>${content}</td>`;
      }).join('')}</tr>`).join('');
      }
    }
    state.total = Number(payload.total || state.total || 0);
    state.lastAppliedTotal = state.total;
    updateApplyButtonLabel(host);
  }

  async function load(host) {
    renderTbody(host, await api({ mode: 'filter', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns }) || {});
    state.tableLoaded = true;
  }

  async function runApplyFilter(host) {
    if (state.isApplying) {
      if (state.applyStartedAt > 0 && (Date.now() - state.applyStartedAt) > 26000) {
        state.isApplying = false;
        state.applyStartedAt = 0;
      } else {
        return;
      }
    }
    state.filters = collectFilters(host);
    state.page = 1;
    state.columnPanelOpen = false;
    state.isApplying = true;
    state.applyStartedAt = Date.now();
    state.countRequestId += 1;
    window.clearTimeout(host._lcniCountTimer);
    const applyBtn = host.querySelector('[data-apply-filter]');
    if (applyBtn) {
      applyBtn.disabled = true;
      applyBtn.setAttribute('aria-busy', 'true');
    }

    const unlockFailsafe = window.setTimeout(() => {
      state.isApplying = false;
      state.applyStartedAt = 0;
      const latestButton = host.querySelector('[data-apply-filter]');
      if (latestButton) {
        latestButton.disabled = false;
        latestButton.removeAttribute('aria-busy');
      }
      showToast('Đang quá thời gian phản hồi. Vui lòng thử Apply lại.');
    }, 25000);

    try {
      await load(host);
    } catch (error) {
      showToast((error && error.message) || 'Không thể áp dụng bộ lọc. Vui lòng thử lại.');
    } finally {
      window.clearTimeout(unlockFailsafe);
      state.isApplying = false;
      state.applyStartedAt = 0;
      const latestButton = host.querySelector('[data-apply-filter]');
      if (latestButton) {
        latestButton.disabled = false;
        latestButton.removeAttribute('aria-busy');
      }
    }
  }
  async function refreshOnly(host) {
    const tbody = host.querySelector('tbody');
    if (!tbody || !state.tableLoaded) return;
    const payload = await api({ mode: 'refresh', page: state.page, limit: state.limit, filters: state.filters, visible_columns: state.visibleColumns });
    renderTbody(host, payload || {});
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
        window.clearInterval(host._lcniRefreshTimer);
        host._lcniRefreshTimer = window.setInterval(() => refreshOnly(host).catch(() => {}), 15000);
        return;
      }
      if (event.target.closest('[data-save-current-filter]')) {
        if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
        const name = window.prompt('Tên bộ lọc');
        if (!name) return;
        state.filters = collectFilters(host);
        await savedFilterApi('/save', { method: 'POST', body: { filter_name: name, filters: state.filters } });
        await loadSavedFilters();
        renderStatic(host);
        await load(host);
        window.clearInterval(host._lcniRefreshTimer);
        host._lcniRefreshTimer = window.setInterval(() => refreshOnly(host).catch(() => {}), 15000);
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
        queueEligibleCount(host);
        return;
      }
      if (event.target.closest('[data-set-default-filter]')) {
        if (!cfg.isLoggedIn) return showAuthModal('Vui lòng đăng nhập hoặc đăng ký để lưu bộ lọc');
        const id = Number(state.selectedSavedFilterId || 0);
        await savedFilterApi('/default', { method: 'POST', body: { id } });
        showToast(id > 0 ? 'Đã đặt bộ lọc mặc định' : 'Đã bỏ bộ lọc mặc định');
        return;
      }
      if (event.target.closest('[data-clear-filter]')) {
        clearAllFilters(host);
        state.filters = [];
        state.page = 1;
        state.selectedSavedFilterId = 0;
        state.selectedTemplateId = 0;
        state.tableLoaded = false;
        state.dataset = [];
        const tbody = host.querySelector('tbody');
        const columns = state.visibleColumns.length ? state.visibleColumns : (((cfg.settings || {}).table_columns) || []);
        if (tbody) tbody.innerHTML = `<tr><td colspan="${columns.length || 1}" class="lcni-cell-text">Nhấn Apply Filter để tải dữ liệu.</td></tr>`;
        queueEligibleCount(host);
        return;
      }
      if (event.target.closest('[data-apply-filter]')) {
        event.preventDefault();
        await runApplyFilter(host);
        return;
      }
      if (event.target.closest('[data-add-filter-result-watchlist]')) {
        const symbols = (Array.isArray(state.dataset) ? state.dataset : []).map((row) => String((row || {}).symbol || '').trim().toUpperCase()).filter(Boolean);
        await openBulkWatchlistSelector(symbols);
        return;
      }
      if (event.target.closest('[data-export-filter-excel]')) {
        exportCurrentResultToExcel();
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

    host.addEventListener('input', (event) => {
      if (event.target.matches('[data-range-slider-min],[data-range-slider-max]')) {
        const key = event.target.getAttribute('data-range-slider-min') || event.target.getAttribute('data-range-slider-max') || '';
        syncNumberRange(host, key, event.target.hasAttribute('data-range-slider-max') ? 'max' : 'min');
        updateCriteriaSelectionState(host);
        queueEligibleCount(host);
      }
    });

    host.addEventListener('keydown', async (event) => {
      if (event.key !== 'Enter') return;
      const inCriteriaPanel = event.target.closest('[data-filter-panel]');
      if (!inCriteriaPanel) return;
      const isTextLike = event.target.matches('input[type="text"],input[type="search"],input[type="number"],select');
      if (!isTextLike) return;
      event.preventDefault();
      await runApplyFilter(host);
    });

    host.addEventListener('change', async (event) => {
      const select = event.target.closest('[data-saved-filter-select]');
      if (select) {
        state.selectedSavedFilterId = Number(select.value || 0);
        state.selectedTemplateId = 0;
        if (state.selectedSavedFilterId > 0) {
          const payload = await savedFilterApi('/load?id=' + encodeURIComponent(state.selectedSavedFilterId), { method: 'GET' });
          applySavedFilterConfig(host, payload.config || {});
          state.filters = collectFilters(host);
          state.page = 1;
          queueEligibleCount(host);
        }
      }

      const templateSelect = event.target.closest('[data-template-filter-select]');
      if (templateSelect) {
        state.selectedTemplateId = Number(templateSelect.value || 0);
        state.selectedSavedFilterId = 0;
        if (state.selectedTemplateId > 0) {
          const payload = await savedFilterApi('/template/load?id=' + encodeURIComponent(state.selectedTemplateId), { method: 'GET' });
          applySavedFilterConfig(host, payload.config || {});
          state.filters = collectFilters(host);
          state.page = 1;
          queueEligibleCount(host);
        }
      }
      if (event.target.matches('[data-visible-col]')) state.columnPanelOpen = true;
      if (event.target.matches('input[type="checkbox"]')) { updateCriteriaSelectionState(host); queueEligibleCount(host); }
      if (event.target.matches('[data-range-slider-min],[data-range-slider-max]')) {
        const key = event.target.getAttribute('data-range-slider-min') || event.target.getAttribute('data-range-slider-max') || '';
        syncNumberRange(host, key, event.target.hasAttribute('data-range-slider-max') ? 'max' : 'min');
        updateCriteriaSelectionState(host);
        queueEligibleCount(host);
      }
    });
  }


  function getAutoFilterConfigFromQuery() {
    const query = new URLSearchParams(window.location.search || '');
    if (query.get('apply_filter') !== '1') return null;
    const filters = [];
    state.criteria.forEach((item) => {
      if (!item || !item.column) return;
      const rawValue = query.get(item.column);
      if (!rawValue) return;
      const parsed = parseCellValueForCriteria(rawValue, item);
      if (!parsed) return;
      filters.push({ column: item.column, operator: parsed.operator, value: parsed.value });
    });
    return filters.length ? { filters } : null;
  }

  function ensureHoverHintNode() {
    let node = document.querySelector('.lcni-filter-cell-hint');
    if (node) return node;
    node = document.createElement('div');
    node.className = 'lcni-filter-cell-hint';
    node.hidden = true;
    document.body.appendChild(node);
    return node;
  }

  function hideHoverHint() {
    const node = document.querySelector('.lcni-filter-cell-hint');
    if (!node) return;
    node.hidden = true;
    node.textContent = '';
    state.hoverCellKey = '';
  }

  async function showHoverHintForCell(cell, event) {
    const field = String(cell.getAttribute('data-cell-field') || '').trim();
    const value = String(cell.getAttribute('data-cell-value') || '').trim();
    const criterion = findCriteriaByColumn(field);
    const parsed = parseCellValueForCriteria(value, criterion);
    if (!field || !criterion || !parsed) return hideHoverHint();

    const requestId = state.hoverRequestId + 1;
    state.hoverRequestId = requestId;
    const node = ensureHoverHintNode();
    node.textContent = 'Đang kiểm tra...';
    node.style.left = `${event.clientX}px`;
    node.style.top = `${event.clientY}px`;
    node.hidden = false;

    try {
      const payload = await api({ mode: 'count_preview', page: 1, limit: 1, filters: [{ column: field, operator: parsed.operator, value: parsed.value }], visible_columns: ['symbol'] });
      if (requestId !== state.hoverRequestId) return;
      const total = Number((payload && payload.total) || 0);
      node.textContent = `${total.toLocaleString('vi-VN')} mã thỏa tiêu chí này`;
      node.style.left = `${event.clientX}px`;
      node.style.top = `${event.clientY}px`;
      node.hidden = false;
    } catch (_error) {
      hideHoverHint();
    }
  }

  function bindRowNavigation() {
    if (document.body.dataset.lcniFilterRowNavBound === '1') return;
    document.body.dataset.lcniFilterRowNavBound = '1';
    document.addEventListener('click', function (e) {
      if (e.target.closest('.lcni-btn')) return;
      const row = e.target.closest('tr[data-symbol]');
      const cell = e.target.closest('td[data-cell-field]');
      if (!row || !row.closest('[data-lcni-stock-filter]') || !cell) return;
      const field = String(cell.getAttribute('data-cell-field') || '').trim();
      const value = String(cell.getAttribute('data-cell-value') || '').trim();
      if (field === 'symbol') {
        const detailBase = String((window.lcniData && window.lcniData.stockDetailUrl) || cfg.stockDetailUrl || '');
        const symbol = encodeURIComponent(row.dataset.symbol || '');
        if (detailBase && symbol) window.location.href = detailBase + '?symbol=' + symbol;
        return;
      }
      const criterion = findCriteriaByColumn(field);
      const parsed = parseCellValueForCriteria(value, criterion);
      const filterUrl = buildFilterUrl(field, value);
      if (filterUrl && parsed) window.location.href = filterUrl;
    });

    document.addEventListener('mousemove', function (event) {
      const cell = event.target.closest('[data-lcni-stock-filter] td[data-cell-field]');
      if (!cell) { hideHoverHint(); return; }
      const field = String(cell.getAttribute('data-cell-field') || '').trim();
      const value = String(cell.getAttribute('data-cell-value') || '').trim();
      const key = `${field}::${value}`;
      const node = document.querySelector('.lcni-filter-cell-hint');
      if (node && !node.hidden) {
        node.style.left = `${event.clientX}px`;
        node.style.top = `${event.clientY}px`;
      }
      if (state.hoverCellKey === key) return;
      state.hoverCellKey = key;
      showHoverHintForCell(cell, event).catch(() => {});
    });

    document.addEventListener('mouseleave', function () {
      hideHoverHint();
    }, true);
  }

  function boot() {
    bindRowNavigation();
    state.panelHidden = !isMobileViewport();
    const defaultColumns = ((cfg.settings || {}).table_columns || []).slice();
    state.visibleColumns = loadVisibleColumns(defaultColumns);
    if (!state.visibleColumns.includes('symbol')) state.visibleColumns.unshift('symbol');
    state.activeCriteriaColumn = (state.criteria[0] && state.criteria[0].column) || '';

    document.querySelectorAll('[data-lcni-stock-filter]').forEach(async (host) => {
      await loadSavedFilters();
      renderStatic(host);
      bind(host);
      syncAllNumberRanges(host, 'input');
      updateCriteriaSelectionState(host);

      let initialConfig = null;
      if (state.selectedSavedFilterId > 0) {
        const payload = await savedFilterApi('/load?id=' + encodeURIComponent(state.selectedSavedFilterId), { method: 'GET' });
        initialConfig = payload.config || null;
      } else if (state.selectedTemplateId > 0) {
        const payload = await savedFilterApi('/template/load?id=' + encodeURIComponent(state.selectedTemplateId), { method: 'GET' });
        initialConfig = payload.config || null;
      }

      if (initialConfig) {
        applySavedFilterConfig(host, initialConfig);
      }

      const autoConfig = getAutoFilterConfigFromQuery();
      if (autoConfig) {
        applySavedFilterConfig(host, autoConfig);
      }

      state.filters = collectFilters(host);
      if (state.filters.length) {
        await load(host);
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

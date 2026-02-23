(function () {
  const cfg = window.LCNIFilterConfig || {};
  const operators = ['=', '!=', '>', '>=', '<', '<=', 'between'];
  const stateKey = 'lcni_stock_filter_state';

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function applyCellStyle(column, value, rules) {
    const numericValue = Number(value);
    for (const rule of rules || []) {
      if (!rule || rule.column !== column) continue;
      const compare = Number(rule.value);
      let ok = false;
      if (!Number.isNaN(numericValue) && !Number.isNaN(compare)) {
        if (rule.operator === '>') ok = numericValue > compare;
        if (rule.operator === '>=') ok = numericValue >= compare;
        if (rule.operator === '<') ok = numericValue < compare;
        if (rule.operator === '<=') ok = numericValue <= compare;
        if (rule.operator === '=') ok = numericValue === compare;
        if (rule.operator === '!=') ok = numericValue !== compare;
      } else if (rule.operator === '=') {
        ok = String(value) === String(rule.value);
      }
      if (ok) return `background:${esc(rule.bg_color)};color:${esc(rule.text_color)};`;
    }
    return '';
  }

  async function api(body) {
    const response = await fetch(cfg.restUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    });
    const json = await response.json();
    if (!response.ok) throw new Error((json && json.message) || 'Không thể tải filter table');
    return json;
  }

  function saveState(state) {
    try { window.localStorage.setItem(stateKey, JSON.stringify(state)); } catch (e) {}
  }

  function loadState() {
    try {
      const raw = window.localStorage.getItem(stateKey);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }

  function renderRuleRow(column, labels, current) {
    const valueA = Array.isArray(current.value) ? (current.value[0] || '') : (current.value || '');
    const valueB = Array.isArray(current.value) ? (current.value[1] || '') : '';
    return `<div class="lcni-filter-row" data-filter-row="${esc(column)}">
      <label>${esc(labels[column] || column)}</label>
      <select data-filter-operator>${operators.map((op) => `<option value="${op}" ${current.operator === op ? 'selected' : ''}>${op}</option>`).join('')}</select>
      <input type="text" data-filter-value-a value="${esc(valueA)}" placeholder="Giá trị" />
      <input type="text" data-filter-value-b value="${esc(valueB)}" placeholder="Từ/Đến" ${current.operator === 'between' ? '' : 'style="display:none"'} />
    </div>`;
  }

  function renderPanel(host, columns, labels, filters) {
    const panel = host.querySelector('[data-filter-panel]');
    const sourceFilters = Array.isArray(filters) && filters.length
      ? filters
      : Array.from({ length: Math.min(5, columns.length) }).map((_, index) => ({ column: columns[index], operator: '=', value: '' }));
    panel.innerHTML = sourceFilters.map((row) => {
      const column = columns.includes(row.column) ? row.column : (columns[0] || 'symbol');
      const current = row || { operator: '=', value: '' };
      return renderRuleRow(column, labels, current);
    }).join('') + '<button type="button" class="lcni-btn" data-add-filter-rule>Thêm rule</button>';
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

  function updateBody(host, data, settings) {
    const tbody = host.querySelector('tbody');
    const pagination = host.querySelector('[data-filter-pagination]');
    const labels = data.column_labels || {};
    const addBtn = settings.add_button || {};
    tbody.innerHTML = (data.items || []).map((row) => {
      const symbol = row.symbol || '';
      return `<tr>${(data.columns || []).map((column, index) => {
        if (column === 'symbol') {
          const style = `background:${esc(addBtn.background || '#dc2626')};color:${esc(addBtn.text_color || '#fff')};font-size:${Number(addBtn.font_size || 14)}px;width:${Number(addBtn.size || 26)}px;height:${Number(addBtn.size || 26)}px;`;
          return `<td class="${index === 0 ? 'is-sticky-col' : ''}"><span class="lcni-watchlist-symbol">${esc(symbol)}</span> <button type="button" class="lcni-watchlist-add" data-lcni-watchlist-add data-symbol="${esc(symbol)}" style="${style}" aria-label="Add to watchlist"><i class="${esc(addBtn.icon || 'fa-solid fa-heart-circle-plus')}" aria-hidden="true"></i></button></td>`;
        }
        const cellStyle = applyCellStyle(column, row[column], settings.value_color_rules || []);
        return `<td style="${cellStyle}">${esc(formatCellValue(column, row[column]))}</td>`;
      }).join('')}</tr>`;
    }).join('');

    const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.limit || 1)));
    pagination.innerHTML = `<button type="button" data-page-prev ${data.page <= 1 ? 'disabled' : ''}>Prev</button> <span>Page ${data.page}/${totalPages}</span> <button type="button" data-page-next ${data.page >= totalPages ? 'disabled' : ''}>Next</button>`;
  }

  function createSkeleton(host, columns, labels) {
    host.innerHTML = `<div data-filter-panel class="lcni-front-grid"></div>
      <div class="lcni-watchlist-table-wrap"><table class="lcni-watchlist-table"><thead><tr>${columns.map((c, i) => `<th class="${i === 0 ? 'is-sticky-col' : ''}">${esc(labels[c] || c)}</th>`).join('')}</tr></thead><tbody></tbody></table></div>
      <div data-filter-pagination style="margin-top:10px;"></div>`;
  }

  function collectFilters(host) {
    return Array.from(host.querySelectorAll('[data-filter-row]')).map((row) => {
      const column = row.getAttribute('data-filter-row');
      const operator = row.querySelector('[data-filter-operator]').value;
      const a = row.querySelector('[data-filter-value-a]').value.trim();
      const b = row.querySelector('[data-filter-value-b]').value.trim();
      return { column, operator, value: operator === 'between' ? [a, b] : a };
    }).filter((item) => Array.isArray(item.value) ? (item.value[0] || item.value[1]) : item.value);
  }

  async function load(host, page) {
    const state = host._filterState || { page: 1, filters: [] };
    state.page = page || state.page;
    const response = await api({ mode: 'all_symbols', page: state.page, limit: 50, filters: state.filters });
    const settings = response.settings || cfg.settings || {};
    const data = response.data || {};

    if (!host._skeleton) {
      createSkeleton(host, data.columns || settings.allowed_columns || [], data.column_labels || settings.column_labels || {});
      renderPanel(host, settings.allowed_columns || [], settings.column_labels || {}, state.filters.length ? state.filters : (settings.default_conditions || []));
      host._skeleton = true;
    }

    updateBody(host, data, settings);
    host._filterState = { page: data.page || 1, filters: state.filters };
    saveState(host._filterState);
  }

  function debounce(fn, wait) {
    let t;
    return function () {
      window.clearTimeout(t);
      t = window.setTimeout(() => fn.apply(this, arguments), wait);
    };
  }

  function bind(host) {
    const run = debounce(async function () {
      host._filterState.filters = collectFilters(host);
      host._filterState.page = 1;
      await load(host, 1);
    }, 350);

    host.addEventListener('change', (event) => {
      if (event.target.matches('[data-filter-operator]')) {
        const row = event.target.closest('[data-filter-row]');
        const second = row && row.querySelector('[data-filter-value-b]');
        if (second) second.style.display = event.target.value === 'between' ? '' : 'none';
      }
      if (event.target.matches('[data-filter-operator],[data-filter-value-a],[data-filter-value-b]')) run();
    });

    host.addEventListener('input', (event) => {
      if (event.target.matches('[data-filter-value-a],[data-filter-value-b]')) run();
    });

    host.addEventListener('click', (event) => {
      const addRuleBtn = event.target.closest('[data-add-filter-rule]');
      if (addRuleBtn) {
        const columns = (cfg.settings && cfg.settings.allowed_columns) || [];
        const labels = (cfg.settings && cfg.settings.column_labels) || {};
        if (!columns.length) return;
        addRuleBtn.insertAdjacentHTML('beforebegin', renderRuleRow(columns[0], labels, { operator: '=', value: '' }));
        return;
      }

      const prev = event.target.closest('[data-page-prev]');
      const next = event.target.closest('[data-page-next]');
      if (prev) load(host, Math.max(1, (host._filterState.page || 1) - 1));
      if (next) load(host, (host._filterState.page || 1) + 1);
    });
  }

  function boot() {
    document.querySelectorAll('[data-lcni-stock-filter]').forEach(async (host) => {
      host._filterState = loadState() || { page: 1, filters: (cfg.settings && cfg.settings.default_conditions) || [] };
      await load(host, host._filterState.page || 1);
      bind(host);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

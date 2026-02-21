(function () {
  const cfg = window.lcniWatchlistConfig || {};
  const WatchlistStore = window.lcniWatchlistStore || {
    symbols: new Set(),
    setSymbols(list) {
      this.symbols = new Set((Array.isArray(list) ? list : []).map((value) => String(value || '').toUpperCase()));
      this.emit();
    },
    add(symbol) {
      const key = String(symbol || '').toUpperCase();
      if (!key) return;
      this.symbols.add(key);
      this.emit();
    },
    remove(symbol) {
      const key = String(symbol || '').toUpperCase();
      if (!key) return;
      this.symbols.delete(key);
      this.emit();
    },
    has(symbol) {
      return this.symbols.has(String(symbol || '').toUpperCase());
    },
    emit() {
      const symbols = Array.from(this.symbols);
      window.dispatchEvent(new CustomEvent('lcniWatchlistSymbolsChanged', { detail: symbols }));
    }
  };
  window.lcniWatchlistStore = WatchlistStore;
  let activeWatchlistId = 0;
  let watchlistSortKey = "";
  let watchlistSortDir = "asc";
  const watchlistDatasetByHost = new WeakMap();

  function api(path, options) {
    return fetch((cfg.restBase || '').replace(/\/$/, '') + path, {
      method: (options && options.method) || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: options && options.body ? JSON.stringify(options.body) : undefined,
      credentials: 'same-origin'
    }).then(async (response) => {
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw payload;
      return payload;
    });
  }

  const esc = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
  const mobile = () => window.matchMedia('(max-width: 767px)').matches;
  const getDevice = () => (mobile() ? 'mobile' : 'desktop');
  const getStorageKey = (device) => `${cfg.settingsStorageKey || 'lcni_watchlist_settings_v1'}:${device}`;

  function getButtonConfig(key) {
    const all = cfg.buttonConfig || {};
    return all[key] || {};
  }

  function renderButtonContent(key, fallbackLabel) {
    const conf = getButtonConfig(key);
    const iconClass = String(conf.icon_class || '').trim();
    const labelText = String(conf.label_text || fallbackLabel || '');
    const icon = iconClass ? `<i class="${esc(iconClass)}" aria-hidden="true"></i>` : '';
    const label = `<span>${esc(labelText)}</span>`;
    return conf.icon_position === 'right' ? `${label}${icon}` : `${icon}${label}`;
  }

  function loadCachedColumns(device) {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(getStorageKey(device)) || '{}');
      return Array.isArray(parsed.columns) ? parsed.columns : [];
    } catch (e) { return []; }
  }

  function saveCachedColumns(device, columns) {
    try { window.localStorage.setItem(getStorageKey(device), JSON.stringify({ columns, updatedAt: Date.now() })); } catch (e) {}
  }

  function resolveActiveWatchlistName() {
    const selected = document.querySelector('[data-watchlist-select] option:checked');
    return selected ? String(selected.textContent || '').trim() : '';
  }

  function showToast(msg) {
    const node = document.createElement('div');
    node.className = 'lcni-watchlist-toast';
    node.textContent = msg;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 2400);
  }

  function setButtonState(button) {
    const symbol = button.getAttribute('data-symbol') || '';
    const icon = button.querySelector('i');
    const inWatchlist = WatchlistStore.has(symbol);
    button.classList.toggle('is-active', inWatchlist);
    button.setAttribute('aria-label', inWatchlist ? 'Remove from watchlist' : 'Add to watchlist');
    if (icon && !button.classList.contains('is-loading')) {
      icon.className = inWatchlist ? 'fa-solid fa-trash' : String(getButtonConfig('btn_watchlist_add').icon_class || 'fa-solid fa-heart');
    }
  }

  function syncAllButtons() {
    document.querySelectorAll('[data-lcni-watchlist-add]').forEach(setButtonState);
  }

  function setHostLoading(host, isLoading) {
    const overlay = host.querySelector('[data-watchlist-overlay]');
    if (overlay) overlay.hidden = !isLoading;
  }

  function buildStockDetailUrl(symbol) {
    const slug = String(cfg.stockDetailPageSlug || '').replace(/^\/+|\/+$/g, '');
    const encoded = encodeURIComponent(String(symbol || '').trim());
    return slug && encoded ? `/${slug}/?symbol=${encoded}` : '';
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
    return false;
  }

  function resolveCellStyle(column, value, rules) {
    for (let i = 0; i < rules.length; i += 1) {
      const rule = rules[i] || {};
      if (rule.column !== column) continue;
      if (!resolveOperatorMatch(value, rule.operator, rule.value)) continue;
      return `background:${esc(rule.bg_color || '')};color:${esc(rule.text_color || '')};`;
    }

    return '';
  }

  function renderTable(host, data) {
    const allowed = Array.isArray(data.allowed_columns) ? data.allowed_columns : [];
    const columns = Array.isArray(data.columns) ? data.columns : [];
    const labels = data.column_labels || {};
    const items = Array.isArray(data.items) ? data.items : [];
    const settings = data.settings || cfg.settingsOption || {};
    const valueColorRules = Array.isArray(settings.value_color_rules) ? settings.value_color_rules : [];
    activeWatchlistId = Number(data.active_watchlist_id || activeWatchlistId || 0);

    watchlistDatasetByHost.set(host, items);
    host.innerHTML = `
      <div class="lcni-watchlist-header"><strong>Watchlist</strong>
      <div class="lcni-watchlist-list-controls"><select data-watchlist-select>${(Array.isArray(data.watchlists)?data.watchlists:[]).map((w)=>`<option value="${Number(w.id||0)}" ${(Number(w.id||0)===Number(data.active_watchlist_id||0))?'selected':''}>${esc(w.name||'')}</option>`).join('')}</select><input type="text" class="lcni-watchlist-symbol-input" data-watchlist-symbol-input placeholder="Nhập mã" /><button type="button" class="lcni-btn lcni-btn-btn_watchlist_add_symbol lcni-watchlist-add-btn" data-watchlist-add-btn>${renderButtonContent('btn_watchlist_add_symbol', 'Thêm')}</button><button type="button" class="lcni-btn lcni-btn-btn_watchlist_new" data-watchlist-create>${renderButtonContent('btn_watchlist_new', '+ New')}</button><button type="button" class="lcni-btn lcni-btn-btn_watchlist_delete" data-watchlist-delete>${renderButtonContent('btn_watchlist_delete', 'Delete')}</button></div>
      <div class="lcni-watchlist-dropdown"><button type="button" class="lcni-watchlist-settings-btn lcni-btn lcni-btn-btn_watchlist_setting" data-watchlist-settings aria-expanded="false">${renderButtonContent('btn_watchlist_setting', '')}</button>
      <div class="lcni-watchlist-controls"><div class="lcni-watchlist-col-grid">${allowed.map((c) => `<label class="lcni-watchlist-col-item"><input type="checkbox" data-col-toggle value="${esc(c)}" ${columns.includes(c) ? 'checked' : ''}> ${esc(labels[c] || c)}</label>`).join('')}</div><button type="button" class="lcni-btn lcni-btn-btn_watchlist_save" data-watchlist-save>${renderButtonContent('btn_watchlist_save', 'Lưu')}</button></div></div></div>
      <div class="lcni-watchlist-table-wrap lcni-table-scroll lcni-table-wrapper"><table class="lcni-watchlist-table lcni-table"><thead><tr>${columns.map((c, idx) => `<th data-sort-key="${esc(c)}" class="${idx === 0 && c === 'symbol' ? 'is-sticky-col' : ''}">${esc(labels[c] || c)} <span class="lcni-sort-icon">${watchlistSortKey===c?(watchlistSortDir==='asc'?'↑':'↓'):""}</span></th>`).join('')}</tr></thead>
      <tbody>${items.map((row) => {
        const symbol = row.symbol || '';
        return `<tr data-row-symbol="${esc(symbol)}">${columns.map((c, idx) => {
          const cls = idx === 0 && c === 'symbol' ? ' class="is-sticky-col"' : '';
          if (c === 'symbol') {
            return `<td${cls}><span class="lcni-watchlist-symbol">${esc(symbol)}</span><button type="button" class="lcni-watchlist-add lcni-btn lcni-btn-btn_watchlist_add is-active" data-lcni-watchlist-add data-symbol="${esc(symbol)}">${renderButtonContent('btn_watchlist_add', '')}</button></td>`;
          }
          const valueStyle = resolveCellStyle(c, row[c], valueColorRules);
          return `<td${cls}${valueStyle ? ` style="${valueStyle}"` : ''}>${esc(row[c])}</td>`;
        }).join('')}</tr>`;
      }).join('')}</tbody></table><div class="lcni-watchlist-overlay" data-watchlist-overlay hidden>Loading...</div></div>`;
  }

  async function refreshTable(host) {
    const device = getDevice();
    const selected = Array.from(host.querySelectorAll('[data-col-toggle]:checked')).map((i) => i.value);
    const queryCols = selected.length ? '&' + selected.map((v) => `columns[]=${encodeURIComponent(v)}`).join('&') : '';
    const queryWatchlist = activeWatchlistId ? '&watchlist_id=' + encodeURIComponent(activeWatchlistId) : '';
    const data = await api('/load?device=' + encodeURIComponent(device) + queryCols + queryWatchlist);
    renderTable(host, data);
    saveCachedColumns(device, data.columns || []);
    WatchlistStore.setSymbols(data.symbols || []);
    syncAllButtons();
  }

  async function refreshRowsOnly(host) {
    const device = getDevice();
    const selected = Array.from(host.querySelectorAll('[data-col-toggle]:checked')).map((i) => i.value);
    const queryCols = selected.length ? '&' + selected.map((v) => `columns[]=${encodeURIComponent(v)}`).join('&') : '';
    const queryWatchlist = activeWatchlistId ? '&watchlist_id=' + encodeURIComponent(activeWatchlistId) : '';
    const data = await api('/list?mode=refresh&device=' + encodeURIComponent(device) + queryCols + queryWatchlist);
    const tbody = host.querySelector('tbody');
    if (tbody) {
      tbody.innerHTML = data.rows || '';
    }
    if (Array.isArray(data.symbols)) {
      WatchlistStore.setSymbols(data.symbols);
      syncAllButtons();
    }
  }

  function startAutoRefresh(host) {
    window.clearInterval(host._lcniWatchlistRefreshTimer);
    host._lcniWatchlistRefreshTimer = window.setInterval(() => {
      refreshRowsOnly(host).catch(() => {});
    }, 15000);
  }

  async function toggleSymbol(symbol, forceAction) {
    const inWatchlist = WatchlistStore.has(symbol);
    const action = forceAction || (inWatchlist ? 'remove' : 'add');
    const endpoint = action === 'add' ? '/add-symbol' : '/remove-symbol';
    const payload = await api(endpoint, { method: 'POST', body: { symbol, watchlist_id: activeWatchlistId } });

    if (action === 'add') {
      WatchlistStore.add(symbol);
      window.dispatchEvent(new CustomEvent('lcniSymbolAdded', { detail: symbol }));
    } else {
      WatchlistStore.remove(symbol);
      window.dispatchEvent(new CustomEvent('lcniSymbolRemoved', { detail: symbol }));
    }

    return payload;
  }

  function bindGlobalDelegation() {
    if (document.body.dataset.watchlistGlobalBound === '1') return;
    document.body.dataset.watchlistGlobalBound = '1';

    document.addEventListener('submit', async (event) => {
      const form = event.target.closest('[data-lcni-watchlist-add-form]');
      if (!form) return;
      event.preventDefault();
      if (!cfg.isLoggedIn) {
        if (cfg.loginUrl) window.location.href = cfg.loginUrl;
        return;
      }

      const input = form.querySelector('[data-watchlist-symbol-input]');
      const submitBtn = form.querySelector('button[type="submit"]');
      const submitIcon = submitBtn ? submitBtn.querySelector('i') : null;
      const symbol = String((input && input.value) || '').trim().toUpperCase();
      if (input) input.value = symbol;
      if (!symbol) return;
      if (!activeWatchlistId) { showToast('Vui lòng chọn watchlist'); return; }

      try {
        if (submitBtn) submitBtn.disabled = true;
        if (submitIcon) submitIcon.className = 'fa-solid fa-spinner fa-spin';
        const payload = await toggleSymbol(symbol, 'add');
        const watchlistName = String((payload && (payload.watchlist_name || payload.name)) || resolveActiveWatchlistName() || '');
        showToast('Đã thêm mã ' + symbol + ' vào Watchlist: ' + watchlistName);
        if (input) input.value = '';
        if (submitIcon) submitIcon.className = 'fa-solid fa-check-circle';
        window.setTimeout(() => {
          if (submitIcon) submitIcon.className = String(getButtonConfig('btn_watchlist_add_symbol').icon_class || 'fa-solid fa-heart');
        }, 1200);
        document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => refreshTable(host).catch(() => {}));
      } catch (error) {
        if (submitIcon) submitIcon.className = 'fa-solid fa-exclamation-circle';
        showToast((error && error.message) || 'Không thể thêm vào watchlist');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });

    document.addEventListener('click', async (event) => {
      const quickAddBtn = event.target.closest('[data-watchlist-add-btn]');
      if (quickAddBtn) {
        const host = quickAddBtn.closest('[data-lcni-watchlist]');
        const input = host ? host.querySelector('[data-watchlist-symbol-input]') : null;
        const symbol = String((input && input.value) || '').trim().toUpperCase();
        if (input) input.value = symbol;
        if (!symbol) { showToast('Vui lòng nhập mã cổ phiếu'); return; }
        if (!activeWatchlistId) { showToast('Vui lòng chọn watchlist'); return; }

        quickAddBtn.disabled = true;
        try {
          const payload = await toggleSymbol(symbol, 'add');
          const watchlistName = String((payload && (payload.watchlist_name || payload.name)) || resolveActiveWatchlistName() || '');
          showToast('Đã thêm mã ' + symbol + ' vào Watchlist: ' + watchlistName);
          if (input) input.value = '';
          if (host) {
            await refreshTable(host);
          }
        } catch (error) {
          showToast((error && error.message) || 'Không thể thêm vào watchlist');
        } finally {
          quickAddBtn.disabled = false;
        }
        return;
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
        addBtn.classList.add('is-loading');
        if (icon) icon.className = 'fa-solid fa-spinner fa-spin';

        try {
          const removeFromTable = addBtn.closest('[data-lcni-watchlist]') && WatchlistStore.has(symbol);
          await toggleSymbol(symbol);
          if (removeFromTable) {
            const row = addBtn.closest('tr[data-row-symbol]');
            if (row) row.remove();
          }
          syncAllButtons();
        } catch (error) {
          showToast((error && error.message) || 'Không thể cập nhật watchlist');
        } finally {
          addBtn.disabled = false;
          addBtn.classList.remove('is-loading');
          setButtonState(addBtn);
        }
        return;
      }

      const sortTh = event.target.closest('th[data-sort-key]');
      if (sortTh) {
        const key = sortTh.getAttribute('data-sort-key');
        if (key === 'actions') return;
        watchlistSortDir = watchlistSortKey === key && watchlistSortDir === 'asc' ? 'desc' : 'asc';
        watchlistSortKey = key;
        const host = sortTh.closest('[data-lcni-watchlist]');
        const dataset = watchlistDatasetByHost.get(host) || [];
        const dir = watchlistSortDir === 'asc' ? 1 : -1;
        dataset.sort((a,b)=>{ const av=a[key], bv=b[key]; const an=Number(av), bn=Number(bv); if(Number.isFinite(an)&&Number.isFinite(bn)) return (an-bn)*dir; return String(av||'').localeCompare(String(bv||''))*dir;});
        renderTable(host, Object.assign({}, {allowed_columns: [], columns: Array.from(host.querySelectorAll('thead th')).map((th)=>th.getAttribute('data-sort-key')), column_labels: cfg.settingsOption.column_labels || {}, items: dataset, settings: cfg.settingsOption, watchlists: [], active_watchlist_id: activeWatchlistId}));
        syncAllButtons();
        return;
      }

      const settingsBtn = event.target.closest('[data-watchlist-settings]');
      if (settingsBtn) {
        event.preventDefault();
        event.stopPropagation();
        const host = settingsBtn.closest('[data-lcni-watchlist]');
        const dropdown = host && host.querySelector('.lcni-watchlist-dropdown');
        const expanded = settingsBtn.getAttribute('aria-expanded') === 'true';
        settingsBtn.setAttribute('aria-expanded', String(!expanded));
        if (dropdown) dropdown.classList.toggle('open', !expanded);
      }

      const saveBtn = event.target.closest('[data-watchlist-save]');
      if (saveBtn) {
        event.preventDefault();
        event.stopPropagation();
        const host = saveBtn.closest('[data-lcni-watchlist]');
        if (!host) return;
        const device = getDevice();
        const selected = Array.from(host.querySelectorAll('[data-col-toggle]:checked')).map((input) => input.value);
        setHostLoading(host, true);
        try {
          saveCachedColumns(device, selected);
          await api('/settings?device=' + encodeURIComponent(device), { method: 'POST', body: { columns: selected } });
          await refreshTable(host);
        } catch (error) {
          showToast((error && error.message) || 'Không thể lưu cài đặt.');
        } finally {
          setHostLoading(host, false);
        }
      }


      const watchlistSelect = event.target.closest('[data-watchlist-select]');
      if (watchlistSelect) {
        return;
      }

      const createBtn = event.target.closest('[data-watchlist-create]');
      if (createBtn) {
        const name = window.prompt('Tên watchlist mới');
        if (!name) return;
        try {
          const created = await api('/create', { method: 'POST', body: { name } });
          activeWatchlistId = Number((created && created.id) || 0);
          document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => refreshTable(host).catch(() => {}));
        } catch (error) {
          showToast((error && error.message) || 'Không thể tạo watchlist');
        }
        return;
      }

      const deleteBtn = event.target.closest('[data-watchlist-delete]');
      if (deleteBtn) {
        if (!activeWatchlistId) return;
        try {
          const deleted = await api('/delete', { method: 'POST', body: { watchlist_id: activeWatchlistId } });
          activeWatchlistId = Number((deleted && deleted.active_watchlist_id) || 0);
          document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => refreshTable(host).catch(() => {}));
        } catch (error) {
          showToast((error && error.message) || 'Không thể xoá watchlist');
        }
        return;
      }

      document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => {
        if (host.contains(event.target)) return;
        const dropdown = host.querySelector('.lcni-watchlist-dropdown');
        const settings = host.querySelector('[data-watchlist-settings]');
        if (dropdown) dropdown.classList.remove('open');
        if (settings) settings.setAttribute('aria-expanded', 'false');
      });
    });

    document.addEventListener('change', async (event) => {
      const select = event.target.closest('[data-watchlist-select]');
      if (!select) return;
      activeWatchlistId = Number(select.value || 0);
      document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => refreshTable(host).catch(() => {}));
    });

    document.addEventListener('click', (event) => {
      const row = event.target.closest('tbody tr[data-row-symbol]');
      if (!row || event.target.closest('[data-lcni-watchlist-add],button,a,i,svg,[role="button"]')) return;
      const url = buildStockDetailUrl(row.getAttribute('data-row-symbol'));
      if (url) window.location.href = url;
    });

    window.addEventListener('lcniWatchlistSymbolsChanged', syncAllButtons);
  }

  async function bootHost(host) {
    if (!cfg.isLoggedIn) {
      host.innerHTML = '<a href="' + (cfg.loginUrl || '#') + '">Đăng nhập để xem watchlist</a>';
      return;
    }

    const device = getDevice();
    const defaults = device === 'mobile' ? (cfg.defaultColumnsMobile || []) : (cfg.defaultColumnsDesktop || []);
    const selected = loadCachedColumns(device).length ? loadCachedColumns(device) : defaults;

    try {
      const query = selected.length ? '&' + selected.map((v) => `columns[]=${encodeURIComponent(v)}`).join('&') : '';
      const data = await api('/list?device=' + encodeURIComponent(device) + query);
      renderTable(host, data);
      saveCachedColumns(device, data.columns || []);
      WatchlistStore.setSymbols(data.symbols || []);
      syncAllButtons();
      startAutoRefresh(host);
    } catch (e) {
      host.innerHTML = '<p>Không thể tải watchlist</p>';
    }
  }

  function boot() {
    bindGlobalDelegation();
    document.querySelectorAll('[data-lcni-watchlist]').forEach((host) => bootHost(host));
    syncAllButtons();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

(function () {
  const spinnerIcon = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>';
  const heartIcon = '<i class="fa-solid fa-heart-circle-plus" aria-hidden="true"></i>';
  const successIcon = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i>';

  const jsonFetch = async (url, options = {}) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error((data && data.message) || 'Request failed');
    }
    return data;
  };

  const formatValue = (value) => {
    if (value === null || value === undefined || value === '') return '-';
    return value;
  };

  const tableFromData = (rows, fields, labels) => `
      <table class="lcni-watchlist-table">
        <thead>
          <tr>
            ${fields.map((field) => `<th>${labels[field] || field}</th>`).join('')}
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${rows.map((row) => `
            <tr>
              ${fields.map((field) => `<td>${formatValue(row[field])}</td>`).join('')}
              <td><button type="button" class="lcni-watchlist-remove-btn" data-remove-symbol="${row.symbol}">Xóa</button></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;

  const renderEmptyState = (node, emptyMessage) => {
    node.innerHTML = `
      <div class="lcni-watchlist-empty">
        <p>${emptyMessage}</p>
        <form class="lcni-watchlist-add-form" data-watchlist-add-form="1">
          <input type="text" name="symbol" placeholder="Nhập mã cổ phiếu (VD: FPT)" maxlength="20" required>
          <button type="submit">${heartIcon} Thêm vào</button>
        </form>
      </div>
    `;
  };

  const bindInlineAddForm = (node, watchlistApi, restNonce, rerender) => {
    const form = node.querySelector('[data-watchlist-add-form="1"]');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      const input = form.querySelector('input[name="symbol"]');
      const symbol = (input && input.value ? input.value : '').trim().toUpperCase();
      if (!symbol) return;

      button.disabled = true;
      button.innerHTML = `${spinnerIcon} Đang thêm...`;
      try {
        await jsonFetch(watchlistApi, {
          method: 'POST',
          headers: { 'X-WP-Nonce': restNonce },
          body: JSON.stringify({ symbol }),
        });
        await rerender();
      } catch (error) {
        button.innerHTML = `${heartIcon} ${error.message || 'Thêm thất bại'}`;
      } finally {
        button.disabled = false;
      }
    });
  };

  const renderWatchlistNode = async (node) => {
    const watchlistApi = node.dataset.watchlistApi;
    const watchlistSettingsApi = node.dataset.watchlistSettingsApi;
    const watchlistPreferencesApi = node.dataset.watchlistPreferencesApi;
    const emptyMessage = node.dataset.emptyMessage || 'Watchlist rỗng.';
    const restNonce = node.dataset.restNonce || '';

    const [state, settings, preferences] = await Promise.all([
      jsonFetch(watchlistApi),
      jsonFetch(watchlistSettingsApi),
      jsonFetch(watchlistPreferencesApi, { headers: { 'X-WP-Nonce': restNonce } }),
    ]);

    const symbols = Array.isArray(state.symbols) ? state.symbols : [];
    const rows = Array.isArray(state.items) ? state.items : [];
    const allowedFields = Array.isArray(settings.allowed_fields) ? settings.allowed_fields : ['symbol'];
    const labels = settings.labels || {};

    const selectedFieldsRaw = Array.isArray(preferences.selected_fields) ? preferences.selected_fields : [];
    const selectedFields = selectedFieldsRaw.filter((field) => allowedFields.includes(field));
    const fields = selectedFields.length ? selectedFields : allowedFields;

    if (!symbols.length) {
      renderEmptyState(node, emptyMessage);
      bindInlineAddForm(node, watchlistApi, restNonce, async () => renderWatchlistNode(node));
      return;
    }

    node.innerHTML = `
      <div class="lcni-watchlist-toolbar">
        <form class="lcni-watchlist-add-form" data-watchlist-add-form="1">
          <input type="text" name="symbol" placeholder="Nhập mã cổ phiếu" maxlength="20" required>
          <button type="submit">${heartIcon} Thêm mã</button>
        </form>
        <button type="button" class="lcni-watchlist-column-toggle" data-toggle-columns="1"><i class="fa-solid fa-gear" aria-hidden="true"></i> Cột hiển thị</button>
      </div>
      <div class="lcni-watchlist-column-panel" data-column-panel="1" hidden>
        ${allowedFields.map((field) => `
          <label><input type="checkbox" value="${field}" ${fields.includes(field) ? 'checked' : ''}> ${labels[field] || field}</label>
        `).join('')}
      </div>
      ${tableFromData(rows, fields, labels)}
    `;

    bindInlineAddForm(node, watchlistApi, restNonce, async () => renderWatchlistNode(node));

    const toggleButton = node.querySelector('[data-toggle-columns="1"]');
    const panel = node.querySelector('[data-column-panel="1"]');
    if (toggleButton && panel) {
      toggleButton.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
      });
    }

    panel?.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
      checkbox.addEventListener('change', async () => {
        const selected = Array.from(panel.querySelectorAll('input[type="checkbox"]:checked')).map((input) => input.value);
        await jsonFetch(watchlistPreferencesApi, {
          method: 'POST',
          headers: { 'X-WP-Nonce': restNonce },
          body: JSON.stringify({ selected_fields: selected }),
        });
        renderWatchlistNode(node);
      });
    });

    node.querySelectorAll('[data-remove-symbol]').forEach((button) => {
      button.addEventListener('click', async () => {
        const symbol = button.getAttribute('data-remove-symbol') || '';
        button.disabled = true;
        button.innerHTML = spinnerIcon;
        await jsonFetch(watchlistApi, {
          method: 'DELETE',
          headers: { 'X-WP-Nonce': restNonce },
          body: JSON.stringify({ symbol }),
        });
        renderWatchlistNode(node);
      });
    });
  };

  const bindAddButton = (button) => {
    if (button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', async () => {
      const symbol = button.getAttribute('data-symbol') || '';
      const watchlistApi = button.getAttribute('data-watchlist-api') || '';
      const restNonce = button.getAttribute('data-rest-nonce') || '';
      if (!symbol || !watchlistApi) return;

      const iconNode = button.querySelector('.lcni-watchlist-add-icon');
      const labelNode = button.querySelector('.lcni-watchlist-add-label');

      button.disabled = true;
      button.classList.remove('is-error');
      button.classList.add('is-loading');
      if (iconNode) iconNode.innerHTML = spinnerIcon;
      if (labelNode) labelNode.textContent = 'Đang thêm...';

      try {
        await jsonFetch(watchlistApi, {
          method: 'POST',
          headers: { 'X-WP-Nonce': restNonce },
          body: JSON.stringify({ symbol }),
        });
        button.classList.remove('is-loading');
        button.classList.add('is-added');
        if (iconNode) iconNode.innerHTML = successIcon;
        if (labelNode) labelNode.textContent = 'Đã thêm';
      } catch (_err) {
        button.classList.remove('is-loading');
        button.classList.add('is-error');
        button.disabled = false;
        if (iconNode) iconNode.innerHTML = heartIcon;
        if (labelNode) labelNode.textContent = 'Thêm thất bại';
      }
    });
  };

  document.querySelectorAll('[data-lcni-watchlist]').forEach((node) => {
    renderWatchlistNode(node).catch((err) => {
      node.innerHTML = `<div class="lcni-watchlist-error">${err.message || 'Không tải được watchlist.'}</div>`;
    });
  });

  document.querySelectorAll('[data-lcni-watchlist-add="1"]').forEach((button) => {
    bindAddButton(button);
  });
})();

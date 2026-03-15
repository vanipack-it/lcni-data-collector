(function () {
  'use strict';

  const cfg      = window.lcniHeatmapConfig || {};
  const settings = cfg.settings || {};

  /* ── helpers ──────────────────────────────────────────────────────────── */

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function px(n) { return Math.round(n) + 'px'; }

  /* ── full-screen positioning ──────────────────────────────────────────── */
  /**
   * Measure the host element's position in the viewport BEFORE we apply
   * fixed positioning, then lock it via CSS custom properties so the heatmap
   * always occupies: top-of-host → bottom-of-viewport (full height below topbar)
   *                  left-of-host → right-of-viewport (full width past sidebar)
   */
  function applyFullscreenPosition(host) {
    if (window.innerWidth <= 768) {
      // Đo khoảng cách từ top của host đến mép trên viewport
      // height = viewport height − vị trí top của host → lấp đầy đến sát đáy
      const rect    = host.getBoundingClientRect();
      const hostTop = Math.round(rect.top + window.scrollY); // offset từ document top
      const avail   = window.innerHeight - rect.top;         // px còn lại từ host đến bottom viewport
      host.style.setProperty('--lcni-hm-avail', Math.max(300, Math.round(avail)) + 'px');
      host.style.setProperty('--lcni-hm-host-top', Math.round(rect.top) + 'px');
      return;
    }

    // Temporarily remove fixed so we can measure natural position
    host.style.position = 'static';
    host.style.width    = '100%';
    host.style.height   = '1px'; // minimal so it doesn't push content

    const rect = host.getBoundingClientRect();
    const top  = Math.round(rect.top  + window.scrollY - window.scrollY); // viewport top
    const left = Math.round(rect.left);

    // Apply offsets as CSS vars then switch to fixed
    host.style.setProperty('--lcni-hm-top',  px(top));
    host.style.setProperty('--lcni-hm-left', px(left));
    host.style.removeProperty('position');
    host.style.removeProperty('width');
    host.style.removeProperty('height');
  }

  /* ── treemap-style sizing ─────────────────────────────────────────────── */
  /**
   * Distribute tiles proportionally.
   * Sorted by weight DESC, laid row-by-row choosing the row length that
   * keeps aspect ratios closest to square.
   */
  function computeSizes(tiles, containerW, containerH, gap) {
    const n = tiles.length;
    if (n === 0) return [];

    const weights  = tiles.map((t) => Math.max(1, t.count || 1));
    const totalW   = weights.reduce((a, b) => a + b, 0);
    const totalArea = containerW * containerH;
    const areas    = weights.map((w) => (w / totalW) * totalArea);

    const results = new Array(n);
    let start = 0;

    while (start < n) {
      const remaining = n - start;
      let bestEnd   = start + 1;
      let bestScore = Infinity;

      for (let end = start + 1; end <= n; end++) {
        const slice    = areas.slice(start, end);
        const sliceSum = slice.reduce((a, b) => a + b, 0);
        const rowH     = sliceSum / containerW;
        if (rowH <= 0) continue;

        let worstAR = 0;
        for (const a of slice) {
          const tw = a / rowH;
          const ar = Math.max(tw / rowH, rowH / tw);
          if (ar > worstAR) worstAR = ar;
        }
        if (worstAR < bestScore) { bestScore = worstAR; bestEnd = end; }
        if (worstAR > bestScore * 1.5 && end > start + 1) break;
      }

      const sliceAreas = areas.slice(start, bestEnd);
      const sliceSum   = sliceAreas.reduce((a, b) => a + b, 0);
      const rowH       = Math.max(1, sliceSum / containerW);

      for (let k = start; k < bestEnd; k++) {
        const tw = sliceAreas[k - start] / rowH;
        results[k] = {
          width:  Math.max(4, tw   - gap),
          height: Math.max(4, rowH - gap),
        };
      }
      start = bestEnd;
    }

    return results;
  }

  /* ── render ───────────────────────────────────────────────────────────── */

  function renderTile(tile, size) {
    const labelSize  = Number(settings.label_font_size  || 13);
    const countSize  = Number(settings.count_font_size  || 42);
    const symbolSize = Number(settings.symbol_font_size || 13);
    // text_color từ từng tile, fallback '#ffffff'
    const textColor   = String(tile.text_color || '#ffffff');
    const radius      = Number(settings.border_radius || 8);
    const detailBase  = String(cfg.stockDetailUrl || '');

    const tileEl = document.createElement('div');
    tileEl.className = 'lcni-heatmap-tile';
    tileEl.style.cssText = [
      'background:'    + esc(tile.color || '#dc2626'),
      'width:'         + px(size.width),
      'height:'        + px(size.height),
      'border-radius:' + px(radius),
    ].join(';');

    // Label
    const labelEl = document.createElement('div');
    labelEl.className = 'lcni-heatmap-label';
    labelEl.textContent = tile.label || tile.column || '';
    labelEl.style.cssText = 'font-size:' + px(labelSize) + ';color:' + textColor + ';';
    tileEl.appendChild(labelEl);

    // Count — scale to tile width, never overflow
    const dynamicCountSize = Math.min(countSize, Math.max(16, size.width * 0.22));
    const countEl = document.createElement('div');
    countEl.className = 'lcni-heatmap-count';
    countEl.textContent = String(tile.count || 0);
    countEl.style.cssText = 'font-size:' + px(dynamicCountSize) + ';color:' + textColor + ';';
    tileEl.appendChild(countEl);

    // Symbols — wrap theo hàng ngang, tính số tối đa hiển thị theo diện tích khả dụng
    const reserved   = labelSize * 1.8 + dynamicCountSize * 1.4 + 24;
    const availH     = Math.max(0, size.height - reserved);
    const availW     = Math.max(60, size.width - 24);          // trừ padding 2 bên
    const lineH      = symbolSize * 1.55;
    const symW       = symbolSize * 4.2;                        // ước lượng width 1 mã ~3 ký tự + gap
    const colsPerRow = Math.max(1, Math.floor(availW / symW));
    const rowCount   = Math.max(1, Math.floor(availH / lineH));
    const maxVisible = colsPerRow * rowCount;

    const symsEl = document.createElement('div');
    symsEl.className = 'lcni-heatmap-symbols';
    symsEl.style.cssText = 'font-size:' + px(symbolSize) + ';color:' + textColor + ';';

    (tile.symbols || []).slice(0, maxVisible).forEach((sym) => {
      const symEl = document.createElement('span');
      symEl.className = 'lcni-heatmap-symbol';
      symEl.textContent = sym;
      if (detailBase && sym) {
        symEl.addEventListener('click', (e) => {
          e.stopPropagation();
          window.location.href = detailBase + '?symbol=' + encodeURIComponent(sym);
        });
      }
      symsEl.appendChild(symEl);
    });

    tileEl.appendChild(symsEl);
    return tileEl;
  }

  function render(host, tiles) {
    host.innerHTML = '';

    if (!tiles || tiles.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'lcni-heatmap-empty';
      empty.textContent = 'Chưa có dữ liệu heatmap. Vui lòng cấu hình các ô trong Admin → Heatmap Filter.';
      host.appendChild(empty);
      return;
    }

    const gap        = Number(settings.gap || 6);
    const containerW = host.clientWidth  || window.innerWidth;
    // Mobile: height = từ top host đến bottom viewport (đo bằng CSS var)
    const isMobile   = window.innerWidth <= 768;
    let containerH;
    if (isMobile) {
      const avail = parseFloat(host.style.getPropertyValue('--lcni-hm-avail'));
      containerH = (avail > 100) ? avail : (window.innerHeight - host.getBoundingClientRect().top);
      containerH = Math.max(300, Math.round(containerH));
    } else {
      containerH = host.clientHeight || window.innerHeight;
    }

    // Ẩn ô không có mã nào, sort DESC để ô nhiều mã to hơn
    const sorted = [...tiles]
      .filter((t) => (t.count || 0) > 0)
      .sort((a, b) => (b.count || 0) - (a.count || 0));

    if (sorted.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'lcni-heatmap-empty';
      empty.textContent = 'Hiện chưa có mã nào thỏa tiêu chí. Heatmap sẽ tự cập nhật khi có dữ liệu.';
      host.appendChild(empty);
      return;
    }

    const grid = document.createElement('div');
    grid.className = 'lcni-heatmap-grid';
    grid.style.cssText = 'gap:' + px(gap) + ';padding:' + px(Math.floor(gap / 2)) + ';';

    const sizes = computeSizes(sorted, containerW - gap, containerH - gap, gap);

    sorted.forEach((tile, i) => {
      const size = sizes[i] || { width: 100, height: 100 };
      grid.appendChild(renderTile(tile, size));
    });

    host.appendChild(grid);
  }

  /* ── skeleton loader ─────────────────────────────────────────────────── */

  function showSkeleton(host) {
    host.innerHTML = '';
    host.classList.add('is-loading');

    const wrap = document.createElement('div');
    wrap.className = 'lcni-heatmap-skeleton-wrap';

    // Generate 4 placeholder tiles with varied widths for realism
    const fakeWeights = [3, 2, 1.5, 1];
    const totalW = fakeWeights.reduce((a, b) => a + b, 0);
    const gap = Number(settings.gap || 6);
    const containerW = host.clientWidth  || window.innerWidth;
    const _avail = parseFloat(host.style.getPropertyValue('--lcni-hm-avail'));
    const containerH = window.innerWidth <= 768
      ? Math.max(300, _avail > 100 ? _avail : Math.round(window.innerHeight - host.getBoundingClientRect().top))
      : (host.clientHeight || window.innerHeight);

    fakeWeights.forEach((w) => {
      const tile = document.createElement('div');
      tile.className = 'lcni-heatmap-skeleton-tile';
      const frac = w / totalW;
      // Simple 2-column-ish layout for skeleton
      const tw = containerW * frac - gap;
      const th = containerH * 0.48 - gap;
      tile.style.cssText = [
        'width:'  + Math.round(tw) + 'px',
        'height:' + Math.round(th) + 'px',
        'border-radius:' + Number(settings.border_radius || 8) + 'px',
      ].join(';');
      wrap.appendChild(tile);
    });

    // Spinner overlay
    const spinner = document.createElement('div');
    spinner.className = 'lcni-heatmap-spinner-wrap';
    spinner.innerHTML =
      '<div class="lcni-heatmap-spinner"></div>' +
      '<p class="lcni-heatmap-spinner-text">Đang tải dữ liệu...</p>';

    host.appendChild(wrap);
    host.appendChild(spinner);
  }

  /* ── data fetch ───────────────────────────────────────────────────────── */

  async function fetchAndRender(host) {
    showSkeleton(host);
    try {
      const headers = { 'Content-Type': 'application/json' };
      if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
      const res  = await fetch(cfg.restUrl || '', {
        credentials: 'same-origin',
        headers: headers,
      });
      const json = await res.json();
      if (!res.ok) throw new Error((json && json.message) || 'Lỗi tải dữ liệu');
      render(host, (json && json.tiles) || []);
    } catch (err) {
      host.innerHTML = '<div class="lcni-heatmap-empty">Không thể tải dữ liệu: ' + esc(String(err.message || err)) + '</div>';
    } finally {
      host.classList.remove('is-loading');
    }
  }

  /* ── boot ─────────────────────────────────────────────────────────────── */

  function boot() {
    document.querySelectorAll('[data-lcni-heatmap]').forEach((host) => {
      // Measure natural position first, then fix to fill remaining viewport
      applyFullscreenPosition(host);

      // Initial data load
      fetchAndRender(host);

      // Re-render on viewport resize
      let resizeTimer;
      window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
          applyFullscreenPosition(host);
          fetchAndRender(host);
        }, 200);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

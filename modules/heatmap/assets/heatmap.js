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

  /* ── build filter URL ─────────────────────────────────────────────────── */
  /**
   * Chuyển filter data từ tile thành URL query string để mở trang filter.
   * Format: ?filters=[{"column":"...","operator":"...","value":"..."}]
   */
  function buildFilterUrl(tileFilter) {
    const base = String(cfg.filterPageUrl || '');
    if (!base || !tileFilter || !tileFilter.length) return '';
    try {
      const encoded = encodeURIComponent(JSON.stringify(tileFilter));
      return base + (base.includes('?') ? '&' : '?') + 'filters=' + encoded;
    } catch (e) {
      return '';
    }
  }

  /* ── full-screen positioning ──────────────────────────────────────────── */

  function applyFullscreenPosition(host) {
    if (window.innerWidth <= 768) {
      // Mobile: position:relative, height = auto (expand theo content)
      // Xóa bỏ height cố định — grid tự mở rộng theo tiles
      host.style.removeProperty('--lcni-hm-avail');
      return;
    }

    host.style.position = 'static';
    host.style.width    = '100%';
    host.style.height   = '1px';

    const rect = host.getBoundingClientRect();
    const top  = Math.round(rect.top  + window.scrollY - window.scrollY);
    const left = Math.round(rect.left);

    host.style.setProperty('--lcni-hm-top',  px(top));
    host.style.setProperty('--lcni-hm-left', px(left));
    host.style.removeProperty('position');
    host.style.removeProperty('width');
    host.style.removeProperty('height');
  }

  /* ── treemap-style sizing ─────────────────────────────────────────────── */

  function computeSizes(tiles, containerW, containerH, gap) {
    const n = tiles.length;
    if (n === 0) return [];

    const labelSize  = Number(settings.label_font_size  || 13);
    const countSize  = Number(settings.count_font_size  || 42);
    const symbolSize = Number(settings.symbol_font_size || 13);

    // Min height để hiển thị đủ: label + count + ít nhất 1 hàng symbol + padding
    const minTileH = Math.ceil(labelSize * 1.8 + countSize * 1.4 + symbolSize * 1.6 + 28);

    // Cap weight: dùng căn bậc hai để nén khoảng cách giữa ô to và nhỏ
    // → ô 26 mã không chiếm gấp 5x ô 5 mã, mà chỉ gấp ~2.3x
    const rawWeights = tiles.map((t) => Math.max(1, t.count || 1));
    const maxRaw     = Math.max(...rawWeights);
    const weights    = rawWeights.map((w) => {
      // Sqrt compression + floor để đảm bảo ô nhỏ nhất không quá nhỏ
      const sqrtW = Math.sqrt(w);
      const sqrtMax = Math.sqrt(maxRaw);
      // Map về [1, maxRatio] với maxRatio = 3.5 (ô lớn nhất tối đa 3.5x ô nhỏ nhất)
      const maxRatio = 3.5;
      return 1 + (sqrtW / sqrtMax) * (maxRatio - 1);
    });

    const totalW    = weights.reduce((a, b) => a + b, 0);
    const totalArea = containerW * containerH;
    const areas     = weights.map((w) => (w / totalW) * totalArea);

    const results = new Array(n);
    let start = 0;

    while (start < n) {
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
          width:  Math.max(80,        tw   - gap),
          height: Math.max(minTileH,  rowH - gap),
        };
      }
      start = bestEnd;
    }

    // Pass 2: enforce min height — nếu treemap cho chiều cao < minTileH
    // thì scale up proportionally để đảm bảo hiển thị đủ nội dung
    for (let i = 0; i < n; i++) {
      if (results[i] && results[i].height < minTileH) {
        results[i].height = minTileH;
      }
    }

    return results;
  }

  /* ── Mobile: compute height từ content (auto-expand) ─────────────────── */
  /**
   * Mobile layout: tiles được chia 2 cột, height của mỗi tile = auto
   * dựa trên số symbols hiển thị. Tổng height = tổng rows.
   */
  function computeMobileSizes(tiles, containerW, gap) {
    if (!tiles.length) return [];
    const colCount = containerW < 360 ? 1 : 2;
    const tileW    = Math.floor((containerW - gap * (colCount + 1)) / colCount);

    const labelSize  = Number(settings.label_font_size  || 13);
    const countSize  = Number(settings.count_font_size  || 42);
    const symbolSize = Number(settings.symbol_font_size || 13);

    return tiles.map((tile) => {
      const symCount    = (tile.symbols || []).length;
      const availW      = tileW - 24;
      const symW        = symbolSize * 4.2;
      const colsPerRow  = Math.max(1, Math.floor(availW / symW));
      const symRows     = Math.ceil(symCount / colsPerRow);
      const lineH       = symbolSize * 1.55;

      // height = label + count + symbol rows + padding
      const minH   = labelSize * 1.8 + countSize * 1.4 + 24;
      const symH   = symRows * lineH;
      const height = Math.max(minH + 16, minH + symH + 8);

      return { width: tileW, height: Math.round(height) };
    });
  }

  /* ── render tile ──────────────────────────────────────────────────────── */

  function renderTile(tile, size, isMobile) {
    const labelSize  = Number(settings.label_font_size  || 13);
    const countSize  = Number(settings.count_font_size  || 42);
    const symbolSize = Number(settings.symbol_font_size || 13);
    const textColor  = String(tile.text_color || '#ffffff');
    const radius     = Number(settings.border_radius || 8);
    const detailBase = String(cfg.stockDetailUrl || '');
    const filterUrl  = buildFilterUrl(tile.filter);

    const tileEl = document.createElement('div');
    tileEl.className = 'lcni-heatmap-tile';
    tileEl.style.cssText = [
      'background:'    + esc(tile.color || '#dc2626'),
      'width:'         + px(size.width),
      'height:'        + (isMobile ? 'auto' : px(size.height)),
      'min-height:'    + px(Math.min(size.height, 80)),
      'border-radius:' + px(radius),
    ].join(';');

    // Header row: label + expand button
    const headerEl = document.createElement('div');
    headerEl.className = 'lcni-heatmap-tile-header';
    headerEl.style.cssText = 'display:flex;align-items:flex-start;justify-content:space-between;gap:4px;';

    const labelEl = document.createElement('div');
    labelEl.className = 'lcni-heatmap-label';
    labelEl.textContent = tile.label || tile.column || '';
    labelEl.style.cssText = 'font-size:' + px(labelSize) + ';color:' + textColor + ';flex:1;min-width:0;';
    headerEl.appendChild(labelEl);

    // Expand button — mở trang filter với bộ lọc của tile
    if (filterUrl) {
      const expandBtn = document.createElement('a');
      expandBtn.className = 'lcni-heatmap-expand-btn';
      expandBtn.href = filterUrl;
      expandBtn.title = 'Mở bộ lọc: ' + (tile.label || tile.column || '');
      expandBtn.setAttribute('aria-label', 'Mở bộ lọc');
      expandBtn.style.cssText = [
        'display:inline-flex',
        'align-items:center',
        'justify-content:center',
        'width:20px',
        'height:20px',
        'border-radius:4px',
        'background:rgba(255,255,255,0.2)',
        'color:' + textColor,
        'text-decoration:none',
        'font-size:11px',
        'line-height:1',
        'flex-shrink:0',
        'transition:background 0.15s',
        'cursor:pointer',
      ].join(';');
      expandBtn.textContent = '↗';
      expandBtn.addEventListener('mouseenter', function() {
        this.style.background = 'rgba(255,255,255,0.35)';
      });
      expandBtn.addEventListener('mouseleave', function() {
        this.style.background = 'rgba(255,255,255,0.2)';
      });
      expandBtn.addEventListener('click', function(e) {
        e.stopPropagation();
      });
      headerEl.appendChild(expandBtn);
    }

    tileEl.appendChild(headerEl);

    // Count
    const dynamicCountSize = isMobile
      ? Math.min(countSize, Math.max(24, size.width * 0.22))
      : Math.min(countSize, Math.max(16, size.width * 0.22));
    const countEl = document.createElement('div');
    countEl.className = 'lcni-heatmap-count';
    countEl.textContent = String(tile.count || 0);
    countEl.style.cssText = 'font-size:' + px(dynamicCountSize) + ';color:' + textColor + ';';
    tileEl.appendChild(countEl);

    // Symbols
    const symsEl = document.createElement('div');
    symsEl.className = 'lcni-heatmap-symbols';
    symsEl.style.cssText = 'font-size:' + px(symbolSize) + ';color:' + textColor + ';';

    // Mobile: hiển thị toàn bộ symbols (tile auto-expand)
    // Desktop: giới hạn theo diện tích khả dụng
    let symbolsToShow;
    if (isMobile) {
      symbolsToShow = tile.symbols || [];
      // Mobile: overflow:visible để hiển thị hết
      symsEl.style.overflow = 'visible';
    } else {
      const reserved   = labelSize * 1.8 + dynamicCountSize * 1.4 + 24;
      const availH     = Math.max(0, size.height - reserved);
      const availW     = Math.max(60, size.width - 24);
      const lineH      = symbolSize * 1.55;
      const symW       = symbolSize * 4.2;
      const colsPerRow = Math.max(1, Math.floor(availW / symW));
      const rowCount   = Math.max(1, Math.floor(availH / lineH));
      const maxVisible = colsPerRow * rowCount;
      symbolsToShow = (tile.symbols || []).slice(0, maxVisible);
    }

    symbolsToShow.forEach((sym) => {
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

    // Mobile: nếu count > symbols shown, hiển thị "..." ở cuối
    if (!isMobile && (tile.symbols || []).length > symbolsToShow.length) {
      const moreEl = document.createElement('span');
      moreEl.className = 'lcni-heatmap-symbol';
      moreEl.textContent = '...';
      moreEl.style.opacity = '0.6';
      symsEl.appendChild(moreEl);
    }

    tileEl.appendChild(symsEl);
    return tileEl;
  }

  /* ── render all ───────────────────────────────────────────────────────── */

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
    const isMobile   = window.innerWidth <= 768;
    const containerW = host.clientWidth || window.innerWidth;

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

    if (isMobile) {
      // Mobile: auto-height grid, 2 cột
      const sizes = computeMobileSizes(sorted, containerW - gap, gap);
      sorted.forEach((tile, i) => {
        const size = sizes[i] || { width: Math.floor(containerW / 2) - gap, height: 120 };
        grid.appendChild(renderTile(tile, size, true));
      });
    } else {
      // Desktop: treemap fill full màn hình (host là position:fixed, tự fill viewport)
      // containerH = chiều cao thực của host (đã được CSS position:fixed set = viewport height)
      // Không set host.style.height cứng — để CSS quản lý.

      const labelSize  = Number(settings.label_font_size  || 13);
      const countSize  = Number(settings.count_font_size  || 42);
      const symbolSize = Number(settings.symbol_font_size || 13);

      // Min height mỗi tile: đủ để hiển thị label + count + ít nhất 1 hàng symbol
      const minTileH = Math.ceil(labelSize * 1.8 + countSize * 1.4 + symbolSize * 1.6 + 28);

      // containerH: ưu tiên host.clientHeight (position:fixed = viewport - topbar)
      // Nếu clientHeight vẫn = 0 sau rAF thì tính từ offsetTop
      var _hostH = host.clientHeight;
      if (_hostH < 80) {
        // Fallback mạnh: innerHeight - khoảng cách từ top của trang đến host
        var _rect = host.getBoundingClientRect();
        _hostH = window.innerHeight - Math.max(0, _rect.top);
      }
      if (_hostH < 80) _hostH = window.innerHeight * 0.85; // last resort
      const containerH = Math.max(_hostH, 200);

      const sizes = computeSizes(sorted, containerW - gap, Math.max(containerH - gap, minTileH * 2), gap);
      sorted.forEach((tile, i) => {
        const size = sizes[i] || { width: 100, height: minTileH };
        grid.appendChild(renderTile(tile, size, false));
      });
    }

    host.appendChild(grid);
  }

  /* ── skeleton loader ─────────────────────────────────────────────────── */

  function showSkeleton(host) {
    host.innerHTML = '';
    host.classList.add('is-loading');

    const wrap = document.createElement('div');
    wrap.className = 'lcni-heatmap-skeleton-wrap';

    const fakeWeights = [3, 2, 1.5, 1];
    const totalW = fakeWeights.reduce((a, b) => a + b, 0);
    const gap = Number(settings.gap || 6);
    const isMobile   = window.innerWidth <= 768;
    const containerW = host.clientWidth  || window.innerWidth;
    const containerH = isMobile ? 400 : (host.clientHeight || window.innerHeight);

    fakeWeights.forEach((w) => {
      const tile = document.createElement('div');
      tile.className = 'lcni-heatmap-skeleton-tile';
      const frac = w / totalW;
      const tw = containerW * frac - gap;
      const th = isMobile ? 120 : (containerH * 0.48 - gap);
      tile.style.cssText = [
        'width:'  + Math.round(tw) + 'px',
        'height:' + Math.round(th) + 'px',
        'border-radius:' + Number(settings.border_radius || 8) + 'px',
      ].join(';');
      wrap.appendChild(tile);
    });

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
      applyFullscreenPosition(host);

      // ── Tile cache: tránh fetch lại khi resize trên mobile ─────────────
      // Mobile browser ẩn/hiện address bar khi scroll → trigger resize
      // → fetchAndRender cũ gọi lại → user mất vị trí scroll.
      // Giải pháp: cache tiles sau lần fetch đầu, resize chỉ re-render.

      var cachedTiles  = null;
      var isDataLoaded = false;
      var lastW        = window.innerWidth;
      var resizeTimer  = null;

      // First fetch: load data + cache
      (function firstLoad() {
        showSkeleton(host);
        var headers = { 'Content-Type': 'application/json' };
        if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
        fetch(cfg.restUrl || '', { credentials: 'same-origin', headers: headers })
          .then(function(res) {
            return res.json().then(function(json) {
              if (!res.ok) throw new Error((json && json.message) || 'Lỗi tải dữ liệu');
              return (json && json.tiles) || [];
            });
          })
          .then(function(tiles) {
            cachedTiles  = tiles;
            isDataLoaded = true;
            // rAF: đảm bảo host đã vào DOM và có clientHeight thực trước khi render
            requestAnimationFrame(function() {
              requestAnimationFrame(function() {
                render(host, tiles);
              });
            });
          })
          .catch(function(err) {
            host.innerHTML = '<div class="lcni-heatmap-empty">Không thể tải dữ liệu: ' + esc(String(err.message || err)) + '</div>';
          })
          .finally(function() {
            host.classList.remove('is-loading');
          });
      })();

      window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);

        var isMobile = window.innerWidth <= 768;
        var newW     = window.innerWidth;

        // Mobile: bỏ qua resize do address bar ẩn/hiện (chỉ height thay đổi)
        if (isMobile && newW === lastW) return;
        lastW = newW;

        resizeTimer = setTimeout(function() {
          applyFullscreenPosition(host);

          if (isDataLoaded && cachedTiles) {
            // Data đã có: chỉ re-render, không fetch lại
            render(host, cachedTiles);
          } else {
            fetchAndRender(host);
          }
        }, isMobile ? 500 : 300);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

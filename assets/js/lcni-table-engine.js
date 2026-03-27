/**
 * lcni-table-engine.js — LCNI Table Engine Core v2.0
 *
 * Single source of truth cho sticky header + sticky column.
 * Tất cả module (filter, watchlist, recommend, industry) dùng chung engine này.
 *
 * API:
 *   LcniTableEngine.refresh(scrollEl, config?)   // ⭐ ENTRY POINT DUY NHẤT
 *   LcniTableEngine.observe(scrollEl, config?)   // auto-recalc khi resize
 *
 * Config (optional — nếu không truyền, engine đọc từ DOM class):
 *   { sticky_columns: 1, sticky_header: true }
 */
(function (global) {
  'use strict';

  var STICKY_CLASS = 'is-sticky-col';
  var STICKY_ALIAS = 'lcni-sticky-col';   // backward compat
  var TABLE_SEL    = '.lcni-table, .lcni-watchlist-table, .lcni-rf-signals-table';

  // ══════════════════════════════════════════════════════════════════════════
  // I. OFFSET ENGINE (production-safe: dùng nth-child theo column index)
  // ══════════════════════════════════════════════════════════════════════════
  function applyStickyOffsets(scrollEl, config) {
    if (!scrollEl) return;

    var table = scrollEl.querySelector(TABLE_SEL);
    if (!table) return;

    // Xác định số cột sticky
    var stickyCount = (config && config.sticky_columns != null)
      ? Number(config.sticky_columns)
      : _detectStickyCount(table);

    if (stickyCount <= 0) return;

    var offset = 0;

    for (var colIndex = 0; colIndex < stickyCount; colIndex++) {
      // Lấy TẤT CẢ cells theo column index (nth-child) — không phụ thuộc DOM order
      var cells = table.querySelectorAll(
        'tr > *:nth-child(' + (colIndex + 1) + ')'
      );
      if (!cells.length) break;

      // Reset left để đo chính xác
      cells.forEach(function (c) { c.style.left = ''; });

      // maxWidth: lấy max của tất cả cells trong cột (header thường rộng nhất)
      var maxWidth = 0;
      cells.forEach(function (c) {
        if (c.offsetWidth > maxWidth) maxWidth = c.offsetWidth;
      });
      if (!maxWidth) maxWidth = 80; // fallback

      // Gán left = offset tích lũy
      cells.forEach(function (c) {
        c.style.left = offset + 'px';
      });

      offset += maxWidth;
    }
  }

  // Detect sticky count từ DOM class (khi không truyền config)
  function _detectStickyCount(table) {
    var headerRow = table.querySelector('thead tr');
    if (!headerRow) return 0;
    var count = 0;
    var cells = headerRow.children;
    for (var i = 0; i < cells.length; i++) {
      if (cells[i].classList.contains(STICKY_CLASS) ||
          cells[i].classList.contains(STICKY_ALIAS)) {
        count++;
      } else {
        break; // sticky cols phải liên tiếp từ đầu
      }
    }
    return count;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // II. APPLY STICKY CLASSES (dựa vào config.sticky_columns — không phụ thuộc DOM)
  // ══════════════════════════════════════════════════════════════════════════
  function applyStickyClasses(scrollEl, config) {
    if (!scrollEl) return;

    var table = scrollEl.querySelector(TABLE_SEL);
    if (!table) return;

    var stickyCount = (config && config.sticky_columns != null)
      ? Number(config.sticky_columns)
      : 0;

    // Xóa class cũ trước (tránh stale khi column reorder)
    table.querySelectorAll('.' + STICKY_CLASS).forEach(function (c) {
      c.classList.remove(STICKY_CLASS);
      c.classList.remove(STICKY_ALIAS);
    });

    if (stickyCount <= 0) return;

    // Gán theo nth-child index — không phụ thuộc DOM visible state
    for (var i = 1; i <= stickyCount; i++) {
      table.querySelectorAll('tr > *:nth-child(' + i + ')')
        .forEach(function (cell) {
          cell.classList.add(STICKY_CLASS);
          cell.classList.add(STICKY_ALIAS);
        });
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  // III. REFRESH — entry point duy nhất (lifecycle hook trung tâm)
  // Gọi sau: render, filter change, column toggle, data reload, tab switch
  // ══════════════════════════════════════════════════════════════════════════
  function refresh(scrollEl, config) {
    if (!scrollEl) return;

    // Nếu config truyền vào → apply sticky classes trước
    if (config && config.sticky_columns != null) {
      applyStickyClasses(scrollEl, config);
    }

    // Apply offset sau khi browser layout xong
    requestAnimationFrame(function () {
      applyStickyOffsets(scrollEl, config);
    });
  }

  // ══════════════════════════════════════════════════════════════════════════
  // IV. MOBILE GUARD — neutralize transforms / contain từ Elementor/WP wrappers
  // ══════════════════════════════════════════════════════════════════════════
  function _applyMobileGuard(scrollEl) {
    if (!scrollEl) return;
    // Các property này do Elementor/WP theme inject có thể phá sticky:
    //   transform   → tạo stacking context mới → position:sticky scope bị kẹt
    //   contain     → contain:strict/layout tạo scroll context riêng
    //   isolation   → isolation:isolate tạo stacking context
    //   will-change → will-change:transform tạo compositing layer (iOS Safari)
    scrollEl.style.transform  = 'none';
    scrollEl.style.contain    = 'none';
    scrollEl.style.isolation  = 'auto';
    scrollEl.style.willChange = 'auto';
    // -webkit-overflow-scrolling:touch tạo isolated GPU layer trên iOS
    // → sticky bị nuốt vào layer → dùng 'auto' thay thế
    scrollEl.style.webkitOverflowScrolling = 'auto';
  }

  // ══════════════════════════════════════════════════════════════════════════
  // V. OBSERVE — auto-recalc khi resize / xoay màn hình
  // ══════════════════════════════════════════════════════════════════════════
  var _observed = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

  function observe(scrollEl, config) {
    if (!scrollEl) return;

    _applyMobileGuard(scrollEl);

    // Lưu config để dùng lại khi resize
    if (_observed) _observed.set(scrollEl, config || null);

    if (typeof ResizeObserver !== 'undefined') {
      var ro = new ResizeObserver(function () {
        var cfg = _observed ? _observed.get(scrollEl) : null;
        applyStickyOffsets(scrollEl, cfg);
      });
      ro.observe(scrollEl);

      var table = scrollEl.querySelector(TABLE_SEL);
      if (table) ro.observe(table);
    }
  }

  // ── Global resize fallback (debounced 150ms) ──────────────────────────────
  var _timer;
  window.addEventListener('resize', function () {
    clearTimeout(_timer);
    _timer = setTimeout(function () {
      document.querySelectorAll(
        '.lcni-table-scroll, .lcni-table-wrapper, .lcni-watchlist-table-wrap'
      ).forEach(function (el) {
        var cfg = _observed ? _observed.get(el) : null;
        applyStickyOffsets(el, cfg);
      });
    }, 150);
  });

  // ── orientationchange (iOS) ───────────────────────────────────────────────
  window.addEventListener('orientationchange', function () {
    setTimeout(function () {
      document.querySelectorAll(
        '.lcni-table-scroll, .lcni-table-wrapper, .lcni-watchlist-table-wrap'
      ).forEach(function (el) {
        var cfg = _observed ? _observed.get(el) : null;
        applyStickyOffsets(el, cfg);
      });
    }, 300); // iOS cần delay sau orientationchange
  });

  // ── Auto-init ─────────────────────────────────────────────────────────────
  function autoInit() {
    document.querySelectorAll(
      '.lcni-table-scroll, .lcni-table-wrapper, .lcni-watchlist-table-wrap'
    ).forEach(function (el) {
      _applyMobileGuard(el);
      refresh(el);
      observe(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

  // ══════════════════════════════════════════════════════════════════════════
  // VI. PUBLIC API
  // ══════════════════════════════════════════════════════════════════════════
  global.LcniTableEngine = {
    /**
     * ⭐ Entry point duy nhất — gọi sau mọi sự kiện thay đổi table
     * @param {Element} scrollEl  — scroll container (.lcni-table-scroll etc.)
     * @param {Object}  config    — optional: { sticky_columns: 1, sticky_header: true }
     */
    refresh: refresh,

    /**
     * Đăng ký auto-recalc khi resize/orientationchange
     */
    observe: observe,

    /**
     * Thấp hơn — dùng khi chỉ cần tính lại offset (không re-apply class)
     */
    recalcOffsets: function (scrollEl, config) {
      requestAnimationFrame(function () {
        applyStickyOffsets(scrollEl, config);
      });
    },

    /**
     * Thấp hơn — apply sticky class theo config
     */
    applyClasses: applyStickyClasses,
  };

})(window);

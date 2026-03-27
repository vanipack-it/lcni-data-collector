/**
 * lcni-add-tx-widget.js  v3.0 — Pure UI Trigger
 * ─────────────────────────────────────────────────────────────
 * This file is a UI trigger ONLY. It does NOT:
 *   - submit REST requests
 *   - validate transactions
 *   - manage modal state
 *
 * All logic lives in LCNITransactionController.
 *
 * Entry points:
 *   [lcni_add_transaction]       → inline button → openModal()
 *   [lcni_add_transaction_float] → floating button → openModal()
 * ─────────────────────────────────────────────────────────────
 */
(function () {
  'use strict';

  var esc = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    });
  };

  function openModal(opts) {
    if (window.LCNITransactionController) {
      window.LCNITransactionController.openModal(opts || {});
    } else {
      console.warn('[lcni-add-tx-widget] LCNITransactionController not available.');
    }
  }

  /* ── [lcni_add_transaction] inline button ─────────────── */
  function initInlineButton(container) {
    var label = container.dataset.label || '＋ Thêm giao dịch';
    container.innerHTML =
      '<div class="lcni-atx-inline-trigger">' +
        '<button type="button" class="lcni-tx-btn lcni-tx-btn-primary lcni-atx-open-btn">' +
          esc(label) +
        '</button>' +
      '</div>';

    var btn = container.querySelector('.lcni-atx-open-btn');
    if (btn) {
      btn.addEventListener('click', function () { openModal(); });
      btn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') openModal();
      });
    }
  }

  /* ── [lcni_add_transaction_float] floating button ───────── */
  function initFloatButton(container) {
    var edge      = container.dataset.edge      || 'right';
    var offset    = container.dataset.offset    || '50%';
    var label     = container.dataset.label     || '＋ GD';
    var collapsed = container.dataset.collapsed !== 'false';

    var btn = document.createElement('div');
    btn.className  = 'lcni-atx-float lcni-atx-float--' + edge;
    btn.style.top  = offset;
    btn.setAttribute('title', 'Thêm giao dịch nhanh');
    btn.setAttribute('role', 'button');
    btn.setAttribute('tabindex', '0');
    btn.setAttribute('aria-label', 'Thêm giao dịch nhanh');
    btn.innerHTML  =
      '<span class="lcni-atx-float-icon">📈</span>' +
      '<span class="lcni-atx-float-label">' + esc(label) + '</span>';

    if (collapsed) btn.classList.add('lcni-atx-float--collapsed');
    document.body.appendChild(btn);

    btn.addEventListener('click', function () { openModal(); });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') openModal();
    });

    btn.addEventListener('mouseenter', function () { btn.classList.remove('lcni-atx-float--collapsed'); });
    btn.addEventListener('mouseleave', function () {
      if (collapsed) btn.classList.add('lcni-atx-float--collapsed');
    });

    /* Drag vertical */
    var dragging = false, startY = 0, startTop = 0;
    btn.addEventListener('mousedown', function (e) {
      if (e.button !== 0) return;
      dragging = true; startY = e.clientY; startTop = btn.getBoundingClientRect().top;
      e.preventDefault();
    });
    document.addEventListener('mousemove', function (e) {
      if (!dragging) return;
      btn.style.top = Math.max(10, Math.min(window.innerHeight - 60, startTop + (e.clientY - startY))) + 'px';
    });
    document.addEventListener('mouseup', function () { dragging = false; });
    btn.addEventListener('touchstart', function (e) {
      dragging = true; startY = e.touches[0].clientY; startTop = btn.getBoundingClientRect().top;
    }, { passive: true });
    document.addEventListener('touchmove', function (e) {
      if (!dragging) return;
      btn.style.top = Math.max(10, Math.min(window.innerHeight - 60, startTop + (e.touches[0].clientY - startY))) + 'px';
    }, { passive: true });
    document.addEventListener('touchend', function () { dragging = false; });
  }

  /* ── Boot ───────────────────────────────────────────────── */
  function boot() {
    document.querySelectorAll('[data-lcni-add-tx-inline]').forEach(initInlineButton);
    document.querySelectorAll('[data-lcni-add-tx-float]').forEach(initFloatButton);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

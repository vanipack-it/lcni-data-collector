/**
 * lcni-transaction-controller.js  v4.1
 * ─────────────────────────────────────────────────────────────
 * LCNITransactionController — UNIFIED transaction entry point.
 *
 * Architecture: ONE controller · ONE modal · ONE submit pipeline
 *
 * All UI triggers must call:
 *   LCNITransactionController.openModal({ symbol, price, type, portfolioId })
 *
 * Public API (window.LCNITransactionController):
 *   .init()
 *   .openModal({ symbol, price, type, portfolioId })
 *   .closeModal()
 *   .bindEvents()
 *   .validate(fields)
 *   .handleOrderTypeChange()
 *   .submit()
 *
 * Pipeline:
 *   openModal()
 *     → validate()
 *     → submit()
 *         → if Manual  : LCNIOrderService.savePortfolioTx()  → REST /portfolio/tx/add
 *         → if Broker  : DNSEOrderService.sendOrder()        → DNSE API
 *         → updatePortfolio() → lcniPortfolioReload() + lcniTxAdded event
 *
 * Fires CustomEvent 'lcniTxAdded' after successful save.
 * ─────────────────────────────────────────────────────────────
 */
(function () {
  'use strict';

  /* ── Config injected by PHP ──────────────────────────────── */
  const CFG = window.lcniPortfolioConfig
           || window.lcniTxControllerConfig
           || window.lcniAddTxConfig
           || {};

  if (!CFG.restUrl) return;

  /* ── Price cell fields recognised as close price ─────────── */
  const PRICE_FIELDS = new Set([
    'close_price', 'close', 'price', 'reference_price',
    'match_price', 'current_price', 'gia', 'tc'
  ]);

  /* ── Market order types (price disabled for these) ───────── */
  const MARKET_ORDER_TYPES = new Set(['ATO', 'ATC', 'MP', 'PM']);

  /* ── HTML escape ─────────────────────────────────────────── */
  const esc = v => String(v ?? '').replace(/[&<>"']/g, m =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])
  );

  /* ── Number helpers ──────────────────────────────────────── */
  function fmtVnd(n) {
    n = parseFloat(n) || 0;
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2) + ' tỷ';
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1) + ' tr';
    return n.toLocaleString('vi-VN') + ' đ';
  }

  function vndToDb(p) { return (parseFloat(p) / 1000).toFixed(4); }

  /* ── DOM refs ────────────────────────────────────────────── */
  let _modal   = null;
  let _overlay = null;
  let _built   = false;

  /* ═══════════════════════════════════════════════════════════
     BUILD MODAL  (once per page — lazy on first openModal())
  ═══════════════════════════════════════════════════════════ */
  function buildModal() {
    if (_built) return;
    _built = true;

    const portfolioOptions = (CFG.portfolios || [])
      .map(p => `<option value="${esc(p.id)}"${p.is_default ? ' selected' : ''}>${esc(p.name)}</option>`)
      .join('');

    const html = `
      <div id="lcni-tx-overlay" class="lcni-tx-overlay" aria-hidden="true"></div>
      <div id="lcni-tx-modal" class="lcni-tx-modal"
           role="dialog" aria-modal="true" aria-labelledby="lcni-tx-modal-title"
           style="display:none;">
        <div class="lcni-tx-modal-box">
          <div class="lcni-tx-modal-header">
            <h3 id="lcni-tx-modal-title" class="lcni-tx-modal-title">＋ Thêm giao dịch</h3>
            <button type="button" class="lcni-tx-close" id="lcni-tx-close" aria-label="Đóng">✕</button>
          </div>
          <div class="lcni-tx-modal-body">

            <div class="lcni-tx-form-group lcni-tx-full">
              <label class="lcni-tx-label" for="lcni-tx-portfolio">
                Danh mục <span class="lcni-tx-req" aria-hidden="true">*</span>
              </label>
              <select id="lcni-tx-portfolio" class="lcni-tx-input lcni-tx-select">
                ${portfolioOptions || '<option value="">— Chưa có danh mục —</option>'}
              </select>
            </div>

            <div class="lcni-tx-form-row">
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-symbol">
                  Mã CP <span class="lcni-tx-req" aria-hidden="true">*</span>
                </label>
                <input id="lcni-tx-symbol" class="lcni-tx-input lcni-tx-symbol-input"
                       type="text" maxlength="10" placeholder="VNM, HPG..."
                       autocomplete="off" inputmode="text" style="text-transform:uppercase;" />
              </div>
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-type">
                  Loại GD <span class="lcni-tx-req" aria-hidden="true">*</span>
                </label>
                <select id="lcni-tx-type" class="lcni-tx-input lcni-tx-select">
                  <option value="buy">🟢 Mua</option>
                  <option value="sell">🔴 Bán</option>
                  <option value="dividend">💰 Cổ tức</option>
                  <option value="fee">💸 Phí</option>
                </select>
              </div>
            </div>

            <div class="lcni-tx-form-row">
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-order-type">Loại lệnh</label>
                <select id="lcni-order-type" class="lcni-tx-input lcni-tx-select">
                  <option value="Manual">Manual — Thủ công</option>
                  <option value="LO">LO — Giới hạn</option>
                  <option value="ATO">ATO — Mở cửa</option>
                  <option value="ATC">ATC — Đóng cửa</option>
                  <option value="MP">MP — Thị trường (KL liên tục)</option>
                  <option value="PM">PM — Thị trường (phiên chiều)</option>
                </select>
              </div>
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-date">
                  Ngày <span class="lcni-tx-req" aria-hidden="true">*</span>
                </label>
                <input id="lcni-tx-date" class="lcni-tx-input" type="date" />
              </div>
            </div>

            <div class="lcni-tx-form-row">
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-qty">
                  Khối lượng <span class="lcni-tx-req" aria-hidden="true">*</span>
                </label>
                <input id="lcni-tx-qty" class="lcni-tx-input" type="number"
                       min="0" step="100" placeholder="1000" inputmode="numeric" />
              </div>
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-price">
                  Giá (VNĐ) <span class="lcni-tx-req" aria-hidden="true">*</span>
                </label>
                <input id="lcni-tx-price" class="lcni-tx-input" type="number"
                       min="0" step="100" placeholder="21500" inputmode="numeric" />
              </div>
            </div>

            <div class="lcni-tx-form-row">
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-fee">Phí (VNĐ)</label>
                <input id="lcni-tx-fee" class="lcni-tx-input" type="number"
                       min="0" value="0" placeholder="0" inputmode="numeric" />
              </div>
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-tax">
                  Thuế (VNĐ) <span class="lcni-tx-hint">0.1% khi bán</span>
                </label>
                <input id="lcni-tx-tax" class="lcni-tx-input" type="number"
                       min="0" value="0" placeholder="0" inputmode="numeric" />
              </div>
            </div>

            <div class="lcni-tx-form-row">
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label">Tổng ước tính</label>
                <div id="lcni-tx-total" class="lcni-tx-total-preview" aria-live="polite">—</div>
              </div>
              <div class="lcni-tx-form-group">
                <label class="lcni-tx-label" for="lcni-tx-note">Ghi chú</label>
                <input id="lcni-tx-note" class="lcni-tx-input" type="text" placeholder="Tuỳ chọn..." />
              </div>
            </div>

            <div id="lcni-tx-dnse-section" class="lcni-tx-dnse-section" style="display:none;">
              <div class="lcni-tx-dnse-divider"><span>⚡ Đặt lệnh thực qua DNSE</span></div>
              <div class="lcni-tx-form-row">
                <div class="lcni-tx-form-group">
                  <label class="lcni-tx-label" for="lcni-tx-dnse-account">Tài khoản DNSE</label>
                  <select id="lcni-tx-dnse-account" class="lcni-tx-input lcni-tx-select"></select>
                </div>
                <div class="lcni-tx-form-group">
                  <label class="lcni-tx-label" for="lcni-tx-dnse-order-type">Loại lệnh DNSE</label>
                  <select id="lcni-tx-dnse-order-type" class="lcni-tx-input lcni-tx-select">
                    <option value="LO">LO — Giới hạn</option>
                    <option value="ATO">ATO — Mở cửa</option>
                    <option value="ATC">ATC — Đóng cửa</option>
                    <option value="MP">MP — Thị trường (KL liên tục)</option>
                    <option value="PM">PM — Thị trường (phiên chiều)</option>
                  </select>
                </div>
              </div>
              <div class="lcni-tx-dnse-note">
                Lệnh sẽ được gửi tới DNSE <strong>và</strong> lưu vào danh mục cùng lúc.
              </div>
              <label class="lcni-tx-dnse-toggle">
                <input type="checkbox" id="lcni-tx-dnse-send" checked>
                Gửi lệnh thực tới DNSE
              </label>
            </div>

            <div id="lcni-tx-error" class="lcni-tx-error" role="alert" style="display:none;"></div>
          </div>

          <div class="lcni-tx-modal-footer">
            <button type="button" class="lcni-tx-btn lcni-tx-btn-ghost" id="lcni-tx-cancel">Huỷ</button>
            <button type="button" class="lcni-tx-btn lcni-tx-btn-primary" id="lcni-tx-save">💾 Lưu giao dịch</button>
          </div>
        </div>
      </div>
    `;

    const wrap = document.createElement('div');
    wrap.innerHTML = html;
    while (wrap.firstChild) document.body.appendChild(wrap.firstChild);

    _modal   = document.getElementById('lcni-tx-modal');
    _overlay = document.getElementById('lcni-tx-overlay');

    _bindModalEvents();
  }

  /* ═══════════════════════════════════════════════════════════
     LCNIDNSEAuth — DNSE connection status helper
  ═══════════════════════════════════════════════════════════ */
  var LCNIDNSEAuth = {
    isConnected: function () {
      return Array.isArray(CFG.dnseAccounts) && CFG.dnseAccounts.length > 0;
    },
  };

  /* ═══════════════════════════════════════════════════════════
     OPEN / CLOSE
  ═══════════════════════════════════════════════════════════ */

  /**
   * openModal({ symbol, price, type, portfolioId })
   *
   * Central entry point for ALL UI triggers.
   * Delegates to lcniOpenPortfolioTxModal() when portfolio.js
   * is on the page (full DNSE support), otherwise uses fallback modal.
   */
  function openModal({ symbol = '', price = '', type = 'buy', portfolioId = null } = {}) {
    if (typeof window.lcniOpenPortfolioTxModal === 'function') {
      window.lcniOpenPortfolioTxModal({ symbol, price, type, portfolioId });
      return;
    }

    buildModal();

    _f('lcni-tx-symbol').value  = String(symbol).toUpperCase();
    _f('lcni-tx-type').value    = type || 'buy';
    _f('lcni-tx-price').value   = price || '';
    _f('lcni-tx-qty').value     = '';
    _f('lcni-tx-fee').value     = '0';
    _f('lcni-tx-tax').value     = '0';
    _f('lcni-tx-note').value    = '';
    _f('lcni-tx-date').value    = _today();
    _f('lcni-order-type').value = 'Manual';
    _f('lcni-tx-error').style.display = 'none';

    // Apply order-type price rules
    handleOrderTypeChange();

    if (portfolioId) {
      const opt = _f('lcni-tx-portfolio')?.querySelector(`option[value="${portfolioId}"]`);
      if (opt) _f('lcni-tx-portfolio').value = portfolioId;
    }

    _f('lcni-tx-symbol').readOnly = !!symbol;
    _updateTotal();

    _modal.style.display = 'flex';
    _overlay.classList.add('lcni-tx-overlay--visible');
    document.body.classList.add('lcni-tx-body-lock');

    setTimeout(() => {
      const focus = symbol ? _f('lcni-tx-qty') : _f('lcni-tx-symbol');
      focus && focus.focus();
    }, 60);
  }

  function closeModal() {
    if (!_modal) return;
    _modal.style.display = 'none';
    _overlay && _overlay.classList.remove('lcni-tx-overlay--visible');
    document.body.classList.remove('lcni-tx-body-lock');
  }

  /* ═══════════════════════════════════════════════════════════
     TASK 4 — handleOrderTypeChange()
     Price input enable/disable based on order type.

     Manual → price enabled
     LO     → price enabled
     ATO / ATC / MP / PM → price disabled + cleared + .lcni-disabled class
  ═══════════════════════════════════════════════════════════ */
  function handleOrderTypeChange() {
    // Resolve order type from unified modal or portfolio modal
    const orderTypeEl = _f('lcni-order-type')
                     || _f('pf-dnse-order-type')
                     || _f('lcni-tx-dnse-order-type');
    const priceEl     = _f('lcni-tx-price') || _f('pf-tx-price');

    if (!orderTypeEl || !priceEl) return;

    const orderType = orderTypeEl.value || 'Manual';

    if (orderType === 'Manual' || orderType === 'LO') {
      // Price enabled
      priceEl.disabled = false;
      priceEl.classList.remove('lcni-disabled');
    } else {
      // ATO / ATC / MP / PM — price disabled
      priceEl.disabled = true;
      priceEl.classList.add('lcni-disabled');
      priceEl.value = '';
    }
  }

  /* ═══════════════════════════════════════════════════════════
     VALIDATE
  ═══════════════════════════════════════════════════════════ */

  /**
   * validate(fields) → string|null
   * Returns error message string, or null if all fields are valid.
   */
  function validate({ symbol, portfolioId, qty, priceVnd, date, orderType } = {}) {
    if (!symbol)                      return 'Vui lòng nhập mã chứng khoán.';
    if (!portfolioId)                 return 'Vui lòng chọn danh mục.';
    if (!qty || parseFloat(qty) <= 0) return 'Khối lượng phải lớn hơn 0.';
    if (!date)                        return 'Vui lòng chọn ngày giao dịch.';

    const isMarket = MARKET_ORDER_TYPES.has(orderType || '');
    if (!isMarket && (!priceVnd || priceVnd <= 0)) {
      return 'Giá phải lớn hơn 0 (lệnh giới hạn/thủ công).';
    }

    return null;
  }

  /* ═══════════════════════════════════════════════════════════
     SUBMIT — unified pipeline
     Manual  → LCNIOrderService.execute() → REST /portfolio/tx/add
     Broker  → LCNIOrderService.execute() → DNSE + REST
  ═══════════════════════════════════════════════════════════ */
  function submit() {
    // Collect
    const symbol      = (_f('lcni-tx-symbol')?.value || '').trim().toUpperCase();
    const portfolioId = parseInt(_f('lcni-tx-portfolio')?.value) || 0;
    const type        = _f('lcni-tx-type')?.value    || 'buy';
    const date        = _f('lcni-tx-date')?.value    || '';
    const qty         = _f('lcni-tx-qty')?.value     || '';
    const priceVnd    = parseFloat(_f('lcni-tx-price')?.value) || 0;
    const fee         = _f('lcni-tx-fee')?.value     || '0';
    const tax         = _f('lcni-tx-tax')?.value     || '0';
    const note        = _f('lcni-tx-note')?.value    || '';
    const orderType   = _f('lcni-order-type')?.value || 'Manual';

    const dnseSend      = !!(_f('lcni-tx-dnse-send')?.checked);
    const dnseAccountNo = _f('lcni-tx-dnse-account')?.value || '';
    const dnseOrderType = _f('lcni-tx-dnse-order-type')?.value || 'LO';
    const dnseAccountEl = _f('lcni-tx-dnse-account');
    const dnseAccountType = (dnseAccountEl?.selectedOptions[0]?.dataset.type) || 'spot';
    const sendDnse = dnseSend && !!dnseAccountNo && ['buy', 'sell'].includes(type) && orderType !== 'Manual';

    // Validate
    const error = validate({ symbol, portfolioId, qty, priceVnd, date, orderType });
    if (error) { _showError(error); return; }

    _hideError();
    _setLoading(true);

    // Build order
    const order = {
      portfolioId, symbol, type, date, qty, priceVnd, fee, tax, note,
      orderType, sendDnse, dnseAccountNo, dnseOrderType, dnseAccountType,
    };

    // Execute via unified pipeline
    const svc = window.LCNIOrderService;
    const promise = svc
      ? svc.execute(order)
      : _legacySave({ symbol, portfolioId, type, date, qty, priceVnd, fee, tax, note });

    promise
      .then(res => {
        if (res && res.success !== false) {
          closeModal();
          const label = type === 'buy' ? 'mua' : type === 'sell' ? 'bán' : type;
          _showToast(`✅ Đã lưu GD ${label} ${symbol}`);
          if (!svc) _triggerPortfolioUpdate(portfolioId, { symbol, type, priceVnd });
          if (res.dnseError) console.warn('[LCNI] DNSE thất bại:', res.dnseError);
        } else {
          _showError((res && (res.data || res.message)) || 'Lỗi không xác định.');
        }
      })
      .catch(err => {
        _showError((err && err.message) ? err.message : 'Không kết nối được server.');
      })
      .finally(() => _setLoading(false));
  }

  /* ═══════════════════════════════════════════════════════════
     LEGACY SAVE  (direct fetch — fallback when LCNIOrderService absent)
  ═══════════════════════════════════════════════════════════ */
  function _legacySave({ symbol, portfolioId, type, date, qty, priceVnd, fee, tax, note }) {
    return fetch(CFG.restUrl + '/portfolio/tx/add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      credentials: 'same-origin',
      body: JSON.stringify({
        portfolio_id: portfolioId, symbol, type,
        trade_date: date, quantity: qty,
        price: vndToDb(priceVnd), fee, tax, note,
      }),
    }).then(r => r.json());
  }

  /* ═══════════════════════════════════════════════════════════
     PORTFOLIO UPDATE HOOKS
  ═══════════════════════════════════════════════════════════ */
  function _triggerPortfolioUpdate(portfolioId, order) {
    if (window.LCNIOrderService) {
      window.LCNIOrderService.updatePortfolio(portfolioId, order);
      return;
    }
    if (typeof window.lcniPortfolioReload === 'function') window.lcniPortfolioReload();
    window.dispatchEvent(new CustomEvent('lcniTxAdded', {
      detail: { portfolioId, symbol: order.symbol, type: order.type, price: order.priceVnd },
    }));
  }

  /* ═══════════════════════════════════════════════════════════
     LIVE TOTAL PREVIEW
  ═══════════════════════════════════════════════════════════ */
  function _updateTotal() {
    if (!_modal) return;
    const qty   = parseFloat(_f('lcni-tx-qty')?.value)   || 0;
    const price = parseFloat(_f('lcni-tx-price')?.value) || 0;
    const fee   = parseFloat(_f('lcni-tx-fee')?.value)   || 0;
    const tax   = parseFloat(_f('lcni-tx-tax')?.value)   || 0;
    const type  = _f('lcni-tx-type')?.value;
    const total = type === 'sell' ? qty * price - fee - tax : qty * price + fee;
    const el = _f('lcni-tx-total');
    if (el) {
      el.textContent = total > 0 ? fmtVnd(total) : '—';
      el.style.color = total > 0 ? (type === 'sell' ? '#16a34a' : '#dc2626') : '#9ca3af';
    }
  }

  function _autoCalcTax() {
    if (!_modal) return;
    const type  = _f('lcni-tx-type')?.value;
    const qty   = parseFloat(_f('lcni-tx-qty')?.value)   || 0;
    const price = parseFloat(_f('lcni-tx-price')?.value) || 0;
    const taxEl = _f('lcni-tx-tax');
    if (!taxEl) return;
    taxEl.value = (type === 'sell' && qty > 0 && price > 0)
      ? Math.round(qty * price * 0.001) : 0;
  }

  /* ═══════════════════════════════════════════════════════════
     DNSE SECTION  (fallback modal only)
  ═══════════════════════════════════════════════════════════ */
  function _initDnseSection() {
    if (!LCNIDNSEAuth.isConnected()) return;
    const accounts = CFG.dnseAccounts || [];
    const sel = _f('lcni-tx-dnse-account');
    if (sel && !sel.options.length) {
      accounts.forEach(function (a) {
        const opt = document.createElement('option');
        opt.value = a.id; opt.dataset.type = a.type; opt.textContent = a.label;
        sel.appendChild(opt);
      });
    }
    const sec = _f('lcni-tx-dnse-section');
    if (sec) sec.style.display = '';
  }

  /* ═══════════════════════════════════════════════════════════
     BIND MODAL EVENTS  (runs once inside buildModal())
  ═══════════════════════════════════════════════════════════ */
  function _bindModalEvents() {
    _f('lcni-tx-close')?.addEventListener('click', closeModal);
    _f('lcni-tx-cancel')?.addEventListener('click', closeModal);
    _overlay?.addEventListener('click', closeModal);
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && _modal?.style.display !== 'none') closeModal();
    });

    // TASK 3: order type change → price enable/disable
    _f('lcni-order-type')?.addEventListener('change', handleOrderTypeChange);
    _f('lcni-tx-dnse-order-type')?.addEventListener('change', handleOrderTypeChange);

    _initDnseSection();

    // Unified submit pipeline
    _f('lcni-tx-save')?.addEventListener('click', submit);

    ['lcni-tx-symbol', 'lcni-tx-qty', 'lcni-tx-price', 'lcni-tx-fee', 'lcni-tx-note'].forEach(id => {
      _f(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') submit(); });
    });

    ['lcni-tx-qty', 'lcni-tx-price', 'lcni-tx-fee', 'lcni-tx-tax', 'lcni-tx-type'].forEach(id => {
      _f(id)?.addEventListener('input',  _updateTotal);
      _f(id)?.addEventListener('change', _updateTotal);
    });

    ['lcni-tx-type', 'lcni-tx-qty', 'lcni-tx-price'].forEach(id => {
      _f(id)?.addEventListener('change', () => { _autoCalcTax(); _updateTotal(); });
      _f(id)?.addEventListener('input',  () => { _autoCalcTax(); _updateTotal(); });
    });

    _f('lcni-tx-symbol')?.addEventListener('input', function () {
      this.value = this.value.toUpperCase();
    });
  }

  /* ═══════════════════════════════════════════════════════════
     bindEvents()  — public, safe to call multiple times
  ═══════════════════════════════════════════════════════════ */
  function bindEvents() { buildModal(); }

  /* ═══════════════════════════════════════════════════════════
     STEP 3 — normalizePrice()
     Convert displayed price string → numeric DNSE-ready value.

     Examples:
       "25.40"   → 25.4
       "25,40"   → 25.4
       "25.400"  → 25400  (VN thousand-separator — handled by raw DB value)
       25400     → 25400
       "1,234.5" → 1234.5
  ═══════════════════════════════════════════════════════════ */
  function normalizePrice(price) {
    if (price === null || price === undefined || price === '') return '';
    let p = String(price).trim();
    // Strip any non-numeric chars except dot and comma
    // Vietnamese format: "25.400" = 25,400 (thousand sep dot) OR 25.4 (decimal dot)
    // Raw DB/API values are already numeric strings — parseFloat handles them cleanly.
    p = p.replace(/[^\d.,]/g, '');  // keep only digits, dot, comma
    // If both comma and dot present: comma is thousand sep → remove it
    if (p.includes(',') && p.includes('.')) {
      p = p.replace(/,/g, '');       // "1,234.50" → "1234.50"
    } else {
      p = p.replace(',', '.');       // "25,4" → "25.4"
    }
    const n = parseFloat(p);
    return isFinite(n) ? n : '';
  }

  /* ═══════════════════════════════════════════════════════════
     STEP 2 — _extractPriceCell(e)
     Shared helper: given a click/dblclick event, extract the
     price-cell td and return { cell, symbol, price } or null.

     Works for all table types (filter, watchlist, signal) via
     event delegation on document — so tables can rerender freely
     without re-binding listeners.
  ═══════════════════════════════════════════════════════════ */
  function _extractPriceCell(e) {
    const cell = e.target.closest('td[data-cell-field], td[data-field], td[data-lcni-field]');
    if (!cell) return null;

    const rawField = (
      cell.getAttribute('data-cell-field') ||
      cell.getAttribute('data-field') ||
      cell.getAttribute('data-lcni-field') || ''
    ).toLowerCase();
    // Normalize module-prefixed fields: "signal__current_price" → "current_price"
    // This supports filter, watchlist (no prefix) and signal tables (signal__ prefix)
    // without needing to enumerate every prefixed variant.
    const field = rawField.includes('__') ? rawField.split('__').pop() : rawField;
    if (!PRICE_FIELDS.has(field)) return null;

    // Price: prefer raw attribute value (unformatted number from API).
    // Signal table uses data-lcni-value; filter/watchlist use data-cell-value.
    const rawPrice = (
      cell.getAttribute('data-cell-value') ||
      cell.getAttribute('data-lcni-value') ||
      cell.getAttribute('data-value') ||
      cell.textContent
    ).trim();
    const price = normalizePrice(rawPrice);
    if (!price) return null;

    // Symbol: walk up to <tr> and check multiple attribute conventions
    const row = cell.closest('tr');
    const symbol = (
      // direct data-symbol on cell
      cell.getAttribute('data-symbol') ||
      // row-level: filter uses data-symbol, watchlist uses data-row-symbol,
      // signal table uses data-lcni-row-symbol
      row?.getAttribute('data-symbol') ||
      row?.getAttribute('data-row-symbol') ||
      row?.getAttribute('data-lcni-row-symbol') ||
      // fallback: find symbol cell in same row — signal uses data-lcni-field="signal__symbol"
      row?.querySelector(
        'td[data-cell-field="symbol"], td[data-lcni-field="symbol__symbol"], td[data-lcni-field="signal__symbol"]'
      )?.getAttribute('data-cell-value') ||
      row?.querySelector(
        'td[data-cell-field="symbol"], td[data-lcni-field="symbol__symbol"], td[data-lcni-field="signal__symbol"]'
      )?.getAttribute('data-lcni-value') ||
      row?.querySelector('td[data-cell-field="symbol"]')?.textContent.trim() ||
      ''
    ).trim().toUpperCase();

    return { cell, symbol, price };
  }

  /* ═══════════════════════════════════════════════════════════
     STEP 2 — _bindPriceCellClick()
     Binds single document-level delegated click listener for ALL
     price cells across filter / watchlist / signal tables.

     Called once inside init() — no duplicate listeners possible.
  ═══════════════════════════════════════════════════════════ */
  function _bindPriceCellClick() {
    document.addEventListener('click', function (e) {
      const hit = _extractPriceCell(e);
      if (!hit) return;

      const { cell, symbol, price } = hit;

      // STEP 6 — flash highlight feedback
      cell.classList.add('lcni-price-click');
      setTimeout(function () { cell.classList.remove('lcni-price-click'); }, 300);

      e.preventDefault();
      e.stopPropagation();

      openModal({ symbol, price, type: 'buy' });
    });
  }

  /* ═══════════════════════════════════════════════════════════
     TASK 5 — init()
     Initialises controller once per page.
     Safe to call multiple times (guarded by _initialized flag).
  ═══════════════════════════════════════════════════════════ */
  var _initialized = false;

  function init() {
    if (_initialized) return;
    _initialized = true;

    // STEP 2: Bind single delegated click listener for all price cells.
    // Called here (inside init) so it runs exactly once per page,
    // regardless of how many shortcodes are present.
    _bindPriceCellClick();

    // Modal DOM is built lazily on first openModal() call.
  }

  /* ── UI Helpers ──────────────────────────────────────────── */
  function _f(id) { return document.getElementById(id); }
  function _today() { return new Date().toISOString().slice(0, 10); }

  function _showError(msg) {
    const el = _f('lcni-tx-error');
    if (el) { el.textContent = msg; el.style.display = 'block'; }
  }
  function _hideError() {
    const el = _f('lcni-tx-error');
    if (el) el.style.display = 'none';
  }
  function _setLoading(on) {
    const btn = _f('lcni-tx-save');
    if (!btn) return;
    btn.disabled = on;
    btn.textContent = on ? 'Đang lưu...' : '💾 Lưu giao dịch';
  }
  function _showToast(msg) {
    const t = document.createElement('div');
    t.className   = 'lcni-tx-toast lcni-tx-toast--success';
    t.textContent = msg;
    t.setAttribute('role', 'status');
    t.setAttribute('aria-live', 'polite');
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('lcni-tx-toast--show'));
    setTimeout(() => { t.classList.remove('lcni-tx-toast--show'); setTimeout(() => t.remove(), 300); }, 2800);
  }

  /* ═══════════════════════════════════════════════════════════
     PUBLIC API
  ═══════════════════════════════════════════════════════════ */
  window.LCNITransactionController = {
    init,
    openModal,
    closeModal,
    bindEvents,
    handleOrderTypeChange,
    normalizePrice,       // STEP 3 — public: used by external callers
    validate,
    submit,
    // Backward-compat aliases
    validateTransaction:  validate,
    submitTransaction:    submit,
    saveTransaction:      _legacySave,
    updatePortfolio:      _triggerPortfolioUpdate,
  };

  window.LCNIDNSEAuth  = LCNIDNSEAuth;
  window.lcniAddTxModal = { open: openModal, close: closeModal };

  // TASK 4: Init on DOM ready with global guard.
  // window.__LCNI_TRANSACTION_INITIALIZED prevents duplicate init when
  // multiple shortcodes ([lcni_portfolio] + [lcni_add_transaction_float]) are
  // on the same page — each script tag would otherwise call init() independently.
  function _bootController() {
    if (window.__LCNI_TRANSACTION_INITIALIZED) return;
    window.__LCNI_TRANSACTION_INITIALIZED = true;
    init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _bootController);
  } else {
    _bootController();
  }

})();

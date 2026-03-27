/**
 * lcni-dnse-order.js — Giai đoạn 2
 * Tab Signals + TradeConfirmModal + Manual Order
 * Load sau lcni-dnse-trading.js
 */
(function () {
    'use strict';

    const CFG  = window.lcniDnseCfg || {};
    const T    = CFG.i18n || {};
    const BASE = CFG.apiBase || '/wp-json/lcni/v1/dnse';

    // ── API ───────────────────────────────────────────────────────────────────
    async function api(method, path, body) {
        const res = await fetch(BASE + path, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
            body: body ? JSON.stringify(body) : undefined,
        });
        // Safe parse — prevents "Unexpected token <" when backend returns HTML error page
        if (!res.ok) {
            const text = await res.text();
            throw new Error('DNSE API Error (' + res.status + '): ' + text.slice(0, 200));
        }
        return res.json();
    }
    const GET  = (path)       => api('GET',  path);
    const POST = (path, body) => api('POST', path, body || {});

    // ── Format ────────────────────────────────────────────────────────────────
    const fmt = {
        price: v => v == null ? '—' : Number(v).toFixed(1),
        qty:   v => v == null ? '—' : Math.round(Number(v)).toLocaleString('vi-VN'),
        pct:   v => v == null ? '—' : (v >= 0 ? '+' : '') + Number(v).toFixed(2) + '%',
        val:   v => v == null ? '—' : (Number(v) / 1e6).toFixed(0) + ' tr',
        date:  ts => ts ? new Date(ts * 1000).toLocaleDateString('vi-VN') : '—',
    };

    function pnlCls(v) { return v >= 0 ? 'lcni-pos' : 'lcni-neg'; }

    // =========================================================================
    // TRADE CONFIRM MODAL
    // =========================================================================

    function createModal() {
        if (document.getElementById('lcni-trade-modal')) return;

        const el = document.createElement('div');
        el.id = 'lcni-trade-modal';
        el.innerHTML = `
        <div class="lcni-modal-overlay" id="lcni-modal-overlay">
            <div class="lcni-modal-box">
                <div class="lcni-modal-header">
                    <span class="lcni-modal-title" id="lcni-modal-title">Xác nhận đặt lệnh</span>
                    <button class="lcni-modal-close" id="lcni-modal-close">✕</button>
                </div>
                <div class="lcni-modal-body" id="lcni-modal-body"></div>
                <div class="lcni-modal-footer">
                    <button class="lcni-dnse-btn lcni-dnse-btn--secondary" id="lcni-modal-cancel">Hủy</button>
                    <button class="lcni-dnse-btn lcni-dnse-btn--danger"    id="lcni-modal-confirm">Xác nhận đặt lệnh</button>
                </div>
            </div>
        </div>`;
        document.body.appendChild(el);

        document.getElementById('lcni-modal-close').addEventListener('click', closeModal);
        document.getElementById('lcni-modal-cancel').addEventListener('click', closeModal);
        document.getElementById('lcni-modal-overlay').addEventListener('click', e => {
            if (e.target.id === 'lcni-modal-overlay') closeModal();
        });
    }

    function closeModal() {
        const overlay = document.getElementById('lcni-modal-overlay');
        if (overlay) overlay.style.display = 'none';
    }

    /**
     * Hiện confirm modal và trả về Promise<boolean>
     */
    function showConfirm(orderData) {
        createModal();
        const overlay = document.getElementById('lcni-modal-overlay');
        const body    = document.getElementById('lcni-modal-body');
        const title   = document.getElementById('lcni-modal-title');
        const confirm = document.getElementById('lcni-modal-confirm');

        const isBuy  = orderData.side === 'buy';
        const val    = orderData.price * orderData.quantity * 1000;

        title.textContent = isBuy ? '🟢 Xác nhận lệnh MUA' : '🔴 Xác nhận lệnh BÁN';
        confirm.className = `lcni-dnse-btn ${isBuy ? 'lcni-dnse-btn--primary' : 'lcni-dnse-btn--danger'}`;
        confirm.textContent = isBuy ? 'Xác nhận MUA' : 'Xác nhận BÁN';

        body.innerHTML = `
        <table class="lcni-modal-table">
            <tr><td>Mã CK</td><td><strong>${orderData.symbol}</strong></td></tr>
            <tr><td>Loại lệnh</td><td>${orderData.order_type}</td></tr>
            <tr><td>Chiều</td>
                <td><strong class="${isBuy ? 'lcni-pos' : 'lcni-neg'}">${isBuy ? '▲ MUA' : '▼ BÁN'}</strong></td></tr>
            <tr><td>Giá đặt</td><td><strong>${fmt.price(orderData.price)}</strong></td></tr>
            <tr><td>Khối lượng</td><td><strong>${fmt.qty(orderData.quantity)}</strong></td></tr>
            <tr><td>Giá trị</td><td><strong>${fmt.val(val)}</strong></td></tr>
            <tr><td>Tài khoản</td><td>${orderData.account_no}</td></tr>
            ${orderData.rule_name ? `<tr><td>Rule</td><td>${orderData.rule_name}</td></tr>` : ''}
            ${orderData.sl_price ? `<tr><td>Stoploss gợi ý</td><td class="lcni-neg">${fmt.price(orderData.sl_price)}</td></tr>` : ''}
        </table>
        <div class="lcni-modal-warning">
            ⚠ Lệnh đặt xong <strong>không thể hoàn tác</strong>. Kiểm tra kỹ trước khi xác nhận.
        </div>`;

        overlay.style.display = 'flex';

        return new Promise(resolve => {
            // Remove old listeners
            const newConfirm = confirm.cloneNode(true);
            confirm.parentNode.replaceChild(newConfirm, confirm);
            newConfirm.className = `lcni-dnse-btn ${isBuy ? 'lcni-dnse-btn--primary' : 'lcni-dnse-btn--danger'}`;
            newConfirm.textContent = isBuy ? 'Xác nhận MUA' : 'Xác nhận BÁN';

            newConfirm.addEventListener('click', () => {
                closeModal();
                resolve(true);
            });

            document.getElementById('lcni-modal-cancel').addEventListener('click', () => {
                resolve(false);
            }, { once: true });
        });
    }


    // SIGNALS TAB REMOVED — tính năng này đã chuyển sang [lcni_rule_follow] Tab 2

    // =========================================================================



    // =========================================================================
    // ORDER FORM — từ Signal
    // =========================================================================

    async function openOrderFormFromSignal(signal, accounts, container) {
        const modal = await buildOrderFormModal(signal, accounts);
        if (!modal) return;

        const confirmed = await showConfirm(modal.orderData);
        if (!confirmed) return;

        const btn = container.querySelector(`[data-signal-id="${signal.signal_id}"]`);
        if (btn) { btn.disabled = true; btn.textContent = 'Đang đặt...'; }

        let res;
        try {
            res = await POST('/order', modal.orderData);
        } catch (e) {
            if (btn) { btn.disabled = false; btn.textContent = '📈 Mua'; }
            showGlobalToast(`❌ ${e.message || 'Đặt lệnh thất bại.'}`, 'error');
            return;
        }

        if (btn) { btn.disabled = false; btn.textContent = '📈 Mua'; }

        if (res.success) {
            const msg = res.message || (res.data && res.data.message) || 'Đặt lệnh thành công.';
            showGlobalToast(`✅ ${msg}`, 'success');
        } else {
            const err = res.error || res.message || 'Đặt lệnh thất bại.';
            showGlobalToast(`❌ ${err}`, 'error');
        }
    }

    async function buildOrderFormModal(signal, accounts) {
        // Hiện form chọn account + quantity trước modal confirm
        return new Promise(resolve => {
            createModal();
            const overlay = document.getElementById('lcni-modal-overlay');
            const body    = document.getElementById('lcni-modal-body');
            const title   = document.getElementById('lcni-modal-title');
            const confirm = document.getElementById('lcni-modal-confirm');

            title.textContent = `Đặt lệnh mua ${signal.symbol}`;
            confirm.textContent = 'Tiếp tục →';
            confirm.className = 'lcni-dnse-btn lcni-dnse-btn--primary';

            const acctOptions = accounts.map(a => {
                const label = a.typeName || (a.type === 'margin' ? 'Margin' : 'Thường');
                return `<option value="${a.id}" data-type="${a.type}">${a.id} — ${label}</option>`;
            }).join('');

            const suggestedQty = 100; // default, user có thể sửa

            body.innerHTML = `
            <div class="lcni-order-form">
                <div class="lcni-dnse-field">
                    <label>Tài khoản</label>
                    <select id="lcni-order-account" style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%">
                        ${acctOptions}
                    </select>
                </div>
                <div class="lcni-dnse-field">
                    <label>Giá đặt (nghìn đồng)</label>
                    <input id="lcni-order-price" type="number" step="0.1" value="${signal.suggested_price.toFixed(1)}">
                </div>
                <div class="lcni-dnse-field">
                    <label>Khối lượng (cổ phiếu)</label>
                    <input id="lcni-order-qty" type="number" step="100" min="100" value="${suggestedQty}">
                </div>
                <div class="lcni-dnse-field">
                    <label>Loại lệnh</label>
                    <select id="lcni-order-type" style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%">
                        <option value="LO">LO — Giới hạn</option>
                        <option value="ATO">ATO — Khớp ngay đầu phiên</option>
                        <option value="ATC">ATC — Khớp ngay cuối phiên</option>
                        <option value="MP">MP — Thị trường</option>
                        <option value="PM">PM — Thị trường (phiên chiều)</option>
                    </select>
                </div>
                <div style="background:rgba(34,113,177,.1);border-radius:6px;padding:10px 12px;font-size:12px;color:#8b949e;margin-top:8px">
                    <strong style="color:#e8b84b">Rule:</strong> ${signal.rule_name}<br>
                    <strong style="color:#e8b84b">Giá vào signal:</strong> ${fmt.price(signal.entry_price)}<br>
                    <strong style="color:#f87171">Stoploss gợi ý:</strong> ${fmt.price(signal.initial_sl)}
                </div>
                <div id="lcni-order-value-preview" style="text-align:right;font-size:12px;color:#6b7280;margin-top:6px"></div>
            </div>`;

            overlay.style.display = 'flex';

            // Live preview giá trị lệnh
            const priceEl = document.getElementById('lcni-order-price');
            const qtyEl   = document.getElementById('lcni-order-qty');
            function updatePreview() {
                const val = parseFloat(priceEl.value) * parseInt(qtyEl.value) * 1000;
                document.getElementById('lcni-order-value-preview').textContent =
                    isNaN(val) ? '' : `Giá trị: ${fmt.val(val)}`;
            }
            priceEl.addEventListener('input', updatePreview);
            qtyEl.addEventListener('input', updatePreview);
            updatePreview();

            // Confirm → build orderData và resolve
            const newConfirm = confirm.cloneNode(true);
            newConfirm.className = 'lcni-dnse-btn lcni-dnse-btn--primary';
            newConfirm.textContent = 'Tiếp tục →';
            confirm.parentNode.replaceChild(newConfirm, confirm);

            newConfirm.addEventListener('click', () => {
                const acctEl = document.getElementById('lcni-order-account');
                const typeEl = document.getElementById('lcni-order-type');
                const acctType = acctEl.options[acctEl.selectedIndex]?.dataset.type || 'spot';

                closeModal();
                resolve({
                    orderData: {
                        account_no:   acctEl.value,
                        account_type: acctType,
                        symbol:       signal.symbol,
                        side:         'buy',
                        order_type:   typeEl.value,
                        price:        parseFloat(priceEl.value),
                        quantity:     parseInt(qtyEl.value),
                        loan_package_id: 0,
                        signal_id:    signal.signal_id,
                        rule_name:    signal.rule_name,
                        sl_price:     signal.initial_sl,
                    }
                });
            }, { once: true });

            document.getElementById('lcni-modal-cancel').addEventListener('click', () => {
                resolve(null);
            }, { once: true });
        });
    }

    // =========================================================================
    // MANUAL ORDER TAB
    // =========================================================================

    function renderManualOrderTab(container, accounts) {
        const content = container.querySelector('.lcni-dnse-content');
        if (!content) return;

        // Label tài khoản theo chuẩn DNSE
        function acctLabel(a) {
            const label = a.typeName || (a.type === 'margin' ? 'Margin' : 'Thường');
            return `${a.id} — ${label}`;
        }

        // Render field tài khoản
        let acctField = '';
        if (accounts.length === 0) {
            acctField = `
                <input id="lcni-manual-account" type="text" placeholder="VD: 064C958993"
                    style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%;box-sizing:border-box;text-transform:uppercase">
                <input type="hidden" id="lcni-manual-account-type" value="spot">`;
        } else {
            // Luôn dùng dropdown (kể cả 1 tài khoản) để user thấy rõ đang chọn TK nào
            const opts = accounts.map(a =>
                `<option value="${a.id}" data-type="${a.type}">${acctLabel(a)}</option>`
            ).join('');
            acctField = `<select id="lcni-manual-account"
                style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%">${opts}</select>`;
        }

        content.innerHTML = `
        <div style="width:100%;max-width:100%">
            <h3 style="font-size:14px;font-weight:600;color:#e8b84b;margin:0 0 14px">✏ Đặt lệnh thủ công</h3>
            <div class="lcni-dnse-form">
                <div class="lcni-dnse-field">
                    <label>Tài khoản</label>
                    ${acctField}
                </div>
                <div class="lcni-dnse-field">
                    <label>Mã CK</label>
                    <input id="lcni-manual-symbol" type="text" placeholder="VD: VNM, HPG, TCB"
                        style="text-transform:uppercase;background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%;box-sizing:border-box">
                </div>
                <div style="display:flex;gap:10px">
                    <div class="lcni-dnse-field" style="flex:1">
                        <label>Chiều lệnh</label>
                        <select id="lcni-manual-side"
                            style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%">
                            <option value="buy">▲ MUA</option>
                            <option value="sell">▼ BÁN</option>
                        </select>
                    </div>
                    <div class="lcni-dnse-field" style="flex:1">
                        <label>Loại lệnh</label>
                        <select id="lcni-manual-type"
                            style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%">
                            <option value="LO">LO — Giới hạn</option>
                            <option value="ATO">ATO — Mở cửa</option>
                            <option value="ATC">ATC — Đóng cửa</option>
                            <option value="MP">MP — Thị trường</option>
                            <option value="PM">PM — Thị trường (phiên chiều)</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="lcni-dnse-field" style="flex:1">
                        <label>Giá (nghìn đồng)</label>
                        <input id="lcni-manual-price" type="number" step="0.1" placeholder="VD: 21.5"
                            style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%;box-sizing:border-box">
                    </div>
                    <div class="lcni-dnse-field" style="flex:1">
                        <label>Khối lượng</label>
                        <input id="lcni-manual-qty" type="number" step="100" min="100" placeholder="VD: 300"
                            style="background:#161b22;border:1px solid rgba(255,255,255,.12);border-radius:6px;color:#e6edf3;padding:8px 12px;width:100%;box-sizing:border-box">
                    </div>
                </div>
                <div id="lcni-manual-preview" style="text-align:right;font-size:12px;color:#6b7280;margin-top:2px"></div>
                <button class="lcni-dnse-btn lcni-dnse-btn--primary" id="lcni-manual-order-btn" style="margin-top:8px;width:100%">
                    Xem xác nhận →
                </button>
            </div>
        </div>`;

        // Live preview giá trị
        const priceEl = content.querySelector('#lcni-manual-price');
        const qtyEl   = content.querySelector('#lcni-manual-qty');
        const preview = content.querySelector('#lcni-manual-preview');
        function updatePreview() {
            const v = parseFloat(priceEl.value) * parseInt(qtyEl.value) * 1000;
            preview.textContent = isNaN(v) ? '' : `≈ Giá trị: ${fmt.val(v)}`;
        }
        [priceEl, qtyEl].forEach(el => el.addEventListener('input', updatePreview));

        // Ẩn/hiện giá khi chọn loại lệnh ATO/ATC/MP
        const typeEl  = content.querySelector('#lcni-manual-type');
        const priceField = priceEl.closest('.lcni-dnse-field');
        typeEl.addEventListener('change', () => {
            const noPrice = ['ATO','ATC','MP','PM'].includes(typeEl.value);
            priceField.style.opacity = noPrice ? '0.4' : '1';
            priceEl.disabled = noPrice;
            if (noPrice) priceEl.value = '0';
        });

        // Đặt lệnh
        content.querySelector('#lcni-manual-order-btn').addEventListener('click', async () => {
            const acctEl   = content.querySelector('#lcni-manual-account');
            const acctTypeEl = content.querySelector('#lcni-manual-account-type');
            // Extract bare account number — supports both plain input and labels like "RocketX Deal (0001032017)"
            const rawAcct  = acctEl.value.trim();
            const acctNo   = (function(raw) {
                const m = raw.match(/\(([^)]+)\)\s*$/);
                return (m ? m[1].trim() : raw).toUpperCase();
            })(rawAcct);
            const acctType = acctTypeEl
                ? acctTypeEl.value
                : (acctEl.options?.[acctEl.selectedIndex]?.dataset?.type || 'spot');

            const orderData = {
                account_no:      acctNo,
                account_type:    acctType,
                symbol:          content.querySelector('#lcni-manual-symbol').value.trim().toUpperCase(),
                side:            content.querySelector('#lcni-manual-side').value,
                order_type:      typeEl.value,
                price:           parseFloat(priceEl.value) || 0,
                quantity:        parseInt(qtyEl.value) || 0,
                loan_package_id: 0,
            };

            if (!orderData.account_no) {
                showGlobalToast('Vui lòng nhập số tài khoản.', 'error'); return;
            }
            if (!orderData.symbol) {
                showGlobalToast('Vui lòng nhập mã chứng khoán.', 'error'); return;
            }
            if (!['ATO','ATC','MP','PM'].includes(orderData.order_type) && orderData.price <= 0) {
                showGlobalToast('Vui lòng nhập giá lệnh.', 'error'); return;
            }
            if (orderData.quantity <= 0) {
                showGlobalToast('Vui lòng nhập khối lượng.', 'error'); return;
            }

            const confirmed = await showConfirm(orderData);
            if (!confirmed) return;

            const btn = content.querySelector('#lcni-manual-order-btn');
            btn.disabled = true;
            btn.textContent = 'Đang đặt lệnh...';

            let res;
            try {
                res = await POST('/order', orderData);
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Xem xác nhận →';
                showGlobalToast(`❌ ${e.message || 'Đặt lệnh thất bại.'}`, 'error');
                return;
            }
            btn.disabled = false;
            btn.textContent = 'Xem xác nhận →';

            if (res.success) {
                const msg = res.message || (res.data && res.data.message) || 'Đặt lệnh thành công.';
                showGlobalToast(`✅ ${msg}`, 'success');
            } else {
                const err = res.error || res.message || 'Đặt lệnh thất bại.';
                showGlobalToast(`❌ ${err}`, 'error');
            }
        });
    }


    // =========================================================================
    // GLOBAL TOAST (outside widget container)
    // =========================================================================

    function showGlobalToast(msg, type = 'success') {
        let toast = document.getElementById('lcni-global-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'lcni-global-toast';
            toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:10px 16px;border-radius:8px;font-size:13px;font-weight:500;max-width:320px;transition:opacity .25s';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.style.background = type === 'success' ? '#065f46' : '#991b1b';
        toast.style.color = '#fff';
        toast.style.opacity = '1';
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 4000);
    }

    // =========================================================================
    // EXTEND EXISTING WIDGET — inject Signals + Manual tabs
    // =========================================================================

    function extendWidget(el) {
        // Chờ widget gốc init xong
        const observer = new MutationObserver(() => {
            const tabsEl = el.querySelector('.lcni-dnse-tabs');
            if (!tabsEl || tabsEl.dataset.phase2) return;
            tabsEl.dataset.phase2 = '1';

            // Thêm 2 tab mới
            const syncBtn = tabsEl.querySelector('[data-role="sync"]');

            // Signals tab đã chuyển sang [lcni_rule_follow]
            const manualTab = document.createElement('button');
            manualTab.className = 'lcni-dnse-tab';
            manualTab.dataset.tab = 'manual';
            manualTab.textContent = '✏ Đặt lệnh';
            tabsEl.insertBefore(manualTab, syncBtn);

            // Lấy accounts từ dashboard data
            let accountsCache = [];

            // Override tab click để handle manual order tab
            [manualTab].forEach(btn => {
                btn.addEventListener('click', async () => {
                    el.querySelectorAll('.lcni-dnse-tab').forEach(b =>
                        b.classList.toggle('lcni-dnse-tab--active', b === btn)
                    );

                    if (btn.dataset.tab === 'manual') {
                        if (!accountsCache.length) {
                            try {
                                // Ưu tiên accounts đã sync từ DB (qua /dashboard)
                                const dashRes = await GET('/dashboard');
                                if (dashRes && dashRes.accounts && dashRes.accounts.length) {
                                    accountsCache = dashRes.accounts.map(a => ({
                                        id: a.account_no || '',
                                        type: a.account_type || 'spot',
                                        typeName: a.account_type_name || '',
                                    })).filter(a => a.id);
                                }
                                // Fallback: sub_accounts từ /status (raw từ DNSE, chưa sync)
                                if (!accountsCache.length) {
                                    const statusRes = await GET('/status');
                                    if (statusRes && statusRes.sub_accounts && statusRes.sub_accounts.length) {
                                        accountsCache = statusRes.sub_accounts.map(a => ({
                                            id: a.investorAccountNo || a.id || a.accountNo || '',
                                            type: a.marginAccount ? 'margin' : 'spot',
                                            typeName: a.accountTypeName || a.accountTypeBriefName || '',
                                        })).filter(a => a.id);
                                    }
                                }
                            } catch(e) {}
                        }
                        renderManualOrderTab(el, accountsCache);
                    }
                });
            });
        });

        observer.observe(el, { childList: true, subtree: true });
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    function boot() {
        document.querySelectorAll('.lcni-dnse-trading').forEach(extendWidget);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // =========================================================================
    // DNSEOrderService — global singleton used by LCNIOrderService.sendDnseOrder()
    //
    // Pipeline:
    //   LCNITransactionController.submit()
    //     → LCNIOrderService.execute()
    //       → LCNIOrderService.sendDnseOrder()
    //         → DNSEOrderService.sendOrder()   ← THIS
    //           → POST /lcni/v1/dnse/order
    // =========================================================================

    /**
     * Extract bare account number from a label like "RocketX Deal (0001032017)".
     * Falls back to the raw string if no parenthetical group found.
     *
     * "RocketX Deal (0001032017)" → "0001032017"
     * "0001032017"               → "0001032017"
     */
    function extractAccountNo(raw) {
        if (!raw) return '';
        const m = String(raw).match(/\(([^)]+)\)\s*$/);
        return m ? m[1].trim() : String(raw).trim();
    }

    /**
     * Map unified controller order → DNSE REST payload.
     *
     * Unified controller fields:
     *   order.dnseAccountNo   — account label OR bare account number
     *   order.dnseOrderType   — 'LO'|'ATO'|'ATC'|'MP'|'PM'
     *   order.dnseAccountType — 'spot'|'margin'
     *   order.symbol
     *   order.type            — 'buy'|'sell'
     *   order.priceVnd        — full VNĐ (e.g. 21500)
     *   order.qty             — quantity
     *
     * DNSE REST payload fields (per DnseOrderRestController):
     *   account_no, symbol, side, order_type, price (DB format), quantity,
     *   loan_package_id, account_type
     */
    function buildDnsePayload(order) {
        // DNSE API v1 market order types (price omitted): MP, MTL, MOK, MAK, ATO, ATC, PM
        const MARKET_TYPES = new Set(['MP', 'MTL', 'MOK', 'MAK', 'ATO', 'ATC', 'PM']);

        const accountNo  = extractAccountNo(order.dnseAccountNo || order.account_no || '');
        const orderType  = (order.dnseOrderType || order.order_type || 'LO').toUpperCase();
        const isMarket   = MARKET_TYPES.has(orderType);

        // Price: DB format (divide VNĐ by 1000). Market orders → 0 (PHP side will unset)
        const priceDb = isMarket ? 0 : parseFloat((parseFloat(order.priceVnd || order.price || 0) / 1000).toFixed(4));

        return {
            account_no:      accountNo,
            symbol:          (order.symbol || '').toUpperCase(),
            side:            (order.type === 'sell' ? 'sell' : 'buy'),
            order_type:      orderType,
            price:           priceDb,
            quantity:        parseInt(order.qty || order.quantity || 0),
            loan_package_id: order.loan_package_id || 0,
            account_type:    order.dnseAccountType || order.account_type || 'spot',
        };
    }

    window.DNSEOrderService = {
        /**
         * Send a real order to DNSE via the WordPress REST endpoint.
         * Returns Promise<{success, data:{order_id, message}}>
         */
        sendOrder: function (order) {
            const base   = (CFG.apiBase || '/wp-json/lcni/v1/dnse').replace(/\/dnse$/, '');
            const nonce  = CFG.nonce || '';
            const payload = buildDnsePayload(order);

            if (!payload.account_no) {
                return Promise.resolve({ success: false, data: 'Thiếu số tài khoản DNSE.' });
            }
            if (!payload.symbol) {
                return Promise.resolve({ success: false, data: 'Thiếu mã chứng khoán.' });
            }
            if (payload.quantity <= 0) {
                return Promise.resolve({ success: false, data: 'Khối lượng phải lớn hơn 0.' });
            }

            return fetch(base + '/dnse/order', {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                credentials: 'same-origin',
                body:        JSON.stringify(payload),
            })
            .then(async function (r) {
                if (!r.ok) {
                    const text = await r.text();
                    throw new Error('DNSE API Error (' + r.status + '): ' + text.slice(0, 200));
                }
                return r.json();
            })
            .then(function (res) {
                if (res && res.success) {
                    return {
                        success: true,
                        data: {
                            order_id: res.order_id || (res.data && res.data.order_id) || '',
                            message:  res.message  || (res.data && res.data.message)  || 'Đặt lệnh thành công.',
                        },
                    };
                }
                return {
                    success: false,
                    error: res.error || res.message || 'Đặt lệnh DNSE thất bại.',
                };
            })
            .catch(function (err) {
                return {
                    success: false,
                    error: (err && err.message) ? err.message : 'Không kết nối được DNSE API.',
                };
            });
        },

        /** Expose helpers for testing / external use */
        extractAccountNo: extractAccountNo,
        buildDnsePayload:  buildDnsePayload,
    };
})();

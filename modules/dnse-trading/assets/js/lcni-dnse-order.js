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

    // =========================================================================
    // SIGNALS TAB
    // =========================================================================

    async function renderSignalsTab(container, dashData) {
        const content = container.querySelector('.lcni-dnse-content');
        if (!content) return;

        content.innerHTML = '<div class="lcni-dnse-loading">Đang tải signals...</div>';

        const res = await GET('/signals');
        if (!res.success) {
            content.innerHTML = `<div class="lcni-dnse-error">${res.message || 'Lỗi tải signals'}</div>`;
            return;
        }

        const signals     = res.signals || [];
        const hasTrade    = res.has_trading;
        const connected   = res.connected;
        const accounts    = (res.accounts || []).map(a => ({
            id: a.investorAccountNo || a.id || a.accountNo || '',
            type: a.marginAccount ? 'margin' : 'spot',
            typeName: a.accountTypeName || a.accountTypeBriefName || '',
        })).filter(a => a.id);

        if (!connected) {
            content.innerHTML = '<p class="lcni-dnse-empty">Kết nối DNSE trước để xem signals.</p>';
            return;
        }

        if (!signals.length) {
            content.innerHTML = '<p class="lcni-dnse-empty">Không có signal nào đang mở. Recommend Rule sẽ tạo signal khi có điều kiện phù hợp.</p>';
            return;
        }

        // Trading token warning
        const tokenWarn = !hasTrade ? `
        <div class="lcni-dnse-alert lcni-dnse-alert--warning" style="margin-bottom:14px">
            ⚠ Trading token hết hạn — bạn cần xác thực OTP trong tab "Kết nối" trước khi đặt lệnh.
        </div>` : '';

        const rows = signals.map(s => {
            const alreadyHeld = s.already_held;
            const btnClass    = hasTrade ? 'lcni-dnse-btn--primary' : 'lcni-dnse-btn--secondary';
            const btnDisabled = !hasTrade ? 'disabled' : '';

            return `
            <tr class="lcni-signal-row" data-signal='${JSON.stringify(s)}'>
                <td class="lcni-dnse-symbol">${s.symbol}</td>
                <td style="font-size:11px;color:#8b949e">${s.rule_name}</td>
                <td>${fmt.price(s.entry_price)}</td>
                <td>${fmt.price(s.suggested_price)}</td>
                <td class="${pnlCls(s.pnl_pct)}">${fmt.pct(s.pnl_pct)}</td>
                <td>${s.holding_days}d</td>
                <td><span style="font-size:10px;background:rgba(255,255,255,.07);padding:2px 6px;border-radius:4px">${s.position_state}</span></td>
                <td>
                    ${alreadyHeld ? `<span class="lcni-dnse-badge lcni-dnse-badge--amber" style="margin-right:4px">Đang giữ</span>` : ''}
                    <button class="lcni-dnse-btn lcni-dnse-btn--sm ${btnClass} lcni-order-btn-signal"
                            data-signal-id="${s.signal_id}" ${btnDisabled}
                            title="${hasTrade ? 'Đặt lệnh mua theo signal' : 'Cần OTP trước'}">
                        📈 Mua
                    </button>
                </td>
            </tr>`;
        }).join('');

        content.innerHTML = `
        ${tokenWarn}
        <div class="lcni-dnse-table-wrap">
            <table class="lcni-dnse-table">
                <thead><tr>
                    <th>Mã CK</th><th>Rule</th><th>Giá vào</th>
                    <th>Giá hiện tại</th><th>% P/L</th><th>Ngày</th>
                    <th>Trạng thái</th><th>Lệnh</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;

        // Bind order buttons
        content.querySelectorAll('.lcni-order-btn-signal').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row    = btn.closest('.lcni-signal-row');
                const signal = JSON.parse(row.dataset.signal);
                await openOrderFormFromSignal(signal, accounts, container);
            });
        });
    }

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

        const res = await POST('/order', modal.orderData);

        if (btn) { btn.disabled = false; btn.textContent = '📈 Mua'; }

        if (res.success) {
            showGlobalToast(`✅ ${res.message}`, 'success');
        } else {
            showGlobalToast(`❌ ${res.message}`, 'error');
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
            const noPrice = ['ATO','ATC','MP'].includes(typeEl.value);
            priceField.style.opacity = noPrice ? '0.4' : '1';
            priceEl.disabled = noPrice;
            if (noPrice) priceEl.value = '0';
        });

        // Đặt lệnh
        content.querySelector('#lcni-manual-order-btn').addEventListener('click', async () => {
            const acctEl   = content.querySelector('#lcni-manual-account');
            const acctTypeEl = content.querySelector('#lcni-manual-account-type');
            const acctNo   = acctEl.value.trim().toUpperCase();
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
            if (!['ATO','ATC','MP'].includes(orderData.order_type) && orderData.price <= 0) {
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

            const res = await POST('/order', orderData);
            btn.disabled = false;
            btn.textContent = 'Xem xác nhận →';

            if (res.success) {
                showGlobalToast(`✅ ${res.message}`, 'success');
            } else {
                showGlobalToast(`❌ ${res.message || 'Đặt lệnh thất bại.'}`, 'error');
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

            const signalTab = document.createElement('button');
            signalTab.className = 'lcni-dnse-tab';
            signalTab.dataset.tab = 'signals';
            signalTab.textContent = '📊 Signals';
            tabsEl.insertBefore(signalTab, syncBtn);

            const manualTab = document.createElement('button');
            manualTab.className = 'lcni-dnse-tab';
            manualTab.dataset.tab = 'manual';
            manualTab.textContent = '✏ Đặt lệnh';
            tabsEl.insertBefore(manualTab, syncBtn);

            // Lấy accounts từ dashboard data
            let accountsCache = [];

            // Override tab click để handle 2 tab mới
            [signalTab, manualTab].forEach(btn => {
                btn.addEventListener('click', async () => {
                    el.querySelectorAll('.lcni-dnse-tab').forEach(b =>
                        b.classList.toggle('lcni-dnse-tab--active', b === btn)
                    );

                    if (btn.dataset.tab === 'signals') {
                        await renderSignalsTab(el, null);
                    } else if (btn.dataset.tab === 'manual') {
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
})();

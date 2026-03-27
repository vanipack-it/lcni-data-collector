/**
 * lcni-dnse-trading.js
 * Frontend cho [lcni_dnse_trading] shortcode
 */
(function () {
    'use strict';

    const CFG  = window.lcniDnseCfg || {};
    const T    = CFG.i18n || {};
    const BASE = CFG.apiBase || '/wp-json/lcni/v1/dnse';

    // ── API calls ─────────────────────────────────────────────────────────────

    async function api(method, path, body) {
        const res = await fetch(BASE + path, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   CFG.nonce || '',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        return res.json();
    }

    const GET  = (path)        => api('GET',  path, null);
    const POST = (path, body)  => api('POST', path, body || {});

    // ── Format helpers ────────────────────────────────────────────────────────

    const fmt = {
        money: v => v == null ? '—' : (v / 1e6).toFixed(1) + ' tr',
        moneyFull: v => v == null ? '—' : Number(v).toLocaleString('vi-VN') + ' đ',
        pct:   v => v == null ? '—' : (v >= 0 ? '+' : '') + Number(v).toFixed(2) + '%',
        price: v => v == null ? '—' : Number(v).toFixed(1),
        qty:   v => v == null ? '—' : Math.round(Number(v)).toLocaleString('vi-VN'),
        date:  ts => ts ? new Date(ts * 1000).toLocaleDateString('vi-VN') : '—',
        time:  ts => ts ? new Date(ts * 1000).toLocaleTimeString('vi-VN', {hour:'2-digit',minute:'2-digit'}) : '—',
    };

    function pnlClass(v) { return v >= 0 ? 'lcni-pos' : 'lcni-neg'; }
    function sideClass(s) { return s === 'buy' || s === 'BUY' ? 'lcni-dnse-buy' : 'lcni-dnse-sell'; }

    // ── Render helpers ────────────────────────────────────────────────────────

    function statusBadge(status) {
        const map = {
            'connected':  ['lcni-dnse-badge--green',  T.connected || 'Đã kết nối'],
            'trading':    ['lcni-dnse-badge--blue',   T.trading_active || 'Trading OK'],
            'expired':    ['lcni-dnse-badge--amber',  T.trading_expired || 'OTP cần gia hạn'],
            'offline':    ['lcni-dnse-badge--gray',   T.not_connected || 'Chưa kết nối'],
        };
        const [cls, label] = map[status] || map.offline;
        return `<span class="lcni-dnse-badge ${cls}">${label}</span>`;
    }

    function renderConnectTab(status) {
        const connected   = status.connected;
        const hasTrading  = status.has_trading;
        const accountNo   = status.account_no || '';

        if (!connected) {
            return `
            <div class="lcni-dnse-section">
                <h3>${T.connect}</h3>
                <p class="lcni-dnse-hint">
                    Nhập tài khoản DNSE (EntradeX) của bạn. Mật khẩu không được lưu lại.
                </p>
                <div class="lcni-dnse-form">
                    <div class="lcni-dnse-field">
                        <label>Tài khoản DNSE</label>
                        <input id="dnse-username" type="text" placeholder="Số tài khoản hoặc số điện thoại" autocomplete="username">
                    </div>
                    <div class="lcni-dnse-field">
                        <label>Mật khẩu</label>
                        <input id="dnse-password" type="password" placeholder="Mật khẩu EntradeX" autocomplete="current-password">
                    </div>
                    <button class="lcni-dnse-btn lcni-dnse-btn--primary" id="dnse-connect-btn">
                        ${T.connect}
                    </button>
                </div>
            </div>`;
        }

        // Đã connected, nhưng có thể cần xác thực OTP
        const otpSection = hasTrading ? `
            <div class="lcni-dnse-alert lcni-dnse-alert--success">
                ${T.trading_active}. Token còn hiệu lực đến 
                <strong>${fmt.time(status.trading_expires_at)}</strong>.
            </div>` : `
            <div class="lcni-dnse-alert lcni-dnse-alert--warning">
                ${T.trading_expired}
            </div>
            <div class="lcni-dnse-form">
                <p class="lcni-dnse-hint">
                    Chọn phương thức OTP:
                </p>
                <div class="lcni-dnse-radio-group">
                    <label><input type="radio" name="otp_type" value="smart" checked> Smart OTP (app EntradeX)</label>
                    <label><input type="radio" name="otp_type" value="email"> Email OTP</label>
                </div>

                <!-- Smart OTP hint: hiện khi chọn Smart OTP -->
                <div id="dnse-smart-hint" class="lcni-dnse-hint" style="margin:8px 0 12px;padding:8px 12px;background:rgba(34,113,177,.1);border-radius:6px;font-size:12px">
                    Mở app <strong>EntradeX</strong> → vào mục Smart OTP → nhập mã 6 chữ số bên dưới.
                </div>

                <!-- Email OTP section: ẩn khi chọn Smart OTP -->
                <div id="dnse-email-otp-section" style="display:none">
                    <button class="lcni-dnse-btn lcni-dnse-btn--secondary" id="dnse-req-otp-btn" style="margin-bottom:12px">
                        📧 ${T.request_otp}
                    </button>
                    <div class="lcni-dnse-hint" style="font-size:11px;margin-bottom:12px">
                        Bấm để gửi mã OTP về email đăng ký tài khoản DNSE.
                    </div>
                </div>

                <div class="lcni-dnse-field">
                    <label>Nhập mã OTP <span id="dnse-otp-type-label" style="color:#e8b84b;font-size:10px">(từ app EntradeX)</span></label>
                    <input id="dnse-otp-input" type="text" placeholder="6 chữ số" maxlength="8" autocomplete="one-time-code">
                </div>
                <button class="lcni-dnse-btn lcni-dnse-btn--primary" id="dnse-verify-otp-btn">
                    ${T.verify_otp}
                </button>
            </div>`;

        return `
        <div class="lcni-dnse-section">
            <div class="lcni-dnse-status-row">
                ${statusBadge('connected')}
                ${hasTrading ? statusBadge('trading') : statusBadge('expired')}
                <span class="lcni-dnse-account">${accountNo}</span>
            </div>
            ${otpSection}
            <div style="margin-top:20px">
                <button class="lcni-dnse-btn lcni-dnse-btn--danger" id="dnse-disconnect-btn">
                    ${T.disconnect}
                </button>
            </div>
        </div>`;
    }

    function renderPortfolioTab(data) {
        const positions = data.positions || [];
        const summary   = data.portfolio_summary || {};

        const summaryHtml = `
        <div class="lcni-dnse-summary-cards">
            <div class="lcni-dnse-summary-card">
                <span class="lcni-dnse-summary-value">${fmt.money(summary.total_market_value)}</span>
                <span class="lcni-dnse-summary-label">Giá trị thị trường</span>
            </div>
            <div class="lcni-dnse-summary-card">
                <span class="lcni-dnse-summary-value ${pnlClass(summary.total_pnl)}">${fmt.money(summary.total_pnl)}</span>
                <span class="lcni-dnse-summary-label">Lãi/Lỗ tạm tính</span>
            </div>
            <div class="lcni-dnse-summary-card">
                <span class="lcni-dnse-summary-value ${pnlClass(summary.total_pnl_pct)}">${fmt.pct(summary.total_pnl_pct)}</span>
                <span class="lcni-dnse-summary-label">% Lãi/Lỗ</span>
            </div>
            <div class="lcni-dnse-summary-card">
                <span class="lcni-dnse-summary-value">${summary.position_count || 0}</span>
                <span class="lcni-dnse-summary-label">Mã đang nắm</span>
            </div>
        </div>`;

        if (!positions.length) {
            return summaryHtml + '<p class="lcni-dnse-empty">Không có vị thế nào. Bấm Đồng bộ để cập nhật.</p>';
        }

        const rows = positions.map(p => `
        <tr>
            <td class="lcni-dnse-symbol">${p.symbol}</td>
            <td>${fmt.qty(p.quantity)}</td>
            <td>${fmt.qty(p.available_quantity)}</td>
            <td>${fmt.price(p.avg_price)}</td>
            <td>${fmt.price(p.current_price)}</td>
            <td class="${pnlClass(p.unrealized_pnl)}">${fmt.money(p.market_value)}</td>
            <td class="${pnlClass(p.unrealized_pnl)}">${fmt.money(p.unrealized_pnl)}</td>
            <td class="${pnlClass(p.unrealized_pnl_pct)}">${fmt.pct(p.unrealized_pnl_pct)}</td>
        </tr>`).join('');

        return `
        ${summaryHtml}
        <div class="lcni-dnse-table-wrap lcni-table-wrapper">
            <table class="lcni-dnse-table lcni-table lcni-table--dark">
                <thead>
                    <tr>
                        <th>Mã CK</th><th>SL</th><th>SL KD</th>
                        <th>Giá TB</th><th>Giá TT</th>
                        <th>Giá trị</th><th>Lãi/Lỗ</th><th>%</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    function renderOrdersTab(data) {
        const orders = data.orders || [];

        if (!orders.length) {
            return '<p class="lcni-dnse-empty">Không có lệnh nào hôm nay. Bấm Đồng bộ để cập nhật.</p>';
        }

        const rows = orders.map(o => `
        <tr>
            <td class="lcni-dnse-symbol">${o.symbol}</td>
            <td><span class="lcni-dnse-side ${sideClass(o.side)}">${o.side.toUpperCase()}</span></td>
            <td>${o.order_type}</td>
            <td>${fmt.price(o.price)}</td>
            <td>${fmt.qty(o.quantity)}</td>
            <td>${fmt.qty(o.filled_quantity)}</td>
            <td><span class="lcni-dnse-status">${o.status}</span></td>
            <td>${o.order_date || '—'}</td>
        </tr>`).join('');

        return `
        <div class="lcni-dnse-table-wrap lcni-table-wrapper">
            <table class="lcni-dnse-table lcni-table lcni-table--dark">
                <thead>
                    <tr>
                        <th>Mã CK</th><th>Loại</th><th>Lệnh</th>
                        <th>Giá</th><th>KL đặt</th><th>KL khớp</th>
                        <th>Trạng thái</th><th>Ngày</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    function renderAccountsTab(data) {
        const accounts = data.accounts || [];
        if (!accounts.length) return '<p class="lcni-dnse-empty">Không có dữ liệu tài khoản.</p>';

        return accounts.map(a => {
            const bal = a.balance || {};
            const cap = a.trade_capacity || {};
            return `
            <div class="lcni-dnse-account-card">
                <div class="lcni-dnse-account-header">
                    <strong>${a.account_no}</strong>
                    <span class="lcni-dnse-badge lcni-dnse-badge--gray">${a.account_type}</span>
                </div>
                <div class="lcni-dnse-account-grid">
                    <div><label>Tài sản ròng</label><span>${fmt.moneyFull(bal.netAsset ?? bal.totalAsset ?? null)}</span></div>
                    <div><label>Tiền có thể dùng</label><span>${fmt.moneyFull(bal.availableCash ?? bal.available ?? null)}</span></div>
                    <div><label>Sức mua</label><span>${fmt.moneyFull(cap.buyingPower ?? cap.maxBuyValue ?? null)}</span></div>
                    <div><label>Giá trị CP</label><span>${fmt.moneyFull(bal.stockValue ?? bal.totalStockValue ?? null)}</span></div>
                </div>
                ${a.synced_at ? `<p class="lcni-dnse-sync-time">Cập nhật: ${a.synced_at}</p>` : ''}
            </div>`;
        }).join('');
    }

    // ── Main controller ───────────────────────────────────────────────────────

    function initWidget(el) {
        let currentTab = el.dataset.defaultTab || CFG.defaultTab || 'portfolio';
        let dashData   = null;
        let status     = null;

        function setLoading(msg) {
            el.querySelector('.lcni-dnse-content').innerHTML =
                `<div class="lcni-dnse-loading">${msg || T.loading}</div>`;
        }

        function setError(msg) {
            el.querySelector('.lcni-dnse-content').innerHTML =
                `<div class="lcni-dnse-error">${msg || T.error_generic}</div>`;
        }

        function showToast(msg, type = 'success') {
            const toast = el.querySelector('.lcni-dnse-toast');
            if (!toast) return;
            toast.textContent = msg;
            toast.className = `lcni-dnse-toast lcni-dnse-toast--${type} lcni-dnse-toast--visible`;
            setTimeout(() => toast.classList.remove('lcni-dnse-toast--visible'), 3000);
        }

        function renderTabs() {
            const tabs = [
                { id: 'portfolio', label: T.tab_portfolio },
                { id: 'orders',    label: T.tab_orders },
                { id: 'connect',   label: T.tab_connect },
            ];
            return `<div class="lcni-dnse-tabs">
                ${tabs.map(t => `
                    <button class="lcni-dnse-tab ${t.id === currentTab ? 'lcni-dnse-tab--active' : ''}"
                            data-tab="${t.id}">${t.label}</button>
                `).join('')}
                <button class="lcni-dnse-sync-btn" data-role="sync" title="${T.sync}">↻ ${T.sync}</button>
            </div>`;
        }

        async function loadStatus() {
            const res = await GET('/status');
            if (res.success) status = res;
            return res;
        }

        async function loadDashboard() {
            const res = await GET('/dashboard');
            if (res.success) dashData = res;
            return res;
        }

        function bindOtpRadio() {
            function updateOtpUI() {
                const selected = el.querySelector('input[name="otp_type"]:checked')?.value || 'smart';
                const emailSection = el.querySelector('#dnse-email-otp-section');
                const smartHint    = el.querySelector('#dnse-smart-hint');
                const label        = el.querySelector('#dnse-otp-type-label');

                if (emailSection) emailSection.style.display = selected === 'email' ? 'block' : 'none';
                if (smartHint)    smartHint.style.display    = selected === 'smart'  ? 'block' : 'none';
                if (label) label.textContent = selected === 'email' ? '(từ email)' : '(từ app EntradeX)';
            }

            el.querySelectorAll('input[name="otp_type"]').forEach(radio => {
                radio.addEventListener('change', updateOtpUI);
            });

            // Khởi tạo trạng thái ban đầu
            updateOtpUI();
        }

        async function renderCurrentTab() {
            const content = el.querySelector('.lcni-dnse-content');
            if (!content) return;

            if (currentTab === 'connect') {
                const st = status || await loadStatus();
                content.innerHTML = renderConnectTab(st);
                bindConnectEvents();
                bindOtpRadio();
                return;
            }

            if (!dashData) {
                setLoading();
                await loadDashboard();
            }

            if (!dashData) {
                setError();
                return;
            }

            if (currentTab === 'portfolio') {
                content.innerHTML = renderPortfolioTab(dashData);
            } else if (currentTab === 'orders') {
                content.innerHTML = renderOrdersTab(dashData);
            }
        }

        function bindConnectEvents() {
            const connectBtn    = el.querySelector('#dnse-connect-btn');
            const disconnectBtn = el.querySelector('#dnse-disconnect-btn');
            const reqOtpBtn     = el.querySelector('#dnse-req-otp-btn');
            const verifyOtpBtn  = el.querySelector('#dnse-verify-otp-btn');

            if (connectBtn) {
                connectBtn.addEventListener('click', async () => {
                    const username = el.querySelector('#dnse-username')?.value?.trim();
                    const password = el.querySelector('#dnse-password')?.value;
                    if (!username || !password) {
                        showToast('Vui lòng nhập đầy đủ tài khoản và mật khẩu.', 'error');
                        return;
                    }
                    connectBtn.disabled = true;
                    connectBtn.textContent = 'Đang kết nối...';
                    const res = await POST('/connect', { username, password });
                    connectBtn.disabled = false;
                    connectBtn.textContent = T.connect;
                    if (res.success) {
                        showToast(res.message, 'success');
                        dashData = null;
                        status = null;
                        await renderCurrentTab();
                    } else {
                        showToast(res.message || T.error_generic, 'error');
                    }
                });
            }

            if (reqOtpBtn) {
                reqOtpBtn.addEventListener('click', async () => {
                    // Tự động chọn Email OTP khi bấm "Gửi Email OTP"
                    const emailRadio = el.querySelector('input[name="otp_type"][value="email"]');
                    if (emailRadio) emailRadio.checked = true;

                    reqOtpBtn.disabled = true;
                    reqOtpBtn.textContent = 'Đang gửi...';
                    const res = await POST('/request-otp');
                    reqOtpBtn.disabled = false;
                    reqOtpBtn.textContent = T.request_otp;

                    if (res.success) {
                        showToast('📧 OTP đã gửi về email. Kiểm tra hộp thư và nhập mã bên dưới.', 'success');
                        // Focus vào ô nhập OTP
                        setTimeout(() => el.querySelector('#dnse-otp-input')?.focus(), 300);
                    } else {
                        showToast(res.message || T.error_generic, 'error');
                    }
                });
            }

            if (verifyOtpBtn) {
                verifyOtpBtn.addEventListener('click', async () => {
                    const otp       = el.querySelector('#dnse-otp-input')?.value?.trim();
                    const otp_type  = el.querySelector('input[name="otp_type"]:checked')?.value || 'smart';
                    if (!otp) {
                        showToast('Vui lòng nhập mã OTP.', 'error');
                        return;
                    }
                    verifyOtpBtn.disabled = true;
                    const res = await POST('/verify-otp', { otp, otp_type });
                    verifyOtpBtn.disabled = false;
                    if (res.success) {
                        showToast(res.message, 'success');
                        status = null;
                        await renderCurrentTab();
                    } else {
                        showToast(res.message || T.error_generic, 'error');
                    }
                });
            }

            if (disconnectBtn) {
                disconnectBtn.addEventListener('click', async () => {
                    if (!confirm('Ngắt kết nối DNSE? Bạn sẽ cần đăng nhập lại để dùng tính năng này.')) return;
                    await POST('/disconnect');
                    dashData = null;
                    status   = null;
                    showToast('Đã ngắt kết nối.', 'success');
                    await renderCurrentTab();
                });
            }
        }

        async function init() {
            // Build shell
            el.innerHTML = `
                <div class="lcni-dnse-widget">
                    <div class="lcni-dnse-toast"></div>
                    <div id="lcni-dnse-tabs-placeholder"></div>
                    <div class="lcni-dnse-content">
                        <div class="lcni-dnse-loading">${T.loading}</div>
                    </div>
                </div>`;

            // Load status để biết tab connect nên hiển thị gì
            await loadStatus();

            // Auto-sync ngầm nếu đã kết nối và lần sync cuối > 1 phút
            if (status && status.connected) {
                const lastSync = status.last_sync_at ? new Date(status.last_sync_at).getTime() : 0;
                const minutesSince = (Date.now() - lastSync) / 60000;
                if (minutesSince > 1 || !lastSync) {
                    POST('/sync').then(res => {
                        if (res.success) dashData = null;
                    }).catch(() => {});
                }
            }

            // Render tabs header
            el.querySelector('#lcni-dnse-tabs-placeholder').outerHTML = renderTabs();

            // Bind tab clicks
            el.querySelectorAll('.lcni-dnse-tab').forEach(btn => {
                btn.addEventListener('click', () => {
                    currentTab = btn.dataset.tab;
                    el.querySelectorAll('.lcni-dnse-tab').forEach(b => b.classList.toggle('lcni-dnse-tab--active', b === btn));
                    renderCurrentTab();
                });
            });

            // Bind sync button
            const syncBtn = el.querySelector('[data-role="sync"]');
            if (syncBtn) {
                syncBtn.addEventListener('click', async () => {
                    syncBtn.disabled = true;
                    syncBtn.textContent = 'Đang sync...';
                    const res = await POST('/sync');
                    syncBtn.disabled = false;
                    syncBtn.textContent = `↻ ${T.sync}`;
                    if (res.success) {
                        dashData = null;
                        showToast(res.message, 'success');
                        await renderCurrentTab();
                    } else {
                        showToast(res.message || T.error_generic, 'error');
                    }
                });
            }

            await renderCurrentTab();

            // Auto-sync + refresh mỗi 1 phút
            setInterval(async () => {
                if (!status || !status.connected) return;
                // Sync dữ liệu mới từ DNSE
                const res = await POST('/sync').catch(() => null);
                if (res && res.success) {
                    dashData = null;
                    await loadStatus();
                    // Refresh UI tab đang xem
                    if (currentTab === 'portfolio' || currentTab === 'orders') {
                        await renderCurrentTab();
                    }
                }
            }, 60 * 1000);
        }

        init();
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    function boot() {
        document.querySelectorAll('.lcni-dnse-trading').forEach(initWidget);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

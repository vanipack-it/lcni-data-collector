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
        // Safe parse — prevents "Unexpected token <" when backend returns HTML error page
        if (!res.ok) {
            const text = await res.text();
            throw new Error('API Error (' + res.status + '): ' + text.slice(0, 200));
        }
        return res.json();
    }

    const GET  = (path)        => api('GET',  path, null);
    const POST = (path, body)  => api('POST', path, body || {});

    /** Extract success message from REST response */
    function _msg(res) { return res.message || (res.data && res.data.message) || T.sync_success || 'Thành công.'; }
    /** Extract error string from REST response */
    function _err(res) { return res.error || res.message || T.error_generic || 'Có lỗi xảy ra.'; }

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
        const perms       = status.permissions || [];

        console.log('[DNSE] renderConnectTab: connected=', connected, 'has_trading=', hasTrading);

        if (!connected) {
            return `
            <div class="lcni-dnse-section">
                <h3>${T.connect}</h3>
                <p class="lcni-dnse-hint">
                    Nhập tài khoản DNSE (EntradeX) của bạn. Mật khẩu không được lưu lại trừ khi bật tự động đăng nhập.
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

                    <!-- Permissions checkboxes -->
                    <div class="lcni-dnse-perms-box" style="margin:12px 0;padding:12px 14px;border:1px solid rgba(255,255,255,.1);border-radius:8px;background:rgba(255,255,255,.03)">
                        <p style="margin:0 0 10px;font-size:12px;color:rgba(255,255,255,.6);font-weight:600;text-transform:uppercase;letter-spacing:.05em">Tùy chọn tự động hóa</p>
                        <label class="lcni-dnse-perm-label" style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;cursor:pointer">
                            <input type="checkbox" id="perm-auto-renew" value="perm_auto_renew" style="margin-top:2px;flex-shrink:0">
                            <span>
                                <strong style="font-size:13px;color:rgba(255,255,255,.85)">🔄 Tự động đăng nhập lại</strong><br>
                                <span style="font-size:11px;color:rgba(255,255,255,.45);line-height:1.5">Lưu mật khẩu (mã hoá) để hệ thống tự đăng nhập và gia hạn kết nối mỗi 8 giờ. Yêu cầu Gmail đã kết nối.</span>
                            </span>
                        </label>
                        <label class="lcni-dnse-perm-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                            <input type="checkbox" id="perm-trade" value="perm_trade" style="margin-top:2px;flex-shrink:0">
                            <span>
                                <strong style="font-size:13px;color:rgba(255,255,255,.85)">⚡ Cho phép đặt lệnh tự động</strong><br>
                                <span style="font-size:11px;color:rgba(255,255,255,.45);line-height:1.5">Hệ thống sẽ tự đặt lệnh mua/bán khi Auto Rule phát hiện tín hiệu. Chỉ áp dụng cho rule có bật Auto Order.</span>
                            </span>
                        </label>
                    </div>

                    <button class="lcni-dnse-btn lcni-dnse-btn--primary" id="dnse-connect-btn">
                        ${T.connect}
                    </button>
                </div>
            </div>`;
        }

        // ── Gmail auto-renew status ──────────────────────────────────────────
        const gmailConfigured = !!status.gmail_configured;
        const gmailConnected  = !!status.gmail_connected;
        const gmailEmail      = status.gmail_email || '';

        const gmailSection = `
            <div class="lcni-dnse-gmail-panel" style="margin-top:16px;padding:14px 16px;border:1px solid rgba(255,255,255,.1);border-radius:8px;background:rgba(255,255,255,.03)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                    <strong style="font-size:13px">📧 Auto-Renew Trading Token</strong>
                    ${gmailConnected
                        ? '<span style="font-size:11px;background:#22c55e22;color:#22c55e;padding:2px 8px;border-radius:20px">● Đang bật</span>'
                        : '<span style="font-size:11px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.45);padding:2px 8px;border-radius:20px">○ Chưa bật</span>'
                    }
                </div>
                ${gmailConnected ? `
                    <p style="font-size:12px;color:rgba(255,255,255,.6);margin:0 0 10px;line-height:1.5">
                        ✅ Đã kết nối Gmail <strong style="color:rgba(255,255,255,.85)">${gmailEmail}</strong>.<br>
                        Trading token sẽ tự động gia hạn mỗi 8 giờ — không cần nhập OTP lại.
                    </p>
                    <button class="lcni-dnse-btn lcni-dnse-btn--secondary" id="dnse-gmail-disconnect-btn"
                            style="font-size:11px;padding:4px 12px;opacity:.7">
                        Ngắt kết nối Gmail
                    </button>
                ` : gmailConfigured ? `
                    <p style="font-size:12px;color:rgba(255,255,255,.6);margin:0 0 12px;line-height:1.5">
                        Kết nối Gmail để hệ thống tự đọc mã OTP từ DNSE và gia hạn trading token mỗi 8 giờ.<br>
                        <span style="opacity:.6;font-size:11px">Chỉ cần thực hiện 1 lần. Chỉ đọc email — không gửi hoặc xoá.</span>
                    </p>
                    <button class="lcni-dnse-btn lcni-dnse-btn--primary" id="dnse-gmail-connect-btn"
                            style="font-size:12px;display:flex;align-items:center;gap:6px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 18h-2V9.25L12 13 6 9.25V18H4V6h1.2l6.8 4.25L18.8 6H20v12z"/>
                        </svg>
                        Kết nối Gmail
                    </button>
                ` : `
                    <p style="font-size:12px;color:rgba(255,255,255,.45);margin:0">
                        ⚙️ Admin chưa cấu hình Gmail OAuth. Liên hệ Admin để bật tính năng này.
                    </p>
                `}
            </div>`;

        // Đã connected, nhưng có thể cần xác thực OTP
        // Kiểm tra transient pending từ server (dùng status field nếu có)
        const autoOtpPending = !!status.auto_otp_pending;

        const otpSection = hasTrading ? `
            <div class="lcni-dnse-alert lcni-dnse-alert--success">
                ${T.trading_active}. Token còn hiệu lực đến 
                <strong>${fmt.time(status.trading_expires_at)}</strong>.
            </div>
            ${gmailSection}` : autoOtpPending ? `
            <div class="lcni-dnse-alert lcni-dnse-alert--warning" style="display:flex;align-items:center;gap:10px">
                <span class="lcni-dnse-auto-otp-badge">⏳ Đang đọc Gmail để xác thực OTP...</span>
            </div>
            ${gmailSection}` : gmailConnected ? `
            <div class="lcni-dnse-alert lcni-dnse-alert--warning">
                ${T.trading_expired}
            </div>
            <div class="lcni-dnse-form" style="padding-top:4px">
                <p class="lcni-dnse-hint" style="margin-bottom:12px">
                    Gmail đã kết nối — hệ thống sẽ tự xác thực OTP. Bấm nút bên dưới để kích hoạt ngay.
                </p>
                <button class="lcni-dnse-btn lcni-dnse-btn--primary" id="dnse-gmail-renew-btn">
                    🔄 Xác thực OTP tự động qua Gmail
                </button>
            </div>
            ${gmailSection}` : `
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
            </div>
            ${gmailSection}`;

        // ── Permissions panel (khi đã connected) ────────────────────────────
        const permAutoRenew = perms.includes('perm_auto_renew');
        const permTrade     = perms.includes('perm_trade');

        const permPanel = `
            <div class="lcni-dnse-perms-panel" id="dnse-perms-panel"
                 style="margin-top:16px;padding:14px 16px;border:1px solid rgba(255,255,255,.1);border-radius:8px;background:rgba(255,255,255,.03)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                    <strong style="font-size:13px">⚙️ Tùy chọn tự động hóa</strong>
                    <button id="dnse-save-perms-btn" class="lcni-dnse-btn lcni-dnse-btn--secondary"
                            style="font-size:11px;padding:4px 12px">Lưu</button>
                </div>
                <label class="lcni-dnse-perm-label" style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;cursor:pointer">
                    <input type="checkbox" id="dnse-perm-auto-renew" value="perm_auto_renew"
                           ${permAutoRenew ? 'checked' : ''} style="margin-top:2px;flex-shrink:0">
                    <span>
                        <strong style="font-size:13px;color:rgba(255,255,255,.85)">🔄 Tự động đăng nhập lại</strong><br>
                        <span style="font-size:11px;color:rgba(255,255,255,.45);line-height:1.5">
                            Lưu mật khẩu (mã hoá) để hệ thống tự gia hạn kết nối mỗi 8 giờ.
                            ${permAutoRenew
                                ? '<span style="color:#22c55e">● Đang bật</span>'
                                : '<span style="color:rgba(255,255,255,.3)">○ Tắt</span>'
                            }
                        </span>
                    </span>
                </label>
                <label class="lcni-dnse-perm-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                    <input type="checkbox" id="dnse-perm-trade" value="perm_trade"
                           ${permTrade ? 'checked' : ''} style="margin-top:2px;flex-shrink:0">
                    <span>
                        <strong style="font-size:13px;color:rgba(255,255,255,.85)">⚡ Cho phép đặt lệnh tự động</strong><br>
                        <span style="font-size:11px;color:rgba(255,255,255,.45);line-height:1.5">
                            Hệ thống tự đặt lệnh mua/bán khi Auto Rule phát tín hiệu.
                            ${permTrade
                                ? '<span style="color:#22c55e">● Đang bật</span>'
                                : '<span style="color:rgba(255,255,255,.3)">○ Tắt</span>'
                            }
                        </span>
                    </span>
                </label>
                <!-- Panel nhập lại password khi bật auto_renew mà chưa có password -->
                <div id="dnse-reauth-box" style="display:none;margin-top:12px;padding:10px 12px;border:1px solid rgba(232,184,75,.3);border-radius:6px;background:rgba(232,184,75,.05)">
                    <p style="margin:0 0 8px;font-size:12px;color:#e8b84b">
                        ⚠️ Cần nhập lại mật khẩu để bật tự động đăng nhập:
                    </p>
                    <input id="dnse-reauth-password" type="password" placeholder="Mật khẩu EntradeX"
                           style="width:100%;margin-bottom:8px;padding:6px 10px;border-radius:6px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;font-size:12px;box-sizing:border-box">
                    <button id="dnse-reauth-confirm-btn" class="lcni-dnse-btn lcni-dnse-btn--primary"
                            style="font-size:12px;padding:6px 16px">Xác nhận</button>
                </div>
            </div>`;

        return `
        <div class="lcni-dnse-section">
            <div class="lcni-dnse-status-row">
                ${statusBadge('connected')}
                ${hasTrading ? statusBadge('trading') : statusBadge('expired')}
                <span class="lcni-dnse-account">${accountNo}</span>
            </div>
            ${otpSection}
            ${permPanel}
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
        let currentTab = el.dataset.defaultTab || CFG.defaultTab || 'connect';
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
                { id: 'connect',   label: T.tab_connect },
                { id: 'orders',    label: T.tab_orders },
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
            try {
                const res = await GET('/status');
                if (res.success) status = res;
                console.log('[DNSE] /status response:', JSON.stringify({connected: res.connected, has_trading: res.has_trading, success: res.success}));
                return res;
            } catch (e) {
                console.error('[DNSE] /status error:', e.message);
                return { success: false, error: e.message };
            }
        }

        async function loadDashboard() {
            try {
                const res = await GET('/dashboard');
                if (res.success) dashData = res;
                return res;
            } catch (e) {
                return { success: false, error: e.message };
            }
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
                // Luôn fetch status mới từ /status — không dùng lại response của /connect
                setLoading();
                const st = await loadStatus();
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

            if (currentTab === 'orders') {
                content.innerHTML = renderOrdersTab(dashData);
            }
        }

        // Poll /gmail-otp-poll mỗi 5s sau khi /connect gửi email OTP.
        // Tối đa 8 lần (~40s). Hiện loading badge trên UI, tự reload khi xong.
        async function pollGmailOtp() {
            const MAX = 8;
            for (let i = 0; i < MAX; i++) {
                // Cập nhật badge trên UI nếu có
                const badge = el.querySelector('.lcni-dnse-auto-otp-badge');
                if (badge) badge.textContent = `⏳ Đang đọc Gmail... (${i + 1}/${MAX})`;

                await new Promise(r => setTimeout(r, 5000));

                let res;
                try {
                    res = await GET('/gmail-otp-poll');
                } catch (e) {
                    continue; // network error → thử lại
                }

                if (res.done) {
                    if (res.success) {
                        showToast('✅ ' + (res.message || 'Trading token xác thực thành công qua Gmail.'), 'success');
                    } else {
                        showToast('⚠️ Auto OTP thất bại: ' + (res.message || ''), 'warning');
                    }
                    dashData = null; status = null;
                    await renderCurrentTab();
                    return;
                }
                // done=false → email chưa đến → poll tiếp
            }
            // Hết lần thử → thông báo nhập thủ công
            showToast('⚠️ Không đọc được OTP từ Gmail sau 40s — vui lòng xác thực thủ công.', 'warning');
            dashData = null; status = null;
            await renderCurrentTab();
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
                    // Thu thập permissions đã chọn
                    const permissions = [];
                    if (el.querySelector('#perm-auto-renew')?.checked) permissions.push('perm_auto_renew');
                    if (el.querySelector('#perm-trade')?.checked)      permissions.push('perm_trade');

                    connectBtn.disabled = true;
                    connectBtn.textContent = 'Đang kết nối...';
                    let res;
                    try {
                        res = await POST('/connect', { username, password, permissions });
                    } catch (e) {
                        connectBtn.disabled = false;
                        connectBtn.textContent = T.connect;
                        showToast(_err({ error: e.message }), 'error');
                        return;
                    }
                    connectBtn.disabled = false;
                    connectBtn.textContent = T.connect;
                    if (res.success) {
                        if (res.auto_otp_pending) {
                            // Gmail đã kết nối, email OTP đã gửi → poll để lấy kết quả
                            showToast('📧 Đang xác thực OTP tự động qua Gmail...', 'success');
                            dashData = null; status = null;
                            await renderCurrentTab();
                            await pollGmailOtp();
                        } else {
                            showToast(_msg(res), 'success');
                            dashData = null; status = null;
                            await renderCurrentTab();
                        }
                    } else {
                        showToast(_err(res), 'error');
                    }
                });
            }

            const gmailRenewBtn = el.querySelector('#dnse-gmail-renew-btn');
            if (gmailRenewBtn) {
                gmailRenewBtn.addEventListener('click', async () => {
                    gmailRenewBtn.disabled = true;
                    gmailRenewBtn.textContent = '⏳ Đang xác thực...';
                    // Trigger /connect lại với credentials đã lưu (server tự re-login + gửi OTP)
                    let res;
                    try {
                        res = await POST('/reconnect');
                    } catch (e) {
                        gmailRenewBtn.disabled = false;
                        gmailRenewBtn.textContent = '🔄 Xác thực OTP tự động qua Gmail';
                        showToast(_err({ error: e.message }), 'error');
                        return;
                    }
                    if (res && res.success) {
                        if (res.auto_otp_pending) {
                            showToast('📧 Đang xác thực OTP tự động qua Gmail...', 'success');
                            dashData = null; status = null;
                            await renderCurrentTab();
                            await pollGmailOtp();
                        } else if (res.manual_otp) {
                            // Gmail không gửi được OTP → fallback form Smart OTP thủ công
                            showToast('⚠️ ' + (res.message || 'Vui lòng xác thực bằng Smart OTP.'), 'warning');
                            dashData = null;
                            // Render lại với gmail_connected=false để hiện form OTP thủ công
                            const st = await GET('/status');
                            if (st && st.success) {
                                st.gmail_connected = false; // force manual form
                                const tabContent = el.querySelector('.lcni-dnse-content');
                                if (tabContent) {
                                    tabContent.innerHTML = renderConnectTab(st);
                                    bindConnectEvents();
                                    bindOtpRadio();
                                }
                            }
                            return;
                        } else {
                            showToast(_msg(res), 'success');
                            dashData = null; status = null;
                            await renderCurrentTab();
                        }
                    } else {
                        gmailRenewBtn.disabled = false;
                        gmailRenewBtn.textContent = '🔄 Xác thực OTP tự động qua Gmail';
                        showToast(_err(res), 'error');
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
                    let res;
                    try {
                        res = await POST('/request-otp');
                    } catch (e) {
                        reqOtpBtn.disabled = false;
                        reqOtpBtn.textContent = T.request_otp;
                        showToast(_err({ error: e.message }), 'error');
                        return;
                    }
                    reqOtpBtn.disabled = false;
                    reqOtpBtn.textContent = T.request_otp;

                    if (res.success) {
                        showToast('📧 OTP đã gửi về email. Kiểm tra hộp thư và nhập mã bên dưới.', 'success');
                        // Focus vào ô nhập OTP
                        setTimeout(() => el.querySelector('#dnse-otp-input')?.focus(), 300);
                    } else {
                        showToast(_err(res), 'error');
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
                    let res;
                    try {
                        res = await POST('/verify-otp', { otp, otp_type });
                    } catch (e) {
                        verifyOtpBtn.disabled = false;
                        showToast(_err({ error: e.message }), 'error');
                        return;
                    }
                    verifyOtpBtn.disabled = false;
                    if (res.success) {
                        showToast(_msg(res), 'success');
                        status = null;
                        await renderCurrentTab();
                    } else {
                        showToast(_err(res), 'error');
                    }
                });
            }

            // ── Gmail connect ─────────────────────────────────────────────────
            const gmailConnectBtn    = el.querySelector('#dnse-gmail-connect-btn');
            const gmailDisconnectBtn = el.querySelector('#dnse-gmail-disconnect-btn');

            if (gmailConnectBtn) {
                gmailConnectBtn.addEventListener('click', async () => {
                    gmailConnectBtn.disabled = true;
                    gmailConnectBtn.textContent = 'Đang chuyển hướng...';
                    let res;
                    try {
                        res = await GET('/gmail-auth-url');
                    } catch(e) {
                        gmailConnectBtn.disabled = false;
                        gmailConnectBtn.innerHTML = '📧 Kết nối Gmail';
                        showToast(e.message || 'Lỗi kết nối.', 'error');
                        return;
                    }
                    if (res.success && res.url) {
                        // Redirect đến Google consent screen
                        window.location.href = res.url;
                    } else {
                        gmailConnectBtn.disabled = false;
                        gmailConnectBtn.innerHTML = '📧 Kết nối Gmail';
                        showToast(_err(res), 'error');
                    }
                });
            }

            if (gmailDisconnectBtn) {
                gmailDisconnectBtn.addEventListener('click', async () => {
                    if (!confirm('Ngắt kết nối Gmail? Sau đó bạn phải nhập OTP thủ công mỗi 8 giờ.')) return;
                    gmailDisconnectBtn.disabled = true;
                    let res;
                    try {
                        res = await POST('/gmail-disconnect');
                    } catch(e) {
                        gmailDisconnectBtn.disabled = false;
                        showToast(e.message || 'Lỗi.', 'error');
                        return;
                    }
                    if (res.success) {
                        showToast(_msg(res), 'success');
                        status = null;
                        await renderCurrentTab();
                    } else {
                        gmailDisconnectBtn.disabled = false;
                        showToast(_err(res), 'error');
                    }
                });
            }

            // ── Permissions save ──────────────────────────────────────────────
            const savePermsBtn = el.querySelector('#dnse-save-perms-btn');
            if (savePermsBtn) {
                savePermsBtn.addEventListener('click', async () => {
                    const permissions = [];
                    if (el.querySelector('#dnse-perm-auto-renew')?.checked) permissions.push('perm_auto_renew');
                    if (el.querySelector('#dnse-perm-trade')?.checked)      permissions.push('perm_trade');

                    savePermsBtn.disabled = true;
                    savePermsBtn.textContent = 'Đang lưu...';
                    let res;
                    try {
                        res = await POST('/save-permissions', { permissions });
                    } catch(e) {
                        savePermsBtn.disabled = false;
                        savePermsBtn.textContent = 'Lưu';
                        showToast('Lỗi: ' + e.message, 'error');
                        return;
                    }
                    savePermsBtn.disabled = false;
                    savePermsBtn.textContent = 'Lưu';

                    if (res.success) {
                        if (res.needs_password) {
                            // Cần nhập lại password để bật auto_renew
                            const reauthBox = el.querySelector('#dnse-reauth-box');
                            if (reauthBox) reauthBox.style.display = 'block';
                            showToast('Nhập lại mật khẩu để bật tự động đăng nhập.', 'warning');
                        } else {
                            showToast('✅ Đã lưu cài đặt.', 'success');
                            dashData = null; status = null;
                            await renderCurrentTab();
                        }
                    } else {
                        showToast(_err(res), 'error');
                    }
                });
            }

            // ── Reauth confirm (nhập lại password để lưu với auto_renew) ─────
            const reauthConfirmBtn = el.querySelector('#dnse-reauth-confirm-btn');
            if (reauthConfirmBtn) {
                reauthConfirmBtn.addEventListener('click', async () => {
                    const pw = el.querySelector('#dnse-reauth-password')?.value;
                    if (!pw) { showToast('Vui lòng nhập mật khẩu.', 'error'); return; }

                    const permissions = [];
                    if (el.querySelector('#dnse-perm-auto-renew')?.checked) permissions.push('perm_auto_renew');
                    if (el.querySelector('#dnse-perm-trade')?.checked)      permissions.push('perm_trade');

                    reauthConfirmBtn.disabled = true;
                    reauthConfirmBtn.textContent = 'Đang xác nhận...';
                    let res;
                    try {
                        // Re-connect để lưu password mới + permissions
                        const accountNo = el.querySelector('.lcni-dnse-account')?.textContent?.trim() || '';
                        res = await POST('/connect', { username: accountNo, password: pw, permissions });
                    } catch(e) {
                        reauthConfirmBtn.disabled = false;
                        reauthConfirmBtn.textContent = 'Xác nhận';
                        showToast('Lỗi: ' + e.message, 'error');
                        return;
                    }
                    reauthConfirmBtn.disabled = false;
                    reauthConfirmBtn.textContent = 'Xác nhận';

                    if (res.success) {
                        showToast('✅ Đã bật tự động đăng nhập.', 'success');
                        dashData = null; status = null;
                        await renderCurrentTab();
                    } else {
                        showToast(_err(res), 'error');
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
                    let res;
                    try {
                        res = await POST('/sync');
                    } catch (e) {
                        syncBtn.disabled = false;
                        syncBtn.textContent = `↻ ${T.sync}`;
                        showToast(_err({ error: e.message }), 'error');
                        return;
                    }
                    syncBtn.disabled = false;
                    syncBtn.textContent = `↻ ${T.sync}`;
                    if (res.success) {
                        dashData = null;
                        showToast(_msg(res), 'success');
                        await renderCurrentTab();
                    } else {
                        showToast(_err(res), 'error');
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

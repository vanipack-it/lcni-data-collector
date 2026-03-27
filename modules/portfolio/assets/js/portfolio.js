/* global lcniPortfolioConfig */
(function ($) {
    'use strict';

    if (typeof lcniPortfolioConfig === 'undefined') return;

    const { restUrl, nonce, activeId: initActiveId } = lcniPortfolioConfig;
    let portfolios    = lcniPortfolioConfig.portfolios || [];
    let activeId      = initActiveId;
    const dnseAccounts = lcniPortfolioConfig.dnseAccounts || [];
    const dnseOrderUrl = lcniPortfolioConfig.dnseOrderUrl || '';
    const hasDnse      = dnseAccounts.length > 0;

    // Khởi tạo DNSE section trong modal
    // ─────────────────────────────────────────────────────────
    // _syncPriceFieldToOrderType()
    // Disable/enable #pf-tx-price based on current #pf-dnse-order-type value.
    // Must be called: (1) on change event, AND (2) immediately on openTxModal().
    // ─────────────────────────────────────────────────────────
    function _syncPriceFieldToOrderType() {
        const orderTypeSel = document.getElementById('pf-dnse-order-type');
        const priceField   = document.getElementById('pf-tx-price');
        if (!orderTypeSel || !priceField) return;

        const noPrice = ['ATO', 'ATC', 'MP', 'PM'].includes(orderTypeSel.value);
        const group   = priceField.closest('.lcni-pf-form-group');

        priceField.disabled = noPrice;
        priceField.classList.toggle('lcni-disabled', noPrice);
        if (group) group.style.opacity = noPrice ? '0.4' : '1';
        if (noPrice) priceField.value = '';
    }


    // =========================================================================
    // BUYING POWER — Sức mua theo tài khoản
    // =========================================================================

    // Debounce timer cho fetch sức mua
    var _bpTimer = null;

    /**
     * fetchBuyingPower(accountNo, accountType, priceVnd, symbol)
     * Gọi REST /dnse/buying-power, cập nhật UI gợi ý max qty.
     * Debounced 400ms để không spam API khi user đang nhập giá.
     */
    function fetchBuyingPower() {
        // Chỉ fetch khi: có DNSE + modal đang mở + transaction type là mua
        if (!hasDnse) return;
        if (!$('#lcni-pf-tx-modal').is(':visible')) return;
        const txType = $('#pf-tx-type').val();
        if (txType !== 'buy') {
            _hideBuyingPower();
            return;
        }

        const accountNo  = $('#pf-dnse-account').val()   || '';
        const accountSel = document.getElementById('pf-dnse-account');
        const accountType= (accountSel && accountSel.selectedOptions[0]
                            && accountSel.selectedOptions[0].dataset.type) || 'spot';
        const priceVnd   = parseFloat($('#pf-tx-price').val()) || 0;
        const symbol     = ($('#pf-tx-symbol').val() || '').trim().toUpperCase();

        if (!accountNo || priceVnd <= 0) {
            _hideBuyingPower();
            return;
        }

        // Giá DB format (nghìn VNĐ) — REST endpoint nhận price ở DB format
        const priceDb = priceVnd / 1000;

        clearTimeout(_bpTimer);
        _bpTimer = setTimeout(function () {
            _showBuyingPowerLoading();

            $.ajax({
                url:     restUrl + '/dnse/buying-power',
                method:  'GET',
                headers: { 'X-WP-Nonce': nonce },
                data: {
                    account_no:   accountNo,
                    account_type: accountType,
                    symbol:       symbol,
                    price:        priceDb,
                },
            })
            .done(function (res) {
                if (res && res.success) {
                    _renderBuyingPower(res, priceVnd);
                } else {
                    _hideBuyingPower();
                }
            })
            .fail(function () { _hideBuyingPower(); });
        }, 400);
    }

    function _renderBuyingPower(res, priceVnd) {
        var maxVol      = parseInt(res.max_volume)    || 0;
        var buyingPower = parseFloat(res.buying_power)|| 0;

        var el = document.getElementById('lcni-pf-buying-power');
        if (!el) return;

        if (maxVol <= 0 && buyingPower <= 0) {
            _hideBuyingPower();
            return;
        }

        var html = '<span class="lcni-pf-bp-label">Sức mua:</span>'
                 + '<span class="lcni-pf-bp-money">' + fmtVnd(buyingPower) + '</span>';

        if (maxVol > 0) {
            html += '<button type="button" class="lcni-pf-bp-chip" data-max-vol="' + maxVol + '">'
                  + 'Tối đa <strong>' + fmtQty(maxVol) + '</strong> CP'
                  + '</button>';
        }

        el.innerHTML  = html;
        el.style.display = 'flex';

        // Click chip → điền khối lượng
        var chip = el.querySelector('.lcni-pf-bp-chip');
        if (chip) {
            chip.addEventListener('click', function () {
                $('#pf-tx-qty').val(this.dataset.maxVol).trigger('input');
                chip.classList.add('lcni-pf-bp-chip--applied');
                setTimeout(function () {
                    chip.classList.remove('lcni-pf-bp-chip--applied');
                }, 600);
            });
        }
    }

    function _showBuyingPowerLoading() {
        var el = document.getElementById('lcni-pf-buying-power');
        if (!el) return;
        el.innerHTML  = '<span class="lcni-pf-bp-label">Sức mua:</span>'
                      + '<span class="lcni-pf-bp-loading">Đang tải…</span>';
        el.style.display = 'flex';
    }

    function _hideBuyingPower() {
        var el = document.getElementById('lcni-pf-buying-power');
        if (el) el.style.display = 'none';
    }

    (function initDnseModal() {
        if (!hasDnse) return;
        // Populate tài khoản
        const sel = document.getElementById('pf-dnse-account');
        if (sel) {
            dnseAccounts.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.dataset.type = a.type;
                opt.textContent = a.label;
                sel.appendChild(opt);
            });
        }
        // Hiện section DNSE
        const sec = document.getElementById('lcni-pf-dnse-order-section');
        if (sec) sec.style.display = '';

        // Bind order-type change → sync price field
        const orderTypeSel = document.getElementById('pf-dnse-order-type');
        if (orderTypeSel) {
            orderTypeSel.addEventListener('change', _syncPriceFieldToOrderType);
        }
    })();
    let allocChart  = null;
    let equityChart = null;
    let equityLimit = 30;

    // =========================================================
    // API helpers
    // =========================================================
    function api(method, endpoint, data = {}) {
        return $.ajax({
            url: restUrl + endpoint,
            method,
            headers: { 'X-WP-Nonce': nonce },
            data: method === 'GET' ? data : JSON.stringify(data),
            contentType: method !== 'GET' ? 'application/json' : undefined,
            dataType: 'json',
        });
    }

    // =========================================================
    // Number formatters
    // =========================================================
    function fmtVnd(n) {
        n = parseFloat(n) || 0;
        if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2) + ' tỷ';
        if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1) + ' tr';
        return n.toLocaleString('vi-VN') + ' đ';
    }
    function fmtQty(n) {
        return parseFloat(n).toLocaleString('vi-VN');
    }
    function fmtPct(n) {
        const v = parseFloat(n) || 0;
        return (v >= 0 ? '+' : '') + v.toFixed(2) + '%';
    }
    function pnlClass(n) {
        return parseFloat(n) >= 0 ? 'lcni-pf-positive' : 'lcni-pf-negative';
    }
    function typeLabel(t) {
        const map = { buy: 'Mua', sell: 'Bán', dividend: 'Cổ tức', fee: 'Phí' };
        return `<span class="lcni-pf-type lcni-pf-type-${t}">${map[t] || t}</span>`;
    }

    function sourceBadge(source) {
        if (source === 'dnse')     return '<span class="lcni-pf-source-badge lcni-pf-source-dnse">⚡ DNSE</span>';
        if (source === 'combined') return '<span class="lcni-pf-source-badge lcni-pf-source-combined">🔗 Tổng hợp</span>';
        return '';
    }
    function sourceIcon(source) {
        if (source === 'dnse')     return '⚡ ';
        if (source === 'combined') return '🔗 ';
        return '';
    }

    // =========================================================
    // Load portfolio data
    // =========================================================
    function loadPortfolio(id) {
        activeId = id;
        showOverlay(true);
        api('GET', '/portfolio/data', { portfolio_id: id })
            .done(res => {
                if (!res.success) return;
                const d = res.data;
                renderSummary(d.summary);
                renderHoldings(d.holdings);
                renderAllocation(d.allocation);
                renderTransactions(d.transactions);
            })
            .always(() => showOverlay(false));
        loadEquityCurve(id, equityLimit);
    }

    // =========================================================
    // Summary cards
    // =========================================================
    function renderSummary(s) {
        // Hiển thị toolbar DNSE sync nếu source=dnse hoặc combined
        const activePf = portfolios.find(p => p.id == activeId);
        const src = (activePf && activePf.source) || s.source || 'manual';
        let dnseBar = '';
        if (src === 'dnse') {
            dnseBar = `<div class="lcni-pf-dnse-bar">
                <span class="lcni-pf-source-badge lcni-pf-source-dnse">⚡ DNSE Live</span>
                <button class="lcni-btn lcni-pf-sync-dnse-btn" data-account="${activePf?.dnse_account_no||''}">↻ Đồng bộ lệnh</button>
                <small style="color:#9ca3af">Vị thế realtime từ DNSE</small>
            </div>`;
        } else if (src === 'combined') {
            dnseBar = `<div class="lcni-pf-dnse-bar">
                <span class="lcni-pf-source-badge lcni-pf-source-combined">🔗 Tổng hợp</span>
                <small style="color:#9ca3af">Gộp từ ${activePf?.dnse_combined_ids ? activePf.dnse_combined_ids.split(',').length : '?'} danh mục</small>
            </div>`;
        }
        // Luôn xóa bar cũ trước, sau đó thêm mới nếu cần
        $('#lcni-pf-dnse-bar').remove();
        if (dnseBar) {
            $('#lcni-pf-summary').before(dnseBar);
        }
        const set = (id, val, pnl = false) => {
            const el = $('#' + id);
            el.text(val);
            if (pnl) el.removeClass('positive negative').addClass(parseFloat(s.total_unrealized_pnl) >= 0 ? '' : '');
        };
        $('#pf-total-value').text(fmtVnd(s.total_market_value));
        $('#pf-cost-basis').text(fmtVnd(s.total_cost_basis));

        const unr = parseFloat(s.total_unrealized_pnl);
        const rlz = parseFloat(s.total_realized_pnl);
        const tot = parseFloat(s.total_pnl);

        $('#pf-unrealized')
            .text(fmtVnd(unr) + ' (' + fmtPct(s.total_pnl_pct) + ')')
            .removeClass('positive negative').addClass(pnlClass(unr));
        $('#pf-realized')
            .text(fmtVnd(rlz))
            .removeClass('positive negative').addClass(pnlClass(rlz));
        $('#pf-total-pnl')
            .text(fmtVnd(tot))
            .removeClass('positive negative').addClass(pnlClass(tot));

        $('#pf-holding-count').text(s.holding_count + ' mã');
    }

    // =========================================================
    // Holdings table
    // =========================================================
    function renderHoldings(holdings) {
        const tbody = $('#lcni-pf-holdings-body').empty();
        const active = holdings.filter(h => h.is_holding);
        if (!active.length) {
            tbody.html('<tr><td colspan="7" class="lcni-pf-loading">Chưa có cổ phiếu nào.</td></tr>');
            return;
        }
        active.forEach(h => {
            tbody.append(`
                <tr>
                    <td class="lcni-sticky-col"><span class="lcni-pf-symbol">${h.symbol}</span></td>
                    <td>${fmtQty(h.quantity)}</td>
                    <td>${fmtVnd(h.avg_cost)}</td>
                    <td>${h.current_price > 0 ? fmtVnd(h.current_price) : '<span style="color:#9ca3af">—</span>'}</td>
                    <td>${fmtVnd(h.market_value)}</td>
                    <td class="${pnlClass(h.unrealized_pnl)}">${fmtVnd(h.unrealized_pnl)}</td>
                    <td class="${pnlClass(h.unrealized_pct)}">${fmtPct(h.unrealized_pct)}</td>
                </tr>`);
        });
    }

    // =========================================================
    // Allocation donut chart
    // =========================================================
    const COLORS = ['#1d4ed8','#0891b2','#16a34a','#d97706','#dc2626','#7c3aed','#db2777','#0d9488','#65a30d','#9333ea'];

    function renderAllocation(allocation) {
        const legend = $('#lcni-pf-alloc-legend').empty();
        if (!allocation || !allocation.length) {
            legend.html('<p style="color:#9ca3af;font-size:12px;text-align:center;">Không có dữ liệu.</p>');
            if (allocChart) { allocChart.destroy(); allocChart = null; }
            return;
        }

        const labels = allocation.map(a => a.symbol);
        const data   = allocation.map(a => a.pct);
        const colors = allocation.map((_, i) => COLORS[i % COLORS.length]);

        if (allocChart) allocChart.destroy();
        const ctx = document.getElementById('lcni-pf-alloc-chart').getContext('2d');
        allocChart = new Chart(ctx, {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
            options: {
                responsive: true,
                plugins: { legend: { display: false }, tooltip: {
                    callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}%` }
                }},
                cutout: '65%',
            }
        });

        allocation.forEach((a, i) => {
            legend.append(`
                <div class="lcni-pf-alloc-item">
                    <span class="lcni-pf-alloc-dot" style="background:${colors[i]}"></span>
                    <span class="lcni-pf-alloc-label">${a.symbol}</span>
                    <span class="lcni-pf-alloc-pct">${a.pct}%</span>
                </div>`);
        });
    }

    // =========================================================
    // Equity curve
    // =========================================================
    function loadEquityCurve(id, limit) {
        api('GET', '/portfolio/equity-curve', { portfolio_id: id, limit })
            .done(res => {
                if (!res.success) return;
                renderEquityCurve(res.data.curve);
            });
    }

    function renderEquityCurve(curve) {
        if (equityChart) equityChart.destroy();
        if (!curve || curve.length < 2) {
            $('#lcni-pf-equity-chart').closest('.lcni-pf-equity-wrap')
                .html('<p style="color:#9ca3af;font-size:13px;text-align:center;padding:40px 0;">Chưa đủ dữ liệu (cần ít nhất 2 ngày).</p>');
            return;
        }

        const labels = curve.map(r => r.snapshot_date);
        const values = curve.map(r => parseFloat(r.total_value));
        const base   = values[0] || 1;
        const isUp   = values[values.length - 1] >= base;
        const color  = isUp ? '#16a34a' : '#dc2626';

        const canvas = document.getElementById('lcni-pf-equity-chart');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        equityChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    data: values,
                    borderColor: color,
                    backgroundColor: isUp ? 'rgba(22,163,74,.1)' : 'rgba(220,38,38,.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 6, font: { size: 11 } } },
                    y: { grid: { color: '#f3f4f6' }, ticks: {
                        font: { size: 11 },
                        callback: v => {
                            if (v >= 1e9) return (v/1e9).toFixed(1) + 'tỷ';
                            if (v >= 1e6) return (v/1e6).toFixed(0) + 'tr';
                            return v.toLocaleString('vi-VN');
                        }
                    }}
                }
            }
        });
    }

    // =========================================================
    // Transactions table
    // =========================================================
    function renderTransactions(txs) {
        const tbody = $('#lcni-pf-tx-body').empty();
        if (!txs || !txs.length) {
            tbody.html('<tr><td colspan="10" class="lcni-pf-loading">Chưa có giao dịch nào.</td></tr>');
            return;
        }
        txs.forEach(tx => {
            const priceVnd = parseFloat(tx.price);
            const total = parseFloat(tx.quantity) * priceVnd;
            tbody.append(`
                <tr>
                    <td class="lcni-sticky-col">${tx.trade_date}</td>
                    <td><span class="lcni-pf-symbol">${tx.symbol}</span></td>
                    <td>${typeLabel(tx.type)}</td>
                    <td>${fmtQty(tx.quantity)}</td>
                    <td>${fmtVnd(priceVnd)}</td>
                    <td style="color:#6b7280">${fmtVnd(tx.fee)}</td>
                    <td style="color:#6b7280">${fmtVnd(tx.tax)}</td>
                    <td><strong>${fmtVnd(total)}</strong></td>
                    <td style="color:#9ca3af;max-width:120px;overflow:hidden;text-overflow:ellipsis;">${tx.note || ''}</td>
                    <td>
                        <button class="lcni-pf-btn-icon" data-action="edit-tx" data-id="${tx.id}" title="Sửa">✏️</button>
                        <button class="lcni-pf-btn-icon" data-action="del-tx" data-id="${tx.id}" title="Xoá">🗑️</button>
                    </td>
                </tr>`);
        });

        // Attach tx data for edit modal
        window._lcniTxCache = {};
        txs.forEach(tx => { window._lcniTxCache[tx.id] = tx; });
    }

    // =========================================================
    // Price unit helper
    // DB/API lưu giá dạng nghìn đồng: 21.5 = 21,500 VNĐ
    // PHP API đã convert sang VNĐ đầy đủ khi trả về.
    // Khi POST lên server: JS cần convert VNĐ → DB format.
    // =========================================================
    function vndPriceToDb(p) { return (parseFloat(p) / 1000).toFixed(4); }

    // =========================================================
    // Transaction Modal
    // =========================================================
    function openTxModal(tx = null) {
        const modal = $('#lcni-pf-tx-modal');
        // _prefill flag: modal opened with pre-filled symbol/price but NOT in edit mode
        const isPrefill = !!(tx && tx._prefill);
        const isEdit = !!tx && !isPrefill;
        const isBuySell = !isEdit || ['buy','sell'].includes(tx?.type);
        if (hasDnse && !isEdit) {
            $('#lcni-pf-modal-title').text('Thêm giao dịch');
            $('#lcni-pf-tx-save').html('💾 Lưu giao dịch');
            $('#lcni-pf-dnse-order-section').show();
        } else {
            $('#lcni-pf-modal-title').text(isEdit ? 'Sửa giao dịch' : 'Thêm giao dịch');
            $('#lcni-pf-tx-save').html('💾 Lưu giao dịch');
            $('#lcni-pf-dnse-order-section').hide();
        }
        $('#lcni-pf-modal-title').text(isEdit ? 'Sửa giao dịch' : 'Thêm giao dịch');
        $('#pf-tx-id').val(tx ? tx.id : '');
        $('#pf-tx-symbol').val(tx ? tx.symbol : '').prop('readonly', isEdit);
        $('#pf-tx-type').val(tx ? tx.type : 'buy');
        $('#pf-tx-date').val(tx ? tx.trade_date : new Date().toISOString().slice(0,10));
        $('#pf-tx-qty').val(tx ? tx.quantity : '');
        // PHP đã convert price sang VNĐ đầy đủ - dùng trực tiếp
        $('#pf-tx-price').val(tx ? tx.price : '');
        $('#pf-tx-fee').val(tx ? tx.fee : 0);
        $('#pf-tx-tax').val(tx ? tx.tax : 0);
        $('#pf-tx-note').val(tx ? tx.note : '');
        $('#lcni-pf-tx-error').hide();
        updateTotalPreview();

        // Reset DNSE order type to LO for new/prefill transactions
        // so price field starts enabled. For edits, keep current selection.
        if (!isEdit) {
            const orderTypeSel = document.getElementById('pf-dnse-order-type');
            if (orderTypeSel) orderTypeSel.value = 'LO';
        }

        // ALWAYS sync price-field disabled state to current order type on open.
        // Without this call, the field stays disabled if user closed modal mid-ATC.
        _syncPriceFieldToOrderType();

        // Reset buying power widget on open, then fetch if we have price
        _hideBuyingPower();
        if (hasDnse && !isEdit && ($('#pf-tx-price').val() || 0) > 0) {
            fetchBuyingPower();
        }

        modal.show();
        setTimeout(() => (isPrefill || isEdit ? $('#pf-tx-qty') : $('#pf-tx-symbol')).focus(), 50);
    }
    function closeTxModal() { $('#lcni-pf-tx-modal').hide(); _hideBuyingPower(); }

    function updateTotalPreview() {
        const qty   = parseFloat($('#pf-tx-qty').val()) || 0;
        const price = parseFloat($('#pf-tx-price').val()) || 0;
        const fee   = parseFloat($('#pf-tx-fee').val()) || 0;
        const tax   = parseFloat($('#pf-tx-tax').val()) || 0;
        const type  = $('#pf-tx-type').val();
        const total = type === 'sell'
            ? qty * price - fee - tax
            : qty * price + fee;
        $('#pf-tx-total').text(total ? fmtVnd(total) : '—');
    }

    function saveTx() {
        const txId     = $('#pf-tx-id').val();
        const isNew    = !txId;
        const priceVnd = parseFloat($('#pf-tx-price').val()) || 0;
        const type     = $('#pf-tx-type').val();

        // ── Collect order data ──────────────────────────────────
        const order = {
            portfolioId:      activeId,
            txId:             txId || undefined,
            symbol:           $('#pf-tx-symbol').val().trim().toUpperCase(),
            type:             type,
            date:             $('#pf-tx-date').val(),
            qty:              $('#pf-tx-qty').val(),
            priceVnd:         priceVnd,
            fee:              $('#pf-tx-fee').val() || 0,
            tax:              $('#pf-tx-tax').val() || 0,
            note:             $('#pf-tx-note').val(),

            // DNSE fields — only populated when user has DNSE connected
            sendDnse:         isNew
                                && hasDnse
                                && $('#pf-dnse-send-order').prop('checked')
                                && ['buy', 'sell'].includes(type),
            dnseAccountNo:    $('#pf-dnse-account').val() || '',
            dnseOrderType:    $('#pf-dnse-order-type').val() || 'LO',
            dnseAccountType:  (() => {
                                const opt = document.getElementById('pf-dnse-account');
                                return (opt && opt.selectedOptions[0]
                                    && opt.selectedOptions[0].dataset.type) || 'spot';
                              })(),
        };

        // ── Basic validation ────────────────────────────────────
        // Market order types (ATO/ATC/MP/PM) không cần giá — price = 0 hợp lệ.
        const isMarketOrder = ['ATO','ATC','MP','PM'].includes(order.dnseOrderType)
                              && order.sendDnse;

        if (!order.symbol) {
            $('#lcni-pf-tx-error').text('Vui lòng nhập mã chứng khoán.').show(); return;
        }
        if (!order.qty || parseFloat(order.qty) <= 0) {
            $('#lcni-pf-tx-error').text('Khối lượng phải lớn hơn 0.').show(); return;
        }
        if (!isMarketOrder && order.priceVnd <= 0) {
            $('#lcni-pf-tx-error').text('Giá phải lớn hơn 0 (lệnh giới hạn/thủ công).').show(); return;
        }

        $('#lcni-pf-tx-save').prop('disabled', true).text('Đang lưu...');
        $('#lcni-pf-tx-error').hide();

        // ── Execute via unified LCNIOrderService ────────────────
        const svc = window.LCNIOrderService;
        const promise = svc
            ? (isNew ? svc.execute(order) : svc.executeUpdate(order))
            : _legacySaveTx(order, isNew); // fallback if service not loaded

        promise
            .then(result => {
                closeTxModal();
                loadPortfolio(activeId);

                // Warn about DNSE failure without blocking the success flow
                if (result && result.dnseError) {
                    setTimeout(() => {
                        $('#lcni-pf-tx-error')
                            .text('⚠ Giao dịch đã lưu nhưng đặt lệnh DNSE thất bại: ' + result.dnseError)
                            .show();
                    }, 200);
                } else if (result && result.dnseOrderId) {
                    const msg = '✅ Đặt lệnh DNSE thành công. Mã lệnh: ' + result.dnseOrderId;
                    console.info('[LCNI DNSE]', msg);
                }
            })
            .catch(err => {
                const msg = (err && err.message) ? err.message : 'Lỗi không xác định.';
                $('#lcni-pf-tx-error').text(msg).show();
            })
            .finally(() => {
                $('#lcni-pf-tx-save').prop('disabled', false).text('💾 Lưu giao dịch');
            });
    }

    // Fallback khi LCNIOrderService chưa load (edge case)
    function _legacySaveTx(order, isNew) {
        return new Promise((resolve, reject) => {
            const endpoint = isNew ? '/portfolio/tx/add' : '/portfolio/tx/update';
            const data = {
                portfolio_id: order.portfolioId,
                tx_id:        order.txId,
                symbol:       order.symbol,
                type:         order.type,
                trade_date:   order.date,
                quantity:     order.qty,
                price:        vndPriceToDb(order.priceVnd),
                fee:          order.fee,
                tax:          order.tax,
                note:         order.note,
            };
            api('POST', endpoint, data)
                .done(res => res.success ? resolve(res) : reject(new Error(res.data || 'Lỗi không xác định.')))
                .fail(() => reject(new Error('Có lỗi kết nối.')));
        });
    }

    function deleteTx(txId) {
        if (!confirm('Xoá giao dịch này?')) return;
        api('POST', '/portfolio/tx/delete', { tx_id: txId })
            .done(res => { if (res.success) loadPortfolio(activeId); });
    }

    // =========================================================
    // Portfolio tab switching
    // =========================================================
    function renderTabs() {
        const container = $('#lcni-pf-tabs');
        container.find('.lcni-pf-tab:not(.lcni-pf-tab-add)').remove();
        portfolios.forEach(p => {
            const badge = sourceBadge(p.source || 'manual');
            const btn = $(`<button class="lcni-pf-tab${p.id == activeId ? ' active' : ''} lcni-pf-tab-source-${p.source||'manual'}" data-id="${p.id}" data-source="${p.source||'manual'}">
                ${$('<span>').text(p.name).html()}
                ${p.is_default ? '<span class="lcni-pf-default-dot"></span>' : ''}
            </button>`);
            container.find('.lcni-pf-tab-add').before(btn);
        });
    }

    // =========================================================
    // Events
    // =========================================================
    $(document).on('click', '.lcni-pf-tab:not(.lcni-pf-tab-add)', function () {
        const id = parseInt($(this).data('id'));
        if (id && id !== activeId) {
            $('.lcni-pf-tab').removeClass('active');
            $(this).addClass('active');
            loadPortfolio(id);
        }
    });

    // Add portfolio
    $('#lcni-pf-add-btn').on('click', () => {
        $('#pf-new-name').val('');
        $('#pf-new-desc').val('');
        $('#lcni-pf-create-modal').show();
        setTimeout(() => $('#pf-new-name').focus(), 50);
    });
    $('#lcni-pf-create-close, #lcni-pf-create-cancel').on('click', () => $('#lcni-pf-create-modal').hide());
    // Sync DNSE orders to portfolio
    $(document).on('click', '.lcni-pf-sync-dnse-btn', function() {
        const btn = $(this);
        const accountNo = btn.data('account');
        if (!accountNo) { alert('Không xác định được số tài khoản DNSE.'); return; }
        btn.prop('disabled', true).text('Đang đồng bộ...');
        api('POST', '/portfolio/sync-dnse', { account_no: accountNo })
            .done(res => {
                if (res.success) {
                    alert(res.data.message || 'Đồng bộ thành công!');
                    loadPortfolio(activeId);
                } else {
                    alert('Lỗi: ' + (res.message || 'Không thể đồng bộ.'));
                }
            })
            .fail(() => alert('Lỗi kết nối.'))
            .always(() => btn.prop('disabled', false).text('↻ Đồng bộ lệnh'));
    });

    // Tạo danh mục tổng hợp
    $(document).on('click', '#lcni-pf-create-combined-btn', function() {
        const checked = $('input[name="pf-combine-ids"]:checked').map((_, el) => parseInt(el.value)).get();
        if (!checked.length) { alert('Chọn ít nhất 1 danh mục để gộp.'); return; }
        const name = $('#pf-combine-name').val().trim() || 'Tổng hợp';
        api('POST', '/portfolio/create-combined', { name, portfolio_ids: checked })
            .done(res => {
                if (res.success) {
                    $('#lcni-pf-combine-modal').hide();
                    api('GET', '/portfolio/list-with-meta')
                        .done(r => {
                            if (r.success) {
                                portfolios = r.data.portfolios;
                                activeId = res.data.id;
                                renderTabs();
                                loadPortfolio(activeId);
                            }
                        });
                }
            });
    });

    // Nút mở modal tạo danh mục tổng hợp
    $(document).on('click', '#lcni-pf-tab-combined', function() {
        // Build modal nếu chưa có
        if (!$('#lcni-pf-combine-modal').length) {
            const manualPfs = portfolios.filter(p => (p.source || 'manual') !== 'combined');
            const opts = manualPfs.map(p =>
                `<label style="display:flex;gap:8px;align-items:center;padding:6px;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer">
                    <input type="checkbox" name="pf-combine-ids" value="${p.id}">
                    ${sourceBadge(p.source||'manual')} ${p.name}
                </label>`
            ).join('');
            $('body').append(`<div id="lcni-pf-combine-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99999;display:flex;align-items:center;justify-content:center">
                <div style="background:#fff;border-radius:12px;padding:20px;min-width:320px;max-width:480px;width:90vw">
                    <h3 style="margin:0 0 12px">🔗 Tạo danh mục tổng hợp</h3>
                    <input id="pf-combine-name" type="text" placeholder="Tên danh mục tổng hợp" style="width:100%;height:36px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:12px;box-sizing:border-box">
                    <p style="font-size:12px;color:#6b7280;margin:0 0 8px">Chọn các danh mục muốn gộp:</p>
                    <div style="display:flex;flex-direction:column;gap:6px;max-height:240px;overflow:auto">${opts}</div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                        <button onclick="$('#lcni-pf-combine-modal').remove()" class="lcni-btn">Hủy</button>
                        <button id="lcni-pf-create-combined-btn" class="lcni-btn lcni-btn-primary">Tạo</button>
                    </div>
                </div>
            </div>`);
        } else {
            $('#lcni-pf-combine-modal').show();
        }
    });

    $('#lcni-pf-create-save').on('click', function () {
        const name = $('#pf-new-name').val().trim() || 'Danh mục mới';
        const desc = $('#pf-new-desc').val().trim();
        $(this).prop('disabled', true).text('Đang tạo...');
        api('POST', '/portfolio/create', { name, description: desc })
            .done(res => {
                if (res.success) {
                    $('#lcni-pf-create-modal').hide();
                    api('GET', '/portfolio/list')
                        .done(r => {
                            if (r.success) {
                                portfolios = r.data.portfolios;
                                activeId   = res.data.id;
                                renderTabs();
                                loadPortfolio(activeId);
                            }
                        });
                }
            })
            .always(() => $('#lcni-pf-create-save').prop('disabled', false).text('Tạo danh mục'));
    });

    // ── Nút "Thêm giao dịch" — delegate tới LCNITransactionController ──
    // Unified entry point cho tất cả UI triggers: portfolio button,
    // floating button, inline button đều gọi cùng một controller.
    $('#lcni-pf-add-tx-btn').on('click', function () {
        if (window.LCNITransactionController) {
            window.LCNITransactionController.openModal({});
        } else {
            // Fallback: gọi trực tiếp nếu controller chưa load (edge case)
            openTxModal();
        }
    });

    // Internal modal — still used for EDIT transactions
    $('#lcni-pf-modal-close, #lcni-pf-modal-cancel').on('click', closeTxModal);
    $('#lcni-pf-tx-modal').on('click', function (e) { if ($(e.target).is(this)) closeTxModal(); });

    // Live total preview
    $('#pf-tx-qty, #pf-tx-price, #pf-tx-fee, #pf-tx-tax, #pf-tx-type').on('input change', updateTotalPreview);

    // Buying power: re-fetch khi account, price, hoặc transaction type thay đổi
    if (hasDnse) {
        $(document).on('change', '#pf-dnse-account', fetchBuyingPower);
        $(document).on('input change', '#pf-tx-price', fetchBuyingPower);
        $(document).on('change', '#pf-tx-type', fetchBuyingPower);
    }

    // Ẩn/hiện DNSE section theo loại giao dịch
    $('#pf-tx-type').on('change', function () {
        const t = $(this).val();
        if (hasDnse) {
            const showDnse = ['buy','sell'].includes(t) && !$('#pf-tx-id').val();
            $('#lcni-pf-dnse-order-section').toggle(showDnse);
        }
    });

    // Auto-calc 0.1% sell tax (thuế = KL × giá VNĐ × 0.1%)
    $('#pf-tx-type').on('change', function () {
        if ($(this).val() === 'sell') {
            const val = (parseFloat($('#pf-tx-qty').val()) || 0) * (parseFloat($('#pf-tx-price').val()) || 0) * 0.001;
            if (val > 0) $('#pf-tx-tax').val(Math.round(val));
        } else {
            $('#pf-tx-tax').val(0);
        }
        updateTotalPreview();
    });
    $('#pf-tx-price, #pf-tx-qty').on('input', function () {
        if ($('#pf-tx-type').val() === 'sell') {
            const val = (parseFloat($('#pf-tx-qty').val()) || 0) * (parseFloat($('#pf-tx-price').val()) || 0) * 0.001;
            $('#pf-tx-tax').val(val > 0 ? Math.round(val) : 0);
        }
        updateTotalPreview();
    });

    // Symbol uppercase
    $('#pf-tx-symbol').on('input', function () { $(this).val($(this).val().toUpperCase()); });

    $('#lcni-pf-tx-save').on('click', saveTx);
    $('#pf-tx-note, #pf-tx-symbol, #pf-tx-qty, #pf-tx-price').on('keydown', function (e) {
        if (e.key === 'Enter') saveTx();
    });

    // Edit / Delete tx via row buttons
    $(document).on('click', '[data-action="edit-tx"]', function () {
        const id = parseInt($(this).data('id'));
        const tx = window._lcniTxCache && window._lcniTxCache[id];
        if (tx) openTxModal(tx);
    });
    $(document).on('click', '[data-action="del-tx"]', function () {
        deleteTx(parseInt($(this).data('id')));
    });

    // Period buttons for equity chart
    $(document).on('click', '.lcni-pf-period', function () {
        $('.lcni-pf-period').removeClass('active');
        $(this).addClass('active');
        equityLimit = parseInt($(this).data('limit'));
        loadEquityCurve(activeId, equityLimit);
    });

    // =========================================================
    // Helpers
    // =========================================================
    function showOverlay(show) {
        $('#lcni-pf-overlay').toggle(show);
    }

    // =========================================================
    // Boot
    // =========================================================
    function boot() {
        // Chỉ khởi động portfolio UI nếu container tồn tại trên trang.
        // Khi chỉ có [lcni_add_transaction_float] (không có [lcni_portfolio]),
        // portfolio.js chỉ expose lcniOpenPortfolioTxModal mà không render gì.
        if (!document.getElementById('lcni-portfolio-app')) return;

        if (typeof Chart === 'undefined') {
            // Load Chart.js from CDN dynamically
            const s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
            s.onload = () => loadPortfolio(activeId);
            document.head.appendChild(s);
        } else {
            loadPortfolio(activeId);
        }
    }

    $(document).ready(boot);

    // ── Expose reload hook for LCNITransactionController ─────
    window.lcniPortfolioReload = function () {
        if (activeId) loadPortfolio(activeId);
    };

    // ── Expose portfolio modal as the UNIFIED transaction entry ──
    // [lcni_add_transaction_float] và nút "Thêm giao dịch" đều gọi
    // hàm này → cùng một modal, cùng DNSE, cùng luồng lưu giao dịch.
    window.lcniOpenPortfolioTxModal = function (opts) {
        opts = opts || {};

        // Đảm bảo modal DOM tồn tại — nếu trang không có [lcni_portfolio]
        // shortcode thì modal chưa được render vào HTML, cần tạo runtime.
        if (!$('#lcni-pf-tx-modal').length) {
            _ensureStandaloneModal();
        }

        // Pass symbol + price opts when called from price-cell click
        // (e.g. LCNITransactionController.openModal({ symbol: "HPG", price: 25.4 }))
        var preFill = null;
        if (opts.symbol || opts.price) {
            preFill = {
                // Mimic the tx record shape openTxModal() expects for pre-fill:
                // id=null → treated as new transaction, not edit
                id:         null,
                symbol:     String(opts.symbol || '').toUpperCase(),
                type:       opts.type || 'buy',
                trade_date: new Date().toISOString().slice(0, 10),
                quantity:   '',
                price:      opts.price || '',
                fee:        0,
                tax:        0,
                note:       '',
                _prefill:   true,  // internal flag: pre-fill only, not edit mode
            };
        }
        openTxModal(preFill);
    };

    // Tạo modal DOM runtime khi trang chỉ có [lcni_add_transaction_float]
    // mà không có [lcni_portfolio] shortcode.
    function _ensureStandaloneModal() {
        if ($('#lcni-pf-tx-modal').length) return;
        const today = new Date().toISOString().slice(0, 10);
        $('body').append(`
            <div id="lcni-pf-tx-modal" class="lcni-pf-modal" style="display:none;">
                <div class="lcni-pf-modal-box">
                    <div class="lcni-pf-modal-header">
                        <h3 id="lcni-pf-modal-title">Thêm giao dịch</h3>
                        <button class="lcni-pf-modal-close" id="lcni-pf-modal-close">✕</button>
                    </div>
                    <div class="lcni-pf-modal-body">
                        <input type="hidden" id="pf-tx-id" value="">
                        <div class="lcni-pf-form-row">
                            <div class="lcni-pf-form-group">
                                <label>Mã chứng khoán <span class="req">*</span></label>
                                <input type="text" id="pf-tx-symbol" placeholder="VD: VNM, HPG..." maxlength="10" style="text-transform:uppercase;">
                            </div>
                            <div class="lcni-pf-form-group">
                                <label>Loại giao dịch <span class="req">*</span></label>
                                <select id="pf-tx-type">
                                    <option value="buy">🟢 Mua</option>
                                    <option value="sell">🔴 Bán</option>
                                    <option value="dividend">💰 Cổ tức</option>
                                    <option value="fee">💸 Phí</option>
                                </select>
                            </div>
                        </div>
                        <div class="lcni-pf-form-row">
                            <div class="lcni-pf-form-group">
                                <label>Ngày giao dịch <span class="req">*</span></label>
                                <input type="date" id="pf-tx-date" value="${today}">
                            </div>
                            <div class="lcni-pf-form-group">
                                <label>Khối lượng <span class="req">*</span></label>
                                <input type="number" id="pf-tx-qty" placeholder="VD: 1000" min="0" step="100">
                                <div id="lcni-pf-buying-power" class="lcni-pf-buying-power" style="display:none;"></div>
                            </div>
                        </div>
                        <div class="lcni-pf-form-row">
                            <div class="lcni-pf-form-group">
                                <label>Giá (VNĐ) <span class="req">*</span></label>
                                <input type="number" id="pf-tx-price" placeholder="VD: 21500" min="0" step="100">
                            </div>
                            <div class="lcni-pf-form-group">
                                <label>Phí giao dịch (VNĐ)</label>
                                <input type="number" id="pf-tx-fee" placeholder="0" min="0" value="0">
                            </div>
                        </div>
                        <div class="lcni-pf-form-row">
                            <div class="lcni-pf-form-group">
                                <label>Thuế (VNĐ) <span style="color:#9ca3af;font-size:11px;">0.1% khi bán</span></label>
                                <input type="number" id="pf-tx-tax" placeholder="0" min="0" value="0">
                            </div>
                            <div class="lcni-pf-form-group">
                                <label>Tổng tiền (ước tính)</label>
                                <div id="pf-tx-total" class="lcni-pf-total-preview">—</div>
                            </div>
                        </div>
                        <div class="lcni-pf-form-group">
                            <label>Ghi chú</label>
                            <input type="text" id="pf-tx-note" placeholder="VD: Mua theo sóng...">
                        </div>
                        <div id="lcni-pf-tx-error" class="lcni-pf-error" style="display:none;"></div>
                        <div id="lcni-pf-dnse-order-section" style="display:none;">
                            <div class="lcni-pf-dnse-section-divider"><span>⚡ Đặt lệnh thực qua DNSE</span></div>
                            <div class="lcni-pf-form-row">
                                <div class="lcni-pf-form-group">
                                    <label>Tài khoản DNSE</label>
                                    <select id="pf-dnse-account"></select>
                                </div>
                                <div class="lcni-pf-form-group">
                                    <label>Loại lệnh</label>
                                    <select id="pf-dnse-order-type">
                                        <option value="LO">LO — Giới hạn</option>
                                        <option value="ATO">ATO — Mở cửa</option>
                                        <option value="ATC">ATC — Đóng cửa</option>
                                        <option value="MP">MP — Thị trường</option>
                                    </select>
                                </div>
                            </div>
                            <div class="lcni-pf-dnse-note">
                                Lệnh sẽ được gửi tới DNSE <strong>và</strong> lưu vào danh mục cùng lúc.
                            </div>
                            <label class="lcni-pf-dnse-toggle-label">
                                <input type="checkbox" id="pf-dnse-send-order" checked>
                                Gửi lệnh thực tới DNSE
                            </label>
                        </div>
                    </div>
                    <div class="lcni-pf-modal-footer">
                        <button class="lcni-pf-btn lcni-pf-btn-ghost" id="lcni-pf-modal-cancel">Huỷ</button>
                        <button class="lcni-pf-btn lcni-pf-btn-primary" id="lcni-pf-tx-save">💾 Lưu giao dịch</button>
                    </div>
                </div>
            </div>`);

        // Re-bind modal events for the dynamically created modal
        $('#lcni-pf-modal-close, #lcni-pf-modal-cancel').off('click').on('click', closeTxModal);
        $('#lcni-pf-tx-modal').off('click').on('click', function(e) { if ($(e.target).is(this)) closeTxModal(); });
        $('#pf-tx-qty, #pf-tx-price, #pf-tx-fee, #pf-tx-tax, #pf-tx-type').off('input change').on('input change', updateTotalPreview);
        $('#pf-tx-type').off('change.tax').on('change.tax', function() {
            if ($(this).val() === 'sell') {
                const v = (parseFloat($('#pf-tx-qty').val()) || 0) * (parseFloat($('#pf-tx-price').val()) || 0) * 0.001;
                if (v > 0) $('#pf-tx-tax').val(Math.round(v));
            } else { $('#pf-tx-tax').val(0); }
            updateTotalPreview();
        });
        $('#pf-tx-price, #pf-tx-qty').off('input.tax').on('input.tax', function() {
            if ($('#pf-tx-type').val() === 'sell') {
                const v = (parseFloat($('#pf-tx-qty').val()) || 0) * (parseFloat($('#pf-tx-price').val()) || 0) * 0.001;
                $('#pf-tx-tax').val(v > 0 ? Math.round(v) : 0);
            }
            updateTotalPreview();
        });
        $('#pf-tx-symbol').off('input.upper').on('input.upper', function() { $(this).val($(this).val().toUpperCase()); });
        $('#lcni-pf-tx-save').off('click').on('click', saveTx);
        $('#pf-tx-note, #pf-tx-symbol, #pf-tx-qty, #pf-tx-price').off('keydown.enter').on('keydown.enter', function(e) {
            if (e.key === 'Enter') saveTx();
        });

        // Init DNSE if available
        if (hasDnse) {
            const sel = document.getElementById('pf-dnse-account');
            if (sel && !sel.options.length) {
                dnseAccounts.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a.id; opt.dataset.type = a.type; opt.textContent = a.label;
                    sel.appendChild(opt);
                });
            }
            // Bind order-type change for standalone modal
            $('#pf-dnse-order-type').off('change.price').on('change.price', _syncPriceFieldToOrderType);
        }
    }

})(jQuery);

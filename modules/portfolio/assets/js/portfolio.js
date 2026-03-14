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

        // Ẩn/hiện giá khi chọn ATO/ATC/MP
        const orderTypeSel = document.getElementById('pf-dnse-order-type');
        if (orderTypeSel) {
            orderTypeSel.addEventListener('change', function() {
                const noPrice = ['ATO','ATC','MP'].includes(this.value);
                const priceField = document.getElementById('pf-tx-price');
                if (priceField) {
                    priceField.closest('.lcni-pf-form-group').style.opacity = noPrice ? '.4' : '1';
                    priceField.disabled = noPrice;
                    if (noPrice) priceField.value = '0';
                }
            });
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
                    <td><span class="lcni-pf-symbol">${h.symbol}</span></td>
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
                    <td>${tx.trade_date}</td>
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
        // Cập nhật title và nút save
        const isEdit = !!tx;
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
        $('#lcni-pf-modal-title').text(tx ? 'Sửa giao dịch' : 'Thêm giao dịch');
        $('#pf-tx-id').val(tx ? tx.id : '');
        $('#pf-tx-symbol').val(tx ? tx.symbol : '').prop('readonly', !!tx);
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
        modal.show();
        setTimeout(() => $('#pf-tx-symbol').focus(), 50);
    }
    function closeTxModal() { $('#lcni-pf-tx-modal').hide(); }

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
        // Kiểm tra có gửi lệnh DNSE không
        const sendDnse = hasDnse
            && $('#pf-dnse-send-order').prop('checked')
            && !$('#pf-tx-id').val() // chỉ đặt lệnh khi thêm mới, không phải edit
            && ['buy','sell'].includes($('#pf-tx-type').val());

        const txId  = $('#pf-tx-id').val();
        const isNew = !txId;
        // User nhập giá dạng VNĐ đầy đủ (21500) → chuyển về dạng DB (21.5) trước khi lưu
        const priceVnd = parseFloat($('#pf-tx-price').val()) || 0;
        const data  = {
            portfolio_id: activeId,
            tx_id:    txId || undefined,
            symbol:   $('#pf-tx-symbol').val().trim().toUpperCase(),
            type:     $('#pf-tx-type').val(),
            trade_date: $('#pf-tx-date').val(),
            quantity: $('#pf-tx-qty').val(),
            price:    vndPriceToDb(priceVnd),
            fee:      $('#pf-tx-fee').val() || 0,
            tax:      $('#pf-tx-tax').val() || 0,
            note:     $('#pf-tx-note').val(),
        };

        const endpoint = isNew ? '/portfolio/tx/add' : '/portfolio/tx/update';
        $('#lcni-pf-tx-save').prop('disabled', true).text('Đang lưu...');
        $('#lcni-pf-tx-error').hide();

        api('POST', endpoint, data)
            .done(res => {
                if (res.success) {
                    closeTxModal();
                    loadPortfolio(activeId);
                } else {
                    $('#lcni-pf-tx-error').text(res.data || 'Lỗi không xác định.').show();
                }
            })
            .fail(() => $('#lcni-pf-tx-error').text('Có lỗi kết nối.').show())
            .always(() => $('#lcni-pf-tx-save').prop('disabled', false).text('💾 Lưu giao dịch'));
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

    // Add tx
    $('#lcni-pf-add-tx-btn').on('click', () => openTxModal());
    $('#lcni-pf-modal-close, #lcni-pf-modal-cancel').on('click', closeTxModal);
    $('#lcni-pf-tx-modal').on('click', function (e) { if ($(e.target).is(this)) closeTxModal(); });

    // Live total preview
    $('#pf-tx-qty, #pf-tx-price, #pf-tx-fee, #pf-tx-tax, #pf-tx-type').on('input change', updateTotalPreview);

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

})(jQuery);

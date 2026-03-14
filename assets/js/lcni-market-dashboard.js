/**
 * lcni-market-dashboard.js
 * Render Market Dashboard từ REST API snapshot
 */
(function () {
    'use strict';

    const CFG = window.lcniMktDashCfg || {};

    // ── Utilities ─────────────────────────────────────────────────────────────

    const fmt = {
        pct:   (v, d = 1) => v == null ? '—' : Number(v).toFixed(d) + '%',
        num:   (v, d = 1) => v == null ? '—' : Number(v).toFixed(d),
        int:   (v)        => v == null ? '—' : Math.round(Number(v)).toLocaleString('vi-VN'),
        money: (v)        => v == null ? '—' : Number(v).toFixed(1) + ' nghìn tỷ',
        sign:  (v)        => v == null ? '' : (v >= 0 ? '+' : '') + Number(v).toFixed(1) + '%',
    };

    function scoreColor(score) {
        if (score >= 65) return '#1ca97c';
        if (score >= 52) return '#e8b84b';
        if (score >= 38) return '#8b8b8b';
        return '#e05252';
    }

    function biasClass(bias) {
        if (bias === 'Tích cực')  return 'lcni-mkt-tag--green';
        if (bias === 'Tiêu cực')  return 'lcni-mkt-tag--red';
        return 'lcni-mkt-tag--gray';
    }

    function phaseIcon(phase) {
        if (!phase) return '';
        if (phase.includes('Bứt phá'))        return '🚀';
        if (phase.includes('Tăng ổn định'))   return '📈';
        if (phase.includes('Tích lũy'))       return '🔄';
        if (phase.includes('Suy yếu'))        return '📉';
        return '';
    }

    // ── API ───────────────────────────────────────────────────────────────────

    async function fetchSnapshot(tf, eventTime = 0, refresh = false) {
        let url = CFG.apiBase + '/snapshot?timeframe=' + tf;
        if (eventTime > 0) url += '&event_time=' + eventTime;
        if (refresh)        url += '&refresh=1';

        const res = await fetch(url, {
            headers: { 'X-WP-Nonce': CFG.nonce || '' },
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (!json.success) throw new Error(json.message || 'No data');
        return json.data;
    }

    async function fetchDates(tf) {
        const res = await fetch(CFG.apiBase + '/available-dates?timeframe=' + tf, {
            headers: { 'X-WP-Nonce': CFG.nonce || '' },
        });
        if (!res.ok) return [];
        const json = await res.json();
        return json.data || [];
    }

    // ── Render helpers ────────────────────────────────────────────────────────

    function renderGauge(score, label, sub, isComposite = false) {
        const color = scoreColor(score);
        const pct   = Math.max(0, Math.min(100, score));
        // arc 125deg mỗi bên = 250deg tổng, dasharray max = PI*40 * (250/360) ≈ 87.3
        // strokeDasharray = pct/100 * 87.3
        const arcLen = (pct / 100) * 87.3;
        const sw     = isComposite ? 9 : 8;
        const fs     = isComposite ? 22 : 19;
        const trackColor = 'rgba(255,255,255,0.10)';

        return `
        <div class="lcni-mkt-gauge-wrap">
            <svg class="lcni-mkt-gauge" viewBox="0 0 100 62">
                <path d="M13,57 A38,38 0 0,1 87,57" fill="none" stroke="${trackColor}" stroke-width="${sw}" stroke-linecap="round"/>
                <path d="M13,57 A38,38 0 0,1 87,57" fill="none" stroke="${color}" stroke-width="${sw}"
                    stroke-linecap="round"
                    stroke-dasharray="${arcLen} 999"/>
                <text x="50" y="52" text-anchor="middle" font-size="${fs}" font-weight="700" fill="${color}">${Math.round(pct)}</text>
            </svg>
            <div class="lcni-mkt-gauge-label">${label}</div>
            ${sub ? `<div class="lcni-mkt-gauge-sub">${sub}</div>` : ''}
        </div>`;
    }

    function renderScoreBar(label, value, max = 100, unit = '') {
        const pct   = Math.max(0, Math.min(100, (value / max) * 100));
        const color = scoreColor(value * (100 / max));
        return `
        <div class="lcni-mkt-bar-row">
            <span class="lcni-mkt-bar-label">${label}</span>
            <div class="lcni-mkt-bar-track">
                <div class="lcni-mkt-bar-fill" style="width:${pct}%;background:${color}"></div>
            </div>
            <span class="lcni-mkt-bar-val" style="color:${color}">${Math.round(value)}${unit}</span>
        </div>`;
    }

    function renderTag(text, cls = '') {
        return `<span class="lcni-mkt-tag ${cls}">${text}</span>`;
    }

    function renderSectorRow(s, idx) {
        const ph = phaseIcon(s.phase);
        const r5 = s.return_5d >= 0 ? `<span class="lcni-pos">+${fmt.num(s.return_5d)}%</span>`
                                    : `<span class="lcni-neg">${fmt.num(s.return_5d)}%</span>`;
        return `
        <tr class="lcni-mkt-sector-row ${idx === 0 ? 'lcni-mkt-sector-row--top' : ''}">
            <td class="lcni-mkt-sector-name">${ph} ${s.name || '—'}</td>
            <td>${s.trend_state ? renderTag(s.trend_state, s.trend_state === 'Ngành dẫn dắt' ? 'lcni-mkt-tag--green' : '') : '—'}</td>
            <td>${s.phase ? `<span class="lcni-mkt-phase">${s.phase}</span>` : '—'}</td>
            <td>${r5}</td>
            <td class="lcni-mkt-score">${fmt.num(s.score, 2)}</td>
        </tr>`;
    }

    function renderFlowSectorRow(s) {
        const ratio = s.flow_ratio;
        const cls   = ratio > 1.2 ? 'lcni-pos' : ratio < 0.9 ? 'lcni-neg' : '';
        return `
        <div class="lcni-mkt-flow-row">
            <span class="lcni-mkt-flow-name">${s.name || '—'}</span>
            <div class="lcni-mkt-flow-bar-wrap">
                <div class="lcni-mkt-flow-bar" style="width:${Math.min(100, (s.flow_share_pct || 0) * 5)}%"></div>
            </div>
            <span class="lcni-mkt-flow-pct">${fmt.pct(s.flow_share_pct)}</span>
            <span class="lcni-mkt-flow-ratio ${cls}">${fmt.num(ratio, 2)}×</span>
        </div>`;
    }

    // ── Main render ───────────────────────────────────────────────────────────

    function render(container, data, opts) {
        const mt = data.market_trend  || {};
        const br = data.breadth       || {};
        const se = data.sentiment     || {};
        const fl = data.flow          || {};
        const ro = data.rotation      || {};

        const ts = data.event_time
            ? new Date(data.event_time * 1000).toLocaleDateString('vi-VN', { day:'2-digit', month:'2-digit', year:'numeric' })
            : '';

        const vcPct = fl.value_change_pct;
        const vcHtml = vcPct != null
            ? `<span class="${vcPct >= 0 ? 'lcni-pos' : 'lcni-neg'}">${fmt.sign(vcPct)}</span>`
            : '';

        // ── HEADER ──────────────────────────────────────────────────────────
        let html = `
        <div class="lcni-mkt-header">
            <div class="lcni-mkt-header-left">
                <span class="lcni-mkt-date">Phiên ${ts}</span>
                <select class="lcni-mkt-tf-select" data-role="tf-select">
                    <option value="1D" ${opts.tf === '1D' ? 'selected' : ''}>Ngày</option>
                    <option value="1W" ${opts.tf === '1W' ? 'selected' : ''}>Tuần</option>
                    <option value="1M" ${opts.tf === '1M' ? 'selected' : ''}>Tháng</option>
                </select>
                <select class="lcni-mkt-date-select" data-role="date-select">
                    <option value="0">Mới nhất</option>
                </select>
            </div>
            <button class="lcni-mkt-refresh-btn" data-role="refresh">↻ Làm mới</button>
        </div>`;

        // ── ROW 1: Composite score + 4 gauges ───────────────────────────────
        html += `<div class="lcni-mkt-row1">`;

        // Composite
        html += `
        <div class="lcni-mkt-composite">
            ${renderGauge(mt.composite_score || 0, mt.market_phase || '—', '', true)}
            ${renderTag(mt.market_bias || '', biasClass(mt.market_bias))}
        </div>`;

        // 4 component gauges
        html += `<div class="lcni-mkt-gauges4">`;
        html += renderGauge(br.ad_ratio || 0,          'Breadth',   br.label || '');
        html += renderGauge(se.fear_greed_index || 0,  'Tâm lý',    se.fear_greed_label || '');
        html += renderGauge(fl.flow_breadth_score || 0,'Dòng tiền', `${fmt.money(fl.total_value_bn)} ${vcHtml}`);
        html += renderGauge(ro.rotation_score || 0,    'Rotation',  `${ro.leader_count || 0} ngành dẫn`);
        html += `</div></div>`;

        // ── ROW 2: Breadth detail bars ───────────────────────────────────────
        html += `
        <div class="lcni-mkt-section">
            <div class="lcni-mkt-section-title">Sức khỏe thị trường</div>
            <div class="lcni-mkt-bars">
                ${renderScoreBar('% mã trên MA20', br.pct_above_ma20 ?? 0, 100, '%')}
                ${renderScoreBar('% mã trên MA50', br.pct_above_ma50 ?? 0, 100, '%')}
                ${renderScoreBar('% mã trên MA100',br.pct_above_ma100 ?? 0,100, '%')}
                ${renderScoreBar('Fear & Greed',   se.fear_greed_index ?? 0, 100, '')}
                ${renderScoreBar('Smart Money',    se.pct_smart_money ?? 0,  100, '%')}
            </div>
            <div class="lcni-mkt-stats-row">
                <div class="lcni-mkt-stat">
                    <span class="lcni-pos">▲ ${fmt.int(br.advance_count)}</span>
                    <label>Mã tăng</label>
                </div>
                <div class="lcni-mkt-stat">
                    <span class="lcni-neg">▼ ${fmt.int(br.decline_count)}</span>
                    <label>Mã giảm</label>
                </div>
                <div class="lcni-mkt-stat">
                    <span>${fmt.int(br.breakout_count)}</span>
                    <label>Phá nền</label>
                </div>
                <div class="lcni-mkt-stat">
                    <span>${fmt.int(br.advance_vol_count)}</span>
                    <label>Tăng kèm Vol</label>
                </div>
                <div class="lcni-mkt-stat">
                    <span>MA ${br.ma_trend_score}/3</span>
                    <label>Điểm xu hướng</label>
                </div>
            </div>
        </div>`;

        // ── ROW 3: Sector Rotation distribution ─────────────────────────────
        if (opts.showRotation) {
            const totalSec = ro.total_sectors || 1;
            const phaseDist= ro.phase_distribution || {};

            html += `
            <div class="lcni-mkt-section">
                <div class="lcni-mkt-section-title">Phân phối ngành</div>
                <div class="lcni-mkt-rotation-bars">
                    ${renderScoreBar('Dẫn dắt',         ro.leader_count   || 0, totalSec, ' ngành')}
                    ${renderScoreBar('Đang cải thiện',   ro.improving_count|| 0, totalSec, ' ngành')}
                    ${renderScoreBar('Suy yếu',          ro.weak_count     || 0, totalSec, ' ngành')}
                    ${renderScoreBar('Tụt hậu',          ro.lagging_count  || 0, totalSec, ' ngành')}
                </div>
                <div class="lcni-mkt-stats-row">
                    <div class="lcni-mkt-stat">
                        <span>${fmt.pct(ro.pct_sector_uptrend)}</span>
                        <label>Xu hướng tăng</label>
                    </div>
                    ${Object.entries(phaseDist).map(([phase, cnt]) =>
                        `<div class="lcni-mkt-stat">
                            <span>${cnt}</span>
                            <label>${phaseIcon(phase)} ${phase}</label>
                        </div>`
                    ).join('')}
                </div>
            </div>`;
        }

        // ── ROW 4: Top sectors dẫn dắt ──────────────────────────────────────
        if (opts.showSectors && ro.top_leaders && ro.top_leaders.length > 0) {
            html += `
            <div class="lcni-mkt-section">
                <div class="lcni-mkt-section-title">Ngành dẫn dắt</div>
                <div class="lcni-mkt-table-wrap">
                    <table class="lcni-mkt-table">
                        <thead>
                            <tr>
                                <th>Ngành</th>
                                <th>Trạng thái</th>
                                <th>Pha</th>
                                <th>Return 5D</th>
                                <th>Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${ro.top_leaders.map((s, i) => renderSectorRow(s, i)).join('')}
                        </tbody>
                    </table>
                </div>
            </div>`;
        }

        // ── ROW 5: Dòng tiền theo ngành ─────────────────────────────────────
        if (opts.showFlow && fl.top_sectors && fl.top_sectors.length > 0) {
            html += `
            <div class="lcni-mkt-section">
                <div class="lcni-mkt-section-title">Dòng tiền ngành (Top 5)</div>
                <div class="lcni-mkt-flow-list">
                    ${fl.top_sectors.map(s => renderFlowSectorRow(s)).join('')}
                </div>
                <div class="lcni-mkt-stats-row" style="margin-top:8px">
                    <div class="lcni-mkt-stat">
                        <span>${fmt.money(fl.total_value_bn)}</span>
                        <label>Tổng GTGD</label>
                    </div>
                    <div class="lcni-mkt-stat">
                        <span ${vcPct != null && vcPct < 0 ? 'class="lcni-neg"' : vcPct != null ? 'class="lcni-pos"' : ''}>
                            ${vcPct != null ? fmt.sign(vcPct) : '—'}
                        </span>
                        <label>So phiên trước</label>
                    </div>
                    <div class="lcni-mkt-stat">
                        <span>${fmt.pct(fl.flow_breadth_score)}</span>
                        <label>Ngành trên MA20</label>
                    </div>
                </div>
            </div>`;
        }

        // ── Footer ──────────────────────────────────────────────────────────
        html += `<div class="lcni-mkt-footer">
            <span class="lcni-mkt-computed-at">Tính lúc: ${
                data.computed_at
                    ? new Date(data.computed_at * 1000).toLocaleTimeString('vi-VN')
                    : ''
            }</span>
            <span class="lcni-mkt-rule-hint">
                💡 Các chỉ số này có thể dùng làm điều kiện trong <strong>Recommend Rule</strong> (bảng <code>lcni_market_context</code>)
            </span>
        </div>`;

        container.innerHTML = html;
    }

    // ── Controller ────────────────────────────────────────────────────────────

    function initWidget(el) {
        const body    = el.querySelector('.lcni-mkt-body');
        const tf0     = el.dataset.timeframe   || CFG.defaultTf || '1D';
        const showSec = el.dataset.showSectors !== '0' && CFG.showSectors !== false;
        const showFl  = el.dataset.showFlow    !== '0' && CFG.showFlow    !== false;
        const showRot = el.dataset.showRotation!== '0' && CFG.showRotation!== false;

        let currentTf = tf0;
        let currentEt = 0;

        async function load(tf, et, refresh = false) {
            body.innerHTML = `<div class="lcni-mkt-loading">${(CFG.i18n || {}).loading || 'Đang tải...'}</div>`;
            try {
                const data = await fetchSnapshot(tf, et, refresh);
                render(body, data, {
                    tf: tf, showSectors: showSec, showFlow: showFl, showRotation: showRot,
                });

                // Bind controls
                const tfSel   = body.querySelector('[data-role="tf-select"]');
                const dateSel = body.querySelector('[data-role="date-select"]');
                const refBtn  = body.querySelector('[data-role="refresh"]');

                // Populate date select
                if (dateSel) {
                    fetchDates(tf).then(dates => {
                        dates.forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d;
                            const label = new Date(d * 1000).toLocaleDateString('vi-VN', {
                                day: '2-digit', month: '2-digit', year: 'numeric',
                            });
                            opt.textContent = label;
                            if (d == et) opt.selected = true;
                            dateSel.appendChild(opt);
                        });
                    });
                    dateSel.addEventListener('change', () => {
                        currentEt = parseInt(dateSel.value) || 0;
                        load(currentTf, currentEt);
                    });
                }

                if (tfSel) {
                    tfSel.addEventListener('change', () => {
                        currentTf = tfSel.value;
                        currentEt = 0;
                        load(currentTf, currentEt);
                    });
                }

                if (refBtn) {
                    refBtn.addEventListener('click', () => {
                        load(currentTf, currentEt, true);
                    });
                }

            } catch (err) {
                body.innerHTML = `<div class="lcni-mkt-error">${(CFG.i18n || {}).error || 'Lỗi'}: ${err.message}</div>`;
            }
        }

        load(currentTf, currentEt);
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    function boot() {
        document.querySelectorAll('.lcni-market-dashboard').forEach(initWidget);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

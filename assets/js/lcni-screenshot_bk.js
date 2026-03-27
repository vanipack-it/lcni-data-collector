/**
 * lcni-screenshot.js  v1.1
 * Flow: FAB → chọn chế độ → capture → editor (text/draw/arrow/rect/emoji) → watermark → export
 */
(function () {
    'use strict';

    const CFG    = window.lcniScreenshotCfg || {};
    const ROOT_ID = 'lcni-screenshot-root';
    const EMOJIS  = ['😀','😂','❤️','👍','🎯','🔥','⭐','📈','📉','💰','🏆','✅','❌','⚠️','💡'];

    /* ── Polyfill roundRect (Chrome < 99, Safari < 15.4) ── */
    if (!CanvasRenderingContext2D.prototype.roundRect) {
        CanvasRenderingContext2D.prototype.roundRect = function (x, y, w, h, r) {
            r = Math.min(r || 0, w / 2, h / 2);
            this.beginPath();
            this.moveTo(x + r, y);
            this.lineTo(x + w - r, y);
            this.quadraticCurveTo(x + w, y, x + w, y + r);
            this.lineTo(x + w, y + h - r);
            this.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
            this.lineTo(x + r, y + h);
            this.quadraticCurveTo(x, y + h, x, y + h - r);
            this.lineTo(x, y + r);
            this.quadraticCurveTo(x, y, x + r, y);
            this.closePath();
            return this;
        };
    }

    /* ── Utils ── */
    function getRoot() { return document.getElementById(ROOT_ID); }

    function showToast(msg, dur) {
        let t = document.querySelector('.lcni-ss-toast');
        if (!t) { t = document.createElement('div'); t.className = 'lcni-ss-toast'; document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._tmr);
        t._tmr = setTimeout(() => t.classList.remove('show'), dur || 2800);
    }

    /* ── Preprocess clone: fix các CSS không được html2canvas support ── */
    function preprocessClone(doc) {
        var liveEls  = document.querySelectorAll('*');
        var cloneEls = doc.querySelectorAll('*');
        var len = Math.min(liveEls.length, cloneEls.length);

        for (var i = 0; i < len; i++) {
            var live  = liveEls[i];
            var clone = cloneEls[i];
            try {
                var cs = window.getComputedStyle(live);

                // 1. Xoá backdrop-filter — gây mờ đục
                if (cs.backdropFilter && cs.backdropFilter !== 'none') {
                    clone.style.backdropFilter       = 'none';
                    clone.style.webkitBackdropFilter = 'none';
                }

                // 2. Xoá filter (blur/brightness) — tạo stacking context, html2canvas không composite được
                //    Quan trọng với heatmap tiles có transition: filter
                if (cs.filter && cs.filter !== 'none') {
                    clone.style.filter = 'none';
                }

                // 3. Xoá transition — tránh state trung gian bị capture
                if (cs.transition && cs.transition !== 'none' && cs.transition !== 'all 0s') {
                    clone.style.transition = 'none';
                }

                // 4. Xoá transform (scale/translate) — tạo stacking context riêng
                //    Heatmap tile hover có transform: scale(1.015)
                if (cs.transform && cs.transform !== 'none') {
                    clone.style.transform = 'none';
                }

                // 5. Fix position:fixed → absolute để topbar/fixed elements render đúng
                if (cs.position === 'fixed') {
                    clone.style.position = 'absolute';
                }

                // 6. Reset animation — fill-mode:both reset clone về "from" state (opacity:0)
                if (cs.animationName && cs.animationName !== 'none') {
                    clone.style.animation         = 'none';
                    clone.style.animationDuration = '0s';
                    clone.style.opacity           = cs.opacity; // giữ opacity computed từ DOM thật
                }

                // 7. Force sharp text rendering
                clone.style.textRendering       = 'optimizeLegibility';
                clone.style.webkitFontSmoothing = 'antialiased';

            } catch(e) {}
        }

        // 7. Reset tất cả CSS animation về trạng thái kết thúc
        //    animation fill-mode:both sẽ reset về "from" state khi clone → opacity:0 → ảnh tối
        doc.querySelectorAll('*').forEach(function(el) {
            el.style.animation         = 'none';
            el.style.animationDuration = '0s';
            el.style.animationDelay    = '0s';
            el.style.transition        = 'none';
        });

        // 8. Heatmap tiles: đảm bảo opacity=1, transform=none, filter=none
        doc.querySelectorAll('.lcni-heatmap-tile, [class*="heatmap"]').forEach(function(tile) {
            tile.style.opacity    = '1';
            tile.style.transform  = 'none';
            tile.style.filter     = 'none';
            tile.style.willChange = 'auto';
        });

        // 8. Ẩn screenshot UI, bell, overlays
        doc.querySelectorAll(
            '#lcni-screenshot-root, #lcni-ss-fab, .lcni-ss-toast, ' +
            '#lcni-bell-wrap, #lcni-bell-btn, #lcni-bell-dropdown, ' +
            '.lcni-ss-region-overlay, .lcni-ss-mode-picker, ' +
            '.lcni-bell-dropdown'
        ).forEach(function(el) { el.style.display = 'none'; });

        // 9. SVG xmlns
        doc.querySelectorAll('svg').forEach(function(svg) {
            if (!svg.getAttribute('xmlns')) svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        });
    }

    /* ── Adaptive brightness ── */
    function regionBrightness(canvas, x, y, w, h) {
        try {
            const ctx  = canvas.getContext('2d');
            const data = ctx.getImageData(
                Math.max(0, Math.round(x)), Math.max(0, Math.round(y)),
                Math.min(Math.round(w), canvas.width  - Math.max(0, Math.round(x))),
                Math.min(Math.round(h), canvas.height - Math.max(0, Math.round(y)))
            ).data;
            let r = 0, g = 0, b = 0, n = 0;
            for (let i = 0; i < data.length; i += 16) { r += data[i]; g += data[i+1]; b += data[i+2]; n++; }
            return n ? (r/n*0.299 + g/n*0.587 + b/n*0.114) : 128;
        } catch (_) { return 128; }
    }

    /* ── Watermark ── */
    function resolveText(tpl) {
        if (!tpl) return '';
        const d  = new Date();
        const dd = String(d.getDate()).padStart(2,'0');
        const mm = String(d.getMonth()+1).padStart(2,'0');
        return tpl
            .replace(/{site_name}/g, CFG.siteName || '')
            .replace(/{date}/g, `${dd}/${mm}/${d.getFullYear()}`)
            .replace(/{url}/g, window.location.hostname);
    }

    function applyWatermark(canvas) {
        return new Promise(resolve => {
            const ctx     = canvas.getContext('2d');
            const W = canvas.width, H = canvas.height;
            const pos     = CFG.watermarkPos     || 'bottom-right';
            const opBase  = parseFloat(CFG.watermarkOpacity) || 0.85;
            const scale   = parseFloat(CFG.watermarkScale)   || 0.18;
            const wmText  = resolveText(CFG.watermarkText || '');
            const tPos    = CFG.watermarkTextPos  || 'below-logo';
            const tSize   = Math.round((parseInt(CFG.watermarkTextSize) || 14) * (W / 800));
            const tColorCfg = CFG.watermarkTextColor || '';
            const MARGIN  = Math.round(W * 0.022);
            const logoW   = Math.round(W * scale);

            function drawText(lx, ly, lw, lh) {
                if (!wmText) return;
                ctx.save();
                ctx.font = `600 ${Math.max(10, tSize)}px "DM Sans",system-ui,sans-serif`;
                const tw = ctx.measureText(wmText).width;
                let tx, ty;
                const gap = Math.round(tSize * 0.5);
                switch (tPos) {
                    case 'above-logo':  tx = lx + (lw - tw) / 2; ty = ly - gap; break;
                    case 'right-logo':  tx = lx + lw + gap; ty = ly + lh/2 + tSize/3; break;
                    case 'left-logo':   tx = lx - tw - gap; ty = ly + lh/2 + tSize/3; break;
                    case 'standalone':  tx = MARGIN; ty = H - MARGIN; break;
                    default:            tx = lx + (lw - tw) / 2; ty = ly + lh + gap + tSize; // below
                }
                const bri = regionBrightness(canvas, tx - 2, ty - tSize - 2, tw + 4, tSize + 8);
                let fc = tColorCfg || (bri > 128 ? 'rgba(0,0,0,0.80)' : 'rgba(255,255,255,0.92)');
                ctx.shadowColor   = bri > 128 ? 'rgba(255,255,255,0.6)' : 'rgba(0,0,0,0.6)';
                ctx.shadowBlur    = 3;
                ctx.shadowOffsetX = ctx.shadowOffsetY = 0;
                ctx.globalAlpha   = opBase;
                ctx.fillStyle     = fc;
                ctx.fillText(wmText, tx, ty);
                ctx.restore();
            }

            if (!CFG.siteLogoUrl) {
                // Chỉ text, không có logo
                if (wmText) drawText(MARGIN, H - MARGIN - tSize * 2, 120, tSize * 1.4);
                return resolve(canvas);
            }

            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                const ratio  = img.naturalWidth / img.naturalHeight;
                const lw     = logoW;
                const lh     = Math.round(lw / ratio);
                let lx, ly;
                switch (pos) {
                    case 'top-left':    lx = MARGIN;       ly = MARGIN; break;
                    case 'top-right':   lx = W-lw-MARGIN;  ly = MARGIN; break;
                    case 'bottom-left': lx = MARGIN;       ly = H-lh-MARGIN; break;
                    case 'center':      lx = (W-lw)/2;     ly = (H-lh)/2; break;
                    default:            lx = W-lw-MARGIN;  ly = H-lh-MARGIN; // bottom-right
                }

                // Tính màu chủ đạo logo để so với nền
                const tmp = document.createElement('canvas');
                tmp.width = img.naturalWidth; tmp.height = img.naturalHeight;
                const tc  = tmp.getContext('2d');
                tc.drawImage(img, 0, 0);
                const logoBri = regionBrightness(tmp, 0, 0, Math.min(40, img.naturalWidth), Math.min(40, img.naturalHeight));
                const bgBri   = regionBrightness(canvas, lx, ly, lw, lh);
                const briDiff = Math.abs(bgBri - logoBri);

                // Adaptive: gần màu → boost opacity + ring
                const op = briDiff < 55 ? Math.min(1.0, opBase + 0.13) : opBase;

                ctx.save();
                ctx.shadowColor   = bgBri > 128 ? 'rgba(0,0,0,0.40)' : 'rgba(255,255,255,0.40)';
                ctx.shadowBlur    = Math.round(lw * 0.07);
                ctx.shadowOffsetX = ctx.shadowOffsetY = 0;

                // Ring khi quá gần màu nền
                if (briDiff < 38) {
                    ctx.globalAlpha = 0.22;
                    ctx.fillStyle   = bgBri > 128 ? '#000' : '#fff';
                    ctx.roundRect(lx - 4, ly - 4, lw + 8, lh + 8, 6);
                    ctx.fill();
                }

                ctx.globalAlpha = op;
                ctx.drawImage(img, lx, ly, lw, lh);
                ctx.restore();

                drawText(lx, ly, lw, lh);
                resolve(canvas);
            };
            img.onerror = () => {
                drawText(MARGIN, H - MARGIN - tSize * 2, 0, 0);
                resolve(canvas);
            };
            img.src = CFG.siteLogoUrl;
        });
    }

    /* ── Capture full page ── */
    async function captureFullPage() {
        const fab   = document.getElementById('lcni-ss-fab');
        const root  = getRoot();
        const toast = document.querySelector('.lcni-ss-toast');
        if (fab)   fab.style.visibility   = 'hidden';
        if (root)  root.style.visibility  = 'hidden';
        if (toast) toast.style.visibility = 'hidden';
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
        await new Promise(r => setTimeout(r, 80));
        try {
            // Scale cao = html2canvas render nhiều pixel hơn → ảnh sắc nét
            // Không dùng dom-to-image vì nó không thực sự tăng pixel density
            const dpr   = window.devicePixelRatio || 1;
            const scale = Math.max(2, Math.min(dpr * 2, 3));
            const pageW = Math.max(document.documentElement.scrollWidth,  document.documentElement.clientWidth);
            const pageH = Math.max(document.documentElement.scrollHeight, document.documentElement.clientHeight);
            return await html2canvas(document.documentElement, {
                useCORS:              true,
                allowTaint:           true,
                logging:              false,
                scale:                scale,
                scrollX:              -window.scrollX,
                scrollY:              -window.scrollY,
                x:                    0,
                y:                    0,
                width:                pageW,
                height:               pageH,
                windowWidth:          pageW,
                windowHeight:         pageH,
                imageTimeout:         15000,
                removeContainer:      true,
                backgroundColor:      getComputedStyle(document.body).backgroundColor || '#ffffff',
                foreignObjectRendering: false,
                onclone: function(doc) { preprocessClone(doc); }
            });
        } finally {
            if (fab)   fab.style.visibility   = '';
            if (root)  root.style.visibility  = '';
            if (toast) toast.style.visibility = '';
        }
    }

    /* ── Capture region ── */
    function captureRegion() {
        return new Promise((resolve, reject) => {
            const ov = document.createElement('div');
            ov.className = 'lcni-ss-region-overlay';
            // 4 mask divs tạo vùng tối xung quanh selection (thay box-shadow bị clip)
            ov.innerHTML = `
                <div class="lcni-ss-region-hint">Kéo để chọn vùng — ESC huỷ</div>
                <div class="lcni-ss-mask" id="lcni-mask-top"></div>
                <div class="lcni-ss-mask" id="lcni-mask-bottom"></div>
                <div class="lcni-ss-mask" id="lcni-mask-left"></div>
                <div class="lcni-ss-mask" id="lcni-mask-right"></div>
                <div class="lcni-ss-region-sel" id="lcni-sel-box"></div>`;
            document.body.appendChild(ov);

            const box    = ov.querySelector('#lcni-sel-box');
            const mTop   = ov.querySelector('#lcni-mask-top');
            const mBot   = ov.querySelector('#lcni-mask-bottom');
            const mLeft  = ov.querySelector('#lcni-mask-left');
            const mRight = ov.querySelector('#lcni-mask-right');

            // cx/cy = viewport coords (clientX/Y), dùng cho hiển thị (fixed overlay)
            // px/py = page coords (clientX + scrollX/Y), dùng cho html2canvas (document coords)
            let cx0=0, cy0=0, cx1=0, cy1=0;
            let px0=0, py0=0, px1=0, py1=0;
            let drag = false;
            const W = window.innerWidth, H = window.innerHeight;

            function updateMasks(l, t, r, b) {
                // top
                mTop.style.cssText    = `top:0;left:0;width:100%;height:${t}px`;
                // bottom
                mBot.style.cssText    = `top:${b}px;left:0;width:100%;height:${H-b}px`;
                // left
                mLeft.style.cssText   = `top:${t}px;left:0;width:${l}px;height:${b-t}px`;
                // right
                mRight.style.cssText  = `top:${t}px;left:${r}px;width:${W-r}px;height:${b-t}px`;
            }

            ov.addEventListener('mousedown', e => {
                e.preventDefault();
                drag = true;
                cx0 = e.clientX; cy0 = e.clientY;
                cx1 = cx0; cy1 = cy0;
                px0 = e.clientX + window.scrollX; py0 = e.clientY + window.scrollY;
                px1 = px0; py1 = py0;
                box.style.cssText = `display:block;left:${cx0}px;top:${cy0}px;width:0;height:0`;
                updateMasks(cx0, cy0, cx0, cy0);
            });
            ov.addEventListener('mousemove', e => {
                if (!drag) return;
                cx1 = e.clientX; cy1 = e.clientY;
                px1 = e.clientX + window.scrollX; py1 = e.clientY + window.scrollY;
                const l = Math.min(cx0,cx1), t = Math.min(cy0,cy1);
                const r = Math.max(cx0,cx1), b = Math.max(cy0,cy1);
                box.style.left = l+'px'; box.style.top = t+'px';
                box.style.width = (r-l)+'px'; box.style.height = (b-t)+'px';
                box.dataset.size = `${Math.round(r-l)} × ${Math.round(b-t)} px`;
                updateMasks(l, t, r, b);
            });
            ov.addEventListener('mouseup', async () => {
                if (!drag) return; drag = false;
                const pl = Math.min(px0,px1), pt = Math.min(py0,py1);
                const pw = Math.abs(px1-px0),  ph = Math.abs(py1-py0);
                if (pw < 10 || ph < 10) { ov.remove(); reject(new Error('Vùng quá nhỏ')); return; }

                // Ẩn overlay + FAB + toast trước khi capture
                ov.style.visibility = 'hidden';
                const fab   = document.getElementById('lcni-ss-fab');
                const root  = getRoot();
                const toast = document.querySelector('.lcni-ss-toast');
                if (fab)   fab.style.visibility   = 'hidden';
                if (root)  root.style.visibility  = 'hidden';
                if (toast) toast.style.visibility = 'hidden';

                // Chờ browser repaint + transition heatmap settle (transition: 0.15s)
                await new Promise(res => requestAnimationFrame(() => requestAnimationFrame(res)));
                await new Promise(res => setTimeout(res, 200));

                try {
                    // html2canvas cho region — ổn định, crop chính xác
                    const dpr2   = window.devicePixelRatio || 1;
                    const scale2 = Math.max(3, Math.min(dpr2 * 3, 6));
                    const pageW2 = Math.max(document.documentElement.scrollWidth,  document.documentElement.clientWidth);
                    const pageH2 = Math.max(document.documentElement.scrollHeight, document.documentElement.clientHeight);
                    const c = await html2canvas(document.documentElement, {
                        useCORS:             true,
                        allowTaint:          true,
                        logging:             false,
                        scale:               scale2,
                        scrollX:             -window.scrollX,
                        scrollY:             -window.scrollY,
                        x:                   pl,
                        y:                   pt,
                        width:               pw,
                        height:              ph,
                        windowWidth:         pageW2,
                        windowHeight:        pageH2,
                        imageTimeout:        15000,
                        removeContainer:     true,
                        backgroundColor:     getComputedStyle(document.body).backgroundColor || '#ffffff',
                        onclone:             function(doc) { preprocessClone(doc); }
                    });
                    resolve(c);
                } catch(e) { reject(e); }
                finally {
                    ov.remove();
                    if (fab)   fab.style.visibility   = '';
                    if (root)  root.style.visibility  = '';
                    if (toast) toast.style.visibility = '';
                }
            });

            function onEsc(e) {
                if (e.key !== 'Escape') return;
                drag = false; ov.remove();
                document.removeEventListener('keydown', onEsc);
                reject(new Error('cancelled'));
            }
            document.addEventListener('keydown', onEsc);
        });
    }

    /* ── EDITOR ── */
    function openEditor(srcCanvas) {
        // Clone
        const base = document.createElement('canvas');
        base.width  = srcCanvas.width;
        base.height = srcCanvas.height;
        base.getContext('2d').drawImage(srcCanvas, 0, 0);

        const modal = document.createElement('div');
        modal.className = 'lcni-ss-editor-modal';
        modal.innerHTML = `
<div class="lcni-ss-editor-inner">
  <div class="lcni-ss-editor-topbar">
    <span class="lcni-ss-editor-logo">📸 Chỉnh sửa &amp; Chia sẻ</span>
    <button class="lcni-ss-ed-close" title="Đóng">✕</button>
  </div>

  <div class="lcni-ss-editor-tools">
    <button class="lcni-ss-tool active" data-tool="select" title="Con trỏ">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 2l9 5-4.5 1.3L6 14z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
    </button>
    <button class="lcni-ss-tool" data-tool="text"  title="Văn bản">T</button>
    <button class="lcni-ss-tool" data-tool="draw"  title="Vẽ tự do">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 14 L6 10 L11 3 L13 5 L8 12 Z" stroke="currentColor" fill="none" stroke-width="1.5" stroke-linejoin="round"/></svg>
    </button>
    <button class="lcni-ss-tool" data-tool="arrow" title="Mũi tên">→</button>
    <button class="lcni-ss-tool" data-tool="rect"  title="Hình chữ nhật">□</button>
    <button class="lcni-ss-tool" data-tool="hl"    title="Highlight / Làm mờ">◧</button>
    <button class="lcni-ss-tool" data-tool="emoji" title="Emoji">😀</button>
    <div class="lcni-ss-tool-sep"></div>
    <input type="color"  class="lcni-ss-color-pick" id="lcni-ed-color" value="#FF0000" title="Màu vẽ">
    <input type="range"  class="lcni-ss-size-pick"  id="lcni-ed-size"  min="2" max="32" value="4" title="Nét vẽ">
    <button class="lcni-ss-tool" id="lcni-ed-undo" title="Hoàn tác (Ctrl+Z)">↩</button>
    <button class="lcni-ss-tool" id="lcni-ed-wm"   title="Chèn watermark kéo thả">🏷️</button>
    <button class="lcni-ss-tool" id="lcni-ed-frame" title="Viền + logo ngoài ảnh">🖼</button>
  </div>

  <div class="lcni-ss-frame-panel" id="lcni-frame-panel" style="display:none">
    <div class="lcni-ss-fp-row">
      <label>Màu nền</label>
      <input type="color" id="lcni-fp-bg" value="${CFG.frameBgColor||'#1a1a2e'}">
      <div class="lcni-ss-fp-sep"></div>
      <label>Viền T/L/R</label>
      <input type="range" id="lcni-fp-thin" min="2" max="60" step="2" value="${CFG.frameThin||12}" style="width:58px">
      <span id="lcni-fp-thin-val" style="min-width:34px;font-size:11px;color:#93c5fd">${CFG.frameThin||12}px</span>
      <div class="lcni-ss-fp-sep"></div>
      <label>Bar dưới</label>
      <input type="range" id="lcni-fp-size" min="20" max="220" step="4" value="${CFG.frameSize||64}" style="width:58px">
      <span id="lcni-fp-size-val" style="min-width:34px;font-size:11px;color:#93c5fd">${CFG.frameSize||64}px</span>
      <div class="lcni-ss-fp-sep"></div>
      <label>Bo góc</label>
      <input type="range" id="lcni-fp-radius" min="0" max="80" step="2" value="${CFG.frameRadius||12}" style="width:50px">
      <span id="lcni-fp-radius-val" style="min-width:34px;font-size:11px;color:#93c5fd">${CFG.frameRadius||12}px</span>
    </div>
    <div class="lcni-ss-fp-row">
      <label>Logo</label>
      <input type="checkbox" id="lcni-fp-logo" ${CFG.frameShowLogo!==false?'checked':''}>
      <label style="margin-left:10px">Text</label>
      <input type="checkbox" id="lcni-fp-text" ${CFG.frameShowText!==false?'checked':''}>
      <label style="margin-left:10px">Màu chữ</label>
      <input type="color" id="lcni-fp-textcolor" value="${CFG.frameTextColor||'#ffffff'}">
      <div class="lcni-ss-fp-sep"></div>
      <label>Cỡ chữ</label>
      <input type="range" id="lcni-fp-textsize" min="8" max="80" step="2" value="${CFG.frameTextSize||28}" style="width:58px">
      <span id="lcni-fp-textsize-val" style="min-width:34px;font-size:11px;color:#93c5fd">${CFG.frameTextSize||28}px</span>
      <button class="lcni-ss-tool" id="lcni-fp-apply" style="margin-left:auto;padding:0 10px;width:auto;font-size:12px;color:#fff;background:#2563eb;border-radius:6px;white-space:nowrap">✅ Áp dụng</button>
    </div>
  </div>

  <div class="lcni-ss-emoji-picker" id="lcni-emoji-picker">
    ${EMOJIS.map(e=>`<button class="lcni-ss-emoji-btn" data-emoji="${e}">${e}</button>`).join('')}
  </div>

  <div class="lcni-ss-editor-canvas-wrap">
    <div class="lcni-ss-canvas-stack" id="lcni-canvas-stack">
      <canvas id="lcni-ed-canvas"></canvas>
      <canvas id="lcni-ed-overlay"></canvas>
      <div id="lcni-wm-layer"></div>
    </div>
  </div>

  <div class="lcni-ss-editor-bottombar">
    <button class="lcni-ss-action-btn" id="lcni-ed-copy">📋 Sao chép</button>
    <button class="lcni-ss-action-btn" id="lcni-ed-download">⬇️ Tải về</button>
    <button class="lcni-ss-action-btn" id="lcni-ed-share">🔗 Chia sẻ</button>
    <div style="flex:1"></div>
    <button class="lcni-ss-action-btn primary" id="lcni-ed-done">✅ Hoàn tất & Lưu</button>
  </div>
</div>`;

        document.body.appendChild(modal);

        const edCanvas  = modal.querySelector('#lcni-ed-canvas');
        const ovCanvas  = modal.querySelector('#lcni-ed-overlay');
        const stack     = modal.querySelector('#lcni-canvas-stack');

        // Tính display size để fit modal
        const MAX_W = Math.min(window.innerWidth  * 0.86, 1080);
        const MAX_H = window.innerHeight * 0.60;
        const scaleRatio = Math.min(MAX_W / base.width, MAX_H / base.height, 1);
        const dispW = Math.round(base.width  * scaleRatio);
        const dispH = Math.round(base.height * scaleRatio);

        // edCanvas: actual resolution
        edCanvas.width  = base.width;
        edCanvas.height = base.height;
        edCanvas.style.width  = dispW + 'px';
        edCanvas.style.height = dispH + 'px';

        // ovCanvas: same display size, scaled internally
        ovCanvas.width  = base.width;
        ovCanvas.height = base.height;
        ovCanvas.style.width  = dispW + 'px';
        ovCanvas.style.height = dispH + 'px';

        // Stack container
        stack.style.width  = dispW + 'px';
        stack.style.height = dispH + 'px';

        const edCtx = edCanvas.getContext('2d');
        const ovCtx = ovCanvas.getContext('2d');
        edCtx.drawImage(base, 0, 0);

        // Watermark drag layer
        const wmLayer = modal.querySelector('#lcni-wm-layer');
        wmLayer.style.cssText = `position:absolute;inset:0;pointer-events:none;overflow:hidden;`;

        // wmState: tọa độ % so với dispW/dispH để scale-independent
        const wmState = {
            visible: false,
            logoX: 0, logoY: 0,          // % của dispW/dispH
            textX: 0, textY: 0,          // % (chỉ dùng nếu standalone)
            logoW: Math.round(CFG.watermarkScale * 100), // % width
        };

        // Tạo logo draggable element
        const wmLogoEl = document.createElement('div');
        wmLogoEl.id = 'lcni-wm-logo-drag';
        wmLogoEl.className = 'lcni-wm-drag-el';
        wmLogoEl.style.display = 'none';
        wmLogoEl.innerHTML = CFG.siteLogoUrl
            ? `<img src="${CFG.siteLogoUrl}" crossorigin="anonymous" style="width:100%;height:100%;object-fit:contain;pointer-events:none;">`
            : '';
        wmLayer.appendChild(wmLogoEl);

        // Tạo text draggable element
        const wmTextEl = document.createElement('div');
        wmTextEl.id = 'lcni-wm-text-drag';
        wmTextEl.className = 'lcni-wm-drag-el lcni-wm-text-el';
        wmTextEl.style.display = 'none';
        const resolvedWmText = resolveText(CFG.watermarkText || '');
        wmTextEl.textContent = resolvedWmText;
        wmLayer.appendChild(wmTextEl);

        function initWmPositions() {
            const pos = CFG.watermarkPos || 'bottom-right';
            const lw  = Math.round(dispW * (parseFloat(CFG.watermarkScale) || 0.18));
            const lh  = CFG.siteLogoUrl ? Math.round(lw * 0.4) : 0; // estimate height

            let lx, ly;
            const M = Math.round(dispW * 0.022);
            switch (pos) {
                case 'top-left':    lx = M;         ly = M; break;
                case 'top-right':   lx = dispW-lw-M; ly = M; break;
                case 'bottom-left': lx = M;         ly = dispH-lh-M; break;
                case 'center':      lx = (dispW-lw)/2; ly = (dispH-lh)/2; break;
                default:            lx = dispW-lw-M; ly = dispH-lh-M;
            }

            if (CFG.siteLogoUrl) {
                wmLogoEl.style.cssText = `
                    display:block; position:absolute;
                    left:${lx}px; top:${ly}px;
                    width:${lw}px; height:auto; min-height:10px;
                    cursor:move; pointer-events:all;
                    opacity:${parseFloat(CFG.watermarkOpacity)||0.85};
                    filter: drop-shadow(1px 1px 3px rgba(0,0,0,0.5));
                `;
                wmState.logoX = lx; wmState.logoY = ly;
            }

            if (resolvedWmText) {
                const tPos = CFG.watermarkTextPos || 'below-logo';
                const tSize = Math.round((parseInt(CFG.watermarkTextSize)||14) * (dispW/800));
                let tx, ty;
                if (tPos === 'standalone') {
                    tx = M; ty = dispH - 28;
                } else if (tPos === 'above-logo') {
                    tx = lx; ty = Math.max(4, ly - tSize - 4);
                } else if (tPos === 'right-logo') {
                    tx = lx + lw + 8; ty = ly + Math.round(lh/2) - tSize/2;
                } else if (tPos === 'left-logo') {
                    tx = Math.max(2, lx - 100); ty = ly + Math.round(lh/2) - tSize/2;
                } else { // below
                    tx = lx; ty = ly + lh + 4;
                }
                const opBase = parseFloat(CFG.watermarkOpacity)||0.85;
                const tColor = CFG.watermarkTextColor || '#ffffff';
                wmTextEl.style.cssText = `
                    display:block; position:absolute;
                    left:${tx}px; top:${ty}px;
                    font-size:${Math.max(10,tSize)}px; font-weight:600;
                    font-family:"DM Sans",system-ui,sans-serif;
                    color:${tColor}; opacity:${opBase};
                    text-shadow:1px 1px 3px rgba(0,0,0,0.65);
                    cursor:move; pointer-events:all;
                    white-space:nowrap; user-select:none;
                `;
                wmState.textX = tx; wmState.textY = ty;
            }
        }

        // Drag logic dùng chung cho logo + text
        function makeDraggable(el) {
            let ox = 0, oy = 0, sx = 0, sy = 0, dragging = false;

            function onDown(e) {
                e.stopPropagation(); e.preventDefault();
                dragging = true;
                const ev = e.touches ? e.touches[0] : e;
                sx = ev.clientX; sy = ev.clientY;
                ox = parseInt(el.style.left) || 0;
                oy = parseInt(el.style.top)  || 0;
                el.style.zIndex = '10';
                document.addEventListener('mousemove', onMove);
                document.addEventListener('touchmove', onMove, { passive:false });
                document.addEventListener('mouseup',   onUp);
                document.addEventListener('touchend',  onUp);
            }
            function onMove(e) {
                if (!dragging) return;
                e.preventDefault();
                const ev = e.touches ? e.touches[0] : e;
                const dx = ev.clientX - sx, dy = ev.clientY - sy;
                el.style.left = (ox + dx) + 'px';
                el.style.top  = (oy + dy) + 'px';
            }
            function onUp() {
                dragging = false;
                el.style.zIndex = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('touchmove', onMove);
                document.removeEventListener('mouseup',   onUp);
                document.removeEventListener('touchend',  onUp);
            }

            el.addEventListener('mousedown',  onDown, { passive:false });
            el.addEventListener('touchstart', onDown, { passive:false });
        }

        makeDraggable(wmLogoEl);
        makeDraggable(wmTextEl);

        // ── Frame border state ──────────────────────────────────────────────
        let frameApplied = false;

        // Hàm tạo canvas mới với viền ngoài + logo/text
        async function buildFramedCanvas(srcCanvas) {
            const fp = {
                bg:       modal.querySelector('#lcni-fp-bg')?.value       || '#1a1a2e',
                thin:     parseInt(modal.querySelector('#lcni-fp-thin')?.value    || 12),
                size:     parseInt(modal.querySelector('#lcni-fp-size')?.value    || 64),
                radius:   parseInt(modal.querySelector('#lcni-fp-radius')?.value  || 12),
                logo:     modal.querySelector('#lcni-fp-logo')?.checked    ?? true,
                text:     modal.querySelector('#lcni-fp-text')?.checked    ?? true,
                textClr:  modal.querySelector('#lcni-fp-textcolor')?.value || '#ffffff',
                textSize: parseInt(modal.querySelector('#lcni-fp-textsize')?.value || 28),
            };

            const W = srcCanvas.width, H = srcCanvas.height;
            const sc  = W / 800;                              // scale theo canvas resolution
            const SM  = Math.round(fp.thin   * sc * 1.6);    // viền mỏng T/L/R
            const BIG = Math.round(fp.size   * sc * 1.6);    // bar dưới dày
            const R   = Math.round(fp.radius * sc * 1.4);    // bo góc

            const out = document.createElement('canvas');
            out.width  = W + SM * 2;           // trái + phải mỏng
            out.height = H + SM + BIG;         // trên mỏng + dưới dày
            const ctx  = out.getContext('2d');

            // Nền toàn bộ (màu viền)
            ctx.fillStyle = fp.bg;
            ctx.roundRect(0, 0, out.width, out.height, R);
            ctx.fill();

            // Ảnh gốc: vào đúng vùng nội dung (top=SM, left=SM)
            ctx.save();
            ctx.roundRect(SM, SM, W, H, Math.max(2, R - Math.round(SM * 0.6)));
            ctx.clip();
            ctx.drawImage(srcCanvas, SM, SM);
            ctx.restore();

            // Vùng dưới dày: logo bên trái + text bên phải căn giữa dọc
            const barY  = SM + H;                  // Y bắt đầu vùng bar
            const barH  = BIG;
            const PAD   = Math.round(BIG * 0.18);

            // Vẽ line phân cách mỏng giữa ảnh và bar
            ctx.save();
            ctx.strokeStyle = 'rgba(255,255,255,0.08)';
            ctx.lineWidth   = Math.max(1, Math.round(sc));
            ctx.beginPath();
            ctx.moveTo(SM, barY);
            ctx.lineTo(SM + W, barY);
            ctx.stroke();
            ctx.restore();

            let textStartX = SM + PAD;

            // Logo
            if (fp.logo && CFG.siteLogoUrl) {
                await new Promise(res => {
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload = () => {
                        const logoH = Math.round(barH * 0.62);
                        const ratio = img.naturalWidth / img.naturalHeight;
                        const logoW = Math.round(logoH * ratio);
                        const logoX = SM + PAD;
                        const logoY = barY + Math.round((barH - logoH) / 2);
                        ctx.save();
                        ctx.globalAlpha = 0.95;
                        ctx.drawImage(img, logoX, logoY, logoW, logoH);
                        ctx.restore();
                        textStartX = logoX + logoW + Math.round(logoH * 0.4);
                        res();
                    };
                    img.onerror = res;
                    img.src = CFG.siteLogoUrl;
                });
            }

            // Text (site name / tagline) — cỡ chữ từ fp.textSize (scale theo canvas)
            if (fp.text && resolvedWmText) {
                const fSize = Math.max(10, Math.round(fp.textSize * sc * 1.6));
                const textY = barY + Math.round(barH / 2) + Math.round(fSize * 0.36);
                ctx.save();
                ctx.font        = `700 ${fSize}px "DM Sans",system-ui,sans-serif`;
                ctx.fillStyle   = fp.textClr;
                ctx.globalAlpha = 0.96;
                ctx.shadowBlur  = 6;
                ctx.shadowColor = 'rgba(0,0,0,0.45)';
                ctx.fillText(resolvedWmText, textStartX, textY);
                ctx.restore();
            }

            return out;
        }

        // Frame panel toggle
        modal.querySelector('#lcni-ed-frame').addEventListener('click', () => {
            const panel = modal.querySelector('#lcni-frame-panel');
            const isOpen = panel.style.display !== 'none';
            panel.style.display = isOpen ? 'none' : 'flex';
            modal.querySelector('#lcni-ed-frame').classList.toggle('active', !isOpen);
        });

        // Size/radius label live update
        ['lcni-fp-thin', 'lcni-fp-size', 'lcni-fp-radius', 'lcni-fp-textsize'].forEach(id => {
            const el  = modal.querySelector('#' + id);
            const val = modal.querySelector('#' + id + '-val');
            if (el && val) el.addEventListener('input', () => { val.textContent = el.value + 'px'; });
        });

        // Apply frame
        modal.querySelector('#lcni-fp-apply').addEventListener('click', async () => {
            const btn = modal.querySelector('#lcni-fp-apply');
            btn.disabled = true; btn.textContent = '⏳ Đang tạo...';

            // Frame dùng ảnh GỐC (không kèm watermark chồng lên) — logo/text xuất hiện trong bar dưới
            // Ẩn wm layer nếu đang hiện để không lẫn vào ảnh
            const hadWm = wmApplied;
            if (hadWm) {
                wmLogoEl.style.display = 'none';
                wmTextEl.style.display = 'none';
            }
            const framed = await buildFramedCanvas(edCanvas);

            // Cập nhật edCanvas với ảnh đã có viền
            saveSnap();
            edCanvas.width  = framed.width;
            edCanvas.height = framed.height;
            edCtx.drawImage(framed, 0, 0);

            // Cập nhật ovCanvas và stack size
            ovCanvas.width  = framed.width;
            ovCanvas.height = framed.height;
            const newScaleRatio = Math.min(
                Math.min(window.innerWidth * 0.86, 1080) / framed.width,
                window.innerHeight * 0.60 / framed.height, 1
            );
            const newDispW = Math.round(framed.width  * newScaleRatio);
            const newDispH = Math.round(framed.height * newScaleRatio);
            edCanvas.style.width  = newDispW + 'px';
            edCanvas.style.height = newDispH + 'px';
            ovCanvas.style.width  = newDispW + 'px';
            ovCanvas.style.height = newDispH + 'px';
            stack.style.width  = newDispW + 'px';
            stack.style.height = newDispH + 'px';

            // Ẩn wm layer (đã burn)
            wmLogoEl.style.display = 'none';
            wmTextEl.style.display = 'none';
            wmApplied = false;
            frameApplied = true;

            modal.querySelector('#lcni-frame-panel').style.display = 'none';
            modal.querySelector('#lcni-ed-frame').classList.remove('active');
            modal.querySelector('#lcni-ed-frame').innerHTML = '✅';
            btn.disabled = false; btn.textContent = '✅ Áp dụng viền';
            showToast('✅ Đã thêm viền ngoài ảnh');
        });

        // Burn watermark vào canvas từ vị trí drag layer hiện tại
        async function burnWatermark() {
            return new Promise((resolve) => {
                const ctx = edCtx;
                // Scale: dispW → canvas width
                const scX = edCanvas.width  / dispW;
                const scY = edCanvas.height / dispH;
                const opBase = parseFloat(CFG.watermarkOpacity) || 0.85;

                function burnLogo() {
                    if (!CFG.siteLogoUrl || wmLogoEl.style.display === 'none') return Promise.resolve();
                    return new Promise(res => {
                        const lx = (parseInt(wmLogoEl.style.left)||0) * scX;
                        const ly = (parseInt(wmLogoEl.style.top) ||0) * scY;
                        const lw = wmLogoEl.offsetWidth  * scX;
                        const lh = wmLogoEl.offsetHeight * scY;

                        const bgBri   = regionBrightness(edCanvas, lx, ly, lw, lh);
                        const tmpC = document.createElement('canvas');
                        const img  = wmLogoEl.querySelector('img');
                        if (!img) return res();
                        tmpC.width = img.naturalWidth; tmpC.height = img.naturalHeight;
                        tmpC.getContext('2d').drawImage(img, 0, 0);
                        const logoBri = regionBrightness(tmpC, 0, 0, Math.min(40,img.naturalWidth), Math.min(40,img.naturalHeight));
                        const briDiff = Math.abs(bgBri - logoBri);
                        const op = briDiff < 55 ? Math.min(1.0, opBase + 0.13) : opBase;

                        ctx.save();
                        ctx.shadowColor = bgBri > 128 ? 'rgba(0,0,0,0.4)' : 'rgba(255,255,255,0.4)';
                        ctx.shadowBlur  = Math.round(lw * 0.07);
                        if (briDiff < 38) {
                            ctx.globalAlpha = 0.22;
                            ctx.fillStyle   = bgBri > 128 ? '#000' : '#fff';
                            ctx.roundRect(lx-4, ly-4, lw+8, lh+8, 6);
                            ctx.fill();
                        }
                        ctx.globalAlpha = op;
                        ctx.drawImage(img, lx, ly, lw, lh);
                        ctx.restore();
                        res();
                    });
                }

                function burnText() {
                    if (!resolvedWmText || wmTextEl.style.display === 'none') return;
                    const tx    = (parseInt(wmTextEl.style.left)||0) * scX;
                    const ty    = (parseInt(wmTextEl.style.top) ||0) * scY;
                    const tSize = Math.round((parseInt(CFG.watermarkTextSize)||14) * (edCanvas.width/800));
                    const tColorCfg = CFG.watermarkTextColor || '';
                    const bri = regionBrightness(edCanvas, tx, ty-tSize, Math.min(200, edCanvas.width), tSize+4);
                    const fc  = tColorCfg || (bri > 128 ? 'rgba(0,0,0,0.80)' : 'rgba(255,255,255,0.92)');
                    ctx.save();
                    ctx.font = `600 ${Math.max(10,tSize)}px "DM Sans",system-ui,sans-serif`;
                    ctx.fillStyle = fc;
                    ctx.globalAlpha = opBase;
                    ctx.shadowBlur  = 3;
                    ctx.shadowColor = bri > 128 ? 'rgba(255,255,255,0.6)' : 'rgba(0,0,0,0.6)';
                    ctx.fillText(resolvedWmText, tx, ty + tSize);
                    ctx.restore();
                }

                burnLogo().then(() => { burnText(); resolve(); });
            });
        }

        // State
        let tool        = 'select';
        let color       = '#FF0000';
        let size        = 4;
        let painting    = false;
        let px = 0, py  = 0;
        let history     = [];
        let wmApplied   = false;

        function saveSnap() {
            const s = document.createElement('canvas');
            s.width = edCanvas.width; s.height = edCanvas.height;
            s.getContext('2d').drawImage(edCanvas, 0, 0);
            history.push(s);
            if (history.length > 25) history.shift();
        }

        function getPos(e) {
            const r  = edCanvas.getBoundingClientRect();
            const sx = edCanvas.width  / r.width;
            const sy = edCanvas.height / r.height;
            const ev = e.touches ? e.touches[0] || e.changedTouches[0] : e;
            return {
                x: Math.round((ev.clientX - r.left) * sx),
                y: Math.round((ev.clientY - r.top)  * sy),
            };
        }

        // Tool select
        modal.querySelectorAll('.lcni-ss-tool[data-tool]').forEach(btn => {
            btn.addEventListener('click', () => {
                modal.querySelectorAll('.lcni-ss-tool[data-tool]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                tool = btn.dataset.tool;
                modal.querySelector('#lcni-emoji-picker').style.display = tool === 'emoji' ? 'flex' : 'none';
                ovCanvas.style.cursor = {
                    text: 'text', draw: 'crosshair', arrow: 'crosshair',
                    rect: 'crosshair', hl: 'crosshair', emoji: 'default',
                }[tool] || 'default';
            });
        });

        // Emoji
        modal.querySelectorAll('.lcni-ss-emoji-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                saveSnap();
                const sz = size * 7 + 20;
                edCtx.font      = sz + 'px serif';
                edCtx.textBaseline = 'middle';
                edCtx.fillText(btn.dataset.emoji, edCanvas.width/2 - sz/2, edCanvas.height/2);
                edCtx.textBaseline = 'alphabetic';
            });
        });

        modal.querySelector('#lcni-ed-color').addEventListener('input', e => color = e.target.value);
        modal.querySelector('#lcni-ed-size').addEventListener('input',  e => size  = +e.target.value);

        // Undo (Ctrl+Z)
        modal.querySelector('#lcni-ed-undo').addEventListener('click', undo);
        modal.addEventListener('keydown', e => { if ((e.ctrlKey||e.metaKey) && e.key === 'z') { e.preventDefault(); undo(); } });
        function undo() {
            if (!history.length) return;
            const prev = history.pop();
            edCtx.clearRect(0, 0, edCanvas.width, edCanvas.height);
            edCtx.drawImage(prev, 0, 0);
        }

        // Watermark — hiện layer kéo thả, chưa burn
        modal.querySelector('#lcni-ed-wm').addEventListener('click', () => {
            if (!wmApplied) {
                wmLayer.style.pointerEvents = 'all';
                initWmPositions();
                wmApplied = true;
                modal.querySelector('#lcni-ed-wm').innerHTML = '📌';
                modal.querySelector('#lcni-ed-wm').title = 'Kéo logo/text đến vị trí mong muốn';
                showToast('Kéo logo & text đến vị trí mong muốn, rồi nhấn Hoàn tất');
            } else {
                // Toggle ẩn/hiện
                const vis = wmLogoEl.style.display !== 'none' || wmTextEl.style.display !== 'none';
                if (vis) {
                    wmLogoEl.style.display = 'none';
                    wmTextEl.style.display = 'none';
                    modal.querySelector('#lcni-ed-wm').style.opacity = '0.4';
                } else {
                    initWmPositions();
                    modal.querySelector('#lcni-ed-wm').style.opacity = '1';
                }
            }
        });

        // Draw events — dùng ovCanvas làm target để không miss events
        ovCanvas.addEventListener('mousedown',  down,   { passive: false });
        ovCanvas.addEventListener('touchstart', down,   { passive: false });
        ovCanvas.addEventListener('mousemove',  move,   { passive: false });
        ovCanvas.addEventListener('touchmove',  move,   { passive: false });
        ovCanvas.addEventListener('mouseup',    up,     { passive: false });
        ovCanvas.addEventListener('touchend',   up,     { passive: false });
        ovCanvas.addEventListener('mouseleave', cancel);

        function down(e) {
            e.preventDefault();
            if (tool === 'select') return;
            if (tool === 'emoji')  return;
            if (tool === 'text')   { insertText(e); return; }
            painting = true;
            const p = getPos(e);
            px = p.x; py = p.y;
            if (tool === 'draw') {
                saveSnap();
                edCtx.beginPath();
                edCtx.moveTo(px, py);
                setStroke(edCtx);
            }
        }

        function move(e) {
            e.preventDefault();
            if (!painting) return;
            const p = getPos(e);
            if (tool === 'draw') {
                edCtx.lineTo(p.x, p.y);
                edCtx.stroke();
            } else {
                // Preview on overlay
                ovCtx.clearRect(0, 0, ovCanvas.width, ovCanvas.height);
                setStroke(ovCtx);
                preview(ovCtx, px, py, p.x, p.y);
            }
        }

        function up(e) {
            if (!painting) return; painting = false;
            const p = getPos(e);
            ovCtx.clearRect(0, 0, ovCanvas.width, ovCanvas.height);
            if (tool === 'draw') { edCtx.closePath(); }
            else {
                saveSnap();
                setStroke(edCtx);
                preview(edCtx, px, py, p.x, p.y);
            }
        }

        function cancel() {
            if (!painting) return;
            painting = false;
            ovCtx.clearRect(0, 0, ovCanvas.width, ovCanvas.height);
            if (tool === 'draw') edCtx.closePath();
        }

        function setStroke(ctx) {
            ctx.strokeStyle = color;
            ctx.fillStyle   = color;
            ctx.lineWidth   = size;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            if (tool === 'hl') {
                ctx.globalAlpha  = 0.35;
                ctx.strokeStyle  = color;
                ctx.lineWidth    = size * 6;
            } else {
                ctx.globalAlpha = 1;
            }
        }

        function preview(ctx, x0, y0, x1, y1) {
            ctx.beginPath();
            if (tool === 'arrow') {
                drawArrow(ctx, x0, y0, x1, y1);
            } else if (tool === 'rect') {
                ctx.strokeRect(x0, y0, x1-x0, y1-y0);
            } else if (tool === 'hl') {
                ctx.moveTo(x0, y0); ctx.lineTo(x1, y1);
                ctx.stroke();
            }
            ctx.globalAlpha = 1;
        }

        function drawArrow(ctx, x1, y1, x2, y2) {
            const len   = Math.hypot(x2-x1, y2-y1);
            if (len < 4) return;
            const angle = Math.atan2(y2-y1, x2-x1);
            const head  = Math.max(14, size * 4);
            ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(x2, y2);
            ctx.lineTo(x2 - head * Math.cos(angle - Math.PI/6), y2 - head * Math.sin(angle - Math.PI/6));
            ctx.lineTo(x2 - head * Math.cos(angle + Math.PI/6), y2 - head * Math.sin(angle + Math.PI/6));
            ctx.closePath();
            ctx.fill();
        }

        function insertText(e) {
            const p     = getPos(e);
            const input = document.createElement('input');
            input.type  = 'text';
            input.className = 'lcni-ss-text-input';
            // Vị trí overlay trên màn hình
            const cr = edCanvas.getBoundingClientRect();
            const scX = cr.width  / edCanvas.width;
            const scY = cr.height / edCanvas.height;
            const displayX = p.x * scX + cr.left + window.scrollX;
            const displayY = p.y * scY + cr.top  + window.scrollY;
            const fSize    = (size * 3 + 12);
            input.style.cssText = [
                `position:absolute`,
                `left:${displayX}px`,
                `top:${displayY - fSize}px`,
                `font-size:${fSize * scX}px`,
                `color:${color}`,
                `min-width:${80 * scX}px`,
                `transform:translateY(-2px)`,
            ].join(';');
            document.body.appendChild(input);
            input.focus();
            function commit() {
                const txt = input.value.trim();
                if (txt) {
                    saveSnap();
                    edCtx.save();
                    edCtx.font = `bold ${fSize}px "DM Sans",sans-serif`;
                    edCtx.fillStyle  = color;
                    edCtx.shadowBlur = 3;
                    edCtx.shadowColor = '#000';
                    edCtx.fillText(txt, p.x, p.y);
                    edCtx.restore();
                }
                input.remove();
            }
            input.addEventListener('keydown', e => { if (e.key==='Enter') commit(); if (e.key==='Escape') input.remove(); });
            input.addEventListener('blur', commit, { once: true });
        }

        // Actions
        async function getExportCanvas() {
            // Export canvas giữ nguyên độ phân giải gốc (đã scale 2-4x từ html2canvas)
            const exp = document.createElement('canvas');
            exp.width  = edCanvas.width;
            exp.height = edCanvas.height;
            const expCtx = exp.getContext('2d');
            expCtx.imageSmoothingEnabled = true;
            expCtx.imageSmoothingQuality = 'high';
            expCtx.drawImage(edCanvas, 0, 0);
            if (wmApplied) {
                // Burn watermark vào clone
                const savedCanvas = edCanvas;
                const _edCtx = edCtx;
                // Tạm thời draw lên exp
                const tmpCtx = exp.getContext('2d');
                const scX = exp.width / dispW;
                const scY = exp.height / dispH;
                const opBase = parseFloat(CFG.watermarkOpacity) || 0.85;
                if (CFG.siteLogoUrl && wmLogoEl.style.display !== 'none') {
                    const lx = (parseInt(wmLogoEl.style.left)||0)*scX;
                    const ly = (parseInt(wmLogoEl.style.top) ||0)*scY;
                    const lw = wmLogoEl.offsetWidth *scX;
                    const lh = wmLogoEl.offsetHeight*scY;
                    const img = wmLogoEl.querySelector('img');
                    if (img) {
                        tmpCtx.save();
                        tmpCtx.globalAlpha = opBase;
                        tmpCtx.shadowBlur = Math.round(lw*0.07);
                        tmpCtx.shadowColor = 'rgba(0,0,0,0.4)';
                        tmpCtx.drawImage(img, lx, ly, lw, lh);
                        tmpCtx.restore();
                    }
                }
                if (resolvedWmText && wmTextEl.style.display !== 'none') {
                    const tx = (parseInt(wmTextEl.style.left)||0)*scX;
                    const ty = (parseInt(wmTextEl.style.top) ||0)*scY;
                    const tSize = Math.round((parseInt(CFG.watermarkTextSize)||14)*(exp.width/800));
                    const fc = CFG.watermarkTextColor || 'rgba(255,255,255,0.92)';
                    tmpCtx.save();
                    tmpCtx.font = `600 ${Math.max(10,tSize)}px "DM Sans",sans-serif`;
                    tmpCtx.fillStyle = fc; tmpCtx.globalAlpha = opBase;
                    tmpCtx.shadowBlur = 3; tmpCtx.shadowColor = 'rgba(0,0,0,0.6)';
                    tmpCtx.fillText(resolvedWmText, tx, ty+tSize);
                    tmpCtx.restore();
                }
            } else if (!frameApplied) {
                // Chỉ apply watermark nếu chưa dùng frame
                // Frame đã có logo/text trong bar dưới, không cần watermark thêm
                await applyWatermark(exp);
            }
            return exp;
        }

        modal.querySelector('#lcni-ed-copy').addEventListener('click', async () => {
            if (!navigator.clipboard?.write) { showToast('Trình duyệt chưa hỗ trợ copy ảnh'); return; }
            const exp = await getExportCanvas();
            exp.toBlob(async blob => {
                try {
                    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
                    showToast('✅ Đã sao chép vào clipboard');
                } catch (_) { showToast('❌ Không thể sao chép (HTTPS cần thiết)'); }
            });
        });

        modal.querySelector('#lcni-ed-download').addEventListener('click', async () => {
            const exp = await getExportCanvas();
            const a = document.createElement('a');
            // PNG: lossless, file lớn hơn nhưng sắc nét nhất
            a.download = `niinsight-${Date.now()}.png`;
            a.href     = exp.toDataURL('image/png');
            a.click();
            showToast('⬇️ Đang tải về (PNG chất lượng cao)...');
        });

        modal.querySelector('#lcni-ed-share').addEventListener('click', async () => {
            if (!navigator.share) { showToast('Chia sẻ trực tiếp chỉ hỗ trợ trên mobile'); return; }
            const exp = await getExportCanvas();
            exp.toBlob(async blob => {
                const file = new File([blob], 'screenshot.png', { type: 'image/png' });
                try { await navigator.share({ files: [file], title: CFG.siteName || 'Screenshot' }); }
                catch (_) {}
            });
        });

        modal.querySelector('#lcni-ed-done').addEventListener('click', async () => {
            // Burn watermark từ vị trí drag layer hiện tại vào canvas
            if (wmApplied) {
                await burnWatermark();
            } else {
                // Chưa thêm watermark → burn theo config mặc định
                await applyWatermark(edCanvas);
            }
            // Ẩn wm layer trước khi export (không ảnh hưởng canvas)
            wmLayer.style.display = 'none';
            const a = document.createElement('a');
            a.download = `lcni-screenshot-${Date.now()}.png`;
            a.href     = edCanvas.toDataURL('image/png');
            a.click();
            modal.remove();
        });

        modal.querySelector('.lcni-ss-ed-close').addEventListener('click', () => modal.remove());
        modal.addEventListener('mousedown', e => { if (e.target === modal) modal.remove(); });
    }

    /* ── Mode picker ── */
    function openModePicker() {
        const prev = document.querySelector('.lcni-ss-mode-picker');
        if (prev) { prev.remove(); return; }

        const picker = document.createElement('div');
        picker.className = 'lcni-ss-mode-picker';
        picker.innerHTML = `
<div class="lcni-ss-mode-title">📸 Chọn chế độ</div>
<button class="lcni-ss-mode-btn" data-mode="full">
  <span class="lcni-ss-mode-icon">🖥️</span><span>Toàn trang</span>
</button>
<button class="lcni-ss-mode-btn" data-mode="region">
  <span class="lcni-ss-mode-icon">✂️</span><span>Chọn vùng kéo</span>
</button>`;
        document.body.appendChild(picker);

        // Vị trí gần FAB
        const fab = document.getElementById('lcni-ss-fab');
        const pos = CFG.btnPosition || 'bottom-right';
        if (fab) {
            const r = fab.getBoundingClientRect();
            picker.style.position = 'fixed';
            if (pos.includes('bottom')) picker.style.bottom = (window.innerHeight - r.top + 8) + 'px';
            else                        picker.style.top    = (r.bottom + 8) + 'px';
            if (pos.includes('right'))  picker.style.right  = (window.innerWidth - r.right) + 'px';
            else                        picker.style.left   = r.left + 'px';
        }

        picker.querySelectorAll('.lcni-ss-mode-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                picker.remove();
                const mode = btn.dataset.mode;
                showToast('⏳ Đang chụp...', 5000);
                try {
                    const canvas = mode === 'full' ? await captureFullPage() : await captureRegion();
                    // Không auto-burn watermark trước editor
                    // Watermark sẽ được burn khi export (getExportCanvas)
                    openEditor(canvas);
                } catch (err) {
                    if (err && err.message !== 'cancelled') showToast('❌ Lỗi: ' + (err.message || err));
                    // Đảm bảo FAB luôn hiện lại dù có lỗi
                    const fabEl = document.getElementById('lcni-ss-fab');
                    const rootEl = getRoot();
                    if (fabEl)  fabEl.style.visibility  = '';
                    if (rootEl) rootEl.style.visibility = '';
                }
            });
        });

        // Close on outside click
        setTimeout(() => {
            document.addEventListener('click', function cls(e) {
                if (!picker.contains(e.target) && e.target.id !== 'lcni-ss-fab') {
                    picker.remove();
                    document.removeEventListener('click', cls);
                }
            });
        }, 100);
    }

    /* ── FAB ── */
    function createFAB() {
        const fab = document.createElement('button');
        fab.id        = 'lcni-ss-fab';
        fab.className = 'lcni-ss-fab';
        fab.title     = 'Chụp màn hình & Chia sẻ';
        fab.dataset.pos = CFG.btnPosition || 'bottom-right';
        fab.setAttribute('aria-label', 'Chụp màn hình');
        fab.innerHTML = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
          <circle cx="12" cy="13" r="4"/>
        </svg>`;
        fab.addEventListener('click', openModePicker);
        const root = getRoot();
        (root || document.body).appendChild(fab);
    }

    /* ── Init ── */
    function init() {
        if (typeof html2canvas === 'undefined') {
            console.warn('[LCNI Screenshot] html2canvas chưa load'); return;
        }
        createFAB();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else { init(); }
})();

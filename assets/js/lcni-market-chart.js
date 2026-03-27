/**
 * lcni-market-chart.js
 * Biểu đồ lịch sử biến động thị trường — Apache ECharts
 * Shortcode: [lcni_market_chart]
 */

( function () {
    'use strict';

    /* ─── Palette ─────────────────────────────────────────────── */
    var SERIES_CFG = {
        composite: { label: 'Composite',    color: '#f5a623', key: 'composite',  yAxis: 0 },
        breadth:   { label: 'Breadth A/D',  color: '#4fc3f7', key: 'ad_ratio',   yAxis: 0 },
        sentiment: { label: 'Fear & Greed', color: '#ef5350', key: 'fear_greed', yAxis: 0 },
        flow:      { label: 'Dòng tiền %',  color: '#66bb6a', key: 'flow_breadth', yAxis: 0 },
        rotation:  { label: 'Rotation',     color: '#ab47bc', key: 'rotation',   yAxis: 0 },
    };

    var BIAS_COLOR = { 'Tích cực': '#66bb6a', 'Tiêu cực': '#ef5350', 'Trung tính': '#90a4ae' };

    /* ─── Init all widgets on page ───────────────────────────── */
    function initAll() {
        document.querySelectorAll( '.lcni-market-chart-wrap' ).forEach( function ( wrap ) {
            if ( wrap.dataset._lcniMcInit ) return;
            wrap.dataset._lcniMcInit = '1';
            initWidget( wrap );
        } );
    }

    function initWidget( wrap ) {
        var raw = wrap.dataset.config;
        if ( ! raw ) return;
        var cfg;
        try { cfg = JSON.parse( raw ); } catch(e) { return; }

        var state = {
            timeframe: cfg.timeframe || '1D',
            days:      cfg.days      || 60,
            active:    { composite:true, breadth:true, sentiment:true, flow:true, rotation:true },
            data:      null,
            chart:     null,
        };

        var chartEl  = wrap.querySelector( '.lcni-mc-echarts' );
        var loaderEl = wrap.querySelector( '.lcni-mc-loader' );
        var errorEl  = wrap.querySelector( '.lcni-mc-error' );

        /* ── ECharts init ─────────────────────────────────────── */
        function ensureChart() {
            if ( state.chart ) return state.chart;
            if ( ! window.echarts ) return null;
            var existing = window.echarts.getInstanceByDom( chartEl );
            if ( existing ) { state.chart = existing; return state.chart; }
            state.chart = window.echarts.init( chartEl, null, { renderer: 'canvas' } );
            // Resize observer
            if ( window.ResizeObserver ) {
                var ro = new ResizeObserver( function () { state.chart && state.chart.resize(); } );
                ro.observe( chartEl );
            }
            return state.chart;
        }

        /* ── Load data via REST ───────────────────────────────── */
        function loadData() {
            showLoader( true );
            showError( '' );

            var url = cfg.rest_url + '?timeframe=' + encodeURIComponent( state.timeframe ) + '&days=' + state.days;
            fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce } } )
                .then( function (r) { return r.json(); } )
                .then( function (res) {
                    showLoader( false );
                    if ( ! res.success || ! res.data || res.data.length === 0 ) {
                        showError( res.message || 'Chưa có dữ liệu lịch sử trong bảng market_context.' );
                        return;
                    }
                    state.data = res.data;
                    renderChart();
                } )
                .catch( function (e) {
                    showLoader( false );
                    showError( 'Lỗi kết nối API: ' + e.message );
                } );
        }

        /* ── Build ECharts option ─────────────────────────────── */
        function buildOption( data ) {
            var dates      = data.map( function(d){ return d.date; } );
            var biasColors = data.map( function(d){ return BIAS_COLOR[ d.bias ] || '#90a4ae'; } );

            var series = [];

            // Background bands: bias color (area at bottom)
            series.push( {
                name:   '__bias__',
                type:   'bar',
                barWidth: '100%',
                silent: true,
                stack:  '__bg__',
                z:      0,
                itemStyle: {
                    color: function(p) { return hexAlpha( biasColors[p.dataIndex], 0.08 ); },
                },
                data: data.map( function() { return 100; } ),
                tooltip: { show: false },
            } );

            Object.keys( SERIES_CFG ).forEach( function( key ) {
                if ( ! state.active[key] ) return;
                var c = SERIES_CFG[key];
                series.push( {
                    name:   c.label,
                    type:   'line',
                    smooth: 0.35,
                    z:      10,
                    symbol: 'none',
                    sampling: 'lttb',
                    lineStyle: { width: 2, color: c.color },
                    itemStyle: { color: c.color },
                    areaStyle: {
                        color: {
                            type: 'linear', x:0, y:0, x2:0, y2:1,
                            colorStops: [
                                { offset: 0,   color: hexAlpha( c.color, 0.22 ) },
                                { offset: 1,   color: hexAlpha( c.color, 0.01 ) },
                            ],
                        },
                    },
                    data: data.map( function(d){ return roundVal( d[ c.key ] ); } ),
                    emphasis: { focus: 'series' },
                } );
            } );

            // Marklines: neutral zones
            var markLineData = [ { yAxis: 50, name: '50', lineStyle:{ color:'rgba(255,255,255,0.15)', type:'dashed' } } ];

            var isDark = ( cfg.theme !== 'light' );
            var textColor  = isDark ? '#b0bec5' : '#455a64';
            var gridColor  = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
            var bgColor    = isDark ? 'transparent' : 'transparent';
            var tooltipBg  = isDark ? '#1a2332' : '#fff';
            var tooltipBorder = isDark ? '#2e3d50' : '#ddd';

            return {
                backgroundColor: bgColor,
                animation: true,
                animationDuration: 600,
                animationEasing: 'cubicOut',

                tooltip: {
                    trigger: 'axis',
                    axisPointer: {
                        type: 'cross',
                        lineStyle: { color: isDark ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.15)' },
                        crossStyle: { color: isDark ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.15)' },
                    },
                    backgroundColor: tooltipBg,
                    borderColor: tooltipBorder,
                    borderWidth: 1,
                    textStyle: { color: textColor, fontSize: 12 },
                    formatter: function( params ) {
                        var idx = params[0] ? params[0].dataIndex : 0;
                        var d = data[idx];
                        if ( ! d ) return '';
                        var bias = d.bias || '';
                        var bCol = BIAS_COLOR[bias] || '#90a4ae';
                        var html = '<div style="font-weight:700;margin-bottom:6px;font-size:13px;">'
                            + d.date
                            + ' &nbsp;<span style="color:' + bCol + ';font-size:11px;">' + bias + '</span></div>';
                        html += '<div style="color:#78909c;font-size:11px;margin-bottom:4px;">' + (d.phase||'') + '</div>';
                        params.forEach( function(p) {
                            if ( p.seriesName === '__bias__' ) return;
                            html += '<div style="display:flex;justify-content:space-between;gap:24px;line-height:1.8;">'
                                + '<span>' + dot(p.color) + p.seriesName + '</span>'
                                + '<span style="font-weight:600;">' + p.value + '</span>'
                                + '</div>';
                        } );
                        return html;
                    },
                    confine: true,
                },

                legend: { show: false }, // handled by custom buttons

                grid: {
                    top: 20, right: 20, bottom: 36, left: 52,
                    containLabel: false,
                },

                xAxis: {
                    type:            'category',
                    data:            dates,
                    boundaryGap:     false,
                    axisLine:        { lineStyle: { color: gridColor } },
                    axisTick:        { show: false },
                    axisLabel:       { color: textColor, fontSize: 11, interval: 'auto' },
                    splitLine:       { show: false },
                },

                yAxis: {
                    type:        'value',
                    min:         0,
                    max:         100,
                    splitNumber: 5,
                    axisLabel:   { color: textColor, fontSize: 11, formatter: '{value}' },
                    axisLine:    { show: false },
                    axisTick:    { show: false },
                    splitLine:   { lineStyle: { color: gridColor, type: 'dashed' } },
                },

                dataZoom: [
                    {
                        type: 'inside',
                        start: Math.max( 0, 100 - Math.round( 40 / data.length * 100 ) ),
                        end:   100,
                        minValueSpan: 10,
                    },
                    {
                        type:         'slider',
                        height:       18,
                        bottom:       0,
                        start:        Math.max( 0, 100 - Math.round( 40 / data.length * 100 ) ),
                        end:          100,
                        handleStyle:  { color: '#f5a623' },
                        textStyle:    { color: textColor, fontSize: 10 },
                        fillerColor:  isDark ? 'rgba(245,166,35,0.12)' : 'rgba(245,166,35,0.18)',
                        borderColor:  'transparent',
                        backgroundColor: isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.04)',
                        dataBackground: {
                            lineStyle: { color: isDark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.15)' },
                            areaStyle: { color: isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.04)' },
                        },
                    },
                ],

                series: series,
            };
        }

        /* ── Render ───────────────────────────────────────────── */
        function renderChart() {
            if ( ! state.data ) return;
            waitForEcharts( function() {
                var chart = ensureChart();
                if ( ! chart ) { showError( 'Không load được ECharts.' ); return; }
                chart.setOption( buildOption( state.data ), true );
            } );
        }

        /* ── UI helpers ───────────────────────────────────────── */
        function showLoader( on ) {
            loaderEl.style.display = on ? 'flex' : 'none';
            chartEl.style.opacity  = on ? '0' : '1';
        }

        function showError( msg ) {
            errorEl.style.display = msg ? 'flex' : 'none';
            errorEl.textContent   = msg;
        }

        /* ── Bind controls ────────────────────────────────────── */
        wrap.querySelectorAll( '.lcni-mc-tab' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function() {
                wrap.querySelectorAll( '.lcni-mc-tab' ).forEach( function(b){ b.classList.remove('active'); } );
                btn.classList.add( 'active' );
                state.timeframe = btn.dataset.tf;
                loadData();
            } );
        } );

        wrap.querySelectorAll( '.lcni-mc-series-btn' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function() {
                var key = btn.dataset.series;
                state.active[key] = ! state.active[key];
                btn.classList.toggle( 'active', state.active[key] );
                // Sync legend
                var leg = wrap.querySelector( '.lcni-mc-leg-item[data-series="' + key + '"]' );
                if ( leg ) leg.style.opacity = state.active[key] ? '1' : '0.35';
                renderChart();
            } );
        } );

        wrap.querySelector( '.lcni-mc-days' ).addEventListener( 'change', function() {
            state.days = parseInt( this.value, 10 );
            loadData();
        } );

        // Set correct tab active on init
        wrap.querySelectorAll( '.lcni-mc-tab' ).forEach( function(b) {
            b.classList.toggle( 'active', b.dataset.tf === state.timeframe );
        } );

        loadData();
    }

    /* ─── Wait for ECharts CDN ────────────────────────────────── */
    function waitForEcharts( cb, tries ) {
        tries = tries || 0;
        if ( window.echarts && typeof window.echarts.init === 'function' ) { cb(); return; }
        if ( tries > 40 ) return; // give up after 4s
        setTimeout( function() { waitForEcharts( cb, tries + 1 ); }, 100 );
    }

    /* ─── Utils ───────────────────────────────────────────────── */
    function hexAlpha( hex, alpha ) {
        var r = parseInt( hex.slice(1,3), 16 );
        var g = parseInt( hex.slice(3,5), 16 );
        var b = parseInt( hex.slice(5,7), 16 );
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }
    function roundVal( v ) { return Math.round( parseFloat(v) * 10 ) / 10; }
    function dot( color ) {
        return '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + color + ';margin-right:6px;"></span>';
    }

    /* ─── Boot ────────────────────────────────────────────────── */
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initAll );
    } else {
        initAll();
    }

    // Support dynamic page builders
    document.addEventListener( 'lcni:reinit', initAll );

} )();

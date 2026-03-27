(function () {
  const parseCandles = (rows) => (Array.isArray(rows) ? rows : []).filter((item) => item && item.time != null);
  const fmtLabel = (time) => {
    const d = new Date(Number(time) * 1000);
    if (Number.isNaN(d.getTime())) return String(time || '');
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  function buildOption(candles, theme) {
    const labels = candles.map((item) => fmtLabel(item.time));
    const ohlc = candles.map((item) => [Number(item.open || 0), Number(item.close || 0), Number(item.low || 0), Number(item.high || 0)]);
    const volumes = candles.map((item, idx) => [idx, Number(item.volume || 0), Number(item.close || 0) >= Number(item.open || 0) ? 1 : -1]);

    return {
      animation: false,
      legend: { show: false },
      axisPointer: { link: [{ xAxisIndex: [0, 1] }] },
      tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
      dataset: {},
      grid: [
        { left: 8, right: 8, top: 8, height: '68%' },
        { left: 8, right: 8, top: '80%', height: '16%' }
      ],
      xAxis: [
        { type: 'category', data: labels, boundaryGap: true, axisLine: { lineStyle: { color: theme.grid } }, splitLine: { show: false }, min: 'dataMin', max: 'dataMax' },
        { type: 'category', gridIndex: 1, data: labels, boundaryGap: true, axisLine: { lineStyle: { color: theme.grid } }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false }, min: 'dataMin', max: 'dataMax' }
      ],
      yAxis: [
        { scale: true, splitLine: { lineStyle: { color: theme.grid } } },
        { gridIndex: 1, splitNumber: 2, splitLine: { show: false } }
      ],
      dataZoom: [
        { type: 'inside', xAxisIndex: [0, 1], start: 70, end: 100 },
        { show: false, xAxisIndex: [0, 1], type: 'slider', top: '96%', start: 70, end: 100 }
      ],
      series: [
        { name: 'price', type: 'candlestick', data: ohlc, itemStyle: { color: '#16a34a', color0: '#dc2626', borderColor: '#16a34a', borderColor0: '#dc2626' }, progressive: 200 },
        { name: 'volume', type: 'bar', xAxisIndex: 1, yAxisIndex: 1, data: volumes, itemStyle: { color: function (params) { return params.data[2] > 0 ? '#16a34a' : '#dc2626'; } }, progressive: 200 }
      ]
    };
  }

  window.LCNIChartEngine = {
    init(container, options) {
      const candles = parseCandles(options && options.data);
      const theme = (options && options.theme) || { bg: '#fff', text: '#333', grid: '#efefef' };
      const chart = window.echarts && typeof window.echarts.init === 'function' ? window.echarts.init(container, null, { renderer: 'canvas' }) : null;
      const state = { chart, candles, symbol: options && options.symbol || '', timeframe: options && options.timeframe || '1D', theme };
      if (chart) {
        chart.setOption(buildOption(candles, theme), false, false);
      }
      return {
        updateData(nextData) {
          state.candles = parseCandles(nextData);
          if (state.chart) state.chart.setOption(buildOption(state.candles, state.theme), false, false);
        },
        updateSymbol(symbol) { state.symbol = symbol || state.symbol; },
        updateTimeframe(tf) { state.timeframe = tf || state.timeframe; },
        resize() { if (state.chart) state.chart.resize(); },
        destroy() { if (state.chart) state.chart.dispose(); }
      };
    }
  };
})();

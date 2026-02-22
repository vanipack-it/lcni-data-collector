(function initLcniEchartsEngine(windowObject) {
  'use strict';

  const win = windowObject;
  if (!win || !win.echarts) {
    return;
  }

  const debounce = function debounce(fn, wait) {
    let timer = null;
    return function debounced() {
      const context = this;
      const args = arguments;
      if (timer) {
        win.clearTimeout(timer);
      }
      timer = win.setTimeout(function run() {
        timer = null;
        fn.apply(context, args);
      }, wait);
    };
  };

  const buildStaticOption = function buildStaticOption() {
    return {
      animation: false,
      useDirtyRect: true,
      progressive: 200,
      progressiveThreshold: 1000,
      tooltip: {
        trigger: 'axis',
        axisPointer: {
          type: 'cross',
          link: [{ xAxisIndex: [0, 1] }]
        }
      },
      axisPointer: {
        link: [{ xAxisIndex: [0, 1] }]
      },
      grid: [
        { left: 48, right: 16, top: 16, height: '62%' },
        { left: 48, right: 16, top: '74%', height: '16%' }
      ],
      xAxis: [
        {
          type: 'category',
          boundaryGap: true,
          scale: true,
          axisLine: { onZero: false },
          splitLine: { show: false },
          min: 'dataMin',
          max: 'dataMax'
        },
        {
          type: 'category',
          gridIndex: 1,
          boundaryGap: true,
          axisLine: { onZero: false },
          axisTick: { show: false },
          axisLabel: { show: false },
          splitLine: { show: false },
          min: 'dataMin',
          max: 'dataMax'
        }
      ],
      yAxis: [
        { scale: true, splitArea: { show: false } },
        { scale: true, gridIndex: 1, splitNumber: 2 }
      ],
      dataZoom: [
        { type: 'inside', xAxisIndex: [0, 1], start: 0, end: 100 },
        { type: 'slider', xAxisIndex: [0, 1], top: '92%', start: 0, end: 100 }
      ]
    };
  };

  const normalizeCandle = function normalizeCandle(candle) {
    const timestamp = candle.time || candle.timestamp || candle.date || candle.datetime;
    const open = Number(candle.open);
    const high = Number(candle.high);
    const low = Number(candle.low);
    const close = Number(candle.close);
    const volume = Number(candle.volume || 0);

    if (!timestamp || [open, high, low, close].some(function (v) { return Number.isNaN(v); })) {
      return null;
    }

    return {
      timeLabel: String(timestamp),
      ohlc: [open, close, low, high],
      volume: volume,
      isUp: close >= open ? 1 : -1
    };
  };

  const createChart = function createChart(container) {
    if (!container) {
      return null;
    }

    const existed = win.echarts.getInstanceByDom(container);
    if (existed) {
      existed.dispose();
    }

    const chart = win.echarts.init(container, null, {
      renderer: 'canvas',
      useDirtyRect: true
    });

    const staticOption = buildStaticOption();
    const resizeHandler = debounce(function onResize() {
      if (!chart.isDisposed()) {
        chart.resize();
      }
    }, 120);

    let observer = null;
    if (typeof win.ResizeObserver === 'function') {
      observer = new win.ResizeObserver(resizeHandler);
      observer.observe(container);
    }

    let currentSeriesSignature = '';

    const updateData = function updateData(candles) {
      const normalized = (Array.isArray(candles) ? candles : [])
        .map(normalizeCandle)
        .filter(Boolean);

      const categoryData = normalized.map(function (item) { return item.timeLabel; });
      const candleData = normalized.map(function (item) { return item.ohlc; });
      const volumeData = normalized.map(function (item) {
        return {
          value: item.volume,
          itemStyle: {
            color: item.isUp > 0 ? '#16a34a' : '#dc2626'
          }
        };
      });

      const nextSignature = categoryData.length ? [categoryData[0], categoryData[categoryData.length - 1], categoryData.length].join('|') : 'empty';
      const dynamicOption = {
        xAxis: [
          { data: categoryData },
          { data: categoryData }
        ],
        series: [
          {
            id: 'candles',
            type: 'candlestick',
            data: candleData,
            progressive: 200,
            progressiveThreshold: 1000
          },
          {
            id: 'volumes',
            type: 'bar',
            xAxisIndex: 1,
            yAxisIndex: 1,
            data: volumeData,
            progressive: 200,
            progressiveThreshold: 1000
          }
        ]
      };

      if (currentSeriesSignature === '') {
        chart.setOption(Object.assign({}, staticOption, dynamicOption), {
          notMerge: true,
          lazyUpdate: true,
          replaceMerge: ['xAxis', 'series']
        });
      } else if (currentSeriesSignature !== nextSignature) {
        chart.setOption(dynamicOption, {
          notMerge: false,
          lazyUpdate: true,
          replaceMerge: ['xAxis', 'series']
        });
      } else {
        chart.setOption({
          series: [
            { id: 'candles', data: candleData },
            { id: 'volumes', data: volumeData }
          ]
        }, {
          notMerge: false,
          lazyUpdate: true,
          replaceMerge: ['series']
        });
      }

      currentSeriesSignature = nextSignature;
    };

    return {
      updateData: updateData,
      resize: resizeHandler,
      destroy: function destroy() {
        if (observer) {
          observer.disconnect();
          observer = null;
        }

        if (!chart.isDisposed()) {
          chart.dispose();
        }
      }
    };
  };

  win.LCNIChartEchartsEngine = {
    createChart: createChart
  };
})(window);

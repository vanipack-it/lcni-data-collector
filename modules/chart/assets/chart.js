(() => {
  'use strict';

  const SELECTOR = '[data-lcni-chart]';
  const DEFAULT_LIMIT = 200;
  const MAX_LIMIT = 1000;
  const DEFAULT_HEIGHT = 420;
  const MIN_HEIGHT = 260;
  const MAX_HEIGHT = 1600;
  const instances = new WeakMap();

  function sanitizeSymbol(value) {
    const symbol = String(value || '').trim().toUpperCase();
    return /^[A-Z0-9._-]{1,20}$/.test(symbol) ? symbol : '';
  }

  function parseLimit(value) {
    const parsed = Number.parseInt(String(value || ''), 10);
    if (!Number.isFinite(parsed) || parsed <= 0) {
      return DEFAULT_LIMIT;
    }

    return Math.min(parsed, MAX_LIMIT);
  }

  function parseHeight(value) {
    const parsed = Number.parseInt(String(value || ''), 10);
    if (!Number.isFinite(parsed)) {
      return DEFAULT_HEIGHT;
    }

    return Math.max(MIN_HEIGHT, Math.min(parsed, MAX_HEIGHT));
  }

  function setError(el) {
    el.innerHTML = '<div class="lcni-chart-error">No data available</div>';
  }

  function fetchData(apiBase, symbol, limit) {
    const base = String(apiBase || '').trim();
    if (!base || !symbol) {
      return Promise.reject(new Error('Invalid chart configuration'));
    }

    let endpoint;
    try {
      endpoint = new URL(base, window.location.origin);
    } catch (_error) {
      return Promise.reject(new Error('Invalid endpoint'));
    }

    endpoint.searchParams.set('symbol', symbol);
    endpoint.searchParams.set('limit', String(limit));

    return fetch(endpoint.toString(), {
      method: 'GET',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error('REST request failed');
        }

        return response.json();
      })
      .then((payload) => {
        if (!payload || !Array.isArray(payload.data) || !payload.data.length) {
          throw new Error('No rows');
        }

        return {
          symbol: sanitizeSymbol(payload.symbol) || symbol,
          rows: payload.data
        };
      });
  }

  function renderChart(el, symbol, rows) {
    if (!window.echarts || typeof window.echarts.init !== 'function') {
      throw new Error('ECharts unavailable');
    }

    if (!Array.isArray(rows) || !rows.length) {
      throw new Error('No rows');
    }

    const categoryData = [];
    const values = [];
    const volumes = [];

    rows.forEach((row) => {
      const date = String(row && row.date ? row.date : '').trim();
      const open = Number(row && row.open);
      const high = Number(row && row.high);
      const low = Number(row && row.low);
      const close = Number(row && row.close);
      const volume = Number(row && row.volume);

      if (!date || !Number.isFinite(open) || !Number.isFinite(high) || !Number.isFinite(low) || !Number.isFinite(close)) {
        return;
      }

      categoryData.push(date);
      values.push([open, close, low, high]);
      volumes.push(Number.isFinite(volume) ? volume : 0);
    });

    if (!categoryData.length) {
      throw new Error('Invalid data');
    }

    let chart = window.echarts.getInstanceByDom(el);
    if (chart) {
      chart.dispose();
    }

    chart = window.echarts.init(el);

    const option = {
      animation: true,
      legend: {
        data: [symbol, 'Volume'],
        left: 0
      },
      tooltip: {
        trigger: 'axis',
        axisPointer: {
          type: 'cross'
        }
      },
      axisPointer: {
        link: [{ xAxisIndex: [0, 1] }]
      },
      grid: [
        {
          left: '8%',
          right: '3%',
          top: 40,
          height: '58%'
        },
        {
          left: '8%',
          right: '3%',
          top: '74%',
          height: '16%'
        }
      ],
      xAxis: [
        {
          type: 'category',
          data: categoryData,
          boundaryGap: false,
          axisLine: { onZero: false },
          splitLine: { show: false },
          min: 'dataMin',
          max: 'dataMax'
        },
        {
          type: 'category',
          gridIndex: 1,
          data: categoryData,
          boundaryGap: false,
          axisLine: { onZero: false },
          axisTick: { show: false },
          axisLabel: { show: false },
          splitLine: { show: false },
          min: 'dataMin',
          max: 'dataMax'
        }
      ],
      yAxis: [
        {
          scale: true,
          splitArea: { show: true }
        },
        {
          scale: true,
          gridIndex: 1,
          splitNumber: 2,
          axisLabel: { show: true },
          splitLine: { show: false }
        }
      ],
      dataZoom: [
        {
          type: 'inside',
          xAxisIndex: [0, 1],
          start: 70,
          end: 100
        },
        {
          show: true,
          type: 'slider',
          xAxisIndex: [0, 1],
          bottom: 10,
          start: 70,
          end: 100
        }
      ],
      series: [
        {
          name: symbol,
          type: 'candlestick',
          data: values,
          itemStyle: {
            color: '#16a34a',
            color0: '#dc2626',
            borderColor: '#16a34a',
            borderColor0: '#dc2626'
          }
        },
        {
          name: 'Volume',
          type: 'bar',
          xAxisIndex: 1,
          yAxisIndex: 1,
          data: volumes,
          barMaxWidth: 12,
          itemStyle: {
            color: '#94a3b8'
          }
        }
      ]
    };

    chart.setOption(option, true);
    return chart;
  }

  function initChart(el) {
    if (!el || instances.has(el)) {
      return;
    }

    instances.set(el, { initialized: true });

    const apiBase = String(el.dataset.apiBase || '').trim();
    const symbol = sanitizeSymbol(el.dataset.symbol);
    const limit = parseLimit(el.dataset.limit);
    const height = parseHeight(el.dataset.height || el.dataset.mainHeight);

    if (!apiBase || !symbol) {
      setError(el);
      return;
    }

    el.innerHTML = '';

    const chartEl = document.createElement('div');
    chartEl.className = 'lcni-chart-canvas';
    chartEl.style.width = '100%';
    chartEl.style.height = `${height}px`;
    chartEl.textContent = 'Loading chart...';
    el.appendChild(chartEl);

    let disposed = false;

    const resizeHandler = () => {
      if (disposed || !window.echarts) {
        return;
      }

      const chart = window.echarts.getInstanceByDom(chartEl);
      if (chart) {
        chart.resize();
      }
    };

    window.addEventListener('resize', resizeHandler, { passive: true });

    fetchData(apiBase, symbol, limit)
      .then((result) => {
        if (disposed) {
          return;
        }

        const chart = renderChart(chartEl, result.symbol, result.rows);
        chartEl.textContent = '';
        instances.set(el, { initialized: true, chartEl, chart, resizeHandler });
      })
      .catch(() => {
        if (disposed) {
          return;
        }

        setError(el);
      });

    instances.set(el, {
      initialized: true,
      chartEl,
      resizeHandler,
      destroy: () => {
        disposed = true;
        window.removeEventListener('resize', resizeHandler);
        if (window.echarts && chartEl) {
          const chart = window.echarts.getInstanceByDom(chartEl);
          if (chart) {
            chart.dispose();
          }
        }
      }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(SELECTOR).forEach(initChart);
  });
})();

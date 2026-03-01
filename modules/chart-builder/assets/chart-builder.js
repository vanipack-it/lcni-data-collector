(function () {
  const parsePayload = (node) => {
    try {
      return JSON.parse(node.getAttribute('data-lcni-chart-builder') || '{}');
    } catch (e) {
      return {};
    }
  };

  const buildOption = (payload) => {
    const cfg = payload.config || {};
    const rows = Array.isArray(payload.data) ? payload.data : [];
    const xAxis = cfg.xAxis || 'event_time';
    const seriesCfg = Array.isArray(cfg.series) ? cfg.series : [];
    const chartType = payload.chart_type || cfg.template || 'multi_line';

    const isAreaStack = chartType === 'area_stack';

    const series = seriesCfg.map((item, index) => {
      const hasColor = typeof item.color === 'string' && item.color.trim() !== '';
      const baseSeries = {
        name: item.name,
        type: item.type || 'line',
        smooth: item.type !== 'bar',
        data: rows.map((r) => Number(r[item.field] || 0)),
      };

      if (isAreaStack) {
        baseSeries.type = 'line';
        baseSeries.stack = item.stack ? 'Total' : undefined;
        baseSeries.areaStyle = item.area ? {} : undefined;
        baseSeries.emphasis = { focus: 'series' };
        baseSeries.label = item.label_show ? { show: true, position: 'top' } : undefined;
      }

      if (hasColor) {
        baseSeries.color = item.color;
        if (isAreaStack) {
          baseSeries.lineStyle = { color: item.color };
          baseSeries.itemStyle = { color: item.color };
        }
      }

      if (index === seriesCfg.length - 1 && isAreaStack && !baseSeries.label) {
        baseSeries.label = { show: true, position: 'top' };
      }

      return baseSeries;
    });

    return {
      title: { text: isAreaStack ? 'Stacked Area Chart' : (payload.name || '') },
      tooltip: { trigger: 'axis' },
      legend: { top: 8, data: series.map((item) => item.name) },
      grid: { left: 40, right: 20, top: 56, bottom: 28 },
      xAxis: { type: 'category', boundaryGap: !isAreaStack ? true : false, data: rows.map((r) => r[xAxis]) },
      yAxis: { type: 'value' },
      series,
    };
  };

  const boot = () => {
    document.querySelectorAll('[data-lcni-chart-builder]').forEach((node) => {
      if (node.dataset.lcniInit === '1') return;
      node.dataset.lcniInit = '1';

      if (!window.echarts) return;
      const payload = parsePayload(node);
      const chart = window.echarts.init(node);
      chart.setOption(buildOption(payload));

      const syncGroup = node.getAttribute('data-sync-group') || '';
      if (syncGroup) {
        chart.group = syncGroup;
        window.echarts.connect(syncGroup);
      }

      window.addEventListener('resize', () => chart.resize());
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

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

    return {
      tooltip: { trigger: 'axis' },
      legend: { top: 8 },
      grid: { left: 40, right: 20, top: 48, bottom: 28 },
      xAxis: { type: 'category', data: rows.map((r) => r[xAxis]) },
      yAxis: { type: 'value' },
      series: seriesCfg.map((item) => ({
        name: item.name,
        type: item.type || 'line',
        smooth: item.type !== 'bar',
        data: rows.map((r) => Number(r[item.field] || 0)),
      })),
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

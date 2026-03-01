(function () {
  const parsePayload = (node) => {
    try {
      return JSON.parse(node.getAttribute('data-lcni-chart-builder') || '{}');
    } catch (e) {
      return {};
    }
  };

  const formatValue = (value, field) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return value;
    }

    if (!window.LCNIFormatter) {
      return numeric;
    }

    const canApply = typeof window.LCNIFormatter.shouldApply !== 'function' || window.LCNIFormatter.shouldApply('chart_builder');
    if (!canApply) {
      return numeric;
    }

    if (typeof window.LCNIFormatter.formatByField === 'function') {
      return window.LCNIFormatter.formatByField(numeric, field);
    }

    if (typeof window.LCNIFormatter.formatByColumn === 'function') {
      return window.LCNIFormatter.formatByColumn(numeric, field);
    }

    if (typeof window.LCNIFormatter.format === 'function') {
      return window.LCNIFormatter.format(numeric, 'price');
    }

    return numeric;
  };

  const buildOption = (payload, rows) => {
    const cfg = payload.config || {};
    const xAxis = cfg.xAxis || 'event_time';
    const seriesCfg = Array.isArray(cfg.series) ? cfg.series : [];
    const chartType = payload.chart_type || cfg.template || 'multi_line';
    const labels = payload.series_labels || {};

    const isAreaStack = chartType === 'area_stack';

    const series = seriesCfg.map((item, index) => {
      const hasColor = typeof item.color === 'string' && item.color.trim() !== '';
      const field = item.field || '';
      const displayName = labels[field] || field || item.name || ('Series ' + (index + 1));
      const baseSeries = {
        name: displayName,
        type: item.type || 'line',
        smooth: item.type !== 'bar',
        data: rows.map((r) => Number(r[field] || 0)),
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
      title: { text: payload.name || '' },
      tooltip: {
        trigger: 'axis',
        formatter: (params) => {
          if (!Array.isArray(params) || !params.length) return '';
          const axisLabel = params[0].axisValueLabel || params[0].axisValue || '';
          const lines = [axisLabel];
          params.forEach((item) => {
            const field = (seriesCfg[item.seriesIndex] && seriesCfg[item.seriesIndex].field) || '';
            const formatted = formatValue(item.data, field);
            lines.push(`${item.marker}${item.seriesName}: ${formatted}`);
          });
          return lines.join('<br/>');
        },
      },
      legend: { top: 8, data: series.map((item) => item.name) },
      grid: { left: 40, right: 20, top: 56, bottom: 28 },
      xAxis: { type: 'category', boundaryGap: !isAreaStack, data: rows.map((r) => r[xAxis]) },
      yAxis: {
        type: 'value',
        axisLabel: {
          formatter: (value) => formatValue(value, seriesCfg[0] ? seriesCfg[0].field : ''),
        },
      },
      series,
    };
  };

  const filterRows = (rows, activeFilter) => {
    if (!activeFilter || !activeFilter.field || activeFilter.value === '') {
      return rows;
    }

    return rows.filter((row) => String(row[activeFilter.field] || '') === String(activeFilter.value));
  };

  const renderFilterBar = (node, payload, onChange) => {
    const options = payload.filter_options || {};
    const fields = Array.isArray(payload.filter_fields) ? payload.filter_fields : [];
    if (!fields.length) return;

    const labels = payload.filter_labels || {};
    const bar = document.createElement('div');
    bar.className = 'lcni-chart-builder-filters';
    bar.style.marginBottom = '8px';
    bar.style.display = 'flex';
    bar.style.flexWrap = 'wrap';
    bar.style.gap = '8px';

    fields.forEach((field) => {
      const values = Array.isArray(options[field]) ? options[field] : [];
      if (!values.length) return;

      const label = document.createElement('strong');
      label.textContent = (labels[field] || field) + ':';
      label.style.marginRight = '6px';

      const group = document.createElement('div');
      group.style.display = 'inline-flex';
      group.style.alignItems = 'center';
      group.style.gap = '6px';

      const allBtn = document.createElement('button');
      allBtn.type = 'button';
      allBtn.textContent = 'All';
      allBtn.dataset.field = field;
      allBtn.dataset.value = '';
      allBtn.className = 'button';
      group.appendChild(label);
      group.appendChild(allBtn);

      values.forEach((value) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button';
        btn.dataset.field = field;
        btn.dataset.value = value;
        btn.textContent = value;
        group.appendChild(btn);
      });

      bar.appendChild(group);
    });

    bar.addEventListener('click', (event) => {
      const target = event.target;
      if (!target || !target.dataset || !target.dataset.field) return;
      onChange({ field: target.dataset.field, value: target.dataset.value || '' });
      bar.querySelectorAll('button').forEach((btn) => btn.classList.remove('button-primary'));
      target.classList.add('button-primary');
    });

    node.parentNode.insertBefore(bar, node);
  };

  const boot = () => {
    document.querySelectorAll('[data-lcni-chart-builder]').forEach((node) => {
      if (node.dataset.lcniInit === '1') return;
      node.dataset.lcniInit = '1';

      if (!window.echarts) return;
      const payload = parsePayload(node);
      const allRows = Array.isArray(payload.data) ? payload.data : [];
      const chart = window.echarts.init(node);
      let activeFilter = { field: '', value: '' };

      const rerender = () => {
        const rows = filterRows(allRows, activeFilter);
        chart.setOption(buildOption(payload, rows), true);
      };

      rerender();
      renderFilterBar(node, payload, (nextFilter) => {
        activeFilter = nextFilter;
        rerender();
      });

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

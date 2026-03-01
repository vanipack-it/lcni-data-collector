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

  const normalizeRowsByTemplate = (rows, chartType, config) => {
    if (chartType !== 'share_dataset') {
      return rows;
    }

    const xField = config.xAxis || 'event_time';
    const seriesCfg = Array.isArray(config.series) ? config.series : [];
    if (!xField || !seriesCfg.length) {
      return rows;
    }

    const grouped = {};
    rows.forEach((row) => {
      const category = String(row[xField] || '');
      if (!category) return;
      if (!grouped[category]) grouped[category] = { [xField]: category };

      seriesCfg.forEach((item) => {
        const valueField = item.value_field || item.valueField || item.field || '';
        const keyField = item.key_field || item.keyField || '';
        if (!valueField) return;

        if (keyField && row[keyField]) {
          const keyName = String(row[keyField]);
          grouped[category][keyName] = Number(row[valueField] || 0);
          return;
        }

        const fallbackName = item.name || item.field || valueField;
        grouped[category][fallbackName] = Number(row[valueField] || 0);
      });
    });

    return Object.values(grouped);
  };

  const buildOption = (payload, rows) => {
    const cfg = payload.config || {};
    const chartType = payload.chart_type || cfg.template || 'multi_line';
    const labels = payload.series_labels || {};
    const xAxis = cfg.xAxis || 'event_time';
    const rawSeriesCfg = Array.isArray(cfg.series) ? cfg.series : [];
    const seriesCfg = chartType === 'share_dataset'
      ? rawSeriesCfg.map((item) => {
          const normalizedField = item.field || item.name || item.value_field || item.valueField || '';
          return Object.assign({}, item, { field: normalizedField || item.field || '' });
        })
      : rawSeriesCfg;

    const isAreaStack = chartType === 'area_stack';
    const isShareDataset = chartType === 'share_dataset';
    const preparedRows = normalizeRowsByTemplate(rows, chartType, cfg);

    const series = seriesCfg.map((item, index) => {
      const hasColor = typeof item.color === 'string' && item.color.trim() !== '';
      const field = item.field || '';
      const displayName = labels[field] || field || item.name || ('Series ' + (index + 1));
      const baseSeries = {
        name: displayName,
        type: item.type || 'line',
        smooth: item.type !== 'bar',
        data: preparedRows.map((r) => Number(r[field] || 0)),
      };

      if (isAreaStack) {
        baseSeries.type = 'line';
        baseSeries.stack = item.stack ? 'Total' : undefined;
        baseSeries.areaStyle = item.area ? {} : undefined;
        baseSeries.emphasis = { focus: 'series' };
        baseSeries.label = item.label_show ? { show: true, position: 'bottom' } : undefined;
      }

      if (isShareDataset) {
        baseSeries.type = 'line';
        baseSeries.smooth = true;
        baseSeries.emphasis = { focus: 'series' };
        if (item.line_style === 'dashed') {
          baseSeries.lineStyle = Object.assign({}, baseSeries.lineStyle || {}, { type: 'dashed' });
        }
      }

      if (hasColor) {
        baseSeries.color = item.color;
        if (isAreaStack || isShareDataset) {
          baseSeries.lineStyle = Object.assign({}, baseSeries.lineStyle || {}, { color: item.color });
          baseSeries.itemStyle = { color: item.color };
        }
      }

      if (index === seriesCfg.length - 1 && isAreaStack && !baseSeries.label) {
        baseSeries.label = { show: true, position: 'bottom' };
      }

      return baseSeries;
    });

    const axisData = preparedRows.map((r) => r[xAxis]);
    const pieField = seriesCfg[0] ? (seriesCfg[0].field || seriesCfg[0].name || '') : '';

    return {
      title: { text: payload.name || '' },
      tooltip: {
        trigger: 'axis',
        showContent: !isShareDataset,
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
      legend: { top: 'bottom', data: series.map((item) => item.name) },
      grid: isShareDataset ? { left: 40, right: 20, top: 90, bottom: 70 } : { left: 40, right: 20, top: 56, bottom: 64 },
      dataset: isShareDataset ? {
        source: [
          [xAxis].concat(series.map((item) => item.name)),
          ...preparedRows.map((row) => [row[xAxis]].concat(series.map((item) => Number(row[item.name] || row[item.field] || 0)))),
        ],
      } : undefined,
      xAxis: { type: 'category', boundaryGap: !isAreaStack, data: axisData },
      yAxis: {
        type: 'value',
        axisLabel: {
          formatter: (value) => formatValue(value, seriesCfg[0] ? seriesCfg[0].field : ''),
        },
      },
      series: isShareDataset
        ? series.concat([
            {
              type: 'pie',
              id: 'pie',
              radius: '28%',
              center: ['50%', '26%'],
              emphasis: { focus: 'self' },
              label: { formatter: pieField ? `{b}: {@${pieField}} ({d}%)` : '{b}: {d}%' },
              encode: pieField ? { itemName: xAxis, value: pieField, tooltip: pieField } : {},
            },
          ])
        : series,
      animationDurationUpdate: 300,
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
      allBtn.className = 'button button-primary';
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
        const option = buildOption(payload, rows);
        chart.setOption(option, true);

        if ((payload.chart_type || (payload.config || {}).template) === 'share_dataset') {
          chart.off('updateAxisPointer');
          chart.on('updateAxisPointer', (event) => {
            const xAxisInfo = event && event.axesInfo && event.axesInfo[0];
            if (!xAxisInfo) return;
            const dimIndex = Number(xAxisInfo.value) + 1;
            const datasetSource = option.dataset && Array.isArray(option.dataset.source) ? option.dataset.source : [];
            const header = datasetSource[0] || [];
            const dimension = header[dimIndex] || header[1] || '';
            if (!dimension) return;

            chart.setOption({
              series: {
                id: 'pie',
                label: {
                  formatter: '{b}: {@[' + dimension + ']} ({d}%)',
                },
                encode: {
                  value: dimension,
                  tooltip: dimension,
                },
              },
            });
          });
        }
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

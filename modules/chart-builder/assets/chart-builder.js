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
    const isHeatmapMatrix = chartType === 'heatmap_matrix';
    const isHeatmapMatrix2 = chartType === 'heatmap_matrix_2';
    const isMiniLineSparkline = chartType === 'mini_line_sparkline';
    const isTreemap1 = chartType === 'treemap_1';
    const preparedRows = normalizeRowsByTemplate(rows, chartType, cfg);

    if (isMiniLineSparkline) {
      const xDimensionField = cfg.xAxis || '';
      const yDimensionField = cfg.yAxis || '';
      const valueField = (seriesCfg[0] && seriesCfg[0].field) || '';
      const timeField = cfg.timeAxis || 'event_time';
      const xDimensionData = [];
      const yDimensionData = [];
      const xSet = new Set();
      const ySet = new Set();
      const cells = {};

      preparedRows.forEach((row, rowIndex) => {
        const xValue = String(row[xDimensionField] || '').trim();
        const yValue = String(row[yDimensionField] || '').trim();
        const numericValue = Number(row[valueField]);
        if (!xValue || !yValue || !Number.isFinite(numericValue)) {
          return;
        }

        if (!xSet.has(xValue)) {
          xSet.add(xValue);
          xDimensionData.push(xValue);
        }
        if (!ySet.has(yValue)) {
          ySet.add(yValue);
          yDimensionData.push(yValue);
        }

        const id = `${xValue}|${yValue}`;
        if (!cells[id]) {
          cells[id] = [];
        }

        const timeValue = row[timeField] || row.event_time || row.date || rowIndex;
        cells[id].push([String(timeValue), numericValue]);
      });

      const matrix = {
        x: {
          data: xDimensionData,
          levelSize: 42,
          label: { fontSize: 13, color: '#555' },
        },
        y: {
          data: yDimensionData.map((item) => ({ value: item })),
          levelSize: 62,
          label: { fontSize: 12, color: '#777' },
        },
        corner: {
          data: [{ coord: [-1, -1], value: 'Nhóm / Mã' }],
          label: { fontSize: 12, color: '#777' },
        },
        top: 24,
        bottom: 80,
        width: '92%',
        left: 'center',
      };

      const option = {
        title: { text: payload.name || '' },
        matrix,
        tooltip: { trigger: 'axis' },
        dataZoom: [
          { type: 'slider', xAxisIndex: 'all', left: '10%', right: '10%', bottom: 26, height: 24, throttle: 120 },
          { type: 'inside', xAxisIndex: 'all', throttle: 120 },
        ],
        grid: [],
        xAxis: [],
        yAxis: [],
        series: [],
        animationDurationUpdate: 300,
      };

      yDimensionData.forEach((yValue, yidx) => {
        xDimensionData.forEach((xValue, xidx) => {
          const id = `${xidx}|${yidx}`;
          const cellKey = `${xValue}|${yValue}`;
          const cellSeries = cells[cellKey] || [];
          if (!cellSeries.length) {
            return;
          }

          option.grid.push({
            id,
            coordinateSystem: 'matrix',
            coord: [xValue, yValue],
            top: 8,
            bottom: 8,
            left: 'center',
            width: '92%',
            containLabel: true,
          });

          option.xAxis.push({
            type: 'category',
            id,
            gridId: id,
            axisTick: { show: false },
            axisLabel: { show: false },
            axisLine: { show: false },
            splitLine: { show: false },
            data: cellSeries.map((item) => item[0]),
          });

          option.yAxis.push({
            id,
            gridId: id,
            scale: true,
            axisLabel: { show: false },
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { show: false },
          });

          option.series.push({
            name: `${yValue} - ${xValue}`,
            xAxisId: id,
            yAxisId: id,
            type: 'line',
            smooth: true,
            symbol: 'none',
            lineStyle: {
              lineWidth: 1.2,
              type: (seriesCfg[0] && seriesCfg[0].line_style) || 'solid',
              color: (seriesCfg[0] && seriesCfg[0].color) || '#5470c6',
            },
            itemStyle: { color: (seriesCfg[0] && seriesCfg[0].color) || '#5470c6' },
            data: cellSeries.map((item) => item[1]),
          });
        });
      });

      return option;
    }

    if (isHeatmapMatrix || isHeatmapMatrix2) {
      const xData = Array.isArray(rows.x) ? rows.x : [];
      const yData = Array.isArray(rows.y) ? rows.y : [];
      const matrixData = Array.isArray(rows.data) ? rows.data : [];
      const heatmapCfg = cfg.heatmap || {};
      const heatmapColors = [
        heatmapCfg.low || '#d73027',
        heatmapCfg.mid || '#fee08b',
        heatmapCfg.high || '#1a9850',
      ];
      const maxValue = matrixData.reduce((acc, item) => {
        const value = Number(Array.isArray(item) ? item[2] : 0);
        return Number.isFinite(value) ? Math.max(acc, value) : acc;
      }, 0);

      return {
        title: { text: payload.name || '' },
        tooltip: {
          position: 'top',
          formatter: (params) => {
            const xLabel = xData[params.data[0]] || '';
            const yLabel = yData[params.data[1]] || '';
            return yLabel + '<br/>' + xLabel + ': ' + formatValue(params.data[2], 'percent');
          },
        },
        grid: { height: '72%', top: '10%' },
        xAxis: { type: 'category', data: xData, splitArea: { show: true } },
        yAxis: { type: 'category', data: yData, splitArea: { show: true } },
        dataZoom: [
          { type: 'slider', xAxisIndex: 0, bottom: 52, height: 16 },
          { type: 'inside', xAxisIndex: 0 },
        ],
        visualMap: {
          min: 0,
          max: maxValue > 0 ? maxValue : 30,
          calculable: true,
          orient: 'horizontal',
          left: 'center',
          bottom: '2%',
          inRange: { color: heatmapColors },
        },
        series: [{
          name: '%GTGD',
          type: 'heatmap',
          data: matrixData,
          label: { show: true, formatter: (item) => formatValue(item.value && item.value[2], 'percent') },
          emphasis: {
            itemStyle: {
              shadowBlur: 10,
              shadowColor: 'rgba(0,0,0,0.5)',
            },
          },
        }],
        animationDurationUpdate: 300,
      };
    }

    if (isTreemap1) {
      const parentField = cfg.xAxis || '';
      const childField = cfg.yAxis || '';
      const valueField = (seriesCfg[0] && seriesCfg[0].field) || '';
      const grouped = {};

      preparedRows.forEach((row) => {
        const parent = String(row[parentField] || 'N/A');
        const child = childField ? String(row[childField] || 'N/A') : parent;
        const value = Number(row[valueField] || 0);
        if (!grouped[parent]) grouped[parent] = {};
        grouped[parent][child] = (grouped[parent][child] || 0) + value;
      });

      const treeData = Object.keys(grouped).map((parent) => ({
        name: parent,
        children: Object.keys(grouped[parent]).map((child) => ({
          name: child,
          value: grouped[parent][child],
        })),
      }));

      return {
        title: { text: payload.name || '' },
        tooltip: {
          formatter: (info) => `${info.name}: ${formatValue(info.value, valueField)}`,
        },
        series: [{
          type: 'treemap',
          roam: false,
          leafDepth: 1,
          breadcrumb: { show: true },
          label: { show: true, formatter: '{b}' },
          upperLabel: { show: true, height: 24 },
          data: treeData,
        }],
      };
    }


    const series = seriesCfg.map((item, index) => {
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
      }

      if (item.line_style === 'dashed') {
        baseSeries.lineStyle = Object.assign({}, baseSeries.lineStyle || {}, { type: 'dashed' });
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
          ...preparedRows.map((row) => [row[xAxis]].concat(series.map((item) => Number(row[item.field] || row[item.name] || 0)))),
        ],
      } : undefined,
      xAxis: { type: 'category', boundaryGap: !isAreaStack, data: axisData },
      dataZoom: axisData.length > 20 ? [{ type: 'slider', xAxisIndex: 0, bottom: 28, height: 14 }, { type: 'inside', xAxisIndex: 0 }] : undefined,
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

  const normalizeFilterValue = (value) => String(value == null ? '' : value).trim();

  const filterRows = (rows, activeFilters) => {
    if (!Array.isArray(rows)) {
      return rows;
    }

    const entries = Object.entries(activeFilters || {}).filter(([, values]) => Array.isArray(values) && values.length);
    if (!entries.length) {
      return rows;
    }

    return rows.filter((row) => entries.every(([field, values]) => {
      const current = normalizeFilterValue(row[field]);
      return values.some((value) => normalizeFilterValue(value) === current);
    }));
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

      const allBtn = document.createElement('label');
      allBtn.style.display = 'inline-flex';
      allBtn.style.alignItems = 'center';
      allBtn.style.gap = '4px';
      const allInput = document.createElement('input');
      allInput.type = 'checkbox';
      allInput.checked = true;
      allInput.dataset.field = field;
      allInput.dataset.value = '__all__';
      const allText = document.createElement('span');
      allText.textContent = 'All';
      allBtn.appendChild(allInput);
      allBtn.appendChild(allText);
      group.appendChild(label);
      group.appendChild(allBtn);

      values.forEach((value) => {
        const itemLabel = document.createElement('label');
        itemLabel.style.display = 'inline-flex';
        itemLabel.style.alignItems = 'center';
        itemLabel.style.gap = '4px';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.field = field;
        input.dataset.value = value;
        const text = document.createElement('span');
        text.textContent = value;
        itemLabel.appendChild(input);
        itemLabel.appendChild(text);
        group.appendChild(itemLabel);
      });

      bar.appendChild(group);
    });

    bar.addEventListener('change', (event) => {
      const target = event.target;
      if (!target || !target.dataset || !target.dataset.field) return;

      const field = target.dataset.field;
      const allInput = bar.querySelector('input[data-field="' + field + '"][data-value="__all__"]');
      const valueInputs = Array.from(bar.querySelectorAll('input[data-field="' + field + '"]:not([data-value="__all__"])'));
      if (target.dataset.value === '__all__') {
        valueInputs.forEach((input) => {
          input.checked = false;
        });
        target.checked = true;
      } else {
        if (target.checked && allInput) {
          allInput.checked = false;
        }
        const checkedValues = valueInputs.filter((input) => input.checked);
        if (!checkedValues.length && allInput) {
          allInput.checked = true;
        }
      }

      const nextFilters = {};
      fields.forEach((filterField) => {
        const all = bar.querySelector('input[data-field="' + filterField + '"][data-value="__all__"]');
        if (all && all.checked) {
          return;
        }
        const checkedValues = Array.from(bar.querySelectorAll('input[data-field="' + filterField + '"]:not([data-value="__all__"])'))
          .filter((input) => input.checked)
          .map((input) => input.dataset.value || '');
        if (checkedValues.length) {
          nextFilters[filterField] = checkedValues;
        }
      });

      onChange(nextFilters);
    });

    node.parentNode.insertBefore(bar, node);
  };

  const boot = () => {
    document.querySelectorAll('[data-lcni-chart-builder]').forEach((node) => {
      if (node.dataset.lcniInit === '1') return;
      node.dataset.lcniInit = '1';

      if (!window.echarts) return;
      const payload = parsePayload(node);
      const allRows = payload.data || [];
      const chart = window.echarts.init(node);
      let activeFilters = {};

      const rerender = () => {
        const rows = filterRows(allRows, activeFilters);
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
      renderFilterBar(node, payload, (nextFilters) => {
        activeFilters = nextFilters;
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

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

  const parseNumericValue = (value) => {
    if (typeof value === 'number') {
      return Number.isFinite(value) ? value : null;
    }

    if (typeof value === 'string') {
      const cleaned = value.replace(/[%\s,]/g, '');
      if (cleaned === '') {
        return null;
      }
      const parsed = Number(cleaned);
      return Number.isFinite(parsed) ? parsed : null;
    }

    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  };

  const normalizeHeatmapRows = (rows, cfg, seriesCfg) => {
    const valueField = (seriesCfg[0] && (seriesCfg[0].field || seriesCfg[0].value_field || seriesCfg[0].valueField)) || '';
    const xField = cfg.xAxis || 'event_time';
    const yField = cfg.yAxis || 'icb2';

    if (rows && !Array.isArray(rows) && Array.isArray(rows.x) && Array.isArray(rows.y) && Array.isArray(rows.data)) {
      const normalizedMatrix = rows.data
        .map((item) => {
          if (!Array.isArray(item) || item.length < 3) return null;
          const xIndex = Number(item[0]);
          const yIndex = Number(item[1]);
          const value = parseNumericValue(item[2]);
          if (!Number.isInteger(xIndex) || !Number.isInteger(yIndex) || value === null) {
            return null;
          }
          return [xIndex, yIndex, value];
        })
        .filter(Boolean);

      return {
        xData: rows.x,
        yData: rows.y,
        matrixData: normalizedMatrix,
        valueField,
      };
    }

    const xValues = [];
    const yValues = [];
    const xMap = new Map();
    const yMap = new Map();
    const matrixData = [];

    (Array.isArray(rows) ? rows : []).forEach((row) => {
      const xValue = String(row[xField] || '').trim();
      const yValue = String(row[yField] || '').trim();
      const value = parseNumericValue(valueField ? row[valueField] : null);
      if (!xValue || !yValue || value === null) {
        return;
      }

      if (!xMap.has(xValue)) {
        xMap.set(xValue, xValues.length);
        xValues.push(xValue);
      }
      if (!yMap.has(yValue)) {
        yMap.set(yValue, yValues.length);
        yValues.push(yValue);
      }

      matrixData.push([xMap.get(xValue), yMap.get(yValue), value]);
    });

    return { xData: xValues, yData: yValues, matrixData, valueField };
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
      const xField = cfg.xAxis || 'event_time';
      const miniChartField = cfg.yAxis || '';
      const miniChartMap = new Map();
      const xAxisValues = [];
      const xSet = new Set();

      preparedRows.forEach((row) => {
        const xValue = String(row[xField] || '').trim();
        const chartKey = miniChartField ? String(row[miniChartField] || '').trim() : 'Mini Chart';
        if (!xValue || !chartKey) {
          return;
        }

        if (!xSet.has(xValue)) {
          xSet.add(xValue);
          xAxisValues.push(xValue);
        }

        if (!miniChartMap.has(chartKey)) {
          miniChartMap.set(chartKey, {});
        }
        miniChartMap.get(chartKey)[xValue] = row;
      });

      const miniChartKeys = Array.from(miniChartMap.keys());
      const miniSeriesCfg = seriesCfg.filter((item) => item && item.field);
      if (!miniChartKeys.length || !xAxisValues.length || !miniSeriesCfg.length) {
        return { title: { text: payload.name || '' }, series: [] };
      }

      const chartPerRow = 4;
      const columnGap = 3;
      const rowGap = 6;
      const rowCount = Math.ceil(miniChartKeys.length / chartPerRow);
      const topArea = payload.name ? 12 : 4;
      const bottomArea = 16;
      const availableHeight = 100 - topArea - bottomArea - rowGap * Math.max(0, rowCount - 1);
      const availableWidth = 100 - columnGap * Math.max(0, chartPerRow - 1);
      const cellHeight = availableHeight / Math.max(1, rowCount);
      const cellWidth = availableWidth / chartPerRow;

      const option = {
        title: { text: payload.name || '' },
        tooltip: {
          trigger: 'axis',
          formatter: (params) => {
            if (!Array.isArray(params) || !params.length) return '';
            const axisLabel = params[0].axisValueLabel || params[0].axisValue || '';
            const lines = [axisLabel, params[0].seriesName.split(' · ')[0]];
            params.forEach((item) => {
              const label = item.seriesName.split(' · ').slice(1).join(' · ') || item.seriesName;
              lines.push(`${item.marker}${label}: ${formatValue(item.data, item.seriesField || '')}`);
            });
            return lines.join('<br/>');
          },
        },
        grid: [],
        xAxis: [],
        yAxis: [],
        series: [],
        graphic: [],
        animationDurationUpdate: 300,
      };

      miniChartKeys.forEach((chartKey, chartIndex) => {
        const col = chartIndex % chartPerRow;
        const row = Math.floor(chartIndex / chartPerRow);
        const gridId = `mini-${chartIndex}`;
        const left = col * (cellWidth + columnGap);
        const top = topArea + row * (cellHeight + rowGap);
        const isBottomRow = row === rowCount - 1;

        option.grid.push({
          id: gridId,
          left: `${left}%`,
          top: `${top}%`,
          width: `${cellWidth}%`,
          height: `${cellHeight}%`,
          containLabel: false,
        });

        option.xAxis.push({
          type: 'category',
          id: gridId,
          gridId,
          data: xAxisValues,
          axisTick: { show: false },
          axisLine: { show: isBottomRow },
          axisLabel: { show: isBottomRow, fontSize: 10 },
          splitLine: { show: false },
        });

        option.yAxis.push({
          id: gridId,
          gridId,
          type: 'value',
          scale: true,
          axisLabel: { show: false },
          axisLine: { show: false },
          axisTick: { show: false },
          splitLine: { show: false },
        });

        option.graphic.push({
          type: 'text',
          left: `${left + 0.4}%`,
          top: `${top - 1.8}%`,
          style: {
            text: chartKey,
            fontSize: 11,
            fontWeight: 600,
            fill: '#4b5563',
          },
        });

        miniSeriesCfg.forEach((seriesItem, seriesIndex) => {
          const field = seriesItem.field || '';
          const lineColor = seriesItem.color || ['#5470c6', '#91cc75', '#ee6666'][seriesIndex % 3];
          const data = xAxisValues.map((axisValue) => {
            const rowData = miniChartMap.get(chartKey)[axisValue] || null;
            const numeric = rowData ? Number(rowData[field]) : null;
            return Number.isFinite(numeric) ? numeric : null;
          });

          option.series.push({
            name: `${chartKey} · ${labels[field] || seriesItem.name || field || `Series ${seriesIndex + 1}`}`,
            seriesField: field,
            xAxisId: gridId,
            yAxisId: gridId,
            type: 'line',
            smooth: true,
            symbol: 'none',
            connectNulls: true,
            lineStyle: {
              lineWidth: 1.2,
              type: seriesItem.line_style || 'solid',
              color: lineColor,
            },
            itemStyle: { color: lineColor },
            data,
          });
        });
      });

      return option;
    }

    if (isHeatmapMatrix || isHeatmapMatrix2) {
      const { xData, yData, matrixData, valueField } = normalizeHeatmapRows(rows, cfg, seriesCfg);
      const heatmapCfg = cfg.heatmap || {};
      const heatmapColors = [
        heatmapCfg.low || '#d73027',
        heatmapCfg.mid || '#fee08b',
        heatmapCfg.high || '#1a9850',
      ];
      const values = matrixData.map((item) => item[2]).filter((value) => Number.isFinite(value));
      const minValue = values.length ? Math.min(...values) : 0;
      const maxValue = values.length ? Math.max(...values) : 0;
      const visualMin = minValue;
      const visualMax = maxValue > minValue ? maxValue : minValue + 1;

      return {
        title: { text: payload.name || '' },
        tooltip: {
          position: 'top',
          formatter: (params) => {
            const xLabel = xData[params.data[0]] || '';
            const yLabel = yData[params.data[1]] || '';
            return yLabel + '<br/>' + xLabel + ': ' + formatValue(params.data[2], valueField || 'percent');
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
          min: visualMin,
          max: visualMax,
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
          label: { show: true, formatter: (item) => formatValue(item.value && item.value[2], valueField || 'percent') },
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

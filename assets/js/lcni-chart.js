document.addEventListener("DOMContentLoaded", async () => {
  const containers = document.querySelectorAll("[data-lcni-chart]");
  if (!containers.length) {
    return;
  }

  if (typeof LightweightCharts === "undefined") {
    containers.forEach((container) => {
      container.textContent = "NO DATA";
    });
    console.error("LCNI: LightweightCharts library is missing");
    return;
  }

  const stockSyncUtils = window.LCNIStockSyncUtils || null;
  const sanitizeSymbol = stockSyncUtils
    ? stockSyncUtils.sanitizeSymbol
    : (value) => {
      const symbol = String(value || "").toUpperCase().trim();
      return /^[A-Z0-9._-]{1,15}$/.test(symbol) ? symbol : "";
    };

  const parseAdminConfig = (rawConfig) => {
    if (!rawConfig) {
      return {};
    }

    try {
      const parsed = JSON.parse(rawConfig);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  };

  const stockSync = stockSyncUtils
    ? stockSyncUtils.createStockSync()
    : {
      subscribe() {},
      setSymbol() {},
      getCurrentSymbol() { return ""; },
      getHistory() { return []; },
      configureQueryParam() {}
    };

  const renderChart = async (container) => {
    const apiBase = container.dataset.apiBase;
    const limit = Number(container.dataset.limit || 200);
    const queryParam = container.dataset.queryParam;
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const fallbackSymbol = sanitizeSymbol(container.dataset.fallbackSymbol);
    const adminConfig = parseAdminConfig(container.dataset.adminConfig);

    const allPanels = ["volume", "macd", "rsi", "rs"];
    const allowedPanels = Array.isArray(adminConfig.allowed_panels) && adminConfig.allowed_panels.length
      ? adminConfig.allowed_panels.filter((panel) => allPanels.includes(panel))
      : allPanels;
    const compactMode = adminConfig.compact_mode !== false;

    stockSync.configureQueryParam(queryParam || "symbol");

    const resolveSymbol = () => {
      if (fixedSymbol) {
        return fixedSymbol;
      }

      const query = new URLSearchParams(window.location.search);
      const symbolFromQuery = queryParam ? sanitizeSymbol(query.get(queryParam)) : "";
      return stockSync.getCurrentSymbol() || symbolFromQuery || fallbackSymbol;
    };

    const buildShell = (symbol) => {
      container.innerHTML = "";

      const root = document.createElement("div");
      root.style.border = "1px solid #e5e7eb";
      root.style.borderRadius = "8px";
      root.style.padding = "10px";
      root.style.background = "#fff";

      const mainWrapHolder = document.createElement("div");
      mainWrapHolder.style.position = "relative";

      const controls = document.createElement("div");
      controls.style.display = "flex";
      controls.style.flexWrap = "wrap";
      controls.style.gap = "8px";
      controls.style.alignItems = "center";
      controls.style.padding = "6px 8px";
      controls.style.border = "1px solid rgba(229, 231, 235, 0.95)";
      controls.style.borderRadius = "6px";
      controls.style.background = "rgba(255,255,255,0.94)";
      controls.style.zIndex = "5";
      controls.style.fontSize = "12px";

      if (compactMode) {
        controls.style.position = "absolute";
        controls.style.top = "8px";
        controls.style.left = "8px";
      } else {
        controls.style.position = "relative";
        controls.style.marginBottom = "10px";
      }

      const title = document.createElement("strong");
      title.textContent = symbol;
      controls.appendChild(title);

      const mainChartWrap = document.createElement("div");
      const mainHeight = Number(container.dataset.mainHeight || 420);
      mainChartWrap.style.height = `${Number.isFinite(mainHeight) ? mainHeight : 420}px`;

      mainWrapHolder.appendChild(mainChartWrap);
      mainWrapHolder.appendChild(controls);

      const panelWrap = (height = 160) => {
        const wrap = document.createElement("div");
        wrap.style.height = `${height}px`;
        wrap.style.marginTop = "8px";
        return wrap;
      };

      const volumeWrap = panelWrap(150);
      const macdWrap = panelWrap(170);
      const rsiWrap = panelWrap(150);
      const rsWrap = panelWrap(170);

      root.appendChild(mainWrapHolder);
      root.appendChild(volumeWrap);
      root.appendChild(macdWrap);
      root.appendChild(rsiWrap);
      root.appendChild(rsWrap);
      container.appendChild(root);

      return { controls, mainChartWrap, volumeWrap, macdWrap, rsiWrap, rsWrap };
    };

    const createCheckbox = (labelText, checked, onChange) => {
      const label = document.createElement("label");
      label.style.display = "inline-flex";
      label.style.alignItems = "center";
      label.style.gap = "4px";
      label.style.cursor = "pointer";

      const input = document.createElement("input");
      input.type = "checkbox";
      input.checked = checked;
      input.addEventListener("change", () => onChange(input.checked));

      const text = document.createElement("span");
      text.textContent = labelText;

      label.appendChild(input);
      label.appendChild(text);

      return label;
    };

    const seriesDataFilter = (candles, key) => candles
      .filter((item) => typeof item[key] === "number" && Number.isFinite(item[key]))
      .map((item) => ({ time: item.time, value: item[key] }));

    const fetchAndRender = async (symbol) => {
      if (!apiBase || !symbol) {
        container.textContent = "NO DATA";
        return;
      }

      const apiUrl = `${apiBase}?symbol=${encodeURIComponent(symbol)}&limit=${Number.isFinite(limit) ? limit : 200}`;

      try {
        const response = await fetch(apiUrl, { credentials: "same-origin" });
        if (!response.ok) {
          throw new Error(`LCNI: request failed (${response.status})`);
        }

        const payload = await response.json();
        const candles = Array.isArray(payload) ? payload : payload?.candles;
        if (!Array.isArray(candles) || !candles.length) {
          container.textContent = "NO DATA";
          return;
        }

        const { controls, mainChartWrap, volumeWrap, macdWrap, rsiWrap, rsWrap } = buildShell(symbol);

        const commonOptions = {
          autoSize: true,
          layout: { background: { color: "#fff" }, textColor: "#333" },
          grid: { vertLines: { color: "#efefef" }, horzLines: { color: "#efefef" } },
          rightPriceScale: { borderColor: "#e5e7eb" }
        };

        const mainChart = LightweightCharts.createChart(mainChartWrap, commonOptions);
        const volumeChart = LightweightCharts.createChart(volumeWrap, commonOptions);
        const macdChart = LightweightCharts.createChart(macdWrap, commonOptions);
        const rsiChart = LightweightCharts.createChart(rsiWrap, commonOptions);
        const rsChart = LightweightCharts.createChart(rsWrap, commonOptions);

        const candleSeries = mainChart.addCandlestickSeries();
        const lineSeries = mainChart.addLineSeries({ color: "#2563eb", lineWidth: 2 });

        candleSeries.setData(candles);
        lineSeries.setData(candles.map((item) => ({ time: item.time, value: item.close })));

        volumeChart.addHistogramSeries({ priceFormat: { type: "volume" }, priceScaleId: "" }).setData(candles.map((item) => ({
          time: item.time,
          value: typeof item.volume === "number" ? item.volume : 0,
          color: item.close >= item.open ? "#16a34a" : "#dc2626"
        })));

        macdChart.addLineSeries({ color: "#1d4ed8", lineWidth: 2 }).setData(seriesDataFilter(candles, "macd"));
        macdChart.addLineSeries({ color: "#f59e0b", lineWidth: 2 }).setData(seriesDataFilter(candles, "macd_signal"));
        macdChart.addHistogramSeries({ priceScaleId: "", base: 0 }).setData(candles
          .filter((item) => typeof item.macd_histogram === "number" && Number.isFinite(item.macd_histogram))
          .map((item) => ({ time: item.time, value: item.macd_histogram, color: item.macd_histogram >= 0 ? "rgba(22,163,74,0.55)" : "rgba(220,38,38,0.55)" })));

        const rsiData = seriesDataFilter(candles, "rsi");
        rsiChart.addLineSeries({ color: "#7c3aed", lineWidth: 2 }).setData(rsiData);
        rsiChart.addLineSeries({ color: "#f97316", lineStyle: 2, lineWidth: 1 }).setData(candles.map((item) => ({ time: item.time, value: 70 })));
        rsiChart.addLineSeries({ color: "#0ea5e9", lineStyle: 2, lineWidth: 1 }).setData(candles.map((item) => ({ time: item.time, value: 30 })));

        const rs1wData = seriesDataFilter(candles, "rs_1w_by_exchange");
        const rs1mData = seriesDataFilter(candles, "rs_1m_by_exchange");
        const rs3mData = seriesDataFilter(candles, "rs_3m_by_exchange");

        rsChart.addLineSeries({ color: "#0ea5e9", lineWidth: 2 }).setData(rs1wData);
        rsChart.addLineSeries({ color: "#f59e0b", lineWidth: 2 }).setData(rs1mData);
        rsChart.addLineSeries({ color: "#ef4444", lineWidth: 2 }).setData(rs3mData);

        let chartMode = adminConfig.default_mode === "candlestick" ? "candlestick" : "line";
        const modeSelect = document.createElement("select");
        [{ value: "line", label: "Line" }, { value: "candlestick", label: "Candlestick" }].forEach((mode) => {
          const option = document.createElement("option");
          option.value = mode.value;
          option.textContent = mode.label;
          if (mode.value === chartMode) {
            option.selected = true;
          }
          modeSelect.appendChild(option);
        });

        const syncMode = () => {
          candleSeries.applyOptions({ visible: chartMode === "candlestick" });
          lineSeries.applyOptions({ visible: chartMode === "line" });
        };

        modeSelect.addEventListener("change", () => {
          chartMode = modeSelect.value;
          syncMode();
        });

        const chartModeWrap = document.createElement("label");
        chartModeWrap.style.display = "inline-flex";
        chartModeWrap.style.gap = "4px";
        chartModeWrap.style.alignItems = "center";
        chartModeWrap.appendChild(Object.assign(document.createElement("span"), { textContent: "Kiểu" }));
        chartModeWrap.appendChild(modeSelect);
        controls.appendChild(chartModeWrap);

        const panels = {
          volume: { label: "Volume", wrap: volumeWrap, chart: volumeChart },
          macd: { label: "MACD", wrap: macdWrap, chart: macdChart },
          rsi: { label: "RSI", wrap: rsiWrap, chart: rsiChart },
          rs: { label: "RS", wrap: rsWrap, chart: rsChart }
        };

        const panelVisibility = {
          volume: allowedPanels.includes("volume"),
          macd: allowedPanels.includes("macd"),
          rsi: allowedPanels.includes("rsi") && !!rsiData.length,
          rs: allowedPanels.includes("rs") && (!!rs1wData.length || !!rs1mData.length || !!rs3mData.length)
        };

        Object.keys(panels).forEach((key) => {
          panels[key].wrap.style.display = panelVisibility[key] ? "block" : "none";
          if (!allowedPanels.includes(key)) {
            return;
          }
          controls.appendChild(createCheckbox(panels[key].label, panelVisibility[key], (checked) => {
            panelVisibility[key] = checked;
            panels[key].wrap.style.display = checked ? "block" : "none";
            applySharedTimeAxis();
          }));
        });

        const charts = [mainChart, volumeChart, macdChart, rsiChart, rsChart];

        const syncTimeScale = (sourceChart, targetCharts) => {
          sourceChart.timeScale().subscribeVisibleLogicalRangeChange((range) => {
            if (!range) {
              return;
            }
            targetCharts.forEach((chart) => chart.timeScale().setVisibleLogicalRange(range));
          });
        };

        syncTimeScale(mainChart, [volumeChart, macdChart, rsiChart, rsChart]);
        syncTimeScale(volumeChart, [mainChart, macdChart, rsiChart, rsChart]);
        syncTimeScale(macdChart, [mainChart, volumeChart, rsiChart, rsChart]);
        syncTimeScale(rsiChart, [mainChart, volumeChart, macdChart, rsChart]);
        syncTimeScale(rsChart, [mainChart, volumeChart, macdChart, rsiChart]);

        const applySharedTimeAxis = () => {
          const activePanels = ["volume", "macd", "rsi", "rs"].filter((key) => panelVisibility[key]);
          const lastPanel = activePanels.length ? activePanels[activePanels.length - 1] : null;

          mainChart.applyOptions({ timeScale: { visible: activePanels.length === 0 } });
          Object.keys(panels).forEach((key) => {
            panels[key].chart.applyOptions({ timeScale: { visible: key === lastPanel } });
          });
        };

        syncMode();
        applySharedTimeAxis();
        charts.forEach((chart) => chart.timeScale().fitContent());
      } catch (error) {
        console.error(error);
        container.textContent = "NO DATA";
      }
    };

    const initialSymbol = resolveSymbol();
    await fetchAndRender(initialSymbol);

    stockSync.subscribe(async (nextSymbol) => {
      if (fixedSymbol || !nextSymbol) {
        return;
      }
      await fetchAndRender(nextSymbol);
    });
  };

  document.addEventListener("click", (event) => {
    const link = event.target.closest("[data-lcni-symbol-link]");
    if (!link) {
      return;
    }

    const symbol = sanitizeSymbol(link.dataset.lcniSymbolLink || link.dataset.symbol || "");
    if (!symbol) {
      return;
    }

    event.preventDefault();
    stockSync.setSymbol(symbol, { source: "link", pushState: true });
  });

  await Promise.all(Array.from(containers).map((container) => renderChart(container)));
});

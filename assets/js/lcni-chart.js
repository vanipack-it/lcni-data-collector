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
    const initialSymbol = sanitizeSymbol(container.dataset.initialSymbol);

    let initialCandles = [];
    if (container.dataset.initialCandles) {
      try {
        const parsedInitialCandles = JSON.parse(container.dataset.initialCandles);
        initialCandles = Array.isArray(parsedInitialCandles) ? parsedInitialCandles : [];
      } catch (error) {
        initialCandles = [];
      }
    }

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

      const controls = document.createElement("div");
      controls.style.display = "flex";
      controls.style.flexWrap = "wrap";
      controls.style.gap = "12px";
      controls.style.marginBottom = "12px";
      controls.style.alignItems = "center";

      const title = document.createElement("strong");
      title.textContent = symbol;
      controls.appendChild(title);

      const mainChartWrap = document.createElement("div");
      const mainHeight = Number(container.dataset.mainHeight || 420);
      mainChartWrap.style.height = `${Number.isFinite(mainHeight) ? mainHeight : 420}px`;

      const panelWrap = () => {
        const wrap = document.createElement("div");
        wrap.style.height = "160px";
        wrap.style.marginTop = "8px";
        return wrap;
      };

      const volumeWrap = panelWrap();
      const macdWrap = panelWrap();
      macdWrap.style.height = "180px";
      const rsiWrap = panelWrap();
      const rsWrap = panelWrap();
      rsWrap.style.height = "180px";

      container.appendChild(controls);
      container.appendChild(mainChartWrap);
      container.appendChild(volumeWrap);
      container.appendChild(macdWrap);
      container.appendChild(rsiWrap);
      container.appendChild(rsWrap);

      return { controls, mainChartWrap, volumeWrap, macdWrap, rsiWrap, rsWrap };
    };

    const createCheckbox = (labelText, checked, onChange) => {
      const label = document.createElement("label");
      label.style.display = "inline-flex";
      label.style.alignItems = "center";
      label.style.gap = "6px";
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

    const fetchAndRender = async (symbol, seededCandles = null) => {
      if (!apiBase || !symbol) {
        container.textContent = "NO DATA";
        return;
      }

      const apiUrl = `${apiBase}?symbol=${encodeURIComponent(symbol)}&limit=${Number.isFinite(limit) ? limit : 200}`;

      try {
        const candles = Array.isArray(seededCandles) && seededCandles.length
          ? seededCandles
          : await (async () => {
            const response = await fetch(apiUrl, { credentials: "same-origin" });
            if (!response.ok) {
              throw new Error(`LCNI: request failed (${response.status})`);
            }

            const payload = await response.json();
            return Array.isArray(payload) ? payload : payload?.candles;
          })();
        if (!Array.isArray(candles) || !candles.length) {
          container.textContent = "NO DATA";
          return;
        }

        const { controls, mainChartWrap, volumeWrap, macdWrap, rsiWrap, rsWrap } = buildShell(symbol);

        const commonOptions = {
          autoSize: true,
          layout: { background: { color: "#fff" }, textColor: "#333" },
          grid: { vertLines: { color: "#efefef" }, horzLines: { color: "#efefef" } }
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

        let chartMode = "line";
        const modeSelect = document.createElement("select");
        [{ value: "line", label: "Line" }, { value: "candlestick", label: "Candlestick" }].forEach((mode) => {
          const option = document.createElement("option");
          option.value = mode.value;
          option.textContent = mode.label;
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
        chartModeWrap.style.gap = "6px";
        chartModeWrap.style.alignItems = "center";
        chartModeWrap.appendChild(Object.assign(document.createElement("span"), { textContent: "Kiểu chart" }));
        chartModeWrap.appendChild(modeSelect);
        controls.appendChild(chartModeWrap);

        controls.appendChild(createCheckbox("Volume", true, (checked) => { volumeWrap.style.display = checked ? "block" : "none"; }));
        controls.appendChild(createCheckbox("MACD", true, (checked) => { macdWrap.style.display = checked ? "block" : "none"; }));
        controls.appendChild(createCheckbox("RSI", true, (checked) => { rsiWrap.style.display = checked ? "block" : "none"; }));
        controls.appendChild(createCheckbox("RS by LCNi", true, (checked) => { rsWrap.style.display = checked ? "block" : "none"; }));

        syncMode();

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

        [mainChart, volumeChart, macdChart, rsiChart, rsChart].forEach((chart) => chart.timeScale().fitContent());

        if (!rsiData.length) {
          rsiWrap.style.display = "none";
        }

        if (!rs1wData.length && !rs1mData.length && !rs3mData.length) {
          rsWrap.style.display = "none";
        }
      } catch (error) {
        console.error(error);
        container.textContent = "NO DATA";
      }
    };

    const resolvedInitialSymbol = resolveSymbol();
    const shouldUseSeededCandles = initialSymbol !== "" && resolvedInitialSymbol === initialSymbol && initialCandles.length > 0;
    await fetchAndRender(resolvedInitialSymbol, shouldUseSeededCandles ? initialCandles : null);

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

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

  const sanitizeSymbol = (value) => {
    const symbol = String(value || "").toUpperCase().trim();
    return /^[A-Z0-9._-]{1,15}$/.test(symbol) ? symbol : "";
  };

  const query = new URLSearchParams(window.location.search);

  const renderChart = async (container) => {
    const apiBase = container.dataset.apiBase;
    const limit = Number(container.dataset.limit || 200);
    const queryParam = container.dataset.queryParam;

    const symbolFromQuery = queryParam ? sanitizeSymbol(query.get(queryParam)) : "";
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const fallbackSymbol = sanitizeSymbol(container.dataset.fallbackSymbol);
    const symbol = fixedSymbol || symbolFromQuery || fallbackSymbol;

    if (!apiBase || !symbol) {
      container.textContent = "NO DATA";
      return;
    }

    const apiUrl = `${apiBase}?symbol=${encodeURIComponent(symbol)}&limit=${Number.isFinite(limit) ? limit : 200}`;

    const buildShell = () => {
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

      const volumeWrap = document.createElement("div");
      volumeWrap.style.height = "160px";
      volumeWrap.style.marginTop = "8px";

      const macdWrap = document.createElement("div");
      macdWrap.style.height = "180px";
      macdWrap.style.marginTop = "8px";

      const rsiWrap = document.createElement("div");
      rsiWrap.style.height = "160px";
      rsiWrap.style.marginTop = "8px";

      const rsWrap = document.createElement("div");
      rsWrap.style.height = "180px";
      rsWrap.style.marginTop = "8px";

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

    const lineSeriesDataFromCandles = (candles) => candles.map((candle) => ({
      time: candle.time,
      value: candle.close
    }));

    const seriesDataFilter = (candles, key) => candles
      .filter((item) => typeof item[key] === "number" && Number.isFinite(item[key]))
      .map((item) => ({ time: item.time, value: item[key] }));

    try {
      const response = await fetch(apiUrl, { credentials: "same-origin" });
      if (!response.ok) {
        throw new Error(`LCNI: request failed (${response.status})`);
      }

      const payload = await response.json();
      const candles = Array.isArray(payload) ? payload : payload?.candles;
      if (!Array.isArray(candles)) {
        throw new Error("LCNI: invalid candles payload");
      }

      if (!candles.length) {
        container.textContent = "NO DATA";
        return;
      }

      const { controls, mainChartWrap, volumeWrap, macdWrap, rsiWrap, rsWrap } = buildShell();

      const commonOptions = {
        autoSize: true,
        layout: {
          background: { color: "#fff" },
          textColor: "#333"
        },
        grid: {
          vertLines: { color: "#efefef" },
          horzLines: { color: "#efefef" }
        }
      };

      const mainChart = LightweightCharts.createChart(mainChartWrap, commonOptions);
      const volumeChart = LightweightCharts.createChart(volumeWrap, commonOptions);
      const macdChart = LightweightCharts.createChart(macdWrap, commonOptions);
      const rsiChart = LightweightCharts.createChart(rsiWrap, commonOptions);
      const rsChart = LightweightCharts.createChart(rsWrap, commonOptions);

      const candleSeries = mainChart.addCandlestickSeries();
      const lineSeries = mainChart.addLineSeries({ color: "#2563eb", lineWidth: 2 });

      candleSeries.setData(candles);
      lineSeries.setData(lineSeriesDataFromCandles(candles));

      const volumeSeries = volumeChart.addHistogramSeries({
        priceFormat: { type: "volume" },
        priceScaleId: ""
      });

      volumeSeries.setData(candles.map((item) => ({
        time: item.time,
        value: typeof item.volume === "number" ? item.volume : 0,
        color: item.close >= item.open ? "#16a34a" : "#dc2626"
      })));

      const macdSeries = macdChart.addLineSeries({ color: "#1d4ed8", lineWidth: 2 });
      const signalSeries = macdChart.addLineSeries({ color: "#f59e0b", lineWidth: 2 });
      const macdHistogramSeries = macdChart.addHistogramSeries({
        priceScaleId: "",
        base: 0
      });

      macdSeries.setData(seriesDataFilter(candles, "macd"));
      signalSeries.setData(seriesDataFilter(candles, "macd_signal"));
      macdHistogramSeries.setData(candles
        .filter((item) => typeof item.macd_histogram === "number" && Number.isFinite(item.macd_histogram))
        .map((item) => ({
          time: item.time,
          value: item.macd_histogram,
          color: item.macd_histogram >= 0 ? "rgba(22,163,74,0.55)" : "rgba(220,38,38,0.55)"
        })));

      const rsiSeries = rsiChart.addLineSeries({ color: "#7c3aed", lineWidth: 2 });
      const rsiUpperSeries = rsiChart.addLineSeries({ color: "#f97316", lineStyle: 2, lineWidth: 1 });
      const rsiLowerSeries = rsiChart.addLineSeries({ color: "#0ea5e9", lineStyle: 2, lineWidth: 1 });

      const rsiData = seriesDataFilter(candles, "rsi");
      rsiSeries.setData(rsiData);
      rsiUpperSeries.setData(candles.map((item) => ({ time: item.time, value: 70 })));
      rsiLowerSeries.setData(candles.map((item) => ({ time: item.time, value: 30 })));

      const rs1wSeries = rsChart.addLineSeries({ color: "#0ea5e9", lineWidth: 2 });
      const rs1mSeries = rsChart.addLineSeries({ color: "#f59e0b", lineWidth: 2 });
      const rs3mSeries = rsChart.addLineSeries({ color: "#ef4444", lineWidth: 2 });

      const rs1wData = seriesDataFilter(candles, "rs_1w_by_exchange");
      const rs1mData = seriesDataFilter(candles, "rs_1m_by_exchange");
      const rs3mData = seriesDataFilter(candles, "rs_3m_by_exchange");

      rs1wSeries.setData(rs1wData);
      rs1mSeries.setData(rs1mData);
      rs3mSeries.setData(rs3mData);

      let chartMode = "line";
      const chartModeWrap = document.createElement("label");
      chartModeWrap.style.display = "inline-flex";
      chartModeWrap.style.gap = "6px";
      chartModeWrap.style.alignItems = "center";

      const modeText = document.createElement("span");
      modeText.textContent = "Kiểu chart";

      const modeSelect = document.createElement("select");
      [
        { value: "line", label: "Line" },
        { value: "candlestick", label: "Candlestick" }
      ].forEach((mode) => {
        const option = document.createElement("option");
        option.value = mode.value;
        option.textContent = mode.label;
        modeSelect.appendChild(option);
      });

      const syncMode = () => {
        candleSeries.applyOptions({ visible: chartMode === "candlestick" });
        lineSeries.applyOptions({ visible: chartMode === "line" });
      };

      modeSelect.value = chartMode;
      modeSelect.addEventListener("change", () => {
        chartMode = modeSelect.value;
        syncMode();
      });

      chartModeWrap.appendChild(modeText);
      chartModeWrap.appendChild(modeSelect);
      controls.appendChild(chartModeWrap);

      controls.appendChild(createCheckbox("Volume", true, (checked) => {
        volumeWrap.style.display = checked ? "block" : "none";
      }));

      controls.appendChild(createCheckbox("MACD", true, (checked) => {
        macdWrap.style.display = checked ? "block" : "none";
      }));

      controls.appendChild(createCheckbox("RSI", true, (checked) => {
        rsiWrap.style.display = checked ? "block" : "none";
      }));

      controls.appendChild(createCheckbox("RS by LCNi", true, (checked) => {
        rsWrap.style.display = checked ? "block" : "none";
      }));

      syncMode();

      const syncTimeScale = (sourceChart, targetCharts) => {
        sourceChart.timeScale().subscribeVisibleLogicalRangeChange((range) => {
          if (!range) {
            return;
          }
          targetCharts.forEach((chart) => {
            chart.timeScale().setVisibleLogicalRange(range);
          });
        });
      };

      syncTimeScale(mainChart, [volumeChart, macdChart, rsiChart, rsChart]);
      syncTimeScale(volumeChart, [mainChart, macdChart, rsiChart, rsChart]);
      syncTimeScale(macdChart, [mainChart, volumeChart, rsiChart, rsChart]);
      syncTimeScale(rsiChart, [mainChart, volumeChart, macdChart, rsChart]);
      syncTimeScale(rsChart, [mainChart, volumeChart, macdChart, rsiChart]);

      mainChart.timeScale().fitContent();
      volumeChart.timeScale().fitContent();
      macdChart.timeScale().fitContent();
      rsiChart.timeScale().fitContent();
      rsChart.timeScale().fitContent();

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

  await Promise.all(Array.from(containers).map((container) => renderChart(container)));
});

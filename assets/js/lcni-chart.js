document.addEventListener("DOMContentLoaded", async () => {
  const containers = document.querySelectorAll("[data-lcni-chart]");
  if (!containers.length) return;

  if (typeof LightweightCharts === "undefined") {
    containers.forEach((container) => { container.textContent = "NO DATA"; });
    return;
  }

  const stockSyncUtils = window.LCNIStockSyncUtils || null;
  const sanitizeSymbol = stockSyncUtils
    ? stockSyncUtils.sanitizeSymbol
    : (value) => (/^[A-Z0-9._-]{1,15}$/.test(String(value || "").toUpperCase().trim()) ? String(value || "").toUpperCase().trim() : "");

  const parseAdminConfig = (rawConfig) => {
    try {
      const parsed = rawConfig ? JSON.parse(rawConfig) : {};
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  };

  const stockSync = stockSyncUtils
    ? stockSyncUtils.createStockSync()
    : { subscribe() {}, setSymbol() {}, getCurrentSymbol() { return ""; }, configureQueryParam() {} };

  const allPanels = ["volume", "macd", "rsi", "rs"];

  const loadLocalSettings = (container, allowedPanels, defaultMode) => {
    const key = `${container.dataset.settingsStorageKey || "lcni_chart_settings_v1"}:${window.location.pathname}`;
    try {
      const raw = window.localStorage.getItem(key);
      if (!raw) return { mode: defaultMode, panels: allowedPanels, key };
      const parsed = JSON.parse(raw);
      const mode = ["line", "candlestick"].includes(parsed.mode) ? parsed.mode : defaultMode;
      const panels = Array.isArray(parsed.panels) ? parsed.panels.filter((item) => allowedPanels.includes(item)) : allowedPanels;
      return { mode, panels: panels.length ? panels : allowedPanels, key };
    } catch (error) {
      return { mode: defaultMode, panels: allowedPanels, key };
    }
  };

  const saveLocalSettings = (state) => {
    if (!state.key) return;
    try {
      window.localStorage.setItem(state.key, JSON.stringify({ mode: state.mode, panels: state.panels, updatedAt: Date.now() }));
    } catch (error) {}
  };

  const syncServerSettings = async (container, state, method = "GET") => {
    if (!container.dataset.settingsApi) return;

    if (method === "GET") {
      const response = await fetch(container.dataset.settingsApi, { credentials: "same-origin", headers: { "X-WP-Nonce": container.dataset.settingsNonce || "" } });
      if (!response.ok) return;
      const payload = await response.json();
      if (["line", "candlestick"].includes(payload.mode)) state.mode = payload.mode;
      if (Array.isArray(payload.panels) && payload.panels.length) state.panels = payload.panels;
      saveLocalSettings(state);
      return;
    }

    await fetch(container.dataset.settingsApi, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": container.dataset.settingsNonce || ""
      },
      body: JSON.stringify({ mode: state.mode, panels: state.panels })
    });
  };

  const renderChart = async (container) => {
    const apiBase = container.dataset.apiBase;
    const queryParam = container.dataset.queryParam;
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const fallbackSymbol = sanitizeSymbol(container.dataset.fallbackSymbol);
    const adminConfig = parseAdminConfig(container.dataset.adminConfig);
    const allowedPanels = Array.isArray(adminConfig.allowed_panels) && adminConfig.allowed_panels.length
      ? adminConfig.allowed_panels.filter((panel) => allPanels.includes(panel))
      : allPanels;

    stockSync.configureQueryParam(queryParam || "symbol");

    const resolveSymbol = () => {
      if (fixedSymbol) return fixedSymbol;
      const query = new URLSearchParams(window.location.search);
      const symbolFromQuery = queryParam ? sanitizeSymbol(query.get(queryParam)) : "";
      return stockSync.getCurrentSymbol() || symbolFromQuery || fallbackSymbol;
    };

    const state = loadLocalSettings(container, allowedPanels, adminConfig.default_mode === "candlestick" ? "candlestick" : "line");
    await syncServerSettings(container, state, "GET").catch(() => {});

    const fetchAndRender = async (symbol) => {
      if (!apiBase || !symbol) {
        container.textContent = "NO DATA";
        return;
      }

      try {
        const response = await fetch(`${apiBase}?symbol=${encodeURIComponent(symbol)}&limit=${Number(container.dataset.limit || 200)}`, { credentials: "same-origin" });
        if (!response.ok) throw new Error("request failed");

        const payload = await response.json();
        const candles = Array.isArray(payload) ? payload : payload?.candles;
        if (!Array.isArray(candles) || !candles.length) {
          container.textContent = "NO DATA";
          return;
        }

        container.innerHTML = "";
        const root = document.createElement("div");
        root.className = "lcni-chart-root";

        const controls = document.createElement("div");
        controls.style.display = "flex";
        controls.style.justifyContent = "space-between";
        controls.style.alignItems = "center";

        const title = document.createElement("strong");
        title.textContent = symbol;

        const settingsBtn = document.createElement("button");
        settingsBtn.type = "button";
        settingsBtn.textContent = "⚙";
        settingsBtn.dataset.chartSettingsToggle = "1";
        settingsBtn.setAttribute("aria-expanded", "false");

        const panel = document.createElement("div");
        panel.hidden = true;
        panel.style.border = "1px dashed #d1d5db";
        panel.style.padding = "8px";
        panel.style.marginTop = "8px";

        const modeSelect = document.createElement("select");
        [{ value: "line", label: "Line" }, { value: "candlestick", label: "Candlestick" }].forEach((mode) => {
          const option = document.createElement("option");
          option.value = mode.value;
          option.textContent = mode.label;
          option.selected = state.mode === mode.value;
          modeSelect.appendChild(option);
        });

        const modeWrap = document.createElement("label");
        modeWrap.textContent = "Kiểu ";
        modeWrap.appendChild(modeSelect);
        panel.appendChild(modeWrap);

        const panelChecks = document.createElement("div");
        allowedPanels.forEach((name) => {
          const label = document.createElement("label");
          label.style.marginRight = "10px";
          const input = document.createElement("input");
          input.type = "checkbox";
          input.value = name;
          input.checked = state.panels.includes(name);
          label.appendChild(input);
          label.appendChild(document.createTextNode(" " + name.toUpperCase()));
          panelChecks.appendChild(label);
        });
        panel.appendChild(panelChecks);

        const saveBtn = document.createElement("button");
        saveBtn.type = "button";
        saveBtn.textContent = "Lưu";
        saveBtn.dataset.chartSettingsSave = "1";
        panel.appendChild(saveBtn);

        const mainWrap = document.createElement("div");
        mainWrap.style.height = `${Number(container.dataset.mainHeight || 420)}px`;
        const volumeWrap = document.createElement("div"); volumeWrap.style.height = "150px";
        const macdWrap = document.createElement("div"); macdWrap.style.height = "170px";
        const rsiWrap = document.createElement("div"); rsiWrap.style.height = "150px";
        const rsWrap = document.createElement("div"); rsWrap.style.height = "170px";

        [volumeWrap, macdWrap, rsiWrap, rsWrap].forEach((item) => { item.style.marginTop = "8px"; });

        const commonOptions = { autoSize: true, layout: { background: { color: "#fff" }, textColor: "#333" }, grid: { vertLines: { color: "#efefef" }, horzLines: { color: "#efefef" } } };
        const mainChart = LightweightCharts.createChart(mainWrap, commonOptions);
        const volumeChart = LightweightCharts.createChart(volumeWrap, commonOptions);
        const macdChart = LightweightCharts.createChart(macdWrap, commonOptions);
        const rsiChart = LightweightCharts.createChart(rsiWrap, commonOptions);
        const rsChart = LightweightCharts.createChart(rsWrap, commonOptions);

        const candleSeries = mainChart.addCandlestickSeries();
        const lineSeries = mainChart.addLineSeries({ color: "#2563eb", lineWidth: 2 });
        candleSeries.setData(candles);
        lineSeries.setData(candles.map((item) => ({ time: item.time, value: item.close })));

        const visibility = {
          volume: state.panels.includes("volume"),
          macd: state.panels.includes("macd"),
          rsi: state.panels.includes("rsi"),
          rs: state.panels.includes("rs")
        };

        const refreshVisibility = () => {
          candleSeries.applyOptions({ visible: state.mode === "candlestick" });
          lineSeries.applyOptions({ visible: state.mode === "line" });
          volumeWrap.style.display = visibility.volume ? "block" : "none";
          macdWrap.style.display = visibility.macd ? "block" : "none";
          rsiWrap.style.display = visibility.rsi ? "block" : "none";
          rsWrap.style.display = visibility.rs ? "block" : "none";
        };

        modeSelect.addEventListener("change", () => {
          state.mode = modeSelect.value;
          refreshVisibility();
        });

        panel.addEventListener("change", (event) => {
          const input = event.target.closest("input[type='checkbox']");
          if (!input) return;
          visibility[input.value] = input.checked;
          state.panels = Object.keys(visibility).filter((key) => visibility[key] && allowedPanels.includes(key));
          if (!state.panels.length) state.panels = [...allowedPanels];
          refreshVisibility();
        });

        panel.addEventListener("click", (event) => {
          if (!event.target.closest("[data-chart-settings-save]")) return;
          state.panels = Object.keys(visibility).filter((key) => visibility[key] && allowedPanels.includes(key));
          saveLocalSettings(state);
          syncServerSettings(container, state, "POST").catch(() => {});
        });

        root.addEventListener("click", (event) => {
          const toggle = event.target.closest("[data-chart-settings-toggle]");
          if (!toggle) return;
          const expanded = toggle.getAttribute("aria-expanded") === "true";
          toggle.setAttribute("aria-expanded", String(!expanded));
          panel.hidden = expanded;
        });

        controls.appendChild(title);
        controls.appendChild(settingsBtn);
        root.appendChild(controls);
        root.appendChild(panel);
        root.appendChild(mainWrap);
        root.appendChild(volumeWrap);
        root.appendChild(macdWrap);
        root.appendChild(rsiWrap);
        root.appendChild(rsWrap);
        container.appendChild(root);

        volumeChart.addHistogramSeries({ priceFormat: { type: "volume" }, priceScaleId: "" }).setData(candles.map((item) => ({ time: item.time, value: Number(item.volume || 0), color: item.close >= item.open ? "#16a34a" : "#dc2626" })));
        const seriesDataFilter = (key) => candles.filter((item) => Number.isFinite(Number(item[key]))).map((item) => ({ time: item.time, value: Number(item[key]) }));
        macdChart.addLineSeries({ color: "#1d4ed8", lineWidth: 2 }).setData(seriesDataFilter("macd"));
        macdChart.addLineSeries({ color: "#f59e0b", lineWidth: 2 }).setData(seriesDataFilter("macd_signal"));
        rsiChart.addLineSeries({ color: "#7c3aed", lineWidth: 2 }).setData(seriesDataFilter("rsi"));
        rsChart.addLineSeries({ color: "#0ea5e9", lineWidth: 2 }).setData(seriesDataFilter("rs_1w_by_exchange"));
        rsChart.addLineSeries({ color: "#f59e0b", lineWidth: 2 }).setData(seriesDataFilter("rs_1m_by_exchange"));
        rsChart.addLineSeries({ color: "#ef4444", lineWidth: 2 }).setData(seriesDataFilter("rs_3m_by_exchange"));

        refreshVisibility();
        [mainChart, volumeChart, macdChart, rsiChart, rsChart].forEach((chart) => chart.timeScale().fitContent());
      } catch (error) {
        container.textContent = "NO DATA";
      }
    };

    await fetchAndRender(resolveSymbol());
    stockSync.subscribe(async (nextSymbol) => {
      if (fixedSymbol || !nextSymbol) return;
      await fetchAndRender(nextSymbol);
    });
  };

  document.addEventListener("click", (event) => {
    const link = event.target.closest("[data-lcni-symbol-link]");
    if (!link) return;
    const symbol = sanitizeSymbol(link.dataset.lcniSymbolLink || link.dataset.symbol || "");
    if (!symbol) return;
    event.preventDefault();
    stockSync.setSymbol(symbol, { source: "link", pushState: true });
  });

  await Promise.all(Array.from(containers).map((container) => renderChart(container)));
});

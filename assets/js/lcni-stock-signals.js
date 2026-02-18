document.addEventListener("DOMContentLoaded", () => {
  const containers = document.querySelectorAll("[data-lcni-stock-signals]");
  if (!containers.length) {
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
      configureQueryParam() {}
    };

  const labels = {
    xay_nen: "Nền giá",
    xay_nen_count_30: "Số phiên đi nền trong 30 phiên",
    nen_type: "Dạng nền",
    pha_nen: "Tín hiệu phá nền",
    tang_gia_kem_vol: "Tăng giá kèm Vol",
    smart_money: "Tín hiệu smart",
    rs_exchange_status: "Trạng thái sức mạnh giá",
    rs_exchange_recommend: "Gợi ý sức mạnh giá",
    rs_recommend_status: "Gợi ý trạng thái sức mạnh giá"
  };

  const defaultFields = Object.keys(labels);

  const formatValue = (value) => {
    if (value === null || value === undefined || value === "") {
      return "-";
    }

    if (typeof value === "number") {
      return value.toLocaleString("vi-VN", { maximumFractionDigits: 2 });
    }

    return String(value);
  };

  const parseAdminConfig = (rawConfig) => {
    if (!rawConfig) {
      return null;
    }

    try {
      const parsed = JSON.parse(rawConfig);
      if (!parsed || typeof parsed !== "object") {
        return null;
      }
      return parsed;
    } catch (error) {
      return null;
    }
  };

  const loadSettings = async (settingsApi, fallbackFields) => {
    try {
      const response = await fetch(settingsApi, { credentials: "same-origin" });
      if (!response.ok) {
        return fallbackFields;
      }
      const payload = await response.json();
      return Array.isArray(payload.fields) && payload.fields.length ? payload.fields : fallbackFields;
    } catch (error) {
      return fallbackFields;
    }
  };

  const saveSettings = async (settingsApi, fields) => {
    await fetch(settingsApi, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ fields })
    });
  };

  const buildField = (label, value, styles) => {
    const item = document.createElement("div");
    item.style.padding = "8px 10px";
    item.style.borderRadius = "6px";
    item.style.minHeight = "56px";
    item.style.background = styles.item_background || "#f9fafb";

    const labelElement = document.createElement("small");
    labelElement.textContent = label;
    labelElement.style.color = styles.label_color || "#4b5563";
    labelElement.style.fontSize = `${styles.label_font_size || 12}px`;

    const valueElement = document.createElement("div");
    const valueStrong = document.createElement("strong");
    valueStrong.textContent = formatValue(value);
    valueStrong.style.color = styles.value_color || "#111827";
    valueStrong.style.fontSize = `${styles.value_font_size || 14}px`;

    valueElement.appendChild(valueStrong);
    item.appendChild(labelElement);
    item.appendChild(valueElement);
    return item;
  };

  containers.forEach(async (container) => {
    const apiBase = container.dataset.apiBase;
    const queryParam = container.dataset.queryParam || "symbol";
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const settingsApi = container.dataset.settingsApi;
    const adminConfig = parseAdminConfig(container.dataset.adminConfig);
    const allowedFields = Array.isArray(adminConfig?.allowed_fields) && adminConfig.allowed_fields.length
      ? adminConfig.allowed_fields.filter((field) => labels[field])
      : defaultFields;
    const styles = adminConfig?.styles || {};

    stockSync.configureQueryParam(queryParam);

    let selectedFields = await loadSettings(settingsApi, allowedFields);
    selectedFields = selectedFields.filter((field) => allowedFields.includes(field));
    if (!selectedFields.length) {
      selectedFields = allowedFields;
    }

    const resolveSymbol = () => {
      if (fixedSymbol) {
        return fixedSymbol;
      }

      const query = new URLSearchParams(window.location.search);
      const symbolFromQuery = queryParam ? sanitizeSymbol(query.get(queryParam)) : "";
      return stockSync.getCurrentSymbol() || symbolFromQuery || "";
    };

    const render = async (symbol) => {
      if (!symbol || !apiBase) {
        container.textContent = "NO DATA";
        return;
      }

      try {
        const response = await fetch(`${apiBase}?symbol=${encodeURIComponent(symbol)}`, { credentials: "same-origin" });
        if (!response.ok) {
          throw new Error("request failed");
        }

        const payload = await response.json();

        container.innerHTML = "";
        const wrap = document.createElement("div");
        wrap.style.border = "1px solid #e5e7eb";
        wrap.style.padding = "10px";
        wrap.style.borderRadius = "8px";

        const header = document.createElement("div");
        header.style.display = "flex";
        header.style.justifyContent = "space-between";
        header.style.alignItems = "center";
        header.style.gap = "8px";

        const title = document.createElement("strong");
        title.textContent = `${payload.symbol} · LCNi Signals v${container.dataset.version || "1.0.0"}`;

        const settingBtn = document.createElement("button");
        settingBtn.type = "button";
        settingBtn.textContent = "⚙";
        settingBtn.setAttribute("aria-label", "Cài đặt hiển thị tín hiệu");
        settingBtn.style.border = "none";
        settingBtn.style.background = "transparent";
        settingBtn.style.cursor = "pointer";
        settingBtn.style.fontSize = "16px";
        settingBtn.style.lineHeight = "1";

        const panel = document.createElement("div");
        panel.style.display = "none";
        panel.style.marginTop = "8px";
        panel.style.padding = "8px";
        panel.style.border = "1px dashed #d1d5db";

        allowedFields.forEach((field) => {
          const label = document.createElement("label");
          label.style.display = "inline-flex";
          label.style.marginRight = "10px";
          label.style.marginBottom = "4px";
          label.style.gap = "5px";

          const checkbox = document.createElement("input");
          checkbox.type = "checkbox";
          checkbox.value = field;
          checkbox.checked = selectedFields.includes(field);

          label.appendChild(checkbox);
          label.appendChild(document.createTextNode(labels[field]));
          panel.appendChild(label);
        });

        const saveBtn = document.createElement("button");
        saveBtn.type = "button";
        saveBtn.textContent = "Lưu";
        saveBtn.style.marginLeft = "8px";

        saveBtn.addEventListener("click", async () => {
          selectedFields = Array.from(panel.querySelectorAll('input[type="checkbox"]:checked')).map((input) => input.value);
          if (!selectedFields.length) {
            selectedFields = allowedFields;
          }
          await saveSettings(settingsApi, selectedFields);
          await render(symbol);
        });

        panel.appendChild(saveBtn);

        settingBtn.addEventListener("click", () => {
          panel.style.display = panel.style.display === "none" ? "block" : "none";
        });

        header.appendChild(title);
        header.appendChild(settingBtn);

        const meta = document.createElement("div");
        meta.style.marginTop = "6px";
        meta.innerHTML = `<small>event_time gần nhất: ${formatValue(payload.event_time)} (${formatValue(payload.event_date)})</small>`;

        const grid = document.createElement("div");
        grid.style.display = "grid";
        grid.style.gridTemplateColumns = "repeat(auto-fit,minmax(180px,1fr))";
        grid.style.gap = "8px";
        grid.style.marginTop = "8px";

        selectedFields.forEach((key) => {
          grid.appendChild(buildField(labels[key], payload[key], styles));
        });

        wrap.appendChild(header);
        wrap.appendChild(panel);
        wrap.appendChild(meta);
        wrap.appendChild(grid);
        container.appendChild(wrap);
      } catch (error) {
        container.textContent = "NO DATA";
      }
    };

    const initialSymbol = resolveSymbol();
    if (initialSymbol && !stockSync.getCurrentSymbol()) {
      stockSync.setSymbol(initialSymbol, { source: "signals-init", pushState: false });
    }

    render(resolveSymbol());

    stockSync.subscribe((symbol) => {
      if (fixedSymbol || !symbol) {
        return;
      }
      render(symbol);
    });
  });
});

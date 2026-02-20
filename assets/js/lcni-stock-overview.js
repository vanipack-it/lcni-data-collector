document.addEventListener("DOMContentLoaded", () => {
  const containers = document.querySelectorAll("[data-lcni-stock-overview]");
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

  const labels = {
    symbol: "Mã",
    exchange: "Sàn",
    icb2_name: "Ngành ICB 2",
    eps: "EPS",
    eps_1y_pct: "% EPS 1 năm",
    dt_1y_pct: "% DT 1 năm",
    bien_ln_gop: "Biên LN gộp",
    bien_ln_rong: "Biên LN ròng",
    roe: "ROE",
    de_ratio: "D/E",
    pe_ratio: "P/E",
    pb_ratio: "P/B",
    ev_ebitda: "EV/EBITDA",
    tcbs_khuyen_nghi: "TCBS khuyến nghị",
    co_tuc_pct: "% Cổ tức",
    tc_rating: "TC Rating",
    so_huu_nn_pct: "% Sở hữu NN",
    tien_mat_rong_von_hoa: "Tiền mặt ròng/Vốn hóa",
    tien_mat_rong_tong_tai_san: "Tiền mặt ròng/Tổng tài sản",
    loi_nhuan_4_quy_gan_nhat: "Lợi nhuận 4 quý gần nhất",
    tang_truong_dt_quy_gan_nhat: "Tăng trưởng DT quý gần nhất",
    tang_truong_dt_quy_gan_nhi: "Tăng trưởng DT quý gần nhì",
    tang_truong_ln_quy_gan_nhat: "Tăng trưởng LN quý gần nhất",
    tang_truong_ln_quy_gan_nhi: "Tăng trưởng LN quý gần nhì"
  };

  const defaultFields = Object.keys(labels);

  const evaluateRule = (field, value, rule) => {
    if (!rule || typeof rule !== "object") {
      return false;
    }

    const targetField = String(rule.field || "*");
    if (targetField !== "*" && targetField !== field) {
      return false;
    }

    const operator = String(rule.operator || "");
    const ruleValue = String(rule.value ?? "").trim();
    if (!operator || !ruleValue) {
      return false;
    }

    const leftNumber = Number(value);
    const rightNumber = Number(ruleValue);
    const hasNumeric = Number.isFinite(leftNumber) && Number.isFinite(rightNumber);
    const leftString = String(value ?? "").toLowerCase();
    const rightString = ruleValue.toLowerCase();

    switch (operator) {
      case "equals":
        return hasNumeric ? leftNumber === rightNumber : leftString === rightString;
      case "contains":
        return leftString.includes(rightString);
      case "gt":
        return hasNumeric && leftNumber > rightNumber;
      case "gte":
        return hasNumeric && leftNumber >= rightNumber;
      case "lt":
        return hasNumeric && leftNumber < rightNumber;
      case "lte":
        return hasNumeric && leftNumber <= rightNumber;
      default:
        return false;
    }
  };

  const resolveValueColor = (field, value, styles) => {
    const rules = Array.isArray(styles?.value_rules) ? styles.value_rules : [];
    for (let index = 0; index < rules.length; index += 1) {
      const rule = rules[index];
      if (evaluateRule(field, value, rule) && rule.color) {
        return rule.color;
      }
    }

    return styles?.value_color || "#111827";
  };

  const parseAdminConfig = (rawConfig) => {
    if (!rawConfig) {
      return null;
    }

    try {
      const parsed = JSON.parse(rawConfig);
      return parsed && typeof parsed === "object" ? parsed : null;
    } catch (error) {
      return null;
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

  const formatValue = (key, value) => {
    if (value === null || value === undefined || value === "") {
      return "-";
    }

    const normalizedNumber = typeof value === "number"
      ? value
      : (typeof value === "string" && value.trim() !== "" && Number.isFinite(Number(value)) ? Number(value) : null);

    if (normalizedNumber !== null) {
      if (key.includes("pct") || ["roe", "de_ratio", "pe_ratio", "pb_ratio", "ev_ebitda"].includes(key)) {
        return normalizedNumber.toLocaleString("vi-VN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
      return normalizedNumber.toLocaleString("vi-VN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    return String(value);
  };

  const loadSettings = async (settingsApi) => {
    try {
      const response = await fetch(settingsApi, { credentials: "same-origin" });
      if (!response.ok) {
        return defaultFields;
      }
      const payload = await response.json();
      return Array.isArray(payload.fields) && payload.fields.length ? payload.fields : defaultFields;
    } catch (error) {
      return defaultFields;
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

  containers.forEach(async (container) => {
    const apiBase = container.dataset.apiBase;
    const settingsApi = container.dataset.settingsApi;
    const queryParam = container.dataset.queryParam;
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const adminConfig = parseAdminConfig(container.dataset.adminConfig);
    const allowedFields = Array.isArray(adminConfig?.allowed_fields) && adminConfig.allowed_fields.length
      ? adminConfig.allowed_fields.filter((field) => labels[field])
      : defaultFields;
    const styles = adminConfig?.styles || {};

    stockSync.configureQueryParam(queryParam || "symbol");

    let selectedFields = await loadSettings(settingsApi);
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
        wrap.style.border = styles.container_border || "1px solid #e5e7eb";
        wrap.style.background = styles.container_background || "transparent";
        wrap.style.padding = "10px";
        wrap.style.borderRadius = "8px";

        const header = document.createElement("div");
        header.style.display = "flex";
        header.style.justifyContent = "space-between";

        const title = document.createElement("strong");
        title.textContent = adminConfig?.title || "Stock Overview";

        const settingBtn = document.createElement("button");
        settingBtn.type = "button";
        settingBtn.textContent = "⚙";
        settingBtn.style.border = "none";
        settingBtn.style.background = "transparent";
        settingBtn.style.cursor = "pointer";

        const panel = document.createElement("div");
        panel.style.display = "none";
        panel.style.marginTop = "6px";
        panel.style.padding = "8px";
        panel.style.border = "1px dashed #d1d5db";

        allowedFields.forEach((field) => {
          const label = document.createElement("label");
          label.style.display = "inline-flex";
          label.style.marginRight = "10px";
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
          selectedFields = selectedFields.filter((field) => allowedFields.includes(field));
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

        const grid = document.createElement("div");
        grid.style.display = "flex";
        grid.style.flexWrap = "wrap";
        grid.style.alignItems = "stretch";
        grid.style.gap = "8px";
        grid.style.marginTop = "8px";

        selectedFields.forEach((field) => {
          const item = document.createElement("div");
          item.style.padding = "8px 10px";
          item.style.minHeight = `${styles.item_height || 56}px`;
          item.style.background = styles.item_background || "#f9fafb";
          item.style.borderRadius = "6px";
          item.style.display = "inline-flex";
          item.style.flexDirection = "column";
          item.style.justifyContent = "space-between";
          item.style.flex = "0 1 auto";
          item.style.width = "fit-content";
          item.style.maxWidth = "100%";

          const labelEl = document.createElement("small");
          labelEl.textContent = labels[field];
          labelEl.style.color = styles.label_color || "#4b5563";
          labelEl.style.fontSize = `${styles.label_font_size || 12}px`;

          const valueWrap = document.createElement("div");
          const valueStrong = document.createElement("strong");
          valueStrong.textContent = formatValue(field, payload[field]);
          valueStrong.style.color = resolveValueColor(field, payload[field], styles);
          valueStrong.style.fontSize = `${styles.value_font_size || 14}px`;

          valueWrap.appendChild(valueStrong);
          item.appendChild(labelEl);
          item.appendChild(valueWrap);
          grid.appendChild(item);
        });

        wrap.appendChild(header);
        wrap.appendChild(panel);
        wrap.appendChild(grid);
        container.appendChild(wrap);
      } catch (error) {
        container.textContent = "NO DATA";
      }
    };

    const initialSymbol = resolveSymbol();
    if (initialSymbol && !stockSync.getCurrentSymbol()) {
      stockSync.setSymbol(initialSymbol, { source: "overview-init", pushState: false });
    }

    await render(resolveSymbol());

    stockSync.subscribe(async (symbol) => {
      if (fixedSymbol || !symbol) {
        return;
      }
      await render(symbol);
    });
  });
});

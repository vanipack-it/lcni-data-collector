(function initLcniOverview() {
  if (window.__lcniOverviewInitialized) {
    return;
  }
  window.__lcniOverviewInitialized = true;

  const LABELS = {
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

  const DEFAULT_FIELDS = Object.keys(LABELS);

  const sanitizeSymbol = (value) => {
    const symbol = String(value || "").toUpperCase().trim();
    return /^[A-Z0-9._-]{1,15}$/.test(symbol) ? symbol : "";
  };

  const parseJsonDataAttr = (value, fallback = null) => {
    if (!value) return fallback;
    try {
      const parsed = JSON.parse(value);
      return parsed && typeof parsed === "object" ? parsed : fallback;
    } catch (error) {
      return fallback;
    }
  };

  const unwrapPayload = (payload) => {
    if (!payload || typeof payload !== "object") {
      return payload;
    }
    if (payload.success === true && payload.data && typeof payload.data === "object") {
      return payload.data;
    }
    return payload;
  };

  const formatValue = (key, value) => {
    if (value === null || value === undefined || value === "") {
      return "-";
    }

    const numericValue = typeof value === "number"
      ? value
      : (typeof value === "string" && value.trim() !== "" && Number.isFinite(Number(value)) ? Number(value) : null);

    if (numericValue !== null && window.LCNIFormatter) {
      if (typeof window.LCNIFormatter.formatByField === "function") {
        return window.LCNIFormatter.formatByField(numericValue, key);
      }
      if (typeof window.LCNIFormatter.formatByColumn === "function") {
        return window.LCNIFormatter.formatByColumn(numericValue, key);
      }
    }

    if (numericValue !== null) {
      return numericValue.toLocaleString("vi-VN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    return String(value);
  };

  const renderButtonContent = (buttonConfig) => {
    const iconClass = String(buttonConfig?.icon_class || "").trim();
    const label = String(buttonConfig?.label_text || "").trim();
    const icon = iconClass ? `<i class="${iconClass}" aria-hidden="true"></i>` : "";
    const text = label ? `<span>${label}</span>` : "";

    if ((buttonConfig?.icon_position || "left") === "right") {
      return `${text}${icon}`;
    }

    return `${icon}${text}`;
  };

  const evaluateRule = (field, value, rule) => {
    const targetField = String(rule?.field || "*");
    if (targetField !== "*" && targetField !== field) {
      return false;
    }

    const operator = String(rule?.operator || "");
    const ruleValue = String(rule?.value ?? "").trim();
    if (!operator || !ruleValue) {
      return false;
    }

    const leftNumber = Number(value);
    const rightNumber = Number(ruleValue);
    const hasNumeric = Number.isFinite(leftNumber) && Number.isFinite(rightNumber);
    const leftString = String(value ?? "").toLowerCase();
    const rightString = ruleValue.toLowerCase();

    switch (operator) {
      case "equals": return hasNumeric ? leftNumber === rightNumber : leftString === rightString;
      case "contains": return leftString.includes(rightString);
      case "gt": return hasNumeric && leftNumber > rightNumber;
      case "gte": return hasNumeric && leftNumber >= rightNumber;
      case "lt": return hasNumeric && leftNumber < rightNumber;
      case "lte": return hasNumeric && leftNumber <= rightNumber;
      default: return false;
    }
  };

  const resolveValueColor = (field, value, styles) => {
    const rules = Array.isArray(styles?.value_rules) ? styles.value_rules : [];
    for (let i = 0; i < rules.length; i += 1) {
      const rule = rules[i];
      if (evaluateRule(field, value, rule) && rule.color) {
        return rule.color;
      }
    }
    return styles?.value_color || "#111827";
  };

  const loadSettings = async (settingsApi, fallbackFields) => {
    try {
      const response = await fetch(settingsApi, { credentials: "same-origin" });
      if (!response.ok) return fallbackFields;
      const payload = await response.json();
      const data = unwrapPayload(payload);
      return Array.isArray(data?.fields) && data.fields.length ? data.fields : fallbackFields;
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

  const createStockSync = () => {
    const utils = window.LCNIStockSyncUtils;
    if (!utils || typeof utils.createStockSync !== "function") {
      return {
        subscribe() {},
        setSymbol() {},
        getCurrentSymbol() { return ""; },
        configureQueryParam() {}
      };
    }

    return utils.createStockSync();
  };

  const initContainer = async (container) => {
    const apiBase = String(container.dataset.apiBase || "");
    const settingsApi = String(container.dataset.settingsApi || "");
    const queryParam = String(container.dataset.queryParam || "symbol");
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const adminConfig = parseJsonDataAttr(container.dataset.adminConfig, {});
    const buttonConfig = parseJsonDataAttr(container.dataset.buttonConfig, {});
    const styles = adminConfig?.styles || {};

    const allowedFields = Array.isArray(adminConfig?.allowed_fields) && adminConfig.allowed_fields.length
      ? adminConfig.allowed_fields.filter((field) => LABELS[field])
      : DEFAULT_FIELDS;

    const stockSync = createStockSync();
    stockSync.configureQueryParam(queryParam);

    let selectedFields = settingsApi
      ? await loadSettings(settingsApi, allowedFields)
      : allowedFields;

    selectedFields = selectedFields.filter((field) => allowedFields.includes(field));
    if (!selectedFields.length) {
      selectedFields = allowedFields;
    }

    const resolveSymbol = () => {
      if (fixedSymbol) return fixedSymbol;
      const query = new URLSearchParams(window.location.search);
      const fromQuery = sanitizeSymbol(query.get(queryParam));
      return stockSync.getCurrentSymbol() || fromQuery || "";
    };

    const render = async (symbol) => {
      if (!apiBase || !symbol) {
        container.textContent = "NO DATA";
        return;
      }

      try {
        const response = await fetch(`${apiBase}?symbol=${encodeURIComponent(symbol)}`, { credentials: "same-origin" });
        if (!response.ok) {
          throw new Error("request_failed");
        }

        const payload = unwrapPayload(await response.json());
        if (!payload || typeof payload !== "object" || !Object.keys(payload).length) {
          container.textContent = "NO DATA";
          return;
        }

        container.innerHTML = "";
        const wrap = document.createElement("div");
        wrap.style.border = styles.container_border || "1px solid #e5e7eb";
        wrap.style.background = styles.container_background || "#ffffff";
        wrap.style.padding = "10px";
        wrap.style.borderRadius = "8px";

        const header = document.createElement("div");
        header.style.display = "flex";
        header.style.justifyContent = "space-between";

        const title = document.createElement("strong");
        title.textContent = adminConfig?.title || "Stock Overview";

        const settingBtn = document.createElement("button");
        settingBtn.type = "button";
        settingBtn.className = "lcni-btn lcni-btn-btn_overview_setting";
        settingBtn.innerHTML = renderButtonContent(buttonConfig);
        settingBtn.setAttribute("aria-label", "Mở cài đặt hiển thị");

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
          label.appendChild(document.createTextNode(LABELS[field] || field));
          panel.appendChild(label);
        });

        const saveBtn = document.createElement("button");
        saveBtn.type = "button";
        saveBtn.className = "lcni-btn lcni-btn-btn_overview_save";
        saveBtn.textContent = "Lưu";
        saveBtn.style.marginLeft = "8px";

        saveBtn.addEventListener("click", async () => {
          selectedFields = Array.from(panel.querySelectorAll('input[type="checkbox"]:checked')).map((input) => input.value);
          selectedFields = selectedFields.filter((field) => allowedFields.includes(field));
          if (!selectedFields.length) {
            selectedFields = allowedFields;
          }
          if (settingsApi) {
            await saveSettings(settingsApi, selectedFields);
          }
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
        grid.style.gap = "8px";
        grid.style.marginTop = "8px";

        selectedFields.forEach((field) => {
          const item = document.createElement("div");
          item.style.padding = "8px 10px";
          item.style.height = `${styles.item_height || 56}px`;
          item.style.background = styles.item_background || "#f9fafb";
          item.style.borderRadius = "6px";
          item.style.display = "inline-flex";
          item.style.flexDirection = "column";
          item.style.justifyContent = "space-between";

          const labelEl = document.createElement("small");
          labelEl.textContent = LABELS[field] || field;
          labelEl.style.color = styles.label_color || "#4b5563";
          labelEl.style.fontSize = `${styles.label_font_size || 12}px`;

          const valueStrong = document.createElement("strong");
          valueStrong.textContent = formatValue(field, payload[field]);
          valueStrong.style.color = resolveValueColor(field, payload[field], styles);
          valueStrong.style.fontSize = `${styles.value_font_size || 14}px`;

          item.appendChild(labelEl);
          item.appendChild(valueStrong);
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

    stockSync.subscribe(async (nextSymbol) => {
      if (fixedSymbol || !nextSymbol) {
        return;
      }
      await render(nextSymbol);
    });
  };

  document.addEventListener("DOMContentLoaded", () => {
    const containers = document.querySelectorAll("[data-lcni-overview]");
    if (!containers.length) {
      return;
    }

    containers.forEach((container) => {
      initContainer(container);
    });
  });
})();

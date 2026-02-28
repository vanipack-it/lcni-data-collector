document.addEventListener("DOMContentLoaded", () => {
  const containers = document.querySelectorAll("[data-lcni-stock-signals]");
  if (!containers.length) {
    return;
  }

  const context = window.LCNIStockContext;
  if (!context) {
    return;
  }

  const sanitizeSymbol = context.normalizeSymbol;

  const defaultLabels = {
    xay_nen: "Nền giá",
    xay_nen_count_30: "Số phiên đi nền trong 30 phiên",
    nen_type: "Dạng nền",
    pha_nen: "Tín hiệu phá nền",
    tang_gia_kem_vol: "Tăng giá kèm Vol",
    smart_money: "Tín hiệu smart",
    rs_exchange_status: "Trạng thái sức mạnh giá",
    rs_exchange_recommend: "Gợi ý sức mạnh giá",
    rs_recommend_status: "Gợi ý trạng thái sức mạnh giá",
  };

  const resolveOperatorMatch = (rawValue, operator, expected) => {
    const numericRaw = Number(rawValue);
    const numericExpected = Number(expected);
    const bothNumeric = Number.isFinite(numericRaw) && Number.isFinite(numericExpected);
    const left = bothNumeric ? numericRaw : String(rawValue ?? "").toLowerCase();
    const right = bothNumeric ? numericExpected : String(expected ?? "").toLowerCase();

    if (operator === ">") return left > right;
    if (operator === ">=") return left >= right;
    if (operator === "<") return left < right;
    if (operator === "<=") return left <= right;
    if (operator === "=" || operator === "equals") return left === right;
    if (operator === "!=") return left !== right;
    if (operator === "contains") return String(rawValue ?? "").toLowerCase().includes(String(expected ?? "").toLowerCase());
    if (operator === "not_contains") return !String(rawValue ?? "").toLowerCase().includes(String(expected ?? "").toLowerCase());
    if (operator === "gt") return bothNumeric && numericRaw > numericExpected;
    if (operator === "gte") return bothNumeric && numericRaw >= numericExpected;
    if (operator === "lt") return bothNumeric && numericRaw < numericExpected;
    if (operator === "lte") return bothNumeric && numericRaw <= numericExpected;

    return false;
  };

  const resolveFieldStyle = (field, value, payload, styles) => {
    const cellRules = Array.isArray(styles?.cell_to_cell_rules) ? styles.cell_to_cell_rules : [];
    for (let index = 0; index < cellRules.length; index += 1) {
      const rule = cellRules[index] || {};
      if (rule.target_field !== field) continue;
      if (!resolveOperatorMatch(payload?.[rule.source_field], rule.operator, rule.value)) continue;
      return {
        color: rule.text_color || styles?.value_color || "#111827",
        background: rule.bg_color || "transparent",
      };
    }

    const globalRules = Array.isArray(styles?.global_value_color_rules) ? styles.global_value_color_rules : [];
    for (let index = 0; index < globalRules.length; index += 1) {
      const rule = globalRules[index] || {};
      if (rule.column !== field) continue;
      if (!resolveOperatorMatch(value, rule.operator, rule.value)) continue;
      return {
        color: rule.text_color || styles?.value_color || "#111827",
        background: rule.bg_color || "transparent",
      };
    }

    const localRules = Array.isArray(styles?.value_rules) ? styles.value_rules : [];
    for (let index = 0; index < localRules.length; index += 1) {
      const rule = localRules[index] || {};
      const targetField = String(rule.field || "*");
      if (targetField !== "*" && targetField !== field) continue;
      if (!resolveOperatorMatch(value, rule.operator, rule.value)) continue;
      if (!rule.color) continue;
      return { color: rule.color, background: "transparent" };
    }

    return { color: styles?.value_color || "#111827", background: "transparent" };
  };

  const formatValue = (field, value) => {
    if (value === null || value === undefined || value === "") {
      return "-";
    }

    if (typeof value === "number") {
      if (window.LCNIFormatter) {
        const canApply =
          typeof window.LCNIFormatter.shouldApply !== "function" ||
          window.LCNIFormatter.shouldApply("signals");

        if (!canApply) {
          return String(value);
        }

        if (typeof window.LCNIFormatter.formatByField === "function") {
          return window.LCNIFormatter.formatByField(value, field);
        }

        if (typeof window.LCNIFormatter.formatByColumn === "function") {
          return window.LCNIFormatter.formatByColumn(value, field);
        }

        if (typeof window.LCNIFormatter.format === "function") {
          return window.LCNIFormatter.format(value, "price");
        }
      }

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

  const parseButtonConfig = (rawConfig) => {
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

  const unwrapPayload = (payload) => {
    if (!payload || typeof payload !== "object") {
      return payload;
    }

    if (payload.success === true && payload.data && typeof payload.data === "object") {
      return payload.data;
    }

    return payload;
  };

  const escapeHtml = (value) =>
    String(value ?? "").replace(
      /[&<>"']/g,
      (char) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[char],
    );

  const renderButtonContent = (buttonConfig) => {
    const iconClass = String(buttonConfig?.icon_class || "").trim();
    const label = String(buttonConfig?.label_text || "").trim();
    const icon = iconClass
      ? `<i class="${escapeHtml(iconClass)}" aria-hidden="true"></i>`
      : "";
    const text = label ? `<span>${escapeHtml(label)}</span>` : "";

    if ((buttonConfig?.icon_position || "left") === "right") {
      return `${text}${icon}`;
    }

    return `${icon}${text}`;
  };
  const loadSettings = async (settingsApi, fallbackFields) => {
    try {
      const response = await fetch(settingsApi, { credentials: "same-origin" });
      if (!response.ok) {
        return fallbackFields;
      }
      const payload = await response.json();
      return Array.isArray(payload.fields) && payload.fields.length
        ? payload.fields
        : fallbackFields;
    } catch (error) {
      return fallbackFields;
    }
  };

  const saveSettings = async (settingsApi, fields) => {
    await fetch(settingsApi, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ fields }),
    });
  };

  const buildFilterUrl = (base, fields, field, value) => {
    const safeBase = String(base || "").trim();
    const safeField = String(field || "").trim();
    const safeValue = String(value == null ? "" : value).trim();
    if (!safeBase || !safeField || !safeValue) return "";
    const filterable = Array.isArray(fields) ? fields.map((item) => String(item || "").trim()) : [];
    if (filterable.length && !filterable.includes(safeField)) return "";
    const url = new URL(safeBase, window.location.origin);
    url.searchParams.set("apply_filter", "1");
    url.searchParams.set(safeField, safeValue);
    return url.toString();
  };

  const buildField = (key, label, value, styles, payload, clickUrl) => {
    const item = document.createElement("div");
    item.style.padding = "8px 10px";
    item.style.borderRadius = "6px";
    item.style.height = `${styles.item_height || 56}px`;
    item.style.background = styles.item_background || "#f9fafb";
    item.style.display = "inline-flex";
    item.style.flexDirection = "column";
    item.style.justifyContent = "space-between";
    item.style.flex = "0 1 auto";
    item.style.width = "fit-content";
    item.style.maxWidth = "100%";

    const labelElement = document.createElement("small");
    labelElement.textContent = label;
    labelElement.style.color = styles.label_color || "#4b5563";
    labelElement.style.fontSize = `${styles.label_font_size || 12}px`;

    const valueElement = document.createElement("div");
    const valueStrong = document.createElement("strong");
    valueStrong.textContent = formatValue(key, value);
    const resolvedStyle = resolveFieldStyle(key, value, payload, styles);
    valueStrong.style.color = resolvedStyle.color;
    if (resolvedStyle.background && resolvedStyle.background !== "transparent") {
      valueStrong.style.background = resolvedStyle.background;
      valueStrong.style.padding = "2px 6px";
      valueStrong.style.borderRadius = "4px";
      valueStrong.style.display = "inline-block";
    }
    valueStrong.style.fontSize = `${styles.value_font_size || 14}px`;

    valueElement.appendChild(valueStrong);
    item.appendChild(labelElement);
    item.appendChild(valueElement);
    if (clickUrl) {
      item.style.cursor = "pointer";
      item.addEventListener("click", () => {
        window.location.href = clickUrl;
      });
    }
    return item;
  };

  containers.forEach(async (container) => {
    const apiBase = container.dataset.apiBase;
    const queryParam = container.dataset.queryParam || "symbol";
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);
    const settingsApi = container.dataset.settingsApi;
    const adminConfig = parseAdminConfig(container.dataset.adminConfig);
    const buttonConfig =
      parseButtonConfig(container.dataset.buttonConfig) || {};
    const labels =
      adminConfig?.field_labels && typeof adminConfig.field_labels === "object"
        ? { ...defaultLabels, ...adminConfig.field_labels }
        : defaultLabels;
    const defaultFields = Object.keys(labels);
    const allowedFields =
      Array.isArray(adminConfig?.allowed_fields) &&
      adminConfig.allowed_fields.length
        ? adminConfig.allowed_fields.filter((field) => labels[field])
        : defaultFields;
    const styles = adminConfig?.styles || {};
    const filterPageUrl = String(container.dataset.filterPageUrl || "");
    const filterFields = (() => { try { const parsed = JSON.parse(container.dataset.filterFields || "[]"); return Array.isArray(parsed) ? parsed : []; } catch (error) { return []; } })();

    let selectedFields = await loadSettings(settingsApi, allowedFields);
    selectedFields = selectedFields.filter((field) =>
      allowedFields.includes(field),
    );
    if (!selectedFields.length) {
      selectedFields = allowedFields;
    }

    const resolveSymbol = () => fixedSymbol || context.getCurrentSymbol() || "";

    const render = async (symbol) => {
      if (!symbol || !apiBase) {
        container.textContent = "NO DATA";
        return;
      }

      try {
        const payload = unwrapPayload(
          await context.fetchJson(
            `signals:${symbol}`,
            `${apiBase}?symbol=${encodeURIComponent(symbol)}`,
          ),
        );

        container.innerHTML = "";
        const wrap = document.createElement("div");
        wrap.style.border = styles.container_border || "1px solid #e5e7eb";
        wrap.style.background = styles.container_background || "transparent";
        wrap.style.padding = "10px";
        wrap.style.borderRadius = "8px";

        const header = document.createElement("div");
        header.style.display = "flex";
        header.style.justifyContent = "space-between";
        header.style.alignItems = "center";
        header.style.gap = "8px";

        const title = document.createElement("strong");
        title.textContent = adminConfig?.title || "LCNi Signals";

        const settingBtn = document.createElement("button");
        settingBtn.type = "button";
        settingBtn.className = "lcni-btn lcni-btn-btn_signals_setting";
        settingBtn.innerHTML = renderButtonContent(buttonConfig);
        settingBtn.setAttribute("aria-label", "Cài đặt hiển thị tín hiệu");

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
          selectedFields = Array.from(
            panel.querySelectorAll('input[type="checkbox"]:checked'),
          ).map((input) => input.value);
          if (!selectedFields.length) {
            selectedFields = allowedFields;
          }
          await saveSettings(settingsApi, selectedFields);
          await render(symbol);
        });

        panel.appendChild(saveBtn);

        settingBtn.addEventListener("click", () => {
          panel.style.display =
            panel.style.display === "none" ? "block" : "none";
        });

        header.appendChild(title);
        header.appendChild(settingBtn);

        const grid = document.createElement("div");
        grid.style.display = "flex";
        grid.style.flexWrap = "wrap";
        grid.style.alignItems = "stretch";
        grid.style.gap = "8px";
        grid.style.marginTop = "8px";

        selectedFields.forEach((key) => {
          const clickUrl = buildFilterUrl(filterPageUrl, filterFields, key, payload[key]);
          grid.appendChild(buildField(key, labels[key], payload[key], styles, clickUrl));
        });

        wrap.appendChild(header);
        wrap.appendChild(panel);
        wrap.appendChild(grid);
        container.appendChild(wrap);
      } catch (error) {
        container.textContent = "NO DATA";
      }
    };

    render(resolveSymbol());

    document.addEventListener("lcni:symbolChange", (event) => {
      if (fixedSymbol) {
        return;
      }

      const nextSymbol = sanitizeSymbol(event?.detail?.symbol || "");
      if (!nextSymbol) {
        return;
      }

      render(nextSymbol);
    });
  });
});

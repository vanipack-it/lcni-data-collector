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

  const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));

  const renderButtonContent = (buttonConfig) => {
    const iconClass = String(buttonConfig?.icon_class || "").trim();
    const label = String(buttonConfig?.label_text || "").trim();
    const icon = iconClass ? `<i class="${escapeHtml(iconClass)}" aria-hidden="true"></i>` : "";
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

  const buildField = (key, label, value, styles) => {
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
    valueStrong.textContent = formatValue(value);
    valueStrong.style.color = resolveValueColor(key, value, styles);
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
    const buttonConfig = parseButtonConfig(container.dataset.buttonConfig) || {};
    const allowedFields = Array.isArray(adminConfig?.allowed_fields) && adminConfig.allowed_fields.length
      ? adminConfig.allowed_fields.filter((field) => labels[field])
      : defaultFields;
    const styles = adminConfig?.styles || {};

    let selectedFields = await loadSettings(settingsApi, allowedFields);
    selectedFields = selectedFields.filter((field) => allowedFields.includes(field));
    if (!selectedFields.length) {
      selectedFields = allowedFields;
    }

    const resolveSymbol = () => fixedSymbol || context.getCurrentSymbol() || '';

    const render = async (symbol) => {
      if (!symbol || !apiBase) {
        container.textContent = "NO DATA";
        return;
      }

      try {
        const payload = await context.fetchJson(`signals:${symbol}`, `${apiBase}?symbol=${encodeURIComponent(symbol)}`);

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

        const grid = document.createElement("div");
        grid.style.display = "flex";
        grid.style.flexWrap = "wrap";
        grid.style.alignItems = "stretch";
        grid.style.gap = "8px";
        grid.style.marginTop = "8px";

        selectedFields.forEach((key) => {
          grid.appendChild(buildField(key, labels[key], payload[key], styles));
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

    document.addEventListener('lcni:symbolChange', (event) => {
      if (fixedSymbol) {
        return;
      }

      const nextSymbol = sanitizeSymbol(event?.detail?.symbol || '');
      if (!nextSymbol) {
        return;
      }

      render(nextSymbol);
    });
  });
});

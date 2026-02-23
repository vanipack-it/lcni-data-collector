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

  const parseButtonConfig = (rawConfig) => {
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

    if (normalizedNumber !== null && window.LCNIFormatter) {
      const canApply = typeof window.LCNIFormatter.shouldApply !== "function" || window.LCNIFormatter.shouldApply("stock_detail");
      if (!canApply) {
        return String(normalizedNumber);
      }
      if (typeof window.LCNIFormatter.formatByField === "function") {
        return window.LCNIFormatter.formatByField(normalizedNumber, key);
      }
      if (typeof window.LCNIFormatter.formatByColumn === "function") {
        return window.LCNIFormatter.formatByColumn(normalizedNumber, key);
      }
      if (typeof window.LCNIFormatter.format === "function") {
        return window.LCNIFormatter.format(normalizedNumber, "price");
      }
    }

    if (normalizedNumber !== null) {
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
        if (!payload || (Array.isArray(payload) && payload.length === 0) || (!Array.isArray(payload) && typeof payload === 'object' && Object.keys(payload).length === 0)) {
          container.textContent = "NO DATA";
          return;
        }

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
          item.style.height = `${styles.item_height || 56}px`;
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
          valueStrong.style.color = styles.value_color || "#111827";
          valueStrong.setAttribute("data-lcni-color-field", field);
          valueStrong.setAttribute("data-lcni-color-value", String(payload[field] ?? ""));
          valueStrong.style.fontSize = `${styles.value_font_size || 14}px`;

          valueWrap.appendChild(valueStrong);
          item.appendChild(labelEl);
          item.appendChild(valueWrap);
          grid.appendChild(item);
        });

        if (window.LCNIColorEngine && typeof window.LCNIColorEngine.apply === 'function') {
          grid.querySelectorAll('[data-lcni-color-field]').forEach((node) => {
            window.LCNIColorEngine.apply(node, node.getAttribute('data-lcni-color-field'), node.getAttribute('data-lcni-color-value'));
          });
        }

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

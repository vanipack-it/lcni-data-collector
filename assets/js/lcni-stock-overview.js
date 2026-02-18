document.addEventListener("DOMContentLoaded", () => {
  const containers = document.querySelectorAll("[data-lcni-stock-overview]");
  if (!containers.length) {
    return;
  }

  const sanitizeSymbol = (value) => {
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

  const stockSync = window.LCNIStockSync || {
    subscribe() {},
    setSymbol() {},
    getCurrentSymbol() { return ""; },
    getHistory() { return []; }
  };

  const formatValue = (key, value) => {
    if (value === null || value === undefined || value === "") {
      return "-";
    }

    if (typeof value === "number") {
      if (key.includes("pct") || ["roe", "de_ratio", "pe_ratio", "pb_ratio", "ev_ebitda"].includes(key)) {
        return value.toLocaleString("vi-VN", { maximumFractionDigits: 2 });
      }
      return value.toLocaleString("vi-VN", { maximumFractionDigits: 2 });
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

    let selectedFields = await loadSettings(settingsApi);

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
        wrap.style.padding = "12px";
        wrap.style.borderRadius = "8px";

        const header = document.createElement("div");
        header.style.display = "flex";
        header.style.justifyContent = "space-between";

        const title = document.createElement("strong");
        title.textContent = `${payload.symbol} · v${container.dataset.version || "1.0.0"}`;

        const settingBtn = document.createElement("button");
        settingBtn.type = "button";
        settingBtn.textContent = "⚙";
        settingBtn.style.border = "none";
        settingBtn.style.background = "transparent";
        settingBtn.style.cursor = "pointer";

        const panel = document.createElement("div");
        panel.style.display = "none";
        panel.style.marginTop = "8px";
        panel.style.padding = "8px";
        panel.style.border = "1px dashed #d1d5db";

        defaultFields.forEach((field) => {
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
        grid.style.display = "grid";
        grid.style.gridTemplateColumns = "repeat(auto-fit,minmax(220px,1fr))";
        grid.style.gap = "10px";
        grid.style.marginTop = "10px";

        selectedFields.forEach((field) => {
          const item = document.createElement("div");
          item.style.padding = "8px";
          item.style.background = "#f9fafb";
          item.style.borderRadius = "6px";
          item.innerHTML = `<small>${labels[field]}</small><div><strong>${formatValue(field, payload[field])}</strong></div>`;
          grid.appendChild(item);
        });

        const historyTitle = document.createElement("div");
        historyTitle.style.marginTop = "10px";
        historyTitle.innerHTML = "<small>Lịch sử đổi symbol (session)</small>";

        const historyList = document.createElement("ul");
        historyList.style.margin = "4px 0 0 16px";
        stockSync.getHistory().slice(-5).reverse().forEach((row) => {
          const li = document.createElement("li");
          li.textContent = `${row.symbol} (${row.source})`;
          historyList.appendChild(li);
        });

        wrap.appendChild(header);
        wrap.appendChild(panel);
        wrap.appendChild(grid);
        wrap.appendChild(historyTitle);
        wrap.appendChild(historyList);
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

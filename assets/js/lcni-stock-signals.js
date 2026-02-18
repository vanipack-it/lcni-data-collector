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
    xay_nen: "xay_nen",
    xay_nen_count_30: "xay_nen_count_30",
    nen_type: "nen_type",
    pha_nen: "pha_nen",
    tang_gia_kem_vol: "tang_gia_kem_vol",
    smart_money: "smart_money",
    rs_exchange_status: "rs_exchange_status",
    rs_exchange_recommend: "rs_exchange_recommend",
    rs_recommend_status: "rs_recommend_status"
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

  const buildField = (label, value) => {
    const item = document.createElement("div");
    item.style.padding = "8px";
    item.style.borderRadius = "6px";
    item.style.background = "#f9fafb";
    item.innerHTML = `<small>${label}</small><div><strong>${formatValue(value)}</strong></div>`;
    return item;
  };

  containers.forEach((container) => {
    const apiBase = container.dataset.apiBase;
    const queryParam = container.dataset.queryParam || "symbol";
    const fixedSymbol = sanitizeSymbol(container.dataset.symbol);

    stockSync.configureQueryParam(queryParam);

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

        const title = document.createElement("strong");
        title.textContent = `${payload.symbol} · LCNi Signals v${container.dataset.version || "1.0.0"}`;

        const meta = document.createElement("div");
        meta.style.marginTop = "6px";
        meta.innerHTML = `<small>event_time gần nhất: ${formatValue(payload.event_time)} (${formatValue(payload.event_date)})</small>`;

        const grid = document.createElement("div");
        grid.style.display = "grid";
        grid.style.gridTemplateColumns = "repeat(auto-fit,minmax(220px,1fr))";
        grid.style.gap = "10px";
        grid.style.marginTop = "10px";

        Object.keys(labels).forEach((key) => {
          grid.appendChild(buildField(labels[key], payload[key]));
        });

        wrap.appendChild(title);
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

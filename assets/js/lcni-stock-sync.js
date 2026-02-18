(function () {
  const sanitizeSymbol = (value) => {
    const symbol = String(value || "").toUpperCase().trim();
    return /^[A-Z0-9._-]{1,15}$/.test(symbol) ? symbol : "";
  };

  const sanitizeParam = (value) => {
    const param = String(value || "").trim();
    return /^[a-zA-Z0-9_]+$/.test(param) ? param : "symbol";
  };

  const createStockSync = (options = {}) => {
    if (window.LCNIStockSync) {
      if (options.queryParam && typeof window.LCNIStockSync.configureQueryParam === "function") {
        window.LCNIStockSync.configureQueryParam(options.queryParam);
      }
      return window.LCNIStockSync;
    }

    const listeners = [];
    const state = {
      queryParam: sanitizeParam(options.queryParam || "symbol"),
      currentSymbol: "",
      history: []
    };

    const resolveFromUrl = () => {
      const query = new URLSearchParams(window.location.search);
      return sanitizeSymbol(query.get(state.queryParam));
    };

    state.currentSymbol = resolveFromUrl();

    const notify = (symbol, source) => {
      const next = sanitizeSymbol(symbol);
      if (!next) {
        return;
      }

      state.currentSymbol = next;
      state.history.push({ symbol: next, source: source || "unknown", at: Date.now() });
      try {
        sessionStorage.setItem("lcni_stock_symbol_history", JSON.stringify(state.history.slice(-50)));
      } catch (error) {
        // ignore
      }

      listeners.forEach((cb) => cb(next, source));
      window.dispatchEvent(new CustomEvent("lcni:symbol-change", { detail: { symbol: next, source } }));
    };

    const syncUrl = (symbol) => {
      const nextUrl = new URL(window.location.href);
      nextUrl.searchParams.set(state.queryParam, symbol);
      window.history.pushState({ ...window.history.state, lcniSymbol: symbol }, "", nextUrl.toString());
    };

    window.addEventListener("popstate", () => {
      const symbol = resolveFromUrl();
      if (symbol) {
        notify(symbol, "popstate");
      }
    });

    window.LCNIStockSync = {
      configureQueryParam(param) {
        state.queryParam = sanitizeParam(param || state.queryParam);
        const symbol = resolveFromUrl();
        if (symbol && !state.currentSymbol) {
          state.currentSymbol = symbol;
        }
      },
      getCurrentSymbol() {
        return state.currentSymbol;
      },
      getHistory() {
        return state.history.slice();
      },
      subscribe(cb) {
        if (typeof cb === "function") {
          listeners.push(cb);
        }
      },
      setSymbol(symbol, options = {}) {
        const next = sanitizeSymbol(symbol);
        if (!next || next === state.currentSymbol) {
          return;
        }

        if (options.pushState !== false) {
          syncUrl(next);
        }

        notify(next, options.source || "manual");
      }
    };

    return window.LCNIStockSync;
  };

  window.LCNIStockSyncUtils = {
    sanitizeSymbol,
    sanitizeParam,
    createStockSync
  };
})();

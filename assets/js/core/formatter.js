(function initLcniFormatter(windowObj) {
  "use strict";

  if (!windowObj || windowObj.LCNIFormatter) {
    return;
  }

  const DEFAULT_CONFIG = {
    use_intl: true,
    locale: "vi-VN",
    compact_numbers: true,
    compact_threshold: 1000,
    decimals: {
      price: 2,
      percent: 2,
      rsi: 1,
      macd: 2,
      pe: 2,
      pb: 2,
      rs: 1,
      volume: 1,
    },
    percent_normalization: {
      multiply_100_fields: [],
      already_percent_fields: [],
    },
    module_scope: {
      dashboard: true,
      stock_detail: true,
      signals: true,
      screener: true,
      watchlist: true,
      market_overview: true,
    },
  };

  const CACHE = {
    standard: new Map(),
    compact: new Map(),
  };

  const RS_COLUMNS = new Set([
    "rs_1m_by_exchange",
    "rs_1w_by_exchange",
    "rs_3m_by_exchange",
  ]);

  function sanitizeNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function normalizeFieldName(field) {
    return String(field || "")
      .trim()
      .toLowerCase();
  }

  function sanitizeFieldList(rawList) {
    if (!Array.isArray(rawList)) {
      return [];
    }

    const unique = new Set();
    for (let i = 0; i < rawList.length; i += 1) {
      const fieldName = normalizeFieldName(rawList[i]);
      if (fieldName) {
        unique.add(fieldName);
      }
    }

    return Array.from(unique);
  }

  function sanitizeConfig(raw) {
    const config = raw && typeof raw === "object" ? raw : {};
    const merged = {
      use_intl:
        config.use_intl !== undefined
          ? !!config.use_intl
          : DEFAULT_CONFIG.use_intl,
      locale:
        typeof config.locale === "string" && config.locale
          ? config.locale
          : DEFAULT_CONFIG.locale,
      compact_numbers:
        config.compact_numbers !== undefined
          ? !!config.compact_numbers
          : DEFAULT_CONFIG.compact_numbers,
      compact_threshold: Number.isFinite(Number(config.compact_threshold))
        ? Math.max(0, Number(config.compact_threshold))
        : DEFAULT_CONFIG.compact_threshold,
      decimals: Object.assign({}, DEFAULT_CONFIG.decimals),
      percent_normalization: {
        multiply_100_fields:
          DEFAULT_CONFIG.percent_normalization.multiply_100_fields.slice(),
        already_percent_fields:
          DEFAULT_CONFIG.percent_normalization.already_percent_fields.slice(),
      },
      module_scope: Object.assign({}, DEFAULT_CONFIG.module_scope),
    };

    const decimals =
      config.decimals && typeof config.decimals === "object"
        ? config.decimals
        : {};
    Object.keys(DEFAULT_CONFIG.decimals).forEach((key) => {
      const decimal = Number(decimals[key]);
      merged.decimals[key] = Number.isFinite(decimal)
        ? Math.max(0, Math.min(8, Math.floor(decimal)))
        : DEFAULT_CONFIG.decimals[key];
    });

    const percentNormalization =
      config.percent_normalization &&
      typeof config.percent_normalization === "object"
        ? config.percent_normalization
        : {};

    merged.percent_normalization.multiply_100_fields = sanitizeFieldList(
      percentNormalization.multiply_100_fields,
    );
    merged.percent_normalization.already_percent_fields = sanitizeFieldList(
      percentNormalization.already_percent_fields,
    );

    const moduleScope =
      config.module_scope && typeof config.module_scope === "object"
        ? config.module_scope
        : {};
    Object.keys(DEFAULT_CONFIG.module_scope).forEach((moduleKey) => {
      merged.module_scope[moduleKey] =
        moduleScope[moduleKey] !== undefined
          ? !!moduleScope[moduleKey]
          : DEFAULT_CONFIG.module_scope[moduleKey];
    });

    return merged;
  }

  let activeConfig = sanitizeConfig(windowObj.LCNI_FORMAT_CONFIG);

  function buildFieldSets() {
    return {
      multiply100Fields: new Set(
        activeConfig.percent_normalization.multiply_100_fields,
      ),
      alreadyPercentFields: new Set(
        activeConfig.percent_normalization.already_percent_fields,
      ),
    };
  }

  let fieldSets = buildFieldSets();

  function getDecimals(type) {
    const key = String(type || "").toLowerCase();
    return Object.prototype.hasOwnProperty.call(activeConfig.decimals, key)
      ? activeConfig.decimals[key]
      : activeConfig.decimals.price;
  }

  function getIntlFormatter(decimals, compact) {
    const cacheKey = `${activeConfig.locale}|${decimals}`;
    const target = compact ? CACHE.compact : CACHE.standard;

    if (target.has(cacheKey)) {
      return target.get(cacheKey);
    }

    try {
      const formatter = new Intl.NumberFormat(activeConfig.locale, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
        notation: compact ? "compact" : "standard",
        compactDisplay: compact ? "short" : undefined,
      });
      target.set(cacheKey, formatter);
      return formatter;
    } catch (_error) {
      return null;
    }
  }

  function formatFixed(value, decimals) {
    const numeric = sanitizeNumber(value);
    if (numeric === null) {
      return "-";
    }

    return numeric.toFixed(decimals);
  }

  function formatStandard(value, decimals) {
    const numeric = sanitizeNumber(value);
    if (numeric === null) {
      return "-";
    }

    if (!activeConfig.use_intl) {
      return formatFixed(numeric, decimals);
    }

    const formatter = getIntlFormatter(decimals, false);
    if (!formatter) {
      return formatFixed(numeric, decimals);
    }

    return formatter.format(numeric);
  }

  function formatCompact(value, type, options) {
    const numeric = sanitizeNumber(value);
    if (numeric === null) {
      return "-";
    }

    const normalizedType = String(type || "price").toLowerCase();
    const isPercentType = normalizedType === "percent";
    const shouldScalePercent =
      !isPercentType || !options || options.scalePercent !== false;
    const normalizedValue =
      isPercentType && shouldScalePercent ? numeric * 100 : numeric;
    const decimals = getDecimals(normalizedType);
    const abs = Math.abs(normalizedValue);

    if (isPercentType) {
      return `${formatStandard(normalizedValue, decimals)}%`;
    }

    if (!activeConfig.compact_numbers || abs < activeConfig.compact_threshold) {
      return formatStandard(normalizedValue, decimals);
    }

    if (!activeConfig.use_intl) {
      const units = [
        { value: 1e9, symbol: "B" },
        { value: 1e6, symbol: "M" },
        { value: 1e3, symbol: "K" },
      ];
      for (let i = 0; i < units.length; i += 1) {
        if (abs >= units[i].value) {
          return `${(normalizedValue / units[i].value).toFixed(decimals)}${units[i].symbol}`;
        }
      }

      return formatFixed(normalizedValue, decimals);
    }

    const formatter = getIntlFormatter(decimals, true);
    if (!formatter) {
      return formatStandard(normalizedValue, decimals);
    }

    return formatter.format(normalizedValue);
  }

  function inferColumnFormat(column) {
    const key = normalizeFieldName(column);
    if (key.indexOf("volume") !== -1 || key === "vol")
      return { type: "volume" };
    if (key.indexOf("rsi") !== -1) return { type: "rsi" };
    if (key.indexOf("macd") !== -1) return { type: "macd" };
    if (fieldSets.multiply100Fields.has(key))
      return { type: "percent", scalePercent: true };
    if (fieldSets.alreadyPercentFields.has(key))
      return { type: "percent", scalePercent: false };
    if (RS_COLUMNS.has(key)) return { type: "rs" };
    if (key === "pe" || key.indexOf("pe_") === 0) return { type: "pe" };
    if (key === "pb" || key.indexOf("pb_") === 0) return { type: "pb" };
    return { type: "price" };
  }

  function shouldApply(moduleName) {
    const key = normalizeFieldName(moduleName);
    if (!key) {
      return true;
    }

    if (!Object.prototype.hasOwnProperty.call(activeConfig.module_scope, key)) {
      return true;
    }

    return !!activeConfig.module_scope[key];
  }

  const api = {
    format(value, type) {
      const valueType = String(type || "price").toLowerCase();
      return formatCompact(value, valueType);
    },
    formatPercent(value, options) {
      return formatCompact(value, "percent", options || {});
    },
    formatCompact(value, type) {
      return formatCompact(value, type || "price");
    },
    formatFull(value, type) {
      return formatStandard(value, getDecimals(type || "price"));
    },
    inferColumnFormat(column) {
      return Object.assign({}, inferColumnFormat(column));
    },
    formatByField(value, fieldName) {
      const format = inferColumnFormat(fieldName);
      return formatCompact(value, format.type, format);
    },
    formatByColumn(value, column) {
      return api.formatByField(value, column);
    },
    shouldApply(moduleName) {
      return shouldApply(moduleName);
    },
    getConfig() {
      return Object.assign({}, activeConfig, {
        decimals: Object.assign({}, activeConfig.decimals),
        percent_normalization: {
          multiply_100_fields:
            activeConfig.percent_normalization.multiply_100_fields.slice(),
          already_percent_fields:
            activeConfig.percent_normalization.already_percent_fields.slice(),
        },
        module_scope: Object.assign({}, activeConfig.module_scope),
      });
    },
    setConfig(nextConfig) {
      activeConfig = sanitizeConfig(nextConfig);
      fieldSets = buildFieldSets();
      CACHE.standard.clear();
      CACHE.compact.clear();
    },
  };



  const COLOR_DEFAULT = { enabled: false, rules: [] };

  function sanitizeColorConfig(raw) {
    const config = raw && typeof raw === "object" ? raw : {};
    const rules = Array.isArray(config.rules) ? config.rules : [];
    return {
      enabled: !!config.enabled,
      rules: rules
        .map((rule) => {
          if (!rule || typeof rule !== "object") return null;
          const column = normalizeFieldName(rule.column);
          const id = normalizeFieldName(rule.id || "");
          const styleMode = ["flat", "bar", "gradient"].includes(rule.style_mode)
            ? rule.style_mode
            : "flat";
          const type = rule.type === "text" ? "text" : "number";
          const operator = String(rule.operator || "=");
          const value = rule.value;
          if (!column || !id) return null;
          return {
            id,
            column,
            type,
            operator,
            value,
            style_mode: styleMode,
            background_color: String(rule.background_color || ""),
            text_color: String(rule.text_color || ""),
            bar_color: String(rule.bar_color || ""),
            show_value_overlay: !!rule.show_value_overlay,
            gradient_min: sanitizeNumber(rule.gradient_min),
            gradient_max: sanitizeNumber(rule.gradient_max),
            gradient_start_color: String(rule.gradient_start_color || "#f8d7da"),
            gradient_end_color: String(rule.gradient_end_color || "#d1e7dd"),
          };
        })
        .filter(Boolean),
    };
  }

  function toRgb(hexColor) {
    const normalized = String(hexColor || "").replace("#", "").trim();
    if (!/^[0-9a-fA-F]{6}$/.test(normalized)) return null;
    return {
      r: parseInt(normalized.slice(0, 2), 16),
      g: parseInt(normalized.slice(2, 4), 16),
      b: parseInt(normalized.slice(4, 6), 16),
    };
  }

  const colorEngine = {
    config: sanitizeColorConfig(COLOR_DEFAULT),
    byColumn: new Map(),
    buildIndex() {
      this.byColumn.clear();
      this.config.rules.forEach((rule) => {
        const list = this.byColumn.get(rule.column) || [];
        list.push(rule);
        this.byColumn.set(rule.column, list);
      });
    },
    evaluate(field, value) {
      if (!this.config.enabled) return null;
      const key = normalizeFieldName(field);
      const rules = this.byColumn.get(key) || [];
      for (let i = 0; i < rules.length; i += 1) {
        const rule = rules[i];
        if (this.matchRule(rule, value)) return rule;
      }
      return null;
    },
    matchRule(rule, value) {
      if (rule.type === "text") {
        const left = String(value == null ? "" : value).toLowerCase();
        const right = String(rule.value == null ? "" : rule.value).toLowerCase();
        if (rule.operator === "contains") return left.includes(right);
        if (rule.operator === "equals") return left === right;
        if (rule.operator === "starts_with") return left.startsWith(right);
        if (rule.operator === "ends_with") return left.endsWith(right);
        return false;
      }

      const left = sanitizeNumber(value);
      if (left === null) return false;
      if (rule.operator === "between" && Array.isArray(rule.value) && rule.value.length === 2) {
        const min = sanitizeNumber(rule.value[0]);
        const max = sanitizeNumber(rule.value[1]);
        return min !== null && max !== null && left >= min && left <= max;
      }

      const right = sanitizeNumber(rule.value);
      if (right === null) return false;
      if (rule.operator === ">") return left > right;
      if (rule.operator === "<") return left < right;
      if (rule.operator === ">=") return left >= right;
      if (rule.operator === "<=") return left <= right;
      if (rule.operator === "=") return left === right;
      return false;
    },
    apply(element, field, value) {
      if (!element) return;
      const matched = this.evaluate(field, value);
      if (!matched) return;
      if (matched.style_mode === "flat") {
        element.classList.add(`lcni-rule-${matched.id}`);
        return;
      }
      if (matched.style_mode === "bar") {
        this.applyBar(element, value, matched);
        return;
      }
      if (matched.style_mode === "gradient") {
        this.applyGradient(element, value, matched);
      }
    },
    applyBar(element, value, rule) {
      const numeric = sanitizeNumber(value);
      if (numeric === null) return;
      const percent = Math.max(0, Math.min(100, Math.abs(numeric)));
      const currentText = element.textContent;
      element.innerHTML = `<span class="lcni-color-bar" style="position:absolute;left:0;top:0;bottom:0;width:${percent}%;background:${rule.bar_color};opacity:0.18;pointer-events:none;"></span><span style="position:relative;z-index:1;">${currentText}</span>`;
      element.style.position = "relative";
      if (rule.show_value_overlay) {
        element.style.fontWeight = "600";
      }
    },
    applyGradient(element, value, rule) {
      const numeric = sanitizeNumber(value);
      if (numeric === null) return;
      const min = rule.gradient_min;
      const max = rule.gradient_max;
      if (min === null || max === null || max <= min) return;
      const start = toRgb(rule.gradient_start_color);
      const end = toRgb(rule.gradient_end_color);
      if (!start || !end) return;
      const normalized = Math.max(0, Math.min(1, (numeric - min) / (max - min)));
      const r = Math.round(start.r + (end.r - start.r) * normalized);
      const g = Math.round(start.g + (end.g - start.g) * normalized);
      const b = Math.round(start.b + (end.b - start.b) * normalized);
      element.style.backgroundColor = `rgb(${r}, ${g}, ${b})`;
    },
    setConfig(next) {
      this.config = sanitizeColorConfig(next);
      this.buildIndex();
    },
  };

  colorEngine.buildIndex();

  windowObj.LCNIFormatter = Object.freeze(api);
  windowObj.LCNIColorEngine = colorEngine;
})(window);

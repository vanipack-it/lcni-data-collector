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
    date_formats: {
      event_time: "DD-MM-YYYY",
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
      date_formats: Object.assign({}, DEFAULT_CONFIG.date_formats),
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

    const dateFormats =
      config.date_formats && typeof config.date_formats === "object"
        ? config.date_formats
        : {};
    const eventTimeFormat = String(
      dateFormats.event_time || DEFAULT_CONFIG.date_formats.event_time,
    ).trim();
    merged.date_formats.event_time =
      eventTimeFormat === "number" ? "number" : "DD-MM-YYYY";

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
    if (key === "event_time") return { type: "event_time" };
    if (key.indexOf("volume") !== -1 || key === "vol" || key.indexOf("value_traded") !== -1)
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

  function pad2(number) {
    const value = Math.max(0, Math.floor(Number(number) || 0));
    return value < 10 ? `0${value}` : String(value);
  }

  function formatEventTime(value) {
    if (value === null || value === undefined || value === "") {
      return "-";
    }

    const normalized = String(value).trim();
    if (activeConfig.date_formats.event_time === "number") {
      return normalized;
    }

    if (/^\d{8}$/.test(normalized)) {
      const year = normalized.slice(0, 4);
      const month = normalized.slice(4, 6);
      const day = normalized.slice(6, 8);
      return `${day}-${month}-${year}`;
    }

    const numeric = Number(normalized);
    if (!Number.isFinite(numeric)) {
      return normalized;
    }

    const timestampMs = numeric > 1e12 ? numeric : numeric * 1000;
    const date = new Date(timestampMs);
    if (!Number.isFinite(date.getTime())) {
      return normalized;
    }

    return `${pad2(date.getDate())}-${pad2(date.getMonth() + 1)}-${date.getFullYear()}`;
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
      if (format.type === "event_time") {
        return formatEventTime(value);
      }
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
        date_formats: Object.assign({}, activeConfig.date_formats),
      });
    },
    setConfig(nextConfig) {
      activeConfig = sanitizeConfig(nextConfig);
      fieldSets = buildFieldSets();
      CACHE.standard.clear();
      CACHE.compact.clear();
    },
  };

  windowObj.LCNIFormatter = Object.freeze(api);
})(window);

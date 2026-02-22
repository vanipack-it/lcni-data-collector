(function initLcniFormatter(windowObj) {
  'use strict';

  if (!windowObj || windowObj.LCNIFormatter) {
    return;
  }

  const DEFAULT_CONFIG = {
    use_intl: true,
    locale: 'vi-VN',
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
      volume: 1
    }
  };

  const CACHE = {
    standard: new Map(),
    compact: new Map()
  };

  const PERCENT_COLUMNS_SCALE_100 = new Set([
    'pct_t_1', 'pct_t_3', 'pct_1w', 'pct_1m', 'pct_3m', 'pct_6m', 'pct_1y',
    'gia_sv_ma10', 'gia_sv_ma20', 'gia_sv_ma50', 'gia_sv_ma100', 'gia_sv_ma200',
    'vol_sv_vol_ma10', 'vol_sv_vol_ma20'
  ]);

  const PERCENT_COLUMNS_DIRECT = new Set([
    'eps_1y_pct', 'dt_1y_pct', 'bien_ln_gop', 'bien_ln_rong', 'roe', 'co_tuc_pct',
    'so_huu_nn_pct', 'tang_truong_dt_quy_gan_nhat', 'tang_truong_dt_quy_gan_nhi',
    'tang_truong_ln_quy_gan_nhat', 'tang_truong_ln_quy_gan_nhi'
  ]);

  const RS_COLUMNS = new Set([
    'rs_1m_by_exchange', 'rs_1w_by_exchange', 'rs_3m_by_exchange'
  ]);

  function sanitizeNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function sanitizeConfig(raw) {
    const config = raw && typeof raw === 'object' ? raw : {};
    const merged = {
      use_intl: config.use_intl !== undefined ? !!config.use_intl : DEFAULT_CONFIG.use_intl,
      locale: typeof config.locale === 'string' && config.locale ? config.locale : DEFAULT_CONFIG.locale,
      compact_numbers: config.compact_numbers !== undefined ? !!config.compact_numbers : DEFAULT_CONFIG.compact_numbers,
      compact_threshold: Number.isFinite(Number(config.compact_threshold)) ? Math.max(0, Number(config.compact_threshold)) : DEFAULT_CONFIG.compact_threshold,
      decimals: Object.assign({}, DEFAULT_CONFIG.decimals)
    };

    const decimals = config.decimals && typeof config.decimals === 'object' ? config.decimals : {};
    Object.keys(DEFAULT_CONFIG.decimals).forEach((key) => {
      const decimal = Number(decimals[key]);
      merged.decimals[key] = Number.isFinite(decimal) ? Math.max(0, Math.min(8, Math.floor(decimal))) : DEFAULT_CONFIG.decimals[key];
    });

    return merged;
  }

  let activeConfig = sanitizeConfig(windowObj.LCNI_FORMAT_CONFIG);

  function getDecimals(type) {
    const key = String(type || '').toLowerCase();
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
        notation: compact ? 'compact' : 'standard',
        compactDisplay: compact ? 'short' : undefined
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
      return '-';
    }

    return numeric.toFixed(decimals);
  }

  function formatStandard(value, decimals) {
    const numeric = sanitizeNumber(value);
    if (numeric === null) {
      return '-';
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
      return '-';
    }

    const normalizedType = String(type || 'price').toLowerCase();
    const isPercentType = normalizedType === 'percent';
    const shouldScalePercent = !isPercentType || !options || options.scalePercent !== false;
    const normalizedValue = isPercentType && shouldScalePercent ? numeric * 100 : numeric;
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
        { value: 1e9, symbol: 'B' },
        { value: 1e6, symbol: 'M' },
        { value: 1e3, symbol: 'K' }
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
    const key = String(column || '').trim().toLowerCase();
    if (key.indexOf('volume') !== -1 || key === 'vol') return { type: 'volume' };
    if (key.indexOf('rsi') !== -1) return { type: 'rsi' };
    if (key.indexOf('macd') !== -1) return { type: 'macd' };
    if (PERCENT_COLUMNS_SCALE_100.has(key)) return { type: 'percent', scalePercent: true };
    if (PERCENT_COLUMNS_DIRECT.has(key)) return { type: 'percent', scalePercent: false };
    if (RS_COLUMNS.has(key)) return { type: 'rs' };
    if (key === 'pe' || key.indexOf('pe_') === 0) return { type: 'pe' };
    if (key === 'pb' || key.indexOf('pb_') === 0) return { type: 'pb' };
    return { type: 'price' };
  }

  const api = {
    format(value, type) {
      const valueType = String(type || 'price').toLowerCase();
      return formatCompact(value, valueType);
    },
    formatPercent(value, options) {
      return formatCompact(value, 'percent', options || {});
    },
    formatCompact(value, type) {
      return formatCompact(value, type || 'price');
    },
    formatFull(value, type) {
      return formatStandard(value, getDecimals(type || 'price'));
    },
    inferColumnFormat(column) {
      return Object.assign({}, inferColumnFormat(column));
    },
    formatByColumn(value, column) {
      const format = inferColumnFormat(column);
      return formatCompact(value, format.type, format);
    },
    getConfig() {
      return Object.assign({}, activeConfig, { decimals: Object.assign({}, activeConfig.decimals) });
    },
    setConfig(nextConfig) {
      activeConfig = sanitizeConfig(nextConfig);
      CACHE.standard.clear();
      CACHE.compact.clear();
    }
  };

  windowObj.LCNIFormatter = Object.freeze(api);
})(window);

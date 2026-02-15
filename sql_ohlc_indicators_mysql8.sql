-- Rebuild indicator columns for wp_lcni_ohlc (MySQL 8+).
-- Core requirement: all window functions ORDER BY event_time per symbol.

ALTER TABLE wp_lcni_ohlc
    ADD COLUMN IF NOT EXISTS trading_index BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS xay_nen VARCHAR(30) NULL;

WITH RECURSIVE
base AS (
    SELECT
        t.id,
        t.symbol,
        t.event_time,
        t.close_price,
        t.volume,
        ROW_NUMBER() OVER (PARTITION BY t.symbol ORDER BY t.event_time) AS rn,

        -- % change (decimal ratio, e.g. 0.05 = +5%)
        t.close_price / NULLIF(LAG(t.close_price, 1)  OVER w, 0) - 1 AS pct_t_1,
        t.close_price / NULLIF(LAG(t.close_price, 3)  OVER w, 0) - 1 AS pct_t_3,
        t.close_price / NULLIF(LAG(t.close_price, 5)  OVER w, 0) - 1 AS pct_1w,
        t.close_price / NULLIF(LAG(t.close_price, 20) OVER w, 0) - 1 AS pct_1m,
        t.close_price / NULLIF(LAG(t.close_price, 60) OVER w, 0) - 1 AS pct_3m,
        t.close_price / NULLIF(LAG(t.close_price, 120) OVER w, 0) - 1 AS pct_6m,
        t.close_price / NULLIF(LAG(t.close_price, 240) OVER w, 0) - 1 AS pct_1y,

        -- Moving averages
        AVG(t.close_price) OVER (w ROWS BETWEEN 9 PRECEDING  AND CURRENT ROW)  AS ma10,
        AVG(t.close_price) OVER (w ROWS BETWEEN 19 PRECEDING AND CURRENT ROW) AS ma20,
        AVG(t.close_price) OVER (w ROWS BETWEEN 49 PRECEDING AND CURRENT ROW) AS ma50,
        AVG(t.close_price) OVER (w ROWS BETWEEN 99 PRECEDING AND CURRENT ROW) AS ma100,
        AVG(t.close_price) OVER (w ROWS BETWEEN 199 PRECEDING AND CURRENT ROW) AS ma200,

        -- Highest / Lowest by close_price
        MAX(t.close_price) OVER (w ROWS BETWEEN 19 PRECEDING  AND CURRENT ROW) AS h1m,
        MAX(t.close_price) OVER (w ROWS BETWEEN 59 PRECEDING  AND CURRENT ROW) AS h3m,
        MAX(t.close_price) OVER (w ROWS BETWEEN 119 PRECEDING AND CURRENT ROW) AS h6m,
        MAX(t.close_price) OVER (w ROWS BETWEEN 239 PRECEDING AND CURRENT ROW) AS h1y,

        MIN(t.close_price) OVER (w ROWS BETWEEN 19 PRECEDING  AND CURRENT ROW) AS l1m,
        MIN(t.close_price) OVER (w ROWS BETWEEN 59 PRECEDING  AND CURRENT ROW) AS l3m,
        MIN(t.close_price) OVER (w ROWS BETWEEN 119 PRECEDING AND CURRENT ROW) AS l6m,
        MIN(t.close_price) OVER (w ROWS BETWEEN 239 PRECEDING AND CURRENT ROW) AS l1y,

        -- Volume moving averages
        AVG(t.volume) OVER (w ROWS BETWEEN 9 PRECEDING  AND CURRENT ROW) AS vol_ma10,
        AVG(t.volume) OVER (w ROWS BETWEEN 19 PRECEDING AND CURRENT ROW) AS vol_ma20,

        -- RSI helper
        t.close_price - LAG(t.close_price, 1) OVER w AS price_change
    FROM wp_lcni_ohlc t
    WINDOW w AS (PARTITION BY t.symbol ORDER BY t.event_time)
),
gainloss AS (
    SELECT
        b.*,
        GREATEST(b.price_change, 0) AS gain,
        ABS(LEAST(b.price_change, 0)) AS loss,
        AVG(GREATEST(b.price_change, 0)) OVER (PARTITION BY b.symbol ORDER BY b.event_time ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_gain_14,
        AVG(ABS(LEAST(b.price_change, 0))) OVER (PARTITION BY b.symbol ORDER BY b.event_time ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_loss_14
    FROM base b
),
ema AS (
    -- seed EMA12 / EMA26 at first row per symbol
    SELECT
        b.id,
        b.symbol,
        b.rn,
        b.close_price,
        b.close_price AS ema12,
        b.close_price AS ema26
    FROM base b
    WHERE b.rn = 1

    UNION ALL

    SELECT
        b.id,
        b.symbol,
        b.rn,
        b.close_price,
        (2.0 / (12 + 1)) * b.close_price + (1 - 2.0 / (12 + 1)) * e.ema12 AS ema12,
        (2.0 / (26 + 1)) * b.close_price + (1 - 2.0 / (26 + 1)) * e.ema26 AS ema26
    FROM ema e
    JOIN base b
      ON b.symbol = e.symbol
     AND b.rn = e.rn + 1
),
macd_raw AS (
    SELECT
        e.id,
        e.symbol,
        e.rn,
        (e.ema12 - e.ema26) AS macd
    FROM ema e
),
macd_signal AS (
    -- seed Signal at first MACD row per symbol
    SELECT
        m.id,
        m.symbol,
        m.rn,
        m.macd,
        m.macd AS macd_signal
    FROM macd_raw m
    WHERE m.rn = 1

    UNION ALL

    SELECT
        m.id,
        m.symbol,
        m.rn,
        m.macd,
        (2.0 / (9 + 1)) * m.macd + (1 - 2.0 / (9 + 1)) * s.macd_signal AS macd_signal
    FROM macd_signal s
    JOIN macd_raw m
      ON m.symbol = s.symbol
     AND m.rn = s.rn + 1
),
final_calc AS (
    SELECT
        g.id,
        g.rn AS trading_index,
        g.pct_t_1,
        g.pct_t_3,
        g.pct_1w,
        g.pct_1m,
        g.pct_3m,
        g.pct_6m,
        g.pct_1y,
        g.ma10,
        g.ma20,
        g.ma50,
        g.ma100,
        g.ma200,
        g.h1m,
        g.h3m,
        g.h6m,
        g.h1y,
        g.l1m,
        g.l3m,
        g.l6m,
        g.l1y,
        g.vol_ma10,
        g.vol_ma20,
        g.close_price / NULLIF(g.ma10, 0) - 1  AS gia_sv_ma10,
        g.close_price / NULLIF(g.ma20, 0) - 1  AS gia_sv_ma20,
        g.close_price / NULLIF(g.ma50, 0) - 1  AS gia_sv_ma50,
        g.close_price / NULLIF(g.ma100, 0) - 1 AS gia_sv_ma100,
        g.close_price / NULLIF(g.ma200, 0) - 1 AS gia_sv_ma200,
        g.volume / NULLIF(g.vol_ma10, 0) - 1 AS vol_sv_vol_ma10,
        g.volume / NULLIF(g.vol_ma20, 0) - 1 AS vol_sv_vol_ma20,
        ms.macd,
        ms.macd_signal,
        100 - 100 / (1 + g.avg_gain_14 / NULLIF(g.avg_loss_14, 0)) AS rsi,
        CASE
            WHEN (100 - 100 / (1 + g.avg_gain_14 / NULLIF(g.avg_loss_14, 0))) BETWEEN 38.5 AND 75.8
             AND ABS(g.close_price / NULLIF(g.ma10, 0) - 1) <= 0.05
             AND ABS(g.close_price / NULLIF(g.ma20, 0) - 1) <= 0.07
             AND ABS(g.close_price / NULLIF(g.ma50, 0) - 1) <= 0.1
             AND (g.volume / NULLIF(g.vol_ma20, 0) - 1) <= 0.1
             AND g.volume >= 100000
             AND g.pct_t_1 BETWEEN -0.03 AND 0.03
             AND g.pct_1w BETWEEN -0.05 AND 0.05
             AND g.pct_1m BETWEEN -0.1 AND 0.1
             AND g.pct_3m BETWEEN -0.15 AND 0.15
            THEN 'xây nền'
            ELSE NULL
        END AS xay_nen
    FROM gainloss g
    JOIN macd_signal ms ON ms.id = g.id
)
UPDATE wp_lcni_ohlc t
JOIN final_calc f ON f.id = t.id
SET
    t.pct_t_1 = f.pct_t_1,
    t.pct_t_3 = f.pct_t_3,
    t.pct_1w = f.pct_1w,
    t.pct_1m = f.pct_1m,
    t.pct_3m = f.pct_3m,
    t.pct_6m = f.pct_6m,
    t.pct_1y = f.pct_1y,
    t.ma10 = f.ma10,
    t.ma20 = f.ma20,
    t.ma50 = f.ma50,
    t.ma100 = f.ma100,
    t.ma200 = f.ma200,
    t.h1m = f.h1m,
    t.h3m = f.h3m,
    t.h6m = f.h6m,
    t.h1y = f.h1y,
    t.l1m = f.l1m,
    t.l3m = f.l3m,
    t.l6m = f.l6m,
    t.l1y = f.l1y,
    t.vol_ma10 = f.vol_ma10,
    t.vol_ma20 = f.vol_ma20,
    t.gia_sv_ma10 = f.gia_sv_ma10,
    t.gia_sv_ma20 = f.gia_sv_ma20,
    t.gia_sv_ma50 = f.gia_sv_ma50,
    t.gia_sv_ma100 = f.gia_sv_ma100,
    t.gia_sv_ma200 = f.gia_sv_ma200,
    t.vol_sv_vol_ma10 = f.vol_sv_vol_ma10,
    t.vol_sv_vol_ma20 = f.vol_sv_vol_ma20,
    t.macd = f.macd,
    t.macd_signal = f.macd_signal,
    t.rsi = f.rsi,
    t.trading_index = f.trading_index,
    t.xay_nen = f.xay_nen;

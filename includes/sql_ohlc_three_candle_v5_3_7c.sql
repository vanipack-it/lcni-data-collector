-- v5.3.7c
-- Add three_candle_pattern column and backfill three-candle patterns using window functions (MySQL 8+).

ALTER TABLE wp_lcni_ohlc
ADD COLUMN three_candle_pattern VARCHAR(50) NULL AFTER two_candle_pattern;

CREATE INDEX idx_symbol_tf_ti
ON wp_lcni_ohlc (symbol, timeframe, trading_index);

WITH candle_lag AS (
    SELECT
        symbol,
        timeframe,
        trading_index,
        open_price,
        close_price,
        high_price,
        low_price,
        LAG(open_price, 1) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev1_open,
        LAG(close_price, 1) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev1_close,
        LAG(high_price, 1) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev1_high,
        LAG(low_price, 1) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev1_low,
        LAG(open_price, 2) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev2_open,
        LAG(close_price, 2) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev2_close,
        LAG(high_price, 2) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev2_high,
        LAG(low_price, 2) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev2_low
    FROM wp_lcni_ohlc
    WHERE symbol_type = 'stock'
)
UPDATE wp_lcni_ohlc o
JOIN candle_lag c
    ON o.symbol = c.symbol
    AND o.timeframe = c.timeframe
    AND o.trading_index = c.trading_index
SET o.three_candle_pattern = CASE
    WHEN
        c.prev2_close < c.prev2_open
        AND ABS(c.prev1_close - c.prev1_open) < ABS(c.prev2_close - c.prev2_open) * 0.5
        AND c.close_price > c.open_price
        AND c.close_price > (c.prev2_open + c.prev2_close) / 2
    THEN 'MORNING_STAR'

    WHEN
        c.prev2_close > c.prev2_open
        AND ABS(c.prev1_close - c.prev1_open) < ABS(c.prev2_close - c.prev2_open) * 0.5
        AND c.close_price < c.open_price
        AND c.close_price < (c.prev2_open + c.prev2_close) / 2
    THEN 'EVENING_STAR'

    WHEN
        c.prev2_close > c.prev2_open
        AND c.prev1_close > c.prev1_open
        AND c.close_price > c.open_price
        AND c.prev1_close > c.prev2_close
        AND c.close_price > c.prev1_close
    THEN 'THREE_WHITE_SOLDIERS'

    WHEN
        c.prev2_close < c.prev2_open
        AND c.prev1_close < c.prev1_open
        AND c.close_price < c.open_price
        AND c.prev1_close < c.prev2_close
        AND c.close_price < c.prev1_close
    THEN 'THREE_BLACK_CROWS'

    ELSE 'NONE'
END
WHERE o.symbol_type = 'stock'
    AND (o.three_candle_pattern IS NULL OR TRIM(IFNULL(o.three_candle_pattern, '')) = '');

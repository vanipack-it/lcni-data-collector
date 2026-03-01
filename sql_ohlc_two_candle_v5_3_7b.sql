-- v5.3.7b
-- Add two_candle_pattern column and backfill two-candle pattern using window functions (MySQL 8+).

ALTER TABLE wp_lcni_ohlc
ADD COLUMN two_candle_pattern VARCHAR(40) NULL AFTER one_candle;

WITH candle_lag AS (
    SELECT
        symbol,
        timeframe,
        trading_index,
        open_price,
        close_price,
        LAG(open_price) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev_open,
        LAG(close_price) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev_close,
        LAG(high_price) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev_high,
        LAG(low_price) OVER (
            PARTITION BY symbol, timeframe
            ORDER BY trading_index
        ) AS prev_low
    FROM wp_lcni_ohlc
    WHERE UPPER(symbol_type) = 'STOCK'
)
UPDATE wp_lcni_ohlc o
JOIN candle_lag c
    ON o.symbol = c.symbol
    AND o.timeframe = c.timeframe
    AND o.trading_index = c.trading_index
SET o.two_candle_pattern = CASE
    WHEN
        c.prev_close < c.prev_open
        AND c.close_price > c.open_price
        AND c.close_price > c.prev_open
        AND c.open_price < c.prev_close
    THEN 'BULLISH_ENGULFING'

    WHEN
        c.prev_close > c.prev_open
        AND c.close_price < c.open_price
        AND c.close_price < c.prev_open
        AND c.open_price > c.prev_close
    THEN 'BEARISH_ENGULFING'

    WHEN
        c.prev_close < c.prev_open
        AND c.close_price > c.open_price
        AND c.open_price < c.prev_low
        AND c.close_price > (c.prev_open + c.prev_close) / 2
        AND c.close_price < c.prev_open
    THEN 'PIERCING_LINE'

    WHEN
        c.prev_close > c.prev_open
        AND c.close_price < c.open_price
        AND c.open_price > c.prev_high
        AND c.close_price < (c.prev_open + c.prev_close) / 2
        AND c.close_price > c.prev_open
    THEN 'DARK_CLOUD'

    ELSE 'NONE'
END
WHERE UPPER(o.symbol_type) = 'STOCK'
    AND (o.two_candle_pattern IS NULL OR TRIM(IFNULL(o.two_candle_pattern, '')) = '');

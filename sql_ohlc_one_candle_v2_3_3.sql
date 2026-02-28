-- LCNI Data Collector v2.3.3
-- Add one_candle column and backfill one-candle pattern classification.

ALTER TABLE wp_lcni_ohlc
ADD COLUMN one_candle VARCHAR(30) NULL AFTER close_price;

UPDATE wp_lcni_ohlc
SET one_candle = CASE
    WHEN (high_price - low_price) <= 0 THEN 'NONE'
    WHEN ABS(close_price - open_price) <= (high_price - low_price) * 0.1 THEN 'DOJI'
    WHEN (LEAST(open_price, close_price) - low_price) >= (ABS(close_price - open_price) * 2)
         AND (high_price - GREATEST(open_price, close_price)) <= ABS(close_price - open_price)
        THEN 'HAMMER'
    WHEN (high_price - GREATEST(open_price, close_price)) >= (ABS(close_price - open_price) * 2)
         AND (LEAST(open_price, close_price) - low_price) <= ABS(close_price - open_price)
        THEN 'SHOOTING_STAR'
    WHEN close_price > open_price
         AND (high_price - GREATEST(open_price, close_price)) <= (high_price - low_price) * 0.05
         AND (LEAST(open_price, close_price) - low_price) <= (high_price - low_price) * 0.05
        THEN 'MARUBOZU_BULL'
    WHEN close_price < open_price
         AND (high_price - GREATEST(open_price, close_price)) <= (high_price - low_price) * 0.05
         AND (LEAST(open_price, close_price) - low_price) <= (high_price - low_price) * 0.05
        THEN 'MARUBOZU_BEAR'
    WHEN ABS(close_price - open_price) <= (high_price - low_price) * 0.3
         AND ABS(close_price - open_price) > (high_price - low_price) * 0.1
        THEN 'SPINNING_TOP'
    ELSE 'NONE'
END;

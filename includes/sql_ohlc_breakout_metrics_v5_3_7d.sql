-- v5.3.7d
-- Add breakout metric columns to wp_lcni_ohlc and backfill values.

ALTER TABLE wp_lcni_ohlc
ADD COLUMN pct_to_h1m DECIMAL(8,2) NULL AFTER three_candle_pattern,
ADD COLUMN pct_to_h3m DECIMAL(8,2) NULL AFTER pct_to_h1m,
ADD COLUMN pct_to_h6m DECIMAL(8,2) NULL AFTER pct_to_h3m,
ADD COLUMN pct_to_h1y DECIMAL(8,2) NULL AFTER pct_to_h6m,
ADD COLUMN trang_thai_h1m VARCHAR(40) NULL AFTER pct_to_h1y,
ADD COLUMN trang_thai_h3m VARCHAR(40) NULL AFTER trang_thai_h1m,
ADD COLUMN trang_thai_h6m VARCHAR(40) NULL AFTER trang_thai_h3m,
ADD COLUMN trang_thai_h1y VARCHAR(40) NULL AFTER trang_thai_h6m,
ADD COLUMN compression_1m DECIMAL(8,2) NULL AFTER trang_thai_h1y,
ADD COLUMN position_1m VARCHAR(40) NULL AFTER compression_1m,
ADD COLUMN position_3m VARCHAR(40) NULL AFTER position_1m,
ADD COLUMN position_6m VARCHAR(40) NULL AFTER position_3m,
ADD COLUMN position_1y VARCHAR(40) NULL AFTER position_6m,
ADD COLUMN breakout_potential_score INT NULL AFTER position_1y;

CREATE INDEX idx_symbol_tf_time ON wp_lcni_ohlc (symbol, timeframe, event_time);
CREATE INDEX idx_h1m ON wp_lcni_ohlc (h1m);
CREATE INDEX idx_h3m ON wp_lcni_ohlc (h3m);
CREATE INDEX idx_h6m ON wp_lcni_ohlc (h6m);
CREATE INDEX idx_h1y ON wp_lcni_ohlc (h1y);

UPDATE wp_lcni_ohlc
SET
    pct_to_h1m = (close_price - h1m) / NULLIF(h1m,0) * 100,
    pct_to_h3m = (close_price - h3m) / NULLIF(h3m,0) * 100,
    pct_to_h6m = (close_price - h6m) / NULLIF(h6m,0) * 100,
    pct_to_h1y = (close_price - h1y) / NULLIF(h1y,0) * 100,
    trang_thai_h1m = CASE
        WHEN h1m IS NULL OR h1m = 0 THEN NULL
        WHEN close_price > h1m * 1.03 THEN 'Vượt mạnh'
        WHEN close_price > h1m THEN 'Vượt đỉnh'
        WHEN close_price >= h1m * 0.97 THEN 'Rất gần đỉnh'
        WHEN close_price >= h1m * 0.95 THEN 'Gần đỉnh'
        ELSE 'Xa đỉnh'
    END,
    trang_thai_h3m = CASE
        WHEN h3m IS NULL OR h3m = 0 THEN NULL
        WHEN close_price > h3m * 1.03 THEN 'Vượt mạnh'
        WHEN close_price > h3m THEN 'Vượt đỉnh'
        WHEN close_price >= h3m * 0.97 THEN 'Rất gần đỉnh'
        WHEN close_price >= h3m * 0.95 THEN 'Gần đỉnh'
        ELSE 'Xa đỉnh'
    END,
    trang_thai_h6m = CASE
        WHEN h6m IS NULL OR h6m = 0 THEN NULL
        WHEN close_price > h6m * 1.03 THEN 'Vượt mạnh'
        WHEN close_price > h6m THEN 'Vượt đỉnh'
        WHEN close_price >= h6m * 0.97 THEN 'Rất gần đỉnh'
        WHEN close_price >= h6m * 0.95 THEN 'Gần đỉnh'
        ELSE 'Xa đỉnh'
    END,
    trang_thai_h1y = CASE
        WHEN h1y IS NULL OR h1y = 0 THEN NULL
        WHEN close_price > h1y * 1.03 THEN 'Vượt mạnh'
        WHEN close_price > h1y THEN 'Vượt đỉnh'
        WHEN close_price >= h1y * 0.97 THEN 'Rất gần đỉnh'
        WHEN close_price >= h1y * 0.95 THEN 'Gần đỉnh'
        ELSE 'Xa đỉnh'
    END,
    compression_1m = (h1m - l1m) / NULLIF(h1m,0) * 100,
    position_1m = CASE
        WHEN h1m IS NULL OR l1m IS NULL OR NULLIF((h1m - l1m),0) IS NULL THEN NULL
        WHEN ((close_price - l1m) / NULLIF((h1m - l1m),0) * 100) <= 20 THEN 'Sát đáy'
        WHEN ((close_price - l1m) / NULLIF((h1m - l1m),0) * 100) <= 50 THEN 'Dưới trung vị'
        WHEN ((close_price - l1m) / NULLIF((h1m - l1m),0) * 100) <= 80 THEN 'Trên trung vị'
        ELSE 'Sát đỉnh'
    END,
    position_3m = CASE
        WHEN h3m IS NULL OR l3m IS NULL OR NULLIF((h3m - l3m),0) IS NULL THEN NULL
        WHEN ((close_price - l3m) / NULLIF((h3m - l3m),0) * 100) <= 20 THEN 'Sát đáy'
        WHEN ((close_price - l3m) / NULLIF((h3m - l3m),0) * 100) <= 50 THEN 'Dưới trung vị'
        WHEN ((close_price - l3m) / NULLIF((h3m - l3m),0) * 100) <= 80 THEN 'Trên trung vị'
        ELSE 'Sát đỉnh'
    END,
    position_6m = CASE
        WHEN h6m IS NULL OR l6m IS NULL OR NULLIF((h6m - l6m),0) IS NULL THEN NULL
        WHEN ((close_price - l6m) / NULLIF((h6m - l6m),0) * 100) <= 20 THEN 'Sát đáy'
        WHEN ((close_price - l6m) / NULLIF((h6m - l6m),0) * 100) <= 50 THEN 'Dưới trung vị'
        WHEN ((close_price - l6m) / NULLIF((h6m - l6m),0) * 100) <= 80 THEN 'Trên trung vị'
        ELSE 'Sát đỉnh'
    END,
    position_1y = CASE
        WHEN h1y IS NULL OR l1y IS NULL OR NULLIF((h1y - l1y),0) IS NULL THEN NULL
        WHEN ((close_price - l1y) / NULLIF((h1y - l1y),0) * 100) <= 20 THEN 'Sát đáy'
        WHEN ((close_price - l1y) / NULLIF((h1y - l1y),0) * 100) <= 50 THEN 'Dưới trung vị'
        WHEN ((close_price - l1y) / NULLIF((h1y - l1y),0) * 100) <= 80 THEN 'Trên trung vị'
        ELSE 'Sát đỉnh'
    END,
    breakout_potential_score = LEAST(10,
        (CASE WHEN (CASE
            WHEN h1m IS NULL OR h1m = 0 THEN NULL
            WHEN close_price > h1m * 1.03 THEN 'Vượt mạnh'
            WHEN close_price > h1m THEN 'Vượt đỉnh'
            WHEN close_price >= h1m * 0.97 THEN 'Rất gần đỉnh'
            WHEN close_price >= h1m * 0.95 THEN 'Gần đỉnh'
            ELSE 'Xa đỉnh'
        END) = 'Rất gần đỉnh' THEN 2 ELSE 0 END)
        + (CASE WHEN ((h1m - l1m) / NULLIF(h1m,0) * 100) < 10 THEN 2 ELSE 0 END)
        + (CASE WHEN rsi >= 60 THEN 1 ELSE 0 END)
        + (CASE WHEN ma20 IS NOT NULL AND close_price > ma20 THEN 1 ELSE 0 END)
        + (CASE WHEN tang_gia_kem_vol = 'Tăng giá kèm Vol' THEN 1 ELSE 0 END)
        + (CASE WHEN smart_money = 'Smart Money' THEN 2 ELSE 0 END)
        + (CASE WHEN (CASE
            WHEN h3m IS NULL OR h3m = 0 THEN NULL
            WHEN close_price > h3m * 1.03 THEN 'Vượt mạnh'
            WHEN close_price > h3m THEN 'Vượt đỉnh'
            WHEN close_price >= h3m * 0.97 THEN 'Rất gần đỉnh'
            WHEN close_price >= h3m * 0.95 THEN 'Gần đỉnh'
            ELSE 'Xa đỉnh'
        END) = 'Rất gần đỉnh' THEN 1 ELSE 0 END)
    )
WHERE UPPER(symbol_type) = 'STOCK';

-- Batch mode suggestion to avoid full-table scan:
-- UPDATE wp_lcni_ohlc ... WHERE UPPER(symbol_type) = 'STOCK' AND event_time = ?;

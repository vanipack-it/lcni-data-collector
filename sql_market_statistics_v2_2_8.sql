-- LCNI Data Collector v2.2.8
-- Thống kê thị trường + ngành ICB cấp 2 (tính theo từng event_time)

-- ============================================================
-- 0) Index tối ưu (không đổi cấu trúc cột bảng gốc)
-- ============================================================
CREATE INDEX idx_ohlc_symbol_event_timeframe ON wp_lcni_ohlc (symbol, event_time, timeframe);
CREATE INDEX idx_ohlc_event_time ON wp_lcni_ohlc (event_time);
CREATE INDEX idx_ohlc_symbol_type ON wp_lcni_ohlc (symbol_type);

-- Khuyến nghị thêm để tối ưu JOIN/GROUP BY
CREATE INDEX idx_sym_icb_market_symbol ON wp_lcni_sym_icb_market (symbol);
CREATE INDEX idx_sym_icb_market_market_icb ON wp_lcni_sym_icb_market (marketid, icb_level2);

-- ============================================================
-- 1) Bảng thống kê thị trường
-- ============================================================
CREATE TABLE IF NOT EXISTS wp_lcni_thong_ke_thi_truong (
    event_time BIGINT NOT NULL,
    marketid INT NOT NULL,
    timeframe VARCHAR(10) NOT NULL,

    so_ma_tang_gia INT NOT NULL DEFAULT 0,
    so_ma_giam_gia INT NOT NULL DEFAULT 0,

    so_rsi_qua_mua INT NOT NULL DEFAULT 0,
    so_rsi_qua_ban INT NOT NULL DEFAULT 0,
    so_rsi_tham_lam INT NOT NULL DEFAULT 0,
    so_rsi_so_hai INT NOT NULL DEFAULT 0,

    so_smart_money INT NOT NULL DEFAULT 0,
    so_tang_gia_kem_vol INT NOT NULL DEFAULT 0,
    so_pha_nen INT NOT NULL DEFAULT 0,

    pct_so_ma_tren_ma20 DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    pct_so_ma_tren_ma50 DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    pct_so_ma_tren_ma100 DECIMAL(6,2) NOT NULL DEFAULT 0.00,

    tong_value_traded BIGINT NOT NULL DEFAULT 0,

    PRIMARY KEY (event_time, marketid, timeframe),
    KEY idx_market_timeframe (marketid, timeframe),
    KEY idx_event_time (event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chạy theo từng batch event_time/timeframe
SET @target_event_time = 0; -- TODO: thay bằng event_time cần tính
SET @target_timeframe = '1D'; -- TODO: thay bằng timeframe cần tính

REPLACE INTO wp_lcni_thong_ke_thi_truong (
    event_time,
    marketid,
    timeframe,
    so_ma_tang_gia,
    so_ma_giam_gia,
    so_rsi_qua_mua,
    so_rsi_qua_ban,
    so_rsi_tham_lam,
    so_rsi_so_hai,
    so_smart_money,
    so_tang_gia_kem_vol,
    so_pha_nen,
    pct_so_ma_tren_ma20,
    pct_so_ma_tren_ma50,
    pct_so_ma_tren_ma100,
    tong_value_traded
)
SELECT
    o.event_time,
    s.marketid,
    o.timeframe,
    SUM(CASE WHEN o.close_price > o.open_price THEN 1 ELSE 0 END) AS so_ma_tang_gia,
    SUM(CASE WHEN o.close_price < o.open_price THEN 1 ELSE 0 END) AS so_ma_giam_gia,

    SUM(CASE WHEN o.rsi >= 70 THEN 1 ELSE 0 END) AS so_rsi_qua_mua,
    SUM(CASE WHEN o.rsi <= 30 THEN 1 ELSE 0 END) AS so_rsi_qua_ban,
    SUM(CASE WHEN o.rsi >= 80 THEN 1 ELSE 0 END) AS so_rsi_tham_lam,
    SUM(CASE WHEN o.rsi <= 20 THEN 1 ELSE 0 END) AS so_rsi_so_hai,

    SUM(CASE WHEN o.smart_money = 1 THEN 1 ELSE 0 END) AS so_smart_money,
    SUM(CASE WHEN o.tang_gia_kem_vol = 1 THEN 1 ELSE 0 END) AS so_tang_gia_kem_vol,
    SUM(CASE WHEN o.pha_nen = 1 THEN 1 ELSE 0 END) AS so_pha_nen,

    ROUND(100 * SUM(CASE WHEN o.close_price > o.ma20 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 2) AS pct_so_ma_tren_ma20,
    ROUND(100 * SUM(CASE WHEN o.close_price > o.ma50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 2) AS pct_so_ma_tren_ma50,
    ROUND(100 * SUM(CASE WHEN o.close_price > o.ma100 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 2) AS pct_so_ma_tren_ma100,

    COALESCE(SUM(o.value_traded), 0) AS tong_value_traded
FROM wp_lcni_ohlc o
JOIN wp_lcni_sym_icb_market s
    ON o.symbol = s.symbol
WHERE LOWER(o.symbol_type) = 'stock'
  AND o.event_time = @target_event_time
  AND o.timeframe = @target_timeframe
GROUP BY
    o.event_time,
    o.timeframe,
    s.marketid;

-- ============================================================
-- 2) Bảng thống kê ngành ICB cấp 2
-- ============================================================
CREATE TABLE IF NOT EXISTS wp_lcni_thong_ke_nganh_icb_2 (
    event_time BIGINT NOT NULL,
    timeframe VARCHAR(10) NOT NULL,
    marketid INT NOT NULL,
    icb_level2 VARCHAR(255) NOT NULL,

    so_smart_money INT NOT NULL DEFAULT 0,
    so_tang_gia_kem_vol INT NOT NULL DEFAULT 0,
    so_pha_nen INT NOT NULL DEFAULT 0,

    tong_value_traded BIGINT NOT NULL DEFAULT 0,

    so_rsi_qua_mua INT NOT NULL DEFAULT 0,
    so_rsi_qua_ban INT NOT NULL DEFAULT 0,
    so_rsi_tham_lam INT NOT NULL DEFAULT 0,
    so_rsi_so_hai INT NOT NULL DEFAULT 0,

    so_macd_cat_len INT NOT NULL DEFAULT 0,
    so_macd_cat_xuong INT NOT NULL DEFAULT 0,

    PRIMARY KEY (event_time, timeframe, marketid, icb_level2),
    KEY idx_market_timeframe_icb (marketid, timeframe, icb_level2),
    KEY idx_event_time (event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

REPLACE INTO wp_lcni_thong_ke_nganh_icb_2 (
    event_time,
    timeframe,
    marketid,
    icb_level2,
    so_smart_money,
    so_tang_gia_kem_vol,
    so_pha_nen,
    tong_value_traded,
    so_rsi_qua_mua,
    so_rsi_qua_ban,
    so_rsi_tham_lam,
    so_rsi_so_hai,
    so_macd_cat_len,
    so_macd_cat_xuong
)
SELECT
    x.event_time,
    x.timeframe,
    x.marketid,
    x.icb_level2,
    SUM(CASE WHEN x.smart_money = 1 THEN 1 ELSE 0 END) AS so_smart_money,
    SUM(CASE WHEN x.tang_gia_kem_vol = 1 THEN 1 ELSE 0 END) AS so_tang_gia_kem_vol,
    SUM(CASE WHEN x.pha_nen = 1 THEN 1 ELSE 0 END) AS so_pha_nen,

    COALESCE(SUM(x.value_traded), 0) AS tong_value_traded,

    SUM(CASE WHEN x.rsi >= 70 THEN 1 ELSE 0 END) AS so_rsi_qua_mua,
    SUM(CASE WHEN x.rsi <= 30 THEN 1 ELSE 0 END) AS so_rsi_qua_ban,
    SUM(CASE WHEN x.rsi >= 80 THEN 1 ELSE 0 END) AS so_rsi_tham_lam,
    SUM(CASE WHEN x.rsi <= 20 THEN 1 ELSE 0 END) AS so_rsi_so_hai,

    SUM(
        CASE
            WHEN x.macd > x.macd_signal
             AND x.prev_macd <= x.prev_macd_signal
            THEN 1 ELSE 0
        END
    ) AS so_macd_cat_len,
    SUM(
        CASE
            WHEN x.macd < x.macd_signal
             AND x.prev_macd >= x.prev_macd_signal
            THEN 1 ELSE 0
        END
    ) AS so_macd_cat_xuong
FROM (
    SELECT
        o.symbol,
        o.event_time,
        o.timeframe,
        s.marketid,
        s.icb_level2,
        o.smart_money,
        o.tang_gia_kem_vol,
        o.pha_nen,
        o.value_traded,
        o.rsi,
        o.macd,
        o.macd_signal,
        LAG(o.macd) OVER (PARTITION BY o.symbol, o.timeframe ORDER BY o.event_time) AS prev_macd,
        LAG(o.macd_signal) OVER (PARTITION BY o.symbol, o.timeframe ORDER BY o.event_time) AS prev_macd_signal
    FROM wp_lcni_ohlc o
    JOIN wp_lcni_sym_icb_market s
        ON o.symbol = s.symbol
    WHERE LOWER(o.symbol_type) = 'stock'
      AND o.timeframe = @target_timeframe
      AND o.event_time IN (@target_event_time - 1, @target_event_time)
) x
WHERE x.event_time = @target_event_time
GROUP BY
    x.event_time,
    x.timeframe,
    x.marketid,
    x.icb_level2;

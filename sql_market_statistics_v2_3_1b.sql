-- LCNI Data Collector v2.3.1b
-- Market statistics rebuild (event_time snapshot groups)

ALTER TABLE wp_lcni_thong_ke_thi_truong
    DROP PRIMARY KEY,
    ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
    ADD COLUMN thong_ke_thi_truong_index BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id;

ALTER TABLE wp_lcni_thong_ke_thi_truong
    MODIFY COLUMN pct_so_ma_tren_ma20 DECIMAL(12,6) NOT NULL DEFAULT 0,
    MODIFY COLUMN pct_so_ma_tren_ma50 DECIMAL(12,6) NOT NULL DEFAULT 0,
    MODIFY COLUMN pct_so_ma_tren_ma100 DECIMAL(12,6) NOT NULL DEFAULT 0,
    MODIFY COLUMN tong_value_traded DECIMAL(24,2) NOT NULL DEFAULT 0;

ALTER TABLE wp_lcni_thong_ke_thi_truong
    ADD UNIQUE KEY uniq_event_market_timeframe (event_time, marketid, timeframe),
    ADD KEY idx_event_time (event_time, timeframe),
    ADD KEY idx_thong_ke_thi_truong_index (thong_ke_thi_truong_index);

ALTER TABLE wp_lcni_thong_ke_nganh_icb_2
    ADD KEY idx_event_time (event_time, timeframe);

TRUNCATE TABLE wp_lcni_thong_ke_thi_truong;
TRUNCATE TABLE wp_lcni_thong_ke_nganh_icb_2;

INSERT INTO wp_lcni_thong_ke_thi_truong (
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
    CAST(COALESCE(NULLIF(TRIM(m.market_id), ''), '0') AS UNSIGNED) AS marketid,
    o.timeframe,
    SUM(CASE WHEN COALESCE(o.pct_t_1, 0) > 0 THEN 1 ELSE 0 END) AS so_ma_tang_gia,
    SUM(CASE WHEN COALESCE(o.pct_t_1, 0) < 0 THEN 1 ELSE 0 END) AS so_ma_giam_gia,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Quá mua' THEN 1 ELSE 0 END) AS so_rsi_qua_mua,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Quá bán' THEN 1 ELSE 0 END) AS so_rsi_qua_ban,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Tham lam' THEN 1 ELSE 0 END) AS so_rsi_tham_lam,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Sợ hãi' THEN 1 ELSE 0 END) AS so_rsi_so_hai,
    SUM(CASE WHEN COALESCE(o.smart_money, '') = 'Smart Money' THEN 1 ELSE 0 END) AS so_smart_money,
    SUM(CASE WHEN COALESCE(o.tang_gia_kem_vol, '') = 'Tăng giá kèm Vol' THEN 1 ELSE 0 END) AS so_tang_gia_kem_vol,
    SUM(CASE WHEN COALESCE(o.pha_nen, '') = 'Phá nền' THEN 1 ELSE 0 END) AS so_pha_nen,
    SUM(CASE WHEN COALESCE(o.gia_sv_ma20, 0) > 0 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS pct_so_ma_tren_ma20,
    SUM(CASE WHEN COALESCE(o.gia_sv_ma50, 0) > 0 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS pct_so_ma_tren_ma50,
    SUM(CASE WHEN COALESCE(o.gia_sv_ma100, 0) > 0 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) AS pct_so_ma_tren_ma100,
    COALESCE(SUM(o.value_traded), 0) AS tong_value_traded
FROM wp_lcni_ohlc o
INNER JOIN wp_lcni_sym_icb_market m ON m.symbol = o.symbol
GROUP BY o.event_time, CAST(COALESCE(NULLIF(TRIM(m.market_id), ''), '0') AS UNSIGNED), o.timeframe;

UPDATE wp_lcni_thong_ke_thi_truong target
INNER JOIN (
    SELECT
        src.id,
        ((event_rank.event_position - 1) * market_total.total_markets) + market_rank.market_position AS computed_index
    FROM wp_lcni_thong_ke_thi_truong src
    INNER JOIN (
        SELECT
            event_times.event_time,
            (@event_pos := @event_pos + 1) AS event_position
        FROM (
            SELECT DISTINCT event_time
            FROM wp_lcni_thong_ke_thi_truong
            ORDER BY event_time ASC
        ) event_times
        CROSS JOIN (SELECT @event_pos := 0) event_vars
    ) event_rank ON event_rank.event_time = src.event_time
    INNER JOIN (
        SELECT
            markets.marketid,
            (@market_pos := @market_pos + 1) AS market_position
        FROM (
            SELECT DISTINCT marketid
            FROM wp_lcni_thong_ke_thi_truong
            ORDER BY marketid ASC
        ) markets
        CROSS JOIN (SELECT @market_pos := 0) market_vars
    ) market_rank ON market_rank.marketid = src.marketid
    CROSS JOIN (
        SELECT COUNT(DISTINCT marketid) AS total_markets
        FROM wp_lcni_thong_ke_thi_truong
    ) market_total
) calc ON calc.id = target.id
SET target.thong_ke_thi_truong_index = calc.computed_index;

INSERT INTO wp_lcni_thong_ke_nganh_icb_2 (
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
    o.event_time,
    o.timeframe,
    CAST(COALESCE(NULLIF(TRIM(m.market_id), ''), '0') AS UNSIGNED) AS marketid,
    COALESCE(i.name_icb2, 'Chưa phân loại') AS icb_level2,
    SUM(CASE WHEN COALESCE(o.smart_money, '') = 'Smart Money' THEN 1 ELSE 0 END) AS so_smart_money,
    SUM(CASE WHEN COALESCE(o.tang_gia_kem_vol, '') = 'Tăng giá kèm Vol' THEN 1 ELSE 0 END) AS so_tang_gia_kem_vol,
    SUM(CASE WHEN COALESCE(o.pha_nen, '') = 'Phá nền' THEN 1 ELSE 0 END) AS so_pha_nen,
    COALESCE(SUM(o.value_traded), 0) AS tong_value_traded,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Quá mua' THEN 1 ELSE 0 END) AS so_rsi_qua_mua,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Quá bán' THEN 1 ELSE 0 END) AS so_rsi_qua_ban,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Tham lam' THEN 1 ELSE 0 END) AS so_rsi_tham_lam,
    SUM(CASE WHEN COALESCE(o.rsi_status, '') = 'Sợ hãi' THEN 1 ELSE 0 END) AS so_rsi_so_hai,
    SUM(CASE WHEN COALESCE(o.macd_cat, '') = 'Cắt lên' THEN 1 ELSE 0 END) AS so_macd_cat_len,
    SUM(CASE WHEN COALESCE(o.macd_cat, '') = 'Cắt xuống' THEN 1 ELSE 0 END) AS so_macd_cat_xuong
FROM wp_lcni_ohlc o
INNER JOIN wp_lcni_sym_icb_market m ON m.symbol = o.symbol
LEFT JOIN wp_lcni_icb2 i ON i.id_icb2 = m.id_icb2
GROUP BY o.event_time, o.timeframe, CAST(COALESCE(NULLIF(TRIM(m.market_id), ''), '0') AS UNSIGNED), COALESCE(i.name_icb2, 'Chưa phân loại');

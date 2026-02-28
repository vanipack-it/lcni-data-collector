-- LCNI Data Collector v2.3.3a
-- Rebuild thống kê thị trường + ngành ICB2 + ICB2 toàn thị trường

CREATE TABLE IF NOT EXISTS wp_lcni_thong_ke_nganh_icb_2_toan_thi_truong (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    icb_level2 VARCHAR(255) NOT NULL,
    event_time BIGINT UNSIGNED NOT NULL,
    timeframe VARCHAR(10) NOT NULL,
    icb2_thi_truong_index BIGINT UNSIGNED NOT NULL DEFAULT 0,
    so_ma_tang_gia INT UNSIGNED NOT NULL DEFAULT 0,
    so_ma_giam_gia INT UNSIGNED NOT NULL DEFAULT 0,
    so_rsi_qua_mua INT UNSIGNED NOT NULL DEFAULT 0,
    so_rsi_qua_ban INT UNSIGNED NOT NULL DEFAULT 0,
    so_rsi_tham_lam INT UNSIGNED NOT NULL DEFAULT 0,
    so_rsi_so_hai INT UNSIGNED NOT NULL DEFAULT 0,
    so_smart_money INT UNSIGNED NOT NULL DEFAULT 0,
    so_tang_gia_kem_vol INT UNSIGNED NOT NULL DEFAULT 0,
    so_pha_nen INT UNSIGNED NOT NULL DEFAULT 0,
    pct_so_ma_tren_ma20 DECIMAL(12,6) NOT NULL DEFAULT 0,
    pct_so_ma_tren_ma50 DECIMAL(12,6) NOT NULL DEFAULT 0,
    pct_so_ma_tren_ma100 DECIMAL(12,6) NOT NULL DEFAULT 0,
    tong_value_traded DECIMAL(24,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_icb_level2_event_timeframe (icb_level2, event_time, timeframe),
    KEY idx_event_time (event_time, timeframe),
    KEY idx_icb2_thi_truong_index (icb2_thi_truong_index)
);

TRUNCATE TABLE wp_lcni_thong_ke_thi_truong;
TRUNCATE TABLE wp_lcni_thong_ke_nganh_icb_2;
TRUNCATE TABLE wp_lcni_thong_ke_nganh_icb_2_toan_thi_truong;

INSERT INTO wp_lcni_thong_ke_nganh_icb_2_toan_thi_truong (
    icb_level2, event_time, timeframe,
    so_ma_tang_gia, so_ma_giam_gia,
    so_rsi_qua_mua, so_rsi_qua_ban, so_rsi_tham_lam, so_rsi_so_hai,
    so_smart_money, so_tang_gia_kem_vol, so_pha_nen,
    pct_so_ma_tren_ma20, pct_so_ma_tren_ma50, pct_so_ma_tren_ma100,
    tong_value_traded
)
SELECT
    COALESCE(i.name_icb2, 'Chưa phân loại') AS icb_level2,
    o.event_time,
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
LEFT JOIN wp_lcni_icb2 i ON i.id_icb2 = m.id_icb2
GROUP BY COALESCE(i.name_icb2, 'Chưa phân loại'), o.event_time, o.timeframe;

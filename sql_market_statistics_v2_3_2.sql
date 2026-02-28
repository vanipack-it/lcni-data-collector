-- LCNI Data Collector v2.3.2
-- Schema + rebuild cho thống kê ngành ICB2 và toàn thị trường

ALTER TABLE wp_lcni_thong_ke_nganh_icb_2
    ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
    ADD COLUMN thong_ke_icb2_index BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id,
    ADD COLUMN so_ma_tang_gia INT UNSIGNED NOT NULL DEFAULT 0 AFTER icb_level2,
    ADD COLUMN so_ma_giam_gia INT UNSIGNED NOT NULL DEFAULT 0 AFTER so_ma_tang_gia,
    ADD COLUMN pct_so_ma_tren_ma20 DECIMAL(12,6) NOT NULL DEFAULT 0 AFTER so_pha_nen,
    ADD COLUMN pct_so_ma_tren_ma50 DECIMAL(12,6) NOT NULL DEFAULT 0 AFTER pct_so_ma_tren_ma20,
    ADD COLUMN pct_so_ma_tren_ma100 DECIMAL(12,6) NOT NULL DEFAULT 0 AFTER pct_so_ma_tren_ma50,
    MODIFY COLUMN tong_value_traded DECIMAL(24,2) NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uniq_event_timeframe_market_icb (event_time, timeframe, marketid, icb_level2),
    ADD KEY idx_event_time (event_time, timeframe),
    ADD KEY idx_thong_ke_icb2_index (thong_ke_icb2_index);

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

-- Rebuild bằng luồng migration trong plugin (khuyến nghị)
-- Hoặc có thể deactivate/activate plugin để trigger ensure schema và backfill.

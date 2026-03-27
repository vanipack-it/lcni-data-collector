-- Market statistics rebuild v2.3.1d
-- Rule: thong_ke_thi_truong_index tăng từ 1->n theo event_time (cũ -> mới) cho từng marketid + timeframe

UPDATE wp_lcni_thong_ke_thi_truong target
INNER JOIN (
    SELECT
        ranked.id,
        ranked.market_index AS computed_index
    FROM (
        SELECT
            src.id,
            src.marketid,
            src.timeframe,
            (@market_rank := IF(@current_market = CONCAT(src.marketid, '|', src.timeframe), @market_rank + 1, 1)) AS market_index,
            (@current_market := CONCAT(src.marketid, '|', src.timeframe)) AS current_market_marker
        FROM wp_lcni_thong_ke_thi_truong src
        CROSS JOIN (SELECT @market_rank := 0, @current_market := '') vars
        ORDER BY src.marketid ASC, src.timeframe ASC, src.event_time ASC, src.id ASC
    ) ranked
) calc ON calc.id = target.id
SET target.thong_ke_thi_truong_index = calc.computed_index;

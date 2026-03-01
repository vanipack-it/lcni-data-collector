# Seed Recalculation Audit (LCNI Data Collector)

## Mục tiêu
- Liệt kê **các cột bắt buộc phải tính toán lại** khi chạy seed để đảm bảo dữ liệu API/analytics đúng.
- Đề xuất hướng xử lý để tránh làm nặng web (WP admin + frontend) trong lúc seed chạy.

---

## 1) Điểm kích hoạt hiện tại khi seed chạy
Luồng seed hiện tại:
1. `LCNI_SeedScheduler::run_single_task_batch()` fetch candles theo symbol/timeframe.
2. Dữ liệu thô được lưu bằng `LCNI_DB::upsert_ohlc_rows($rows)`.
3. Trong `upsert_ohlc_rows`, hệ thống đang:
   - upsert OHLC raw row,
   - rebuild metrics theo từng series bị chạm,
   - rebuild RS theo timeframe,
   - refresh snapshot latest,
   - và **rebuild full** 3 bảng thống kê thị trường (TRUNCATE + INSERT lại toàn bộ).

=> Đây là lý do chính gây tải nặng khi seed lớn.

---

## 2) Các cột cần tính lại (recalculate) sau khi seed

### A. Nhóm cột OHLC derived theo từng `symbol + timeframe`
Các cột này đang được cập nhật trong `rebuild_ohlc_indicators()` và phụ thuộc vào chuỗi lịch sử:

1. Biến động phần trăm:
- `pct_t_1`, `pct_t_3`, `pct_1w`, `pct_1m`, `pct_3m`, `pct_6m`, `pct_1y`

2. Moving average & high/low window:
- `ma10`, `ma20`, `ma50`, `ma100`, `ma200`
- `h1m`, `h3m`, `h6m`, `h1y`
- `l1m`, `l3m`, `l6m`, `l1y`

3. Volume MA & ratio:
- `vol_ma10`, `vol_ma20`
- `gia_sv_ma10`, `gia_sv_ma20`, `gia_sv_ma50`, `gia_sv_ma100`, `gia_sv_ma200`
- `vol_sv_vol_ma10`, `vol_sv_vol_ma20`

4. MACD/RSI:
- `macd`, `macd_signal`, `macd_histogram`
- `macd_cat`, `macd_tren_0`, `macd_hist_tang`, `macd_manh`, `macd_diem_dong_luong`
- `rsi`, `rsi_status`

5. Rule-based labels:
- `xay_nen`, `xay_nen_count_30`, `nen_type`
- `pha_nen`, `tang_gia_kem_vol`, `smart_money`
- `hanh_vi_gia`, `hanh_vi_gia_1w`
- `one_candle`

6. Chỉ số tuần tự:
- `trading_index` (được rebuild riêng theo thứ tự event_time tăng dần).

### B. Nhóm cột RS theo exchange (cross-symbol trong cùng event_time/timeframe/exchange)
Các cột này phụ thuộc toàn bộ tập mã cùng exchange ở cùng mốc thời gian:
- `rs_1m_by_exchange`
- `rs_1w_by_exchange`
- `rs_3m_by_exchange`
- `rs_exchange_status`
- `rs_exchange_recommend`
- `rs_recommend_status`

### C. Nhóm bảng thống kê tổng hợp (materialized aggregate)
Các bảng này hiện được rebuild full sau mỗi lần upsert:

1. `lcni_thong_ke_thi_truong`:
- `so_ma_tang_gia`, `so_ma_giam_gia`
- `so_rsi_qua_mua`, `so_rsi_qua_ban`, `so_rsi_tham_lam`, `so_rsi_so_hai`
- `so_smart_money`, `so_tang_gia_kem_vol`, `so_pha_nen`
- `pct_so_ma_tren_ma20`, `pct_so_ma_tren_ma50`, `pct_so_ma_tren_ma100`
- `tong_value_traded`
- `thong_ke_thi_truong_index`

2. `lcni_thong_ke_nganh_icb_2`:
- toàn bộ nhóm count/ratio/value tương tự theo `marketid + icb_level2`
- thêm `so_macd_cat_len`, `so_macd_cat_xuong`
- `thong_ke_icb2_index`

3. `lcni_thong_ke_nganh_icb_2_toan_thi_truong`:
- toàn bộ nhóm count/ratio/value theo `icb_level2`
- `icb2_thi_truong_index`

---

## 3) Cột nào bắt buộc tính ngay vs có thể trì hoãn

### Bắt buộc tính ngay (để API stock detail đúng)
- OHLC derived của chính series vừa seed (mục A).
- RS cốt lõi của event_time mới chạm: `rs_1m_by_exchange`, `rs_1w_by_exchange`, `rs_3m_by_exchange` (mục B).

### Có thể trì hoãn / chạy nền
- `rs_exchange_status`, `rs_exchange_recommend`, `rs_recommend_status` (có thể batch theo timeframe).
- 3 bảng thống kê tổng hợp (mục C), đặc biệt là index thứ tự (`*_index`) và metric toàn thị trường.

---

## 4) Đề xuất để tránh làm nặng web

## Đề xuất 1 — Tách 2 pha: Ingest nhanh + Rebuild nền
- **Pha A (synchronous trong seed batch):**
  - chỉ upsert raw OHLC;
  - rebuild indicator **chỉ cho series vừa chạm**;
  - đẩy “dirty keys” vào queue (event_time/timeframe/marketid/icb2).
- **Pha B (background cron):**
  - đọc queue dirty và rebuild aggregate/statistics theo **delta**.

Lợi ích: giảm thời gian request admin action, giảm block PHP worker.

## Đề xuất 2 — Thay full rebuild statistics bằng incremental upsert
Hiện trạng: `backfill_market_statistics_tables(true)` đang `TRUNCATE` rồi insert toàn bộ mỗi lần seed.

Nên đổi sang:
- xác định tập `event_time + timeframe` vừa bị ảnh hưởng;
- chạy `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` chỉ cho tập đó;
- cập nhật lại `*_index` theo phạm vi nhỏ (timeframe + market/id bị ảnh hưởng), không scan toàn bảng.

## Đề xuất 3 — Giới hạn phạm vi RS rebuild
- `rs_3m_by_exchange` hiện rebuild theo toàn timeframe (không chỉ touched event_time).
- Với seed lịch sử lớn, nên chạy:
  - incremental theo event_time touched trước,
  - full RS backfill chạy riêng vào cron đêm (off-peak).

## Đề xuất 4 — Áp dụng queue tương tự RULE_REBUILD cho seed
Repo đã có cơ chế queue `RULE_REBUILD_TASKS_OPTION` + `process_rule_rebuild_batch()`.

Có thể tái sử dụng pattern này cho seed hậu xử lý:
- queue loại task: `series_metrics`, `rs_metrics`, `market_stats`;
- mỗi cron tick xử lý tối đa N task;
- có progress/status riêng để giám sát.

## Đề xuất 5 — Throttle theo cấu hình + lịch chạy ngoài giờ
- giữ `tasks_per_run`, `batch_requests_per_run`, `rate_limit_microseconds` thấp ở giờ giao dịch;
- tăng batch vào khung đêm.
- ưu tiên chạy seed full vào off-peak để tránh tranh chấp CPU/DB.

## Đề xuất 6 — Tối ưu DB query và lock
- đảm bảo index phục vụ nhóm query rebuild:
  - `lcni_ohlc(symbol, timeframe, event_time)`
  - `lcni_ohlc(event_time, timeframe)`
  - `lcni_sym_icb_market(symbol, market_id, id_icb2, exchange)`
- tránh transaction lớn kéo dài trên bảng aggregate.
- chia nhỏ theo timeframe/event_time để giảm lock contention.

---

## 5) Mẫu kế hoạch triển khai (an toàn)
1. Bước 1: thêm cờ config `lcni_seed_defer_market_stats=yes` (mặc định bật).
2. Bước 2: khi seed upsert, bỏ full `backfill_market_statistics_tables(true)`, thay bằng ghi dirty queue.
3. Bước 3: thêm cron background xử lý queue theo lô nhỏ (50–200 key/lần).
4. Bước 4: thêm nút admin “Rebuild full statistics now” cho tình huống cần đồng bộ ngay.
5. Bước 5: bổ sung metrics monitor:
   - thời gian batch seed,
   - số dirty key chờ,
   - lag cập nhật thống kê.

---

## 6) Kết luận ngắn
- Các cột bắt buộc recalculation tập trung ở 3 nhóm: **OHLC derived**, **RS exchange**, **aggregate statistics**.
- Nút thắt nặng nhất hiện nay là **full rebuild statistics sau mỗi upsert seed**.
- Hướng tối ưu an toàn nhất: **incremental + queue background + off-peak scheduling**.

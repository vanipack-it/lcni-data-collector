# Phân tích timeout khi seed ~1600 symbol (01/01/2024 → 27/02/2026) và tính indicators

## 1) Tóm tắt hiện tượng

Khi seed dữ liệu lịch sử với số lượng symbol lớn rồi bật tính chỉ báo, hệ thống dễ timeout vì workload đang dồn vào cùng một luồng PHP/WordPress Cron:

- Ghi OHLC theo từng row (mỗi row = 1 câu SQL `INSERT ... ON DUPLICATE KEY UPDATE`).
- Xếp hàng rebuild.
- Rebuild chỉ báo theo từng `symbol + timeframe` bằng PHP loop qua toàn bộ lịch sử.
- Mỗi row lại chạy 1 câu `UPDATE` để ghi indicator.
- Sau đó còn chạy thêm các bước RS theo exchange + market statistics.

Mô hình này đảm bảo đúng dữ liệu, nhưng với dữ liệu lịch sử lớn thì tổng số round-trip DB rất cao và thời gian xử lý mỗi cron run tăng nhanh.

---

## 2) Phân tích cấu trúc database và điểm nghẽn

## 2.1 Bảng trung tâm `wp_lcni_ohlc` rất rộng + ghi cập nhật dày đặc

Bảng `lcni_ohlc` chứa cả dữ liệu giá/khối lượng + rất nhiều cột indicator/phân loại (`ma*`, `vol_ma*`, `macd*`, `rsi`, `xay_nen`, `nen_type`, `pha_nen`, `smart_money`, `one_candle`, `two_candle_pattern`, `three_candle_pattern`, `breakout_*`, ...). Điều này khiến mỗi lần `UPDATE` row có thể ghi rất nhiều cột. Với hàng trăm nghìn đến hàng triệu row, tổng IO là rất lớn.

## 2.2 Pipeline hiện tại đang thiên về "row-by-row" (N+1 ở quy mô lớn)

### (a) Upsert seed theo từng row

Trong `upsert_ohlc_rows()`, hệ thống lặp từng row và gọi `$wpdb->query()` cho mỗi bản ghi (`INSERT ... ON DUPLICATE KEY UPDATE`). Đây là phương án an toàn nhưng đắt chi phí khi seed bulk lớn.

### (b) Rebuild indicator theo từng series, đọc full series

`rebuild_ohlc_indicators($symbol, $timeframe)` load toàn bộ lịch sử của series rồi loop từ đầu đến cuối để tính MA/RSI/MACD/rule signals.

### (c) Ghi lại indicator theo từng row

Ngay trong loop đó, mỗi iteration gọi `$wpdb->update(... ['id' => ...])`. Nếu 1 series có 500-600 candle thì là 500-600 `UPDATE`; nhân 1600 symbol sẽ thành cực lớn.

### (d) Hàm cửa sổ dùng `array_slice` + `array_sum/max/min` lặp lại

`window_average/window_max/window_min` đều cắt mảng rồi tính lại ở mỗi bước. Dù cửa sổ nhỏ (10/20/50/100/200), nhưng lặp lại trên toàn bộ series và nhiều chỉ báo vẫn tăng CPU đáng kể.

## 2.3 Cron chạy mỗi phút + tác vụ nặng chồng tác vụ nặng

Hook seed cron chạy mỗi phút và trong mỗi lần chạy sẽ gọi:

1. `LCNI_SeedScheduler::run_batch()`
2. `LCNI_DB::process_seed_rebuild_pipeline()`
3. `LCNI_DB::refresh_ohlc_latest_snapshot()`

Nghĩa là một tick cron có thể vừa fetch API vừa upsert vừa rebuild vừa refresh snapshot. Nếu host shared hoặc PHP worker ít, rất dễ chạm timeout và/hoặc nghẽn request frontend/admin.

## 2.4 Khối lượng dữ liệu thực tế quá lớn cho cách tính đồng bộ

Ước lượng nhanh cho 1D:

- ~1600 symbol
- Khoảng 540-560 phiên giao dịch từ 01/01/2024 đến 27/02/2026
- Tổng row ~ 864k đến 896k (chỉ timeframe 1D)

Nếu có thêm timeframe (1H/15M) thì số row tăng bội số. Với kiến trúc loop-PHP + update từng row, timeout là kết quả gần như chắc chắn nếu chạy dồn.

---

## 3) Nguyên nhân cốt lõi khiến web timeout

1. **Quá nhiều truy vấn nhỏ thay vì ít truy vấn lớn** (chatty DB pattern).
2. **Tính lại full series quá thường xuyên** thay vì incremental theo đoạn mới/chạm dữ liệu.
3. **Cron tick gánh nhiều công đoạn nặng trong cùng request PHP**.
4. **Tài nguyên server không đủ cho batch hiện tại** (max_execution_time, CPU, IOPS, MySQL buffer).
5. **Dữ liệu lịch sử lớn + bảng rộng** làm mỗi UPDATE tốn kém hơn mong đợi.

---

## 4) Cách hoàn thành tính toán nhanh nhất mà không làm web chậm

## Ưu tiên A (tác động lớn nhất, nên làm trước)

1. **Tách hẳn worker tính toán khỏi web request**
   - Chạy seed/rebuild bằng WP-CLI hoặc worker nền riêng (system cron gọi CLI), không phụ thuộc traffic web.
   - Mục tiêu: frontend/admin không còn "chia CPU" với job tính chỉ báo.

2. **Chuyển compute nặng sang SQL set-based (MySQL 8 window functions)**
   - Repo đã có file `sql_ohlc_indicators_mysql8.sql` theo hướng này.
   - Dùng 1 batch SQL lớn theo partition `symbol,timeframe` thường nhanh hơn rất nhiều so với loop PHP + update từng row.

3. **Giảm phạm vi tính toán: incremental + lookback tối thiểu**
   - Khi có candle mới, chỉ rebuild đoạn cuối (ví dụ 260-300 phiên gần nhất để đủ MA200 + RSI/MACD ổn định), không rebuild toàn bộ lịch sử symbol.

## Ưu tiên B (giảm timeout tức thời)

4. **Giảm kích thước batch trong giờ cao điểm**
   - Hạ `tasks_per_run`, `batch_requests_per_run`, `seed_rebuild_*_batch_size` để tránh mỗi cron tick quá nặng.
   - Tăng lại batch vào ban đêm (off-peak) để hoàn thành nhanh mà không ảnh hưởng user.

5. **Tạm tắt hoặc giãn `refresh_ohlc_latest_snapshot` trong giai đoạn seed bulk**
   - Chỉ refresh snapshot theo nhịp lớn hơn (ví dụ mỗi 5-10 phút hoặc sau mỗi N batch) để giảm ghi lặp.

6. **Khóa tiến trình theo pha rõ ràng**
   - Pha 1: ingest xong bulk.
   - Pha 2: compute indicators.
   - Pha 3: RS & market stats.
   - Tránh để 3 pha chạy đè trong cùng window thời gian.

## Ưu tiên C (nâng trần hiệu năng)

7. **Tối ưu hạ tầng MySQL/PHP**
   - MySQL 8 + cấu hình buffer pool phù hợp dữ liệu.
   - Tăng `max_execution_time` cho worker nền (không áp vào frontend).
   - Nếu có thể: tách DB server hoặc nâng IOPS (NVMe).

8. **Theo dõi bằng số liệu thay vì cảm giác**
   - Log thời gian từng bước: fetch, upsert, series rebuild, rs rebuild, market stats.
   - Bật slow query log để bắt chính xác query gây nghẽn.

---

## 5) Lộ trình triển khai đề xuất (thực tế, ít rủi ro)

1. **Ngay lập tức (1-2 ngày):**
   - Giảm batch giờ cao điểm + dời job nặng sang đêm.
   - Chạy seed/rebuild bằng CLI cron để giải phóng web requests.

2. **Ngắn hạn (3-7 ngày):**
   - Chuyển compute indicator chính sang SQL set-based theo block thời gian/symbol nhóm.
   - Giữ PHP fallback cho các rule đặc thù nếu cần.

3. **Trung hạn (1-2 tuần):**
   - Hoàn thiện incremental compute (chỉ tính đoạn mới + lookback kỹ thuật).
   - Chuẩn hóa observability (dashboard throughput, ETA, error rate).

---

## 6) Kết luận

Timeout hiện tại không phải do một lỗi đơn lẻ, mà do **mismatch giữa quy mô dữ liệu lớn và cách xử lý row-by-row trong request cron PHP**. Muốn "hoàn thành nhanh nhất mà không làm web chậm", chiến lược tốt nhất là:

- **Tách compute khỏi luồng web**,
- **Chuyển phần tính toán sang SQL set-based**, và
- **Tính incremental thay vì full rebuild**.

Ba điểm này thường giảm thời gian xử lý theo cấp số lớn so với việc chỉ tinh chỉnh vài thông số batch.

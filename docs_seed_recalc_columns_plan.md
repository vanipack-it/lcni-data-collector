# Seed Recalculation Plan (Workflow theo pha rebuild)

## Mục tiêu
- Chuyển quy trình từ **seed + rebuild đồng thời** sang **seed dừng rồi rebuild theo nhóm** để giảm tải web.
- Vẫn đảm bảo dữ liệu đủ đúng/sạch cho API chính (`/wp-json/lcni/v1/stock/{symbol}`).
- Tối ưu thời gian rebuild toàn cục bằng cách ưu tiên thứ tự phụ thuộc thay vì chạy full đồng loạt.

---

## 1) Vấn đề hiện tại
Khi seed ghi OHLC, hệ thống thường kích hoạt nhiều lớp tính toán ngay lập tức:
- Rebuild indicator theo series.
- Rebuild RS theo exchange/timeframe.
- Rebuild full bảng thống kê thị trường.

Cách này an toàn về tính nhất quán tức thời, nhưng nhược điểm lớn:
- Tăng lock/IO DB trong giờ web đang phục vụ request.
- Tranh chấp tài nguyên giữa seed job và API/frontend.
- Tổng thời gian seed lớn bị kéo dài vì mỗi batch lại kéo theo nhiều tác vụ nặng.

---

## 2) Workflow đề xuất (seed dừng -> rebuild nhóm 1 -> nhóm 2 -> nhóm 3)

### Pha 0 — Seed ingest (chỉ ghi dữ liệu + đánh dấu dirty)
**Làm ngay:**
- Upsert OHLC raw.
- Ghi `dirty keys` (symbol, timeframe, event_time, exchange, market_id, icb2).

**Không làm ngay:**
- Không full rebuild thống kê.
- Không chạy các tác vụ cross-market nặng.

**Điều kiện chuyển pha:** seed batch/burst kết thúc hoặc đạt ngưỡng thời gian cắt phiên (vd 3–10 phút/lần).

---

### Nhóm 1 (chạy trước) — Rebuild theo series, phạm vi hẹp
**Mục tiêu:** ưu tiên dữ liệu endpoint chi tiết từng mã đúng nhanh nhất.

**Rebuild:**
- Percent change: `pct_t_1`, `pct_t_3`, `pct_1w`, `pct_1m`, `pct_3m`, `pct_6m`, `pct_1y`.
- MA/High/Low windows: `ma10..ma200`, `h1m/h3m/h6m/h1y`, `l1m/l3m/l6m/l1y`.
- Volume metrics: `vol_ma10`, `vol_ma20`, nhóm ratio giá/vol so MA.
- MACD/RSI + status cơ bản.
- Rule labels phụ thuộc trực tiếp chuỗi giá/vol.
- `trading_index` theo từng `symbol + timeframe`.

**Thứ tự nội bộ gợi ý:**
1. Chỉ số nền (MA, RSI, MACD).
2. Label/rule phụ thuộc chỉ số nền.
3. Trading index.

**Lý do phải chạy đầu tiên:**
- Đây là lớp dữ liệu gần API stock detail nhất.
- Chi phí tính toán có thể giới hạn theo `symbol + timeframe` bị chạm.

---

### Nhóm 2 (chạy sau Nhóm 1) — Rebuild RS cross-symbol theo lát cắt nhỏ
**Mục tiêu:** chuẩn hóa so sánh sức mạnh tương đối giữa mã cùng exchange.

**Rebuild ưu tiên:**
- `rs_1m_by_exchange`, `rs_1w_by_exchange`, `rs_3m_by_exchange` theo **event_time/timeframe bị dirty**.

**Rebuild trì hoãn nhẹ (nền):**
- `rs_exchange_status`, `rs_exchange_recommend`, `rs_recommend_status`.

**Chiến lược tốc độ:**
- Không quét full timeframe ngay.
- Chỉ tính delta cho event_time bị ảnh hưởng.
- Backfill full RS (nếu cần) chuyển sang lịch đêm/off-peak.

**Vì sao chạy sau Nhóm 1:**
- RS dùng dữ liệu derived của nhiều mã; nếu nhóm 1 chưa ổn định sẽ gây sai lệch ranking.

---

### Nhóm 3 (chạy cuối) — Rebuild aggregate/materialized statistics
**Mục tiêu:** cập nhật dashboard/thống kê thị trường mà không chặn luồng chính.

**Bảng xử lý:**
- `lcni_thong_ke_thi_truong`.
- `lcni_thong_ke_nganh_icb_2`.
- `lcni_thong_ke_nganh_icb_2_toan_thi_truong`.

**Nguyên tắc:**
- Bỏ `TRUNCATE + full INSERT` sau mỗi seed batch.
- Dùng incremental `INSERT ... ON DUPLICATE KEY UPDATE` theo tập dirty nhỏ.
- Chỉ cập nhật lại index thứ tự (`*_index`) trong phạm vi bị ảnh hưởng.

**Vì sao chạy cuối:**
- Đây là lớp tổng hợp nặng nhất, độ ưu tiên thấp hơn dữ liệu chi tiết từng mã.
- Chạy cuối giúp web không bị chậm trong lúc seed.

---

## 3) Nâng cấp chức năng để “nhẹ mà vẫn đúng/sạch dữ liệu”

### 3.1 Dirty Queue chuẩn hóa theo loại tác vụ
Thiết kế queue có `task_type`:
- `series_metrics` (Nhóm 1)
- `rs_metrics` (Nhóm 2)
- `market_stats` (Nhóm 3)

Mỗi item có:
- key định danh (symbol/timeframe/event_time/exchange/market/icb2)
- priority
- retry_count
- updated_at

=> Dễ retry, dễ giám sát lag, tránh rebuild trùng lặp.

### 3.2 Watermark & idempotent rebuild
- Gắn `last_processed_at`/`watermark` theo từng group.
- Rebuild idempotent: chạy lại cùng key không làm sai dữ liệu.
- Khi job fail giữa chừng, hệ thống resume từ watermark thay vì chạy lại toàn cục.

### 3.3 Cơ chế “degraded mode” giờ cao điểm
- Giờ cao điểm: chỉ chạy Nhóm 1 + một phần Nhóm 2.
- Nhóm 3 dời sang nền hoặc off-peak.
- Có cờ cấu hình để tăng/giảm batch size theo khung giờ.

### 3.4 Partial refresh thay vì full refresh
- Mọi truy vấn aggregate phải nhận danh sách dirty window (`event_time`, `timeframe`, `market_id`, `icb2`).
- Chỉ recompute các window này.
- Full rebuild toàn cục chỉ dùng khi admin bấm tay hoặc maintenance.

### 3.5 Khóa/transaction nhỏ
- Chia lô nhỏ (ví dụ 50–200 keys/lần).
- Commit nhanh để giảm lock contention.
- Tránh transaction dài trên bảng aggregate lớn.

### 3.6 Chỉ số theo dõi bắt buộc
- Seed ingest latency.
- Queue depth theo từng group.
- Rebuild lag (phút).
- API p95/p99 trong lúc chạy rebuild.
- Tỉ lệ retry/fail theo task_type.

=> Có số liệu để cân bằng “độ đúng gần thời gian thực” và “độ nhẹ hệ thống”.

---

## 4) Thứ tự ưu tiên chạy trước/sau (khuyến nghị vận hành)
1. **Seed ingest stop-point** (ghi dữ liệu + dirty queue).
2. **Nhóm 1**: per-series derived (ưu tiên API stock detail).
3. **Nhóm 2**: RS delta theo event_time/timeframe touched.
4. **Nhóm 3**: aggregate statistics incremental.
5. **Full backfill nền** (nếu cần) vào khung off-peak.

> Không chạy đồng thời full cả 3 nhóm trên cùng tập dữ liệu lớn.
> Nên chạy pipeline nối tiếp theo phụ thuộc dữ liệu để tổng thời gian toàn cục ổn định hơn và giảm spike tài nguyên.

---

## 5) Lộ trình triển khai thực tế (workfollow)

### Giai đoạn A — An toàn, ít đụng chạm
- Giữ seed ingest như cũ nhưng tắt full rebuild aggregate sau mỗi batch.
- Bổ sung dirty queue cho Nhóm 3 trước.
- Chạy cron xử lý incremental statistics.

### Giai đoạn B — Tăng độ mượt
- Mở rộng dirty queue cho Nhóm 2 (RS delta).
- Giới hạn RS full-scan, chuyển thành job đêm.

### Giai đoạn C — Hoàn chỉnh pipeline
- Chuẩn hóa đủ 3 nhóm task trong cùng framework queue.
- Thêm watermark, retry policy, dashboard giám sát.
- Thêm nút admin “Rebuild full now” cho tình huống đặc biệt.

---

## 6) Kết luận
- Workflow tối ưu nên là: **seed dừng -> rebuild Nhóm 1 -> Nhóm 2 -> Nhóm 3**.
- Ưu tiên đúng dữ liệu API chi tiết trước, rồi mới đến lớp so sánh thị trường và lớp thống kê tổng hợp.
- Trọng tâm tối ưu hiệu năng là **incremental rebuild + dirty queue + lịch chạy theo tải hệ thống**, thay cho mô hình rebuild đồng thời/full-scan.

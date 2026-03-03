# LCNI Data Collector - Version 5.4.0

## Changes
- Nâng version plugin lên `5.4.0`.
- Tinh chỉnh pipeline seed theo thứ tự mới có ROI cao: `Seed raw OHLC -> Filter volume + prune retention -> Rebuild indicators -> Refresh latest snapshot`.
- Bổ sung tối ưu dữ liệu seed trong `wp_lcni_ohlc`:
  - Retention mặc định `260` nến trên từng `symbol + timeframe`, prune theo `trading_index/event_time`.
  - Hard volume filter giai đoạn đầu cho EOD (`1D`) với ngưỡng mặc định `> 10000` (lọc theo nến 1D mới nhất của từng symbol).
  - Giữ nguyên cấu trúc bảng và logic tính chỉ báo hiện có.
- Bổ sung đảm bảo index trước khi tối ưu dữ liệu để phục vụ prune/rebuild: `(symbol, timeframe, trading_index)` và `(symbol, timeframe, event_time)`.
- Cập nhật màn hình `Update > Data Runtime (wp_lcni_ohlc)`:
  - Bỏ tùy chọn `Chu kỳ (phút)`.
  - Thêm tùy chọn `Thời gian bắt đầu chạy (HH:MM)`.
  - Runtime cron được cố định theo nhịp 1 phút và chỉ chạy sau thời gian bắt đầu đã cấu hình.

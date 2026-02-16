# Development Log

## 2026-02-14
- Điều tra lỗi import CSV trả về `updated 0 / total N` dù file có dữ liệu.
- Bổ sung cơ chế `LCNI_DB::ensure_tables_exist()` để tự động tạo bảng nếu plugin chưa chạy activation hoặc bị thiếu bảng.
- Gọi `ensure_tables_exist()` trước các luồng chính: import CSV, sync security definitions, sync OHLC.
- Sửa parser header CSV để loại bỏ BOM UTF-8 ở đầu cột (tránh trường hợp cột `symbol` không được nhận diện).
- Kỳ vọng sau sửa: import CSV sẽ tạo/đảm bảo bảng `lcni_symbols` tồn tại và upsert dữ liệu thành công.

## 2026-02-15
- Cập nhật trang **Saved Data** theo dạng tab, mỗi tab hiển thị một bảng riêng (Symbols, Market, ICB2, Symbol-Market-ICB, OHLC + Indicators).
- Bổ sung bảng mới `lcni_sym_icb_market` (symbol, market_id, id_icb2) để liên kết dữ liệu symbol với market và ngành ICB2.
- Mở rộng bảng `lcni_symbols` thêm cột `id_icb2` để lưu ngành theo symbol và đồng bộ sang bảng liên kết mới.
- Mở rộng bảng `lcni_ohlc` thêm các cột chỉ báo: nhóm % thay đổi theo kỳ, MA, High/Low theo kỳ, Volume MA, tỷ lệ giá/volume so với MA, MACD, MACD Signal, RSI.
- Bổ sung luồng tính toán lại indicator theo từng symbol/timeframe ngay sau khi đồng bộ dữ liệu OHLC; chỉ tính trên các phiên có dữ liệu thực tế nên tự động bỏ qua ngày nghỉ/lễ.
- Sửa lỗi các cột indicator mở rộng bị `NULL` sau khi seed/update: chuyển việc tính toán indicator sang ngay trong `upsert_ohlc_rows()` để mọi luồng ghi OHLC (sync thường + seed queue) đều tự động tính lại theo công thức.
- Bổ sung `rebuild_missing_ohlc_indicators()` để tự dò các mã/timeframe mà bản ghi mới nhất còn thiếu chỉ báo và tự tính bù.
- Bổ sung cột `trading_index` cho `lcni_ohlc` và tự động đánh số giao dịch liên tục theo từng `symbol` (tăng dần theo `event_time`).
- Bổ sung cột `xay_nen` và cập nhật logic gán nhãn `'xây nền'` theo bộ điều kiện RSI, độ lệch MA, thanh khoản và biên độ biến động giá.
- Cập nhật script SQL MySQL 8 để tính đồng thời `trading_index` và `xay_nen` khi rebuild indicator.
- Bổ sung migration `backfill_ohlc_trading_index_and_xay_nen` để tự quét các series còn thiếu `trading_index`/`xay_nen` và tính bù toàn bộ indicator.
- Điều chỉnh nhãn `xay_nen` để phân biệt rõ trạng thái: `chưa đủ dữ liệu` (chưa đủ phiên tính công thức), `không xây nền` (đã tính nhưng không thỏa điều kiện), `xây nền` (thỏa điều kiện).
- Cập nhật script SQL MySQL 8 cùng chuẩn phân loại `xay_nen` mới để khi rebuild bằng SQL không còn hiển thị `NULL` mơ hồ.

## 2026-02-16

- Bổ sung cột `pha_nen` cho `lcni_ohlc`, tính theo điều kiện phiên trước có `nen_type` thuộc `Nền vừa/Nền chặt` kết hợp `%T-1` và `vol_sv_vol_ma20`; áp dụng cho cả dữ liệu đã có và dữ liệu mới cập nhật.
- Bổ sung migration/backfill `pha_nen` và mở rộng luồng rebuild để tự tính lại đầy đủ các cột `xay_nen`, `xay_nen_count_30`, `nen_type`, `pha_nen` khi thiếu dữ liệu.
- Bổ sung cơ chế đảm bảo index `idx_symbol_index(symbol, trading_index)` cho bảng `lcni_ohlc` để tăng tốc truy vấn join theo symbol + trading_index.
- Nâng cấp tab **OHLC Data + Indicators**: cho phép chọn cột hiển thị bằng checkbox và lọc nhanh danh sách cột bằng thao tác nhập từ khóa rồi nhấn Enter.
- Bổ sung tab **Rule Setting** trong Saved Data để cấu hình tham số tính `xay_nen/xay_nen_count_30/nen_type/pha_nen`; khi lưu tham số mới hệ thống tự động tính lại toàn bộ series.
- Cập nhật script `sql_ohlc_indicators_mysql8.sql` để thêm `pha_nen` và phản ánh công thức tính phá nền trong luồng rebuild SQL.
- Sửa lỗi rebuild `trading_index` đang đánh số theo toàn bộ `symbol` (bỏ qua `timeframe`), dẫn đến sai chỉ số khi một mã có nhiều khung thời gian; đã cập nhật rebuild theo cặp `symbol + timeframe`.
- Tăng độ bền luồng tự vá `rebuild_missing_ohlc_indicators()` bằng cách bổ sung điều kiện kiểm tra thiếu cho các cột `xay_nen`, `xay_nen_count_30`, `nen_type` để tự tính bù khi còn `NULL`.
- Nâng phiên bản migration backfill `xay_nen_count_30/nen_type` lên `v2`, đồng thời rebuild lại `trading_index` theo đúng `timeframe` trong quá trình backfill.

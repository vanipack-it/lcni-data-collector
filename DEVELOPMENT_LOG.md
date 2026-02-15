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
- Bổ sung hai cột mới cho `lcni_ohlc`: `trading_index` (đánh số phiên liên tục theo từng mã theo `event_time`) và `xay_nen` (gắn nhãn “xây nền” theo điều kiện RSI/MA/volume/%change đã thống nhất).
- Cập nhật luồng rebuild indicator trong plugin để tự động tính và lưu `trading_index`, `xay_nen` cho từng bản ghi OHLC mỗi lần đồng bộ.
- Cập nhật script MySQL 8 (`sql_ohlc_indicators_mysql8.sql`) để thêm/ghi dữ liệu cho `trading_index` và `xay_nen` khi chạy rebuild bằng SQL thuần.

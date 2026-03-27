# Hướng dẫn thiết lập Watchlist + Stock Detail động

## 1) Cấu hình trang template Stock Detail
1. Vào **LCNI Data → Frontend Setting → Watchlist**.
2. Ở mục **Stock Detail Page**, chọn 1 page WordPress dùng làm template.
3. Lưu cấu hình.
4. Trong page template, thêm các shortcode module:
   - `[lcni_stock_overview]`
   - `[lcni_stock_chart]`
   - `[lcni_stock_signals]`

> Khi truy cập URL dạng `/stock/HPG`, hệ thống sẽ render đúng page template đã chọn và tự bind symbol `HPG` vào các shortcode trên.

## 2) Cấu hình cột Watchlist cho frontend
1. Vào **LCNI Data → Frontend Setting → Watchlist**.
2. Tick các cột admin cho phép user xem.
3. Danh sách cột được lấy từ các bảng:
   - `wp_lcni_ohlc_latest`
   - `wp_lcni_symbol_tongquan`
   - `wp_lcni_sym_icb_market`
4. Lưu cấu hình.

## 3) Trải nghiệm user trên frontend
1. User mở module `[lcni_watchlist]`.
2. Click icon ⚙ để mở dropdown chọn cột.
3. Chọn cột và bấm **Lưu**.
4. Cấu hình cột được lưu theo từng user (`user_meta`) và giữ lại sau khi reload.

## 4) Điều hướng sang trang chi tiết cổ phiếu
- Hover row có hiệu ứng highlight.
- Con trỏ chuột dạng pointer.
- Click row bất kỳ sẽ chuyển sang URL `/stock/{symbol}`.

## 5) Rewrite + query var
Plugin đã tự đăng ký:
- `add_rewrite_rule('stock/{symbol}')`
- `query_var('symbol')`
- loader render dynamic template theo page admin đã chọn.

## 6) Lưu ý vận hành
- Sau khi deploy bản mới, vào **Settings → Permalinks** và bấm **Save** nếu server cache rewrite cũ.
- Khi plugin activate/deactivate, rewrite rules cũng được flush tự động.

## 7) Watchlist mobile + cài đặt theo thiết bị
1. Trong **Frontend Setting → Watchlist**, admin chọn:
   - `allowed_columns` (cột được phép hiển thị)
   - `default_columns_desktop`
   - `default_columns_mobile`
2. Frontend tự nhận diện mobile/desktop và nạp bộ cột mặc định tương ứng ngay khi render.
3. User đổi cột bằng panel ⚙ (mặc định ẩn), bấm **Lưu** để ghi `user_meta` dạng JSON.
4. UI cache cục bộ qua `localStorage` để lần tải sau hiển thị ngay, sau đó đồng bộ lại với `user_meta` qua AJAX REST.

## 8) Stock Chart user setting
1. Module chart có panel ⚙ ở góc phải, mặc định ẩn.
2. User chọn kiểu chart + panel indicator (Volume/MACD/RSI/RS), bấm **Lưu**.
3. Cấu hình được lưu theo user (`user_meta`) + cache localStorage, reload vẫn giữ nguyên.
4. REST setting endpoint bắt buộc `X-WP-Nonce` hợp lệ.

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
